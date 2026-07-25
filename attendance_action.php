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

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// Clocking someone ELSE in/out writes payroll data for another person — manager/admin
// only, mirroring the reconcile split in stock_count.php. The self-serve clock_in /
// clock_out actions below are unchanged and stay open to every signed-in user.
$is_manager = in_array($_SESSION['role'] ?? '', ['admin','manager'], true);

/* ── Manager: clock another employee IN ── */
if ($action === 'manager_clock_in') {
    if (!$is_manager)  { echo json_encode(['ok'=>false,'msg'=>'Only an admin or manager can clock staff in.']); exit; }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Session expired. Reload and try again.']); exit;
    }
    $target = (int)($_POST['user_id'] ?? 0);
    if ($target <= 0) { echo json_encode(['ok'=>false,'msg'=>'Pick an employee.']); exit; }

    $u = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
    $u->bind_param('i', $target);
    $u->execute();
    $urow = $u->get_result()->fetch_assoc();
    if (!$urow) { echo json_encode(['ok'=>false,'msg'=>'Unknown employee.']); exit; }

    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = CURDATE() AND clock_out IS NULL");
    $check->bind_param('i', $target);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['ok'=>false,'msg'=>$urow['username'].' is already clocked in.']); exit;
    }

    $ins = $conn->prepare("INSERT INTO attendance (user_id, username, clock_in, date, adjusted_by, adjusted_at)
                           VALUES (?, ?, NOW(), CURDATE(), ?, NOW())");
    $ins->bind_param('iss', $target, $urow['username'], $username);
    $ins->execute();
    echo json_encode(['ok'=>true,'msg'=>$urow['username'].' clocked in at '.date('g:i A')]);
    exit;
}

/* ── Manager: clock another employee OUT ── */
if ($action === 'manager_clock_out') {
    if (!$is_manager)  { echo json_encode(['ok'=>false,'msg'=>'Only an admin or manager can clock staff out.']); exit; }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Session expired. Reload and try again.']); exit;
    }
    $att_id = (int)($_POST['att_id'] ?? 0);
    if ($att_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Missing record.']); exit; }

    // Guarded on clock_out IS NULL so a double-click can't overwrite an existing
    // clock-out time (which would silently rewrite the hours already banked).
    $upd = $conn->prepare("UPDATE attendance
        SET clock_out = NOW(),
            hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, clock_in, NOW()) / 60, 2),
            adjusted_by = ?, adjusted_at = NOW()
        WHERE id = ? AND clock_out IS NULL");
    $upd->bind_param('si', $username, $att_id);
    $upd->execute();
    if ($upd->affected_rows === 0) {
        echo json_encode(['ok'=>false,'msg'=>'That shift is already closed.']); exit;
    }

    $row = $conn->query("SELECT username, hours_worked FROM attendance WHERE id = $att_id")->fetch_assoc();
    echo json_encode([
        'ok'  => true,
        'msg' => $row['username'].' clocked out — '.fmt_hours((float)$row['hours_worked']).' worked',
    ]);
    exit;
}

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
        $hrs_raw            = $r['working']
            ? (time() - strtotime($r['clock_in'])) / 3600
            : (float)$r['hours_worked'];
        $r['hours_display'] = fmt_hours((float)$hrs_raw);
        $r['hours_raw']     = round((float)$hrs_raw, 2);
        $r['att_id']        = (int)$r['id'];
        $r['adjusted_by']   = $r['adjusted_by'] ?? null;
    }
    unset($r);

    echo json_encode([
        'ok'                  => true,
        'records'             => $records,
        'total_present'       => $total_present,
        'still_working'       => $still_working,
        'done'                => $total_present - $still_working,
        'total_hours'         => round($total_hours, 2),
        'total_hours_display' => fmt_hours((float)$total_hours),
        'time'                => date('g:i:s A'),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
