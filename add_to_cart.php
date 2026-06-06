<?php
session_start();
require 'config.php';

header('Content-Type: application/json; charset=UTF-8');

function json_out(bool $success, string $message, ?int $cart_count = null, ?float $cart_total = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode([
        'success'    => $success,
        'message'    => $message,
        'cart_count' => $cart_count,
        'cart_total' => $cart_total,
    ]);
    exit;
}

// CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    json_out(false, 'Invalid request token', 0, null, 403);
}

$product_id = (int)($_POST['id'] ?? 0);
if ($product_id <= 0) {
    json_out(false, 'Missing product ID', 0, null, 400);
}

$qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));

$sweetness = trim((string)($_POST['sweetness'] ?? ''));
$ice       = trim((string)($_POST['ice']       ?? ''));
$milk      = trim((string)($_POST['milk']      ?? ''));

// Validate options against allowed values
$valid_sweetness = ['0%', '25%', '50%', '75%', '100%', ''];
$valid_ice       = ['No Ice', 'Less Ice', 'Normal Ice', 'More Ice', ''];
$valid_milk      = ['Fresh Milk', 'Almond Milk', 'Soy Milk', 'Oat Milk', ''];

if ($sweetness !== '' && !in_array($sweetness, $valid_sweetness)) {
    json_out(false, 'Invalid sweetness option', 0, null, 400);
}
if ($ice !== '' && !in_array($ice, $valid_ice)) {
    json_out(false, 'Invalid ice option', 0, null, 400);
}
if ($milk !== '' && !in_array($milk, $valid_milk)) {
    json_out(false, 'Invalid milk option', 0, null, 400);
}

// Fetch product
$stmt = $conn->prepare("SELECT product_id, name, price, image FROM products WHERE product_id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    json_out(false, 'Product not found or unavailable', 0, null, 404);
}

$p = $res->fetch_assoc();

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Merge identical items
$found = false;
foreach ($_SESSION['cart'] as &$item) {
    if (
        $item['product_id'] == $product_id &&
        $item['sweetness']  == $sweetness  &&
        $item['ice']        == $ice        &&
        $item['milk']       == $milk
    ) {
        $item['qty'] += $qty;
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    $_SESSION['cart'][] = [
        'product_id'   => $p['product_id'],
        'product_name' => $p['name'],
        'price'        => (float)$p['price'],
        'image'        => $p['image'],
        'sweetness'    => $sweetness,
        'ice'          => $ice,
        'milk'         => $milk,
        'qty'          => $qty,
    ];
}

// Calculate totals
$total_qty   = 0;
$cart_total  = 0.0;
foreach ($_SESSION['cart'] as $item) {
    $q           = (int)($item['qty'] ?? 1);
    $total_qty  += $q;
    $cart_total += (float)($item['price'] ?? 0) * $q;
}

json_out(true, 'Added to cart!', $total_qty, round($cart_total, 2));
