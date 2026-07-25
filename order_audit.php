<?php
require 'auth.php';

// Money-trail page: admin/manager only, matching the dashboard KPI gate.
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}

$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Optional filter by a single order
$f_order  = (int)($_GET['order_id'] ?? 0);
$where    = $f_order > 0 ? "WHERE a.order_id = ?" : "";

$count_sql = "SELECT COUNT(*) AS c FROM order_audit_log a $where";
$stmt_c = $conn->prepare($count_sql);
if ($f_order > 0) $stmt_c->bind_param("i", $f_order);
$stmt_c->execute();
$total_rows = (int)$stmt_c->get_result()->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$sql = "SELECT a.*, o.daily_order_no
        FROM order_audit_log a
        LEFT JOIN orders o ON o.order_id = a.order_id
        $where
        ORDER BY a.created_at DESC, a.audit_id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($f_order > 0) $stmt->bind_param("iii", $f_order, $per_page, $offset);
else              $stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order Change Log | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
    --bg:#0e0e0e; --card:#161616; --border:#242424;
    --text:#f0f0f0; --muted:#8a8a8a; --amber:#d1904b;
    --red:#ff6b6b; --green:#3ecf70;
}
[data-theme="light"]{
    --bg:#faf6f0; --card:#fffdf9; --border:#e8ded0;
    --text:#1a1410; --muted:#7a6a5a;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);padding:24px;min-height:100vh}
.wrap{max-width:1100px;margin:0 auto}
.top{display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap}
.back{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:50px;
      border:1px solid var(--border);background:var(--card);color:var(--text);
      text-decoration:none;font-size:13px;font-weight:600}
.back:hover{border-color:var(--amber);color:var(--amber)}
h1{font-size:21px;font-weight:700;margin-left:4px}
.sub{color:var(--muted);font-size:13px;margin:8px 0 20px;line-height:1.6}
.panel{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:13px 14px;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;
   color:var(--muted);font-weight:700;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:13px 14px;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
.ordno{font-weight:700;color:var(--amber)}
.who{font-weight:600}
.act{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;
     background:rgba(209,144,75,.14);color:var(--amber);border:1px solid rgba(209,144,75,.3)}
.detail{color:var(--muted);font-size:12.5px;line-height:1.55;max-width:340px}
.money{white-space:nowrap;font-weight:600}
.money .b{color:var(--muted);text-decoration:line-through;font-weight:400}
.money .a{color:var(--text)}
.up{color:var(--green)} .down{color:var(--red)}
.when{color:var(--muted);font-size:12px;white-space:nowrap}
.empty{padding:52px 20px;text-align:center;color:var(--muted)}
.empty i{font-size:34px;opacity:.4;display:block;margin-bottom:12px}
.pager{display:flex;gap:8px;justify-content:center;align-items:center;margin-top:18px;flex-wrap:wrap}
.pager a,.pager span{padding:7px 13px;border-radius:9px;border:1px solid var(--border);
    background:var(--card);color:var(--text);text-decoration:none;font-size:12.5px;font-weight:600}
.pager .on{background:var(--amber);border-color:var(--amber);color:#fff}
.pager .off{opacity:.4;pointer-events:none}
@media(max-width:720px){ .detail{max-width:none} th:nth-child(5),td:nth-child(5){display:none} }
</style>
</head>
<body>
<div class="wrap">

    <div class="top">
        <a href="dashboard.php" class="back"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <h1><i class="fa-solid fa-clock-rotate-left"></i> Order Change Log</h1>
    </div>
    <p class="sub">
        Every edit made to an order <b>after</b> it was placed. Revenue and item totals are calculated
        from these orders, so this is the record of anyone changing a figure the reports depend on.
        Entries are append-only &mdash; nothing in the system edits or deletes them.
        <?php if ($f_order > 0): ?>
            <br><b>Filtered to order #<?= (int)$f_order ?>.</b> <a href="order_audit.php" style="color:var(--amber)">Show all</a>
        <?php endif; ?>
    </p>

    <div class="panel">
    <?php if (!$rows): ?>
        <div class="empty">
            <i class="fa-solid fa-shield-halved"></i>
            No order changes recorded<?= $f_order > 0 ? ' for this order' : ' yet' ?>.
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Changed by</th>
                    <th>Action</th>
                    <th>What changed</th>
                    <th>Total</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $tb = $r['total_before']; $ta = $r['total_after'];
                $diff = ($tb !== null && $ta !== null) ? (float)$ta - (float)$tb : null;
            ?>
                <tr>
                    <td><span class="ordno">#<?= h($r['daily_order_no'] ?? $r['order_id']) ?></span></td>
                    <td><span class="who"><?= h($r['user_name'] ?: 'unknown') ?></span></td>
                    <td><span class="act"><?= h(str_replace('_', ' ', $r['action'])) ?></span></td>
                    <td class="detail"><?= h($r['detail']) ?></td>
                    <td class="money">
                        <?php if ($diff !== null): ?>
                            <span class="b">$<?= number_format((float)$tb, 2) ?></span>
                            <span class="a">$<?= number_format((float)$ta, 2) ?></span>
                            <?php if (abs($diff) >= 0.005): ?>
                                <span class="<?= $diff > 0 ? 'up' : 'down' ?>">
                                    (<?= $diff > 0 ? '+' : '−' ?>$<?= number_format(abs($diff), 2) ?>)
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td class="when"><?= date('d M Y, g:i A', strtotime($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>

    <?php if ($total_pages > 1):
        $qs = $f_order > 0 ? '&order_id=' . (int)$f_order : ''; ?>
    <div class="pager">
        <a class="<?= $page <= 1 ? 'off' : '' ?>" href="?page=<?= $page - 1 ?><?= $qs ?>">Prev</a>
        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <a class="<?= $i === $page ? 'on' : '' ?>" href="?page=<?= $i ?><?= $qs ?>"><?= $i ?></a>
        <?php endfor; ?>
        <a class="<?= $page >= $total_pages ? 'off' : '' ?>" href="?page=<?= $page + 1 ?><?= $qs ?>">Next</a>
    </div>
    <?php endif; ?>

</div>
<script>
// Match the app-wide theme choice (shared localStorage key, dark default).
(function(){
    var t = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', t);
})();
</script>
</body>
</html>
