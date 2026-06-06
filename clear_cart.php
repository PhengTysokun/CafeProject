<?php
session_start();

// ── CLEAR CART ──
$_SESSION['cart'] = [];

// Redirect back to menu
header("Location: menu.php");
exit;
?>