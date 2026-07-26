# Daily Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `daily_report.php` — a one-business-day report that answers three manager questions (did we earn more, did we keep more, can we open tomorrow) in plain words, leaving `report.php` in place as the deep analytics page.

**Architecture:** One new PHP page in the codebase's existing procedural MySQLi style, with four tabs. Tab 1 renders server-side; tabs 2–4 fetch their own HTML fragment from the same file via `?fragment=<tab>`. Three pieces of costing/date logic move from `report.php` into `config.php` so both pages share one implementation. No framework, no build step, no new dependency.

**Tech Stack:** PHP 8.2 (procedural, MySQLi), MySQL, vanilla JS + fetch, inline `<style>` per page (the convention every page here follows), Font Awesome + Poppins (already loaded site-wide).

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-07-26-daily-report-redesign-design.md`. Read it before Task 1.
- **Permission slug is `report`** (singular). Gate: `if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }`
- **Collected money is always `paid_orders_where()`** from `config.php:243`. Never hand-write a revenue condition.
- **Day filtering is always `orders.business_date`**, never `DATE(order_date)` and never a `BETWEEN` on `order_date`. (`report.php` uses the 6am `order_date` window; 186 rows disagree with it. Do not copy that.)
- **Pay-later:** `status='Completed'` means made-but-still-owing (`is_open=1`); `status='Paid'` + `is_open=0` means settled. Never describe an unsettled tab as paid. This has caused three money bugs.
- **`orders.employee_id` → `employees.employee_id`**, NOT `users.user_id`. They never coincide.
- **Banned words** (spec has the full table): margin, revenue, profit, COGS, outstanding, variance, remake, refund, peak hour, avg order value. Use the plain replacements.
- **Colour rule:** only the three verdict boxes are red/green. Everything else is neutral.
- **No percentages on tab 1.** Differences are dollars.
- **Khmer strings** are fixed literals from the spec's table — copy them exactly, including `តើ` on the three questions.
- Escape all output with `htmlspecialchars()`. Use prepared statements for anything with a variable.
- **Commit after every task.** Use `git commit -F <file>` with a message file — the Bash tool is not PowerShell and here-strings mangle messages.

---

## File Structure

| File | Responsibility |
|---|---|
| `config.php` (modify) | Gains 3 shared helpers: `business_date_today()`, `ingredient_cost_map()`, `order_cogs()`. Also `weekday_baseline()` in Task 2. |
| `report.php` (modify) | Deletes its local copies of the above and calls the shared ones. No other change. |
| `daily_report.php` (create) | The whole new page: server-rendered tab 1, plus `?fragment=orders|stock|staff` returning HTML for tabs 2–4, plus `?poll=1` returning a JSON signature. |
| `tests/daily_report_test.php` (create) | CLI assertion script for the shared helpers and the baseline maths. Run with `php tests/daily_report_test.php`. |
| `nav_menu.php` (modify) | Points the Report nav entry at `daily_report.php`. |

There is no test framework in this project. `tests/daily_report_test.php` is a plain PHP CLI script with a tiny assert helper — it is real, runnable, and fast. UI behaviour is verified in the browser with explicit expected outcomes.

---

### Task 1: Shared costing helpers in config.php

Three pieces of logic currently live only inside `report.php`. `daily_report.php` needs all three. Extract them so the two pages cannot drift apart.

**Files:**
- Modify: `config.php` (append after `paid_orders_where()`, around line 247)
- Modify: `report.php:15-21` (delete `getBusinessDateToday`), `report.php:90-121` (delete cost-map block), `report.php:210-277` (call shared COGS)
- Create: `tests/daily_report_test.php`

**Interfaces:**
- Produces: `business_date_today(): string` — 'Y-m-d', rolling back one day before 06:00.
- Produces: `ingredient_cost_map(mysqli $conn): array` — keyed by BOTH `(int)ingredient_id` and `strtolower(trim(ingredient_name))`; each value `['name'=>string,'unit_cost'=>float]`.
- Produces: `order_cogs(mysqli $conn, array $orderIds, array $costMap): array` — returns `['total'=>float, 'items'=>int, 'by_product'=>array]` where `by_product` is keyed by product name with `['qty'=>int,'cost'=>float,'revenue'=>float]`.

- [ ] **Step 1: Write the failing test**

Create `tests/daily_report_test.php`:

```php
<?php
/**
 * CLI assertions for the daily report's shared helpers.
 * Run:  php tests/daily_report_test.php
 * There is no test framework in this project; this script is the harness.
 */
require __DIR__ . '/../config.php';

$failures = 0;
function check(string $what, $got, $want): void {
    global $failures;
    $ok = is_float($want) ? abs($got - $want) < 0.0001 : $got === $want;
    if ($ok) { echo "  PASS  $what\n"; return; }
    $failures++;
    echo "  FAIL  $what\n        got:  " . var_export($got, true)
       . "\n        want: " . var_export($want, true) . "\n";
}

echo "ingredient_cost_map\n";
$map = ingredient_cost_map($conn);
// Milk base is ingredient 48, priced 1.30 per 1000ml on 2026-07-26.
check('costs are keyed by id',    $map[48]['unit_cost'],            0.0013);
check('costs are keyed by name',  $map['milk base']['unit_cost'],   0.0013);
check('sugar syrup by id',        $map[49]['unit_cost'],            0.0025);
check('name key carries a name',  $map['milk base']['name'],        'Milk base');

echo "business_date_today\n";
check('returns a Y-m-d string', (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', business_date_today()), true);

echo "order_cogs\n";
$ids = [];
$r = $conn->query("SELECT order_id FROM orders WHERE business_date='2026-07-21' AND " . paid_orders_where());
while ($row = $r->fetch_row()) { $ids[] = (int)$row[0]; }
$cogs = order_cogs($conn, $ids, $map);
check('empty order list costs nothing', order_cogs($conn, [], $map)['total'], 0.0);
check('a real day has items',           $cogs['items'] > 0,                   true);
check('a real day costs something',     $cogs['total'] > 0,                   true);
check('cost never exceeds takings',     $cogs['total'] < 40.09,               true);

echo ($failures === 0) ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/daily_report_test.php`
Expected: FAIL — `Call to undefined function ingredient_cost_map()`.

- [ ] **Step 3: Add the helpers to config.php**

Append immediately after the `paid_orders_where()` block (~line 247):

```php
/**
 * The business day a moment belongs to. Trade before 06:00 belongs to the
 * previous calendar day, matching orders.business_date.
 */
if (!function_exists('business_date_today')) {
    function business_date_today(): string {
        $now = new DateTime();
        if ((int)$now->format('H') < 6) { $now->modify('-1 day'); }
        return $now->format('Y-m-d');
    }
}

/**
 * Cost per unit for every ingredient, keyed by id AND by lowercased name.
 * The name key exists because recipes name their milk by type at order time.
 * cost_per_unit is derived (cost_price / purchase_qty) and is authoritative;
 * dividing is only a fallback for rows that predate it being populated.
 */
if (!function_exists('ingredient_cost_map')) {
    function ingredient_cost_map(mysqli $conn): array {
        $map = [];
        $q = $conn->query("SELECT ingredient_id, ingredient_name, cost_price, purchase_qty, cost_per_unit FROM ingredients");
        while ($r = $q->fetch_assoc()) {
            $cpu  = (float)$r['cost_per_unit'];
            $pq   = (float)$r['purchase_qty'];
            $cost = $cpu > 0 ? $cpu : ($pq > 0 ? (float)$r['cost_price'] / $pq : 0.0);
            $entry = ['name' => $r['ingredient_name'], 'unit_cost' => $cost];
            $map[(int)$r['ingredient_id']] = $entry;
            $map[strtolower(trim($r['ingredient_name']))] = $entry + ['id' => (int)$r['ingredient_id']];
        }
        return $map;
    }
}

/**
 * What the drinks in these orders cost us in ingredients.
 * Ingredients whose name contains "milk" are resolved through the milk the
 * customer actually chose on the line, not the recipe's default.
 */
if (!function_exists('order_cogs')) {
    function order_cogs(mysqli $conn, array $orderIds, array $costMap): array {
        $out = ['total' => 0.0, 'items' => 0, 'by_product' => []];
        $ids = array_values(array_filter(array_map('intval', $orderIds)));
        if (!$ids) { return $out; }
        $in = implode(',', $ids);

        $items = [];
        $productIds = [];
        $q = $conn->query("
            SELECT oi.product_id, oi.product_name, oi.milk, oi.quantity, oi.price
            FROM order_items oi
            WHERE oi.order_id IN ($in) AND oi.price > 0
        ");
        while ($it = $q->fetch_assoc()) {
            $items[] = $it;
            if ((int)$it['product_id'] > 0) { $productIds[(int)$it['product_id']] = true; }
        }

        $recipes = [];
        if ($productIds) {
            $pin = implode(',', array_keys($productIds));
            $qr = $conn->query("
                SELECT pi.product_id, pi.ingredient_id, pi.amount_used, i.ingredient_name
                FROM product_ingredients pi
                JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
                WHERE pi.product_id IN ($pin)
            ");
            while ($r = $qr->fetch_assoc()) {
                $recipes[(int)$r['product_id']][] = [
                    'ingredient_id'   => (int)$r['ingredient_id'],
                    'ingredient_name' => $r['ingredient_name'],
                    'amount_used'     => (float)$r['amount_used'],
                ];
            }
        }

        foreach ($items as $it) {
            $pid  = (int)$it['product_id'];
            $qty  = max(1, (int)$it['quantity']);
            $milk = trim((string)$it['milk']);
            $name = (string)$it['product_name'];

            $cost = 0.0;
            foreach ($recipes[$pid] ?? [] as $rc) {
                $amount = $rc['amount_used'] * $qty;
                if ($amount <= 0) { continue; }
                if (strpos(strtolower(trim($rc['ingredient_name'])), 'milk') !== false) {
                    $key = strtolower(trim($milk !== '' ? $milk : 'Fresh Milk'));
                    if (isset($costMap[$key])) { $cost += $amount * (float)$costMap[$key]['unit_cost']; }
                } else {
                    $cost += $amount * (float)($costMap[$rc['ingredient_id']]['unit_cost'] ?? 0);
                }
            }

            $out['total'] += $cost;
            $out['items'] += $qty;
            if (!isset($out['by_product'][$name])) {
                $out['by_product'][$name] = ['qty' => 0, 'cost' => 0.0, 'revenue' => 0.0];
            }
            $out['by_product'][$name]['qty']     += $qty;
            $out['by_product'][$name]['cost']    += $cost;
            $out['by_product'][$name]['revenue'] += (float)($it['price'] ?? 0) * $qty;
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/daily_report_test.php`
Expected: `ALL PASS`, exit 0.

- [ ] **Step 5: Point report.php at the shared helpers**

In `report.php`: delete the `getBusinessDateToday()` function (lines 15-21) and replace its two call sites with `business_date_today()`. Delete the cost-map block (lines 90-121) and replace with `$ingredients = ingredient_cost_map($conn);`.

Leave the per-item loop at lines 210-277 alone for now — it also builds `$categorySales` and `$topProducts` in report.php's own shapes. Extracting it fully is out of scope; the cost map and date helper are what both pages share.

- [ ] **Step 6: Verify report.php still works**

Open `https://localhost/Cafe/report.php?mode=daily&date=2026-07-21` in the browser as admin (`Sokun` / `@Sokun9811`).
Expected: page renders; Food Cost and Profit cards show the same figures as before the change. Note them — Task 4 reuses them.

- [ ] **Step 7: Commit**

```bash
git add config.php report.php tests/daily_report_test.php
git commit -F <message-file>   # "refactor: share the ingredient cost map between reports"
```

---

### Task 2: The weekday baseline

The comparison that drives the verdict colours. Trade is weekly-seasonal, so today is measured against the same weekday, not against yesterday.

**Files:**
- Modify: `config.php` (append after `order_cogs()`)
- Modify: `tests/daily_report_test.php` (append assertions)

**Interfaces:**
- Consumes: `paid_orders_where()`, `business_date_today()`.
- Produces: `weekday_baseline(mysqli $conn, string $date, int $want = 4): array` returning
  `['value'=>float|null, 'basis'=>'weekday'|'yesterday'|'none', 'label'=>string, 'days'=>int]`.
  `label` is ready to print: `'a normal Sunday'`, `'yesterday'`, or `''`.

- [ ] **Step 1: Write the failing test**

Append to `tests/daily_report_test.php`, before the final summary block:

```php
echo "weekday_baseline\n";
// Sundays before 2026-07-26 with paid orders: 07-12 14.74, 06-14 85.75,
// 06-07 19.83, 05-31 78.99  ->  mean 49.8275.
$b = weekday_baseline($conn, '2026-07-26');
check('uses the same weekday',   $b['basis'], 'weekday');
check('averages the last four',  round($b['value'], 4), 49.8275);
check('reads as a normal Sunday',$b['label'], 'a normal Sunday');
check('counts the days used',    $b['days'],  4);

// A date with no prior same-weekday trading must not invent a comparison.
$early = weekday_baseline($conn, '2026-05-25');
check('degrades rather than lying', in_array($early['basis'], ['yesterday','none'], true), true);
check('never labels an empty basis', $early['basis'] === 'none' ? $early['label'] : 'a normal Sunday', $early['basis'] === 'none' ? '' : 'a normal Sunday');
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php tests/daily_report_test.php`
Expected: FAIL — `Call to undefined function weekday_baseline()`.

- [ ] **Step 3: Implement it**

Append to `config.php`:

```php
/**
 * What a normal <weekday> takes, for judging today against.
 *
 * A cafe's trade is weekly-seasonal, so Saturday is only fair against other
 * Saturdays. Averages the last $want same-weekday business dates that
 * actually traded. Some weekdays are thin, so this degrades: fewer than two
 * such days falls back to yesterday, and no yesterday returns no basis at
 * all rather than comparing today against a day that never happened.
 */
if (!function_exists('weekday_baseline')) {
    function weekday_baseline(mysqli $conn, string $date, int $want = 4): array {
        $none = ['value' => null, 'basis' => 'none', 'label' => '', 'days' => 0];

        $stmt = $conn->prepare("
            SELECT business_date, SUM(total) AS takings
            FROM orders
            WHERE business_date < ?
              AND DAYOFWEEK(business_date) = DAYOFWEEK(?)
              AND " . paid_orders_where() . "
            GROUP BY business_date
            HAVING takings > 0
            ORDER BY business_date DESC
            LIMIT ?
        ");
        $stmt->bind_param("ssi", $date, $date, $want);
        $stmt->execute();
        $days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (count($days) >= 2) {
            $sum = 0.0;
            foreach ($days as $d) { $sum += (float)$d['takings']; }
            return [
                'value' => $sum / count($days),
                'basis' => 'weekday',
                'label' => 'a normal ' . date('l', strtotime($date)),
                'days'  => count($days),
            ];
        }

        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $stmt = $conn->prepare("SELECT SUM(total) FROM orders WHERE business_date = ? AND " . paid_orders_where());
        $stmt->bind_param("s", $yesterday);
        $stmt->execute();
        $y = $stmt->get_result()->fetch_row()[0];

        if ($y === null || (float)$y <= 0) { return $none; }
        return ['value' => (float)$y, 'basis' => 'yesterday', 'label' => 'yesterday', 'days' => 1];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/daily_report_test.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add config.php tests/daily_report_test.php
git commit -F <message-file>   # "feat: measure a day against a normal weekday"
```

---

### Task 3: Page shell — auth, header, tabs, print

The frame everything else hangs on. No figures yet.

**Files:**
- Create: `daily_report.php`

**Interfaces:**
- Consumes: `business_date_today()`.
- Produces: the query contract `?date=YYYY-MM-DD` (which day), `?fragment=orders|stock|staff` (tab HTML), `?poll=1` (JSON signature). Tasks 6–9 fill these in.

- [ ] **Step 1: Create the page with its gate and date handling**

```php
<?php
require 'auth.php';
require 'config.php';
if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }

// Which business day are we reading? Defaults to the one in progress.
$today = business_date_today();
$date  = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = $today; }
if ($date > $today) { $date = $today; }
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday  = ($date === $today);
```

- [ ] **Step 2: Add the header and tab bar**

Follow the page conventions in `inventory_count.php`: inline `<style>`, Poppins, Font Awesome, the shared theme variables. Markup:

```php
<div class="dr-head">
    <div>
        <div class="dr-eyebrow">DAILY REPORT</div>
        <h1><?= date('l, F j, Y', strtotime($date)) ?></h1>
    </div>
    <div class="dr-head-actions">
        <a class="dr-nav" href="?date=<?= $prevDate ?>"><i class="fa-solid fa-chevron-left"></i> Yesterday</a>
        <a class="dr-nav <?= $isToday ? 'is-disabled' : '' ?>" href="?date=<?= $nextDate ?>">Tomorrow <i class="fa-solid fa-chevron-right"></i></a>
        <button class="dr-nav" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <a class="dr-nav" href="report.php?mode=daily&date=<?= $date ?>">Full analytics <i class="fa-solid fa-arrow-right"></i></a>
    </div>
</div>

<div class="dr-tabs" role="tablist">
    <button class="dr-tab is-on" data-tab="today"  role="tab">Today</button>
    <button class="dr-tab"       data-tab="orders" role="tab">Orders</button>
    <button class="dr-tab"       data-tab="stock"  role="tab">Stock <span class="dr-badge" id="stockBadge"></span></button>
    <button class="dr-tab"       data-tab="staff"  role="tab">Staff</button>
</div>
<div class="dr-panel" id="panel-today"><!-- Task 4 + 5 --></div>
<div class="dr-panel" id="panel-orders" hidden></div>
<div class="dr-panel" id="panel-stock"  hidden></div>
<div class="dr-panel" id="panel-staff"  hidden></div>
```

When `$isToday`, the Tomorrow link gets `is-disabled` (`pointer-events:none; opacity:.35`) — there is no tomorrow to report on.

- [ ] **Step 3: Add tab switching with lazy fragment loading**

```js
const drLoaded = {};           // tab -> true once its HTML has arrived
const DR_DATE  = <?= json_encode($date) ?>;

document.querySelectorAll('.dr-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.dr-tab').forEach(b => b.classList.toggle('is-on', b === btn));
        document.querySelectorAll('.dr-panel').forEach(p => p.hidden = (p.id !== 'panel-' + tab));
        if (tab !== 'today' && !drLoaded[tab]) { loadFragment(tab); }
    });
});

async function loadFragment(tab) {
    const panel = document.getElementById('panel-' + tab);
    panel.innerHTML = '<div class="dr-loading">Loading…</div>';
    try {
        const res  = await fetch('daily_report.php?fragment=' + tab + '&date=' + encodeURIComponent(DR_DATE));
        if (!res.ok) throw new Error(res.status);
        panel.innerHTML = await res.text();
        drLoaded[tab] = true;
    } catch (e) {
        // Never leave a blank panel — say what happened and offer the retry.
        panel.innerHTML = '<div class="dr-error">Could not load this tab. '
                        + '<button onclick="loadFragment(\'' + tab + '\')">Try again</button></div>';
    }
}
```

- [ ] **Step 4: Add the fragment router at the top of the file**

Directly after the date block, before any HTML output:

```php
// Tabs 2-4 ask for their own HTML. Each branch echoes a fragment and exits.
$fragment = $_GET['fragment'] ?? '';
if ($fragment !== '') {
    header('Content-Type: text/html; charset=utf-8');
    switch ($fragment) {
        case 'orders': /* Task 6 */ break;
        case 'stock':  /* Task 7 */ break;
        case 'staff':  /* Task 8 */ break;
        default: http_response_code(404); echo 'Unknown tab.';
    }
    exit;
}
```

- [ ] **Step 5: Add print styles**

```css
@media print {
    .dr-tabs, .dr-head-actions, .sidebar, .dr-nav { display: none !important; }
    .dr-panel[hidden] { display: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .dr-card { break-inside: avoid; border: 1px solid #ccc !important; }
}
```

- [ ] **Step 6: Verify in the browser**

Log in as admin, open `https://localhost/Cafe/daily_report.php`.
Expected: header shows today's date in full; four tabs; clicking Orders/Stock/Staff shows "Loading…" then the 404 text (they are not built yet — this proves the router and the lazy load are wired); `← Yesterday` changes the heading date; on today the Tomorrow link is visibly disabled; Ctrl+P preview shows no tab bar.

- [ ] **Step 7: Commit**

```bash
git add daily_report.php
git commit -F <message-file>   # "feat: add the daily report shell"
```

---

### Task 4: The three verdict boxes

The reason the page exists.

**Files:**
- Modify: `daily_report.php`

**Interfaces:**
- Consumes: `paid_orders_where()`, `ingredient_cost_map()`, `order_cogs()`, `weekday_baseline()`.
- Produces: `$gotToday`, `$keptToday`, `$lowItems` for Task 5's neutral row.

- [ ] **Step 1: Gather the day's figures**

```php
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
```

- [ ] **Step 2: Work out each verdict**

```php
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

$vGot  = dr_verdict($gotToday,  $baseGot['value'], $baseGot['label']);
// The same baseline day-set judges what we kept, so both boxes answer the
// same question about the same days.
$vKept = dr_verdict($keptToday, $baseGot['value'] !== null ? $baseGot['value'] * ($gotToday > 0 ? $keptToday / $gotToday : 0) : null, $baseGot['label']);
```

- [ ] **Step 3: Work out the stock verdict**

```php
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

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(ABS(h.amount) * i.cost_per_unit),0)
    FROM ingredient_history h
    JOIN ingredients i ON i.ingredient_id = h.ingredient_id
    WHERE h.change_type = 'order_deduct' AND DATE(h.created_at) = ?
");
$stmt->bind_param("s", $date);
$stmt->execute();
$usedValue = (float)$stmt->get_result()->fetch_row()[0];
```

- [ ] **Step 4: Render the three boxes**

Khmer strings are copied exactly from the spec. Each box: English question, Khmer question, big number, plain sub-label, then the verdict line.

```php
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
```

- [ ] **Step 5: Style them — and only them — with colour**

```css
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
```

- [ ] **Step 6: Verify against real data**

Open `daily_report.php` (today is 2026-07-26, a Sunday).
Expected: box 1 reads about `$18.64` and, against a normal-Sunday baseline of `$49.83`, shows red `$31.19 LESS than a normal Sunday`. Box 3 is red naming three ingredients "and 10 more" (13 are at or below their level), with stock we have `$526.48`.
Then open `?date=2026-07-21` and confirm the figures change and the weekday label reads "a normal Tuesday".

- [ ] **Step 7: Commit**

```bash
git add daily_report.php
git commit -F <message-file>   # "feat: answer the three questions a manager arrives with"
```

---

### Task 5: The neutral row — how the money came in

Facts, not verdicts. No colour here.

**Files:**
- Modify: `daily_report.php`

**Interfaces:**
- Consumes: `$date`, `$gotToday`, `$ids`, `$cogs` from Task 4.

- [ ] **Step 1: Query the split**

```php
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

// Tabs still open: made, maybe served, definitely not paid for.
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE business_date = ? AND payment_method='paylater' AND is_open = 1 AND status NOT IN ('Cancelled','Void')");
$stmt->bind_param("s", $date);
$stmt->execute();
[$notPaidYet, $notPaidCount] = $stmt->get_result()->fetch_row();
$notPaidYet = (float)$notPaidYet;
```

- [ ] **Step 2: Render the money cards, counts, bar and best seller**

```php
<div class="dr-facts">
  <div class="dr-card"><div class="dr-k">cash</div><div class="dr-k-km">សាច់ប្រាក់</div><div class="dr-v">$<?= number_format($gotCash, 2) ?></div><div class="dr-note">in the register</div></div>
  <div class="dr-card"><div class="dr-k">bakong</div><div class="dr-k-km">បាគង</div><div class="dr-v">$<?= number_format($gotBakong, 2) ?></div><div class="dr-note">by phone</div></div>
  <div class="dr-card"><div class="dr-k">pay later — paid</div><div class="dr-k-km">បង់ក្រោយ — បង់រួច</div><div class="dr-v">$<?= number_format($gotLater, 2) ?></div><div class="dr-note">settled today</div></div>
  <div class="dr-card"><div class="dr-k">not paid yet</div><div class="dr-k-km">មិនទាន់បង់</div><div class="dr-v">$<?= number_format($notPaidYet, 2) ?></div><div class="dr-note"><?= (int)$notPaidCount ?> open tab(s)</div></div>
</div>

<div class="dr-card dr-wide">
  <div class="dr-k">how the money came in</div>
  <div class="dr-bar">
    <?php foreach ([['cash',$gotCash],['bakong',$gotBakong],['later',$gotLater]] as [$cls,$amt]):
        $pct = $gotToday > 0 ? ($amt / $gotToday) * 100 : 0; ?>
        <span class="seg seg-<?= $cls ?>" style="width:<?= round($pct, 2) ?>%"></span>
    <?php endforeach; ?>
  </div>
  <?php if ($notPaidYet > 0): ?>
    <p class="dr-note">$<?= number_format($notPaidYet, 2) ?> not paid yet. We count it only when the customer pays.</p>
  <?php endif; ?>
</div>
```

Cups sold and orders come from `$cogs['items']` and `$paidOrderCount`; best seller is the first entry of `$cogs['by_product']` after `uasort` by `qty` descending. Guard the divide: with no orders, show "no sales yet today" instead of a zero average.

- [ ] **Step 3: Verify**

Expected on 2026-07-26: the four cards sum to `$18.64`; the bar segments total 100% of that; cups sold is a positive number; best seller names a real drink. On a day with an open tab, "not paid yet" is non-zero and the sentence appears; that money is absent from box 1.

- [ ] **Step 4: Commit**

```bash
git add daily_report.php
git commit -F <message-file>   # "feat: show how the day's money came in"
```

---

### Task 6: Tab 2 — Orders

**Files:**
- Modify: `daily_report.php` (the `case 'orders':` branch)

- [ ] **Step 1: Query the day's orders**

```php
case 'orders':
    $stmt = $conn->prepare("
        SELECT daily_order_no, customer_name, total, payment_method, status, is_open,
               order_date, order_type
        FROM orders
        WHERE business_date = ? AND status NOT IN ('Cancelled','Void')
        ORDER BY order_date ASC
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
```

- [ ] **Step 2: Label each row's payment state correctly**

```php
/**
 * For pay-later, Completed means the drinks were made and the customer still
 * owes — is_open is the only trustworthy signal. Getting this wrong has caused
 * three money bugs in this codebase.
 */
function dr_pay_label(array $o): array {
    $m = strtolower((string)$o['payment_method']);
    if ($m === 'paylater') {
        return ((int)$o['is_open'] === 0 && $o['status'] === 'Paid')
            ? ['pay later — paid', 'ok']
            : ['not paid yet', 'open'];
    }
    return [$m === 'bakong' ? 'bakong' : 'cash', 'ok'];
}
```

- [ ] **Step 3: Render the table**

Columns: time (`H:i` from `order_date`), order (`#` + `daily_order_no`, zero-padded to 4), customer (`'Guest'` renders as `—`), total, how they paid. Rows where the label is `open` get `class="is-open"` (amber tint). Filter pills All / Cash / Bakong / Pay later filter client-side on a `data-method` attribute. Footer: order count, cups, day total. Paginate at 25 rows client-side.

Above the table, two small neutral counts:

```php
$stmt = $conn->prepare("SELECT COUNT(*) FROM order_refunds r JOIN orders o ON o.order_id = r.order_id WHERE o.business_date = ?");
// ... and the same shape for order_remakes
```
rendered as "money given back: N · drinks made again: N". Small grey text, never a red card.

- [ ] **Step 4: Verify**

Open the tab on 2026-07-26. Expected: 6+ rows, times ascending, order numbers matching what `find_order.php` shows for the same day, filter pills narrowing correctly, an open pay-later tab showing "not paid yet" and tinted.

- [ ] **Step 5: Commit**

```bash
git add daily_report.php
git commit -F <message-file>   # "feat: list the day's orders and how each was paid"
```

---

### Task 7: Tab 3 — Stock

**Files:**
- Modify: `daily_report.php` (the `case 'stock':` branch, and the badge in the shell)

- [ ] **Step 1: Query levels and today's movement**

```php
case 'stock':
    $stmt = $conn->prepare("
        SELECT i.ingredient_id, i.ingredient_name, i.unit, i.stock_quantity,
               i.minimum_stock, i.cost_per_unit,
               COALESCE(u.used, 0) AS used_today
        FROM ingredients i
        LEFT JOIN (
            SELECT ingredient_id, SUM(ABS(amount)) AS used
            FROM ingredient_history
            WHERE change_type = 'order_deduct' AND DATE(created_at) = ?
            GROUP BY ingredient_id
        ) u ON u.ingredient_id = i.ingredient_id
        ORDER BY (i.stock_quantity - i.minimum_stock) ASC, i.ingredient_name ASC
    ");
```

- [ ] **Step 2: Render with status pills and bars**

Columns: item, how much we have, buy-more level, used today, what it costs us (`cost_per_unit`, 4dp). Status: `buy now` when `stock_quantity <= minimum_stock`, `low` when within 25% above it, else `OK`. Bar width `min(100, stock/max(1,minimum*2)*100)`. Filter pills All / Need buying / OK, plus a search box over the name. Three counts above: items we track, items to buy, items already out (`stock_quantity <= 0`).

- [ ] **Step 3: Fill the tab badge**

The shell's `#stockBadge` needs the count without loading the tab. Compute `$lowItems` in Task 4 (already done) and print it into the badge server-side; hide the badge when zero.

- [ ] **Step 4: Verify**

Expected on 2026-07-26: 13 items marked "buy now", sorted to the top; badge on the tab reads 13; costs match `ingredients.cost_per_unit` (Milk base 0.0013); the search filters live.

- [ ] **Step 5: Commit**

```bash
git add daily_report.php
git commit -F <message-file>   # "feat: show stock levels and what today used"
```

---

### Task 8: Tab 4 — Staff

**Files:**
- Modify: `daily_report.php` (the `case 'staff':` branch)

- [ ] **Step 1: Write the query with the correct join**

`orders.employee_id` is a foreign key to `employees.employee_id`. `attendance` keys on `user_id`. Joining orders directly to attendance by any shared-looking id produces silent nonsense.

```php
case 'staff':
    $stmt = $conn->prepare("
        SELECT e.employee_id, e.full_name, a.clock_in, a.clock_out, a.hours_worked,
               COUNT(o.order_id)                AS orders_served,
               COALESCE(SUM(o.total), 0)        AS money_taken
        FROM attendance a
        JOIN employees e ON e.user_id = a.user_id
        LEFT JOIN orders o
               ON o.employee_id = e.employee_id
              AND o.business_date = ?
              AND " . paid_orders_where('o') . "
        WHERE a.date = ?
        GROUP BY e.employee_id, a.id
        ORDER BY a.clock_in ASC
    ");
    $stmt->bind_param("ss", $date, $date);
```

- [ ] **Step 2: Render the table**

Columns: name, clocked in (`H:i`), clocked out (`H:i` or "still working"), hours, orders served, money taken. Someone who worked but served nothing shows `—`, not a zero-ranked row. No ranking, no trophies.

Hours: reuse `fmt_hours()` from `config.php` — it already renders a sub-hour shift as minutes rather than "0.0h".

- [ ] **Step 3: Verify against employees.php**

Open both `daily_report.php` (Staff tab) and `employees.php` for the same day.
Expected: the same people, the same order counts, the same money. A mismatch means the join is wrong — fix the join, not the display.

- [ ] **Step 4: Commit**

```bash
git add daily_report.php
git commit -F <message-file>   # "feat: show who worked and what they served"
```

---

### Task 9: Live refresh and nav wiring

**Files:**
- Modify: `daily_report.php`, `nav_menu.php`

- [ ] **Step 1: Add the signature endpoint**

Re-running every KPI query for every open browser is what makes `dashboard.php` expensive. Return a cheap signature instead and only re-render when it changes.

```php
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(total),0), COALESCE(MAX(order_date),'') FROM orders WHERE business_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $sig = implode('|', $stmt->get_result()->fetch_row());
    echo json_encode(['sig' => md5($sig)]);
    exit;
}
```

- [ ] **Step 2: Poll from the client, today only**

```js
// Only the day in progress can change. Past days are settled history.
const DR_IS_TODAY = <?= $isToday ? 'true' : 'false' ?>;
let drSig = null;
if (DR_IS_TODAY) {
    setInterval(async () => {
        try {
            const res = await fetch('daily_report.php?poll=1&date=' + encodeURIComponent(DR_DATE));
            const { sig } = await res.json();
            if (drSig !== null && sig !== drSig) { window.location.reload(); }
            drSig = sig;
        } catch (e) { /* a dropped poll is not worth interrupting the manager */ }
    }, 30000);
}
```

- [ ] **Step 3: Point the nav at the new page**

In `nav_menu.php`, change the `report` entry's href from `report.php` to `daily_report.php`. The label stays "Report". `report.php` remains reachable from the new page's "Full analytics" link and from its own URL.

- [ ] **Step 4: Verify the whole page as three roles**

- Admin (`Sokun` / `@Sokun9811`): all four tabs load; nav Report goes to the new page.
- Cashier (`Sok_Dara` / `@Sokdara5678`): if the role lacks `report`, hitting the URL directly redirects to `dashboard.php?denied=1`.
- Confirm the poll: with the page open on today, place an order in another tab; within 30s the report reloads and box 1 grows by the order's total.
- Open `?date=<a past day>` and confirm no polling happens (Network tab shows no repeating `poll=1`).

- [ ] **Step 5: Run the full test script once more**

Run: `php tests/daily_report_test.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add daily_report.php nav_menu.php
git commit -F <message-file>   # "feat: refresh the day in progress and route Report to it"
```

---

## Self-Review

**Spec coverage:** every spec section maps to a task — shell and tabs (3), verdicts and baseline (2, 4), neutral money row (5), tabs 2–4 (6, 7, 8), Khmer (4, 5), shared helpers (1), poll and permission (9), print (3), empty states (3, 4, 5).

**Two spec corrections found while planning, both already folded in above:**

1. The spec's test 1 said tab 1 "must equal report.php's collected total exactly". It will not. `report.php:133` filters on `order_date BETWEEN` the 6am window while this page uses the `business_date` column, and 186 rows disagree. Verification compares against direct SQL instead, and the Global Constraints record which one is authoritative.
2. The spec implied box 2 compares against a stored historical figure for money kept. No such figure exists — costs are computed per order at read time. Task 4 derives the kept-money baseline from the same baseline days at today's keep-rate, which is honest about what is actually known.

**One thing worth knowing before demo day:** real data is thin. 2026-07-26 has 6 paid orders totalling $18.64, against a normal-Sunday baseline of $49.83. The page will work and show a genuine red verdict, but the numbers are nothing like the $237.50 in the mockup. If the demo wants fuller figures, seeding a realistic day is a separate task — not part of this plan.

**Placeholder scan:** no TBDs; every code step carries real code; the `case` branches in Task 3 are deliberate stubs filled by Tasks 6–8 and are labelled as such.

**Type consistency:** `ingredient_cost_map()` returns entries with `unit_cost` — used under that name in `order_cogs()` and Task 4. `weekday_baseline()` returns `value`/`basis`/`label`/`days` — consumed under those names in Task 4. `order_cogs()` returns `total`/`items`/`by_product` — consumed under those names in Tasks 4 and 5.
