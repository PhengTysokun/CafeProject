<?php
require 'config.php';

$username = 'cashier';
$password = password_hash('cashier123', PASSWORD_DEFAULT);
$role = 'staff';

$stmt = $conn->prepare("UPDATE users SET password = ?, role = ? WHERE username = ?");
$stmt->bind_param("sss", $password, $role, $username);

if ($stmt->execute()) {
    echo "Cashier password fixed successfully!";
} else {
    echo "Error: " . $conn->error;
}