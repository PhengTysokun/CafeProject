<?php
require 'auth.php';
require 'config.php';
if (!can('attendance')) { header("Location: dashboard.php?denied=1"); exit; }

// ── Resolve filters ──
$today        = date('Y-m-d');
$default_from = date('Y-m-d', strtotime('-30 days'));

$from = $_GET['from'] ?? $default_from;
$to   = $_GET['to']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }

$emp = (int)($_GET['emp'] ?? 0);   // 0 = all

// ── Shared WHERE fragment + bind set (reused by count, rows, summary, CSV) ──
$where = "a.date BETWEEN ? AND ?";
$types = "ss";
$binds = [$from, $to];
if ($emp > 0) { $where .= " AND a.user_id = ?"; $types .= "i"; $binds[] = $emp; }

// ── URL helper: preserve current filters, override as needed ──
function qs(array $overrides = []): string {
    global $from, $to, $emp;
    $p = array_merge(['from' => $from, 'to' => $to, 'emp' => $emp ?: 'all'], $overrides);
    return 'attendance_history.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance History | Bird's Nest Coffee</title>
</head>
<body>
<h1>Attendance History (scaffold)</h1>
<p>Range: <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?> | emp: <?= (int)$emp ?></p>
</body>
</html>
