<?php
require 'admin_only.php';
require 'config.php';

$currentUserId = (int)$_SESSION['user_id'];

// ── AJAX: username availability check ──
if (isset($_GET['check_username'])) {
    header('Content-Type: application/json');
    $u = trim($_GET['check_username'] ?? '');
    if (strlen($u) < 3) { echo json_encode(['available' => false, 'reason' => 'too_short']); exit; }
    $s = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    $s->bind_param('s', $u); $s->execute(); $s->store_result();
    echo json_encode(['available' => $s->num_rows === 0]);
    exit;
}

$message      = '';
$message_type = '';

// ── ADD ADMIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($username === '' || $password === '') {
        $message = 'All fields are required.'; $message_type = 'error';
    } elseif (strlen($username) < 3) {
        $message = 'Username must be at least 3 characters.'; $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.'; $message_type = 'error';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.'; $message_type = 'error';
    } else {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param('s', $username); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) {
            $message = 'Username already taken.'; $message_type = 'error';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO users (username, password, role_id) VALUES (?, ?, (SELECT id FROM roles WHERE slug='admin'))");
            $ins->bind_param('ss', $username, $hashed);
            if ($ins->execute()) { $message = "Admin \"$username\" created successfully."; $message_type = 'success'; }
            else { $message = 'Database error. Please try again.'; $message_type = 'error'; }
        }
    }
}

// ── FETCH ADMINS ──
$admins = [];
try {
    $res = $conn->prepare("SELECT u.user_id, u.username, r.slug AS role, u.created_at FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' ORDER BY u.user_id ASC");
    $res->execute();
    $admins = $res->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $res = $conn->prepare("SELECT u.user_id, u.username, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' ORDER BY u.user_id ASC");
    $res->execute();
    $admins = $res->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Admins | Café</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg:      #0c0c0c;
    --card:    #161616;
    --border:  #222;
    --accent:  #d1904b;
    --accent2: #b57b3b;
    --text:    #f0f0f0;
    --muted:   #888;
    --success: #3ecf70;
    --danger:  #ff4d4d;
    --warning: #f5a623;
    --radius:  14px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: Poppins, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
}

/* ── NAV ── */
.topnav {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10,10,10,.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 14px 24px;
    display: flex; align-items: center; gap: 12px;
}
.back-btn {
    display: flex; align-items: center; gap: 7px;
    color: #d1904b; text-decoration: none;
    font-weight: 600; font-size: 13px;
    padding: 7px 14px; border: 1px solid rgba(209,144,75,.35);
    border-radius: 10px; background: rgba(209,144,75,.08);
    transition: background .2s, border-color .2s;
}
.back-btn:hover { background: rgba(209,144,75,.16); border-color: #d1904b; }
.nav-title {
    font-size: 14px; font-weight: 600; color: var(--text);
    margin-left: 4px;
}
.admin-count-chip {
    margin-left: auto;
    background: rgba(209,144,75,.12);
    border: 1px solid rgba(209,144,75,.25);
    color: var(--accent); font-size: 11px; font-weight: 600;
    padding: 4px 12px; border-radius: 20px;
    display: flex; align-items: center; gap: 5px;
}

/* ── LAYOUT ── */
.page-wrap {
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 20px 60px;
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 740px) { .page-wrap { grid-template-columns: 1fr; } }

/* ── SECTION CARD ── */
.section-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.section-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.section-head i { color: var(--accent); font-size: 14px; }
.section-head h3 { font-size: 14px; font-weight: 600; }
.section-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }

/* ── FORM ── */
.field { display: flex; flex-direction: column; gap: 6px; }
.flabel { font-size: 12px; font-weight: 500; color: #c0a070; letter-spacing: .2px; }
.input-wrap { position: relative; }
.input-wrap .iicon {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 14px; pointer-events: none;
    transition: color .2s;
}
.input-wrap input {
    width: 100%; padding: 11px 40px;
    border-radius: 10px; border: 1px solid var(--border);
    background: #0f0f0f; color: var(--text);
    font-family: Poppins, sans-serif; font-size: 14px;
    transition: border-color .2s, box-shadow .2s;
}
.input-wrap input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(209,144,75,.12); }
.input-wrap input:focus ~ .iicon { color: var(--accent); }
.toggle-pw {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    color: var(--muted); cursor: pointer; font-size: 14px;
    transition: color .2s;
}
.toggle-pw:hover { color: var(--accent); }

/* username status */
.field-hint {
    font-size: 11px; display: flex; align-items: center; gap: 5px;
    height: 16px; transition: color .2s;
}
.field-hint.ok    { color: var(--success); }
.field-hint.err   { color: var(--danger); }
.field-hint.info  { color: var(--muted); }

/* password strength */
.pw-strength { display: flex; gap: 5px; margin-top: 4px; }
.pw-bar {
    flex: 1; height: 3px; border-radius: 99px;
    background: #2a2a2a; transition: background .3s;
}
.pw-label { font-size: 11px; color: var(--muted); margin-top: 4px; }

/* confirm match */
.match-icon {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    font-size: 14px; pointer-events: none;
}

/* ── SUBMIT ── */
.btn-save {
    width: 100%; padding: 13px;
    border: none; border-radius: var(--radius);
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    color: #000; font-size: 14px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: 8px;
    transition: opacity .2s, transform .15s;
    font-family: Poppins, sans-serif;
    margin-top: 4px;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }

/* ── MESSAGE ── */
.msg-bar {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: 12px;
    font-size: 13px; font-weight: 500;
    animation: slideDown .3s ease;
}
@keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
.msg-bar.success { background: rgba(62,207,112,.08); color: var(--success); border: 1px solid rgba(62,207,112,.2); }
.msg-bar.error   { background: rgba(255,77,77,.08);  color: var(--danger);  border: 1px solid rgba(255,77,77,.2); }

/* ── ADMIN LIST ── */
.admin-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    border-bottom: 1px solid #1a1a1a;
    transition: background .15s;
}
.admin-item:last-child { border-bottom: none; }
.admin-item:hover { background: #1a1a1a; }

.admin-avatar {
    width: 40px; height: 40px; border-radius: 12px;
    background: linear-gradient(135deg, #1e1e1e, #2a2a2a);
    border: 1px solid #2e2e2e;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 700; color: var(--accent);
    flex-shrink: 0; text-transform: uppercase;
}
.admin-name { font-size: 14px; font-weight: 600; }
.admin-meta { font-size: 11px; color: var(--muted); margin-top: 2px; display: flex; gap: 8px; align-items: center; }

.badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 600; padding: 2px 8px;
    border-radius: 20px;
}
.badge.you  { background: rgba(93,173,226,.12); color: #5dade2; border: 1px solid rgba(93,173,226,.25); }
.badge.role { background: rgba(209,144,75,.1);  color: var(--accent); border: 1px solid rgba(209,144,75,.15); }

.delete-btn {
    margin-left: auto; flex-shrink: 0;
    width: 32px; height: 32px; border-radius: 9px;
    border: 1px solid #2a2a2a; background: transparent;
    color: var(--muted); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; transition: background .2s, border-color .2s, color .2s;
}
.delete-btn:hover { background: rgba(255,77,77,.1); border-color: rgba(255,77,77,.3); color: var(--danger); }
.delete-btn:disabled { opacity: .3; cursor: not-allowed; pointer-events: none; }

.empty-admins {
    text-align: center; padding: 40px 20px; color: var(--muted); font-size: 13px;
}
.empty-admins i { font-size: 28px; opacity: .3; display: block; margin-bottom: 8px; }

/* ── DELETE MODAL ── */
.modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.7);
    z-index: 200; display: flex;
    align-items: center; justify-content: center;
    opacity: 0; pointer-events: none;
    transition: opacity .25s;
}
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 18px; padding: 28px;
    width: 360px; max-width: 90vw;
    transform: scale(.94); transition: transform .25s;
}
.modal-backdrop.open .modal { transform: scale(1); }
.modal-icon {
    width: 48px; height: 48px; border-radius: 14px;
    background: rgba(255,77,77,.1); border: 1px solid rgba(255,77,77,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: var(--danger); margin-bottom: 14px;
}
.modal h3 { font-size: 17px; margin-bottom: 6px; }
.modal p  { font-size: 13px; color: var(--muted); line-height: 1.6; }
.modal-name { color: var(--text); font-weight: 600; }
.modal-actions { display: flex; gap: 10px; margin-top: 22px; }
.btn-cancel {
    flex: 1; padding: 11px; border-radius: 10px;
    border: 1px solid var(--border); background: #111;
    color: var(--muted); font-family: Poppins, sans-serif;
    font-size: 14px; cursor: pointer;
    transition: border-color .2s, color .2s;
}
.btn-cancel:hover { border-color: #3a3a3a; color: var(--text); }
.btn-delete {
    flex: 1; padding: 11px; border-radius: 10px;
    border: none; background: var(--danger);
    color: #fff; font-family: Poppins, sans-serif;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: opacity .2s;
}
.btn-delete:hover { opacity: .85; }

/* ── ANIMATIONS ── */
@keyframes fadeDown  { from { opacity:0; transform:translateY(-18px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeUp    { from { opacity:0; transform:translateY(22px);  } to { opacity:1; transform:translateY(0); } }
@keyframes slideLeft { from { opacity:0; transform:translateX(-22px); } to { opacity:1; transform:translateX(0); } }
@keyframes slideRight{ from { opacity:0; transform:translateX(22px);  } to { opacity:1; transform:translateX(0); } }
@keyframes popIn     { from { opacity:0; transform:scale(.88);        } to { opacity:1; transform:scale(1);     } }
@keyframes floatA    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-28px,22px)} }
@keyframes floatB    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(22px,-28px)} }
@keyframes shimmer   { 0%{background-position:-200% center} 100%{background-position:200% center} }

.topnav   { animation: fadeDown  .45s ease both; }
.left-col { animation: slideLeft .5s  .1s ease both; }
.right-col{ animation: slideRight .5s .18s ease both; }

.section-card { position: relative; overflow: hidden; }
.section-head::after {
    content: '';
    position: absolute; left: 0; right: 0; bottom: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(209,144,75,.35), transparent);
    background-size: 200% auto;
    animation: shimmer 3s linear infinite;
}
.section-head { position: relative; }

/* ambient orbs */
.orb {
    position: fixed; border-radius: 50%; filter: blur(90px);
    pointer-events: none; z-index: 0;
}
.orb-a {
    width: 420px; height: 420px;
    background: radial-gradient(circle, rgba(209,144,75,.18) 0%, transparent 70%);
    top: -120px; right: -120px;
    animation: floatA 9s ease-in-out infinite;
}
.orb-b {
    width: 320px; height: 320px;
    background: radial-gradient(circle, rgba(93,173,226,.12) 0%, transparent 70%);
    bottom: -80px; left: -80px;
    animation: floatB 11s ease-in-out infinite;
}
.page-wrap { position: relative; z-index: 1; }

/* avatar colour palette */
.admin-avatar {
    transition: transform .2s, box-shadow .2s;
}
.admin-avatar:hover {
    transform: scale(1.1) rotate(-3deg);
    box-shadow: 0 0 0 2px rgba(209,144,75,.4);
}
.admin-item {
    opacity: 0;
    animation: fadeUp .4s ease forwards;
    transition: background .15s, transform .15s;
}
.admin-item:hover { transform: translateX(4px); }

/* msg bar pop */
.msg-bar { animation: popIn .35s ease both; }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 24px; right: 24px;
    padding: 13px 18px; border-radius: 12px;
    font-weight: 600; font-size: 13px;
    display: flex; align-items: center; gap: 8px;
    transform: translateY(80px); opacity: 0;
    transition: transform .35s ease, opacity .35s ease;
    z-index: 500; pointer-events: none;
}
.toast.success { background: #1e2e20; border: 1px solid rgba(62,207,112,.35); color: var(--success); }
.toast.error   { background: #2a1515; border: 1px solid rgba(255,77,77,.35);  color: var(--danger); }
.toast.show    { transform: translateY(0); opacity: 1; }
</style>
</head>
<body>
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<!-- NAV -->
<nav class="topnav">
    <a class="back-btn" href="dashboard.php">
        <i class="fa-solid fa-arrow-left"></i> Dashboard
    </a>
    <span class="nav-title">Admin Management</span>
    <div class="admin-count-chip">
        <i class="fa-solid fa-users" style="font-size:10px"></i>
        <?= count($admins) ?> admin<?= count($admins) !== 1 ? 's' : '' ?>
    </div>
</nav>

<div class="page-wrap">

    <!-- LEFT: ADD FORM -->
    <div class="left-col" style="display:flex;flex-direction:column;gap:18px;">

        <?php if ($message): ?>
        <div class="msg-bar <?= $message_type ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="section-card">
            <div class="section-head">
                <i class="fa-solid fa-user-plus"></i>
                <h3>Add New Admin</h3>
            </div>
            <div class="section-body">
                <form method="POST" id="addForm" autocomplete="off">
                    <input type="hidden" name="action" value="add">

                    <!-- Username -->
                    <div class="field" style="margin-bottom:4px">
                        <label class="flabel">Username</label>
                        <div class="input-wrap">
                            <i class="iicon fa-solid fa-user"></i>
                            <input type="text" name="username" id="f_user"
                                placeholder="e.g. johndoe" minlength="3" required
                                autocomplete="new-password">
                        </div>
                        <div class="field-hint info" id="userHint">
                            <i class="fa-solid fa-circle-dot" style="font-size:8px"></i>
                            Min. 3 characters
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="field">
                        <label class="flabel">Password</label>
                        <div class="input-wrap">
                            <i class="iicon fa-solid fa-lock"></i>
                            <input type="password" name="password" id="f_pw"
                                placeholder="Min. 6 characters" required
                                autocomplete="new-password">
                            <span class="toggle-pw" onclick="togglePw('f_pw','pwEye')">
                                <i class="fa-solid fa-eye" id="pwEye"></i>
                            </span>
                        </div>
                        <div class="pw-strength" id="pwStrength">
                            <div class="pw-bar" id="bar0"></div>
                            <div class="pw-bar" id="bar1"></div>
                            <div class="pw-bar" id="bar2"></div>
                            <div class="pw-bar" id="bar3"></div>
                        </div>
                        <div class="pw-label" id="pwLabel">Enter a password</div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="field">
                        <label class="flabel">Confirm Password</label>
                        <div class="input-wrap">
                            <i class="iicon fa-solid fa-lock-open"></i>
                            <input type="password" name="confirm_password" id="f_confirm"
                                placeholder="Repeat password" required
                                autocomplete="new-password">
                            <span class="toggle-pw" onclick="togglePw('f_confirm','cfEye')">
                                <i class="fa-solid fa-eye" id="cfEye"></i>
                            </span>
                            <i class="match-icon" id="matchIcon"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-save" style="margin-top:8px">
                        <i class="fa-solid fa-plus"></i>
                        Create Admin Account
                    </button>
                </form>
            </div>
        </div>

        <!-- INFO CARD -->
        <div class="section-card">
            <div class="section-body" style="flex-direction:row;gap:12px;align-items:flex-start">
                <i class="fa-solid fa-circle-info" style="color:var(--accent);margin-top:2px;flex-shrink:0"></i>
                <div style="font-size:12px;color:var(--muted);line-height:1.7">
                    Admin accounts have full access to the dashboard, products, ingredients, orders, and settings.
                    Only create accounts for trusted staff members.
                </div>
            </div>
        </div>

    </div>

    <!-- RIGHT: ADMIN LIST -->
    <div class="section-card right-col">
        <div class="section-head">
            <i class="fa-solid fa-users"></i>
            <h3>Admin Accounts</h3>
        </div>

        <?php if (empty($admins)): ?>
        <div class="empty-admins">
            <i class="fa-solid fa-user-slash"></i>
            No admin accounts found
        </div>
        <?php else: ?>
        <div id="adminList">
        <?php
        $avatarPalette = [
            '#d1904b','#5dade2','#3ecf70','#e74c3c','#9b59b6',
            '#1abc9c','#e67e22','#2980b9','#27ae60','#c0392b'
        ];
        foreach ($admins as $idx => $a):
            $isYou    = ($a['user_id'] === $currentUserId);
            $initials = strtoupper(substr($a['username'], 0, 1));
            $created  = isset($a['created_at']) && $a['created_at']
                ? date('M j, Y', strtotime($a['created_at'])) : null;
            $avatarColor = $avatarPalette[$idx % count($avatarPalette)];
            $delay = round(0.18 + $idx * 0.07, 2) . 's';
        ?>
        <div class="admin-item" id="admin-row-<?= $a['user_id'] ?>" style="animation-delay:<?= $delay ?>">
            <div class="admin-avatar" style="color:<?= $avatarColor ?>;border-color:<?= $avatarColor ?>33;background:linear-gradient(135deg,#1a1a1a,<?= $avatarColor ?>18)"><?= htmlspecialchars($initials) ?></div>
            <div>
                <div class="admin-name">
                    <?= htmlspecialchars($a['username']) ?>
                    <?php if ($isYou): ?>
                    <span class="badge you" style="margin-left:6px">You</span>
                    <?php endif; ?>
                </div>
                <div class="admin-meta">
                    <span class="badge role">Admin</span>
                    <?php if ($created): ?>
                    <span>Joined <?= $created ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <button class="delete-btn" <?= $isYou ? 'disabled title="Cannot delete your own account"' : '' ?>
                onclick="openDeleteModal(<?= $a['user_id'] ?>, '<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>')">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <div class="modal-icon"><i class="fa-solid fa-trash-can"></i></div>
        <h3>Delete Admin Account</h3>
        <p>This will permanently remove <span class="modal-name" id="modalName">—</span>'s access to the dashboard. This cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-delete" id="confirmDeleteBtn" onclick="executeDelete()">Delete Account</button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
let pendingDeleteId = null;

// ── Username availability check ──
const fUser = document.getElementById('f_user');
const userHint = document.getElementById('userHint');
let usernameTimer;
fUser.addEventListener('input', () => {
    clearTimeout(usernameTimer);
    const val = fUser.value.trim();
    if (val.length < 3) {
        userHint.className = 'field-hint info';
        userHint.innerHTML = '<i class="fa-solid fa-circle-dot" style="font-size:8px"></i> Min. 3 characters';
        return;
    }
    userHint.className = 'field-hint info';
    userHint.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:10px"></i> Checking...';
    usernameTimer = setTimeout(async () => {
        try {
            const r = await fetch(`manage_admin.php?check_username=${encodeURIComponent(val)}`);
            const d = await r.json();
            if (d.available) {
                userHint.className = 'field-hint ok';
                userHint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Available';
            } else {
                userHint.className = 'field-hint err';
                userHint.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Username taken';
            }
        } catch { userHint.className = 'field-hint info'; userHint.innerHTML = ''; }
    }, 420);
});

// ── Password strength ──
const fPw = document.getElementById('f_pw');
const bars = [0,1,2,3].map(i => document.getElementById('bar'+i));
const pwLabel = document.getElementById('pwLabel');
const colors = { weak:'#ff4d4d', medium:'#f5a623', strong:'#3ecf70', very:'#3ecf70' };
const labels = { 0:'', 1:'Weak', 2:'Fair', 3:'Good', 4:'Strong' };

fPw.addEventListener('input', () => {
    const pw = fPw.value;
    let score = 0;
    if (pw.length >= 6)  score++;
    if (pw.length >= 10) score++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw) && /[^a-zA-Z0-9]/.test(pw)) score++;
    const col = score <= 1 ? colors.weak : score === 2 ? colors.medium : colors.strong;
    bars.forEach((b, i) => { b.style.background = i < score ? col : '#2a2a2a'; });
    pwLabel.textContent = pw.length === 0 ? 'Enter a password' : labels[score];
    pwLabel.style.color = pw.length === 0 ? 'var(--muted)' : col;
    checkMatch();
});

// ── Password match ──
const fConfirm = document.getElementById('f_confirm');
const matchIcon = document.getElementById('matchIcon');
fConfirm.addEventListener('input', checkMatch);
function checkMatch() {
    if (!fConfirm.value) { matchIcon.innerHTML = ''; return; }
    if (fPw.value === fConfirm.value) {
        matchIcon.innerHTML = '<i class="fa-solid fa-check" style="color:var(--success)"></i>';
    } else {
        matchIcon.innerHTML = '<i class="fa-solid fa-xmark" style="color:var(--danger)"></i>';
    }
}

// ── Show/hide password ──
function togglePw(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa-solid fa-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'fa-solid fa-eye'; }
}

// ── Delete modal ──
function openDeleteModal(id, name) {
    pendingDeleteId = id;
    document.getElementById('modalName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('open');
    pendingDeleteId = null;
}
document.getElementById('deleteModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

async function executeDelete() {
    if (!pendingDeleteId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.textContent = 'Deleting...'; btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', pendingDeleteId);
        const r = await fetch('delete_admin.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            const row = document.getElementById('admin-row-' + pendingDeleteId);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(() => row.remove(), 300); }
            showToast('Admin account deleted.', 'success');
            // update count chip
            const chip = document.querySelector('.admin-count-chip');
            if (chip) {
                const cur = parseInt(chip.textContent) - 1;
                chip.innerHTML = `<i class="fa-solid fa-users" style="font-size:10px"></i> ${cur} admin${cur !== 1 ? 's' : ''}`;
            }
        } else {
            showToast(d.error || 'Delete failed.', 'error');
        }
    } catch { showToast('Network error.', 'error'); }
    closeModal();
    btn.textContent = 'Delete Account'; btn.disabled = false;
}

// ── Toast ──
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.className = 'toast ' + type;
    t.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${msg}`;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

<?php if ($message && $message_type === 'success'): ?>
setTimeout(() => showToast(<?= json_encode($message) ?>, 'success'), 200);
<?php endif; ?>
</script>
</body>
</html>
