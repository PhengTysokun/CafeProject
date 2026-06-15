<?php
require 'auth.php';
require 'config.php';
if (!can('report')) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

header('Content-Type: application/json');
date_default_timezone_set('Asia/Phnom_Penh');

function getBusinessDateToday(): string {
    $now = new DateTime();
    if ((int)$now->format("H") < 6) $now->modify("-1 day");
    return $now->format("Y-m-d");
}

$mode = $_GET['mode'] ?? 'daily';
if (!in_array($mode, ['daily','monthly','range'])) $mode = 'daily';

if ($mode === 'monthly') {
    $month = $_GET['month'] ?? (new DateTime())->format("Y-m");
    $start = new DateTime($month . "-01 06:00:00");
    $end   = clone $start;
    $end->modify("+1 month")->modify("-1 second");
} elseif ($mode === 'range') {
    $fromDate = $_GET['from_date'] ?? getBusinessDateToday();
    $toDate   = $_GET['to_date']   ?? getBusinessDateToday();
    $start = new DateTime($fromDate . " 06:00:00");
    $end   = new DateTime($toDate   . " 06:00:00");
    $end->modify("+1 day")->modify("-1 second");
} else {
    $date  = $_GET['date'] ?? getBusinessDateToday();
    $start = new DateTime($date . " 06:00:00");
    $end   = clone $start;
    $end->modify("+1 day")->modify("-1 second");
}

$startStr = $start->format("Y-m-d H:i:s");
$endStr   = $end->format("Y-m-d H:i:s");

// ── Ingredient cost map ──
$ingredients = [];
$qIng = mysqli_query($conn, "SELECT ingredient_id, ingredient_name, cost_price, purchase_qty, cost_per_unit FROM ingredients");
while ($r = mysqli_fetch_assoc($qIng)) {
    $cpu = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)$r['purchase_qty'] > 0)
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    $iid = (int)$r['ingredient_id'];
    $ingredients[$iid] = ['name' => $r['ingredient_name'], 'unit_cost' => $cpu];
    $ingredients[strtolower(trim($r['ingredient_name']))] = ['id'=>$iid,'name'=>$r['ingredient_name'],'unit_cost'=>$cpu];
}

// ── Orders ──
$orderIds = []; $totalSales = 0; $orderCount = 0;
$qOrders = mysqli_query($conn, "SELECT order_id, total FROM orders WHERE status='Completed' AND order_date BETWEEN '$startStr' AND '$endStr'");
while ($o = mysqli_fetch_assoc($qOrders)) {
    $orderIds[] = (int)$o['order_id'];
    $totalSales += (float)$o['total'];
    $orderCount++;
}
$avgOrder = $orderCount > 0 ? $totalSales / $orderCount : 0;

$totalCOGS = 0; $totalItemsSold = 0;
$topProducts = []; $categorySales = [];

if (count($orderIds) > 0) {
    $inOrder    = implode(",", $orderIds);
    $productIds = []; $items = [];

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

    uasort($topProducts,   fn($a,$b) => $b['qty'] <=> $a['qty']);
    uasort($categorySales, fn($a,$b) => $b['qty'] <=> $a['qty']);
}

$totalProfit = $totalSales - $totalCOGS;
$margin      = $totalSales > 0 ? ($totalProfit / $totalSales * 100) : 0;

// ── Refunds ──
$totalRefunded = 0; $refundCount = 0;
$qRef = mysqli_query($conn, "SELECT COALESCE(SUM(refund_amount),0) as total, COUNT(*) as cnt FROM order_refunds WHERE refunded_at BETWEEN '$startStr' AND '$endStr'");
if ($rf = mysqli_fetch_assoc($qRef)) { $totalRefunded = (float)$rf['total']; $refundCount = (int)$rf['cnt']; }
$netRevenue = $totalSales - $totalRefunded;

// ── Peak hour + hourly data (daily only) ──
$peakHour   = null;
$hourlyData = [];
if ($mode === 'daily') {
    $qH = mysqli_query($conn, "SELECT HOUR(order_date) as h, COUNT(*) as cnt, SUM(total) as rev FROM orders WHERE status='Completed' AND order_date BETWEEN '$startStr' AND '$endStr' GROUP BY HOUR(order_date) ORDER BY h ASC");
    $hourMap = [];
    while ($r = mysqli_fetch_assoc($qH)) $hourMap[(int)$r['h']] = ['count'=>(int)$r['cnt'],'revenue'=>(float)$r['rev']];
    $maxRev = 0;
    for ($h = 6; $h <= 22; $h++) {
        $rev = $hourMap[$h]['revenue'] ?? 0;
        $hourlyData[] = ['label'=>sprintf('%02d:00',$h),'count'=>$hourMap[$h]['count']??0,'revenue'=>$rev];
        if ($rev > $maxRev) { $maxRev = $rev; $peakHour = date('g:i A', mktime($h,0,0)); }
    }
}

$topProdName = array_key_first($topProducts) ?? null;
$topCatName  = array_key_first($categorySales) ?? null;
$refundRate  = ($orderCount > 0 && $refundCount > 0) ? round($refundCount / $orderCount * 100, 1) : 0;

echo json_encode([
    'orderCount'     => $orderCount,
    'totalItemsSold' => $totalItemsSold,
    'avgOrder'       => round($avgOrder, 2),
    'totalSales'     => round($totalSales, 2),
    'totalCOGS'      => round($totalCOGS, 2),
    'totalProfit'    => round($totalProfit, 2),
    'margin'         => round($margin, 1),
    'totalRefunded'  => round($totalRefunded, 2),
    'refundCount'    => $refundCount,
    'netRevenue'     => round($netRevenue, 2),
    'peakHour'       => $peakHour,
    'topProduct'     => $topProdName,
    'topProductQty'  => $topProdName ? (int)$topProducts[$topProdName]['qty'] : 0,
    'topCategory'    => $topCatName,
    'topCategoryQty' => $topCatName ? (int)$categorySales[$topCatName]['qty'] : 0,
    'refundRate'     => $refundRate,
    'hourlyData'     => $hourlyData,
    'ts'             => date('g:i A'),
]);
