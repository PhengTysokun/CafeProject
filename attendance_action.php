<?php
require 'auth.php';
require 'config.php';

header('Content-Type: application/json');

$conn->query("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    date DATE NOT NULL,
    hours_worked DECIMAL(5,2) NULL
)");

// Auto-close any open records from previous days at 23:59:59 of their date
$conn->query("UPDATE attendance SET
    clock_out    = TIMESTAMP(date, '23:59:59'),
    hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, TIMESTAMP(date, '23:59:59')) / 60, 2)
WHERE clock_out IS NULL AND date < CURDATE()");

$user_id  = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$action   = $_POST['action'] ?? '';

if ($action === 'clock_in') {
    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = CURDATE() AND clock_out IS NULL");
    $check->bind_param('i', $user_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['ok' => false, 'msg' => 'Already clocked in.']);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO attendance (user_id, username, clock_in, date) VALUES (?, ?, NOW(), CURDATE())");
    $stmt->bind_param('is', $user_id, $username);
    $stmt->execute();
    echo json_encode(['ok' => true, 'msg' => 'Clocked in at ' . date('g:i A'), 'time' => date('g:i A')]);
    exit;
}

if ($action === 'clock_out') {
    $stmt = $conn->prepare("UPDATE attendance SET clock_out = NOW(), hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, NOW()) / 60, 2) WHERE user_id = ? AND date = CURDATE() AND clock_out IS NULL");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    if ($conn->affected_rows > 0) {
        $hrs = $conn->query("SELECT hours_worked FROM attendance WHERE user_id = $user_id AND date = CURDATE() AND clock_out IS NOT NULL ORDER BY clock_out DESC LIMIT 1")->fetch_assoc()['hours_worked'];
        echo json_encode(['ok' => true, 'msg' => 'Clocked out — ' . $hrs . 'h worked', 'hours' => $hrs]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Not clocked in.']);
    }
    exit;
}

// GET: fetch attendance data for a date (used by live-poll in attendance.php)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'data') {
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $stmt = $conn->prepare("SELECT a.*, e.name AS emp_name FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id WHERE a.date = ? ORDER BY a.clock_in ASC");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $total_present = count($records);
    $still_working = count(array_filter($records, fn($r) => is_null($r['clock_out'])));
    $total_hours   = array_sum(array_column($records, 'hours_worked'));

    // Add computed fields for JS rendering
    foreach ($records as &$r) {
        $r['display_name']  = $r['emp_name'] ?: $r['username'];
        $r['clock_in_fmt']  = date('g:i A', strtotime($r['clock_in']));
        $r['clock_out_fmt'] = $r['clock_out'] ? date('g:i A', strtotime($r['clock_out'])) : null;
        $r['working']       = is_null($r['clock_out']);
        $r['hours_display'] = $r['working']
            ? round((time() - strtotime($r['clock_in'])) / 3600, 1)
            : (float)$r['hours_worked'];
    }

    echo json_encode([
        'ok'            => true,
        'records'       => $records,
        'total_present' => $total_present,
        'still_working' => $still_working,
        'done'          => $total_present - $still_working,
        'total_hours'   => round($total_hours, 1),
        'time'          => date('g:i:s A'),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
