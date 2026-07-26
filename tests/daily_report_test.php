<?php
/**
 * CLI assertions for the daily report's shared helpers.
 * Run:  php tests/daily_report_test.php
 * There is no test framework in this project; this script is the harness.
 */
require __DIR__ . '/../config.php';

$failures = 0;
function check(string $what, $got, $want): void {
    global $failures;
    $ok = is_float($want) ? abs($got - $want) < 0.0001 : $got === $want;
    if ($ok) { echo "  PASS  $what\n"; return; }
    $failures++;
    echo "  FAIL  $what\n        got:  " . var_export($got, true)
       . "\n        want: " . var_export($want, true) . "\n";
}

echo "ingredient_cost_map\n";
$map = ingredient_cost_map($conn);
// Milk base is ingredient 48, priced 1.30 per 1000ml on 2026-07-26.
check('costs are keyed by id',    $map[48]['unit_cost'],            0.0013);
check('costs are keyed by name',  $map['milk base']['unit_cost'],   0.0013);
check('sugar syrup by id',        $map[49]['unit_cost'],            0.0025);
check('name key carries a name',  $map['milk base']['name'],        'Milk base');

echo "business_date_today\n";
check('returns a Y-m-d string', (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', business_date_today()), true);

echo "order_cogs\n";
$ids = [];
$r = $conn->query("SELECT order_id FROM orders WHERE business_date='2026-07-21' AND " . paid_orders_where());
while ($row = $r->fetch_row()) { $ids[] = (int)$row[0]; }
$cogs = order_cogs($conn, $ids, $map);
check('empty order list costs nothing', order_cogs($conn, [], $map)['total'], 0.0);
check('a real day has items',           $cogs['items'] > 0,                   true);
check('a real day costs something',     $cogs['total'] > 0,                   true);
check('cost never exceeds takings',     $cogs['total'] < 40.09,               true);

echo ($failures === 0) ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
