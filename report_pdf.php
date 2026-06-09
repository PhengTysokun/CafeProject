<?php
require 'admin_only.php';
require 'config.php';
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getBusinessDateToday(): string {
    $now = new DateTime();
    if ((int)$now->format("H") < 6) $now->modify("-1 day");
    return $now->format("Y-m-d");
}

// ── Date range (same logic as report.php) ──
$mode = $_GET['mode'] ?? 'daily';
if (!in_array($mode, ['daily','monthly','range'])) $mode = 'daily';

if ($mode === 'monthly') {
    $month = $_GET['month'] ?? (new DateTime())->format("Y-m");
    $start = new DateTime($month . "-01 06:00:00");
    $end   = clone $start;
    $end->modify("+1 month")->modify("-1 second");
    $label = $start->format("F Y");
} elseif ($mode === 'range') {
    $fromDate = $_GET['from_date'] ?? getBusinessDateToday();
    $toDate   = $_GET['to_date']   ?? getBusinessDateToday();
    $start = new DateTime($fromDate . " 06:00:00");
    $end   = new DateTime($toDate   . " 06:00:00");
    $end->modify("+1 day")->modify("-1 second");
    $label = (new DateTime($fromDate))->format("d M Y") . " to " . (new DateTime($toDate))->format("d M Y");
} else {
    $date  = $_GET['date'] ?? getBusinessDateToday();
    $start = new DateTime($date . " 06:00:00");
    $end   = clone $start;
    $end->modify("+1 day")->modify("-1 second");
    $label = (new DateTime($date))->format("d M Y");
}

$startStr = $start->format("Y-m-d H:i:s");
$endStr   = $end->format("Y-m-d H:i:s");

// ── Ingredient costs ──
$ingredients = [];
$qIng = mysqli_query($conn, "SELECT ingredient_id, ingredient_name, cost_price, purchase_qty, cost_per_unit FROM ingredients");
while ($r = mysqli_fetch_assoc($qIng)) {
    $cpu = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)$r['purchase_qty'] > 0)
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    $iid = (int)$r['ingredient_id'];
    $ingredients[$iid] = ['name' => $r['ingredient_name'], 'unit_cost' => $cpu];
    $ingredients[strtolower(trim($r['ingredient_name']))] = ['id' => $iid, 'name' => $r['ingredient_name'], 'unit_cost' => $cpu];
}

// ── Orders ──
$orderIds   = [];
$totalSales = 0;
$orderCount = 0;
$qOrders = mysqli_query($conn, "SELECT order_id, total FROM orders WHERE status='Completed' AND order_date BETWEEN '$startStr' AND '$endStr'");
while ($o = mysqli_fetch_assoc($qOrders)) {
    $orderIds[] = (int)$o['order_id'];
    $totalSales += (float)$o['total'];
    $orderCount++;
}
$avgOrder = $orderCount > 0 ? $totalSales / $orderCount : 0;

$totalCOGS     = 0;
$totalItemsSold = 0;
$topProducts   = [];
$categorySales = [];

if (count($orderIds) > 0) {
    $inOrder    = implode(",", $orderIds);
    $productIds = [];
    $items      = [];

    $qItems = mysqli_query($conn, "
        SELECT oi.product_id, oi.product_name, oi.milk, oi.quantity, oi.price,
               COALESCE(NULLIF(p.category,''),'Uncategorized') AS category
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id IN ($inOrder) AND oi.price > 0
    ");
    while ($it = mysqli_fetch_assoc($qItems)) {
        $items[] = $it;
        if ((int)$it['product_id'] > 0) $productIds[(int)$it['product_id']] = true;
    }

    $recipes = [];
    if (count($productIds) > 0) {
        $inProduct = implode(",", array_map('intval', array_keys($productIds)));
        $qRec = mysqli_query($conn, "
            SELECT pi.product_id, pi.ingredient_id, pi.amount_used, i.ingredient_name
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id IN ($inProduct)
        ");
        while ($r = mysqli_fetch_assoc($qRec)) {
            $pid = (int)$r['product_id'];
            $recipes[$pid][] = ['ingredient_id'=>(int)$r['ingredient_id'],'ingredient_name'=>$r['ingredient_name'],'amount_used'=>(float)$r['amount_used']];
        }
    }

    foreach ($items as $it) {
        $pid      = (int)$it['product_id'];
        $qty      = max(1, (int)$it['quantity']);
        $milkType = trim((string)$it['milk']);
        $pname    = (string)$it['product_name'];
        $category = trim((string)$it['category']) ?: 'Uncategorized';
        $itemCost = 0;

        if (isset($recipes[$pid])) {
            foreach ($recipes[$pid] as $rc) {
                $iname  = strtolower(trim($rc['ingredient_name']));
                $amount = (float)$rc['amount_used'] * $qty;
                if ($amount <= 0) continue;
                if (strpos($iname, 'milk') !== false) {
                    $key = strtolower(trim($milkType ?: 'Fresh Milk'));
                    $itemCost += $amount * (float)($ingredients[$key]['unit_cost'] ?? 0);
                } else {
                    $iid = (int)$rc['ingredient_id'];
                    $itemCost += $amount * (float)($ingredients[$iid]['unit_cost'] ?? 0);
                }
            }
        }
        $totalCOGS     += $itemCost;
        $totalItemsSold += $qty;
        $revenue        = (float)$it['price'] * $qty;

        if (!isset($topProducts[$pname])) $topProducts[$pname] = ['qty'=>0,'cogs'=>0,'revenue'=>0,'category'=>$category];
        $topProducts[$pname]['qty']     += $qty;
        $topProducts[$pname]['cogs']    += $itemCost;
        $topProducts[$pname]['revenue'] += $revenue;

        if (!isset($categorySales[$category])) $categorySales[$category] = ['qty'=>0,'revenue'=>0,'cogs'=>0];
        $categorySales[$category]['qty']     += $qty;
        $categorySales[$category]['revenue'] += $revenue;
        $categorySales[$category]['cogs']    += $itemCost;
    }

    uasort($topProducts, fn($a,$b) => $b['revenue'] <=> $a['revenue']);
    uasort($categorySales, fn($a,$b) => $b['revenue'] <=> $a['revenue']);
}

$totalProfit = $totalSales - $totalCOGS;
$margin      = $totalSales > 0 ? ($totalProfit / $totalSales * 100) : 0;
$generated   = date('d M Y, g:i A');
$reportId    = 'SAL-' . date('Ymd-Hi');
$modeLabel   = match($mode) { 'monthly'=>'Monthly Report', 'range'=>'Date Range Report', default=>'Daily Report' };

// ── Refunds ──
$totalRefunded = 0;
$refundCount   = 0;
$qRef = mysqli_query($conn, "SELECT COALESCE(SUM(refund_amount),0) as total, COUNT(*) as cnt FROM orders WHERE is_refunded=1 AND refunded_at BETWEEN '$startStr' AND '$endStr'");
if ($rf = mysqli_fetch_assoc($qRef)) { $totalRefunded = (float)$rf['total']; $refundCount = (int)$rf['cnt']; }
$netRevenue = $totalSales - $totalRefunded;

// ── Remakes ──
$remakeCount = 0;
$remakeRows  = '';
$_tbl_chk = mysqli_query($conn, "SHOW TABLES LIKE 'order_remakes'");
if ($_tbl_chk && mysqli_num_rows($_tbl_chk) > 0) {
    $qRem = mysqli_query($conn, "
        SELECT rm.reason, rm.remade_by, rm.remade_at,
               o.daily_order_no, o.customer_name,
               GROUP_CONCAT(DISTINCT oi.product_name ORDER BY oi.product_name SEPARATOR ', ') AS products
        FROM order_remakes rm
        JOIN orders o ON o.order_id = rm.order_id
        LEFT JOIN order_items oi ON oi.order_id = rm.order_id
        WHERE rm.remade_at BETWEEN '$startStr' AND '$endStr'
        GROUP BY rm.id
        ORDER BY rm.remade_at DESC
    ");
    $ri = 1;
    while ($r = mysqli_fetch_assoc($qRem)) {
        $remakeCount++;
        $rowBg = ($ri % 2 === 0) ? '#f9f6f2' : '#ffffff';
        $remakeRows .= '<tr style="background:'.$rowBg.';">'
            . '<td>#'.he((string)$r['daily_order_no']).'</td>'
            . '<td>'.he($r['customer_name']).'</td>'
            . '<td>'.he($r['products'] ?? '—').'</td>'
            . '<td style="font-style:italic;color:#555;">'.he($r['reason']).'</td>'
            . '<td>'.he($r['remade_by']).'</td>'
            . '<td style="color:#6b7280;font-size:9px;">'.date('g:i A', strtotime($r['remade_at'])).'</td>'
            . '</tr>';
        $ri++;
    }
}

// ── Peak hour (daily only) ──
$peakHour = null;
if ($mode === 'daily') {
    $qPH = mysqli_query($conn, "SELECT HOUR(order_date) as h, SUM(total) as rev FROM orders WHERE status='Completed' AND order_date BETWEEN '$startStr' AND '$endStr' GROUP BY HOUR(order_date) ORDER BY rev DESC LIMIT 1");
    if ($ph = mysqli_fetch_assoc($qPH)) $peakHour = date('g:i A', mktime((int)$ph['h'], 0, 0));
}

// Top performer
$topProdName = array_key_first($topProducts) ?? null;
$topProdData = $topProdName ? $topProducts[$topProdName] : null;

// Executive summary (HTML — do NOT wrap in he())
$refundNote = $refundCount > 0
    ? "<strong>{$refundCount}</strong> " . ($refundCount === 1 ? 'order was' : 'orders were') . " refunded totalling <strong>\$" . number_format($totalRefunded,2) . "</strong>, reducing net revenue to <strong>\$" . number_format($netRevenue,2) . "</strong>."
    : "No refunds were issued.";
$peakNote  = ($mode === 'daily' && $peakHour) ? " Busiest sales hour: <strong>{$peakHour}</strong>." : '';
$topNote   = $topProdName ? " Best seller: <strong>" . he($topProdName) . "</strong> ({$topProdData['qty']} sold, \$" . number_format($topProdData['revenue'],2) . " revenue)." : '';
$marginNote = $margin >= 30 ? 'healthy' : 'below the 30% target';

if ($orderCount === 0) {
    $exec_summary = "No completed orders were recorded for <strong>" . he($label) . "</strong>. The café may have been closed or data has not been entered yet.";
} else {
    $exec_summary = "For <strong>" . he($label) . "</strong>, the café completed <strong>{$orderCount}</strong> " . ($orderCount === 1 ? 'order' : 'orders') . " generating <strong>\$" . number_format($totalSales,2) . "</strong> in sales across <strong>{$totalItemsSold}</strong> items (avg <strong>\$" . number_format($avgOrder,2) . "</strong> per order). "
        . "After ingredient costs of <strong>\$" . number_format($totalCOGS,2) . "</strong>, the gross profit was <strong>\$" . number_format($totalProfit,2) . "</strong> — a margin of <strong>" . number_format($margin,1) . "%</strong> ({$marginNote}). "
        . $refundNote . $peakNote . $topNote;
}

// ── Product rows ──
$prod_rows = '';
$pi = 1;
foreach ($topProducts as $name => $p) {
    $pmargin    = $p['revenue'] > 0 ? (($p['revenue'] - $p['cogs']) / $p['revenue'] * 100) : 0;
    $pprofit    = $p['revenue'] - $p['cogs'];
    $revShare   = $totalSales > 0 ? round($p['revenue'] / $totalSales * 100, 1) : 0;
    $rowBg      = ($pi % 2 === 0) ? '#f9f6f2' : '#ffffff';
    $mc         = $pmargin >= 60 ? '#166534' : ($pmargin >= 40 ? '#713f12' : '#991b1b');
    $mbg        = $pmargin >= 60 ? '#dcfce7'  : ($pmargin >= 40 ? '#fef9c3'  : '#fee2e2');
    $starBadge  = ($pi === 1 && $orderCount > 0) ? ' <span style="background:#fef9c3;color:#713f12;font-size:7.5px;padding:1px 5px;border-radius:8px;font-weight:700;">TOP</span>' : '';
    $prod_rows .= '
    <tr style="background:'.$rowBg.';">
        <td style="color:#9ca3af;font-size:9px;text-align:center;">'.$pi.'</td>
        <td><strong style="color:#111827;">'.he($name).'</strong>'.$starBadge.'<br><span style="font-size:8.5px;color:#9ca3af;">'.he($p['category']).'</span></td>
        <td style="text-align:center;font-weight:700;color:#374151;">'.$p['qty'].'</td>
        <td style="text-align:right;font-weight:700;color:#111827;">$'.number_format($p['revenue'],2).'</td>
        <td style="text-align:right;color:#d1904b;font-weight:600;">$'.number_format($p['cogs'],2).'</td>
        <td style="text-align:right;color:#1e3a8a;font-weight:700;">$'.number_format($pprofit,2).'</td>
        <td style="text-align:center;font-size:9px;color:#6b7280;">'.$revShare.'%</td>
        <td style="text-align:center;"><span style="background:'.$mbg.';color:'.$mc.';padding:2px 7px;border-radius:10px;font-size:8.5px;font-weight:700;">'.number_format($pmargin,1).'%</span></td>
    </tr>';
    $pi++;
}
if (!$prod_rows) $prod_rows = '<tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px;">No sales data for this period.</td></tr>';

// ── Category rows ──
$cat_rows = '';
$maxRevCat = count($categorySales) > 0 ? max(array_column($categorySales, 'revenue')) : 1;
foreach ($categorySales as $cat => $c) {
    $cmargin  = $c['revenue'] > 0 ? (($c['revenue'] - $c['cogs']) / $c['revenue'] * 100) : 0;
    $cRevShare = $totalSales > 0 ? round($c['revenue'] / $totalSales * 100, 1) : 0;
    $barWidth = $maxRevCat > 0 ? round($c['revenue'] / $maxRevCat * 80) : 0;
    $mc = $cmargin >= 50 ? '#166534' : '#713f12';
    $cat_rows .= '
    <tr>
        <td style="font-weight:700;color:#111827;">'.he($cat).'</td>
        <td style="text-align:center;font-weight:600;color:#374151;">'.$c['qty'].'</td>
        <td style="text-align:right;font-weight:700;color:#111827;">$'.number_format($c['revenue'],2).'</td>
        <td style="text-align:right;color:#d1904b;">$'.number_format($c['cogs'],2).'</td>
        <td style="text-align:right;color:#1e3a8a;font-weight:700;">$'.number_format($c['revenue']-$c['cogs'],2).'</td>
        <td style="text-align:center;color:#6b7280;">'.$cRevShare.'%</td>
        <td style="text-align:center;font-weight:700;color:'.$mc.';">'.number_format($cmargin,1).'%</td>
    </tr>';
}
if (!$cat_rows) $cat_rows = '<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:12px;">No data.</td></tr>';

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 portrait; margin: 0; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111827; margin: 0; padding: 0; }

.top-stripe { background: #d1904b; height: 5px; width: 100%; }
.page-body  { padding: 11mm 13mm 13mm; }

.header { display: table; width: 100%; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 12px; }
.header-left  { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; }

.brand-name    { font-size: 20px; font-weight: 700; color: #111827; letter-spacing: .5px; text-transform: uppercase; }
.brand-tagline { font-size: 9px; color: #6b7280; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
.brand-contact { font-size: 9px; color: #9ca3af; margin-top: 4px; }

.report-badge { display: inline-block; background: #eff6ff; color: #1d4ed8; font-size: 8px; font-weight: 700; padding: 2px 8px; border-radius: 10px; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 4px; }
.conf-badge   { display: inline-block; background: #fee2e2; color: #991b1b; font-size: 8px; font-weight: 700; padding: 2px 8px; border-radius: 10px; letter-spacing: .5px; text-transform: uppercase; margin-left: 4px; }
.report-title { font-size: 17px; font-weight: 700; color: #d1904b; margin-top: 2px; }
.report-period { font-size: 11px; color: #374151; font-weight: 600; margin-top: 2px; }
.report-meta  { font-size: 9px; color: #9ca3af; margin-top: 2px; }
.report-id    { font-size: 9px; color: #6b7280; font-weight: 600; margin-top: 2px; }

.exec-box { background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid #d1904b; border-radius: 4px; padding: 9px 13px; margin-bottom: 12px; font-size: 10px; color: #374151; line-height: 1.6; }
.exec-label { font-size: 8.5px; font-weight: 700; color: #d1904b; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 4px; }

.stats { display: table; width: 100%; margin-bottom: 12px; }
.stat-cell { display: table-cell; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 6px; text-align: center; }
.stat-cell.orange { border-top: 3px solid #d1904b; }
.stat-cell.green  { border-top: 3px solid #16a34a; }
.stat-cell.blue   { border-top: 3px solid #2563eb; }
.stat-cell.purple { border-top: 3px solid #7c3aed; }
.stat-cell.teal   { border-top: 3px solid #0d9488; }
.stat-cell.gray   { border-top: 3px solid #6b7280; }
.stat-gap { display: table-cell; width: 5px; }
.stat-num { font-size: 15px; font-weight: 700; color: #111827; line-height: 1; }
.stat-lbl { font-size: 7.5px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
.stat-sub { font-size: 7.5px; color: #9ca3af; margin-top: 2px; }

.section-title { font-size: 11px; font-weight: 700; color: #111827; border-left: 3px solid #d1904b; padding-left: 8px; margin: 12px 0 7px; }

table.main { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 12px; }
table.main thead tr { background: #d1904b; }
table.main thead th { color: #fff; font-weight: 700; padding: 7px 8px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; }
table.main thead th.right  { text-align: right; }
table.main thead th.center { text-align: center; }
table.main tbody td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
table.main tfoot td { padding: 7px 8px; border-top: 2px solid #d1904b; font-weight: 700; font-size: 10.5px; background: #fffbf5; }

table.cat-table { width: 100%; border-collapse: collapse; font-size: 10px; }
table.cat-table thead tr { background: #374151; }
table.cat-table thead th { color: #fff; padding: 6px 8px; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; text-align: left; }
table.cat-table thead th.right  { text-align: right; }
table.cat-table thead th.center { text-align: center; }
table.cat-table tbody td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }
table.cat-table tfoot td { padding: 6px 8px; border-top: 2px solid #374151; font-weight: 700; background: #f9fafb; font-size: 10px; }

.sig-row { display: table; width: 100%; margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
.sig-cell { display: table-cell; text-align: center; }
.sig-line { border-top: 1px solid #9ca3af; width: 130px; margin: 0 auto; padding-top: 4px; font-size: 9px; color: #6b7280; }
.sig-label { font-size: 8px; color: #9ca3af; margin-top: 2px; text-transform: uppercase; letter-spacing: .5px; }

.footer { margin-top: 12px; border-top: 1px solid #e5e7eb; padding-top: 6px; display: table; width: 100%; }
.footer-left  { display: table-cell; font-size: 8px; color: #9ca3af; vertical-align: middle; }
.footer-right { display: table-cell; text-align: right; font-size: 8px; color: #9ca3af; vertical-align: middle; }
</style>
</head>
<body>
<div class="top-stripe"></div>
<div class="page-body">

<div class="header">
    <div class="header-left">
        <div class="brand-name">Bird\'s Nest Coffee</div>
        <div class="brand-tagline">Specialty Coffee &amp; Beverages</div>
        <div class="brand-contact">2nd Floor, Chbar Ampov District, Phnom Penh, Cambodia &nbsp;|&nbsp; operations@birdsnestcoffee.com</div>
    </div>
    <div class="header-right">
        <div>
            <span class="report-badge">Sales &amp; Finance</span><span class="conf-badge">Confidential</span>
        </div>
        <div class="report-title">Sales Report</div>
        <div class="report-period">'.he($modeLabel).' &mdash; '.he($label).'</div>
        <div class="report-id">Report No: '.$reportId.'</div>
        <div class="report-meta">Generated: '.$generated.' &nbsp;|&nbsp; Phnom Penh Time</div>
    </div>
</div>

<div class="exec-box">
    <div class="exec-label">Period Summary</div>
    '.$exec_summary.'
</div>

<div class="stats">
    <div class="stat-cell orange">
        <div class="stat-num">'.$orderCount.'</div>
        <div class="stat-lbl">Orders</div>
        <div class="stat-sub">$'.number_format($avgOrder,2).' avg each</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell gray">
        <div class="stat-num">'.$totalItemsSold.'</div>
        <div class="stat-lbl">Items Sold</div>
        <div class="stat-sub">Total qty</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell green">
        <div class="stat-num">$'.number_format($totalSales,2).'</div>
        <div class="stat-lbl">Sales</div>
        <div class="stat-sub">Paid orders</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell blue">
        <div class="stat-num">$'.number_format($totalCOGS,2).'</div>
        <div class="stat-lbl">Food Cost</div>
        <div class="stat-sub">Ingredients used</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell purple">
        <div class="stat-num">$'.number_format($totalProfit,2).'</div>
        <div class="stat-lbl">Profit</div>
        <div class="stat-sub">'.number_format($margin,1).'% margin</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell" style="border-top:3px solid #dc2626;">
        <div class="stat-num" style="color:'.($refundCount>0?'#dc2626':'#6b7280').';">$'.number_format($totalRefunded,2).'</div>
        <div class="stat-lbl">Refunds</div>
        <div class="stat-sub">'.$refundCount.' order'.($refundCount===1?'':'s').'</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell" style="border-top:3px solid #d97706;">
        <div class="stat-num" style="color:'.($remakeCount>0?'#d97706':'#6b7280').';font-size:22px;">'.$remakeCount.'</div>
        <div class="stat-lbl">Remakes</div>
        <div class="stat-sub">drink'.($remakeCount===1?'':'s').' remade</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell" style="border-top:3px solid #16a34a;">
        <div class="stat-num" style="color:#16a34a;">$'.number_format($netRevenue,2).'</div>
        <div class="stat-lbl">Take Home</div>
        <div class="stat-sub">Sales minus refunds</div>
    </div>
</div>

<div class="section-title">Product Performance</div>
<table class="main">
    <thead>
        <tr>
            <th class="center" style="width:20px">#</th>
            <th>Product</th>
            <th class="center" style="width:34px">Qty</th>
            <th class="right" style="width:65px">Revenue</th>
            <th class="right" style="width:58px">Food Cost</th>
            <th class="right" style="width:60px">Profit</th>
            <th class="center" style="width:44px">Rev %</th>
            <th class="center" style="width:48px">Margin</th>
        </tr>
    </thead>
    <tbody>'.$prod_rows.'</tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="color:#6b7280;font-size:9px;">'.count($topProducts).' products &mdash; '.$totalItemsSold.' items total</td>
            <td style="text-align:right;color:#111827;">$'.number_format($totalSales,2).'</td>
            <td style="text-align:right;color:#d1904b;">$'.number_format($totalCOGS,2).'</td>
            <td style="text-align:right;color:#1e3a8a;">$'.number_format($totalProfit,2).'</td>
            <td style="text-align:center;color:#6b7280;">100%</td>
            <td style="text-align:center;font-weight:700;color:'.($margin>=50?'#166534':'#713f12').';">'.number_format($margin,1).'%</td>
        </tr>
    </tfoot>
</table>

<div class="section-title">Category Breakdown</div>
<table class="cat-table">
    <thead>
        <tr>
            <th>Category</th>
            <th class="center">Items Sold</th>
            <th class="right">Revenue</th>
            <th class="right">Food Cost</th>
            <th class="right">Gross Profit</th>
            <th class="center">Rev Share</th>
            <th class="center">Margin</th>
        </tr>
    </thead>
    <tbody>'.$cat_rows.'</tbody>
    <tfoot>
        <tr>
            <td style="color:#6b7280;">Total</td>
            <td style="text-align:center;font-weight:700;">'.$totalItemsSold.'</td>
            <td style="text-align:right;font-weight:700;">$'.number_format($totalSales,2).'</td>
            <td style="text-align:right;color:#d1904b;">$'.number_format($totalCOGS,2).'</td>
            <td style="text-align:right;color:#1e3a8a;font-weight:700;">$'.number_format($totalProfit,2).'</td>
            <td style="text-align:center;">100%</td>
            <td style="text-align:center;font-weight:700;color:'.($margin>=50?'#166534':'#713f12').';">'.number_format($margin,1).'%</td>
        </tr>
    </tfoot>
</table>

<?php if ($remakeCount > 0): ?>
<div class="section-title" style="color:#d97706;">Remade Orders</div>
<table class="main">
    <thead>
        <tr>
            <th style="width:40px;">Order #</th>
            <th style="width:80px;">Customer</th>
            <th>Drinks</th>
            <th>Reason</th>
            <th style="width:70px;">Logged By</th>
            <th style="width:55px;">Time</th>
        </tr>
    </thead>
    <tbody><?= $remakeRows ?></tbody>
</table>
<?php endif; ?>

<div class="sig-row">
    <div class="sig-cell">
        <div class="sig-line">Prepared by: POS System</div>
        <div class="sig-label">Auto-generated</div>
    </div>
    <div class="sig-cell">
        <div class="sig-line">&nbsp;</div>
        <div class="sig-label">Reviewed by / Date</div>
    </div>
    <div class="sig-cell">
        <div class="sig-line">&nbsp;</div>
        <div class="sig-label">Approved by / Date</div>
    </div>
</div>

<div class="footer">
    <div class="footer-left">CONFIDENTIAL &mdash; FOR INTERNAL USE ONLY &nbsp;|&nbsp; Bird\'s Nest Coffee &copy; '.date('Y').'</div>
    <div class="footer-right">'.he($reportId).' &nbsp;|&nbsp; Page 1 of 1</div>
</div>

</div>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('sales_report_'.date('Y-m-d').'.pdf', ['Attachment' => false]);
