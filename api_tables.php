<?php
require 'auth.php';
header('Content-Type: application/json');
$res = $conn->query("SELECT table_number, capacity FROM cafe_tables WHERE status = 'available' ORDER BY table_number");
$tables = [];
while ($row = $res->fetch_assoc()) $tables[] = $row;
echo json_encode($tables);
