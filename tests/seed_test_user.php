<?php
require_once __DIR__ . '/../config.php';
$hash = password_hash('Test@1234', PASSWORD_DEFAULT);
$conn->query("INSERT IGNORE INTO users (username, password, role)
              VALUES ('test_cashier', '$hash', 'staff')");
echo "Done. Rows affected: " . $conn->affected_rows . "\n";
echo "test_cashier / Test@1234 is ready.\n";
?>
