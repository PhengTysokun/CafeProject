<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config.php';

$username = 'test_admin';
$password = 'Test@1234';
$role     = 'admin';
$hash     = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT IGNORE INTO users (username, password, role, must_change_password) VALUES (?, ?, ?, 0)");
$stmt->bind_param("sss", $username, $hash, $role);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo "Created test_admin user.\n";
} else {
    echo "test_admin already exists (or insert failed).\n";
}
echo "Verify: " . (password_verify($password, $hash) ? "PASS" : "FAIL") . "\n";
