<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$raw    = explode(',', $_POST['ids'] ?? '');
$ids    = array_values(array_filter(array_map('intval', $raw), fn($v) => $v > 0));

if (empty($ids)) { echo json_encode(['ok' => false, 'error' => 'No IDs']); exit; }

$ph = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

if ($action === 'delete') {
    // Remove image files
    $sel = $conn->prepare("SELECT image FROM products WHERE product_id IN ($ph)");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        if (!empty($r['image']) && file_exists($r['image'])) unlink($r['image']);
    }
    $del = $conn->prepare("DELETE FROM products WHERE product_id IN ($ph)");
    $del->bind_param($types, ...$ids);
    $del->execute();
    echo json_encode(['ok' => true, 'deleted' => $ids]);

} elseif ($action === 'toggle') {
    $upd = $conn->prepare("UPDATE products SET is_available = 1 - is_available WHERE product_id IN ($ph)");
    $upd->bind_param($types, ...$ids);
    $upd->execute();
    $sel = $conn->prepare("SELECT product_id, is_available FROM products WHERE product_id IN ($ph)");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $states = [];
    foreach ($sel->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $states[$r['product_id']] = (int)$r['is_available'];
    }
    echo json_encode(['ok' => true, 'states' => $states]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
