<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ── Session timeout: 30 minutes idle ──
$timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

// ── Re-sync role from DB so admin role changes take effect on next page load ──
require_once 'config.php';
$_rs = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$_rs->bind_param("i", $_SESSION['user_id']);
$_rs->execute();
$_rr = $_rs->get_result()->fetch_assoc();
if (!$_rr) {
    // Account deleted — force logout
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['role'] = $_rr['role'];
unset($_rs, $_rr);
?>
