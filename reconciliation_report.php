<?php
require 'auth.php';
require 'config.php';
if (!can('cash_reconciliation')) {
    header("Location: dashboard.php?denied=1"); exit;
}

// ── FILTERS ──
$filter_user = (int)($_GET['user_id'] ?? 0);
$filter_from = trim($_GET['from'] ?? '');
$filter_to   = trim($_GET['to']   ?? date('Y-m-d'));
if ($filter_from === '') $filter_from = date('Y-m-d', strtotime('-30 days'));

$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$is_ajax  = !empty($_GET['ajax']);

// ── CASHIER LIST ──
$cashiers = [];
$cr = $conn->query("SELECT DISTINCT user_id, username FROM cash_counts ORDER BY username ASC");

// ── STOCK COUNT SESSIONS in date range ──
$sc_stmt = $conn->prepare("
    SELECT
        sc.count_id,
        sc.business_date,
        sc.status,
        sc.submitted_by,
        sc.submitted_at,
        sc.notes,
        COUNT(sci.item_id)                                             AS total_items,
        SUM(sci.actual_qty IS NOT NULL)                                AS counted_items,
        SUM(sci.variance < -0.01)                                      AS shortage_items,
        SUM(sci.variance >  0.01)                                      AS overage_items,
        SUM(CASE WHEN sci.variance < -0.01 THEN sci.variance ELSE 0 END) AS total_shortage,
        SUM(CASE WHEN sci.variance >  0.01 THEN sci.variance ELSE 0 END) AS total_overage
    FROM stock_counts sc
    LEFT JOIN stock_count_items sci ON sci.count_id = sc.count_id
    WHERE sc.business_date BETWEEN ? AND ?
    GROUP BY sc.count_id
    ORDER BY sc.business_date DESC
");
$sc_stmt->bind_param("ss", $filter_from, $filter_to);
$sc_stmt->execute();
$sc_rows = $sc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
while ($row = $cr->fetch_assoc()) $cashiers[] = $row;

// ── WHERE CLAUSE ──
$where  = ['cr.shift_date BETWEEN ? AND ?'];
$params = [$filter_from, $filter_to];
$types  = 'ss';
if ($filter_user > 0) { $where[] = 'cr.user_id = ?'; $params[] = $filter_user; $types .= 'i'; }
$where_sql = implode(' AND ', $where);

// ── SUMMARY STATS (all matching rows) ──
$ss = $conn->prepare("SELECT
    COUNT(*)                                              AS total,
    SUM(CASE WHEN ABS(difference) < 0.01 THEN 1 ELSE 0 END) AS matches,
    SUM(CASE WHEN difference > 0.005     THEN 1 ELSE 0 END) AS overs,
    SUM(CASE WHEN difference < -0.005    THEN 1 ELSE 0 END) AS shorts,
    COALESCE(SUM(difference), 0)                         AS total_diff
    FROM cash_counts cr WHERE $where_sql");
$ss->bind_param($types, ...$params);
$ss->execute();
$stats      = $ss->get_result()->fetch_assoc();
$total      = (int)$stats['total'];
$matches    = (int)$stats['matches'];
$overs      = (int)$stats['overs'];
$shorts     = (int)$stats['shorts'];
$total_diff = (float)$stats['total_diff'];
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;

// ── PAGINATED ROWS ──
$pparams = [...$params, $per_page, $offset];
$ptypes  = $types . 'ii';
$stmt = $conn->prepare("SELECT cr.id, cr.user_id, cr.username, cr.shift_date, cr.login_time,
               cr.expected_cash, cr.actual_cash, cr.difference, cr.recorded_at
        FROM cash_counts cr
        WHERE $where_sql
        ORDER BY cr.recorded_at DESC
        LIMIT ? OFFSET ?");
$stmt->bind_param($ptypes, ...$pparams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── PAGINATION URL HELPER ──
function page_url(int $p): string {
    $q = $_GET;
    unset($q['ajax']);
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

// ── RESULTS HTML (shared between AJAX and full-page) ──
ob_start();
?>
    <div class="table-head">
        <h3>Results</h3>
        <span class="count-badge"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="empty">
        <i class="fa-solid fa-inbox"></i>
        <h3>No records found</h3>
        <p>No drawer counts submitted for this date range.</p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Cashier</th>
                <th>Shift Date</th>
                <th>Login Time</th>
                <th>Expected</th>
                <th>Actual</th>
                <th>Difference</th>
                <th>Status</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $diff = (float)$r['difference'];
            if (abs($diff) < 0.01) {
                $status = 'match'; $diff_class = 'diff-match'; $diff_label = '✓ 0.00';
            } elseif ($diff > 0) {
                $status = 'over';  $diff_class = 'diff-over';  $diff_label = '+$' . number_format($diff, 2);
            } else {
                $status = 'short'; $diff_class = 'diff-short'; $diff_label = '-$' . number_format(abs($diff), 2);
            }
            $initial = strtoupper(substr($r['username'], 0, 1));
        ?>
        <tr class="row-<?= $status ?>">
            <td>
                <div class="cashier-cell">
                    <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                    <?= htmlspecialchars($r['username']) ?>
                </div>
            </td>
            <td><?= date('d M Y', strtotime($r['shift_date'])) ?></td>
            <td><?= date('g:i A', strtotime($r['login_time'])) ?></td>
            <td>$<?= number_format($r['expected_cash'], 2) ?></td>
            <td>$<?= number_format($r['actual_cash'], 2) ?></td>
            <td class="<?= $diff_class ?>"><?= $diff_label ?></td>
            <td><span class="badge <?= $status ?>"><?= $status === 'match' ? '✓ Match' : ($status === 'over' ? '↑ Over' : '↓ Short') ?></span></td>
            <td style="color:var(--muted2)"><?= date('g:i A', strtotime($r['recorded_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <div class="page-info">
            Showing <?= number_format(($page - 1) * $per_page + 1) ?>–<?= number_format(min($page * $per_page, $total)) ?> of <?= number_format($total) ?> records
        </div>
        <div class="page-btns">
            <a href="<?= page_url(1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" title="First">
                <i class="fa-solid fa-angles-left" style="font-size:11px"></i>
            </a>
            <a href="<?= page_url($page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" title="Previous">
                <i class="fa-solid fa-angle-left" style="font-size:11px"></i>
            </a>

            <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end   = min($total_pages, $page + $range);
            if ($start > 1) echo '<span class="page-dots">…</span>';
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="<?= page_url($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $total_pages) echo '<span class="page-dots">…</span>';
            ?>

            <a href="<?= page_url($page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Next">
                <i class="fa-solid fa-angle-right" style="font-size:11px"></i>
            </a>
            <a href="<?= page_url($total_pages) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Last">
                <i class="fa-solid fa-angles-right" style="font-size:11px"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
<?php
$results_html = ob_get_clean();

// ── AJAX: return only the results box content ──
if ($is_ajax) {
    header('Content-Type: text/html; charset=utf-8');
    echo $results_html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cash Count Report</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --green:#22c55e;--green-dim:rgba(34,197,94,.1);--green-border:rgba(34,197,94,.2);
  --red:#ef4444;--red-dim:rgba(239,68,68,.1);--red-border:rgba(239,68,68,.2);
  --yellow:#f59e0b;--yellow-dim:rgba(245,158,11,.1);--yellow-border:rgba(245,158,11,.2);
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --radius:14px;
}
@keyframes fadeInUp {from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes scaleIn  {from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
@keyframes fadeIn   {from{opacity:0}to{opacity:1}}
@keyframes rowIn    {from{opacity:0;transform:translateX(-8px)}to{opacity:1;transform:translateX(0)}}

body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),#0a0a0a;
  color:var(--text);min-height:100vh;
}

/* ── Top bar ── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 28px;
  background:rgba(255,255,255,.02);
  border-bottom:1px solid var(--border);
  animation:fadeIn .4s ease both;
}
.topbar-left{display:flex;align-items:center;gap:14px}
.back-btn{
  display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);font-size:12px;font-weight:500;text-decoration:none;transition:all .2s;
}
.back-btn:hover{color:var(--text);background:rgba(255,255,255,.09)}
.page-title{font-size:18px;font-weight:700;color:var(--text)}
.page-sub{font-size:12px;color:var(--muted);margin-top:1px}

/* ── Content ── */
.wrap{max-width:1100px;margin:0 auto;padding:28px 24px}

/* ── Summary cards ── */
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
@media(max-width:700px){.cards{grid-template-columns:repeat(2,1fr)}}
.card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:20px 22px;
  animation:scaleIn .45s cubic-bezier(.16,1,.3,1) both;
  transition:transform .2s,border-color .2s;
}
.card:hover{transform:translateY(-2px)}
.card:nth-child(1){animation-delay:.08s}
.card:nth-child(2){animation-delay:.14s}
.card:nth-child(3){animation-delay:.20s}
.card:nth-child(4){animation-delay:.26s}
.card-icon{font-size:18px;margin-bottom:10px;opacity:.6}
.card-val{font-size:30px;font-weight:800;line-height:1;margin-bottom:4px}
.card-label{font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px}
.card.green{border-color:var(--green-border);background:var(--green-dim)}
.card.green .card-val{color:var(--green)}
.card.green .card-icon{color:var(--green)}
.card.red{border-color:var(--red-border);background:var(--red-dim)}
.card.red .card-val{color:var(--red)}
.card.red .card-icon{color:var(--red)}
.card.yellow{border-color:var(--yellow-border);background:var(--yellow-dim)}
.card.yellow .card-val{color:var(--yellow)}
.card.yellow .card-icon{color:var(--yellow)}
.card.amber .card-val{color:var(--amber)}
.card.amber .card-icon{color:var(--amber)}

/* ── Filter bar ── */
.filter-bar{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 20px;margin-bottom:20px;
  display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;
  animation:fadeInUp .45s ease .3s both;
}
.filter-group{display:flex;flex-direction:column;gap:5px;min-width:140px}
.filter-group label{font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.4px}
.filter-group select,
.filter-group input{
  background:var(--surface2);border:1px solid var(--border);color:var(--text);
  border-radius:9px;padding:8px 12px;font-family:inherit;font-size:13px;outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.filter-group select:focus,
.filter-group input:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(209,144,75,.1)}
.filter-group select option{background:#1a1a1a}
.btn-filter{
  display:flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;border:none;
  background:var(--amber);color:#000;font-family:inherit;font-size:13px;font-weight:700;
  cursor:pointer;transition:all .2s;white-space:nowrap;align-self:flex-end;
}
.btn-filter:hover{opacity:.85;transform:translateY(-1px)}
.btn-reset{
  display:flex;align-items:center;gap:6px;padding:9px 14px;border-radius:9px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);font-family:inherit;font-size:13px;font-weight:500;
  cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap;align-self:flex-end;
}
.btn-reset:hover{color:var(--text);background:rgba(255,255,255,.09)}

/* ── Table box ── */
.table-wrap{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;
  animation:fadeInUp .45s ease .38s both;
}
.table-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);
}
.table-head h3{font-size:13px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px}
.count-badge{
  background:var(--amber-dim);border:1px solid var(--amber-border);
  color:var(--amber);font-size:12px;font-weight:600;
  padding:3px 10px;border-radius:20px;
}
table{width:100%;border-collapse:collapse}
th{
  font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;
  padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);background:var(--surface2);
}
td{padding:13px 16px;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s;}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.03)}
tbody tr{animation:rowIn .3s ease both;}
tbody tr:nth-child(1){animation-delay:.05s}
tbody tr:nth-child(2){animation-delay:.08s}
tbody tr:nth-child(3){animation-delay:.11s}
tbody tr:nth-child(4){animation-delay:.14s}
tbody tr:nth-child(5){animation-delay:.17s}
tbody tr:nth-child(6){animation-delay:.20s}
tbody tr:nth-child(7){animation-delay:.23s}
tbody tr:nth-child(8){animation-delay:.26s}
tbody tr:nth-child(9){animation-delay:.29s}
tbody tr:nth-child(10){animation-delay:.32s}
tr.row-short td:first-child{border-left:3px solid var(--red)}
tr.row-over  td:first-child{border-left:3px solid var(--yellow)}
tr.row-match td:first-child{border-left:3px solid var(--green)}

/* ── Status badges ── */
.badge{
  display:inline-flex;align-items:center;gap:5px;
  font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;
  text-transform:uppercase;letter-spacing:.3px;
}
.badge.match{background:var(--green-dim);color:var(--green);border:1px solid var(--green-border)}
.badge.over{background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow-border)}
.badge.short{background:var(--red-dim);color:var(--red);border:1px solid var(--red-border)}
.diff-match{color:var(--green);font-weight:700}
.diff-over{color:var(--yellow);font-weight:700}
.diff-short{color:var(--red);font-weight:700}
.cashier-cell{display:flex;align-items:center;gap:9px}
.avatar{
  width:30px;height:30px;border-radius:50%;
  background:var(--amber-dim);border:1px solid var(--amber-border);
  color:var(--amber);font-size:12px;font-weight:700;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}

/* ── Pagination ── */
.pagination{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-top:1px solid var(--border);
}
.page-info{font-size:12px;color:var(--muted2)}
.page-btns{display:flex;align-items:center;gap:4px}
.page-btn{
  display:flex;align-items:center;justify-content:center;
  min-width:34px;height:34px;padding:0 8px;border-radius:9px;
  background:rgba(255,255,255,.04);border:1px solid var(--border);
  color:var(--muted2);font-family:inherit;font-size:13px;font-weight:500;
  text-decoration:none;transition:all .2s;cursor:pointer;
}
.page-btn:hover{background:rgba(255,255,255,.09);color:var(--text);transform:translateY(-1px)}
.page-btn.active{background:var(--amber);color:#000;border-color:var(--amber);font-weight:700;pointer-events:none;}
.page-btn.disabled{opacity:.25;pointer-events:none;}
.page-dots{color:var(--muted);font-size:13px;padding:0 4px;}

/* ── Net diff bar ── */
.net-bar{
  display:flex;align-items:center;justify-content:flex-end;gap:10px;
  margin-top:14px;padding:12px 18px;border-radius:var(--radius);
  background:var(--surface);border:1px solid var(--border);
}
.net-bar-label{font-size:12px;color:var(--muted2)}
.net-bar-val{font-size:14px;font-weight:700}

/* ── Empty state ── */
.empty{padding:60px 20px;text-align:center;color:var(--muted);}
.empty i{font-size:52px;color:rgba(255,255,255,.05);margin-bottom:16px;display:block}
.empty h3{font-size:15px;font-weight:600;color:rgba(255,255,255,.12);margin-bottom:6px}
.empty p{font-size:13px;color:rgba(255,255,255,.1)}

/* ── AJAX loading state ── */
#results-box{transition:opacity .15s ease}
#results-box.loading{opacity:.35;pointer-events:none}

/* ── Inventory section ── */
.inv-section{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-top:20px;
  animation:fadeInUp .45s ease .5s both;
}
.inv-header{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);
}
.inv-title{font-size:13px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
.inv-new-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:9px;font-size:12px;font-weight:600;
  background:var(--amber-dim);border:1px solid var(--amber-border);
  color:var(--amber);text-decoration:none;transition:all .2s;
}
.inv-new-btn:hover{background:rgba(209,144,75,.2);}
.inv-view-btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 11px;border-radius:8px;font-size:11px;font-weight:500;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);text-decoration:none;transition:all .2s;white-space:nowrap;
}
.inv-view-btn:hover{color:var(--text);background:rgba(255,255,255,.09);}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <div>
            <div class="page-title"><i class="fa-solid fa-cash-register" style="color:var(--amber);margin-right:8px"></i>Cash Count Report</div>
            <div class="page-sub">Drawer counts submitted by cashiers at end of shift</div>
        </div>
    </div>
    <div style="font-size:12px;color:var(--muted)"><?= date('d M Y') ?></div>
</div>

<div class="wrap">

    <!-- Summary cards -->
    <div class="cards">
        <div class="card amber">
            <div class="card-icon"><i class="fa-solid fa-cash-register"></i></div>
            <div class="card-val" data-target="<?= $total ?>">0</div>
            <div class="card-label">Total Submissions</div>
        </div>
        <div class="card green">
            <div class="card-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="card-val" data-target="<?= $matches ?>">0</div>
            <div class="card-label">Exact Match</div>
        </div>
        <div class="card yellow">
            <div class="card-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="card-val" data-target="<?= $overs ?>">0</div>
            <div class="card-label">Over</div>
        </div>
        <div class="card red">
            <div class="card-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="card-val" data-target="<?= $shorts ?>">0</div>
            <div class="card-label">Short</div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar" id="filter-form">
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>">
        </div>
        <div class="filter-group">
            <label>Cashier</label>
            <select name="user_id">
                <option value="0">All cashiers</option>
                <?php foreach ($cashiers as $c): ?>
                <option value="<?= $c['user_id'] ?>" <?= $filter_user === (int)$c['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="reconciliation_report.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
    </form>

    <!-- Results box (AJAX target) -->
    <div class="table-wrap" id="results-box">
        <?= $results_html ?>
    </div>

    <?php if ($total > 0 && abs($total_diff) >= 0.01): ?>
    <div class="net-bar" id="net-bar">
        <span class="net-bar-label"><i class="fa-solid fa-scale-balanced" style="margin-right:6px"></i>Net difference this period</span>
        <span class="net-bar-val" style="color:<?= $total_diff >= 0 ? 'var(--yellow)' : 'var(--red)' ?>">
            <?= ($total_diff >= 0 ? '+' : '-') ?>$<?= number_format(abs($total_diff), 2) ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- ── INVENTORY STOCK COUNTS ── -->
    <div class="inv-section">
        <div class="inv-header">
            <div class="inv-title">
                <i class="fa-solid fa-clipboard-list" style="color:var(--amber)"></i>
                Inventory Stock Counts
            </div>
            <a href="stock_count.php" class="inv-new-btn">
                <i class="fa-solid fa-plus"></i> New Count
            </a>
        </div>

        <?php if (empty($sc_rows)): ?>
        <div class="empty" style="padding:40px 20px">
            <i class="fa-solid fa-clipboard-list"></i>
            <h3>No stock counts in this period</h3>
            <p>Stock counts submitted via the Stock Count page will appear here.</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="text-align:right">Items Counted</th>
                    <th style="text-align:right;color:#f87171">Shortages</th>
                    <th style="text-align:right;color:#fbbf24">Overages</th>
                    <th>Submitted By</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sc_rows as $scr):
                $shortage = (int)$scr['shortage_items'];
                $overage  = (int)$scr['overage_items'];
                $counted  = (int)$scr['counted_items'];
                $total_i  = (int)$scr['total_items'];
                $row_class = $shortage > 0 ? 'row-short' : ($overage > 0 ? 'row-over' : ($counted > 0 ? 'row-match' : ''));
            ?>
            <tr class="<?= $row_class ?>">
                <td>
                    <strong><?= date('d M Y', strtotime($scr['business_date'])) ?></strong>
                    <?php if ($scr['business_date'] === date('Y-m-d')): ?>
                    <span class="badge match" style="font-size:10px;padding:2px 7px;margin-left:6px">Today</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($scr['status'] === 'submitted'): ?>
                    <span class="badge match"><i class="fa-solid fa-lock"></i> Submitted</span>
                    <?php else: ?>
                    <span class="badge" style="background:rgba(255,255,255,.06);color:#888;border:1px solid rgba(255,255,255,.1)">
                        <i class="fa-solid fa-pencil"></i> Draft
                    </span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;color:var(--muted2)"><?= $counted ?> / <?= $total_i ?></td>
                <td style="text-align:right">
                    <?php if ($shortage > 0): ?>
                    <span class="badge short"><?= $shortage ?> item<?= $shortage !== 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <?php if ($overage > 0): ?>
                    <span class="badge over"><?= $overage ?> item<?= $overage !== 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--muted2)"><?= htmlspecialchars($scr['submitted_by'] ?? '—') ?></td>
                <td style="color:var(--muted);font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($scr['notes'] ?? '') ?>
                </td>
                <td>
                    <a href="stock_count.php?date=<?= $scr['business_date'] ?>" class="inv-view-btn">
                        View <i class="fa-solid fa-arrow-right" style="font-size:10px"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<script>
// Animate card counters
document.querySelectorAll('.card-val[data-target]').forEach(el => {
    const target = parseInt(el.dataset.target, 10);
    if (target === 0) { el.textContent = '0'; return; }
    const duration = 600;
    const start    = performance.now();
    function step(now) {
        const p = Math.min((now - start) / duration, 1);
        el.textContent = Math.round(p * target);
        if (p < 1) requestAnimationFrame(step);
    }
    setTimeout(() => requestAnimationFrame(step), parseInt(el.closest('.card').style.animationDelay || '0') * 1000 + 200);
});

// AJAX pagination — only swap the results box
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.page-btn');
    if (!btn) return;
    if (btn.classList.contains('disabled') || btn.classList.contains('active')) return;

    e.preventDefault();
    const href = btn.getAttribute('href');
    if (!href) return;

    const box = document.getElementById('results-box');
    box.classList.add('loading');

    try {
        const sep = href.includes('?') ? '&' : '?';
        const res  = await fetch(href + sep + 'ajax=1');
        const html = await res.text();
        box.innerHTML = html;
        history.pushState(null, '', href);
    } catch(err) {}

    box.classList.remove('loading');
});
</script>
</body>
</html>
