<?php
require 'admin_only.php';
require 'config.php';

$id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$e = $stmt->get_result()->fetch_assoc();
if (!$e) { header("Location: employees.php"); exit; }

$role = $_SESSION['role'] ?? 'staff';

$age = null;
if (!empty($e['date_of_birth'])) {
    $age = (int)date_diff(date_create($e['date_of_birth']), date_create('today'))->y;
}

$tenure = 'N/A';
if (!empty($e['hire_date'])) {
    $diff = date_diff(date_create($e['hire_date']), date_create('today'));
    $parts = [];
    if ($diff->y > 0) $parts[] = $diff->y . ' yr' . ($diff->y !== 1 ? 's' : '');
    if ($diff->m > 0) $parts[] = $diff->m . ' mo';
    $tenure = $parts ? implode(', ', $parts) : 'Less than 1 month';
}

$dob_fmt  = !empty($e['date_of_birth']) ? date('M j, Y', strtotime($e['date_of_birth'])) : 'Not set';
$hire_fmt = !empty($e['hire_date'])     ? date('M j, Y', strtotime($e['hire_date']))     : 'Not set';
$initials = strtoupper(mb_substr($e['name'], 0, 1));
$has_photo = !empty($e['photo']) && file_exists($e['photo']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Details | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --bg: #0c0c0c;
    --bg-card: #121212;
    --border: #222;
    --border-hover: #333;
    --text: #ffffff;
    --text-muted: #9a9a9a;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209,144,75,0.2);
    --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}

*{box-sizing:border-box;margin:0;padding:0;}

html{height:100%;}

body {
    font-family: 'Poppins', sans-serif;
    background: radial-gradient(circle at top, #111 0%, #000 60%);
    min-height: 100vh;
    color: var(--text);
    padding: 80px 20px 40px;
    position: relative;
}

body::before {
    content: '';
    position: fixed;
    top: -200px; right: -200px;
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(209,144,75,0.06) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

body::after {
    content: '';
    position: fixed;
    bottom: -200px; left: -200px;
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(209,144,75,0.04) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

::-webkit-scrollbar{width:6px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:var(--accent);border-radius:10px;}

/* ── Back Button ── */
.back-btn {
    position: fixed;
    top: 20px; left: 20px;
    display: flex; align-items: center; gap: 8px;
    color: var(--text-muted);
    text-decoration: none;
    font-weight: 500; font-size: 14px;
    padding: 9px 16px;
    border-radius: 12px;
    background: rgba(18,18,18,0.9);
    backdrop-filter: blur(10px);
    border: 1px solid var(--border);
    transition: var(--transition);
    z-index: 100;
}
.back-btn:hover {
    color: var(--accent);
    transform: translateX(-3px);
    border-color: var(--border-hover);
}

/* ── Wrapper ── */
.wrapper {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 820px;
    margin: 0 auto;
}

/* ── Card ── */
.card {
    background: rgba(18,18,18,0.95);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    padding: 36px 40px;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border);
    animation: slideUp 0.5s ease;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Profile Header ── */
.profile-header {
    display: flex;
    align-items: center;
    gap: 24px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 28px;
    flex-wrap: wrap;
}

.avatar-wrap {
    flex-shrink: 0;
    position: relative;
}

.avatar {
    width: 100px; height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--accent);
    box-shadow: var(--shadow-accent);
    display: block;
    transition: var(--transition);
}
.avatar:hover { transform: scale(1.04); }

.avatar-fallback {
    width: 100px; height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: #000;
    font-size: 38px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--accent);
    box-shadow: var(--shadow-accent);
}

.profile-meta { flex: 1; min-width: 0; }

.emp-name {
    font-size: 22px; font-weight: 700;
    margin-bottom: 4px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.job-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 500;
    color: var(--accent);
    background: rgba(209,144,75,0.1);
    border: 1px solid rgba(209,144,75,0.18);
    padding: 4px 14px;
    border-radius: 20px;
    margin-bottom: 12px;
}

.stat-chips {
    display: flex; flex-wrap: wrap; gap: 8px;
}

.stat-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 500;
    color: var(--text-muted);
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    padding: 5px 12px;
    border-radius: 20px;
}
.stat-chip i { font-size: 11px; color: var(--accent); }

/* ── Info Grid ── */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 28px;
}

.info-item {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    transition: var(--transition);
}
.info-item:hover {
    border-color: var(--border-hover);
    background: rgba(255,255,255,0.04);
}
.info-item.full { grid-column: 1 / -1; }

.info-label {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 6px;
}
.info-label i { font-size: 11px; color: var(--accent); }

.info-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    line-height: 1.5;
    word-break: break-word;
}
.info-value.accent { color: var(--accent); font-weight: 600; }
.info-value.muted  { color: var(--text-muted); letter-spacing: 3px; }

/* ── Actions ── */
.actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.btn {
    padding: 11px 24px;
    border-radius: 12px;
    font-size: 14px;
    text-decoration: none;
    border: 1px solid transparent;
    font-weight: 600;
    transition: var(--transition);
    display: flex; align-items: center; gap: 8px;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
}
.btn i { font-size: 15px; }

.btn-edit {
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    color: #000;
    box-shadow: var(--shadow-accent);
}
.btn-edit:hover {
    transform: translateY(-2px);
    filter: brightness(1.1);
    box-shadow: 0 6px 24px rgba(209,144,75,0.35);
}

.btn-delete {
    border-color: #ff5c5c;
    color: #ff5c5c;
    background: rgba(255,92,92,0.05);
}
.btn-delete:hover {
    background: #ff5c5c;
    color: #000;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255,92,92,0.3);
}

/* ── Responsive ── */
@media (max-width: 640px) {
    body { padding: 70px 14px 30px; }
    .card { padding: 22px 18px; }
    .info-grid { grid-template-columns: 1fr; }
    .emp-name { font-size: 18px; }
    .avatar, .avatar-fallback { width: 76px; height: 76px; }
    .avatar-fallback { font-size: 28px; }
    .profile-header { gap: 16px; }
    .btn { padding: 9px 18px; font-size: 13px; }
}
</style>
</head>

<body>

<a href="employees.php" class="back-btn">
    <i class="fa-solid fa-arrow-left"></i> Back to Employees
</a>

<div class="wrapper">
    <div class="card">

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="avatar-wrap">
                <?php if ($has_photo): ?>
                    <img src="<?= htmlspecialchars($e['photo']) ?>" class="avatar" alt="<?= htmlspecialchars($e['name']) ?>">
                <?php else: ?>
                    <div class="avatar-fallback"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
            </div>
            <div class="profile-meta">
                <div class="emp-name"><?= htmlspecialchars($e['name']) ?></div>
                <div class="job-badge">
                    <i class="fa-solid fa-briefcase"></i>
                    <?= htmlspecialchars($e['job_title']) ?>
                </div>
                <div class="stat-chips">
                    <?php if ($age !== null): ?>
                    <span class="stat-chip">
                        <i class="fa-solid fa-cake-candles"></i> <?= $age ?> years old
                    </span>
                    <?php endif; ?>
                    <span class="stat-chip">
                        <i class="fa-solid fa-clock"></i> <?= $tenure ?>
                    </span>
                    <span class="stat-chip">
                        <i class="fa-solid fa-id-badge"></i> ID #<?= $e['employee_id'] ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="info-grid">

            <div class="info-item">
                <div class="info-label"><i class="fa-solid fa-phone"></i> Phone</div>
                <div class="info-value"><?= htmlspecialchars($e['phone'] ?: 'Not set') ?></div>
            </div>

            <div class="info-item">
                <div class="info-label"><i class="fa-solid fa-calendar"></i> Date of Birth</div>
                <div class="info-value">
                    <?= $dob_fmt ?>
                    <?php if ($age !== null): ?>
                        <span style="font-size:12px;color:var(--text-muted);margin-left:6px;">(<?= $age ?> yrs)</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label"><i class="fa-solid fa-calendar-check"></i> Hire Date</div>
                <div class="info-value"><?= $hire_fmt ?></div>
            </div>

            <div class="info-item">
                <div class="info-label"><i class="fa-solid fa-hourglass-half"></i> Tenure</div>
                <div class="info-value accent"><?= htmlspecialchars($tenure) ?></div>
            </div>

            <div class="info-item full">
                <div class="info-label"><i class="fa-solid fa-location-dot"></i> Address</div>
                <div class="info-value"><?= nl2br(htmlspecialchars($e['address'] ?: 'Not set')) ?></div>
            </div>

            <div class="info-item full">
                <div class="info-label"><i class="fa-solid fa-dollar-sign"></i> Salary</div>
                <?php if ($role !== 'admin'): ?>
                    <div class="info-value muted">••••••</div>
                <?php else: ?>
                    <div class="info-value accent">$<?= number_format($e['salary'], 2) ?></div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Actions -->
        <div class="actions">
            <a href="employee_edit.php?id=<?= $id ?>" class="btn btn-edit">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </a>
            <a href="employee_delete.php?id=<?= $id ?>" class="btn btn-delete"
               onclick="return confirm('Delete <?= htmlspecialchars(addslashes($e['name'])) ?>? This cannot be undone.')">
                <i class="fa-solid fa-trash"></i> Delete
            </a>
        </div>

    </div>
</div>

</body>
</html>
