<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: dashboard.php?denied=1"); exit; }

/* ── DATE RANGE ── */
$today     = date('Y-m-d');
$default_f = date('Y-m-d', strtotime('-30 days'));
$date_from = trim($_GET['from'] ?? $default_f);
$date_to   = trim($_GET['to']   ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_f;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$days_in_range = (int)(new DateTime($date_from))->diff(new DateTime($date_to))->days + 1;

/* ── MAIN QUERY ── */
$stmt = $conn->prepare("
    SELECT
        i.ingredient_id,
        i.ingredient_name,
        i.unit,
        i.stock_quantity,
        i.minimum_stock,
        COALESCE(SUM(CASE
            WHEN h.change_type = 'order_deduct' THEN h.amount
            WHEN h.change_type = 'manual_adjust' AND h.amount < 0 THEN ABS(h.amount)
            ELSE 0
        END), 0) AS total_consumed,
        COALESCE(SUM(CASE
            WHEN h.change_type IN ('quick_restock','po_received','order_restore') THEN h.amount
            WHEN h.change_type = 'manual_adjust' AND h.amount > 0 THEN h.amount
            ELSE 0
        END), 0) AS total_added,
        COUNT(h.id) AS event_count
    FROM ingredients i
    LEFT JOIN ingredient_history h
        ON h.ingredient_id = i.ingredient_id
        AND DATE(h.created_at) BETWEEN ? AND ?
    GROUP BY i.ingredient_id, i.ingredient_name, i.unit, i.stock_quantity, i.minimum_stock
    ORDER BY total_consumed DESC, i.ingredient_name ASC
");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── COMPUTE PER-ROW DERIVED VALUES ── */
$grand_consumed = 0;
$grand_added    = 0;
$grand_events   = 0;
$active_ings    = 0;

foreach ($rows as &$r) {
    $r['consumed']    = (float)$r['total_consumed'];
    $r['added']       = (float)$r['total_added'];
    $r['net']         = $r['added'] - $r['consumed'];
    $r['daily_avg']   = $days_in_range > 0 ? $r['consumed'] / $days_in_range : 0;
    $stock            = (float)$r['stock_quantity'];
    $r['days_left']   = ($r['daily_avg'] > 0 && $stock > 0) ? (int)round($stock / $r['daily_avg']) : null;

    $grand_consumed += $r['consumed'];
    $grand_added    += $r['added'];
    $grand_events   += (int)$r['event_count'];
    if ($r['consumed'] > 0 || $r['added'] > 0) $active_ings++;
}
unset($r);

$grand_net = $grand_added - $grand_consumed;

function fmtR($n) { return rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.'); }
function he($s)   { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingredient Report | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#f2ede8; --bg-card:#fff; --bg-card-hover:#fdf8f2; --bg-input:#ede8e0;
    --border:#e0d4c4; --border-hover:#c9b89f;
    --text:#1a1410; --text-muted:#7a6a58; --text-light:#1a1410;
    --shadow-sm:0 2px 8px rgba(90,60,20,.07); --shadow-md:0 4px 20px rgba(90,60,20,.11);
}
[data-theme="light"] .topbar { background:rgba(255,252,248,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:48px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* TOPBAR */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.brand-icon  { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text  { display:flex; flex-direction:column; line-height:1.2; }
.brand-title { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub   { font-size:10px; color:var(--text-muted); }
.topbar-sep  { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-right { display:flex; align-items:center; gap:6px; margin-left:auto; flex-wrap:wrap; }

.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.icon-only { padding:7px 10px; }

/* DATE RANGE FORM */
.range-bar {
    margin:14px 24px 0; padding:14px 18px;
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;
}
.range-group { display:flex; flex-direction:column; gap:5px; }
.range-label { font-size:10px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
.range-input {
    padding:7px 12px; border-radius:8px; border:1px solid var(--border);
    background:var(--bg-input); color:var(--text); font-size:13px;
    font-family:'Poppins',sans-serif; outline:none; transition:var(--transition);
}
.range-input:focus { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.range-actions { display:flex; gap:8px; margin-left:auto; align-items:flex-end; }
.btn-apply {
    padding:7px 18px; border-radius:8px; border:1px solid var(--accent);
    background:var(--accent); color:#000; font-size:13px; font-weight:700;
    cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition);
}
.btn-apply:hover { background:var(--accent-light); }
.btn-quick {
    padding:6px 12px; border-radius:8px; border:1px solid var(--border);
    background:transparent; color:var(--text-muted); font-size:12px; font-weight:600;
    cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition);
}
.btn-quick:hover { border-color:var(--accent); color:var(--accent); }
.period-label { font-size:12px; color:var(--text-muted); padding:7px 0; }

/* STATS */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; padding:14px 24px 0; }
.stat-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:16px 18px; display:flex; align-items:center; gap:12px; position:relative; overflow:hidden;
}
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:var(--radius) var(--radius) 0 0; }
.stat-card.s-consumed::before { background:linear-gradient(90deg,#c0392b,var(--danger)); }
.stat-card.s-added::before    { background:linear-gradient(90deg,#2ecc71,var(--ok)); }
.stat-card.s-net::before      { background:linear-gradient(90deg,#2471a3,var(--blue)); }
.stat-card.s-events::before   { background:linear-gradient(90deg,var(--accent-dark),var(--accent-light)); }
.stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.stat-icon.consumed { background:rgba(255,95,95,.12);  color:var(--danger); }
.stat-icon.added    { background:rgba(85,224,135,.12);  color:var(--ok); }
.stat-icon.net      { background:rgba(52,152,219,.12);  color:var(--blue); }
.stat-icon.events   { background:rgba(209,144,75,.12);  color:var(--accent); }
.stat-label { font-size:10px; color:var(--text-muted); font-weight:500; text-transform:uppercase; letter-spacing:.5px; }
.stat-num   { font-size:18px; font-weight:800; line-height:1.2; }
.stat-hint  { font-size:10px; color:var(--text-muted); margin-top:1px; }
.s-consumed .stat-num { color:var(--danger); }
.s-added    .stat-num { color:var(--ok); }
.s-net      .stat-num { color:var(--blue); }
.s-events   .stat-num { color:var(--accent); }

/* TABLE */
.table-card { margin:14px 24px 0; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
.table-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--border); gap:10px; flex-wrap:wrap; }
.table-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; }
.table-wrap { overflow:auto; max-height:calc(100vh - 360px); }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead { position:sticky; top:0; z-index:10; }
th { padding:10px 14px; text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); background:var(--bg-card); border-bottom:1px solid var(--border); white-space:nowrap; cursor:pointer; user-select:none; }
th:hover { color:var(--accent); }
th.sorted { color:var(--accent); }
th .si { margin-left:4px; opacity:.3; font-size:9px; }
th.sorted .si { opacity:1; }
td { padding:11px 14px; border-bottom:1px solid var(--border); color:var(--text); white-space:nowrap; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td { background:rgba(255,255,255,.025); }
tfoot td { padding:10px 14px; font-size:11px; font-weight:700; border-top:2px solid var(--border); color:var(--text-muted); background:rgba(255,255,255,.015); }

/* INGREDIENT NAME */
.ing-cell { display:flex; align-items:center; gap:9px; }
.ing-dot  { width:26px; height:26px; border-radius:7px; background:rgba(209,144,75,.1); display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--accent); flex-shrink:0; }

/* DAYS REMAINING */
.days-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:50px; font-size:11px; font-weight:700; }
.days-ok       { background:rgba(85,224,135,.1);  color:var(--ok);     border:1px solid rgba(85,224,135,.2); }
.days-warn     { background:rgba(241,196,15,.1);  color:var(--low);    border:1px solid rgba(241,196,15,.2); }
.days-critical { background:rgba(255,95,95,.1);   color:var(--danger); border:1px solid rgba(255,95,95,.2); }
.days-none     { color:var(--text-muted); font-size:11px; }

/* USAGE BAR */
.usage-wrap { display:flex; align-items:center; gap:8px; min-width:120px; }
.usage-bar  { flex:1; height:5px; background:rgba(255,255,255,.07); border-radius:4px; overflow:hidden; }
.usage-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#c0392b,var(--danger)); }

/* SEARCH */
.search-wrap { display:flex; align-items:center; gap:8px; padding:7px 12px; border-radius:50px; border:1px solid var(--border); background:var(--bg-input); transition:var(--transition); max-width:240px; }
.search-wrap:focus-within { border-color:var(--accent); }
.search-wrap i { color:var(--text-muted); font-size:12px; }
.search-wrap input { border:none; background:transparent; outline:none; color:var(--text); font-size:12px; font-family:'Poppins',sans-serif; width:100%; }
.search-wrap input::placeholder { color:var(--text-muted); }

/* TOAST */
#toast-cnt { position:fixed; bottom:24px; right:20px; z-index:99999; display:flex; flex-direction:column-reverse; gap:8px; pointer-events:none; }
.toast { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--text); box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; min-width:220px; max-width:320px; transform:translateX(120%); transition:transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events:auto; }
.toast.show { transform:translateX(0); }
.toast.success { border-left:3px solid var(--ok); }
.toast.error   { border-left:3px solid var(--danger); }

/* EMPTY */
.empty-state { text-align:center; padding:60px 20px; }
.empty-state .ei { font-size:42px; color:var(--border-hover); margin-bottom:14px; }
.empty-state h3  { font-size:16px; font-weight:600; margin-bottom:6px; }
.empty-state p   { font-size:13px; color:var(--text-muted); }

@media (max-width:900px)  { .stats-row { grid-template-columns:repeat(2,1fr); } }
@media (max-width:640px)  { .stats-row,.range-bar,.table-card { margin-left:14px; margin-right:14px; } .topbar { padding:10px 14px; } }
@media print {
    .topbar,.range-bar { display:none!important; }
    body { background:#fff; color:#000; }
    .table-card { box-shadow:none; border:1px solid #ccc; margin:0; }
    .table-wrap { max-height:none; overflow:visible; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="ingredients.php" class="btn-nav icon-only" title="Back to Ingredients"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="brand-icon"><i class="fa-solid fa-chart-bar"></i></div>
    <div class="brand-text">
        <span class="brand-title">Consumption Report</span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; Inventory</span>
    </div>
    <div class="topbar-right">
        <a href="ingredient_history.php" class="btn-nav"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
        <button class="btn-nav" onclick="exportCSV()" title="Export to CSV"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<!-- DATE RANGE FORM -->
<form method="get" class="range-bar">
    <div class="range-group">
        <label class="range-label">From</label>
        <input type="date" name="from" class="range-input" value="<?= he($date_from) ?>">
    </div>
    <div class="range-group">
        <label class="range-label">To</label>
        <input type="date" name="to" class="range-input" value="<?= he($date_to) ?>">
    </div>
    <div class="range-actions">
        <button type="button" class="btn-quick" onclick="setRange(7)">Last 7d</button>
        <button type="button" class="btn-quick" onclick="setRange(30)">Last 30d</button>
        <button type="button" class="btn-quick" onclick="setRange(90)">Last 90d</button>
        <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
    </div>
    <span class="period-label" style="margin-left:4px">
        <i class="fa-regular fa-calendar-days" style="color:var(--accent)"></i>
        <?= $days_in_range ?> day<?= $days_in_range !== 1 ? 's' : '' ?>
        &mdash; <?= date('d M Y', strtotime($date_from)) ?> to <?= date('d M Y', strtotime($date_to)) ?>
    </span>
</form>

<!-- STATS -->
<div class="stats-row">
    <div class="stat-card s-consumed">
        <div class="stat-icon consumed"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div>
            <div class="stat-label">Total Consumed</div>
            <div class="stat-num"><?= fmtR($grand_consumed) ?></div>
            <div class="stat-hint">units deducted</div>
        </div>
    </div>
    <div class="stat-card s-added">
        <div class="stat-icon added"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div>
            <div class="stat-label">Total Added</div>
            <div class="stat-num"><?= fmtR($grand_added) ?></div>
            <div class="stat-hint">units restocked</div>
        </div>
    </div>
    <div class="stat-card s-net">
        <div class="stat-icon net"><i class="fa-solid fa-scale-balanced"></i></div>
        <div>
            <div class="stat-label">Net Change</div>
            <div class="stat-num" style="color:<?= $grand_net >= 0 ? 'var(--ok)' : 'var(--danger)' ?>">
                <?= ($grand_net >= 0 ? '+' : '') . fmtR($grand_net) ?>
            </div>
            <div class="stat-hint">added minus consumed</div>
        </div>
    </div>
    <div class="stat-card s-events">
        <div class="stat-icon events"><i class="fa-solid fa-list-ul"></i></div>
        <div>
            <div class="stat-label">Total Events</div>
            <div class="stat-num"><?= $grand_events ?></div>
            <div class="stat-hint"><?= $active_ings ?> active ingredients</div>
        </div>
    </div>
</div>

<!-- TABLE -->
<div class="table-card">
    <div class="table-header">
        <div class="table-title"><i class="fa-solid fa-chart-bar" style="color:var(--accent)"></i> Per-Ingredient Summary</div>
        <div style="display:flex;align-items:center;gap:10px">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input id="searchInput" placeholder="Search ingredient…" autocomplete="off" oninput="liveSearch(this.value)">
            </div>
            <span style="font-size:12px;color:var(--text-muted)" id="rowCount"><?= count($rows) ?> ingredients</span>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($rows)): ?>
        <div class="empty-state">
            <div class="ei"><i class="fa-solid fa-chart-bar"></i></div>
            <h3>No ingredients found</h3>
            <p>Add ingredients to start tracking consumption.</p>
        </div>
        <?php else: ?>
        <table id="reportTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0)" data-col="0">Ingredient <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(1)" data-col="1">Unit <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(2)" data-col="2">Consumed <i class="fa-solid fa-sort-down si" style="opacity:1"></i></th>
                    <th onclick="sortTable(3)" data-col="3">Added <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(4)" data-col="4">Net Change <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(5)" data-col="5">Avg / Day <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(6)" data-col="6">Days Left <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(7)" data-col="7">Events <i class="fa-solid fa-sort si"></i></th>
                </tr>
            </thead>
            <tbody id="reportBody">
            <?php
            $max_consumed = max(array_column($rows, 'total_consumed') ?: [0]);
            foreach ($rows as $r):
                $consumed   = $r['consumed'];
                $added      = $r['added'];
                $net        = $r['net'];
                $daily      = $r['daily_avg'];
                $days_left  = $r['days_left'];
                $unit       = he($r['unit'] ?? '');
                $bar_pct    = $max_consumed > 0 ? min(100, round($consumed / $max_consumed * 100)) : 0;

                if ($days_left === null)       { $days_cls = 'days-none';     $days_txt = '—'; }
                elseif ($days_left <= 2)       { $days_cls = 'days-critical'; $days_txt = "~$days_left day" . ($days_left !== 1 ? 's' : ''); }
                elseif ($days_left <= 7)       { $days_cls = 'days-warn';     $days_txt = "~$days_left days"; }
                else                           { $days_cls = 'days-ok';       $days_txt = "~$days_left days"; }
            ?>
            <tr data-name="<?= he(strtolower($r['ingredient_name'])) ?>"
                data-0="<?= he($r['ingredient_name']) ?>"
                data-1="<?= he($r['unit'] ?? '') ?>"
                data-2="<?= $consumed ?>"
                data-3="<?= $added ?>"
                data-4="<?= $net ?>"
                data-5="<?= round($daily, 4) ?>"
                data-6="<?= $days_left ?? -1 ?>"
                data-7="<?= (int)$r['event_count'] ?>">
                <td>
                    <div class="ing-cell">
                        <div class="ing-dot"><i class="fa-solid fa-leaf"></i></div>
                        <a href="ingredient_history.php?ingredient_id=<?= (int)$r['ingredient_id'] ?>&from=<?= he($date_from) ?>&to=<?= he($date_to) ?>"
                           style="color:var(--text);text-decoration:none;font-weight:500">
                            <?= he($r['ingredient_name']) ?>
                        </a>
                    </div>
                </td>
                <td style="color:var(--text-muted)"><?= $unit ?: '—' ?></td>
                <td>
                    <div class="usage-wrap">
                        <span style="color:var(--danger);font-weight:700;min-width:60px"><?= $consumed > 0 ? fmtR($consumed) : '<span style="color:var(--text-muted)">0</span>' ?></span>
                        <?php if ($bar_pct > 0): ?>
                        <div class="usage-bar"><div class="usage-fill" style="width:<?= $bar_pct ?>%"></div></div>
                        <?php endif; ?>
                    </div>
                </td>
                <td style="color:var(--ok);font-weight:<?= $added > 0 ? '700' : '400' ?>">
                    <?= $added > 0 ? fmtR($added) : '<span style="color:var(--text-muted)">0</span>' ?>
                </td>
                <td style="font-weight:700;color:<?= $net >= 0 ? 'var(--ok)' : 'var(--danger)' ?>">
                    <?= ($net >= 0 ? '+' : '') . fmtR($net) ?>
                </td>
                <td style="color:var(--text-muted);font-size:12px">
                    <?= $daily > 0 ? fmtR($daily) . ($unit ? ' '.$unit : '') : '<span style="color:var(--border-hover)">—</span>' ?>
                </td>
                <td>
                    <?php if ($days_left === null): ?>
                    <span class="<?= $days_cls ?>">—</span>
                    <?php else: ?>
                    <span class="days-chip <?= $days_cls ?>">
                        <i class="fa-solid fa-clock" style="font-size:9px"></i> <?= $days_txt ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text-muted);font-size:12px"><?= (int)$r['event_count'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" style="color:var(--text-muted)"><?= count($rows) ?> ingredients total</td>
                    <td style="color:var(--danger)"><?= fmtR($grand_consumed) ?></td>
                    <td style="color:var(--ok)"><?= fmtR($grand_added) ?></td>
                    <td style="color:<?= $grand_net >= 0 ? 'var(--ok)' : 'var(--danger)' ?>;font-size:13px">
                        <?= ($grand_net >= 0 ? '+' : '') . fmtR($grand_net) ?>
                    </td>
                    <td colspan="3" style="color:var(--text-muted)"><?= $grand_events ?> total events</td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
</div>

<div id="toast-cnt"></div>

<script>
/* ── DATE QUICK SELECT ── */
function setRange(days) {
    const to   = new Date();
    const from = new Date(); from.setDate(to.getDate() - days + 1);
    const fmt  = d => d.toISOString().slice(0,10);
    document.querySelector('input[name="from"]').value = fmt(from);
    document.querySelector('input[name="to"]').value   = fmt(to);
    document.querySelector('form').submit();
}

/* ── SORT ── */
let sortDir = { 2: 'desc' };
function sortTable(col) {
    const tbody = document.getElementById('reportBody');
    const rows  = [...tbody.querySelectorAll('tr[data-name]')];
    const asc   = sortDir[col] !== 'asc';
    sortDir = {}; sortDir[col] = asc ? 'asc' : 'desc';

    document.querySelectorAll('th').forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const ic = th.querySelector('.si');
        if (ic) ic.className = i === col
            ? `fa-solid ${asc ? 'fa-sort-up' : 'fa-sort-down'} si`
            : 'fa-solid fa-sort si';
        if (i === col) ic.style.opacity = '1'; else if(ic) ic.style.opacity = '.3';
    });

    rows.sort((a, b) => {
        const av = a.dataset[col] ?? '';
        const bv = b.dataset[col] ?? '';
        const an = parseFloat(av), bn = parseFloat(bv);
        if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(r => tbody.appendChild(r));
}

/* ── LIVE SEARCH ── */
function liveSearch(q) {
    q = q.toLowerCase().trim();
    let shown = 0;
    document.querySelectorAll('#reportBody tr[data-name]').forEach(tr => {
        const match = !q || tr.dataset.name.includes(q);
        tr.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('rowCount').textContent = shown + ' ingredient' + (shown !== 1 ? 's' : '');
}

/* ── EXPORT CSV ── */
function exportCSV() {
    const headers = ['Ingredient','Unit','Consumed','Added','Net Change','Avg/Day','Days Left','Events'];
    const rows = [];
    document.querySelectorAll('#reportBody tr[data-name]').forEach(tr => {
        if (tr.style.display === 'none') return;
        rows.push([
            tr.dataset[0] || '',
            tr.dataset[1] || '',
            tr.dataset[2] || '',
            tr.dataset[3] || '',
            tr.dataset[4] || '',
            tr.dataset[5] || '',
            tr.dataset[6] === '-1' ? '' : tr.dataset[6],
            tr.dataset[7] || '',
        ]);
    });
    const csv  = [headers, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `ingredient_report_<?= $date_from ?>_to_<?= $date_to ?>.csv`;
    a.click(); URL.revokeObjectURL(a.href);
    showToast(`Exported ${rows.length} rows`, 'success');
}

/* ── THEME ── */
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const light = html.getAttribute('data-theme') === 'light';
    if (light) { html.removeAttribute('data-theme'); icon.className = 'fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else        { html.setAttribute('data-theme','light'); icon.className = 'fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}

/* ── TOAST ── */
function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    const col  = type === 'success' ? 'var(--ok)' : 'var(--danger)';
    t.innerHTML = `<i class="fa-solid ${icon}" style="color:${col};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3500);
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light')
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
});
</script>
</body>
</html>
