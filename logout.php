<?php
session_start();
session_unset();
session_destroy();
$timeout = isset($_GET['timeout']) ? '?timeout=1' : '';
header("Location: login.php" . $timeout);
exit;
