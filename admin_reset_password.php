<?php
require 'auth.php';
require 'config.php';
if (!can('reset_password')) { header('Location: dashboard.php?denied=1'); exit; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$toast      = '';
$toast_type = '';

// ── Handle POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $toast = "Invalid request. Please try again.";
        $toast_type = 'error';
        goto render;
    }

    $action  = $_POST['action']  ?? '';
    $target  = (int)($_POST['target_user_id'] ?? 0);

    // Force-reset: generate a temporary password and set must_change_password
    if ($action === 'force_reset' && $target > 0) {
        // Manager cannot reset admin passwords — only admin can do that
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $check = $conn->prepare("SELECT r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.user_id = ?");
            $check->bind_param("i", $target);
            $check->execute();
            $tgt_role = $check->get_result()->fetch_assoc()['role'] ?? '';
            if ($tgt_role === 'admin') {
                $toast = "You cannot reset an admin password.";
                $toast_type = 'error';
                goto render;
            }
        }

        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower   = 'abcdefghjkmnpqrstuvwxyz';
        $digits  = '23456789';
        $special = '!@#$%';
        $pool    = [$upper, $lower, $lower, $digits, $digits, $special, $upper];
        $chars   = array_map(fn($s) => $s[random_int(0, strlen($s) - 1)], $pool);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        $tmp_pass = implode('', $chars);

        $hashed = password_hash($tmp_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "UPDATE users SET password = ?, must_change_password = 1, security_question = NULL, security_answer = NULL WHERE user_id = ?"
        );
        $stmt->bind_param("si", $hashed, $target);
        $stmt->execute();

        $toast      = "Password reset. Temporary password: {$tmp_pass} — Give this to the employee and ask them to change it immediately.";
        $toast_type = 'info';
    }

    // Remove must_change flag manually
    elseif ($action === 'clear_flag' && $target > 0) {
        $stmt = $conn->prepare("UPDATE users SET must_change_password = 0 WHERE user_id = ?");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $toast = "Must-change flag removed.";
        $toast_type = 'success';
    }
}

render:
// ── Load role metadata from DB (same source as manage_roles.php) ──
$role_meta = [];
$rm_res = $conn->query("SELECT slug, name, icon, color FROM roles");
while ($rm = $rm_res->fetch_assoc()) $role_meta[$rm['slug']] = $rm;

// ── Load users: admin sees everyone; manager cannot see/reset other admins ──
$_is_admin_role = ($_SESSION['role'] ?? '') === 'admin';
$_user_where    = $_is_admin_role ? "1=1" : "r.slug != 'admin'";
$users_result = $conn->query(
    "SELECT u.user_id, u.username, r.slug AS role, u.security_question, u.must_change_password
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE {$_user_where}
     ORDER BY r.slug DESC, u.username ASC"
);
$all_users = [];
while ($r = $users_result->fetch_assoc()) $all_users[] = $r;

$total        = count($all_users);
$sq_set       = count(array_filter($all_users, fn($u) => !empty($u['security_question'])));
$must_change  = count(array_filter($all_users, fn($u) => $u['must_change_password']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password Management | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --accent:       #d1904b;
    --accent-light: #e8b87a;
    --accent-dark:  #a0702a;
    --bg:           #0b0b0b;
    --card:         #111;
    --border:       rgba(255,255,255,0.07);
    --text:         #f5f5f5;
    --text-muted:   #777;
    --danger:       #e74c3c;
    --success:      #55e087;
    --warning:      #f39c12;
    --info:         #3498db;
}
@keyframes fadeInUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
@keyframes scaleIn  { from{opacity:0;transform:scale(.94)}       to{opacity:1;transform:scale(1)}     }
@keyframes rowIn    { from{opacity:0;transform:translateX(-6px)}  to{opacity:1;transform:translateX(0)} }
@keyframes fadeIn   { from{opacity:0}                             to{opacity:1}                         }

*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family:'Poppins',sans-serif;
    background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),var(--bg);
    color:var(--text); min-height:100vh;
}

/* Topbar */
.topbar {
    position:sticky; top:0; z-index:100;
    background:rgba(10,10,10,0.95); border-bottom:1px solid var(--border);
    backdrop-filter:blur(12px);
    display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; height:62px;
    animation:fadeIn .35s ease both;
}
.back-btn {
    display:inline-flex; align-items:center; gap:8px;
    text-decoration:none; color:var(--text-muted);
    font-size:13px; font-weight:500; padding:6px 12px;
    border-radius:8px; border:1px solid var(--border);
    transition:all .2s;
}
.back-btn:hover { color:var(--accent); border-color:rgba(209,144,75,.3); background:rgba(209,144,75,.06); }
.page-title { font-size:15px; font-weight:700; }
.page-title span { color:var(--accent); }

/* Layout */
.page-wrap { max-width:960px; margin:0 auto; padding:28px 20px 60px; }

/* Stat strip */
.stat-strip { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:26px; }
.stat-card {
    background:var(--card); border:1px solid var(--border); border-radius:14px;
    padding:18px 20px; display:flex; align-items:center; gap:14px;
    animation:scaleIn .45s cubic-bezier(.16,1,.3,1) both;
    transition:transform .2s,border-color .2s;
}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.12)}
.stat-card:nth-child(1){animation-delay:.08s}
.stat-card:nth-child(2){animation-delay:.15s}
.stat-card:nth-child(3){animation-delay:.22s}
.stat-icon {
    width:44px; height:44px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:18px;
}
.stat-val  { font-size:22px; font-weight:800; color:var(--text); }
.stat-lbl  { font-size:11.5px; color:var(--text-muted); margin-top:1px; }

/* Table card */
.card {
    background:var(--card); border:1px solid var(--border); border-radius:18px; overflow:hidden;
    animation:fadeInUp .45s ease .28s both;
}
.card-header {
    padding:20px 24px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
}
.card-header-left { display:flex; align-items:center; gap:14px; }
.card-icon {
    width:40px; height:40px; border-radius:11px;
    background:linear-gradient(135deg,rgba(209,144,75,.2),rgba(209,144,75,.05));
    border:1px solid rgba(209,144,75,.2);
    display:flex; align-items:center; justify-content:center;
    color:var(--accent); font-size:16px; flex-shrink:0;
}
.card-title    { font-size:15px; font-weight:700; }
.card-subtitle { font-size:12px; color:var(--text-muted); margin-top:1px; }

/* Table */
.tbl-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead th {
    padding:12px 18px; font-size:11px; font-weight:600;
    color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px;
    border-bottom:1px solid var(--border); text-align:left; white-space:nowrap;
}
tbody td { padding:14px 18px; font-size:13.5px; border-bottom:1px solid rgba(255,255,255,0.04); vertical-align:middle; transition:background .15s; }
tbody tr:last-child td { border-bottom:none; }
tbody tr:hover td { background:rgba(255,255,255,0.03); }
tbody tr { animation:rowIn .3s ease both; }
tbody tr:nth-child(1){animation-delay:.32s} tbody tr:nth-child(2){animation-delay:.36s}
tbody tr:nth-child(3){animation-delay:.40s} tbody tr:nth-child(4){animation-delay:.44s}
tbody tr:nth-child(5){animation-delay:.48s} tbody tr:nth-child(6){animation-delay:.52s}
tbody tr:nth-child(7){animation-delay:.56s} tbody tr:nth-child(8){animation-delay:.60s}
tbody tr:nth-child(9){animation-delay:.64s} tbody tr:nth-child(10){animation-delay:.68s}

/* Badges */
.badge {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap;
}
.badge-ok      { background:rgba(85,224,135,.1);  color:var(--success);  border:1px solid rgba(85,224,135,.25); }
.badge-missing { background:rgba(243,156,18,.1);  color:var(--warning);  border:1px solid rgba(243,156,18,.25); }
.badge-alert   { background:rgba(231,76,60,.1);   color:#e87070;          border:1px solid rgba(231,76,60,.25); }

/* Buttons */
.btn-reset {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 14px; border-radius:8px;
    background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3);
    color:#e87070; font-size:12px; font-weight:600;
    cursor:pointer; font-family:'Poppins',sans-serif;
    transition:all .2s;
}
.btn-reset:hover { background:rgba(231,76,60,.2); border-color:rgba(231,76,60,.5); }
.btn-sm {
    display:inline-flex; align-items:center; gap:5px;
    padding:5px 11px; border-radius:7px; font-size:11.5px; font-weight:600;
    cursor:pointer; font-family:'Poppins',sans-serif; border:1px solid var(--border);
    background:rgba(255,255,255,.04); color:var(--text-muted); transition:all .2s;
}
.btn-sm:hover { color:var(--text); border-color:rgba(255,255,255,.15); background:rgba(255,255,255,.07); }

/* Toast */
.toast {
    position:fixed; bottom:28px; right:28px; z-index:9999;
    display:flex; align-items:flex-start; gap:12px;
    padding:16px 20px; border-radius:14px;
    font-size:13px; font-weight:500;
    box-shadow:0 8px 32px rgba(0,0,0,.5);
    animation:toastIn .4s ease both;
    max-width:460px; min-width:280px;
}
.toast.success { background:#1a3a28; border:1px solid rgba(85,224,135,.3); color:var(--success); }
.toast.error   { background:#3a1a1a; border:1px solid rgba(231,76,60,.3);  color:#e87070; }
.toast.info    { background:#0d2035; border:1px solid rgba(52,152,219,.35); color:#5dade2; }
.toast i       { font-size:16px; flex-shrink:0; margin-top:1px; }
.toast-body    { flex:1; line-height:1.5; }
.toast-copy {
    background:rgba(255,255,255,.1); border:none; border-radius:6px;
    color:inherit; font-size:11px; font-weight:600; cursor:pointer;
    padding:3px 8px; margin-top:6px; display:inline-block;
    font-family:'Poppins',sans-serif;
}
@keyframes toastIn  { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
@keyframes toastOut { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(12px)} }

/* Confirm overlay */
.overlay {
    display:none; position:fixed; inset:0; z-index:500;
    background:rgba(0,0,0,.7); backdrop-filter:blur(4px);
    align-items:center; justify-content:center;
}
.overlay.active { display:flex; }
.modal {
    background:#161616; border:1px solid var(--border);
    border-radius:20px; padding:30px 28px; max-width:400px; width:90%;
    animation:toastIn .35s ease both;
}
.modal-icon {
    width:56px; height:56px; border-radius:50%;
    background:rgba(231,76,60,.1); border:2px solid rgba(231,76,60,.25);
    display:flex; align-items:center; justify-content:center;
    font-size:22px; color:var(--danger); margin:0 auto 16px;
}
.modal-title { font-size:18px; font-weight:800; text-align:center; margin-bottom:8px; }
.modal-msg   { font-size:13px; color:var(--text-muted); text-align:center; line-height:1.6; margin-bottom:22px; }
.modal-btns  { display:flex; gap:10px; }
.modal-btns button { flex:1; height:44px; border-radius:10px; font-family:'Poppins',sans-serif; font-size:14px; font-weight:600; cursor:pointer; border:none; }
.btn-cancel  { background:rgba(255,255,255,.06); color:var(--text-muted); }
.btn-confirm { background:linear-gradient(135deg,#e74c3c,#c0392b); color:#fff; }

@media(max-width:640px) {
    .stat-strip { grid-template-columns:1fr; }
    .page-wrap  { padding:18px 14px 50px; }
}

/* Light theme */
[data-theme="light"] {
    --bg:         #ECEEF2;
    --card:       #FFFFFF;
    --border:     rgba(0,0,0,0.08);
    --text:       #111827;
    --text-muted: #5A6373;
    --danger:     #dc2626;
    --success:    #16a34a;
    --warning:    #d97706;
    --info:       #2563eb;
}
[data-theme="light"] .topbar   { background:rgba(236,238,242,.95); }
[data-theme="light"] .back-btn { background:transparent; }
[data-theme="light"] .stat-card { box-shadow:0 1px 4px rgba(0,0,0,.06); }
[data-theme="light"] tbody tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] tbody td  { border-color:rgba(0,0,0,.05); }
[data-theme="light"] thead th  { border-color:rgba(0,0,0,.08); }
[data-theme="light"] .modal    { background:#fff; }
[data-theme="light"] .btn-cancel { background:rgba(0,0,0,.06); }
[data-theme="light"] .toast.success { background:#dcfce7; color:#16a34a; border-color:rgba(22,163,74,.3); }
[data-theme="light"] .toast.error   { background:#fee2e2; color:#dc2626; border-color:rgba(220,38,38,.3); }
[data-theme="light"] .toast.info    { background:#dbeafe; color:#1d4ed8; border-color:rgba(29,78,216,.3); }
[data-theme="light"] .toast-copy    { background:rgba(0,0,0,.1); color:inherit; }
</style>
<script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
</head>
<body>

<div class="topbar">
    <div style="display:flex;align-items:center;gap:16px">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <span class="page-title">Password <span>Management</span></span>
    </div>
    <a href="profile.php" style="text-decoration:none">
        <div style="display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:20px">
            <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-dark));display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;font-weight:700">
                <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
            </div>
            <div style="font-size:12px;font-weight:600;color:var(--text)"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></div>
        </div>
    </a>
</div>

<div class="page-wrap">

    <!-- Stats -->
    <div class="stat-strip">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(209,144,75,.1);color:var(--accent)"><i class="fa-solid fa-users"></i></div>
            <div><div class="stat-val"><?= $total ?></div><div class="stat-lbl">Total Staff Accounts</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(85,224,135,.1);color:var(--success)"><i class="fa-solid fa-shield-check"></i></div>
            <div>
                <div class="stat-val"><?= $sq_set ?> <span style="font-size:14px;color:var(--text-muted)">/ <?= $total ?></span></div>
                <div class="stat-lbl">Security Questions Set</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(231,76,60,.1);color:var(--danger)"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="stat-val" style="color:<?= $must_change > 0 ? 'var(--warning)' : 'var(--success)' ?>"><?= $must_change ?></div>
                <div class="stat-lbl">Pending Password Change</div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-icon"><i class="fa-solid fa-key"></i></div>
                <div>
                    <div class="card-title">Staff Accounts</div>
                    <div class="card-subtitle">Force-reset a password or view security question status</div>
                </div>
            </div>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Security Question</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_users as $i => $u): ?>
                <tr>
                    <td style="color:var(--text-muted);font-size:12px"><?= $i + 1 ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,rgba(209,144,75,.2),rgba(209,144,75,.06));border:1px solid rgba(209,144,75,.2);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--accent);flex-shrink:0">
                                <?= strtoupper(substr($u['username'], 0, 1)) ?>
                            </div>
                            <span style="font-weight:600"><?= htmlspecialchars($u['username']) ?></span>
                        </div>
                    </td>
                    <td>
                        <?php
                            $rm  = $role_meta[$u['role']] ?? null;
                            $rc  = $rm['color'] ?? '#888888';
                            $ri  = $rm['icon']  ?? 'fa-user';
                            $rl  = $rm['name']  ?? ucwords(str_replace('_', ' ', $u['role']));
                        ?>
                        <span class="badge" style="background:<?= $rc ?>22;color:<?= $rc ?>;border:1px solid <?= $rc ?>44">
                            <i class="fa-solid <?= htmlspecialchars($ri) ?>"></i>
                            <?= htmlspecialchars($rl) ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($u['security_question'])): ?>
                        <span class="badge badge-ok"><i class="fa-solid fa-check"></i> Set</span>
                        <?php else: ?>
                        <span class="badge badge-missing"><i class="fa-solid fa-exclamation"></i> Not set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['must_change_password']): ?>
                        <span class="badge badge-alert"><i class="fa-solid fa-clock"></i> Must change</span>
                        <?php else: ?>
                        <span class="badge badge-ok"><i class="fa-solid fa-circle-check"></i> Active</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
                            <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
                            <button class="btn-reset" onclick="confirmReset(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-rotate-right"></i> Reset
                            </button>
                            <?php if ($u['must_change_password']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="clear_flag">
                                <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                                <button type="submit" class="btn-sm"><i class="fa-solid fa-check"></i> Clear flag</button>
                            </form>
                            <?php endif; ?>
                            <?php else: ?>
                            <a href="profile.php" style="text-decoration:none">
                                <span class="btn-sm"><i class="fa-solid fa-pen"></i> Edit my profile</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($all_users)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:40px">No staff accounts found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Help note -->
    <div style="margin-top:18px;padding:14px 18px;background:rgba(52,152,219,.06);border:1px solid rgba(52,152,219,.2);border-radius:12px;display:flex;gap:12px;align-items:flex-start;animation:fadeInUp .4s ease .5s both">
        <i class="fa-solid fa-circle-info" style="color:var(--info);margin-top:2px;flex-shrink:0"></i>
        <div style="font-size:12.5px;color:rgba(255,255,255,.5);line-height:1.6">
            <strong style="color:rgba(255,255,255,.7)">How force-reset works:</strong>
            A temporary password is generated and the account is flagged to require a password change on next login.
            Verbally give the temporary password to the employee. Their security question is cleared and must be re-set after they log in.
        </div>
    </div>

</div>

<!-- Confirm modal -->
<div class="overlay" id="confirmOverlay">
    <div class="modal">
        <div class="modal-icon"><i class="fa-solid fa-rotate-right"></i></div>
        <h2 class="modal-title">Reset Password?</h2>
        <p class="modal-msg">This will generate a new temporary password for <strong id="resetUsername"></strong> and require them to change it on next login. Their security question will also be cleared.</p>
        <form method="POST" id="resetForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="force_reset">
            <input type="hidden" name="target_user_id" id="resetUserId">
            <div class="modal-btns">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa-solid fa-rotate-right"></i> Reset</button>
            </div>
        </form>
    </div>
</div>

<!-- Toast -->
<?php if ($toast): ?>
<div class="toast <?= $toast_type ?>" id="toastEl">
    <i class="fa-solid fa-<?= $toast_type === 'success' ? 'circle-check' : ($toast_type === 'info' ? 'key' : 'circle-exclamation') ?>"></i>
    <div class="toast-body">
        <?= htmlspecialchars($toast) ?>
        <?php if ($toast_type === 'info'): ?>
        <br><button class="toast-copy" onclick="copyPass(this)">Copy password</button>
        <?php endif; ?>
    </div>
</div>
<script>
<?php if ($toast_type === 'info'): ?>
function copyPass(btn){
    // extract just the password part between ": " and " —"
    var full = <?= json_encode($toast) ?>;
    var m = full.match(/:\s([^\s—]+)/);
    var pass = m ? m[1] : '';
    navigator.clipboard.writeText(pass).then(function(){btn.textContent='Copied!';});
}
<?php endif; ?>

// Auto-dismiss after 12s (longer because admin needs to copy the password)
setTimeout(function(){
    var t = document.getElementById('toastEl');
    if(t){t.style.animation='toastOut .4s ease forwards';setTimeout(function(){if(t)t.remove();},400);}
}, 12000);
</script>
<?php endif; ?>

<script>
function confirmReset(userId, username) {
    document.getElementById('resetUserId').value   = userId;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('confirmOverlay').classList.add('active');
}
function closeModal() {
    document.getElementById('confirmOverlay').classList.remove('active');
}
document.getElementById('confirmOverlay').addEventListener('click', function(e){
    if(e.target === this) closeModal();
});
</script>
</body>
</html>
