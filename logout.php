<?php
session_start();

// Auto-clock-out if the user forgot to clock out before logging off
if (!empty($_SESSION['user_id'])) {
    require_once 'config.php';
    $uid = (int)$_SESSION['user_id'];
    $conn->query("UPDATE attendance
                  SET clock_out    = NOW(),
                      hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, NOW()) / 60, 2)
                  WHERE user_id = $uid
                    AND date = CURDATE()
                    AND clock_out IS NULL");
}

session_unset();
session_destroy();
$timeout = isset($_GET['timeout']) ? '?timeout=1' : '';
header("Location: login.php" . $timeout);
exit;
