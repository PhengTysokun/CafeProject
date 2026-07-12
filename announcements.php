<?php
require 'auth.php';
require 'config.php';
if (!can('announcements')) { header("Location: dashboard.php?denied=1"); exit; }

$conn->query("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','warning','urgent') DEFAULT 'info',
    created_by VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE NULL,
    starts_at DATE NULL,
    is_active TINYINT(1) DEFAULT 1
)");
// Existing installs: ensure the scheduling column is present (mirrors config.php migration).
$conn->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS starts_at DATE NULL AFTER expires_at");

// Mark all active announcements as read for this user
$conn->query("INSERT IGNORE INTO announcement_reads (user_id, announcement_id)
    SELECT " . (int)$_SESSION['user_id'] . ", id FROM announcements
    WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) AND (starts_at IS NULL OR starts_at <= CURDATE())");

$toast = '';
$toast_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title   = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type    = in_array($_POST['type'] ?? '', ['info','warning','urgent']) ? $_POST['type'] : 'info';
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $starts  = !empty($_POST['starts_at'])  ? $_POST['starts_at']  : null;
        $author  = $_SESSION['username'] ?? 'Admin';

        if (!$title || !$message) {
            $toast = "Title and message are required.";
            $toast_type = 'error';
        } elseif ($starts && $expires && $starts > $expires) {
            $toast = "Show From date must be on or before the Expires On date.";
            $toast_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (title, message, type, created_by, expires_at, starts_at) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("ssssss", $title, $message, $type, $author, $expires, $starts);
            $stmt->execute();
            $toast = "Announcement posted!";
            $toast_type = 'success';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $toast = "Announcement deleted.";
        $toast_type = 'success';
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE announcements SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $toast = "Announcement updated.";
        $toast_type = 'success';
    }
}

$per_page    = 10;
$page        = max(1, (int)($_GET['page'] ?? 1));
$total_count = (int)$conn->query("SELECT COUNT(*) FROM announcements")->fetch_row()[0];
$active_count = (int)$conn->query("SELECT COUNT(*) FROM announcements WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) AND (starts_at IS NULL OR starts_at <= CURDATE())")->fetch_row()[0];
$total_pages = $total_count > 0 ? (int)ceil($total_count / $per_page) : 1;
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;
$pg_base     = 'announcements.php?page=';
$stmt = $conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
:root {
    --accent:       #d1904b;
    --accent-light: #e8b87a;
    --accent-dark:  #a0702a;
    --bg:           #0b0b0b;
    --card:         #111;
    --card2:        #161616;
    --border:       rgba(255,255,255,0.07);
    --text:         #f5f5f5;
    --text-muted:   #777;
    --danger:       #e74c3c;
    --success:      #55e087;
    --warning:      #f39c12;
    --info:         #5bc0de;
    --urgent:       #e74c3c;
}
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

/* Topbar */
.topbar {
    position:sticky; top:0; z-index:100;
    background:rgba(10,10,10,.95); border-bottom:1px solid var(--border);
    backdrop-filter:blur(12px);
    display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; height:62px;
}
.topbar-left { display:flex; align-items:center; gap:16px; }
.back-btn {
    display:inline-flex; align-items:center; gap:7px;
    text-decoration:none; color:#d1904b;
    font-size:13px; font-weight:600;
    padding:7px 14px; border-radius:10px;
    border:1px solid rgba(209,144,75,.35); background:rgba(209,144,75,.08); transition:all .2s;
}
.back-btn:hover { background:rgba(209,144,75,.16); border-color:#d1904b; }
.page-title { font-size:15px; font-weight:700; }
.page-title span { color:var(--accent); }

/* Layout */
.page-wrap { max-width:960px; margin:0 auto; padding:32px 20px 60px; }
.layout { display:grid; grid-template-columns:1fr 380px; gap:24px; align-items:start; }
@media(max-width:760px) { .layout { grid-template-columns:1fr; } }

/* Card */
.card {
    background:var(--card); border:1px solid var(--border);
    border-radius:18px; overflow:hidden; margin-bottom:20px;
}
.card-header {
    padding:20px 24px; display:flex; align-items:center;
    justify-content:space-between; border-bottom:1px solid var(--border);
}
.card-header-left { display:flex; align-items:center; gap:14px; }
.card-icon {
    width:40px; height:40px; border-radius:12px;
    background:linear-gradient(135deg,rgba(209,144,75,.2),rgba(209,144,75,.06));
    border:1px solid rgba(209,144,75,.2);
    display:flex; align-items:center; justify-content:center;
    color:var(--accent); font-size:16px; flex-shrink:0;
}
.card-title    { font-size:15px; font-weight:700; }
.card-subtitle { font-size:12px; color:var(--text-muted); margin-top:2px; }
.card-body { padding:22px 24px; }

/* Badge */
.badge {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
}
.badge-info    { background:rgba(91,192,222,.12); color:#5bc0de; border:1px solid rgba(91,192,222,.25); }
.badge-warning { background:rgba(243,156,18,.12);  color:var(--warning); border:1px solid rgba(243,156,18,.25); }
.badge-urgent  { background:rgba(231,76,60,.12);   color:#e87070; border:1px solid rgba(231,76,60,.25); }
.badge-active  { background:rgba(85,224,135,.12);  color:var(--success); border:1px solid rgba(85,224,135,.25); }
.badge-off     { background:rgba(255,255,255,.05); color:var(--text-muted); border:1px solid var(--border); }

/* Count chip */
.count-chip {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:22px; height:22px; padding:0 7px;
    background:rgba(209,144,75,.15); color:var(--accent);
    border-radius:20px; font-size:11px; font-weight:700;
}

/* Form fields */
.fl-label {
    display:block; font-size:11px; font-weight:600;
    color:var(--text-muted); text-transform:uppercase;
    letter-spacing:.5px; margin-bottom:6px;
}
.fl-input, .fl-textarea, .fl-select {
    width:100%; padding:12px 16px;
    border-radius:10px; border:1px solid rgba(255,255,255,.09);
    background:rgba(255,255,255,.03); color:var(--text);
    font-size:14px; font-family:'Poppins',sans-serif;
    outline:none; transition:border-color .2s, box-shadow .2s;
}
.fl-input:focus, .fl-textarea:focus, .fl-select:focus {
    border-color:rgba(209,144,75,.45);
    background:rgba(209,144,75,.04);
    box-shadow:0 0 0 3px rgba(209,144,75,.08);
}
.fl-textarea { resize:vertical; min-height:90px; }
.fl-select option { background:#1a1a1a; }
.field-group { margin-bottom:14px; }

/* Type selector */
.type-pills { display:flex; gap:8px; flex-wrap:wrap; }
.type-pill {
    display:flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:20px; cursor:pointer;
    border:1px solid var(--border); font-size:12px; font-weight:600;
    color:var(--text-muted); transition:all .2s; user-select:none;
}
.type-pill input[type=radio] { display:none; }
.type-pill.sel-info    { border-color:rgba(91,192,222,.5);  background:rgba(91,192,222,.1);  color:#5bc0de; }
.type-pill.sel-warning { border-color:rgba(243,156,18,.5);  background:rgba(243,156,18,.1);  color:var(--warning); }
.type-pill.sel-urgent  { border-color:rgba(231,76,60,.5);   background:rgba(231,76,60,.1);   color:#e87070; }

/* Submit btn */
.btn-primary {
    display:inline-flex; align-items:center; gap:8px;
    padding:0 20px; height:44px; border:none; border-radius:10px;
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    color:#fff; font-family:'Poppins',sans-serif;
    font-size:14px; font-weight:600; cursor:pointer; width:100%;
    justify-content:center;
    box-shadow:0 3px 14px rgba(209,144,75,.3);
    transition:transform .2s, box-shadow .2s;
}
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 22px rgba(209,144,75,.4); }

/* Announcement row */
.ann-row {
    padding:16px 20px; border-bottom:1px solid var(--border);
    transition:background .15s;
}
.ann-row:last-child { border-bottom:none; }
.ann-row:hover { background:rgba(255,255,255,.02); }
.ann-row.inactive { opacity:.5; }
.ann-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:6px; }
.ann-title { font-size:14px; font-weight:700; }
.ann-msg   { font-size:13px; color:var(--text-muted); line-height:1.55; margin-bottom:8px; }
.ann-meta  { display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:11px; color:var(--text-muted); }
.ann-actions { display:flex; gap:6px; flex-shrink:0; }
.btn-icon {
    width:30px; height:30px; border-radius:8px; border:1px solid var(--border);
    background:rgba(255,255,255,.04); color:var(--text-muted); cursor:pointer;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:12px; transition:all .2s;
}
.btn-icon:hover { color:var(--text); border-color:rgba(255,255,255,.15); }
.btn-icon.del:hover { color:#e87070; border-color:rgba(231,76,60,.3); background:rgba(231,76,60,.07); }

/* Empty state */
.empty-state {
    padding:40px 20px; text-align:center; color:var(--text-muted);
}
.empty-state i { font-size:36px; margin-bottom:12px; opacity:.3; }
.empty-state p { font-size:13px; }

/* Expired tag */
.tag-expired { color:#e87070; font-size:10px; font-weight:600; }

/* Toast */
.toast {
    position:fixed; bottom:28px; right:28px; z-index:9999;
    display:flex; align-items:center; gap:12px;
    padding:14px 20px; border-radius:14px; font-size:13.5px; font-weight:500;
    box-shadow:0 8px 32px rgba(0,0,0,.5);
    animation:toastIn .4s ease both; min-width:260px;
}
.toast.success { background:#1a3a28; border:1px solid rgba(85,224,135,.3); color:var(--success); }
.toast.error   { background:#3a1a1a; border:1px solid rgba(231,76,60,.3);  color:#e87070; }
@keyframes toastIn  { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
@keyframes toastOut { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(12px)} }

/* ── Entrance & motion ─────────────────────────────── */
@keyframes fadeUp    { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }

.topbar { animation:slideDown .45s cubic-bezier(.22,1,.36,1) both; }

.layout > div:first-child > .card {
    animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both;
    animation-delay:.12s;
    box-shadow:0 4px 32px rgba(0,0,0,.3);
}
.layout > div:last-child > .card {
    animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both;
    animation-delay:.22s;
    box-shadow:0 4px 32px rgba(0,0,0,.3);
}
.layout > div:last-child > div:not(.card) {
    animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both;
    animation-delay:.30s;
}

/* Pagination */
.pg-wrap { padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:all .2s; }
.pg-btn:hover { border-color:var(--accent); color:var(--accent); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }

/* Staggered announcement rows */
.ann-row { animation:fadeUp .35s cubic-bezier(.22,1,.36,1) both; }
.ann-row:nth-child(1)  { animation-delay:.18s; }
.ann-row:nth-child(2)  { animation-delay:.22s; }
.ann-row:nth-child(3)  { animation-delay:.26s; }
.ann-row:nth-child(4)  { animation-delay:.30s; }
.ann-row:nth-child(5)  { animation-delay:.34s; }
.ann-row:nth-child(6)  { animation-delay:.38s; }
.ann-row:nth-child(7)  { animation-delay:.42s; }
.ann-row:nth-child(8)  { animation-delay:.46s; }
.ann-row:nth-child(9)  { animation-delay:.50s; }
.ann-row:nth-child(10) { animation-delay:.54s; }

/* Enhanced row hover — subtle slide-right nudge */
.ann-row { transition:background .18s ease, transform .2s ease; }
.ann-row:not(.inactive):hover { background:rgba(255,255,255,.03) !important; transform:translateX(3px); }

/* Button polish */
.btn-primary { transition:transform .2s ease, box-shadow .2s ease; }
.btn-primary:active { transform:scale(.97) translateY(0) !important; }
.btn-icon { transition:all .18s ease; }
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--card:#FFFFFF;--card2:#F5F7FA;--border:#E2E5EA;--text:#111827;--text-muted:#5A6373;}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <span class="page-title">Staff <span>Announcements</span></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted)">
        <span class="count-chip"><?= $active_count ?></span>
        active now
    </div>
</div>

<div class="page-wrap">
<div class="layout">

<!-- Left: list -->
<div>
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-icon"><i class="fa-solid fa-bullhorn"></i></div>
                <div>
                    <div class="card-title">All Announcements</div>
                    <div class="card-subtitle"><?= number_format($total_count) ?> total</div>
                </div>
            </div>
        </div>
        <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-bullhorn"></i>
            <p>No announcements yet.<br>Post one using the form.</p>
        </div>
        <?php else: ?>
        <?php foreach ($announcements as $a):
            $expired   = !is_null($a['expires_at']) && $a['expires_at'] < date('Y-m-d');
            $scheduled = !is_null($a['starts_at'])  && $a['starts_at'] > date('Y-m-d');
            $isActive  = $a['is_active'] && !$expired && !$scheduled;
            $typeMap  = ['info'=>['#5bc0de','info-circle'], 'warning'=>['#f39c12','triangle-exclamation'], 'urgent'=>['#e74c3c','circle-exclamation']];
            [$tColor, $tIcon] = $typeMap[$a['type']] ?? $typeMap['info'];
        ?>
        <div class="ann-row <?= !$isActive ? 'inactive' : '' ?>">
            <div class="ann-top">
                <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0">
                    <i class="fa-solid fa-<?= $tIcon ?>" style="color:<?= $tColor ?>;flex-shrink:0"></i>
                    <span class="ann-title"><?= htmlspecialchars($a['title']) ?></span>
                </div>
                <div class="ann-actions">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn-icon" title="<?= $a['is_active'] ? 'Hide' : 'Show' ?>">
                            <i class="fa-solid fa-<?= $a['is_active'] ? 'eye' : 'eye-slash' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this announcement?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn-icon del" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <div class="ann-msg"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
            <div class="ann-meta">
                <span class="badge badge-<?= $a['type'] ?>"><i class="fa-solid fa-<?= $tIcon ?>"></i> <?= ucfirst($a['type']) ?></span>
                <?php if ($isActive): ?>
                <span class="badge badge-active"><i class="fa-solid fa-circle-dot"></i> Active</span>
                <?php elseif ($scheduled && $a['is_active'] && !$expired): ?>
                <span class="badge badge-off"><i class="fa-regular fa-clock"></i> Scheduled</span>
                <?php else: ?>
                <span class="badge badge-off"><i class="fa-solid fa-circle"></i> <?= $expired ? 'Expired' : 'Hidden' ?></span>
                <?php endif; ?>
                <span><i class="fa-regular fa-user"></i> <?= htmlspecialchars($a['created_by']) ?></span>
                <span title="Date posted"><i class="fa-regular fa-clock"></i> Posted <?= date('d M Y, g:i A', strtotime($a['created_at'])) ?></span>
                <span title="Show From"><i class="fa-regular fa-calendar-plus"></i>
                    <?php if ($a['starts_at']): ?>
                    Shows <?= date('d M Y', strtotime($a['starts_at'])) ?><?= $scheduled ? ' (scheduled)' : '' ?>
                    <?php else: ?>
                    Shows immediately
                    <?php endif; ?>
                </span>
                <span title="Expires On" class="<?= $expired ? 'tag-expired' : '' ?>"><i class="fa-solid fa-calendar-xmark"></i>
                    <?php if ($a['expires_at']): ?>
                    Expires <?= date('d M Y', strtotime($a['expires_at'])) ?>
                    <?php else: ?>
                    No expiry
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($total_pages > 1): ?>
        <div class="pg-wrap">
            <span class="pg-info">Page <?= $page ?> of <?= $total_pages ?> · <?= number_format($total_count) ?> total</span>
            <nav class="pg-nav">
                <?php if ($page > 1): ?>
                <a href="<?= $pg_base ?>1" class="pg-btn">«</a>
                <a href="<?= $pg_base ?><?= $page - 1 ?>" class="pg-btn">‹</a>
                <?php else: ?>
                <span class="pg-disabled">«</span>
                <span class="pg-disabled">‹</span>
                <?php endif; ?>
                <?php
                $w_start = max(1, $page - 2);
                $w_end   = min($total_pages, $page + 2);
                if ($w_start > 1): ?><span class="pg-ellipsis">…</span><?php endif;
                for ($pg_i = $w_start; $pg_i <= $w_end; $pg_i++): ?>
                    <?php if ($pg_i === $page): ?>
                    <span class="pg-active"><?= $pg_i ?></span>
                    <?php else: ?>
                    <a href="<?= $pg_base ?><?= $pg_i ?>" class="pg-btn"><?= $pg_i ?></a>
                    <?php endif; ?>
                <?php endfor;
                if ($w_end < $total_pages): ?><span class="pg-ellipsis">…</span><?php endif; ?>
                <?php if ($page < $total_pages): ?>
                <a href="<?= $pg_base ?><?= $page + 1 ?>" class="pg-btn">›</a>
                <a href="<?= $pg_base ?><?= $total_pages ?>" class="pg-btn">»</a>
                <?php else: ?>
                <span class="pg-disabled">›</span>
                <span class="pg-disabled">»</span>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Right: create form -->
<div>
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-icon"><i class="fa-solid fa-plus"></i></div>
                <div>
                    <div class="card-title">Post Announcement</div>
                    <div class="card-subtitle">Visible to all staff on their order screen</div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" id="createForm">
                <input type="hidden" name="action" value="create">

                <div class="field-group">
                    <label class="fl-label">Title</label>
                    <input type="text" name="title" class="fl-input" placeholder="e.g. New drink on the menu!" required maxlength="255">
                </div>

                <div class="field-group">
                    <label class="fl-label">Message</label>
                    <textarea name="message" class="fl-textarea" placeholder="Write your announcement here…" required></textarea>
                </div>

                <div class="field-group">
                    <label class="fl-label">Type</label>
                    <div class="type-pills" id="typePills">
                        <label class="type-pill sel-info" data-val="info">
                            <input type="radio" name="type" value="info" checked>
                            <i class="fa-solid fa-info-circle"></i> Info
                        </label>
                        <label class="type-pill" data-val="warning">
                            <input type="radio" name="type" value="warning">
                            <i class="fa-solid fa-triangle-exclamation"></i> Warning
                        </label>
                        <label class="type-pill" data-val="urgent">
                            <input type="radio" name="type" value="urgent">
                            <i class="fa-solid fa-circle-exclamation"></i> Urgent
                        </label>
                    </div>
                </div>

                <div class="field-group">
                    <label class="fl-label">Show From <span style="font-size:10px;color:var(--text-muted);font-weight:400;text-transform:none">(optional — leave blank to show immediately)</span></label>
                    <input type="date" name="starts_at" class="fl-input" min="<?= date('Y-m-d') ?>">
                </div>

                <div class="field-group">
                    <label class="fl-label">Expires On <span style="font-size:10px;color:var(--text-muted);font-weight:400;text-transform:none">(optional — leave blank to never expire)</span></label>
                    <input type="date" name="expires_at" class="fl-input" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-bullhorn"></i> Post Announcement
                </button>
            </form>
        </div>
    </div>

    <!-- Preview tip -->
    <div style="padding:14px 18px;background:rgba(209,144,75,.06);border:1px solid rgba(209,144,75,.15);border-radius:12px;font-size:12px;color:var(--text-muted);line-height:1.6">
        <i class="fa-solid fa-lightbulb" style="color:var(--accent);margin-right:6px"></i>
        Active announcements appear as a banner at the top of the <strong style="color:var(--text)">Orders screen</strong> that all staff see when they log in.
    </div>
</div>

</div><!-- /layout -->
</div><!-- /page-wrap -->

<?php if ($toast): ?>
<div class="toast <?= $toast_type ?>" id="toastEl">
    <i class="fa-solid fa-<?= $toast_type === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
    <span><?= htmlspecialchars($toast) ?></span>
</div>
<script>
setTimeout(function(){
    var t = document.getElementById('toastEl');
    if(t){t.style.animation='toastOut .4s ease forwards';setTimeout(()=>t.remove(),400);}
},4000);
</script>
<?php endif; ?>

<script>
// Type pill selection
document.querySelectorAll('#typePills .type-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.querySelectorAll('#typePills .type-pill').forEach(function(p) {
            p.className = 'type-pill';
        });
        var val = pill.dataset.val;
        pill.classList.add('sel-' + val);
    });
});
</script>
</body>
</html>
