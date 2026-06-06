<?php
require 'admin_only.php';
require 'config.php';
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

// Executive summary
if ($needs_attention === 0) {
    $exec_summary = "All {$total} ingredients are currently at or above minimum stock thresholds. Total inventory is valued at " . money($stk_val) . ". No immediate procurement action is required.";
} else {
    $exec_summary = "Out of {$total} tracked ingredients, {$cnt_ok} ({$pct_ok}%) are at healthy stock levels. " .
        ($cnt_out > 0 ? "{$cnt_out} item(s) are completely out of stock and require immediate reorder. " : '') .
        ($cnt_low > 0 ? "{$cnt_low} item(s) are running below minimum threshold. " : '') .
        "Total inventory is valued at " . money($stk_val) . ". Procurement action is recommended.";
}

// Reorder alert HTML
$reorder_html = '';
if ($needs_attention > 0) {
    $reorder_html .= '<div style="margin-bottom:14px;border:1px solid #fca5a5;background:#fff8f8;border-left:4px solid #dc2626;border-radius:4px;padding:10px 14px;">';
    $reorder_html .= '<div style="font-size:10.5px;font-weight:700;color:#dc2626;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">&#9888; Procurement Alert &mdash; ' . $needs_attention . ' item(s) require action</div>';
    $reorder_html .= '<div style="display:table;width:100%;">';

    if (count($reorder_items_out) > 0) {
        $reorder_html .= '<div style="display:table-cell;width:50%;vertical-align:top;padding-right:10px;">';
        $reorder_html .= '<div style="font-size:9px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Out of Stock (' . count($reorder_items_out) . ')</div>';
        foreach ($reorder_items_out as $oi) {
            $reorder_html .= '<div style="font-size:9.5px;color:#7f1d1d;padding:1px 0;">&#8226; ' . he($oi['ingredient_name']) . ' <span style="color:#999;">(' . he($oi['unit']) . ')</span></div>';
        }
        $reorder_html .= '</div>';
    }

    if (count($reorder_items_low) > 0) {
        $reorder_html .= '<div style="display:table-cell;width:50%;vertical-align:top;">';
        $reorder_html .= '<div style="font-size:9px;font-weight:700;color:#713f12;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Low Stock (' . count($reorder_items_low) . ')</div>';
        foreach ($reorder_items_low as $li) {
            $reorder_html .= '<div style="font-size:9.5px;color:#451a03;padding:1px 0;">&#8226; ' . he($li['ingredient_name']) . ' <span style="color:#999;">(' . fmt($li['stock']) . '/' . fmt($li['min']) . ' ' . he($li['unit']) . ')</span></div>';
        }
        $reorder_html .= '</div>';
    }

    $reorder_html .= '</div></div>';
}

// Table rows
$rows_html = '';
$i = 1;
foreach ($rows as $r) {
    $statusLabel = match($r['status']) { 'ok'=>'IN STOCK','low'=>'LOW STOCK','out'=>'OUT OF STOCK' };
    $statusColor = match($r['status']) { 'ok'=>'#166534','low'=>'#713f12','out'=>'#991b1b' };
    $statusBg    = match($r['status']) { 'ok'=>'#dcfce7','low'=>'#fef9c3','out'=>'#fee2e2' };
    $rowBg       = ($i % 2 === 0) ? '#f9f6f2' : '#ffffff';
    $stockColor  = match($r['status']) { 'ok'=>'#111827','low'=>'#713f12','out'=>'#991b1b' };
    $rows_html  .= '
    <tr style="background:'.$rowBg.';">
        <td style="color:#9ca3af;font-size:9px;text-align:center;">'.$i.'</td>
        <td><strong style="color:#111827;font-size:11px;">'.he($r['ingredient_name']).'</strong></td>
        <td style="text-align:center;color:#6b7280;font-size:10px;">'.he($r['unit'] ?: '—').'</td>
        <td style="text-align:right;font-weight:700;color:'.$stockColor.';">'.fmt($r['stock']).'</td>
        <td style="text-align:right;color:#9ca3af;">'.fmt($r['min']).'</td>
        <td style="text-align:right;color:#d1904b;font-weight:700;">'.money($r['cpu']).'</td>
        <td style="text-align:right;font-weight:700;color:#1e3a8a;">'.money($r['value']).'</td>
        <td style="text-align:center;">
            <span style="background:'.$statusBg.';color:'.$statusColor.';padding:2px 8px;border-radius:10px;font-size:8px;font-weight:700;letter-spacing:.4px;">'.$statusLabel.'</span>
        </td>
        <td style="font-size:10px;color:#6b7280;">'.he($r['supplier_name'] ?: '—').'</td>
    </tr>';
    $i++;
}

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 landscape; margin: 0; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111827; margin: 0; padding: 0; }

.top-stripe { background: #d1904b; height: 5px; width: 100%; }
.page-body  { padding: 12mm 14mm 14mm; }

.header { display: table; width: 100%; border-bottom: 2px solid #e5e7eb; padding-bottom: 11px; margin-bottom: 14px; }
.header-left  { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; }

.brand-name { font-size: 21px; font-weight: 700; color: #111827; letter-spacing: .5px; text-transform: uppercase; }
.brand-tagline { font-size: 9px; color: #6b7280; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
.brand-contact { font-size: 9px; color: #9ca3af; margin-top: 4px; }

.report-badge { display: inline-block; background: #fef3e2; color: #92400e; font-size: 8px; font-weight: 700; padding: 2px 8px; border-radius: 10px; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 4px; }
.conf-badge   { display: inline-block; background: #fee2e2; color: #991b1b; font-size: 8px; font-weight: 700; padding: 2px 8px; border-radius: 10px; letter-spacing: .5px; text-transform: uppercase; margin-left: 4px; }
.report-title { font-size: 18px; font-weight: 700; color: #d1904b; margin-top: 2px; }
.report-meta  { font-size: 9px; color: #9ca3af; margin-top: 3px; }
.report-id    { font-size: 9px; color: #6b7280; font-weight: 600; margin-top: 2px; letter-spacing: .5px; }

.exec-box { background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid #d1904b; border-radius: 4px; padding: 9px 14px; margin-bottom: 14px; font-size: 10px; color: #374151; line-height: 1.6; }
.exec-label { font-size: 8.5px; font-weight: 700; color: #d1904b; text-transform: uppercase; letter-spacing: .7px; margin-bottom: 4px; }

.stats { display: table; width: 100%; margin-bottom: 14px; }
.stat-cell { display: table-cell; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; text-align: center; }
.stat-cell.orange { border-top: 3px solid #d1904b; }
.stat-cell.green  { border-top: 3px solid #16a34a; }
.stat-cell.yellow { border-top: 3px solid #ca8a04; }
.stat-cell.red    { border-top: 3px solid #dc2626; }
.stat-cell.blue   { border-top: 3px solid #2563eb; }
.stat-gap { display: table-cell; width: 8px; }
.stat-num  { font-size: 22px; font-weight: 700; color: #111827; line-height: 1; }
.stat-lbl  { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: .6px; margin-top: 4px; }
.stat-sub  { font-size: 8px; color: #9ca3af; margin-top: 2px; }

table.main { width: 100%; border-collapse: collapse; font-size: 10.5px; }
table.main thead tr { background: #d1904b; }
table.main thead th { color: #fff; font-weight: 700; padding: 8px 10px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .7px; white-space: nowrap; }
table.main thead th.right  { text-align: right; }
table.main thead th.center { text-align: center; }
table.main tbody td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
table.main tfoot td { padding: 8px 10px; border-top: 2px solid #d1904b; font-weight: 700; font-size: 11px; background: #fffbf5; }

.sig-row { display: table; width: 100%; margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 12px; }
.sig-cell { display: table-cell; text-align: center; }
.sig-line { border-top: 1px solid #9ca3af; width: 140px; margin: 0 auto; padding-top: 4px; font-size: 9px; color: #6b7280; }
.sig-label { font-size: 8px; color: #9ca3af; margin-top: 2px; text-transform: uppercase; letter-spacing: .5px; }

.footer { margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 7px; display: table; width: 100%; }
.footer-left  { display: table-cell; font-size: 8.5px; color: #9ca3af; vertical-align: middle; }
.footer-right { display: table-cell; text-align: right; font-size: 8.5px; color: #9ca3af; vertical-align: middle; }
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
            <span class="report-badge">Inventory</span><span class="conf-badge">Confidential</span>
        </div>
        <div class="report-title">Ingredient Stock Report</div>
        <div class="report-id">Report No: '.$reportId.'</div>
        <div class="report-meta">Generated: '.$generated.' &nbsp;|&nbsp; Phnom Penh Time</div>
    </div>
</div>

<div class="exec-box">
    <div class="exec-label">Executive Summary</div>
    '.he($exec_summary).'
</div>

'.$reorder_html.'

<div class="stats">
    <div class="stat-cell orange">
        <div class="stat-num">'.$total.'</div>
        <div class="stat-lbl">Total Ingredients</div>
        <div class="stat-sub">All tracked items</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell green">
        <div class="stat-num">'.$cnt_ok.'</div>
        <div class="stat-lbl">In Stock</div>
        <div class="stat-sub">'.$pct_ok.'% healthy</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell yellow">
        <div class="stat-num">'.$cnt_low.'</div>
        <div class="stat-lbl">Low Stock</div>
        <div class="stat-sub">Below minimum</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell red">
        <div class="stat-num">'.$cnt_out.'</div>
        <div class="stat-lbl">Out of Stock</div>
        <div class="stat-sub">Immediate reorder</div>
    </div>
    <div class="stat-gap"></div>
    <div class="stat-cell blue">
        <div class="stat-num">'.money($stk_val).'</div>
        <div class="stat-lbl">Total Stock Value</div>
        <div class="stat-sub">At current cost/unit</div>
    </div>
</div>

<table class="main">
    <thead>
        <tr>
            <th class="center" style="width:26px">#</th>
            <th>Ingredient Name</th>
            <th class="center" style="width:42px">Unit</th>
            <th class="right" style="width:70px">Stock Qty</th>
            <th class="right" style="width:62px">Min. Qty</th>
            <th class="right" style="width:72px">Unit Cost</th>
            <th class="right" style="width:78px">Stock Value</th>
            <th class="center" style="width:88px">Status</th>
            <th style="width:120px">Supplier</th>
        </tr>
    </thead>
    <tbody>
        '.$rows_html.'
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" style="color:#6b7280;font-size:10px;">'.$total.' ingredients tracked &nbsp;&mdash;&nbsp; '.$cnt_ok.' healthy &nbsp;/ &nbsp;'.$cnt_low.' low &nbsp;/ &nbsp;'.$cnt_out.' out</td>
            <td style="text-align:right;color:#1e3a8a;">'.money($stk_val).'</td>
            <td colspan="2"></td>
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
