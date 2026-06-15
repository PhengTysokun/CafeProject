<?php
require 'auth.php';
require 'config.php';
if (!can('stock_count')) { header("Location: dashboard.php?denied=1"); exit; }

/* ── Business date (before 6 AM = yesterday) ── */
$now_h  = (int)date('G');
$bdate  = ($now_h < 6) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
$bdate  = trim($_GET['date'] ?? $bdate);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bdate)) $bdate = date('Y-m-d');
$today  = date('Y-m-d');
$is_today = ($bdate === $today || ($now_h < 6 && $bdate === date('Y-m-d', strtotime('-1 day'))));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ══════════════════════════════════════════════
   AJAX: save a single row's actual_qty
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_item') {
    header('Content-Type: application/json');
    $count_id      = (int)($_POST['count_id']      ?? 0);
    $ingredient_id = (int)($_POST['ingredient_id'] ?? 0);
    $actual        = $_POST['actual_qty'];
    if ($count_id <= 0 || $ingredient_id <= 0 || !is_numeric($actual)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
    }
    $actual  = (float)$actual;
    // fetch expected from item row
    $chk = $conn->prepare("
        SELECT sci.item_id, sci.expected_qty
        FROM stock_count_items sci
        JOIN stock_counts sc ON sc.count_id = sci.count_id
        WHERE sci.count_id=? AND sci.ingredient_id=? AND sc.status='draft'
    ");
    $chk->bind_param("ii", $count_id, $ingredient_id);
    $chk->execute();
    $item = $chk->get_result()->fetch_assoc();
    if (!$item) { echo json_encode(['ok'=>false,'msg'=>'Locked or not found']); exit; }
    $variance = $actual - (float)$item['expected_qty'];
    $upd = $conn->prepare("UPDATE stock_count_items SET actual_qty=?, variance=? WHERE item_id=?");
    $upd->bind_param("ddi", $actual, $variance, $item['item_id']);
    $upd->execute();
    echo json_encode(['ok'=>true,'variance'=>round($variance,2)]);
    exit;
}

/* ══════════════════════════════════════════════
   AJAX: submit (lock) the count
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    header('Content-Type: application/json');
    $count_id = (int)($_POST['count_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    if ($count_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
    $by = $_SESSION['username'] ?? '';
    $s = $conn->prepare("UPDATE stock_counts SET status='submitted', submitted_by=?, submitted_at=NOW(), notes=? WHERE count_id=? AND status='draft'");
    $s->bind_param("ssi", $by, $notes, $count_id);
    $s->execute();
    echo json_encode(['ok'=>true]);
    exit;
}

/* ══════════════════════════════════════════════
   LOAD OR CREATE count session for $bdate
══════════════════════════════════════════════ */
$sc = $conn->prepare("SELECT * FROM stock_counts WHERE business_date=? LIMIT 1");
$sc->bind_param("s", $bdate);
$sc->execute();
$session = $sc->get_result()->fetch_assoc();

if (!$session) {
    // Create new draft session
    $by = $_SESSION['username'] ?? '';
    $ins = $conn->prepare("INSERT INTO stock_counts (business_date, status, created_by) VALUES (?,  'draft', ?)");
    $ins->bind_param("ss", $bdate, $by);
    $ins->execute();
    $count_id = $conn->insert_id;

    // Populate items: all ingredients
    $ings = $conn->query("SELECT ingredient_id, ingredient_name, stock_quantity FROM ingredients ORDER BY ingredient_name ASC");
    while ($ing = $ings->fetch_assoc()) {
        $iid = (int)$ing['ingredient_id'];

        // Opening stock: from ingredient_daily_stock if available, else estimate
        $os_row = $conn->prepare("SELECT opening_stock FROM ingredient_daily_stock WHERE ingredient_id=? AND business_date=? LIMIT 1");
        $os_row->bind_param("is", $iid, $bdate);
        $os_row->execute();
        $os = $os_row->get_result()->fetch_assoc();

        // Usage today: order_deduct entries
        $used_q = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS used FROM ingredient_history WHERE ingredient_id=? AND change_type='order_deduct' AND DATE(created_at)=?");
        $used_q->bind_param("is", $iid, $bdate);
        $used_q->execute();
        $used_today = (float)$used_q->get_result()->fetch_assoc()['used'];

        // Restocks today
        $rst_q = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS added FROM ingredient_history WHERE ingredient_id=? AND change_type IN ('quick_restock','po_received') AND DATE(created_at)=?");
        $rst_q->bind_param("is", $iid, $bdate);
        $rst_q->execute();
        $restocked = (float)$rst_q->get_result()->fetch_assoc()['added'];

        if ($os) {
            $opening = (float)$os['opening_stock'];
        } else {
            // Fallback: current stock + used - restocked = opening
            $opening = (float)$ing['stock_quantity'] + $used_today - $restocked;
        }
        $expected = $opening - $used_today + $restocked;

        $ii = $conn->prepare("INSERT INTO stock_count_items (count_id, ingredient_id, opening_stock, system_used, expected_qty) VALUES (?,?,?,?,?)");
        $ii->bind_param("iiddd", $count_id, $iid, $opening, $used_today, $expected);
        $ii->execute();
    }

    $sc2 = $conn->prepare("SELECT * FROM stock_counts WHERE count_id=? LIMIT 1");
    $sc2->bind_param("i", $count_id);
    $sc2->execute();
    $session = $sc2->get_result()->fetch_assoc();
}

$count_id  = (int)$session['count_id'];
$is_locked = ($session['status'] === 'submitted');

/* ── Load items ── */
$items_q = $conn->prepare("
    SELECT sci.*, i.ingredient_name, i.unit, i.minimum_stock
    FROM stock_count_items sci
    JOIN ingredients i ON i.ingredient_id = sci.ingredient_id
    WHERE sci.count_id = ?
    ORDER BY i.ingredient_name ASC
");
$items_q->bind_param("i", $count_id);
$items_q->execute();
$items = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Summary stats ── */
$total_items    = count($items);
$counted        = array_filter($items, fn($r) => $r['actual_qty'] !== null);
$counted_count  = count($counted);
$missing        = array_filter($items, fn($r) => $r['variance'] !== null && $r['variance'] < -0.01);
$over           = array_filter($items, fn($r) => $r['variance'] !== null && $r['variance'] > 0.01);
$ok             = array_filter($items, fn($r) => $r['variance'] !== null && abs($r['variance']) <= 0.01);

$date_fmt = date('M j, Y', strtotime($bdate));
$day_label = ($bdate === $today) ? 'Today' : (($bdate === date('Y-m-d', strtotime('-1 day'))) ? 'Yesterday' : $date_fmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock Count — <?= h($date_fmt) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

@keyframes fadeInUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
@keyframes scaleIn  { from{opacity:0;transform:scale(.93)}        to{opacity:1;transform:scale(1)}     }
@keyframes fadeIn   { from{opacity:0}                             to{opacity:1}                        }
@keyframes spin     { to{transform:rotate(360deg)} }

body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 70% 40% at 50% 0%,rgba(209,144,75,.09) 0%,transparent 100%),#0a0a0a;
  color:#e8e8e8;min-height:100vh;
  display:flex;flex-direction:column;align-items:center;
  padding:36px 16px 80px;
}
body.fading{opacity:0;pointer-events:none;transition:opacity .4s ease;}
a{text-decoration:none;}

/* Header */
.page-header{width:100%;max-width:860px;margin-bottom:28px;text-align:center;animation:fadeInUp .5s ease both;}
.page-header .date{font-size:13px;color:#666;margin-bottom:5px;letter-spacing:.6px;}
.page-header h1{font-size:24px;font-weight:700;color:#f0f0f0;margin-bottom:6px;}
.page-header .sub{font-size:13px;color:#888;}
.page-header .sub strong{color:#d1904b;}

/* Date nav */
.date-nav{display:flex;align-items:center;gap:10px;justify-content:center;margin-bottom:24px;animation:fadeIn .5s ease both;}
.date-nav a,.date-nav button{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:9px;font-size:12px;font-weight:500;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  color:#ccc;cursor:pointer;font-family:inherit;transition:background .2s,border-color .2s;
}
.date-nav a:hover,.date-nav button:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.18);}
.date-nav .current-date{font-size:13px;font-weight:600;color:#f0f0f0;padding:7px 16px;
  background:rgba(209,144,75,.12);border:1px solid rgba(209,144,75,.3);border-radius:9px;}

/* Stats */
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;width:100%;max-width:860px;margin-bottom:20px;}
@media(min-width:500px){.stat-grid{grid-template-columns:repeat(4,1fr);}}
.stat-card{
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:16px;padding:18px 14px;text-align:center;
  animation:scaleIn .45s ease both;
}
.stat-card:nth-child(1){animation-delay:.08s}
.stat-card:nth-child(2){animation-delay:.16s}
.stat-card:nth-child(3){animation-delay:.24s}
.stat-card:nth-child(4){animation-delay:.32s}
.stat-card .val{font-size:24px;font-weight:700;margin-bottom:3px;}
.stat-card .lbl{font-size:11px;color:#666;font-weight:400;letter-spacing:.3px;}
.stat-card.c-total .val{color:#60a5fa;}
.stat-card.c-ok    .val{color:#22c55e;}
.stat-card.c-miss  .val{color:#f87171;}
.stat-card.c-over  .val{color:#d1904b;}

/* Section */
.section{
  width:100%;max-width:860px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:16px;padding:20px;margin-bottom:16px;
  animation:fadeInUp .5s ease both;
}
.section.s1{animation-delay:.38s}
.section.s2{animation-delay:.46s}
.section-title{font-size:11px;font-weight:600;color:#555;letter-spacing:.9px;text-transform:uppercase;margin-bottom:16px;display:flex;align-items:center;gap:7px;}

/* Locked banner */
.locked-banner{
  width:100%;max-width:860px;
  background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);
  border-radius:12px;padding:12px 18px;margin-bottom:16px;
  display:flex;align-items:center;gap:10px;
  font-size:13px;color:#86efac;
  animation:fadeIn .4s ease both;
}
.locked-banner i{color:#22c55e;font-size:15px;}

/* Table */
.count-table{width:100%;border-collapse:collapse;}
.count-table th{
  font-size:10px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;
  color:#555;padding:0 12px 12px;text-align:right;
}
.count-table th:first-child{text-align:left;}
.count-table th.col-actual{color:#d1904b;}
.count-table tr.item-row{border-top:1px solid rgba(255,255,255,.05);transition:background .15s;}
.count-table tr.item-row:hover{background:rgba(255,255,255,.025);}
.count-table td{padding:11px 12px;font-size:13px;text-align:right;vertical-align:middle;}
.count-table td:first-child{text-align:left;}
.ing-name{font-weight:500;color:#e0e0e0;}
.ing-unit{font-size:11px;color:#555;margin-top:2px;}
.num-cell{font-variant-numeric:tabular-nums;color:#aaa;}
.num-cell.expected{color:#ccc;font-weight:500;}

/* Actual input */
.actual-wrap{display:flex;justify-content:flex-end;align-items:center;gap:6px;}
.actual-input{
  width:90px;padding:6px 10px;text-align:right;
  background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);
  border-radius:8px;color:#f0f0f0;font-size:13px;font-family:inherit;font-weight:500;
  transition:border-color .2s,background .2s;
}
.actual-input:focus{outline:none;border-color:rgba(209,144,75,.6);background:rgba(209,144,75,.08);}
.actual-input:disabled{opacity:.5;cursor:not-allowed;}
.save-indicator{width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.save-indicator .spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#d1904b;border-radius:50%;animation:spin .7s linear infinite;}
.save-indicator .ok-icon{color:#22c55e;font-size:13px;opacity:0;transition:opacity .3s;}
.save-indicator .ok-icon.show{opacity:1;}

/* Variance badge */
.var-badge{
  display:inline-flex;align-items:center;gap:4px;
  padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;
}
.var-badge.ok   {background:rgba(34,197,94,.1); color:#86efac;}
.var-badge.miss {background:rgba(248,113,113,.1);color:#fca5a5;}
.var-badge.over {background:rgba(209,144,75,.1); color:#fbbf24;}
.var-badge.empty{background:rgba(255,255,255,.06);color:#555;}

/* Status badge */
.status-pill{
  display:inline-flex;align-items:center;gap:5px;
  padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;
}
.status-pill.ok    {background:rgba(34,197,94,.1); color:#86efac;}
.status-pill.review{background:rgba(248,113,113,.1);color:#fca5a5;}
.status-pill.low   {background:rgba(209,144,75,.1); color:#fbbf24;}
.status-pill.empty {background:rgba(255,255,255,.05);color:#555;}

/* Progress */
.progress-wrap{margin-bottom:20px;}
.progress-label{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#888;margin-bottom:7px;}
.progress-label strong{color:#d1904b;}
.progress-bar-bg{background:rgba(255,255,255,.06);border-radius:6px;height:6px;overflow:hidden;}
.progress-bar{height:100%;background:linear-gradient(90deg,#d1904b,#f5a623);border-radius:6px;transition:width .6s ease;}

/* Notes */
.notes-row{display:flex;gap:10px;align-items:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.07);}
.notes-input{
  flex:1;padding:9px 12px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  border-radius:10px;color:#e8e8e8;font-size:13px;font-family:inherit;resize:none;
  transition:border-color .2s;
}
.notes-input:focus{outline:none;border-color:rgba(209,144,75,.5);}
.notes-input:disabled{opacity:.5;cursor:not-allowed;}

/* Submit */
.submit-btn{
  padding:10px 22px;border-radius:10px;font-size:13px;font-weight:600;
  background:linear-gradient(135deg,#d1904b,#f5a623);color:#000;
  border:none;cursor:pointer;font-family:inherit;
  transition:opacity .2s,transform .1s;
  display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
}
.submit-btn:hover:not(:disabled){opacity:.88;transform:translateY(-1px);}
.submit-btn:disabled{opacity:.4;cursor:not-allowed;transform:none;}

/* Back link */
.back-link{
  display:inline-flex;align-items:center;gap:7px;
  color:#888;font-size:13px;margin-bottom:20px;
  animation:fadeIn .4s ease both;
  transition:color .2s;
}
.back-link:hover{color:#d1904b;}

/* Mobile */
@media(max-width:600px){
  .count-table th.col-open,
  .count-table th.col-used,
  .count-table td.col-open,
  .count-table td.col-used{display:none;}
  .actual-input{width:76px;}
}
</style>
</head>
<body>

<a href="dashboard.php" class="back-link" onclick="document.body.classList.add('fading')">
    <i class="fa-solid fa-arrow-left"></i> Dashboard
</a>

<!-- Header -->
<div class="page-header">
    <div class="date"><?= h($day_label) ?> · <?= h($date_fmt) ?></div>
    <h1><i class="fa-solid fa-clipboard-list" style="color:#d1904b;margin-right:8px"></i>Stock Count</h1>
    <div class="sub">
        Logged in as <strong><?= h($_SESSION['username'] ?? '') ?></strong>
        <?php if ($is_locked): ?>
        · Submitted by <strong><?= h($session['submitted_by'] ?? '') ?></strong>
        at <strong><?= h(date('g:i A', strtotime($session['submitted_at']))) ?></strong>
        <?php endif; ?>
    </div>
</div>

<!-- Date navigation -->
<div class="date-nav">
    <?php
    $prev = date('Y-m-d', strtotime($bdate . ' -1 day'));
    $next = date('Y-m-d', strtotime($bdate . ' +1 day'));
    ?>
    <a href="?date=<?= $prev ?>"><i class="fa-solid fa-chevron-left"></i> <?= date('M j', strtotime($prev)) ?></a>
    <span class="current-date"><?= h($day_label === 'Today' || $day_label === 'Yesterday' ? $day_label : $date_fmt) ?></span>
    <?php if ($bdate < $today): ?>
    <a href="?date=<?= $next ?>"><?= date('M j', strtotime($next)) ?> <i class="fa-solid fa-chevron-right"></i></a>
    <?php else: ?>
    <span style="padding:7px 14px;opacity:.25;font-size:12px">—</span>
    <?php endif; ?>
</div>

<?php if ($is_locked): ?>
<div class="locked-banner">
    <i class="fa-solid fa-circle-check"></i>
    Count submitted and locked. View-only mode.
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stat-grid">
    <div class="stat-card c-total">
        <div class="val"><?= $total_items ?></div>
        <div class="lbl">Total Items</div>
    </div>
    <div class="stat-card c-ok">
        <div class="val"><?= count($ok) ?></div>
        <div class="lbl">Matched</div>
    </div>
    <div class="stat-card c-miss">
        <div class="val"><?= count($missing) ?></div>
        <div class="lbl">Shortage</div>
    </div>
    <div class="stat-card c-over">
        <div class="val"><?= count($over) ?></div>
        <div class="lbl">Overage</div>
    </div>
</div>

<!-- Main count table -->
<div class="section s1">
    <div class="section-title">
        <i class="fa-solid fa-boxes-stacked"></i>
        INVENTORY COUNT — <?= h(strtoupper($day_label !== 'Today' && $day_label !== 'Yesterday' ? $date_fmt : $day_label)) ?>
    </div>

    <!-- Progress bar -->
    <?php if (!$is_locked): ?>
    <div class="progress-wrap">
        <div class="progress-label">
            <span>Progress</span>
            <strong><?= $counted_count ?> / <?= $total_items ?> counted</strong>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar" id="progressBar" style="width:<?= $total_items > 0 ? round($counted_count/$total_items*100) : 0 ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <table class="count-table">
        <thead>
            <tr>
                <th style="width:30%">Item</th>
                <th class="col-open">Opening</th>
                <th class="col-used">Used Today</th>
                <th>Expected</th>
                <th class="col-actual">Actual Count</th>
                <th>Variance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $row):
            $ing_id    = (int)$row['ingredient_id'];
            $opening   = (float)$row['opening_stock'];
            $used      = (float)$row['system_used'];
            $expected  = (float)$row['expected_qty'];
            $actual    = $row['actual_qty'];
            $variance  = $row['variance'];
            $unit      = h($row['unit']);

            // Status logic
            if ($actual === null) {
                $status_html = '<span class="status-pill empty">—</span>';
            } elseif (abs((float)$variance) <= 0.01) {
                $status_html = '<span class="status-pill ok"><i class="fa-solid fa-check"></i> OK</span>';
            } elseif ((float)$variance < -((float)$row['minimum_stock'] * 0.1 + 1)) {
                $status_html = '<span class="status-pill review"><i class="fa-solid fa-triangle-exclamation"></i> REVIEW</span>';
            } elseif ((float)$variance < 0) {
                $status_html = '<span class="status-pill low"><i class="fa-solid fa-minus"></i> Low</span>';
            } else {
                $status_html = '<span class="status-pill ok"><i class="fa-solid fa-plus"></i> Over</span>';
            }

            if ($variance === null) {
                $var_html = '<span class="var-badge empty">—</span>';
            } elseif (abs((float)$variance) <= 0.01) {
                $var_html = '<span class="var-badge ok">0</span>';
            } elseif ((float)$variance < 0) {
                $var_html = '<span class="var-badge miss">'.number_format((float)$variance, 1).' '.$unit.'</span>';
            } else {
                $var_html = '<span class="var-badge over">+'.number_format((float)$variance, 1).' '.$unit.'</span>';
            }
        ?>
        <tr class="item-row" data-id="<?= $ing_id ?>">
            <td>
                <div class="ing-name"><?= h($row['ingredient_name']) ?></div>
                <div class="ing-unit"><?= $unit ?></div>
            </td>
            <td class="num-cell col-open"><?= number_format($opening, 1) ?></td>
            <td class="num-cell col-used" style="color:<?= $used > 0 ? '#f87171' : '#555' ?>">
                <?= $used > 0 ? '−'.number_format($used, 1) : '—' ?>
            </td>
            <td class="num-cell expected"><?= number_format($expected, 1) ?></td>
            <td>
                <div class="actual-wrap">
                    <input
                        type="number"
                        class="actual-input"
                        data-id="<?= $ing_id ?>"
                        data-count="<?= $count_id ?>"
                        value="<?= $actual !== null ? number_format((float)$actual, 1, '.', '') : '' ?>"
                        placeholder="—"
                        step="0.1"
                        min="0"
                        <?= $is_locked ? 'disabled' : '' ?>
                    >
                    <div class="save-indicator" id="si-<?= $ing_id ?>">
                        <i class="fa-solid fa-check ok-icon" id="ok-<?= $ing_id ?>"></i>
                    </div>
                </div>
            </td>
            <td id="var-<?= $ing_id ?>"><?= $var_html ?></td>
            <td id="st-<?= $ing_id ?>"><?= $status_html ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!$is_locked): ?>
    <div class="notes-row">
        <textarea
            id="countNotes"
            class="notes-input"
            rows="2"
            placeholder="Notes (optional) — e.g. fridge temp, staff on shift…"
        ><?= h($session['notes'] ?? '') ?></textarea>
        <button class="submit-btn" id="submitBtn" onclick="submitCount()">
            <i class="fa-solid fa-circle-check"></i>
            Submit Count
        </button>
    </div>
    <?php elseif ($session['notes']): ?>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.07);font-size:13px;color:#888;">
        <span style="font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:#555;margin-right:8px;">Notes</span>
        <?= h($session['notes']) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Variance summary (only after any counted) -->
<?php
$counted_arr = array_values(array_filter($items, fn($r) => $r['actual_qty'] !== null && abs((float)$r['variance']) > 0.01));
if ($counted_arr):
?>
<div class="section s2">
    <div class="section-title">
        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
        VARIANCES
    </div>
    <table class="count-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Expected</th>
                <th>Actual</th>
                <th>Variance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($counted_arr as $row):
            $variance = (float)$row['variance'];
            $unit = h($row['unit']);
            $var_str = ($variance >= 0 ? '+' : '').number_format($variance, 1).' '.$unit;
            $var_class = abs($variance) <= 0.01 ? 'ok' : ($variance < 0 ? 'miss' : 'over');
        ?>
        <tr class="item-row">
            <td><div class="ing-name"><?= h($row['ingredient_name']) ?></div></td>
            <td class="num-cell"><?= number_format((float)$row['expected_qty'], 1) ?> <?= $unit ?></td>
            <td class="num-cell"><?= number_format((float)$row['actual_qty'], 1) ?> <?= $unit ?></td>
            <td><span class="var-badge <?= $var_class ?>"><?= $var_str ?></span></td>
            <td>
                <?php if ($variance < -((float)$row['minimum_stock'] * 0.1 + 1)): ?>
                <span class="status-pill review"><i class="fa-solid fa-triangle-exclamation"></i> REVIEW</span>
                <?php elseif ($variance < 0): ?>
                <span class="status-pill low"><i class="fa-solid fa-minus"></i> Shortage</span>
                <?php else: ?>
                <span class="status-pill ok"><i class="fa-solid fa-plus"></i> Overage</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
const COUNT_ID = <?= $count_id ?>;
const TOTAL    = <?= $total_items ?>;
let   counted  = <?= $counted_count ?>;

/* ── Debounced auto-save ── */
let timers = {};

document.querySelectorAll('.actual-input').forEach(inp => {
    inp.addEventListener('input', function() {
        const id = this.dataset.id;
        clearTimeout(timers[id]);
        showSpinner(id);
        timers[id] = setTimeout(() => saveItem(id, this.value), 600);
    });
    inp.addEventListener('change', function() {
        const id = this.dataset.id;
        clearTimeout(timers[id]);
        showSpinner(id);
        saveItem(id, this.value);
    });
});

function showSpinner(id) {
    const ok = document.getElementById('ok-' + id);
    if (ok) ok.classList.remove('show');
    const si = document.getElementById('si-' + id);
    if (si && !si.querySelector('.spinner')) {
        si.innerHTML = '<div class="spinner"></div>';
    }
}

function saveItem(ingredient_id, value) {
    if (value === '' || isNaN(parseFloat(value))) {
        clearIndicator(ingredient_id);
        return;
    }
    const fd = new FormData();
    fd.append('action', 'save_item');
    fd.append('count_id', COUNT_ID);
    fd.append('ingredient_id', ingredient_id);
    fd.append('actual_qty', parseFloat(value).toFixed(1));

    fetch('stock_count.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                showOK(ingredient_id);
                updateVariance(ingredient_id, d.variance, parseFloat(value));
                updateProgress();
            }
        })
        .catch(() => clearIndicator(ingredient_id));
}

function showOK(id) {
    const si = document.getElementById('si-' + id);
    if (si) {
        si.innerHTML = '<i class="fa-solid fa-check ok-icon show" id="ok-' + id + '"></i>';
        setTimeout(() => {
            const ok = document.getElementById('ok-' + id);
            if (ok) ok.classList.remove('show');
        }, 2000);
    }
}

function clearIndicator(id) {
    const si = document.getElementById('si-' + id);
    if (si) si.innerHTML = '<i class="fa-solid fa-check ok-icon" id="ok-' + id + '"></i>';
}

function updateVariance(id, variance, actual) {
    const varCell = document.getElementById('var-' + id);
    const stCell  = document.getElementById('st-' + id);
    if (!varCell || !stCell) return;

    const abs = Math.abs(variance);
    let varHtml, stHtml;

    const unitEl = document.querySelector('[data-id="' + id + '"]')
                    ?.closest('tr')?.querySelector('.ing-unit');
    const unit   = unitEl ? unitEl.textContent.trim() : '';

    if (abs <= 0.01) {
        varHtml = '<span class="var-badge ok">0</span>';
        stHtml  = '<span class="status-pill ok"><i class="fa-solid fa-check"></i> OK</span>';
    } else if (variance < 0) {
        varHtml = `<span class="var-badge miss">${variance.toFixed(1)} ${unit}</span>`;
        stHtml  = '<span class="status-pill low"><i class="fa-solid fa-minus"></i> Shortage</span>';
    } else {
        varHtml = `<span class="var-badge over">+${variance.toFixed(1)} ${unit}</span>`;
        stHtml  = '<span class="status-pill ok"><i class="fa-solid fa-plus"></i> Over</span>';
    }

    varCell.innerHTML = varHtml;
    stCell.innerHTML  = stHtml;

    // Update stat cards
    updateStatCards();
}

function updateStatCards() {
    const rows = document.querySelectorAll('.item-row[data-id]');
    let ok = 0, miss = 0, over = 0, cnt = 0;
    rows.forEach(row => {
        const varBadge = row.querySelector('[id^="var-"]');
        if (!varBadge) return;
        const badge = varBadge.querySelector('.var-badge');
        if (!badge || badge.classList.contains('empty')) return;
        cnt++;
        if (badge.classList.contains('ok'))   ok++;
        if (badge.classList.contains('miss')) miss++;
        if (badge.classList.contains('over')) over++;
    });
    const cards = document.querySelectorAll('.stat-card');
    if (cards[1]) cards[1].querySelector('.val').textContent = ok;
    if (cards[2]) cards[2].querySelector('.val').textContent = miss;
    if (cards[3]) cards[3].querySelector('.val').textContent = over;
}

function updateProgress() {
    const inputs = document.querySelectorAll('.actual-input');
    let filled = 0;
    inputs.forEach(i => { if (i.value !== '') filled++; });
    const bar  = document.getElementById('progressBar');
    const lbl  = document.querySelector('.progress-label strong');
    if (bar) bar.style.width = Math.round(filled / TOTAL * 100) + '%';
    if (lbl) lbl.textContent = filled + ' / ' + TOTAL + ' counted';
}

/* ── Submit ── */
function submitCount() {
    const btn = document.getElementById('submitBtn');
    const notes = document.getElementById('countNotes')?.value || '';

    // Check at least half are counted
    const inputs = document.querySelectorAll('.actual-input');
    let filled = 0;
    inputs.forEach(i => { if (i.value !== '') filled++; });
    if (filled === 0) {
        alert('Please count at least one item before submitting.');
        return;
    }

    if (!confirm(`Submit this stock count for ${filled}/${TOTAL} items? This cannot be undone.`)) return;

    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border:2px solid rgba(0,0,0,.2);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></div> Submitting…';

    const fd = new FormData();
    fd.append('action', 'submit');
    fd.append('count_id', COUNT_ID);
    fd.append('notes', notes);

    fetch('stock_count.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                document.body.classList.add('fading');
                setTimeout(() => location.reload(), 400);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Submit Count';
                alert('Error: ' + (d.msg || 'Unknown error'));
            }
        });
}
</script>
</body>
</html>
