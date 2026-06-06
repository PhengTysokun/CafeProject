<?php
require 'admin_only.php';
require 'config.php';
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Load ingredient costs
$ingredientMeta = [];
$qIng = mysqli_query($conn, "SELECT ingredient_id, ingredient_name, cost_price, purchase_qty, cost_per_unit, unit FROM ingredients");
while ($r = mysqli_fetch_assoc($qIng)) {
    $iid = (int)$r['ingredient_id'];
    $cpu = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)$r['purchase_qty'] > 0)
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    $ingredientMeta[$iid] = ['name' => $r['ingredient_name'], 'cpu' => $cpu, 'unit' => $r['unit']];
}

// Load recipes
$res = mysqli_query($conn, "
    SELECT p.product_id, p.name AS product_name, p.category, p.price,
           pi.ingredient_id, i.ingredient_name, i.unit, pi.amount_used
    FROM products p
    JOIN product_ingredients pi ON p.product_id = pi.product_id
    JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
    WHERE pi.amount_used > 0
    ORDER BY p.category ASC, p.name ASC, i.ingredient_name ASC
");

$recipes = [];
while ($row = mysqli_fetch_assoc($res)) {
    $pid = (int)$row['product_id'];
    if (!isset($recipes[$pid])) {
        $recipes[$pid] = [
            'product_name' => $row['product_name'],
            'category'     => $row['category'],
            'price'        => (float)$row['price'],
            'items'        => [],
            'cogs'         => 0.0,
        ];
    }
    $iid    = (int)$row['ingredient_id'];
    $amount = (float)$row['amount_used'];
    $cpu    = $ingredientMeta[$iid]['cpu'] ?? 0;
    $recipes[$pid]['items'][] = [
        'name'   => $row['ingredient_name'],
        'unit'   => $row['unit'],
        'amount' => $amount,
        'cost'   => $amount * $cpu,
    ];
    $recipes[$pid]['cogs'] += $amount * $cpu;
}

$totalRecipes = count($recipes);
$totalCogs    = array_sum(array_column($recipes, 'cogs'));
$avgCogs      = $totalRecipes > 0 ? $totalCogs / $totalRecipes : 0;
$margins      = array_filter(array_map(fn($r) => $r['price'] > 0 ? (($r['price'] - $r['cogs']) / $r['price'] * 100) : null, $recipes));
$avgMargin    = count($margins) > 0 ? array_sum($margins) / count($margins) : 0;
$generated    = date('d M Y, g:i A');
$reportId     = 'RCP-' . date('Ymd-Hi');

// Best / worst margin
$bestMarginProd = null; $bestMarginVal = -1;
$worstMarginProd = null; $worstMarginVal = 999;
foreach ($recipes as $r) {
    if ($r['price'] <= 0) continue;
    $m = ($r['price'] - $r['cogs']) / $r['price'] * 100;
    if ($m > $bestMarginVal)  { $bestMarginVal  = $m; $bestMarginProd  = $r['product_name']; }
    if ($m < $worstMarginVal) { $worstMarginVal = $m; $worstMarginProd = $r['product_name']; }
}

// Category stats
$catStats = [];
foreach ($recipes as $r) {
    $cat = $r['category'] ?: 'Other';
    if (!isset($catStats[$cat])) $catStats[$cat] = ['count'=>0,'cogs'=>0,'revenue'=>0];
    $catStats[$cat]['count']++;
    $catStats[$cat]['cogs']    += $r['cogs'];
    $catStats[$cat]['revenue'] += $r['price'];
}

$exec_summary = "This report covers {$totalRecipes} menu recipes across " . count($catStats) . " categories. " .
    "The average cost of goods per drink is \$" . number_format($avgCogs, 3) . " against an average selling price, " .
    "yielding an average gross margin of " . number_format($avgMargin, 1) . "%. " .
    ($bestMarginProd ? "Highest-margin item: {$bestMarginProd} (" . number_format($bestMarginVal, 1) . "%). " : '') .
    ($worstMarginProd && $worstMarginProd !== $bestMarginProd ? "Lowest-margin item: {$worstMarginProd} (" . number_format($worstMarginVal, 1) . "%). " : '') .
    "Review low-margin items to evaluate pricing or ingredient cost optimisation.";

$catColors = [
    'Iced'     => '#2563eb',
    'Hot'      => '#dc2626',
    'Frappe'   => '#7c3aed',
    'Juice'    => '#16a34a',
    'Milk Tea' => '#d1904b',
];

// Build rows grouped by category
$rows_html  = '';
$currentCat = null;
$i = 1;
foreach ($recipes as $r) {
    $cat = $r['category'] ?: 'Other';
    if ($cat !== $currentCat) {
        $catColor  = $catColors[$cat] ?? '#6b7280';
        $catCount  = $catStats[$cat]['count'];
        $catAvgM   = $catStats[$cat]['revenue'] > 0 ? round(($catStats[$cat]['revenue'] - $catStats[$cat]['cogs']) / $catStats[$cat]['revenue'] * 100, 1) : 0;
        $rows_html .= '
        <tr>
            <td colspan="7" style="background:#f3f4f6;padding:5px 10px;font-size:9px;font-weight:700;color:'.$catColor.';letter-spacing:.8px;text-transform:uppercase;border-bottom:2px solid '.$catColor.';border-top:1px solid #e5e7eb;">
                '.$cat.' &nbsp;<span style="font-weight:400;color:#9ca3af;letter-spacing:0;text-transform:none;">'.$catCount.' item(s) &nbsp;&mdash;&nbsp; avg margin '.$catAvgM.'%</span>
            </td>
        </tr>';
        $currentCat = $cat;
    }
    $margin      = $r['price'] > 0 ? (($r['price'] - $r['cogs']) / $r['price'] * 100) : 0;
    $profit      = $r['price'] - $r['cogs'];
    $rowBg       = ($i % 2 === 0) ? '#f9f6f2' : '#ffffff';
    $marginColor = $margin >= 60 ? '#166534' : ($margin >= 40 ? '#713f12' : '#991b1b');
    $marginBg    = $margin >= 60 ? '#dcfce7'  : ($margin >= 40 ? '#fef9c3'  : '#fee2e2');

    $ing_lines = '';
    foreach ($r['items'] as $it) {
        $ing_lines .= '<span style="display:inline-block;background:#f3f4f6;border-radius:3px;padding:1px 5px;margin:1px 2px 1px 0;font-size:7.5px;color:#6b7280;">'.he($it['name']).' '.rtrim(rtrim(number_format($it['amount'],3,'.','' ),'0'),'.').' '.he($it['unit']).'</span>';
    }

    $rows_html .= '
    <tr style="background:'.$rowBg.';">
        <td style="color:#9ca3af;font-size:9px;text-align:center;">'.$i.'</td>
        <td>
            <strong style="color:#111827;font-size:11px;">'.he($r['product_name']).'</strong>
            <div style="margin-top:3px;">'.$ing_lines.'</div>
        </td>
        <td style="text-align:center;color:#6b7280;font-weight:600;">'.count($r['items']).'</td>
        <td style="text-align:right;font-weight:700;color:#111827;">$'.number_format($r['price'],2).'</td>
        <td style="text-align:right;color:#d1904b;font-weight:700;">$'.number_format($r['cogs'],3).'</td>
        <td style="text-align:right;font-weight:700;color:#1e3a8a;">$'.number_format($profit,2).'</td>
        <td style="text-align:center;">
            <span style="background:'.$marginBg.';color:'.$marginColor.';padding:2px 8px;border-radius:10px;font-size:8.5px;font-weight:700;">'.number_format($margin,1).'%</span>
        </td>
    </tr>';
    $i++;
}

// Category summary rows
$cat_summary = '';
foreach ($catStats as $cat => $cs) {
    $catColor = $catColors[$cat] ?? '#6b7280';
    $cAvgM    = $cs['revenue'] > 0 ? round(($cs['revenue'] - $cs['cogs']) / $cs['revenue'] * 100, 1) : 0;
    $cat_summary .= '
    <tr>
        <td style="font-weight:700;color:'.$catColor.';">'.he($cat).'</td>
        <td style="text-align:center;color:#374151;">'.$cs['count'].'</td>
        <td style="text-align:right;color:#111827;font-weight:600;">$'.number_format($cs['revenue']/$cs['count'],2).' avg</td>
        <td style="text-align:right;color:#d1904b;font-weight:600;">$'.number_format($cs['cogs']/$cs['count'],3).' avg</td>
        <td style="text-align:center;font-weight:700;color:'.($cAvgM>=60?'#166534':($cAvgM>=40?'#713f12':'#991b1b')).';">'.$cAvgM.'%</td>
    </tr>';
}

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

.report-badge { display: inline-block; background: #f0fdf4; color: #166534; font-size: 8px; font-weight: 700; padding: 2px 8px; border-radius: 10px; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 4px; }
.conf-badge   { display: inline-block; background: #fee2e2; color: #991b1b; font-size: 8px; font-weight: 700; padding: 2px 8px; border-radius: 10px; letter-spacing: .5px; text-transform: uppercase; margin-left: 4px; }
.report-title { font-size: 17px; font-weight: 700; color: #d1904b; margin-top: 2px; }
.report-meta  { font-size: 9px; color: #9ca3af; margin-top: 3px; }
.report-id    { font-size: 9px; color: #6b7280; font-weight: 600; margin-top: 2px; }

.exec-box { background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid #d1904b; border-radius: 4px; padding: 9px 13px; margin-bottom: 12px; font-size: 10px; color: #374151; line-height: 1.6; }
.exec-label { font-size: 8.5px; font-weight: 700; color: #d1904b; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 4px; }

.stats { display: table; width: 100%; margin-bottom: 12px; }
.stat-cell { display: table-cell; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 9px 10px; text-align: center; }
.stat-cell.orange { border-top: 3px solid #d1904b; }
.stat-cell.blue   { border-top: 3px solid #2563eb; }
.stat-cell.green  { border-top: 3px solid #16a34a; }
.stat-gap { display: table-cell; width: 8px; }
.stat-num { font-size: 20px; font-weight: 700; color: #111827; line-height: 1; }
.stat-lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: .6px; margin-top: 4px; }
.stat-sub { font-size: 8px; color: #9ca3af; margin-top: 2px; }

.section-title { font-size: 11px; font-weight: 700; color: #111827; border-left: 3px solid #d1904b; padding-left: 8px; margin: 12px 0 7px; }

table.main { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 12px; }
table.main thead tr { background: #d1904b; }
table.main thead th { color: #fff; font-weight: 700; padding: 7px 8px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; }
table.main thead th.right  { text-align: right; }
table.main thead th.center { text-align: center; }
table.main tbody td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
table.main tfoot td { padding: 7px 8px; border-top: 2px solid #d1904b; font-weight: 700; font-size: 10.5px; background: #fffbf5; }

table.cat-table { width: 100%; border-collapse: collapse; font-size: 10px; }
table.cat-table thead tr { background: #374151; }
table.cat-table thead th { color: #fff; padding: 6px 8px; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; }
table.cat-table thead th.right  { text-align: right; }
table.cat-table thead th.center { text-align: center; }
table.cat-table tbody td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }

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
            <span class="report-badge">Menu &amp; Cost</span><span class="conf-badge">Confidential</span>
        </div>
        <div class="report-title">Recipe Cost Report</div>
        <div class="report-id">Report No: '.$reportId.'</div>
        <div class="report-meta">Generated: '.$generated.' &nbsp;|&nbsp; Phnom Penh Time</div>
    </div>
</div>

<div class="exec-box">
    <div class="exec-label">Executive Summary</div>
    '.he($exec_summary).'
</div>

<div class="stats">
    <div class="stat-cell orange">
        <div class="stat-num">'.$totalRecipes.'</div>
        <div class="stat-lbl">Total Recipes</div>
        <div class="stat-sub">'.count($catStats).' categories</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell blue">
        <div class="stat-num">$'.number_format($avgCogs,3).'</div>
        <div class="stat-lbl">Avg COGS / Drink</div>
        <div class="stat-sub">Cost per unit sold</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell green">
        <div class="stat-num">'.number_format($avgMargin,1).'%</div>
        <div class="stat-lbl">Avg Gross Margin</div>
        <div class="stat-sub">Across all recipes</div>
    </div>
</div>

<div class="section-title">Recipe Detail by Category</div>
<table class="main">
    <thead>
        <tr>
            <th class="center" style="width:20px">#</th>
            <th>Product &amp; Ingredients</th>
            <th class="center" style="width:32px">Ings</th>
            <th class="right" style="width:50px">Price</th>
            <th class="right" style="width:58px">COGS</th>
            <th class="right" style="width:58px">Gross Profit</th>
            <th class="center" style="width:50px">Margin</th>
        </tr>
    </thead>
    <tbody>
        '.$rows_html.'
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="color:#6b7280;font-size:9px;">'.$totalRecipes.' recipes &nbsp;|&nbsp; avg COGS $'.number_format($avgCogs,3).'</td>
            <td style="text-align:right;color:#d1904b;">$'.number_format($avgCogs,3).' avg</td>
            <td></td>
            <td style="text-align:center;font-weight:700;color:'.($avgMargin>=60?'#166534':($avgMargin>=40?'#713f12':'#991b1b')).';">'.number_format($avgMargin,1).'%</td>
        </tr>
    </tfoot>
</table>

<div class="section-title">Category Summary</div>
<table class="cat-table">
    <thead>
        <tr>
            <th>Category</th>
            <th class="center">Items</th>
            <th class="right">Avg Selling Price</th>
            <th class="right">Avg COGS</th>
            <th class="center">Avg Margin</th>
        </tr>
    </thead>
    <tbody>
        '.$cat_summary.'
    </tbody>
</table>

<div class="sig-row">
    <div class="sig-cell">
        <div class="sig-line">Prepared by: Menu System</div>
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
$dompdf->stream('recipes_cost_'.date('Y-m-d').'.pdf', ['Attachment' => false]);
