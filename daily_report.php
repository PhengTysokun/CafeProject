<?php
require 'auth.php';
require 'config.php';
if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }

// Which business day are we reading? Defaults to the one in progress.
$today = business_date_today();
$date  = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $date = $today;
}
if ($date > $today) { $date = $today; }
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday  = ($date === $today);

// ── Live refresh (Task 9): cheap signature, not a data refetch ──
// dashboard.php re-runs its full KPI queries every 5s for every open browser;
// this endpoint returns only a signature so the client can decide whether a
// reload is even worth doing. Must exit before any HTML is output.
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(total),0), COALESCE(MAX(order_date),'') FROM orders WHERE business_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $sig = implode('|', $stmt->get_result()->fetch_row());
    echo json_encode(['sig' => md5($sig)]);
    exit;
}

// ── Tab 1: the three verdicts (Task 4) ──
// Money we got — the app-wide definition of collected, never hand-rolled.
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $date);
$stmt->execute();
[$gotToday, $paidOrderCount] = $stmt->get_result()->fetch_row();
$gotToday = (float)$gotToday;

$ids = [];
$stmt = $conn->prepare("SELECT order_id FROM orders WHERE business_date = ? AND " . paid_orders_where());
$stmt->bind_param("s", $date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_row()) { $ids[] = (int)$row[0]; }

$costMap  = ingredient_cost_map($conn);
$cogs     = order_cogs($conn, $ids, $costMap);
$keptToday = $gotToday - $cogs['total'];
$centsKept = $gotToday > 0 ? round(($keptToday / $gotToday) * 100) : 0;

$baseGot = weekday_baseline($conn, $date);

/**
 * Turn a difference into the sentence a manager reads. Money, never percent —
 * "9.1% less" is a maths sentence, "$30.50 less" is a money sentence.
 */
function dr_verdict(float $now, ?float $baseline, string $label): array {
    if ($baseline === null) {
        return ['tone' => 'flat', 'line' => 'first day — nothing to compare yet', 'sub' => ''];
    }
    $diff = $now - $baseline;
    if (abs($diff) < 0.005) {
        return ['tone' => 'flat', 'line' => 'the same as ' . $label, 'sub' => ''];
    }
    return [
        'tone' => $diff > 0 ? 'good' : 'bad',
        'line' => '$' . number_format(abs($diff), 2) . ($diff > 0 ? ' MORE' : ' LESS') . ' than',
        'sub'  => $label,
    ];
}

$vGot  = dr_verdict($gotToday, $baseGot['value'], $baseGot['label']);

/**
 * What we kept on the baseline days, costed the same way today is.
 *
 * Scaling the takings baseline by today's keep-rate would be cheaper, but it
 * reduces to the takings difference times a constant — box 2 would always
 * agree with box 1 and never tell the manager anything new. A day can take
 * less and keep more (cheap drinks sold instead of dear ones), and that is
 * exactly the day this box exists to catch. So cost the baseline days for real.
 */
$keptBaseline = null;
if ($baseGot['basis'] !== 'none') {
    // Take the day's total from this same read — a second SUM(total) query per
    // day would only re-fetch rows already in hand.
    $stmt = $conn->prepare("
        SELECT business_date, order_id, total
        FROM orders
        WHERE business_date IN (" . implode(',', array_fill(0, count($baseGot['dates']), '?')) . ")
          AND " . paid_orders_where()
    );
    $stmt->bind_param(str_repeat('s', count($baseGot['dates'])), ...$baseGot['dates']);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDay = [];
    $gotByDay = [];
    while ($r = $res->fetch_assoc()) {
        $byDay[$r['business_date']][] = (int)$r['order_id'];
        $gotByDay[$r['business_date']] = ($gotByDay[$r['business_date']] ?? 0.0) + (float)$r['total'];
    }

    $keptSum = 0.0;
    foreach ($baseGot['dates'] as $d) {
        $dayIds = $byDay[$d] ?? [];
        $keptSum += ($gotByDay[$d] ?? 0.0) - order_cogs($conn, $dayIds, $costMap)['total'];
    }
    $keptBaseline = $keptSum / count($baseGot['dates']);
}

$vKept = dr_verdict($keptToday, $keptBaseline, $baseGot['label']);

// Stock going DOWN is not bad — it means drinks were sold. Red fires only
// when something will actually stop service tomorrow.
$low = $conn->query("
    SELECT ingredient_name, stock_quantity, minimum_stock, unit
    FROM ingredients
    WHERE stock_quantity <= minimum_stock
    ORDER BY (stock_quantity - minimum_stock) ASC
")->fetch_all(MYSQLI_ASSOC);
$lowItems  = count($low);
$lowNames  = array_column(array_slice($low, 0, 3), 'ingredient_name');
$lowExtra  = max(0, $lowItems - 3);

$stockValue = (float)$conn->query("SELECT COALESCE(SUM(stock_quantity * cost_per_unit),0) FROM ingredients")->fetch_row()[0];

// ingredient_history has no business_date column, so match the business day by
// its 06:00-to-06:00 window. Joining through orders would look tidier but drops
// the 33 order_deduct rows that carry a NULL order_id.
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(ABS(h.amount) * i.cost_per_unit),0)
    FROM ingredient_history h
    JOIN ingredients i ON i.ingredient_id = h.ingredient_id
    WHERE h.change_type = 'order_deduct'
      AND h.created_at >= CONCAT(?, ' 06:00:00')
      AND h.created_at <  CONCAT(DATE_ADD(?, INTERVAL 1 DAY), ' 06:00:00')
");
$stmt->bind_param("ss", $date, $date);
$stmt->execute();
$usedValue = (float)$stmt->get_result()->fetch_row()[0];

// ── Tab 1: the neutral row (Task 5) — how the money came in, facts only ──
// How the collected money arrived. Pay-later only counts once settled.
$stmt = $conn->prepare("
    SELECT payment_method, COALESCE(SUM(total),0) AS amount
    FROM orders WHERE business_date = ? AND " . paid_orders_where() . "
    GROUP BY payment_method
");
$stmt->bind_param("s", $date);
$stmt->execute();
$byMethod = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $byMethod[strtolower((string)$r['payment_method'])] = (float)$r['amount']; }

$gotCash   = $byMethod['cash'] ?? 0.0;
$gotBakong = $byMethod['bakong'] ?? 0.0;
$gotLater  = $byMethod['paylater'] ?? 0.0;
// Remainder, not enumeration: legacy rows (payment_method='0', 'riel', ...)
// predate the current three-method model. Deriving this from the total
// keeps the cards honest even as old data carries values we don't name here.
$gotOther  = $gotToday - ($gotCash + $gotBakong + $gotLater);

// Tabs still open: made, maybe served, definitely not paid for.
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND payment_method='paylater' AND is_open = 1 AND status NOT IN ('Cancelled','Void')");
$stmt->bind_param("s", $date);
$stmt->execute();
[$notPaidYet, $notPaidCount] = $stmt->get_result()->fetch_row();
$notPaidYet = (float)$notPaidYet;

// Best seller: $cogs['by_product'] arrives unsorted (insertion order), so sort
// by cups moved. uasort preserves the key, and the key IS the product name.
$byProductSorted = $cogs['by_product'];
uasort($byProductSorted, fn($a, $b) => $b['qty'] <=> $a['qty']);
$bestSellerName = null;
$bestSellerQty  = 0;
foreach ($byProductSorted as $bpName => $bpRow) { $bestSellerName = $bpName; $bestSellerQty = (int)$bpRow['qty']; break; }
$avgCups = $paidOrderCount > 0 ? $cogs['items'] / $paidOrderCount : null;

/**
 * For pay-later, Completed means the drinks were made and the customer still
 * owes — is_open is the only trustworthy signal. Getting this wrong has caused
 * three money bugs in this codebase.
 *
 * Non-payment outranks method: an order that fails the same collected test
 * paid_orders_where() uses reads "not paid yet" regardless of what method it
 * carries — a Preparing/is_open=1 cash row has not been paid any more than an
 * open pay-later tab has. Only once collected do we name the method, and
 * anything outside cash/bakong/paylater (legacy payment_method='0'/'riel'
 * rows) reads "other way", matching tab 1's "other ways" card instead of
 * being folded silently into "cash".
 *
 * Returns [label, state, bucket]. bucket is the payment-method category
 * (cash/bakong/paylater/other) and drives the filter pills; state is
 * 'open'/'ok' and drives the amber tint. The two are independent — a
 * paylater row keeps bucket=paylater whether or not it is state=open.
 */
function dr_pay_label(array $o): array {
    $m = strtolower((string)$o['payment_method']);
    $bucket = in_array($m, ['cash', 'bakong', 'paylater'], true) ? $m : 'other';

    $collected = ((int)$o['is_open'] === 0)
        && !in_array($o['status'], ['PendingPayment', 'Cancelled', 'Refunded', 'Void'], true);
    if (!$collected) {
        return ['not paid yet', 'open', $bucket];
    }

    $label = match ($bucket) {
        'paylater' => 'pay later — paid',
        'other'    => 'other way',
        default    => $bucket, // cash, bakong
    };
    return [$label, 'ok', $bucket];
}

// ── Tab 2: Orders (Task 6) — the day's orders and how each was paid ──
function dr_fragment_orders(mysqli $conn, string $date): void {
    $stmt = $conn->prepare("
        SELECT order_id, daily_order_no, customer_name, total, payment_method, status, is_open,
               order_date, order_type
        FROM orders
        WHERE business_date = ? AND status NOT IN ('Cancelled','Void')
        ORDER BY order_date ASC
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Neutral counts, never a red card — see banned-vocabulary note.
    $stmt = $conn->prepare("SELECT COUNT(*) FROM order_refunds r JOIN orders o ON o.order_id = r.order_id WHERE o.business_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $givenBackCount = (int)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM order_remakes r JOIN orders o ON o.order_id = r.order_id WHERE o.business_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $remadeCount = (int)$stmt->get_result()->fetch_row()[0];

    // Cups per order, for the per-row data attribute the footer's JS sums
    // over the currently-filtered rows.
    $cupsByOrder = [];
    $stmt = $conn->prepare("
        SELECT oi.order_id, COALESCE(SUM(oi.quantity),0) AS cups
        FROM order_items oi
        JOIN orders o ON o.order_id = oi.order_id
        WHERE o.business_date = ? AND o.status NOT IN ('Cancelled','Void')
        GROUP BY oi.order_id
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $cupsByOrder[(int)$r['order_id']] = (int)$r['cups']; }
    $cupsToday = array_sum($cupsByOrder);

    // Money collected vs. still owed — the same collected test tab 1 uses
    // (paid_orders_where()), never a hand-rolled status check. This is the
    // figure that belongs in a "total" slot; folding in open pay-later tabs
    // here is exactly the confusion that has cost this codebase three money
    // bugs already.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND " . paid_orders_where());
    $stmt->bind_param("s", $date);
    $stmt->execute();
    [$collectedTotal, $collectedCount] = $stmt->get_result()->fetch_row();
    $collectedTotal = (float)$collectedTotal;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total),0), COUNT(*)
        FROM orders
        WHERE business_date = ? AND status NOT IN ('Cancelled','Void') AND NOT (" . paid_orders_where() . ")
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    [$notPaidTotal, $notPaidCount] = $stmt->get_result()->fetch_row();
    $notPaidTotal = (float)$notPaidTotal;

    // Pre-derive each row's label/state/bucket once — the table and the
    // "Other" pill's visibility both need it, and deriving it twice is
    // exactly the desync risk the bucket used to have.
    $rowData = [];
    $hasOther = false;
    foreach ($rows as $o) {
        [$label, $state, $bucket] = dr_pay_label($o);
        if ($bucket === 'other') { $hasOther = true; }
        $rowData[] = [
            'label'  => $label,
            'state'  => $state,
            'bucket' => $bucket,
            'time'   => date('H:i', strtotime($o['order_date'])),
            'no'     => '#' . str_pad((string)(int)$o['daily_order_no'], 4, '0', STR_PAD_LEFT),
            'cust'   => (($name = trim((string)$o['customer_name'])) === '' || $name === 'Guest') ? '—' : $name,
            'total'  => (float)$o['total'],
            'cups'   => $cupsByOrder[(int)$o['order_id']] ?? 0,
        ];
    }
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <p class="dr-note" style="margin-bottom:14px">money given back: <?= (int)$givenBackCount ?> &middot; drinks made again: <?= (int)$remadeCount ?></p>

      <div class="dr-pills" role="group" aria-label="Filter by how the order was paid">
        <button type="button" class="dr-pill is-on" data-filter="all">All</button>
        <button type="button" class="dr-pill" data-filter="cash">Cash</button>
        <button type="button" class="dr-pill" data-filter="bakong">Bakong</button>
        <button type="button" class="dr-pill" data-filter="paylater">Pay later</button>
        <?php if ($hasOther): ?>
        <button type="button" class="dr-pill" data-filter="other">Other</button>
        <?php endif; ?>
      </div>

      <div class="dr-table-wrap">
        <table class="dr-table" id="ordersTable">
          <thead>
            <tr><th>Time</th><th>Order</th><th>Customer</th><th>Total</th><th>Paid</th></tr>
          </thead>
          <tbody>
            <?php if (!$rowData): ?>
            <tr><td colspan="5" class="dr-note" style="padding:20px 0;text-align:center">no orders this day</td></tr>
            <?php endif; ?>
            <?php foreach ($rowData as $rd): ?>
            <tr class="<?= $rd['state'] === 'open' ? 'is-open' : '' ?>"
                data-method="<?= htmlspecialchars($rd['bucket']) ?>"
                data-state="<?= htmlspecialchars($rd['state']) ?>"
                data-total="<?= htmlspecialchars((string)$rd['total']) ?>"
                data-cups="<?= (int)$rd['cups'] ?>">
              <td><?= htmlspecialchars($rd['time']) ?></td>
              <td><?= htmlspecialchars($rd['no']) ?></td>
              <td><?= htmlspecialchars($rd['cust']) ?></td>
              <td>$<?= htmlspecialchars(number_format($rd['total'], 2)) ?></td>
              <td><?= htmlspecialchars($rd['label']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="dr-table-foot" id="ordersFoot">
        <span><?= (int)count($rowData) ?> orders</span> &middot; <span><?= (int)$cupsToday ?> cups</span> &middot; <span>$<?= htmlspecialchars(number_format($collectedTotal, 2)) ?> collected</span><?php if ($notPaidCount > 0): ?> &middot; <span>$<?= htmlspecialchars(number_format($notPaidTotal, 2)) ?> not paid yet</span><?php endif; ?>
      </div>

      <div class="dr-pagination" id="ordersPagination"></div>
    </div>
    <?php
}

// ── Tab 3: Stock (Task 7) — levels, what today used, what needs buying ──
/**
 * Quantity formatting shared by the stock table: whole numbers print plain,
 * fractional ones (ingredient_history.amount is DECIMAL(10,4), so "used
 * today" can be fractional even though stock_quantity/minimum_stock are
 * always ints) get two decimal places.
 */
function dr_qty(float $n): string {
    return (abs($n - round($n)) < 0.005) ? number_format($n, 0) : number_format($n, 2);
}

function dr_fragment_stock(mysqli $conn, string $date): void {
    // Same 06:00-to-06:00 business-day window as tab 1's usedValue query —
    // ingredient_history has no business_date column, and joining through
    // orders would silently drop the order_deduct rows with a NULL order_id.
    $stmt = $conn->prepare("
        SELECT i.ingredient_id, i.ingredient_name, i.unit, i.stock_quantity,
               i.minimum_stock, i.cost_per_unit,
               COALESCE(u.used, 0) AS used_today
        FROM ingredients i
        LEFT JOIN (
            SELECT ingredient_id, SUM(ABS(amount)) AS used
            FROM ingredient_history
            WHERE change_type = 'order_deduct'
              AND created_at >= CONCAT(?, ' 06:00:00')
              AND created_at <  CONCAT(DATE_ADD(?, INTERVAL 1 DAY), ' 06:00:00')
            GROUP BY ingredient_id
        ) u ON u.ingredient_id = i.ingredient_id
        ORDER BY (i.stock_quantity - i.minimum_stock) ASC, i.ingredient_name ASC
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $trackedCount = count($rows);
    $buyCount = 0;
    $outCount = 0;
    $rowData  = [];
    foreach ($rows as $r) {
        $stock = (int)$r['stock_quantity'];
        $min   = (int)$r['minimum_stock'];
        $used  = (float)$r['used_today'];
        $cost  = (float)$r['cost_per_unit'];

        // Three logical states, but only two the manager needs to act on:
        // "buy now" is the only one that ever gets amber — it is also the
        // exact test tab 1's $lowItems uses, so the badge and this table
        // never disagree. "low" is shown as a heads-up label only, styled
        // the same neutral way as "OK" — nothing red or green on this tab.
        if ($stock <= $min) {
            $bucket = 'buy';   $statusLabel = 'buy now';
            $buyCount++;
        } elseif ($min > 0 && $stock <= $min * 1.25) {
            $bucket = 'ok';    $statusLabel = 'getting low';
        } else {
            $bucket = 'ok';    $statusLabel = 'have enough';
        }
        if ($stock <= 0) { $outCount++; }

        $barBase = max(1, $min * 2);
        $barPct  = min(100, ($stock / $barBase) * 100);

        $rowData[] = [
            'name'    => (string)$r['ingredient_name'],
            'unit'    => (string)$r['unit'],
            'stock'   => $stock,
            'min'     => $min,
            'used'    => $used,
            'cost'    => $cost,
            'bucket'  => $bucket,
            'label'   => $statusLabel,
            'needBuy' => $bucket === 'buy',
            'barPct'  => $barPct,
        ];
    }
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <div class="dr-facts-inline" style="margin-top:0">
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$trackedCount ?></div><div class="dr-note">items we track</div></div>
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$buyCount ?></div><div class="dr-note">items to buy</div></div>
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$outCount ?></div><div class="dr-note">items already out</div></div>
      </div>

      <div class="dr-toolbar">
        <div class="dr-pills" role="group" aria-label="Filter by whether we need to buy more">
          <button type="button" class="dr-pill is-on" data-filter="all">All</button>
          <button type="button" class="dr-pill" data-filter="buy">Need buying</button>
          <button type="button" class="dr-pill" data-filter="ok">OK</button>
        </div>
        <input type="text" class="dr-search" id="stockSearch" placeholder="Search ingredient…" aria-label="Search ingredients">
      </div>

      <div class="dr-table-wrap">
        <table class="dr-table" id="stockTable">
          <thead>
            <tr><th>Item</th><th>We have</th><th>Buy more below</th><th>Used today</th><th>What it costs us (per unit)</th><th>Level</th></tr>
          </thead>
          <tbody>
            <?php if (!$rowData): ?>
            <tr><td colspan="6" class="dr-note" style="padding:20px 0;text-align:center">no ingredients tracked</td></tr>
            <?php endif; ?>
            <?php foreach ($rowData as $rd): ?>
            <tr class="<?= $rd['needBuy'] ? 'needs-buy' : '' ?>"
                data-bucket="<?= htmlspecialchars($rd['bucket']) ?>"
                data-name="<?= htmlspecialchars(mb_strtolower($rd['name'])) ?>">
              <td><?= htmlspecialchars($rd['name']) ?></td>
              <td><?= htmlspecialchars(dr_qty($rd['stock'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td><?= htmlspecialchars(dr_qty($rd['min'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td><?= htmlspecialchars(dr_qty($rd['used'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td>$<?= htmlspecialchars(number_format($rd['cost'], 4)) ?> / <?= htmlspecialchars($rd['unit']) ?></td>
              <td>
                <div class="dr-stock-bar"><span class="dr-stock-fill <?= $rd['needBuy'] ? 'tone-attn' : 'tone-normal' ?>" style="width:<?= round($rd['barPct'], 2) ?>%"></span></div>
                <div class="dr-note" style="margin-top:4px"><?= htmlspecialchars($rd['label']) ?></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
}

// ── Tab 4: Staff (Task 8) — who worked today and what they served ──
//
// LANDMINE: orders.employee_id is a FK to employees.employee_id, which is NOT
// users.user_id and the two never coincide. attendance keys on user_id. The
// only correct chain is attendance.user_id -> employees.user_id ->
// employees.employee_id -> orders.employee_id. Joining orders to attendance
// any more directly attributes work to the wrong person (or nobody) and has
// produced silently wrong staff figures in this codebase before.
// employees.user_id carries a UNIQUE key, so this chain cannot fan out one
// attendance row into more than one employee.

/**
 * "two shifts" / "three shifts" — spelled out for the common small counts a
 * split-shift day actually produces, numeric fallback beyond that.
 */
function dr_shift_note(int $n): string {
    static $words = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five'];
    return ($words[$n] ?? (string)$n) . ' shifts';
}

function dr_fragment_staff(mysqli $conn, string $date): void {
    // Order/money aggregates are computed per employee_id in their own
    // subquery, then joined in once. Aggregating orders directly against a
    // one-row-per-shift attendance join would fan out: a person with two
    // attendance rows that day would multiply their order rows by two before
    // GROUP BY ever runs, doubling both the order count and the money.
    $stmt = $conn->prepare("
        SELECT e.employee_id, e.name AS full_name,
               MIN(a.clock_in)                                    AS clock_in,
               MAX(a.clock_out)                                   AS clock_out,
               SUM(a.hours_worked)                                AS hours_worked,
               COUNT(a.id)                                        AS shift_count,
               SUM(a.clock_out IS NULL)                           AS open_shifts,
               COALESCE(MAX(o.orders_served), 0)                  AS orders_served,
               COALESCE(MAX(o.money_taken), 0)                    AS money_taken
        FROM attendance a
        JOIN employees e ON e.user_id = a.user_id
        LEFT JOIN (
            SELECT employee_id,
                   COUNT(order_id)          AS orders_served,
                   COALESCE(SUM(total), 0)  AS money_taken
            FROM orders
            WHERE business_date = ? AND " . paid_orders_where() . "
            GROUP BY employee_id
        ) o ON o.employee_id = e.employee_id
        WHERE a.date = ?
        GROUP BY e.employee_id
        ORDER BY MIN(a.clock_in) ASC
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Money a person "took" means money actually collected (paid_orders_where
    // above), never an open pay-later tab they merely rang up. One row per
    // person now (split shifts collapsed above), so a plain sum is correct —
    // no per-employee dedup needed.
    $peopleCount = count($rows);
    $ordersTotal = 0;
    $moneyTotal  = 0.0;
    foreach ($rows as $r) {
        $ordersTotal += (int)$r['orders_served'];
        $moneyTotal  += (float)$r['money_taken'];
    }

    // The day's collected money (tab 1's own figure, same paid_orders_where()
    // test) minus what landed on someone who was actually clocked in. Orders
    // with a NULL employee_id — or an employee_id that isn't in today's
    // attendance at all — never show up in $moneyTotal above, so without this
    // line the table would silently show a column of dashes next to a tab 1
    // total that says money came in, and read as broken rather than honest.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE business_date = ? AND " . paid_orders_where());
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $collectedTotal = (float)$stmt->get_result()->fetch_row()[0];
    $unlinkedMoney  = $collectedTotal - $moneyTotal;
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <div class="dr-table-wrap">
        <table class="dr-table" id="staffTable">
          <thead>
            <tr><th>Name</th><th>Clocked in</th><th>Clocked out</th><th>Hours</th><th>Orders served</th><th>Money taken</th></tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="6" class="dr-note" style="padding:20px 0;text-align:center">no one worked this day</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                // Any open shift that day means the person reads as still
                // working — the normal case for a manager reading this
                // mid-shift, not an edge case. hours_worked is genuinely NULL
                // until clock-out sets it, so a shift in progress must never
                // be summed into fmt_hours() (non-nullable float param); we
                // simply don't use the hours figure while still working.
                $stillWorking = (int)$r['open_shifts'] > 0;
                $shiftCount   = (int)$r['shift_count'];
                $orders = (int)$r['orders_served'];
                $money  = (float)$r['money_taken'];
                $served = $orders > 0;

                $hoursCell = $stillWorking
                    ? 'still working'
                    : ($r['hours_worked'] !== null ? fmt_hours((float)$r['hours_worked']) : '—');
                if ($shiftCount > 1) { $hoursCell .= ' · ' . dr_shift_note($shiftCount); }
            ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['full_name']) ?></td>
              <td><?= htmlspecialchars(date('H:i', strtotime($r['clock_in']))) ?></td>
              <td><?= $stillWorking ? 'still working' : htmlspecialchars(date('H:i', strtotime($r['clock_out']))) ?></td>
              <td><?= htmlspecialchars($hoursCell) ?></td>
              <td><?= $served ? (int)$orders : '—' ?></td>
              <td><?= $served ? '$' . htmlspecialchars(number_format($money, 2)) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($rows): ?>
      <div class="dr-table-foot" id="staffFoot">
        <span><?= (int)$peopleCount ?> people worked</span> &middot; <span><?= (int)$ordersTotal ?> orders served</span> &middot; <span>$<?= htmlspecialchars(number_format($moneyTotal, 2)) ?> taken</span>
      </div>
      <?php endif; ?>

      <?php if ($unlinkedMoney > 0.005): ?>
      <p class="dr-note" style="margin-top:12px">$<?= htmlspecialchars(number_format($unlinkedMoney, 2)) ?> collected today is not linked to anyone who was clocked in.</p>
      <?php endif; ?>
    </div>
    <?php
}

// Tabs 2-4 ask for their own HTML. Each branch echoes a fragment and exits.
// This MUST run before any HTML output — a fragment response is inlined into
// a tab panel by JS, so it must never carry the page chrome.
$fragment = $_GET['fragment'] ?? '';
if ($fragment !== '') {
    header('Content-Type: text/html; charset=utf-8');
    switch ($fragment) {
        case 'orders': dr_fragment_orders($conn, $date); break;
        case 'stock':  dr_fragment_stock($conn, $date); break;
        case 'staff':  dr_fragment_staff($conn, $date); break;
        default: http_response_code(404); echo 'Unknown tab.';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Report | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --bg-card:var(--surface);--text-muted:var(--muted2);
  --radius:14px;
}
[data-theme="light"]{
  --bg:#F4F1EC;--surface:#FFFFFF;--surface2:#FAF8F5;--border:rgba(0,0,0,.09);
  --text:#1a1410;--muted:#9a8f84;--muted2:#6b6259;
  --bg-card:var(--surface);--text-muted:var(--muted2);
}
body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),var(--bg);
  color:var(--text);min-height:100vh;
}
[data-theme="light"] body{background:var(--bg);}

.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 60px}

.back-btn{
  display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--amber);
  font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;
  border:1px solid var(--amber-border);background:var(--amber-dim);transition:all .2s;
  margin-bottom:18px;
}
.back-btn:hover{background:rgba(209,144,75,.2)}

/* ── Header ── */
.dr-head{
  display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;
  margin-bottom:20px;
}
.dr-eyebrow{font-size:11px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:4px}
.dr-head h1{font-size:24px;font-weight:700;color:var(--text)}
.dr-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dr-nav{
  display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:9px;
  background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;font-family:'Poppins',sans-serif;
  transition:all .2s;
}
.dr-nav:hover{border-color:var(--amber);color:var(--amber)}
.dr-nav.is-disabled{pointer-events:none;opacity:.35}

/* ── Tabs ── */
.dr-tabs{
  display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px;
  overflow-x:auto;
}
.dr-tab{
  appearance:none;background:none;border:none;border-bottom:2px solid transparent;
  padding:11px 16px;font-size:13.5px;font-weight:600;color:var(--muted2);
  font-family:'Poppins',sans-serif;cursor:pointer;white-space:nowrap;
  display:inline-flex;align-items:center;gap:7px;
}
.dr-tab:hover{color:var(--text)}
.dr-tab.is-on{color:var(--amber);border-bottom-color:var(--amber)}
.dr-badge{
  display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;
  padding:0 5px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);
  color:var(--muted2);font-size:10px;font-weight:700;
}
.dr-badge:empty{display:none}

/* ── Panels ── */
.dr-panel{animation:fadeInUp .3s ease both}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.dr-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 20px;
}
.dr-loading,.dr-error{
  padding:48px 20px;text-align:center;color:var(--muted2);font-size:13.5px;
}
.dr-error button{
  margin-left:8px;padding:6px 12px;border-radius:8px;border:1px solid var(--amber-border);
  background:var(--amber-dim);color:var(--amber);font-size:12.5px;font-weight:600;
  cursor:pointer;font-family:'Poppins',sans-serif;
}

/* ── Tab 1: the three verdicts ── */
.dr-verdicts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
@media (max-width: 900px) { .dr-verdicts { grid-template-columns: 1fr; } }

.dr-verdict { border-radius: 18px; padding: 20px 22px; border: 1px solid var(--border); background: var(--bg-card); border-top-width: 4px; }
.dr-verdict.tone-good { border-top-color: #2ecc71; }
.dr-verdict.tone-bad  { border-top-color: #e74c3c; }
.dr-verdict.tone-flat { border-top-color: var(--border); }
.tone-good .dr-line { color: #2ecc71; }
.tone-bad  .dr-line { color: #e74c3c; }
.tone-flat .dr-line { color: var(--text-muted); }

.dr-q     { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
/* Khmer needs more leading than Latin or the diacritics clip. */
.dr-q-km  { font-size: 12.5px; line-height: 1.9; color: var(--text-muted); margin-bottom: 10px; }
.dr-sub-km{ font-size: 12px;   line-height: 1.9; color: var(--text-muted); }
.dr-big   { font-size: 34px; font-weight: 800; font-variant-numeric: tabular-nums; }
.dr-line  { font-weight: 700; margin-top: 10px; }
.dr-foot  { font-size: 12px; color: var(--text-muted); margin-top: 8px; line-height: 1.7; }

/* ── Tab 1: the neutral row — facts, no colour ── */
.dr-facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 16px; }
@media (max-width: 900px) { .dr-facts { grid-template-columns: repeat(2, 1fr); } }
.dr-k     { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
.dr-k-km  { font-size: 12px; line-height: 1.8; color: var(--text-muted); margin-bottom: 6px; }
.dr-v     { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; margin-top: 2px; }
.dr-note  { font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6; }
.dr-wide  { margin-top: 16px; }

.dr-bar   { display: flex; width: 100%; height: 14px; border-radius: 8px; overflow: hidden; background: var(--surface2); margin-top: 10px; }
.seg      { display: block; height: 100%; }
/* One hue, descending strength — segments are labelled underneath, so they
   don't need to be independently identifiable by colour. Never red/green:
   colour on this page means "act on this", reserved for the verdict boxes. */
.seg-cash   { background: rgba(209,144,75,1);    }
.seg-bakong { background: rgba(209,144,75,.65);  }
.seg-later  { background: rgba(209,144,75,.4);   }
.seg-other  { background: rgba(209,144,75,.22);  }

.dr-facts-inline { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 12px; }
@media (max-width: 900px) { .dr-facts-inline { grid-template-columns: repeat(2, 1fr); } }
.dr-fact  { display: flex; flex-direction: column; }
.dr-v-sm  { font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
.dr-v-text{ font-size: 16px; font-weight: 700; font-variant-numeric: initial; }

/* ── Tab 2: Orders ── */
.dr-pills   { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.dr-pill    {
  appearance: none; font-family: 'Poppins',sans-serif; cursor: pointer;
  padding: 7px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 600;
  background: var(--surface2); border: 1px solid var(--border); color: var(--text-muted);
  transition: all .15s;
}
.dr-pill:hover  { color: var(--text); }
.dr-pill.is-on  { background: var(--amber-dim); border-color: var(--amber-border); color: var(--amber); }

.dr-table-wrap  { overflow-x: auto; }
.dr-table       { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.dr-table th    {
  text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
  color: var(--text-muted); padding: 8px 10px; border-bottom: 1px solid var(--border);
}
.dr-table td    { padding: 9px 10px; border-bottom: 1px solid var(--border); font-variant-numeric: tabular-nums; }
.dr-table tbody tr:last-child td { border-bottom: none; }
/* Amber = needs attention, not a verdict colour — reserved for tab 1. */
.dr-table tr.is-open td { background: var(--amber-dim); }
.dr-table tr.is-open td:first-child { border-left: 3px solid var(--amber); }

.dr-table-foot  { display: flex; gap: 10px; margin-top: 12px; font-size: 12.5px; color: var(--text-muted); }
.dr-pagination  { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 16px; }
.dr-pagination .dr-nav[disabled] { opacity: .35; pointer-events: none; }

/* ── Tab 3: Stock ── */
.dr-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 16px 0 14px; }
.dr-search  {
  font-family: 'Poppins',sans-serif; font-size: 12.5px; color: var(--text);
  background: var(--surface2); border: 1px solid var(--border); border-radius: 20px;
  padding: 8px 14px; min-width: 200px;
}
.dr-search:focus { outline: none; border-color: var(--amber); }
/* Amber = needs attention, same rule as the orders tab's open rows — never a
   verdict colour. Rows that don't need buying get a neutral, colourless bar. */
.dr-table tr.needs-buy td { background: var(--amber-dim); }
.dr-table tr.needs-buy td:first-child { border-left: 3px solid var(--amber); }
.dr-stock-bar  { width: 100px; height: 8px; border-radius: 6px; overflow: hidden; background: var(--surface2); }
.dr-stock-fill { display: block; height: 100%; }
.dr-stock-fill.tone-attn   { background: var(--amber); }
.dr-stock-fill.tone-normal { background: var(--muted2); }

@media print {
    .dr-tabs, .dr-head-actions, .sidebar, .dr-nav, .back-btn { display: none !important; }
    .dr-panel[hidden] { display: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .dr-card { break-inside: avoid; border: 1px solid #ccc !important; }
}
</style>
</head>
<body>

<div class="wrap">

    <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>

    <div class="dr-head">
        <div>
            <div class="dr-eyebrow">DAILY REPORT</div>
            <h1><?= date('l, F j, Y', strtotime($date)) ?></h1>
        </div>
        <div class="dr-head-actions">
            <a class="dr-nav" href="?date=<?= htmlspecialchars($prevDate) ?>"><i class="fa-solid fa-chevron-left"></i> Yesterday</a>
            <a class="dr-nav <?= $isToday ? 'is-disabled' : '' ?>" href="?date=<?= htmlspecialchars($nextDate) ?>">Tomorrow <i class="fa-solid fa-chevron-right"></i></a>
            <button class="dr-nav" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <a class="dr-nav" href="report.php?mode=daily&date=<?= htmlspecialchars($date) ?>">Full analytics <i class="fa-solid fa-arrow-right"></i></a>
        </div>
    </div>

    <div class="dr-tabs" role="tablist">
        <button class="dr-tab is-on" data-tab="today"  role="tab">Today</button>
        <button class="dr-tab"       data-tab="orders" role="tab">Orders</button>
        <button class="dr-tab"       data-tab="stock"  role="tab">Stock <span class="dr-badge" id="stockBadge"><?= $lowItems ? (int)$lowItems : '' ?></span></button>
        <button class="dr-tab"       data-tab="staff"  role="tab">Staff</button>
    </div>
    <div class="dr-panel" id="panel-today">
        <div class="dr-verdicts">
          <div class="dr-verdict tone-<?= $vGot['tone'] ?>">
            <div class="dr-q">Did we earn more?</div>
            <div class="dr-q-km">តើយើងរកចំណូលបានច្រើនជាងមុនទេ?</div>
            <div class="dr-big">$<?= number_format($gotToday, 2) ?></div>
            <div class="dr-sub">money we got today</div>
            <div class="dr-sub-km">ប្រាក់ចំណូលថ្ងៃនេះ</div>
            <div class="dr-line"><?= htmlspecialchars($vGot['line']) ?> <?= htmlspecialchars($vGot['sub']) ?></div>
          </div>

          <div class="dr-verdict tone-<?= $vKept['tone'] ?>">
            <div class="dr-q">Did we keep more?</div>
            <div class="dr-q-km">តើយើងរកប្រាក់ចំណេញបានច្រើនជាងមុនទេ?</div>
            <div class="dr-big">$<?= number_format($keptToday, 2) ?></div>
            <div class="dr-sub">money we keep</div>
            <div class="dr-sub-km">ប្រាក់ចំណេញ</div>
            <div class="dr-line"><?= htmlspecialchars($vKept['line']) ?> <?= htmlspecialchars($vKept['sub']) ?></div>
            <div class="dr-foot">we keep <?= (int)$centsKept ?>¢ of each $1</div>
          </div>

          <div class="dr-verdict tone-<?= $lowItems ? 'bad' : 'good' ?>">
            <div class="dr-q">Can we open tomorrow?</div>
            <div class="dr-q-km">តើយើងអាចបើកហាងស្អែកបានទេ?</div>
            <div class="dr-big"><?= $lowItems ? 'NO' : 'YES' ?></div>
            <div class="dr-sub">
              <?php if ($lowItems): ?>
                buy more <?= htmlspecialchars(implode(', ', $lowNames)) ?><?= $lowExtra ? " and $lowExtra more" : '' ?>
              <?php else: ?>
                every item is above its buy-more level
              <?php endif; ?>
            </div>
            <div class="dr-foot">
              stock we have $<?= number_format($stockValue, 2) ?> · ស្តុកដែលមាន<br>
              used today $<?= number_format($usedValue, 2) ?>
            </div>
          </div>
        </div>

        <div class="dr-facts">
          <div class="dr-card"><div class="dr-k">cash</div><div class="dr-k-km">សាច់ប្រាក់</div><div class="dr-v">$<?= number_format($gotCash, 2) ?></div><div class="dr-note">in the register</div></div>
          <div class="dr-card"><div class="dr-k">bakong</div><div class="dr-k-km">បាគង</div><div class="dr-v">$<?= number_format($gotBakong, 2) ?></div><div class="dr-note">by phone</div></div>
          <div class="dr-card"><div class="dr-k">pay later — paid</div><div class="dr-k-km">បង់ក្រោយ — បង់រួច</div><div class="dr-v">$<?= number_format($gotLater, 2) ?></div><div class="dr-note">settled today</div></div>
          <div class="dr-card"><div class="dr-k">not paid yet</div><div class="dr-k-km">មិនទាន់បង់</div><div class="dr-v">$<?= number_format($notPaidYet, 2) ?></div><div class="dr-note"><?= (int)$notPaidCount ?> open tab(s)</div></div>
          <?php if ($gotOther > 0.01): ?>
          <div class="dr-card"><div class="dr-k">other ways</div><div class="dr-v">$<?= number_format($gotOther, 2) ?></div><div class="dr-note">older orders that did not record how they were paid</div></div>
          <?php endif; ?>
        </div>

        <div class="dr-card dr-wide">
          <div class="dr-k">how the money came in</div>
          <?php if ($gotToday > 0): ?>
            <div class="dr-bar">
              <?php foreach ([['cash',$gotCash],['bakong',$gotBakong],['later',$gotLater],['other',$gotOther]] as [$cls,$amt]):
                  if ($amt <= 0) { continue; }
                  $pct = ($amt / $gotToday) * 100; ?>
                  <span class="seg seg-<?= $cls ?>" style="width:<?= round($pct, 2) ?>%"></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="dr-note">no sales yet today</p>
          <?php endif; ?>
          <?php if ($notPaidYet > 0): ?>
            <p class="dr-note">$<?= number_format($notPaidYet, 2) ?> not paid yet. We count it only when the customer pays.</p>
          <?php endif; ?>
        </div>

        <div class="dr-card dr-wide">
          <div class="dr-k">what sold</div>
          <?php if ($paidOrderCount > 0 && $cogs['items'] > 0): ?>
            <div class="dr-facts-inline">
              <div class="dr-fact"><div class="dr-v-sm"><?= (int)$paidOrderCount ?></div><div class="dr-note">orders</div></div>
              <div class="dr-fact"><div class="dr-v-sm"><?= (int)$cogs['items'] ?></div><div class="dr-note">cups sold</div></div>
              <div class="dr-fact"><div class="dr-v-sm"><?= $avgCups !== null ? number_format($avgCups, 1) : '—' ?></div><div class="dr-note">cups per order</div></div>
              <div class="dr-fact"><div class="dr-v-sm dr-v-text"><?= htmlspecialchars($bestSellerName ?? '—') ?></div><div class="dr-note">best seller<?= $bestSellerQty ? ' · ' . (int)$bestSellerQty . ' sold' : '' ?></div></div>
            </div>
          <?php else: ?>
            <p class="dr-note">no sales yet today</p>
          <?php endif; ?>
        </div>
    </div>
    <div class="dr-panel" id="panel-orders" hidden></div>
    <div class="dr-panel" id="panel-stock"  hidden></div>
    <div class="dr-panel" id="panel-staff"  hidden></div>

</div>

<script>
const drLoaded = {};           // tab -> true once its HTML has arrived
const DR_DATE  = <?= json_encode($date) ?>;

function drShowTab(tab) {
    const btn = document.querySelector('.dr-tab[data-tab="' + tab + '"]');
    if (!btn) return;
    document.querySelectorAll('.dr-tab').forEach(b => b.classList.toggle('is-on', b === btn));
    document.querySelectorAll('.dr-panel').forEach(p => p.hidden = (p.id !== 'panel-' + tab));
    if (tab !== 'today' && !drLoaded[tab]) { loadFragment(tab); }
    try { sessionStorage.setItem('drActiveTab:' + DR_DATE, tab); } catch (e) {}
}

document.querySelectorAll('.dr-tab').forEach(btn => {
    btn.addEventListener('click', () => drShowTab(btn.dataset.tab));
});

// A poll-triggered reload must not throw away where the manager was reading —
// restore the tab they had open, and their scroll position, right after load.
(function drRestoreAfterReload() {
    try {
        const savedTab = sessionStorage.getItem('drActiveTab:' + DR_DATE);
        if (savedTab && savedTab !== 'today') { drShowTab(savedTab); }
        const savedY = sessionStorage.getItem('drScrollY:' + DR_DATE);
        if (savedY !== null) {
            sessionStorage.removeItem('drScrollY:' + DR_DATE);
            window.scrollTo(0, parseInt(savedY, 10) || 0);
        }
    } catch (e) {}
})();

async function loadFragment(tab) {
    const panel = document.getElementById('panel-' + tab);
    panel.innerHTML = '<div class="dr-loading">Loading…</div>';
    try {
        const res  = await fetch('daily_report.php?fragment=' + tab + '&date=' + encodeURIComponent(DR_DATE));
        if (!res.ok) throw new Error(res.status);
        panel.innerHTML = await res.text();
        drLoaded[tab] = true;
        // Fragment HTML is set via innerHTML, so any <script> inside it is
        // inert. Wire up per-tab behaviour here instead, keyed by tab name.
        const initFn = window['drInit_' + tab];
        if (typeof initFn === 'function') initFn();
    } catch (e) {
        // Never leave a blank panel — say what happened and offer the retry.
        panel.innerHTML = '<div class="dr-error">Could not load this tab. '
                        + '<button onclick="loadFragment(\'' + tab + '\')">Try again</button></div>';
    }
}

// ── Tab 2: Orders — filter pills + client-side pagination ──
// Run once per fragment load (loadFragment calls this after innerHTML is set,
// since <script> tags inside injected HTML never execute).
function drInit_orders() {
    const panel = document.getElementById('panel-orders');
    const table = panel && panel.querySelector('#ordersTable');
    if (!table) return;
    const pageSize = 25;
    const allRows  = Array.from(table.querySelectorAll('tbody tr[data-method]'));
    const pager    = panel.querySelector('#ordersPagination');
    const foot     = panel.querySelector('#ordersFoot');
    let filtered = allRows.slice();
    let page = 1;

    function render() {
        allRows.forEach(r => { r.style.display = 'none'; });
        const start = (page - 1) * pageSize;
        filtered.slice(start, start + pageSize).forEach(r => { r.style.display = ''; });
        renderPager();
        renderFoot();
    }

    // Describes the currently-filtered set (all matching rows, not just the
    // page on screen) — clicking a pill must change what the footer says, or
    // it silently contradicts the table exactly like the "$X total" bug did.
    function renderFoot() {
        if (!foot) return;
        let cups = 0, collected = 0, notPaid = 0, notPaidCount = 0;
        filtered.forEach(r => {
            cups += parseInt(r.dataset.cups || '0', 10);
            const total = parseFloat(r.dataset.total || '0');
            if (r.dataset.state === 'open') { notPaid += total; notPaidCount++; }
            else { collected += total; }
        });
        let html = '<span>' + filtered.length + ' orders</span> · <span>' + cups + ' cups</span> · '
                 + '<span>$' + collected.toFixed(2) + ' collected</span>';
        if (notPaidCount > 0) {
            html += ' · <span>$' + notPaid.toFixed(2) + ' not paid yet</span>';
        }
        foot.innerHTML = html;
    }

    function renderPager() {
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (!pager) return;
        if (totalPages <= 1) { pager.innerHTML = ''; return; }
        pager.innerHTML =
            '<button type="button" class="dr-nav" data-page="prev"' + (page <= 1 ? ' disabled' : '') + '>' +
            '<i class="fa-solid fa-chevron-left"></i> Prev</button>' +
            '<span class="dr-note">Page ' + page + ' of ' + totalPages + '</span>' +
            '<button type="button" class="dr-nav" data-page="next"' + (page >= totalPages ? ' disabled' : '') + '>' +
            'Next <i class="fa-solid fa-chevron-right"></i></button>';
        pager.querySelectorAll('button[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.dataset.page === 'prev' && page > 1) page--;
                if (btn.dataset.page === 'next' && page < totalPages) page++;
                render();
            });
        });
    }

    panel.querySelectorAll('.dr-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.dr-pill').forEach(b => b.classList.toggle('is-on', b === btn));
            const f = btn.dataset.filter;
            filtered = (f === 'all') ? allRows.slice() : allRows.filter(r => r.dataset.method === f);
            page = 1;
            render();
        });
    });

    render();
}

// ── Tab 3: Stock — filter pills + live search over ingredient name ──
function drInit_stock() {
    const panel = document.getElementById('panel-stock');
    const table = panel && panel.querySelector('#stockTable');
    if (!table) return;
    const allRows = Array.from(table.querySelectorAll('tbody tr[data-bucket]'));
    const search  = panel.querySelector('#stockSearch');
    let activeFilter = 'all';

    function apply() {
        const q = (search && search.value || '').trim().toLowerCase();
        allRows.forEach(r => {
            const matchesFilter = activeFilter === 'all' || r.dataset.bucket === activeFilter;
            const matchesSearch = !q || r.dataset.name.includes(q);
            r.style.display = (matchesFilter && matchesSearch) ? '' : 'none';
        });
    }

    panel.querySelectorAll('.dr-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.dr-pill').forEach(b => b.classList.toggle('is-on', b === btn));
            activeFilter = btn.dataset.filter;
            apply();
        });
    });

    if (search) search.addEventListener('input', apply);

    apply();
}

// ── Live refresh (Task 9) — poll a cheap signature, today only. A past date
// is settled history and must never repeat a network request for it.
const DR_IS_TODAY = <?= $isToday ? 'true' : 'false' ?>;
let drSig = null;
if (DR_IS_TODAY) {
    setInterval(async () => {
        try {
            const res = await fetch('daily_report.php?poll=1&date=' + encodeURIComponent(DR_DATE));
            const { sig } = await res.json();
            if (drSig !== null && sig !== drSig) {
                // Reloading mid-read is hostile to a manager part-way down a
                // table — stash where they were so drRestoreAfterReload can
                // put them back once the new page settles.
                try { sessionStorage.setItem('drScrollY:' + DR_DATE, String(window.scrollY)); } catch (e) {}
                window.location.reload();
            }
            drSig = sig;
        } catch (e) { /* a dropped poll is not worth interrupting the manager */ }
    }, 30000);
}

// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>
</body>
</html>
