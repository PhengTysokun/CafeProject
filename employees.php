<?php
ob_start();
require 'auth.php';
require_once 'config.php';
if (!can('employees')) { header("Location: dashboard.php?denied=1"); exit; }

/* ── Get employee data for edit modal (AJAX GET) ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_employee') {
    header('Content-Type: application/json');
    $eid = intval($_GET['eid'] ?? 0);
    if ($eid > 0) {
        $s = $conn->prepare("SELECT e.*, COALESCE(NULLIF(u.role,''),'staff') AS emp_role FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) WHERE e.employee_id=?");
        $s->bind_param("i", $eid); $s->execute();
        $emp = $s->get_result()->fetch_assoc();
        if ($emp) { ob_end_clean(); echo json_encode(['ok' => true, 'emp' => $emp]); exit; }
    }
    ob_end_clean();
    echo json_encode(['ok' => false]);
    exit;
}

/* ── Real-time stats (AJAX GET) ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_stats') {
    header('Content-Type: application/json');
    $hasBizDate = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'business_date'")->num_rows > 0;
    $dc = $hasBizDate ? 'business_date' : 'created_at';
    $res = $conn->query("
        SELECT e.employee_id, e.user_id, e.shift, e.job_title,
            COALESCE(s.total_orders,      0) AS total_orders,
            COALESCE(s.total_revenue,     0) AS total_revenue,
            COALESCE(s.orders_this_month, 0) AS orders_this_month,
            COALESCE(s.orders_today,      0) AS orders_today,
            COALESCE(s.avg_order_value,   0) AS avg_order_value
        FROM employees e
        LEFT JOIN (
            SELECT employee_id,
                COUNT(*)                                                                        AS total_orders,
                COALESCE(SUM(total), 0)                                                         AS total_revenue,
                SUM(CASE WHEN DATE_FORMAT(`$dc`,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 1 ELSE 0 END) AS orders_this_month,
                SUM(CASE WHEN DATE(`$dc`) = CURDATE() THEN 1 ELSE 0 END)                        AS orders_today,
                AVG(total)                                                                       AS avg_order_value
            FROM orders
            WHERE status NOT IN ('cancelled','refunded','void')
            GROUP BY employee_id
        ) s ON s.employee_id = e.user_id
    ");
    $stats = []; $max = 1;
    while ($r = $res->fetch_assoc()) {
        $stats[(int)$r['employee_id']] = [
            'total_orders'      => (int)$r['total_orders'],
            'total_revenue'     => (float)$r['total_revenue'],
            'orders_this_month' => (int)$r['orders_this_month'],
            'orders_today'      => (int)$r['orders_today'],
            'avg_order_value'   => (float)$r['avg_order_value'],
            'shift'             => $r['shift'] ?? null,
            'job_title'         => $r['job_title'] ?? '',
        ];
        if ((int)$r['total_orders'] > $max) $max = (int)$r['total_orders'];
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'stats' => $stats, 'max_orders' => $max]);
    exit;
}

/* ── Save employee from edit modal (AJAX POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_employee') {
    header('Content-Type: application/json');
    $eid      = intval($_POST['eid'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $job      = trim($_POST['job_title'] ?? '');
    $salary   = floatval($_POST['salary'] ?? 0);
    $dob      = $_POST['date_of_birth'] ?? '';
    $hire     = $_POST['hire_date'] ?? '';
    $address  = trim($_POST['address'] ?? '');
    $new_role = trim($_POST['emp_role'] ?? '');
    $shift_raw = trim($_POST['shift'] ?? '');
    $shift    = in_array($shift_raw, ['morning','afternoon','night']) ? $shift_raw : null;

    if ($eid <= 0 || $name === '') {
        ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Invalid data']); exit;
    }
    $se = $conn->prepare("SELECT user_id, employee_id FROM employees WHERE employee_id=?");
    $se->bind_param("i", $eid); $se->execute();
    $er = $se->get_result()->fetch_assoc();
    if (!$er) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Not found']); exit; }

    $photo = null;
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            if (!is_dir('uploads')) mkdir('uploads', 0755, true);
            $photo = 'uploads/' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
        }
    }

    if ($photo) {
        $st = $conn->prepare("UPDATE employees SET name=?,phone=?,job_title=?,salary=?,date_of_birth=?,hire_date=?,address=?,photo=?,shift=? WHERE employee_id=?");
        $st->bind_param("sssssssssi", $name,$phone,$job,$salary,$dob,$hire,$address,$photo,$shift,$eid);
    } else {
        $st = $conn->prepare("UPDATE employees SET name=?,phone=?,job_title=?,salary=?,date_of_birth=?,hire_date=?,address=?,shift=? WHERE employee_id=?");
        $st->bind_param("ssssssssi", $name,$phone,$job,$salary,$dob,$hire,$address,$shift,$eid);
    }
    $st->execute();

    $_cur_is_admin = ($_SESSION['role'] ?? '') === 'admin';
    if ($new_role !== '' && ($_cur_is_admin || $new_role !== 'admin')) {
        $vr = $conn->prepare($_cur_is_admin ? "SELECT slug FROM roles WHERE slug=?" : "SELECT slug FROM roles WHERE slug=? AND slug!='admin'");
        $vr->bind_param("s", $new_role); $vr->execute();
        if ($vr->get_result()->fetch_assoc()) {
            $uid = !empty($er['user_id']) ? intval($er['user_id']) : intval($er['employee_id']);
            $rs  = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
            $rs->bind_param("si", $new_role, $uid); $rs->execute();
        }
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'photo' => $photo, 'name' => $name, 'job' => $job, 'role' => $new_role, 'shift' => $shift]);
    exit;
}

/* ── Quick role update (AJAX) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_role') {
    header('Content-Type: application/json');
    $eid      = intval($_POST['eid'] ?? 0);
    $new_role = trim($_POST['role'] ?? '');
    $_cur_is_admin = ($_SESSION['role'] ?? '') === 'admin';
    if ($eid > 0 && $new_role !== '' && ($_cur_is_admin || $new_role !== 'admin')) {
        $vr = $conn->prepare($_cur_is_admin ? "SELECT slug FROM roles WHERE slug=?" : "SELECT slug FROM roles WHERE slug=? AND slug!='admin'");
        $vr->bind_param("s", $new_role); $vr->execute();
        if ($vr->get_result()->fetch_assoc()) {
            $se = $conn->prepare("SELECT COALESCE(user_id, employee_id) AS uid FROM employees WHERE employee_id=?");
            $se->bind_param("i", $eid); $se->execute();
            $er = $se->get_result()->fetch_assoc();
            if ($er) {
                $uid = intval($er['uid']);
                $ru  = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
                $ru->bind_param("si", $new_role, $uid); $ru->execute();
                if ($conn->affected_rows >= 0) {
                    ob_end_clean();
                    echo json_encode(['ok' => true]);
                    exit;
                }
            }
        }
    }
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Invalid request']);
    exit;
}

$sess_role = $_SESSION['role'] ?? 'staff';

/* ── Enrich employees with performance data from orders ── */
$has_orders = $conn->query("SHOW TABLES LIKE 'orders'")->num_rows > 0;

if ($has_orders) {
    $hasBizDate = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'business_date'")->num_rows > 0;
    $dc = $hasBizDate ? 'business_date' : 'created_at';
    $emp_sql = "
        SELECT
            e.*,
            COALESCE(NULLIF(u.role,''), 'staff') AS emp_role,
            COALESCE(s.total_orders,      0)    AS total_orders,
            COALESCE(s.total_revenue,     0)    AS total_revenue,
            COALESCE(s.orders_this_month, 0)    AS orders_this_month,
            COALESCE(s.orders_today,      0)    AS orders_today,
            COALESCE(s.avg_order_value,   0)    AS avg_order_value,
            s.last_order_date
        FROM employees e
        LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id)
        LEFT JOIN (
            SELECT
                employee_id,
                COUNT(*)                                                                   AS total_orders,
                COALESCE(SUM(total), 0)                                                    AS total_revenue,
                SUM(CASE WHEN DATE_FORMAT(`$dc`,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 1 ELSE 0 END) AS orders_this_month,
                SUM(CASE WHEN DATE(`$dc`) = CURDATE() THEN 1 ELSE 0 END)                  AS orders_today,
                AVG(total)                                                                 AS avg_order_value,
                MAX(`$dc`)                                                                 AS last_order_date
            FROM orders
            WHERE status NOT IN ('cancelled','refunded','void')
            GROUP BY employee_id
        ) s ON s.employee_id = e.user_id
        ORDER BY total_orders DESC, e.name ASC
    ";
} else {
    $emp_sql = "
        SELECT e.*, COALESCE(NULLIF(u.role,''),'staff') AS emp_role,
               0 AS total_orders, 0 AS total_revenue,
               0 AS orders_this_month, 0 AS orders_today,
               0 AS avg_order_value, NULL AS last_order_date
        FROM employees e
        LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id)
        ORDER BY e.name ASC
    ";
}

$employees  = [];
$max_orders = 1;
$res = $conn->query($emp_sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $employees[] = $r;
        if ((int)$r['total_orders'] > $max_orders) $max_orders = (int)$r['total_orders'];
    }
}

$total_staff      = count($employees);
$total_orders_all = (int)array_sum(array_column($employees, 'total_orders'));
$total_revenue    = (float)array_sum(array_column($employees, 'total_revenue'));
$total_this_month = (int)array_sum(array_column($employees, 'orders_this_month'));

$top_month = null;
foreach ($employees as $e) {
    if (!$top_month || (int)$e['orders_this_month'] > (int)$top_month['orders_this_month'])
        $top_month = $e;
}

$leaderboard = array_slice($employees, 0, 3);

// Load roles from DB for dynamic filter pills
$_roles_db = [];
$_rdb = $conn->query("SELECT slug, name, icon, color FROM roles ORDER BY is_system DESC, id ASC");
while ($_rdbr = $_rdb->fetch_assoc()) $_roles_db[$_rdbr['slug']] = $_rdbr;

// Count employees per role dynamically
$role_counts_emp = [];
foreach ($employees as $_e) {
    $r = $_e['emp_role'] ?? 'staff';
    $role_counts_emp[$r] = ($role_counts_emp[$r] ?? 0) + 1;
}
// Keep legacy vars for any remaining hardcoded references
$count_admin   = $role_counts_emp['admin']   ?? 0;
$count_manager = $role_counts_emp['manager'] ?? 0;
$count_staff   = $role_counts_emp['staff']   ?? 0;

// Shift counts
$shift_counts = ['morning' => 0, 'afternoon' => 0, 'night' => 0];
foreach ($employees as $_e) {
    $sh = $_e['shift'] ?? '';
    if (isset($shift_counts[$sh])) $shift_counts[$sh]++;
}

$sorted_employees = $employees;
$_role_order = array_keys($_roles_db);
usort($sorted_employees, function($a, $b) use ($_role_order) {
    $ap = array_search($a['emp_role'] ?? 'staff', $_role_order);
    $bp = array_search($b['emp_role'] ?? 'staff', $_role_order);
    $ap = $ap === false ? 999 : $ap;
    $bp = $bp === false ? 999 : $bp;
    if ($ap !== $bp) return $ap - $bp;
    return (int)$b['total_orders'] - (int)$a['total_orders'];
});

function h($s)       { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n)   { return number_format((float)$n, 2); }
function fmtnum($n)  { return number_format((int)$n); }
function tenure($d) {
    if (!$d || $d === '0000-00-00') return '—';
    try {
        $diff = (new DateTime($d))->diff(new DateTime());
        if ($diff->y > 0) return $diff->y . 'y ' . $diff->m . 'm';
        if ($diff->m > 0) return $diff->m . ' mo';
        return max(1, $diff->d) . 'd';
    } catch (Exception $e) { return '—'; }
}
function initials($name) {
    $p = array_values(array_filter(explode(' ', trim($name))));
    $i = strtoupper(substr($p[0] ?? 'E', 0, 1));
    if (count($p) > 1) $i .= strtoupper(substr(end($p), 0, 1));
    return $i;
}
function avatarColor($name) {
    $colors = ['#d1904b','#55e087','#3498db','#9b59b6','#e74c3c','#1abc9c','#e67e22','#e91e8c'];
    return $colors[crc32($name) % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Employees | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── VARS ── */
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --gold:#f1c40f; --silver:#bdc3c7; --bronze:#cd7f32;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#f2ede8; --bg-card:#fff; --bg-input:#ede8e0;
    --border:#e0d4c4; --border-hover:#c9b89f;
    --text:#1a1410; --text-muted:#7a6a58; --text-light:#1a1410;
    --shadow-sm:0 2px 8px rgba(90,60,20,.07); --shadow-md:0 4px 20px rgba(90,60,20,.11);
}
[data-theme="light"] .topbar { background:rgba(255,252,248,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tbody tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

/* ── RESET ── */
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:60px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* ── TOPBAR ── */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.topbar-brand { display:flex; align-items:center; gap:10px; }
.brand-icon   { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text   { display:flex; flex-direction:column; line-height:1.2; }
.brand-title  { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub    { font-size:10px; color:var(--text-muted); }
.topbar-sep   { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-center { flex:1; display:flex; justify-content:center; min-width:180px; }
.topbar-right  { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.search-wrap {
    display:flex; align-items:center; gap:8px; padding:8px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    transition:var(--transition); max-width:300px; width:100%;
}
.search-wrap:focus-within { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.search-wrap i { color:var(--text-muted); font-size:12px; flex-shrink:0; }
.search-wrap input { border:none; background:transparent; outline:none; color:var(--text); font-size:13px; font-family:'Poppins',sans-serif; flex:1; min-width:0; }
.search-wrap input::placeholder { color:var(--text-muted); }
#clearSearch { background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0; font-size:12px; transition:var(--transition); display:none; align-items:center; }
#clearSearch.vis { display:flex; }
#clearSearch:hover { color:var(--danger); }
.kbd { font-size:10px; color:var(--text-muted); background:rgba(255,255,255,.06); padding:1px 6px; border-radius:4px; border:1px solid var(--border); font-family:monospace; }
.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.primary { background:var(--accent); color:#000; border-color:var(--accent); font-weight:700; }
.btn-nav.primary:hover { background:var(--accent-light); }
.btn-nav.icon-only { padding:7px 10px; }

/* ── STATS ── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; padding:16px 24px 0; }
.stat-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:16px 18px; display:flex; align-items:center; gap:12px;
    transition:var(--transition); position:relative; overflow:hidden;
}
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:var(--radius) var(--radius) 0 0; }
.stat-card.s-a::before { background:linear-gradient(90deg,var(--accent-dark),var(--accent-light)); }
.stat-card.s-b::before { background:linear-gradient(90deg,#2471a3,var(--blue)); }
.stat-card.s-c::before { background:linear-gradient(90deg,#d4ac0d,var(--gold)); }
.stat-card.s-d::before { background:linear-gradient(90deg,#27ae60,var(--ok)); }
.stat-card:hover { border-color:var(--border-hover); box-shadow:var(--shadow-md); transform:translateY(-1px); }
.stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.si-a { background:rgba(209,144,75,.12); color:var(--accent); }
.si-b { background:rgba(52,152,219,.12);  color:var(--blue); }
.si-c { background:rgba(241,196,15,.12);  color:var(--gold); }
.si-d { background:rgba(85,224,135,.12);  color:var(--ok); }
.stat-label { font-size:10px; color:var(--text-muted); font-weight:500; text-transform:uppercase; letter-spacing:.5px; }
.stat-num   { font-size:21px; font-weight:800; line-height:1.2; }
.stat-hint  { font-size:10px; color:var(--text-muted); margin-top:1px; }
.s-a .stat-num { color:var(--text-light); }
.s-b .stat-num { color:var(--blue); }
.s-c .stat-num { color:var(--gold); }
.s-d .stat-num { color:var(--ok); }

/* ── LEADERBOARD ── */
.section-label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.8px; display:flex; align-items:center; gap:8px; padding:16px 24px 0; }
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }
.leaderboard { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; padding:10px 24px 0; }
.podium-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:20px; text-align:center; transition:var(--transition); position:relative; overflow:hidden;
}
.podium-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.podium-card.rank-1::before { background:linear-gradient(90deg,#d4ac0d,var(--gold)); }
.podium-card.rank-2::before { background:linear-gradient(90deg,#95a5a6,var(--silver)); }
.podium-card.rank-3::before { background:linear-gradient(90deg,#a04000,var(--bronze)); }
.podium-card.rank-1 { border-color:rgba(241,196,15,.25); }
.podium-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.rank-badge {
    display:inline-flex; align-items:center; justify-content:center;
    width:28px; height:28px; border-radius:50%; font-size:13px; font-weight:800; margin-bottom:10px;
}
.rank-badge.r1 { background:rgba(241,196,15,.15); color:var(--gold); border:1px solid rgba(241,196,15,.3); }
.rank-badge.r2 { background:rgba(189,195,199,.12); color:var(--silver); border:1px solid rgba(189,195,199,.25); }
.rank-badge.r3 { background:rgba(205,127,50,.12);  color:var(--bronze); border:1px solid rgba(205,127,50,.25); }
.podium-avatar {
    width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    font-size:20px; font-weight:700; color:#fff; margin:0 auto 10px; border:2px solid transparent;
    transition:var(--transition);
}
.rank-1 .podium-avatar { border-color:rgba(241,196,15,.4); box-shadow:0 0 16px rgba(241,196,15,.15); }
.podium-name { font-size:14px; font-weight:700; color:var(--text-light); margin-bottom:2px; }
.podium-title { font-size:11px; color:var(--text-muted); margin-bottom:10px; }
.podium-stats { display:flex; justify-content:center; gap:14px; }
.podium-stat { text-align:center; }
.podium-stat .ps-val { font-size:15px; font-weight:800; color:var(--accent); }
.podium-stat .ps-lbl { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; }
.podium-img { width:56px; height:56px; border-radius:50%; object-fit:cover; display:block; margin:0 auto 10px; border:2px solid transparent; }
.rank-1 .podium-img { border-color:rgba(241,196,15,.4); box-shadow:0 0 16px rgba(241,196,15,.15); }
.top-badge { position:absolute; top:12px; right:12px; background:rgba(241,196,15,.15); color:var(--gold); border:1px solid rgba(241,196,15,.25); border-radius:20px; padding:2px 8px; font-size:10px; font-weight:700; }

/* ── CONTROLS BAR ── */
.controls-bar { display:flex; align-items:center; gap:8px; padding:14px 24px 0; flex-wrap:wrap; }
.filter-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 13px; border-radius:50px; border:1px solid var(--border); background:var(--bg-card); color:var(--text-muted); font-size:12px; font-weight:600; cursor:pointer; transition:var(--transition); user-select:none; }
.filter-pill:hover { border-color:var(--accent); color:var(--accent); }
.filter-pill.role-empty { opacity:.45; }
.filter-pill.active { background:var(--accent); color:#000; border-color:var(--accent); }
.filter-pill.active-top  { background:var(--gold);   color:#000; border-color:var(--gold); }
.filter-pill.active-idle { background:var(--danger); color:#fff; border-color:var(--danger); }
<?php foreach ($_roles_db as $rslug => $rinfo):
    $rc = $rinfo['color'] ?? '#888'; ?>
.filter-pill.active-<?= $rslug ?> { background:<?= $rc ?>; color:#000; border-color:<?= $rc ?>; }
<?php endforeach; ?>
.pill-count { font-size:10px; font-weight:700; padding:1px 5px; border-radius:20px; background:rgba(255,255,255,.2); }
.ctrl-right { display:flex; align-items:center; gap:8px; margin-left:auto; }
.row-count  { font-size:12px; color:var(--text-muted); white-space:nowrap; }
.compact-btn { display:flex; align-items:center; gap:5px; padding:5px 10px; border-radius:7px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:11px; cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition); }
.compact-btn:hover { border-color:var(--border-hover); color:var(--text); }
.compact-btn.on { border-color:var(--accent); color:var(--accent); }

/* ── TABLE ── */
.table-card { margin:12px 24px 0; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
.table-wrap { overflow:auto; max-height:calc(100vh - 420px); min-height:200px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead { position:sticky; top:0; z-index:10; }
th { padding:10px 14px; text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); background:var(--bg-card); border-bottom:1px solid var(--border); white-space:nowrap; cursor:pointer; user-select:none; transition:color .18s; }
th:hover { color:var(--accent); }
th.sorted { color:var(--accent); }
th .si { margin-left:4px; opacity:.3; font-size:9px; }
th.sorted .si { opacity:1; }
th:last-child { cursor:default; }
th:last-child:hover { color:var(--text-muted); }
td { padding:12px 14px; border-bottom:1px solid var(--border); color:var(--text); white-space:nowrap; transition:background .12s; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tbody tr:hover td { background:rgba(255,255,255,.025); }
tr.hidden { display:none!important; }
tr.compact td { padding:6px 14px; }
tfoot td { padding:10px 14px; font-size:11px; font-weight:700; border-top:2px solid var(--border); color:var(--text-muted); background:rgba(255,255,255,.015); }
.foot-val { color:var(--blue); font-size:13px; }

/* ── RANK CELL ── */
.rank-cell { font-size:13px; font-weight:700; color:var(--text-muted); text-align:center; min-width:36px; }
.rank-cell.r1 { color:var(--gold); }
.rank-cell.r2 { color:var(--silver); }
.rank-cell.r3 { color:var(--bronze); }

/* ── EMPLOYEE CELL ── */
.emp-cell { display:flex; align-items:center; gap:11px; }
.avatar { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; color:#fff; flex-shrink:0; border:2px solid transparent; transition:var(--transition); }
.avatar-img { width:38px; height:38px; border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid transparent; transition:var(--transition); }
tbody tr:hover .avatar, tbody tr:hover .avatar-img { border-color:var(--accent); }
.emp-name  { font-size:13px; font-weight:600; color:var(--text-light); line-height:1.2; }
.emp-title { font-size:11px; color:var(--text-muted); }

/* ── ROLE BADGE ── */

/* ── GROUP SEPARATOR ── */
.group-sep { pointer-events:none; }
.group-sep td {
    padding:8px 14px 5px;
    font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase;
    border-bottom:1px solid var(--border);
    border-top:2px solid var(--border);
    background:var(--bg);
}
.group-sep td { color:var(--gs-color, var(--text-muted)); }
.group-sep .gc { display:inline-flex; align-items:center; gap:6px; }
.group-sep .gcnt {
    font-size:9px; padding:1px 6px; border-radius:20px;
    background:rgba(255,255,255,.08); font-weight:700;
}

/* ── PERFORMANCE BAR ── */
.perf-cell { min-width:120px; }
.perf-top  { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:4px; }
.perf-num  { font-size:13px; font-weight:700; color:var(--text-light); }
.perf-bar  { height:4px; background:rgba(255,255,255,.07); border-radius:4px; overflow:hidden; }
.perf-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,var(--accent-dark),var(--accent)); transition:width .6s ease; }
.perf-sub  { font-size:10px; color:var(--text-muted); }

/* ── SMALL NUMBER ── */
.num-cell { font-size:13px; font-weight:600; color:var(--text-light); }
.num-zero { color:var(--text-muted); font-weight:400; }
.revenue  { color:var(--ok); font-weight:700; }

/* ── TENURE ── */
.tenure-cell { font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:5px; }

/* ── ROW ACTIONS ── */
.row-actions { display:flex; gap:5px; }
.btn-row { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:7px; font-size:11px; font-weight:600; cursor:pointer; text-decoration:none; transition:var(--transition); border:1px solid transparent; font-family:'Poppins',sans-serif; }
.btn-row.view { background:rgba(52,152,219,.08);  color:var(--blue);   border-color:rgba(52,152,219,.2); }
.btn-row.edit { background:rgba(209,144,75,.08);  color:var(--accent); border-color:rgba(209,144,75,.2); }
.btn-row.del  { background:rgba(255,95,95,.06);   color:var(--danger); border-color:rgba(255,95,95,.15); padding:4px 8px; }
.btn-row.view:hover { background:var(--blue);   color:#fff; }
.btn-row.edit:hover { background:var(--accent); color:#000; }
.btn-row.del:hover  { background:var(--danger); color:#fff; }

/* ── EMPTY STATE ── */
.empty-state { text-align:center; padding:60px 20px; }
.empty-state .ei { font-size:42px; color:var(--border-hover); margin-bottom:14px; }
.empty-state h3  { font-size:16px; font-weight:600; margin-bottom:6px; }
.empty-state p   { font-size:13px; color:var(--text-muted); margin-bottom:18px; }

/* ── TOAST ── */
#toast-cnt { position:fixed; bottom:24px; right:20px; z-index:99999; display:flex; flex-direction:column-reverse; gap:8px; pointer-events:none; }
.toast { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--text); box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; min-width:220px; max-width:320px; transform:translateX(120%); transition:transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events:auto; }
.toast.show { transform:translateX(0); }
.toast.success { border-left:3px solid var(--ok); }
.toast.error   { border-left:3px solid var(--danger); }

/* ── DELETE MODAL ── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.72); backdrop-filter:blur(10px); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; padding:28px; max-width:360px; width:100%; box-shadow:0 24px 64px rgba(0,0,0,.65); animation:popIn .24s ease both; position:relative; }
@keyframes popIn { from{opacity:0;transform:scale(.92) translateY(14px)} to{opacity:1;transform:scale(1) translateY(0)} }
.modal-close { position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; border:none; background:rgba(255,255,255,.06); color:var(--text-muted); font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:var(--transition); }
.modal-close:hover { color:var(--text); background:rgba(255,255,255,.12); }
.modal-title { font-size:17px; font-weight:700; margin-bottom:4px; color:var(--danger); display:flex; align-items:center; gap:8px; }
.modal-sub   { font-size:12px; color:var(--text-muted); margin-bottom:18px; }
.modal-row   { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:10px; background:var(--bg-input); margin-bottom:16px; border:1px solid rgba(255,95,95,.2); background:rgba(255,95,95,.04); }
.modal-row .ml { font-size:11px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.modal-row .mv { font-size:14px; font-weight:700; color:var(--text-light); }
.btn-danger  { width:100%; padding:12px; border-radius:10px; border:none; background:var(--danger); color:#fff; font-size:14px; font-weight:700; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; display:flex; align-items:center; justify-content:center; gap:8px; }
.btn-danger:hover  { filter:brightness(1.1); transform:translateY(-1px); }
.btn-cancel  { width:100%; margin-top:8px; padding:10px; border-radius:10px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; }
.btn-cancel:hover  { border-color:var(--border-hover); color:var(--text); }

/* ── SHORTCUTS ── */
.sc-bar { position:fixed; bottom:16px; left:20px; display:flex; gap:14px; font-size:11px; color:var(--text-muted); pointer-events:none; }
.sc-key { background:rgba(255,255,255,.06); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-family:monospace; font-size:10px; }

/* ── RESPONSIVE ── */
@media (max-width:1100px) { .stats-row { grid-template-columns:repeat(2,1fr); } .leaderboard { grid-template-columns:repeat(2,1fr); } }
@media (max-width:900px)  { .topbar-center { display:none; } }
@media (max-width:768px)  {
    .stats-row,.controls-bar,.table-card,.leaderboard { margin-left:14px; margin-right:14px; }
    .section-label { padding-left:14px; padding-right:14px; }
    .topbar { padding:10px 14px; }
    td,th { padding:8px 10px; font-size:12px; }
    .stats-row { padding:12px 14px 0; }
    .hide-mob { display:none; }
    .sc-bar { display:none; }
    .leaderboard { grid-template-columns:1fr; }
    .table-wrap { max-height:calc(100vh - 280px); }
}
@media (max-width:480px) {
    .stats-row { grid-template-columns:1fr 1fr; }
}
@media print {
    .topbar,.controls-bar,.row-actions,#toast-cnt,.modal-overlay,.sc-bar { display:none!important; }
    body { background:#fff; color:#000; padding:0; }
    .table-card { box-shadow:none; border:1px solid #ccc; margin:0; }
    .table-wrap { max-height:none; overflow:visible; }
}
@media (prefers-reduced-motion:reduce) { *,*::before,*::after { transition:none!important; animation:none!important; } }

/* ── SHIFT BADGE ── */
.shift-badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:var(--sb-c,#888); margin-top:3px; }
.shift-badge i { font-size:9px; }
.shift-pill[data-shift-filter="morning"]  i { color:#f39c12; }
.shift-pill[data-shift-filter="afternoon"] i { color:#3498db; }
.shift-pill[data-shift-filter="night"]    i { color:#9b59b6; }
.filter-pill.shift-active { background:var(--sb-active,var(--accent)); color:#000; border-color:var(--sb-active,var(--accent)); }
.filter-pill.shift-active i { color:inherit; }
.shift-sep { width:1px; height:20px; background:var(--border); margin:0 4px; flex-shrink:0; }

/* ── INLINE ROLE SELECTOR ── */
.role-wrap { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:50px; font-size:10px; font-weight:700; background:var(--rb-bg,rgba(255,255,255,.07)); border:1px solid var(--rb-border,rgba(255,255,255,.12)); transition:var(--transition); white-space:nowrap; }
.role-wrap.editable:hover { filter:brightness(1.18); cursor:pointer; }
.role-wrap i { pointer-events:none; }
.role-select { background:transparent; border:none; outline:none; color:var(--rb-color,#aaa); font-size:10px; font-weight:700; font-family:'Poppins',sans-serif; cursor:pointer; max-width:88px; }
.role-select option { background:#1a1a1a; color:#f5f5f5; font-weight:600; font-size:12px; }
[data-theme="light"] .role-select option { background:#fff; color:#1a1410; }

/* ── EDIT MODAL ── */
.em-overlay { position:fixed; inset:0; background:rgba(0,0,0,.72); z-index:900; display:flex; align-items:center; justify-content:center; padding:16px; opacity:0; pointer-events:none; transition:opacity .25s ease; }
.em-overlay.open { opacity:1; pointer-events:all; }
.em-panel { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; width:100%; max-width:760px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.6); transform:translateY(22px) scale(.97); transition:transform .28s cubic-bezier(.4,0,.2,1), opacity .25s ease; opacity:0; }
.em-overlay.open .em-panel { transform:none; opacity:1; }
.em-header { display:flex; align-items:center; gap:16px; padding:22px 26px 18px; border-bottom:1px solid var(--border); flex-shrink:0; }
.em-avatar-wrap { position:relative; flex-shrink:0; cursor:pointer; width:68px; height:68px; }
.em-avatar, .em-avatar-fallback { width:68px; height:68px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); display:block; transition:var(--transition); }
.em-avatar-fallback { background:linear-gradient(135deg,var(--accent),var(--accent-dark,#a0702a)); color:#000; font-size:26px; font-weight:700; display:flex; align-items:center; justify-content:center; }
.em-avatar-overlay { position:absolute; inset:0; border-radius:50%; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; opacity:0; transition:var(--transition); color:#fff; font-size:18px; }
.em-avatar-wrap:hover .em-avatar-overlay { opacity:1; }
.em-avatar-wrap:hover .em-avatar, .em-avatar-wrap:hover .em-avatar-fallback { transform:scale(1.05); }
.em-title { flex:1; }
.em-title h3 { font-size:17px; font-weight:700; margin-bottom:3px; }
.em-title p { font-size:12px; color:var(--text-muted); }
.em-close { width:34px; height:34px; border-radius:9px; border:1px solid var(--border); background:transparent; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:var(--transition); font-size:16px; }
.em-close:hover { background:rgba(255,95,95,.1); color:var(--danger); border-color:rgba(255,95,95,.3); }
.em-body { overflow-y:auto; padding:22px 26px; flex:1; }
.em-grid { display:grid; grid-template-columns:1fr 1fr; gap:11px; }
.em-tile { background:rgba(255,255,255,.02); border:1px solid var(--border); border-radius:13px; padding:11px 14px; transition:var(--transition); }
.em-tile:focus-within { border-color:var(--accent); background:rgba(255,255,255,.04); box-shadow:0 0 0 3px rgba(209,144,75,.07); }
.em-tile.full { grid-column:1/-1; }
.em-label { font-size:10px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px; display:flex; align-items:center; gap:6px; margin-bottom:6px; }
.em-label i { font-size:10px; color:var(--accent); }
.em-input { width:100%; background:transparent; border:none; outline:none; color:var(--text); font-size:13px; font-weight:500; font-family:'Poppins',sans-serif; }
.em-input::placeholder { color:var(--text-muted); font-weight:400; }
.em-input[type="date"] { color-scheme:dark; }
textarea.em-input { resize:vertical; min-height:62px; line-height:1.5; }
.em-role-grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
.em-role-opt { display:none; }
.em-role-label { display:flex; align-items:center; gap:8px; padding:8px 13px; border-radius:11px; border:1.5px solid var(--border); background:rgba(255,255,255,.02); cursor:pointer; transition:var(--transition); user-select:none; }
.em-role-label:hover { border-color:var(--border-hover); background:rgba(255,255,255,.04); }
.em-role-opt:checked + .em-role-label { border-color:var(--erc); background:var(--erc-bg); box-shadow:0 0 0 3px var(--erc-glow); }
.em-role-icon { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:12px; background:var(--erc-bg); color:var(--erc); flex-shrink:0; }
.em-role-name { font-size:12px; font-weight:600; }
.em-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 26px; border-top:1px solid var(--border); flex-shrink:0; }
.em-btn { padding:9px 20px; border-radius:11px; font-size:13px; font-weight:600; border:1px solid transparent; cursor:pointer; display:flex; align-items:center; gap:7px; transition:var(--transition); font-family:'Poppins',sans-serif; }
.em-btn-cancel { border-color:var(--border-hover); color:var(--text-muted); background:transparent; }
.em-btn-cancel:hover { color:var(--text); border-color:var(--text-muted); }
.em-btn-save { background:linear-gradient(135deg,var(--accent),var(--accent-light,#e8b87a)); color:#000; box-shadow:0 4px 16px rgba(209,144,75,.25); border:none; }
.em-btn-save:hover { filter:brightness(1.1); transform:translateY(-1px); }
.em-btn-save:disabled { opacity:.6; cursor:not-allowed; transform:none; }
.em-photo-pill { display:none; align-items:center; gap:6px; font-size:11px; font-weight:500; color:var(--accent); background:rgba(209,144,75,.1); border:1px solid rgba(209,144,75,.2); padding:3px 10px; border-radius:20px; margin-top:6px; }
</style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="topbar">
    <div class="topbar-brand">
        <a href="dashboard.php" class="btn-nav icon-only" title="Back to dashboard"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="brand-icon"><i class="fa-solid fa-users"></i></div>
        <div class="brand-text">
            <span class="brand-title">Employee Management</span>
            <span class="brand-sub">Bird's Nest Coffee &rsaquo; Admin</span>
        </div>
    </div>
    <div class="topbar-sep"></div>
    <div class="topbar-center">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="searchInput" placeholder="Search by name or job title…" autocomplete="off">
            <button id="clearSearch" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
            <span class="kbd">/</span>
        </div>
    </div>
    <div class="topbar-right">
        <button class="btn-nav" onclick="exportCSV()" title="Export to CSV"><i class="fa-solid fa-file-csv"></i> Export</button>
        <a href="employee_add.php" class="btn-nav primary"><i class="fa-solid fa-plus"></i> Add Employee</a>
        <button class="btn-nav icon-only" onclick="toggleTheme()" id="themeBtn" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<!-- ── STATS ── -->
<div class="stats-row">
    <div class="stat-card s-a">
        <div class="stat-icon si-a"><i class="fa-solid fa-users"></i></div>
        <div>
            <div class="stat-label">Total Staff</div>
            <div class="stat-num" data-target="<?= $total_staff ?>"><?= $total_staff ?></div>
            <div class="stat-hint">Employees on record</div>
        </div>
    </div>
    <div class="stat-card s-b">
        <div class="stat-icon si-b"><i class="fa-solid fa-receipt"></i></div>
        <div>
            <div class="stat-label">Orders This Month</div>
            <div class="stat-num" data-target="<?= $total_this_month ?>"><?= $total_this_month ?></div>
            <div class="stat-hint"><?= fmtnum($total_orders_all) ?> all-time</div>
        </div>
    </div>
    <div class="stat-card s-c">
        <div class="stat-icon si-c"><i class="fa-solid fa-trophy"></i></div>
        <div>
            <div class="stat-label">Top This Month</div>
            <div class="stat-num" style="font-size:14px;font-weight:700;line-height:1.4;margin-top:2px">
                <?= $top_month ? h($top_month['name']) : '—' ?>
            </div>
            <div class="stat-hint"><?= $top_month ? fmtnum($top_month['orders_this_month']) . ' orders' : 'No data yet' ?></div>
        </div>
    </div>
    <?php if ($sess_role === 'admin'): ?>
    <div class="stat-card s-d">
        <div class="stat-icon si-d"><i class="fa-solid fa-dollar-sign"></i></div>
        <div>
            <div class="stat-label">Revenue Served</div>
            <div class="stat-num" data-target="<?= round($total_revenue, 2) ?>" data-prefix="$" data-dec="0">$<?= fmtnum($total_revenue) ?></div>
            <div class="stat-hint">All-time, all staff</div>
        </div>
    </div>
    <?php else: ?>
    <div class="stat-card s-d">
        <div class="stat-icon si-d"><i class="fa-solid fa-mug-hot"></i></div>
        <div>
            <div class="stat-label">All-Time Orders</div>
            <div class="stat-num" data-target="<?= $total_orders_all ?>"><?= fmtnum($total_orders_all) ?></div>
            <div class="stat-hint">By all staff</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($leaderboard) && $total_orders_all > 0): ?>
<!-- ── LEADERBOARD ── -->
<div class="section-label"><i class="fa-solid fa-ranking-star" style="color:var(--gold)"></i> All-Time Leaderboard</div>
<div class="leaderboard">
<?php
$medals = ['rank-1','rank-2','rank-3'];
$rbadge = ['r1','r2','r3'];
$rnums  = ['1','2','3'];
foreach ($leaderboard as $i => $emp):
    if ((int)$emp['total_orders'] === 0) continue;
    $color = avatarColor($emp['name']);
    $hasPhoto = !empty($emp['photo']) && file_exists($emp['photo']);
?>
    <div class="podium-card <?= $medals[$i] ?>" data-podium-eid="<?= (int)$emp['employee_id'] ?>">
        <?php if ($i === 0): ?><span class="top-badge"><i class="fa-solid fa-crown"></i> Top Performer</span><?php endif; ?>
        <div class="rank-badge <?= $rbadge[$i] ?>"><?= $rnums[$i] ?></div>
        <?php if ($hasPhoto): ?>
            <img src="<?= h($emp['photo']) ?>" class="podium-img" alt="<?= h($emp['name']) ?>">
        <?php else: ?>
            <div class="podium-avatar" style="background:<?= $color ?>">
                <?= initials($emp['name']) ?>
            </div>
        <?php endif; ?>
        <div class="podium-name"><?= h($emp['name']) ?></div>
        <?php
        $shiftMeta = ['morning'=>['#f39c12','fa-sun','Morning'],'afternoon'=>['#3498db','fa-cloud-sun','Afternoon'],'night'=>['#9b59b6','fa-moon','Night']];
        if (!empty($emp['shift']) && isset($shiftMeta[$emp['shift']])):
            [$sc,$si,$sl] = $shiftMeta[$emp['shift']];
        ?>
        <div class="podium-title"><?= h($emp['job_title'] ?? '—') ?> <span style="color:<?= $sc ?>;font-size:9px;opacity:.85"><i class="fa-solid <?= $si ?>"></i> <?= $sl ?></span></div>
        <?php else: ?>
        <div class="podium-title"><?= h($emp['job_title'] ?? '—') ?></div>
        <?php endif; ?>
        <div class="podium-stats">
            <div class="podium-stat">
                <div class="ps-val ps-orders"><?= fmtnum($emp['total_orders']) ?></div>
                <div class="ps-lbl">Orders</div>
            </div>
            <?php if ($sess_role === 'admin'): ?>
            <div class="podium-stat ps-rev-wrap" <?= $emp['total_revenue'] <= 0 ? 'style="display:none"' : '' ?>>
                <div class="ps-val ps-revenue" style="color:var(--ok)">$<?= fmtnum($emp['total_revenue']) ?></div>
                <div class="ps-lbl">Revenue</div>
            </div>
            <?php endif; ?>
            <div class="podium-stat">
                <div class="ps-val ps-month" style="color:var(--blue)"><?= fmtnum($emp['orders_this_month']) ?></div>
                <div class="ps-lbl">This Mo.</div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── CONTROLS BAR ── -->
<div class="controls-bar">
    <button class="filter-pill active" data-filter="all" onclick="setFilter(this,'all')">
        <i class="fa-solid fa-layer-group"></i> All <span class="pill-count"><?= $total_staff ?></span>
    </button>
    <button class="filter-pill" data-filter="top" onclick="setFilter(this,'top')">
        <i class="fa-solid fa-fire"></i> Active <span class="pill-count"><?= count(array_filter($employees, fn($e) => (int)$e['total_orders'] > 0)) ?></span>
    </button>
    <button class="filter-pill" data-filter="idle" onclick="setFilter(this,'idle')">
        <i class="fa-solid fa-moon"></i> No Orders <span class="pill-count"><?= count(array_filter($employees, fn($e) => (int)$e['total_orders'] === 0)) ?></span>
    </button>
    <?php foreach ($_roles_db as $rslug => $rdata):
        $rc = $role_counts_emp[$rslug] ?? 0; ?>
    <button class="filter-pill <?= $rc === 0 ? 'role-empty' : '' ?>" data-filter="<?= $rslug ?>" onclick="setFilter(this,'<?= $rslug ?>')">
        <i class="fa-solid <?= htmlspecialchars($rdata['icon']) ?>"></i>
        <?= htmlspecialchars($rdata['name']) ?>
        <span class="pill-count"><?= $rc ?></span>
    </button>
    <?php endforeach; ?>
    <div class="shift-sep"></div>
    <button class="filter-pill shift-pill active-shift shift-active" data-shift-filter="" onclick="setShiftFilter(this,'')">
        <i class="fa-solid fa-clock-rotate-left"></i> All Shifts
    </button>
    <button class="filter-pill shift-pill" data-shift-filter="morning" onclick="setShiftFilter(this,'morning')" style="--sb-active:#f39c12">
        <i class="fa-solid fa-sun"></i> Morning <span class="pill-count"><?= $shift_counts['morning'] ?></span>
    </button>
    <button class="filter-pill shift-pill" data-shift-filter="afternoon" onclick="setShiftFilter(this,'afternoon')" style="--sb-active:#3498db">
        <i class="fa-solid fa-cloud-sun"></i> Afternoon <span class="pill-count"><?= $shift_counts['afternoon'] ?></span>
    </button>
    <button class="filter-pill shift-pill" data-shift-filter="night" onclick="setShiftFilter(this,'night')" style="--sb-active:#9b59b6">
        <i class="fa-solid fa-moon"></i> Night <span class="pill-count"><?= $shift_counts['night'] ?></span>
    </button>
    <div class="ctrl-right">
        <span class="row-count" id="rowCount">Showing <?= $total_staff ?> of <?= $total_staff ?></span>
        <button class="compact-btn" id="compactBtn" onclick="toggleCompact()">
            <i class="fa-solid fa-bars"></i> Compact
        </button>
    </div>
</div>

<!-- ── TABLE ── -->
<div class="table-card">
    <div class="table-wrap">
        <table id="empTable">
            <thead>
                <tr>
                    <th style="width:44px;text-align:center;cursor:default">#</th>
                    <th onclick="sortTable(1)">Employee <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(2)">Role <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(3)">All-Time Orders <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(4)">This Month <i class="fa-solid fa-sort si"></i></th>
                    <?php if ($sess_role === 'admin'): ?>
                    <th onclick="sortTable(5)" class="hide-mob">Revenue <i class="fa-solid fa-sort si"></i></th>
                    <?php endif; ?>
                    <th onclick="sortTable(<?= $sess_role==='admin'?6:5 ?>)" class="hide-mob">Tenure <i class="fa-solid fa-sort si"></i></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
<?php
$currentGroup = null;
foreach ($sorted_employees as $idx => $emp):
    $color   = avatarColor($emp['name']);
    $hasPhoto = !empty($emp['photo']) && file_exists($emp['photo']);
    $rankCls  = $idx === 0 ? 'r1' : ($idx === 1 ? 'r2' : ($idx === 2 ? 'r3' : ''));
    $pct      = $max_orders > 0 ? min(100, round((int)$emp['total_orders'] / $max_orders * 100)) : 0;
    $empRole  = $emp['emp_role'] ?? 'staff';
    $eid      = (int)$emp['employee_id'];
    $hasOrders = (int)$emp['total_orders'] > 0;
    if ($currentGroup !== $empRole):
        $currentGroup = $empRole;
        $_grInfo = $_roles_db[$empRole] ?? ['icon' => 'fa-user', 'name' => ucfirst($empRole), 'color' => '#888'];
        $gIcon   = $_grInfo['icon'];
        $gName   = $_grInfo['name'];
        $gColor  = $_grInfo['color'] ?? '#888';
        $gCount  = $role_counts_emp[$empRole] ?? 0;
?>
                <tr class="group-sep" data-group="<?= $empRole ?>">
                    <td colspan="99" style="--gs-color:<?= $gColor ?>">
                        <span class="gc">
                            <i class="fa-solid <?= $gIcon ?>"></i>
                            <?= htmlspecialchars($gName) ?>
                            <span class="gcnt"><?= $gCount ?></span>
                        </span>
                    </td>
                </tr>
<?php endif; ?>
                <tr data-name="<?= h(strtolower($emp['name'])) ?>"
                    data-title="<?= h(strtolower($emp['job_title'] ?? '')) ?>"
                    data-orders="<?= (int)$emp['total_orders'] ?>"
                    data-role="<?= h($empRole) ?>"
                    data-shift="<?= h($emp['shift'] ?? '') ?>"
                    data-id="<?= $eid ?>">
                    <td>
                        <div class="rank-cell <?= $rankCls ?>">
                            <?php if ($idx < 3 && $hasOrders): ?>
                                <i class="fa-solid fa-medal"></i>
                            <?php else: ?>
                                <?= $idx + 1 ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-val="<?= h($emp['name']) ?>">
                        <div class="emp-cell">
                            <?php if ($hasPhoto): ?>
                                <img src="<?= h($emp['photo']) ?>" class="avatar-img" alt="<?= h($emp['name']) ?>">
                            <?php else: ?>
                                <div class="avatar" style="background:<?= $color ?>"><?= initials($emp['name']) ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="emp-name"><?= h($emp['name']) ?></div>
                                <div class="emp-title">
                                    <?= h($emp['job_title'] ?? '—') ?>
                                    <?php if (!empty($emp['shift'])):
                                        $shiftMeta = ['morning'=>['#f39c12','fa-sun','Morning'],'afternoon'=>['#3498db','fa-cloud-sun','Afternoon'],'night'=>['#9b59b6','fa-moon','Night']];
                                        [$sc,$si,$sl] = $shiftMeta[$emp['shift']] ?? ['#888','fa-clock',ucfirst($emp['shift'])];
                                    ?>
                                    <span class="shift-inline" style="color:<?= $sc ?>;font-size:9px;margin-left:4px;opacity:.9"><i class="fa-solid <?= $si ?>"></i> <?= $sl ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td data-val="<?= h($empRole) ?>">
                        <?php
                            $_role_exists = isset($_roles_db[$empRole]);
                            $_rinfo  = $_roles_db[$empRole] ?? ['name' => ucfirst($empRole), 'icon' => 'fa-user', 'color' => '#888'];
                            $_rbcol  = $_rinfo['color'] ?? '#888';
                        ?>
                        <?php if (!$_role_exists && $empRole !== 'admin'): ?>
                        <div class="role-wrap editable" data-eid="<?= $eid ?>" data-current="<?= h($empRole) ?>"
                             style="--rb-bg:#e74c3c1a;--rb-color:#e74c3c;--rb-border:#e74c3c33" title="Role '<?= h($empRole) ?>' no longer exists — please reassign">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <select class="role-select" onchange="updateRole(<?= $eid ?>, this)" title="Reassign role" style="color:#e74c3c">
                                <option value="" disabled selected>Reassign…</option>
                                <?php foreach ($_roles_db as $_rs => $_ri): if ($_rs === 'admin' && ($_SESSION['role'] ?? '') !== 'admin') continue; ?>
                                <option value="<?= h($_rs) ?>"><?= h($_ri['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif ($empRole === 'admin'): ?>
                        <span class="role-wrap" style="--rb-bg:<?= $_rbcol ?>1a;--rb-color:<?= $_rbcol ?>;--rb-border:<?= $_rbcol ?>33">
                            <i class="fa-solid <?= htmlspecialchars($_rinfo['icon']) ?>"></i>
                            <?= htmlspecialchars($_rinfo['name']) ?>
                        </span>
                        <?php else: ?>
                        <div class="role-wrap editable" data-eid="<?= $eid ?>" data-current="<?= h($empRole) ?>"
                             style="--rb-bg:<?= $_rbcol ?>1a;--rb-color:<?= $_rbcol ?>;--rb-border:<?= $_rbcol ?>33">
                            <i class="fa-solid <?= htmlspecialchars($_rinfo['icon']) ?>"></i>
                            <select class="role-select" onchange="updateRole(<?= $eid ?>, this)" title="Change role">
                                <?php foreach ($_roles_db as $_rs => $_ri): if ($_rs === 'admin' && ($_SESSION['role'] ?? '') !== 'admin') continue; ?>
                                <option value="<?= h($_rs) ?>" <?= $empRole === $_rs ? 'selected' : '' ?>><?= h($_ri['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td data-val="<?= (int)$emp['total_orders'] ?>" data-stat-orders="<?= $eid ?>">
                        <div class="perf-cell">
                            <div class="perf-top">
                                <span class="perf-num <?= !$hasOrders ? 'num-zero' : '' ?>"><?= fmtnum($emp['total_orders']) ?></span>
                            </div>
                            <div class="perf-bar"><div class="perf-fill" style="width:<?= $pct ?>%"></div></div>
                            <?php if ($hasOrders): ?>
                            <div class="perf-sub">avg $<?= money($emp['avg_order_value']) ?> / order</div>
                            <?php else: ?>
                            <div class="perf-sub">No orders yet</div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-val="<?= (int)$emp['orders_this_month'] ?>" data-stat-month="<?= $eid ?>">
                        <span class="num-cell <?= (int)$emp['orders_this_month'] === 0 ? 'num-zero' : '' ?>">
                            <?= fmtnum($emp['orders_this_month']) ?>
                        </span>
                        <?php if ((int)$emp['orders_today'] > 0): ?>
                        <div class="today-badge" style="font-size:10px;color:var(--ok);margin-top:2px">+<?= (int)$emp['orders_today'] ?> today</div>
                        <?php endif; ?>
                    </td>
                    <?php if ($sess_role === 'admin'): ?>
                    <td data-val="<?= round((float)$emp['total_revenue'], 2) ?>" data-stat-rev="<?= $eid ?>" class="hide-mob">
                        <span class="revenue <?= (float)$emp['total_revenue'] <= 0 ? 'num-zero' : '' ?>">
                            $<?= money($emp['total_revenue']) ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td data-val="<?= h($emp['hire_date'] ?? '') ?>" class="hide-mob">
                        <div class="tenure-cell">
                            <i class="fa-solid fa-clock" style="font-size:11px"></i>
                            <?= tenure($emp['hire_date'] ?? '') ?>
                        </div>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn-row view" href="employee_view.php?id=<?= $eid ?>">
                                <i class="fa-regular fa-eye"></i> View
                            </a>
                            <button class="btn-row edit" onclick="openEditModal(<?= $eid ?>)">
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </button>
                            <?php if ($sess_role === 'admin'): ?>
                            <button class="btn-row del" onclick="confirmDelete(<?= $eid ?>,'<?= h($emp['name']) ?>')" title="Delete">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
            <tfoot id="tableFoot">
                <tr>
                    <td colspan="3" id="footLabel" style="color:var(--text-muted);font-size:11px;font-weight:700">
                        <?= $total_staff ?> employees
                    </td>
                    <td id="footOrders" class="foot-val"><?= fmtnum($total_orders_all) ?> total</td>
                    <td id="footMonth"><?= fmtnum($total_this_month) ?> this mo.</td>
                    <?php if ($sess_role === 'admin'): ?>
                    <td id="footRevenue" class="foot-val hide-mob">$<?= money($total_revenue) ?></td>
                    <?php endif; ?>
                    <td class="hide-mob"></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <div class="empty-state" id="emptyState" style="display:none">
            <div class="ei"><i class="fa-solid fa-users"></i></div>
            <h3>No employees found</h3>
            <p>Try adjusting your search or filter.</p>
            <button class="btn-nav" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset filters</button>
        </div>
    </div>
</div>

<!-- ── DELETE MODAL ── -->
<div class="modal-overlay" id="deleteModal" onclick="if(event.target===this)closeDelete()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeDelete()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-triangle-exclamation"></i> Delete Employee</div>
        <div class="modal-sub">This will permanently remove the employee record. Order history is preserved.</div>
        <div class="modal-row">
            <span class="ml">Employee</span>
            <span class="mv" id="deleteName">—</span>
        </div>
        <input type="hidden" id="deleteId">
        <a id="deleteLink" href="#" class="btn-danger">
            <i class="fa-solid fa-trash-can"></i> Delete Permanently
        </a>
        <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
    </div>
</div>

<div id="toast-cnt"></div>

<div class="sc-bar">
    <span><span class="sc-key">/</span> Search</span>
    <span><span class="sc-key">N</span> New employee</span>
    <span><span class="sc-key">Esc</span> Close</span>
</div>

<script>
const TOTAL = <?= $total_staff ?>;
const ROLES_INFO = <?= json_encode(array_combine(
    array_keys($_roles_db),
    array_map(fn($r) => ['name' => $r['name'], 'icon' => $r['icon'], 'color' => $r['color'] ?? '#888'], $_roles_db)
)) ?>;
let currentFilter = 'all';
let currentShiftFilter = '';
let isCompact = false;

/* ── COUNT-UP ── */
function countUp() {
    document.querySelectorAll('.stat-num[data-target]').forEach(el => {
        const target = parseFloat(el.dataset.target);
        const prefix = el.dataset.prefix || '';
        const dec    = parseInt(el.dataset.dec || 0);
        if (isNaN(target)) return;
        const dur = 700, start = performance.now();
        function frame(now) {
            const t    = Math.min((now - start) / dur, 1);
            const ease = 1 - Math.pow(1 - t, 3);
            const val  = target * ease;
            el.textContent = prefix + (dec
                ? Math.round(val).toLocaleString()
                : Math.round(val).toLocaleString());
            if (t < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    });
}

/* ── SEARCH ── */
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 150);
    document.getElementById('clearSearch').classList.toggle('vis', this.value.length > 0);
});
function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('clearSearch').classList.remove('vis');
    applyFilters();
    document.getElementById('searchInput').focus();
}
function resetFilters() {
    clearSearch();
    setFilter(document.querySelector('[data-filter="all"]'), 'all');
}

/* ── FILTER ── */
const ROLE_FILTERS = <?= json_encode(array_keys($_roles_db)) ?>;
function setFilter(btn, filter) {
    currentFilter = filter;
    document.querySelectorAll('.filter-pill:not(.shift-pill)').forEach(p => {
        p.className = 'filter-pill';
        if (p.dataset.filter === filter) {
            if (filter === 'all') p.classList.add('active');
            else p.classList.add('active-' + filter);
        }
    });
    applyFilters();
}
function setShiftFilter(btn, shift) {
    currentShiftFilter = (shift !== '' && currentShiftFilter === shift) ? '' : shift;
    document.querySelectorAll('.shift-pill').forEach(p => {
        p.classList.remove('active-shift','shift-active');
        if (p.dataset.shiftFilter === currentShiftFilter) p.classList.add('active-shift','shift-active');
    });
    applyFilters();
}

/* ── APPLY FILTERS ── */
function applyFilters() {
    const q    = document.getElementById('searchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableBody tr[data-name]');
    let shown  = 0, shownOrders = 0, shownMonth = 0;
    rows.forEach(row => {
        const nameOk = !q || row.dataset.name.includes(q) || row.dataset.title.includes(q);
        let filtOk;
        if (currentFilter === 'all')            filtOk = true;
        else if (currentFilter === 'top')       filtOk = parseInt(row.dataset.orders) > 0;
        else if (currentFilter === 'idle')      filtOk = parseInt(row.dataset.orders) === 0;
        else if (ROLE_FILTERS.includes(currentFilter)) filtOk = row.dataset.role === currentFilter;
        else filtOk = true;
        const shiftOk = !currentShiftFilter || row.dataset.shift === currentShiftFilter;
        const show = nameOk && filtOk && shiftOk;
        row.classList.toggle('hidden', !show);
        if (show) {
            shown++;
            shownOrders += parseInt(row.children[3]?.dataset?.val) || 0;
            shownMonth  += parseInt(row.children[4]?.dataset?.val) || 0;
        }
    });
    // Show/hide group separators based on whether their group has visible rows
    document.querySelectorAll('.group-sep').forEach(sep => {
        let next = sep.nextElementSibling;
        let hasVisible = false;
        while (next && !next.classList.contains('group-sep')) {
            if (!next.classList.contains('hidden')) { hasVisible = true; break; }
            next = next.nextElementSibling;
        }
        sep.classList.toggle('hidden', !hasVisible);
    });
    document.getElementById('emptyState').style.display = shown === 0 ? 'block' : 'none';
    document.getElementById('rowCount').textContent   = `Showing ${shown} of ${TOTAL}`;
    document.getElementById('footLabel').textContent  = `${shown} employee${shown !== 1 ? 's' : ''}`;
    document.getElementById('footOrders').textContent = shownOrders.toLocaleString() + ' total';
    document.getElementById('footMonth').textContent  = shownMonth.toLocaleString() + ' this mo.';
}

/* ── SORT ── */
let sortDir = {};
function sortTable(col) {
    const tbody = document.getElementById('tableBody');
    const seps  = [...tbody.querySelectorAll('tr.group-sep')];
    const asc   = sortDir[col] !== 'asc';
    sortDir = {}; sortDir[col] = asc ? 'asc' : 'desc';
    document.querySelectorAll('th').forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const ic = th.querySelector('.si');
        if (ic) ic.className = i === col
            ? `fa-solid ${asc ? 'fa-sort-up' : 'fa-sort-down'} si`
            : 'fa-solid fa-sort si';
    });
    // Sort within each group, keeping group separators in place
    seps.forEach(sep => {
        const group = [];
        let next = sep.nextElementSibling;
        while (next && !next.classList.contains('group-sep')) {
            group.push(next);
            next = next.nextElementSibling;
        }
        group.sort((a, b) => {
            const av = a.children[col]?.dataset?.val ?? a.children[col]?.textContent?.trim() ?? '';
            const bv = b.children[col]?.dataset?.val ?? b.children[col]?.textContent?.trim() ?? '';
            const an = parseFloat(av), bn = parseFloat(bv);
            if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
            return asc ? av.localeCompare(bv) : bv.localeCompare(av);
        });
        const anchor = sep;
        group.forEach((r, i) => {
            if (i === 0) anchor.after(r);
            else group[i - 1].after(r);
        });
    });
}

/* ── COMPACT ── */
function toggleCompact() {
    isCompact = !isCompact;
    document.querySelectorAll('#tableBody tr[data-name]').forEach(r => r.classList.toggle('compact', isCompact));
    document.getElementById('compactBtn').classList.toggle('on', isCompact);
}

/* ── DELETE CONFIRM ── */
function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteLink').href = `employee_delete.php?id=${id}`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() { document.getElementById('deleteModal').classList.remove('open'); }

/* ── EXPORT CSV ── */
function exportCSV() {
    const headers = ['Rank','Name','Job Title','Role','Total Orders','This Month','Tenure'];
    const rows = [];
    document.querySelectorAll('#tableBody tr[data-name]:not(.hidden):not(.group-sep)').forEach((tr, i) => {
        const c = tr.querySelectorAll('td');
        rows.push([
            i + 1,
            c[1]?.querySelector('.emp-name')?.textContent?.trim() || '',
            c[1]?.querySelector('.emp-title')?.textContent?.trim() || '',
            c[2]?.querySelector('.role-wrap')?.textContent?.trim().replace(/\s+/g,' ') || '',
            c[3]?.dataset?.val || '0',
            c[4]?.dataset?.val || '0',
            c[c.length - 2]?.textContent?.trim().replace(/\s+/g,' ') || '',
        ]);
    });
    const csv  = [headers, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `employees_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
    showToast(`Exported ${rows.length} employee${rows.length !== 1 ? 's' : ''}`, 'success');
}

/* ── TOAST ── */
function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const col = type === 'success' ? 'var(--ok)' : 'var(--danger)';
    const ico = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    t.innerHTML = `<i class="fa-solid ${ico}" style="color:${col};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3500);
}

/* ── INLINE ROLE UPDATE ── */
/* ── ROLE CHANGE HELPERS ── */
function adjustPillCount(slug, delta) {
    const pill = document.querySelector(`.filter-pill[data-filter="${slug}"]`);
    if (!pill) return;
    const cnt = pill.querySelector('.pill-count');
    if (!cnt) return;
    const n = Math.max(0, (parseInt(cnt.textContent) || 0) + delta);
    cnt.textContent = n;
    pill.classList.toggle('role-empty', n === 0);
}

function recalcShiftCounts() {
    const counts = { morning: 0, afternoon: 0, night: 0 };
    document.querySelectorAll('#tableBody tr[data-id]').forEach(r => {
        const s = r.dataset.shift;
        if (s && counts[s] !== undefined) counts[s]++;
    });
    ['morning', 'afternoon', 'night'].forEach(s => {
        const pill = document.querySelector(`.shift-pill[data-shift-filter="${s}"]`);
        if (pill) { const cnt = pill.querySelector('.pill-count'); if (cnt) cnt.textContent = counts[s]; }
    });
}

function getOrCreateGroupSep(tbody, role, info) {
    let sep = tbody.querySelector(`tr.group-sep[data-group="${role}"]`);
    if (sep) return sep;
    const color = info?.color || '#888';
    sep = document.createElement('tr');
    sep.className = 'group-sep';
    sep.dataset.group = role;
    sep.innerHTML = `<td colspan="99" style="--gs-color:${color}"><span class="gc"><i class="fa-solid ${info?.icon || 'fa-user'}"></i> ${info?.name || role} <span class="gcnt">0</span></span></td>`;
    const order = Object.keys(ROLES_INFO);
    const ni    = order.indexOf(role);
    const after = [...tbody.querySelectorAll('tr.group-sep')].find(s => order.indexOf(s.dataset.group) > ni);
    after ? tbody.insertBefore(sep, after) : tbody.appendChild(sep);
    return sep;
}

function moveRowToGroup(row, prevRole, newRole, newInfo) {
    const tbody = document.getElementById('tableBody');
    // Decrement old separator count
    const oldSep = tbody.querySelector(`tr.group-sep[data-group="${prevRole}"]`);
    if (oldSep) {
        const c = oldSep.querySelector('.gcnt');
        if (c) c.textContent = Math.max(0, (parseInt(c.textContent) || 0) - 1);
    }
    // Find / create new separator and increment
    const newSep = getOrCreateGroupSep(tbody, newRole, newInfo);
    const nc = newSep.querySelector('.gcnt');
    if (nc) nc.textContent = (parseInt(nc.textContent) || 0) + 1;
    // Move row to end of new group
    let anchor = newSep;
    let next   = newSep.nextElementSibling;
    while (next && !next.classList.contains('group-sep')) { anchor = next; next = next.nextElementSibling; }
    anchor.after(row);
}

async function updateRole(eid, sel) {
    const newRole = sel.value;
    const wrap    = sel.closest('.role-wrap');
    const prev    = wrap.dataset.current;
    if (newRole === prev) return;
    try {
        const fd = new FormData();
        fd.append('action', 'quick_role');
        fd.append('eid', eid);
        fd.append('role', newRole);
        const res = await fetch('employees.php', { method:'POST', body:fd });
        const j   = await res.json();
        if (j.ok) {
            const info  = ROLES_INFO[newRole];
            const color = info?.color || '#888';
            // Update badge
            wrap.style.setProperty('--rb-bg',     color + '1a');
            wrap.style.setProperty('--rb-color',  color);
            wrap.style.setProperty('--rb-border', color + '33');
            const icon = wrap.querySelector('i');
            if (icon && info?.icon) icon.className = `fa-solid ${info.icon}`;
            wrap.dataset.current = newRole;
            // Update row + move to correct group
            const row = wrap.closest('tr[data-role]');
            if (row) {
                row.dataset.role = newRole;
                moveRowToGroup(row, prev, newRole, info);
            }
            // Update filter pill counts
            adjustPillCount(prev, -1);
            adjustPillCount(newRole, +1);
            // Re-apply filters to handle visibility correctly
            applyFilters();
            showToast(`Role changed to ${info?.name || newRole}`, 'success');
        } else {
            sel.value = prev;
            showToast(j.msg || 'Failed to update role', 'error');
        }
    } catch(e) {
        sel.value = prev;
        showToast('Network error', 'error');
    }
}

/* ── THEME ── */
function toggleTheme() {
    const html  = document.documentElement;
    const icon  = document.getElementById('themeIcon');
    const light = html.getAttribute('data-theme') === 'light';
    if (light) { html.removeAttribute('data-theme'); icon.className='fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else        { html.setAttribute('data-theme','light'); icon.className='fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}

/* ── KEYBOARD ── */
document.addEventListener('keydown', e => {
    const tag = document.activeElement?.tagName;
    const inField = tag === 'INPUT' || tag === 'TEXTAREA';
    if (e.key === 'Escape') {
        if (document.getElementById('editOverlay').classList.contains('open')) { closeEditModal(); return; }
        closeDelete(); return;
    }
    if (inField) return;
    if (e.key === '/' || e.key === 'f') { e.preventDefault(); document.getElementById('searchInput').focus(); }
    if (e.key === 'n' || e.key === 'N') window.location.href = 'employee_add.php';
});

/* ── TABLE HEIGHT (fill remaining viewport) ── */
function resizeTable() {
    const wrap = document.querySelector('.table-wrap');
    if (!wrap) return;
    const top = wrap.getBoundingClientRect().top + window.scrollY;
    const newMax = window.innerHeight - top - 32;
    wrap.style.maxHeight = Math.max(200, newMax) + 'px';
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light')
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
    countUp();
    resizeTable();
});
window.addEventListener('resize', resizeTable);
</script>
<script src="animations.js"></script>

<!-- ── EDIT EMPLOYEE MODAL ── -->
<div class="em-overlay" id="editOverlay" onclick="if(event.target===this)closeEditModal()">
  <div class="em-panel" role="dialog" aria-modal="true" aria-labelledby="emTitle">

    <div class="em-header">
      <label class="em-avatar-wrap" for="emPhotoInput" title="Change photo">
        <img src="" class="em-avatar" id="emAvatar" alt="" style="display:none">
        <div class="em-avatar-fallback" id="emAvatarFb"></div>
        <div class="em-avatar-overlay"><i class="fa-solid fa-camera"></i></div>
        <input type="file" id="emPhotoInput" accept="image/*" style="display:none">
      </label>
      <div class="em-title">
        <h3 id="emTitle">Edit Employee</h3>
        <p id="emSubtitle" style="color:var(--text-muted);font-size:12px"></p>
        <div class="em-photo-pill" id="emPhotoPill"><i class="fa-solid fa-image"></i><span id="emPhotoName"></span></div>
      </div>
      <button class="em-close" onclick="closeEditModal()" title="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form id="emForm" class="em-body" onsubmit="submitEditForm(event)">
      <input type="hidden" id="emEid" name="eid">
      <input type="hidden" name="action" value="edit_employee">

      <div class="em-grid">
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-user"></i> Full Name</div>
          <input class="em-input" type="text" name="name" id="emName" required placeholder="Employee name">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-phone"></i> Phone Number</div>
          <input class="em-input" type="text" name="phone" id="emPhone" placeholder="Phone number">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-briefcase"></i> Job Title</div>
          <input class="em-input" type="text" name="job_title" id="emJob" required placeholder="e.g. Barista">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-dollar-sign"></i> Salary</div>
          <input class="em-input" type="number" step="0.01" name="salary" id="emSalary" placeholder="0.00">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-calendar"></i> Date of Birth</div>
          <input class="em-input" type="date" name="date_of_birth" id="emDob">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-calendar-check"></i> Hire Date</div>
          <input class="em-input" type="date" name="hire_date" id="emHire">
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-location-dot"></i> Address</div>
          <textarea class="em-input" name="address" id="emAddress" rows="3" placeholder="Employee address"></textarea>
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-shield-halved"></i> Role</div>
          <div class="em-role-grid" id="emRoleGrid">
            <?php foreach ($_roles_db as $rslug => $rdata):
                if ($rslug === 'admin' && ($_SESSION['role'] ?? '') !== 'admin') continue;
                $rc = $rdata['color'] ?? '#888';
            ?>
            <input type="radio" class="em-role-opt" name="emp_role"
                   id="emRole_<?= $rslug ?>" value="<?= h($rslug) ?>">
            <label class="em-role-label" for="emRole_<?= $rslug ?>"
                   style="--erc:<?= $rc ?>;--erc-bg:<?= $rc ?>22;--erc-glow:<?= $rc ?>26">
              <div class="em-role-icon"><i class="fa-solid <?= htmlspecialchars($rdata['icon']) ?>"></i></div>
              <span class="em-role-name"><?= htmlspecialchars($rdata['name']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-clock"></i> Shift</div>
          <div class="em-role-grid" id="emShiftGrid">
            <input type="radio" class="em-role-opt" name="shift" id="emShift_none" value="">
            <label class="em-role-label" for="emShift_none" style="--erc:#888;--erc-bg:#88888822;--erc-glow:#88888826">
              <div class="em-role-icon"><i class="fa-solid fa-ban"></i></div>
              <span class="em-role-name">None</span>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="emShift_morning" value="morning">
            <label class="em-role-label" for="emShift_morning" style="--erc:#f39c12;--erc-bg:#f39c1222;--erc-glow:#f39c1226">
              <div class="em-role-icon"><i class="fa-solid fa-sun"></i></div>
              <span class="em-role-name">Morning</span>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="emShift_afternoon" value="afternoon">
            <label class="em-role-label" for="emShift_afternoon" style="--erc:#3498db;--erc-bg:#3498db22;--erc-glow:#3498db26">
              <div class="em-role-icon"><i class="fa-solid fa-cloud-sun"></i></div>
              <span class="em-role-name">Afternoon</span>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="emShift_night" value="night">
            <label class="em-role-label" for="emShift_night" style="--erc:#9b59b6;--erc-bg:#9b59b622;--erc-glow:#9b59b626">
              <div class="em-role-icon"><i class="fa-solid fa-moon"></i></div>
              <span class="em-role-name">Night</span>
            </label>
          </div>
        </div>
      </div>

      <!-- footer inside form so submit button works -->
      <div class="em-footer" style="padding:16px 0 0; margin-top:16px; border-top:1px solid var(--border);">
        <button type="button" class="em-btn em-btn-cancel" onclick="closeEditModal()">
          <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="submit" class="em-btn em-btn-save" id="emSaveBtn">
          <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
      </div>
    </form>

  </div>
</div>

<script>
/* ── EDIT MODAL ── */
let _emPhotoFile = null;

function openEditModal(eid) {
    _emPhotoFile = null;
    document.getElementById('emPhotoPill').style.display = 'none';
    document.getElementById('emPhotoInput').value = '';
    const btn = document.getElementById('emSaveBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';

    fetch(`employees.php?action=get_employee&eid=${eid}`)
        .then(r => r.json())
        .then(j => {
            if (!j.ok) { showToast('Could not load employee data', 'error'); return; }
            const emp = j.emp;

            document.getElementById('emEid').value    = emp.employee_id;
            document.getElementById('emName').value   = emp.name || '';
            document.getElementById('emPhone').value  = emp.phone || '';
            document.getElementById('emJob').value    = emp.job_title || '';
            document.getElementById('emSalary').value = emp.salary || '';
            document.getElementById('emDob').value    = emp.date_of_birth || '';
            document.getElementById('emHire').value   = emp.hire_date || '';
            document.getElementById('emAddress').value= emp.address || '';

            // Avatar
            const img = document.getElementById('emAvatar');
            const fb  = document.getElementById('emAvatarFb');
            if (emp.photo) {
                img.src = emp.photo;
                img.style.display = 'block';
                fb.style.display  = 'none';
            } else {
                img.style.display = 'none';
                fb.style.display  = 'flex';
                fb.textContent    = (emp.name || '?')[0].toUpperCase();
            }

            // Title
            document.getElementById('emTitle').textContent   = emp.name || 'Edit Employee';
            document.getElementById('emSubtitle').textContent= emp.job_title || '';

            // Role
            const role = emp.emp_role || 'staff';
            const rb = document.querySelector(`#emRoleGrid input[value="${role}"]`);
            if (rb) rb.checked = true;
            else document.querySelectorAll('#emRoleGrid input[type="radio"]').forEach(r => r.checked = false);

            // Shift
            const shift = emp.shift || '';
            const sb = document.querySelector(`#emShiftGrid input[value="${shift}"]`);
            if (sb) sb.checked = true;
            else { const ns = document.getElementById('emShift_none'); if (ns) ns.checked = true; }

            document.getElementById('editOverlay').classList.add('open');
        })
        .catch(() => showToast('Network error', 'error'));
}

function closeEditModal() {
    document.getElementById('editOverlay').classList.remove('open');
    const btn = document.getElementById('emSaveBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
}

document.getElementById('emPhotoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    _emPhotoFile = file;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('emAvatar');
        const fb  = document.getElementById('emAvatarFb');
        img.src = e.target.result;
        img.style.display = 'block';
        fb.style.display  = 'none';
    };
    reader.readAsDataURL(file);
    const pill = document.getElementById('emPhotoPill');
    document.getElementById('emPhotoName').textContent = file.name;
    pill.style.display = 'flex';
});

async function submitEditForm(e) {
    e.preventDefault();
    const btn = document.getElementById('emSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const form = document.getElementById('emForm');
    const fd   = new FormData(form);
    if (_emPhotoFile) fd.set('photo', _emPhotoFile);

    try {
        const res = await fetch('employees.php', { method: 'POST', body: fd });
        const j   = await res.json();
        if (j.ok) {
            const eid  = parseInt(document.getElementById('emEid').value);
            const row  = document.querySelector(`#tableBody tr[data-id="${eid}"]`);
            if (row) {
                // Update name + job title
                const nameEl = row.querySelector('.emp-name');
                const titleEl = row.querySelector('.emp-title');
                if (nameEl) nameEl.textContent = j.name;
                if (titleEl) {
                    const existingShiftSpan = titleEl.querySelector('.shift-inline');
                    titleEl.textContent = j.job;
                    if (existingShiftSpan) titleEl.appendChild(existingShiftSpan);
                }
                // Keep data-name / data-title in sync for search
                row.dataset.name  = j.name.toLowerCase();
                row.dataset.title = j.job.toLowerCase();

                // Update avatar photo
                if (j.photo) {
                    const av = row.querySelector('.avatar-img, .avatar');
                    if (av) {
                        const img = document.createElement('img');
                        img.className = 'avatar-img';
                        img.src = j.photo;
                        img.alt = j.name;
                        av.replaceWith(img);
                    }
                }

                // Update shift inline span
                const shiftMeta = { morning:['#f39c12','fa-sun','Morning'], afternoon:['#3498db','fa-cloud-sun','Afternoon'], night:['#9b59b6','fa-moon','Night'] };
                row.dataset.shift = j.shift || '';
                const titleEl2 = row.querySelector('.emp-title');
                if (titleEl2) {
                    let si2 = titleEl2.querySelector('.shift-inline');
                    if (j.shift && shiftMeta[j.shift]) {
                        const [sc, si, sl] = shiftMeta[j.shift];
                        if (!si2) { si2 = document.createElement('span'); si2.className = 'shift-inline'; si2.style.marginLeft = '4px'; si2.style.fontSize = '9px'; si2.style.opacity = '.9'; titleEl2.appendChild(si2); }
                        si2.style.color = sc;
                        si2.innerHTML = `<i class="fa-solid ${si}"></i> ${sl}`;
                    } else if (si2) { si2.remove(); }
                }
                recalcShiftCounts();

                // If role changed, trigger the same real-time logic
                const prevRole = row.dataset.role;
                if (j.role && j.role !== prevRole && j.role !== 'admin') {
                    const info = ROLES_INFO[j.role];
                    moveRowToGroup(row, prevRole, j.role, info);
                    adjustPillCount(prevRole, -1);
                    adjustPillCount(j.role, +1);
                    row.dataset.role = j.role;
                    // Update the inline role select too
                    const sel = row.querySelector('.role-select');
                    if (sel) sel.value = j.role;
                    const wrap = row.querySelector('.role-wrap');
                    if (wrap && info) {
                        const color = info.color || '#888';
                        wrap.style.setProperty('--rb-bg',     color+'1a');
                        wrap.style.setProperty('--rb-color',  color);
                        wrap.style.setProperty('--rb-border', color+'33');
                        const icon = wrap.querySelector('i');
                        if (icon) icon.className = `fa-solid ${info.icon}`;
                        wrap.dataset.current = j.role;
                    }
                }
                applyFilters();
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
            closeEditModal();
            showToast(`${j.name} updated successfully`, 'success');
        } else {
            showToast(j.msg || 'Save failed', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
        }
    } catch {
        showToast('Network error', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    }
}

/* ── Real-time stats polling (every 30s) ── */
let _statsMax = <?= $max_orders ?>;

function fmtNum(n) {
    return n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : String(n);
}

async function refreshStats() {
    if (document.hidden) return;
    try {
        const res  = await fetch('employees.php?action=get_stats');
        const data = await res.json();
        if (!data.ok) return;
        _statsMax = data.max_orders || 1;

        for (const [eidStr, s] of Object.entries(data.stats)) {
            const eid = parseInt(eidStr);

            // All-time orders cell
            const ordTd = document.querySelector(`[data-stat-orders="${eid}"]`);
            if (ordTd) {
                const pct     = Math.min(100, Math.round(s.total_orders / _statsMax * 100));
                const hasOrd  = s.total_orders > 0;
                const numEl   = ordTd.querySelector('.perf-num');
                const fillEl  = ordTd.querySelector('.perf-fill');
                const subEl   = ordTd.querySelector('.perf-sub');
                if (numEl)  { numEl.textContent = fmtNum(s.total_orders); numEl.classList.toggle('num-zero', !hasOrd); }
                if (fillEl) fillEl.style.width = pct + '%';
                if (subEl)  subEl.textContent  = hasOrd ? `avg $${s.avg_order_value.toFixed(2)} / order` : 'No orders yet';
                ordTd.dataset.val = s.total_orders;
            }

            // This month cell
            const monTd = document.querySelector(`[data-stat-month="${eid}"]`);
            if (monTd) {
                const numEl   = monTd.querySelector('.num-cell');
                let   todayEl = monTd.querySelector('.today-badge');
                if (numEl) { numEl.textContent = fmtNum(s.orders_this_month); numEl.classList.toggle('num-zero', s.orders_this_month === 0); }
                if (s.orders_today > 0) {
                    if (!todayEl) { todayEl = document.createElement('div'); todayEl.className = 'today-badge'; todayEl.style.cssText = 'font-size:10px;color:var(--ok);margin-top:2px'; monTd.appendChild(todayEl); }
                    todayEl.textContent = `+${s.orders_today} today`;
                } else if (todayEl) {
                    todayEl.remove();
                }
                monTd.dataset.val = s.orders_this_month;
            }

            // Revenue cell
            const revTd = document.querySelector(`[data-stat-rev="${eid}"]`);
            if (revTd) {
                const numEl = revTd.querySelector('.revenue');
                if (numEl) { numEl.textContent = `$${s.total_revenue.toFixed(2)}`; numEl.classList.toggle('num-zero', s.total_revenue <= 0); }
                revTd.dataset.val = s.total_revenue.toFixed(2);
            }

            // Leaderboard podium card
            const card = document.querySelector(`[data-podium-eid="${eid}"]`);
            if (card) {
                const ordEl = card.querySelector('.ps-orders');
                const revEl = card.querySelector('.ps-revenue');
                const moEl  = card.querySelector('.ps-month');
                const revWrap = card.querySelector('.ps-rev-wrap');
                if (ordEl) ordEl.textContent = fmtNum(s.total_orders);
                if (moEl)  moEl.textContent  = fmtNum(s.orders_this_month);
                if (revEl) { revEl.textContent = `$${fmtNum(s.total_revenue)}`; }
                if (revWrap) revWrap.style.display = s.total_revenue > 0 ? '' : 'none';
                // Update job title + shift inline
                const titleEl = card.querySelector('.podium-title');
                if (titleEl) {
                    const job = s.job_title || '';
                    const shiftColors = {morning:'#f39c12',afternoon:'#3498db',night:'#9b59b6'};
                    const shiftIcons  = {morning:'fa-sun',afternoon:'fa-cloud-sun',night:'fa-moon'};
                    const shiftLabels = {morning:'Morning',afternoon:'Afternoon',night:'Night'};
                    if (s.shift && shiftColors[s.shift]) {
                        titleEl.innerHTML = `${job} <span style="color:${shiftColors[s.shift]};font-size:9px;opacity:.85"><i class="fa-solid ${shiftIcons[s.shift]}"></i> ${shiftLabels[s.shift]}</span>`;
                    } else {
                        titleEl.textContent = job;
                    }
                }
            }

            // Sync table row data-shift + shift-inline from polling
            const rowEl = document.querySelector(`#tableBody tr[data-id="${eid}"]`);
            if (rowEl && rowEl.dataset.shift !== (s.shift || '')) {
                rowEl.dataset.shift = s.shift || '';
                const shiftMeta3 = { morning:['#f39c12','fa-sun','Morning'], afternoon:['#3498db','fa-cloud-sun','Afternoon'], night:['#9b59b6','fa-moon','Night'] };
                const titleEl3 = rowEl.querySelector('.emp-title');
                if (titleEl3) {
                    let si3 = titleEl3.querySelector('.shift-inline');
                    if (s.shift && shiftMeta3[s.shift]) {
                        const [sc3, si3icon, sl3] = shiftMeta3[s.shift];
                        if (!si3) { si3 = document.createElement('span'); si3.className = 'shift-inline'; si3.style.marginLeft = '4px'; si3.style.fontSize = '9px'; si3.style.opacity = '.9'; titleEl3.appendChild(si3); }
                        si3.style.color = sc3;
                        si3.innerHTML = `<i class="fa-solid ${si3icon}"></i> ${sl3}`;
                    } else if (si3) { si3.remove(); }
                }
                recalcShiftCounts();
            }
        }
    } catch (_) { /* silent — no disruption on network error */ }
}

refreshStats();
setInterval(refreshStats, 10000);
</script>
</body>
</html>
