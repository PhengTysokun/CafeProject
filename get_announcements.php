<?php
require 'config.php';
header('Content-Type: application/json');

$check = $conn->query("SHOW TABLES LIKE 'announcements'");
if (!$check || $check->num_rows === 0) { echo json_encode([]); exit; }

$res = $conn->query("
    SELECT id, title, message, type
    FROM announcements
    WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE())
    ORDER BY created_at DESC
");
echo json_encode($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
