<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$hash = password_hash('Test@1234', PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, 'staff')");
$u = 'test_cashier';
$stmt->bind_param('ss', $u, $hash);
$stmt->execute();
echo "Done. Rows affected: " . $conn->affected_rows . "\n";
echo "test_cashier / Test@1234 is ready.\n";
