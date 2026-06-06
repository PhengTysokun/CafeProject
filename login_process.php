<?php
session_start();
include "config.php";

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── Rate limiting: max 5 attempts per 15 minutes per IP ──
$conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$rate = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$rate->bind_param("s", $ip);
$rate->execute();
$attempts = (int)$rate->get_result()->fetch_row()[0];

if ($attempts >= 5) {
    header("Location: login.php?error=locked");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header("Location: login.php?error=1");
    exit;
}

$sql  = "SELECT * FROM users WHERE username = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($user = mysqli_fetch_assoc($result)) {
    if (password_verify($password, $user['password'])) {
        // ── Successful login ──
        session_regenerate_id(true); // prevent session fixation

        $_SESSION['user_id']  = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['last_activity'] = time();

        // Clear failed attempts for this IP on success
        $del = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $del->bind_param("s", $ip);
        $del->execute();

        if (in_array($user['role'], ['admin', 'manager'])) {
            header("Location: dashboard.php");
        } elseif ($user['role'] === 'staff') {
            header("Location: view_order.php");
        } else {
            header("Location: login.php?error=1");
        }
        exit;
    }
}

// ── Failed attempt — log it ──
$log = $conn->prepare("INSERT INTO login_attempts (ip) VALUES (?)");
$log->bind_param("s", $ip);
$log->execute();

$remaining = max(0, 4 - $attempts);
header("Location: login.php?error=1&left=" . $remaining);
exit;
