<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header('Location: dashboard.php?denied=1'); exit; }
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

$rows = [];
$res  = mysqli_query($conn, "SELECT i.*, s.name AS supplier_name FROM ingredients i LEFT JOIN suppliers s ON s.supplier_id = i.supplier_id ORDER BY i.ingredient_name ASC");
while ($r = mysqli_fetch_assoc($res)) {
    $stock = (float)$r['stock_quantity'];
    $min   = (float)$r['minimum_stock'];
    $cpu   = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)($r['purchase_qty'] ?? 0) > 0)
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    $value = $stock * $cpu;
    if ($stock <= 0)       $status = 'out';
    elseif ($stock < $min) $status = 'low';
    else                   $status = 'ok';
    $rows[] = array_merge($r, ['stock'=>$stock,'min'=>$min,'cpu'=>$cpu,'value'=>$value,'status'=>$status]);
}

$total     = count($rows);
$cnt_ok    = count(array_filter($rows, fn($r) => $r['status']==='ok'));
$cnt_low   = count(array_filter($rows, fn($r) => $r['status']==='low'));
$cnt_out   = count(array_filter($rows, fn($r) => $r['status']==='out'));
$stk_val   = array_sum(array_column($rows, 'value'));
$pct_ok    = $total > 0 ? round($cnt_ok / $total * 100) : 0;
$generated = date('d M Y, g:i A');
$reportId  = 'INV-' . date('Ymd-Hi');

$reorder_items_out = array_values(array_filter($rows, fn($r) => $r['status'] === 'out'));
$reorder_items_low = array_values(array_filter($rows, fn($r) => $r['status'] === 'low'));
$needs_attention   = count($reorder_items_out) + count($reorder_items_low);

function fmt($n)   { return rtrim(rtrim(number_format((float)$n,3,'.',''),'0'),'.'); }
function money($n) { return '$'.number_format((float)$n,2); }
function he($s)    { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

// Procurement alert — B&W
$reorder_html = '';
if ($needs_attention > 0) {
    $reorder_html .= '<div style="margin-bottom:12px;border:1px dashed #888;border-radius:2px;padding:8px 12px;">';
    $reorder_html .= '<div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Procurement Required &mdash; '.$needs_attention.' item(s) need attention</div>';

    if (count($reorder_items_out) > 0) {
        $names = implode(', ', array_map(fn($oi) => he($oi['ingredient_name']), $reorder_items_out));
        $reorder_html .= '<div style="font-size:9px;margin-bottom:3px;"><strong>Out of Stock ('.count($reorder_items_out).'):</strong> '.$names.'</div>';
    }
    if (count($reorder_items_low) > 0) {
        $parts = array_map(fn($li) => he($li['ingredient_name']).' ('.fmt($li['stock']).'/'.fmt($li['min']).' '.he($li['unit']).')', $reorder_items_low);
        $reorder_html .= '<div style="font-size:9px;"><strong>Low Stock ('.count($reorder_items_low).'):</strong> '.implode(', ', $parts).'</div>';
    }

    $reorder_html .= '</div>';
}

// Table rows — B&W, no cost/value columns
$rows_html = '';
$i = 1;
foreach ($rows as $r) {
    $statusLabel = match($r['status']) { 'ok'=>'In Stock','low'=>'Low Stock','out'=>'Out of Stock' };
    $badgeStyle  = match($r['status']) {
        'ok'  => 'border:1px solid #bbb;color:#555;',
        'low' => 'border:1.5px solid #444;color:#111;font-weight:700;',
        'out' => 'border:2px solid #111;color:#111;font-weight:700;background:#ebebeb;',
    };
    $stockStyle  = match($r['status']) {
        'ok'  => 'color:#111;',
        'low' => 'color:#111;font-weight:700;',
        'out' => 'color:#111;font-weight:700;',
    };
    $rowBg = ($i % 2 === 0) ? '#f7f7f7' : '#ffffff';
    $unit     = $r['unit']         ? he($r['unit'])         : '&mdash;';
    $supplier = $r['supplier_name'] ? he($r['supplier_name']) : '&mdash;';
    $rows_html .= '
    <tr style="background:'.$rowBg.';">
        <td style="color:#aaa;font-size:9px;text-align:center;">'.$i.'</td>
        <td><strong style="color:#111;font-size:11px;">'.he($r['ingredient_name']).'</strong></td>
        <td style="text-align:center;color:#555;font-size:10px;">'.$unit.'</td>
        <td style="text-align:right;'.$stockStyle.'">'.fmt($r['stock']).'</td>
        <td style="text-align:right;color:#777;">'.fmt($r['min']).'</td>
        <td style="text-align:center;">
            <span style="'.$badgeStyle.'padding:2px 7px;border-radius:2px;font-size:8.5px;letter-spacing:.3px;">'.$statusLabel.'</span>
        </td>
        <td style="font-size:10px;color:#555;">'.$supplier.'</td>
    </tr>';
    $i++;
}

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 landscape; margin: 0; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111; margin: 0; padding: 0; }
.page-body { padding: 12mm 14mm 14mm; }

.header { display: table; width: 100%; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 12px; }
.header-left  { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; }

.brand-name    { font-size: 20px; font-weight: 700; color: #111; text-transform: uppercase; letter-spacing: 1px; }
.brand-tagline { font-size: 9px; color: #666; letter-spacing: 1px; text-transform: uppercase; margin-top: 2px; }
.brand-contact { font-size: 8.5px; color: #888; margin-top: 3px; }

.report-title { font-size: 17px; font-weight: 700; color: #111; }
.report-id    { font-size: 9px; color: #444; font-weight: 600; margin-top: 3px; letter-spacing: .4px; }
.report-meta  { font-size: 9px; color: #777; margin-top: 2px; }

.summary-bar  { background: #f5f5f5; border: 1px solid #ddd; padding: 7px 12px; margin-bottom: 12px; font-size: 10px; color: #333; border-radius: 2px; }

table.main { width: 100%; border-collapse: collapse; font-size: 10.5px; }
table.main thead tr { background: #1a1a1a; }
table.main thead th { color: #fff; font-weight: 700; padding: 8px 10px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; }
table.main thead th.right  { text-align: right; }
table.main thead th.center { text-align: center; }
table.main tbody td { padding: 7px 10px; border-bottom: 1px solid #eeeeee; vertical-align: middle; }
table.main tfoot td { padding: 8px 10px; border-top: 2px solid #111; font-size: 10px; background: #f5f5f5; color: #333; }

.sig-row  { display: table; width: 100%; margin-top: 18px; border-top: 1px solid #ccc; padding-top: 12px; }
.sig-cell { display: table-cell; text-align: center; }
.sig-line { border-top: 1px solid #888; width: 140px; margin: 0 auto; padding-top: 4px; font-size: 9px; color: #555; }
.sig-label { font-size: 8px; color: #888; margin-top: 2px; text-transform: uppercase; letter-spacing: .4px; }

.footer       { margin-top: 12px; border-top: 1px solid #ddd; padding-top: 6px; display: table; width: 100%; }
.footer-left  { display: table-cell; font-size: 8px; color: #888; vertical-align: middle; }
.footer-right { display: table-cell; text-align: right; font-size: 8px; color: #888; vertical-align: middle; }
</style>
</head>
<body>
<div class="page-body">

<div class="header">
    <div class="header-left">
        <div class="brand-name">Bird\'s Nest Coffee</div>
        <div class="brand-tagline">Specialty Coffee &amp; Beverages</div>
        <div class="brand-contact">2nd Floor, Chbar Ampov District, Phnom Penh, Cambodia &nbsp;|&nbsp; operations@birdsnestcoffee.com</div>
    </div>
    <div class="header-right">
        <div class="report-title">Ingredient Stock Report</div>
        <div class="report-id">Report No: '.$reportId.'</div>
        <div class="report-meta">Generated: '.$generated.' &nbsp;|&nbsp; Phnom Penh Time</div>
    </div>
</div>

<div class="summary-bar">
    <strong>Summary:</strong> &nbsp;&nbsp;
    Total Items: <strong>'.$total.'</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    In Stock: <strong>'.$cnt_ok.'</strong> ('.$pct_ok.'%)
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Low Stock: <strong>'.$cnt_low.'</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Out of Stock: <strong>'.$cnt_out.'</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Inventory Value: <strong>'.money($stk_val).'</strong>
</div>

'.$reorder_html.'

<table class="main">
    <thead>
        <tr>
            <th class="center" style="width:26px">#</th>
            <th>Ingredient</th>
            <th class="center" style="width:42px">Unit</th>
            <th class="right" style="width:72px">Stock Qty</th>
            <th class="right" style="width:65px">Min. Qty</th>
            <th class="center" style="width:90px">Status</th>
            <th>Supplier</th>
        </tr>
    </thead>
    <tbody>
        '.$rows_html.'
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4">'.$total.' ingredients &mdash; '.$cnt_ok.' in stock &nbsp;/&nbsp; '.$cnt_low.' low &nbsp;/&nbsp; '.$cnt_out.' out of stock</td>
            <td colspan="3" style="text-align:right;font-weight:700;">Total Inventory Value: '.money($stk_val).'</td>
        </tr>
    </tfoot>
</table>

<div class="sig-row">
    <div class="sig-cell">
        <div class="sig-line">Prepared by: Inventory System</div>
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('ingredients_stock_'.date('Y-m-d').'.pdf', ['Attachment' => false]);
