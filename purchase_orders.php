<?php
require 'auth.php';
require 'config.php';
if (!can('purchase_orders')) { header("Location: dashboard.php?denied=1"); exit; }

$filter = $_GET['status'] ?? 'all';
$allowed = ['all','Draft','Ordered','Received','Cancelled'];
if (!in_array($filter, $allowed)) $filter = 'all';

// Stats
$stats = [];
$sres = $conn->query("SELECT status, COUNT(*) AS cnt, IFNULL(SUM(total_cost),0) AS tot FROM purchase_orders GROUP BY status");
$allTotal = 0; $allCount = 0; $receivedTotal = 0; $pendingCount = 0;
while ($sr = $sres->fetch_assoc()) {
    $stats[$sr['status']] = $sr;
    $allCount += $sr['cnt'];
    $allTotal += $sr['tot'];
    if ($sr['status'] === 'Received') $receivedTotal = $sr['tot'];
    if (in_array($sr['status'], ['Draft','Ordered'])) $pendingCount += $sr['cnt'];
}

// PO list
$where = $filter !== 'all' ? "WHERE p.status = '$filter'" : '';
$pos = [];
$res = $conn->query("
    SELECT p.*, s.name AS supplier_name,
           COUNT(i.poi_id) AS item_count
    FROM purchase_orders p
    JOIN suppliers s ON s.supplier_id = p.supplier_id
    LEFT JOIN purchase_order_items i ON i.po_id = p.po_id
    $where
    GROUP BY p.po_id
    ORDER BY p.created_at DESC
");
if ($res) while ($r = $res->fetch_assoc()) $pos[] = $r;

$statusColors = [
    'Draft'     => ['bg'=>'rgba(255,255,255,.06)',  'color'=>'#888',     'icon'=>'fa-pen'],
    'Ordered'   => ['bg'=>'rgba(52,152,219,.15)',   'color'=>'#3498db',  'icon'=>'fa-clock'],
    'Received'  => ['bg'=>'rgba(85,224,135,.13)',   'color'=>'#55e087',  'icon'=>'fa-check'],
    'Cancelled' => ['bg'=>'rgba(255,95,95,.12)',    'color'=>'#ff5f5f',  'icon'=>'fa-xmark'],
];
function he($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purchase Orders | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
    --bg:#0b0b0b; --bg-card:#131313; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --success:#55e087; --danger:#ff5f5f; --warning:#f1c40f; --info:#3498db;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35);
    --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-lg:0 8px 40px rgba(0,0,0,.55);
    --shadow-accent:0 4px 20px rgba(209,144,75,.18);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

.topbar{display:flex;align-items:center;gap:12px;padding:18px 32px;border-bottom:1px solid var(--border);background:var(--bg-card);position:sticky;top:0;z-index:100;}
.topbar-back{display:flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:var(--transition);}
.topbar-back:hover{color:var(--accent);border-color:var(--accent);}
.topbar-title{font-size:18px;font-weight:700;color:var(--text-light);flex:1;}
.topbar-title i{color:var(--accent);margin-right:8px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;border:none;font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:var(--transition);text-decoration:none;}
.btn-accent{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#000;}
.btn-accent:hover{transform:translateY(-2px);box-shadow:var(--shadow-accent);filter:brightness(1.08);}
.btn-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border);}
.btn-outline:hover{color:var(--accent);border-color:var(--accent);}
.btn-sm{padding:5px 12px;font-size:12px;}

.content{max-width:1100px;margin:0 auto;padding:32px 24px;}

.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;align-items:center;gap:14px;animation:fadeUp .4s ease both;}
.stat-card:nth-child(2){animation-delay:.06s;}
.stat-card:nth-child(3){animation-delay:.12s;}
.stat-card:nth-child(4){animation-delay:.18s;}
.stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.stat-icon.orange{background:rgba(209,144,75,.15);color:var(--accent);}
.stat-icon.blue{background:rgba(52,152,219,.12);color:var(--info);}
.stat-icon.green{background:rgba(85,224,135,.12);color:var(--success);}
.stat-icon.yellow{background:rgba(241,196,15,.12);color:var(--warning);}
.stat-val{font-size:20px;font-weight:700;color:var(--text-light);}
.stat-lbl{font-size:11px;color:var(--text-muted);}

/* Tabs */
.tabs{display:flex;gap:4px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:4px;margin-bottom:16px;animation:fadeUp .4s .22s ease both;overflow-x:auto;}
.tab{display:flex;align-items:center;gap:6px;padding:7px 16px;border-radius:7px;font-size:12px;font-weight:600;color:var(--text-muted);text-decoration:none;white-space:nowrap;transition:var(--transition);}
.tab:hover{color:var(--text);background:rgba(255,255,255,.05);}
.tab.active{background:var(--accent);color:#000;}
.tab .cnt{background:rgba(0,0,0,.2);border-radius:20px;padding:1px 7px;font-size:10px;}
.tab.active .cnt{background:rgba(0,0,0,.25);}

.table-wrap{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:fadeUp .45s .28s ease both;}
table{width:100%;border-collapse:collapse;}
thead th{padding:12px 18px;text-align:left;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);}
tbody td{padding:13px 18px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:rgba(255,255,255,.025);}

.po-num{font-weight:700;color:var(--accent);font-size:13px;}
.po-date{font-size:11px;color:var(--text-muted);}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:600;}
.actions{display:flex;gap:6px;justify-content:flex-end;}
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
.empty-state i{font-size:48px;color:var(--border-hover);display:block;margin-bottom:12px;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){
    .topbar{padding:14px 16px;}
    .content{padding:20px 12px;}
    .stats-row{grid-template-columns:1fr 1fr;}
    thead th:nth-child(4),tbody td:nth-child(4){display:none;}
}
</style>
</head>
<body>

<div class="topbar">
    <a class="topbar-back" href="suppliers.php"><i class="fa-solid fa-arrow-left"></i> Suppliers</a>
    <div class="topbar-title"><i class="fa-solid fa-file-invoice"></i> Purchase Orders</div>
    <a class="btn btn-accent" href="purchase_order_create.php"><i class="fa-solid fa-plus"></i> New PO</a>
</div>

<div class="content">
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fa-solid fa-file-invoice"></i></div>
            <div><div class="stat-val"><?= $allCount ?></div><div class="stat-lbl">Total POs</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="fa-solid fa-clock"></i></div>
            <div><div class="stat-val"><?= $pendingCount ?></div><div class="stat-lbl">Pending</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div>
            <div><div class="stat-val">$<?= number_format($receivedTotal,2) ?></div><div class="stat-lbl">Total Received</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-dollar-sign"></i></div>
            <div><div class="stat-val">$<?= number_format($allTotal,2) ?></div><div class="stat-lbl">All Time Spend</div></div>
        </div>
    </div>

    <!-- Tabs -->
    <?php
    $tabs = [
        'all'       => ['label'=>'All',       'icon'=>'fa-list'],
        'Draft'     => ['label'=>'Draft',     'icon'=>'fa-pen'],
        'Ordered'   => ['label'=>'Ordered',   'icon'=>'fa-clock'],
        'Received'  => ['label'=>'Received',  'icon'=>'fa-check'],
        'Cancelled' => ['label'=>'Cancelled', 'icon'=>'fa-xmark'],
    ];
    ?>
    <div class="tabs">
        <?php foreach ($tabs as $key => $tab):
            $cnt = $key === 'all' ? $allCount : (int)($stats[$key]['cnt'] ?? 0);
            $active = $filter === $key ? 'active' : '';
        ?>
        <a class="tab <?= $active ?>" href="?status=<?= $key ?>">
            <i class="fa-solid <?= $tab['icon'] ?>"></i> <?= $tab['label'] ?>
            <span class="cnt"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap">
        <?php if (empty($pos)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-file-invoice"></i>
            <p>No purchase orders<?= $filter !== 'all' ? " with status \"$filter\"" : '' ?>.</p>
            <br><a class="btn btn-accent" href="purchase_order_create.php" style="margin:auto"><i class="fa-solid fa-plus"></i> Create First PO</a>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th style="text-align:right">Total Cost</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pos as $po):
                $sc = $statusColors[$po['status']] ?? $statusColors['Draft'];
            ?>
            <tr>
                <td>
                    <div class="po-num"><?= he($po['po_number']) ?></div>
                    <div class="po-date"><?= date('M j, Y', strtotime($po['created_at'])) ?></div>
                </td>
                <td><?= he($po['supplier_name']) ?></td>
                <td>
                    <span class="status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                        <i class="fa-solid <?= $sc['icon'] ?>"></i> <?= $po['status'] ?>
                    </span>
                </td>
                <td><?= (int)$po['item_count'] ?> item<?= $po['item_count'] != 1 ? 's' : '' ?></td>
                <td style="text-align:right;font-weight:600;color:var(--success)">
                    $<?= number_format($po['total_cost'],2) ?>
                </td>
                <td>
                    <div class="actions">
                        <a class="btn btn-outline btn-sm" href="purchase_order_view.php?po_id=<?= $po['po_id'] ?>">
                            <i class="fa-solid fa-eye"></i> View
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
