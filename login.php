<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'config.php';

// ── GET-param messages (from redirect) ──
$error = '';
if (isset($_GET['timeout']))       $error = 'Your session expired due to inactivity. Please sign in again.';
elseif (isset($_GET['error'])) {
    if ($_GET['error'] === 'locked') $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    else {
        $left  = isset($_GET['left']) ? (int)$_GET['left'] : null;
        $error = 'Invalid username or password.' . ($left !== null && $left > 0 ? " $left attempt(s) remaining before lockout." : '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ── Rate limiting: max 5 attempts per 15 minutes per IP ──
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $rate = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $rate->bind_param("s", $ip);
    $rate->execute();
    $attempts = (int)$rate->get_result()->fetch_row()[0];

    if ($attempts >= 5) {
        header("Location: login.php?error=locked");
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            session_regenerate_id(true); // prevent session fixation

            $_SESSION['user_id']       = $user['user_id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['last_activity'] = time();
            $_SESSION['flash_welcome']     = true;
            $_SESSION['flash_stock_alert'] = true;

            // Clear failed attempts for this IP on success
            $del = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
            $del->bind_param("s", $ip);
            $del->execute();

            // Force password change if flagged
            if (!empty($user['must_change_password'])) {
                header("Location: profile.php");
                exit;
            }

            $role = $user['role'] ?? '';
            if ($role === 'barista') {
                header("Location: view_order.php");
            } elseif ($role === 'inventory_clerk') {
                header("Location: products.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        }
    }

    // ── Failed attempt — log it ──
    $log = $conn->prepare("INSERT INTO login_attempts (ip) VALUES (?)");
    $log->bind_param("s", $ip);
    $log->execute();

    $remaining = max(0, 4 - $attempts);
    header("Location: login.php?error=1&left=" . $remaining);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --accent:       #d1904b;
    --accent-light: #e8b87a;
    --accent-dark:  #a0702a;
    --bg:           #0b0b0b;
    --border:       rgba(255,255,255,0.07);
    --text:         #f5f5f5;
    --text-muted:   #777;
    --danger:       #e74c3c;
    --success:      #55e087;
    --shadow-accent: 0 8px 40px rgba(209,144,75,0.4);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; height: 100%; }

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    min-height: 100dvh;
    overflow: hidden;
    position: relative;
}

/* ── BACKGROUND ── */
.bg-image {
    position: fixed; inset: 0;
    background: url('https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?q=80&w=2070&auto=format&fit=crop') center/cover no-repeat;
    z-index: 0;
}
.bg-overlay {
    position: fixed; inset: 0;
    background: linear-gradient(135deg, rgba(0,0,0,0.94) 0%, rgba(0,0,0,0.78) 50%, rgba(0,0,0,0.94) 100%);
    z-index: 1;
}

/* ── KEYFRAMES ── */
@keyframes slideUp    { from { opacity:0; transform:translateY(32px); }  to { opacity:1; transform:translateY(0); } }
@keyframes slideLeft  { from { opacity:0; transform:translateX(-32px); } to { opacity:1; transform:translateX(0); } }
@keyframes slideRight { from { opacity:0; transform:translateX(32px); }  to { opacity:1; transform:translateX(0); } }
@keyframes fadeIn     { from { opacity:0; } to { opacity:1; } }
@keyframes float      { 0%,100%{transform:translateY(0) rotate(0deg);} 50%{transform:translateY(-10px) rotate(6deg);} }
@keyframes floatAlt   { 0%,100%{transform:translateY(0) rotate(0deg);} 50%{transform:translateY(9px)  rotate(-6deg);} }
@keyframes pulse      { 0%,100%{box-shadow:0 0 0 0 rgba(209,144,75,0.45);} 50%{box-shadow:0 0 0 14px rgba(209,144,75,0);} }
@keyframes shimmer    { from{background-position:-200% center;} to{background-position:200% center;} }
@keyframes shake      { 0%,100%{transform:translateX(0);} 20%,60%{transform:translateX(-8px);} 40%,80%{transform:translateX(8px);} }
@keyframes spin       { to { transform:rotate(360deg); } }
@keyframes blobDrift  { 0%,100%{transform:scale(1) translate(0,0);} 40%{transform:scale(1.06) translate(12px,-10px);} 70%{transform:scale(0.95) translate(-8px,8px);} }
@keyframes rippleAnim { to { transform:scale(4); opacity:0; } }

/* ── WRAPPER ── */
.login-wrapper {
    position: relative; z-index: 2;
    display: grid;
    grid-template-columns: 1fr 1.15fr;
    width: min(900px, calc(100vw - 32px));
    min-height: 580px;
    border-radius: 24px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.07);
    box-shadow: 0 40px 100px rgba(0,0,0,0.75), 0 0 0 1px rgba(209,144,75,0.06);
    animation: slideUp 0.55s ease both;
}

/* ═══════════════════════════════════════
   LEFT  —  BRAND PANEL
═══════════════════════════════════════ */
.brand-panel {
    background: linear-gradient(160deg, #160c00 0%, #0e0700 55%, #050505 100%);
    padding: 48px 36px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
    animation: slideLeft 0.6s 0.08s ease both;
}

/* Ambient glows */
.brand-panel::before {
    content:'';
    position:absolute; top:-70px; left:-70px;
    width:300px; height:300px;
    background:radial-gradient(circle, rgba(209,144,75,0.2) 0%, transparent 68%);
    border-radius:50%;
    animation: blobDrift 9s ease-in-out infinite;
    pointer-events:none;
}
.brand-panel::after {
    content:'';
    position:absolute; bottom:-50px; right:-50px;
    width:240px; height:240px;
    background:radial-gradient(circle, rgba(209,144,75,0.12) 0%, transparent 68%);
    border-radius:50%;
    animation: blobDrift 11s 2s ease-in-out infinite reverse;
    pointer-events:none;
}

/* Floating deco dots */
.deco-dot {
    position:absolute; border-radius:50%;
    background:var(--accent); pointer-events:none;
}
.deco-dot:nth-child(1){ width:11px;height:11px;opacity:.18; top:20%; left:14%; animation:float    4.2s ease-in-out infinite; }
.deco-dot:nth-child(2){ width:7px; height:7px; opacity:.14; top:52%; left:26%; animation:floatAlt 5.1s 1s   ease-in-out infinite; }
.deco-dot:nth-child(3){ width:5px; height:5px; opacity:.12; top:77%; left:10%; animation:float    6.3s .5s  ease-in-out infinite; }
.deco-dot:nth-child(4){ width:9px; height:9px; opacity:.16; top:33%; left:76%; animation:floatAlt 4.8s 1.5s ease-in-out infinite; }
.deco-dot:nth-child(5){ width:13px;height:13px;opacity:.1;  top:84%; left:70%; animation:float    5.6s .9s  ease-in-out infinite; }

.brand-top { position:relative; z-index:1; }

/* Logo row */
.brand-logo {
    display:flex; align-items:center; gap:13px;
    margin-bottom:34px;
}
.brand-logo-icon {
    width:50px; height:50px;
    border-radius:15px;
    background:linear-gradient(135deg, var(--accent), var(--accent-dark));
    display:flex; align-items:center; justify-content:center;
    font-size:22px; color:#fff;
    box-shadow:0 4px 24px rgba(209,144,75,0.45);
    animation: pulse 3.2s ease-in-out infinite;
    flex-shrink:0;
}
.brand-logo-text { display:flex; flex-direction:column; }
.brand-name { font-size:15px; font-weight:700; color:var(--text); line-height:1.2; }
.brand-sub  { font-size:11px; color:var(--accent); font-weight:500; letter-spacing:.4px; margin-top:1px; }

/* Heading */
.brand-heading {
    font-size:25px; font-weight:800;
    color:var(--text); line-height:1.35;
    margin-bottom:10px;
}
.brand-heading span { color:var(--accent); }

.brand-tagline {
    font-size:12.5px; color:var(--text-muted);
    line-height:1.65; margin-bottom:30px;
}

/* Feature cards */
.brand-features { display:flex; flex-direction:column; gap:10px; }
.brand-feature {
    display:flex; align-items:center; gap:12px;
    padding:11px 14px;
    border-radius:12px;
    border:1px solid rgba(209,144,75,0.1);
    background:rgba(209,144,75,0.04);
    transition:all 0.3s ease;
}
.brand-feature:hover {
    border-color:rgba(209,144,75,0.28);
    background:rgba(209,144,75,0.09);
    transform:translateX(5px);
}
.brand-feature.fading { opacity:0; }
.brand-feature-icon {
    width:34px; height:34px;
    border-radius:9px;
    background:linear-gradient(135deg, rgba(209,144,75,0.22), rgba(209,144,75,0.07));
    border:1px solid rgba(209,144,75,0.2);
    display:flex; align-items:center; justify-content:center;
    color:var(--accent); font-size:14px; flex-shrink:0;
}
.brand-feature-text { display:flex; flex-direction:column; }
.brand-feature-title { font-size:13px; font-weight:600; color:var(--text); }
.brand-feature-desc  { font-size:11px; color:var(--text-muted); margin-top:1px; }

/* Bottom */
.brand-bottom { position:relative; z-index:1; }
.brand-divider {
    height:1px;
    background:linear-gradient(90deg, transparent, rgba(209,144,75,0.22), transparent);
    margin:22px 0 14px;
}
.brand-version { font-size:11px; color:rgba(255,255,255,0.18); text-align:center; }

/* ═══════════════════════════════════════
   RIGHT  —  FORM PANEL
═══════════════════════════════════════ */
.form-panel {
    background:#0e0e0e;
    padding:52px 46px;
    display:flex; flex-direction:column; justify-content:center;
    position:relative;
    animation: slideRight 0.6s 0.12s ease both;
}

/* Top accent line */
.form-panel::before {
    content:'';
    position:absolute; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg, var(--accent), var(--accent-light), var(--accent));
    background-size:200% 100%;
    animation: shimmer 2.5s ease-in-out infinite;
}

.time-greeting {
    font-size:11.5px; font-weight:600;
    color:var(--accent); letter-spacing:.5px;
    text-transform:uppercase; margin-bottom:6px;
    animation: fadeIn 0.5s 0.3s ease both; opacity:0;
}

.form-heading {
    font-size:28px; font-weight:800; color:var(--text);
    margin-bottom:4px;
    animation: slideUp 0.45s 0.34s ease both; opacity:0;
}
.form-subtitle {
    font-size:13px; color:var(--text-muted);
    margin-bottom:28px;
    animation: slideUp 0.45s 0.40s ease both; opacity:0;
}

/* ── ERROR ── */
.error-banner {
    display:flex; align-items:center; gap:10px;
    padding:12px 16px; border-radius:10px;
    background:rgba(231,76,60,0.08);
    border:1px solid rgba(231,76,60,0.25);
    color:#e87070; font-size:13px; font-weight:500;
    margin-bottom:20px;
    animation: shake 0.5s ease;
}
.error-banner i { color:var(--danger); flex-shrink:0; }

/* ── FLOATING LABEL INPUTS ── */
.field-group {
    position:relative;
    margin-bottom:16px;
    animation: slideUp 0.4s ease both; opacity:0;
}
.field-group:nth-of-type(1) { animation-delay:.46s; }
.field-group:nth-of-type(2) { animation-delay:.53s; }

.field-icon {
    position:absolute; left:16px; top:50%;
    transform:translateY(-50%);
    color:var(--text-muted); font-size:15px;
    pointer-events:none; transition:color .25s ease; z-index:1;
}

.field-group input {
    width:100%; height:58px;
    padding:22px 48px 8px 46px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,0.08);
    background:rgba(255,255,255,0.03);
    color:var(--text);
    font-size:14px; font-family:'Poppins',sans-serif;
    outline:none;
    transition:border-color .25s ease, box-shadow .25s ease, background .25s ease;
}
.field-group input::placeholder { opacity:0; }

.field-group label {
    position:absolute; left:46px; top:50%;
    transform:translateY(-50%);
    font-size:14px; color:var(--text-muted);
    pointer-events:none;
    transition:all .2s ease; z-index:1;
}

/* Label floats up when focused or filled */
.field-group input:focus ~ label,
.field-group input:not(:placeholder-shown) ~ label {
    top:12px; transform:translateY(0);
    font-size:10px; font-weight:600;
    letter-spacing:.5px; text-transform:uppercase;
    color:var(--accent);
}

.field-group input:focus {
    border-color:rgba(209,144,75,0.5);
    background:rgba(209,144,75,0.04);
    box-shadow:0 0 0 3px rgba(209,144,75,0.09);
}
.field-group input:focus ~ .field-icon { color:var(--accent); }

/* Green checkmark when filled */
.field-valid {
    position:absolute; right:46px; top:50%;
    transform:translateY(-50%);
    font-size:12px; color:var(--success);
    opacity:0; transition:opacity .2s ease;
    pointer-events:none;
}
.field-group.filled .field-valid { opacity:1; }

/* Password toggle */
.toggle-pass {
    position:absolute; right:14px; top:50%;
    transform:translateY(-50%);
    background:none; border:none;
    color:var(--text-muted); font-size:14px;
    cursor:pointer; padding:4px; line-height:1;
    transition:color .2s ease; z-index:2;
}
.toggle-pass:hover { color:var(--text); }

/* ── REMEMBER ME ── */
.form-extras {
    display:flex; align-items:center;
    margin-bottom:22px;
    animation: slideUp 0.4s 0.59s ease both; opacity:0;
}
.toggle-switch {
    display:flex; align-items:center; gap:9px;
    cursor:pointer; user-select:none;
}
.toggle-switch input { display:none; }
.toggle-track {
    width:34px; height:18px;
    background:rgba(255,255,255,0.09);
    border-radius:20px; position:relative;
    transition:background .2s ease;
    border:1px solid rgba(255,255,255,0.1);
    flex-shrink:0;
}
.toggle-track::after {
    content:''; position:absolute;
    width:12px; height:12px;
    background:var(--text-muted); border-radius:50%;
    top:2px; left:2px;
    transition:transform .2s ease, background .2s ease;
}
.toggle-switch input:checked + .toggle-track {
    background:rgba(209,144,75,0.22);
    border-color:rgba(209,144,75,0.3);
}
.toggle-switch input:checked + .toggle-track::after {
    transform:translateX(16px);
    background:var(--accent);
}
.toggle-label { font-size:12.5px; color:var(--text-muted); }

/* ── SUBMIT BUTTON ── */
.submit-btn {
    width:100%; height:54px;
    border:none; border-radius:12px;
    background:linear-gradient(135deg, var(--accent), var(--accent-dark));
    color:#fff;
    font-family:'Poppins',sans-serif;
    font-size:15px; font-weight:700;
    cursor:pointer; position:relative; overflow:hidden;
    transition:transform .2s ease, box-shadow .2s ease;
    box-shadow:0 4px 20px rgba(209,144,75,0.3);
    display:flex; align-items:center; justify-content:center; gap:10px;
    animation: slideUp 0.4s 0.63s ease both; opacity:0;
}
.submit-btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-accent); }
.submit-btn:active { transform:translateY(0); }
.submit-btn .ripple {
    position:absolute; border-radius:50%;
    background:rgba(255,255,255,0.22);
    transform:scale(0);
    animation: rippleAnim 0.55s linear;
    pointer-events:none;
}
.submit-btn .spinner {
    display:none;
    width:20px; height:20px;
    border:2px solid rgba(255,255,255,0.3);
    border-top-color:#fff;
    border-radius:50%;
    animation: spin 0.8s linear infinite;
}
.submit-btn.loading .btn-text { display:none; }
.submit-btn.loading .spinner   { display:block; }

/* ── FOOTER ── */
.form-footer {
    display:flex; align-items:center; justify-content:space-between;
    margin-top:20px;
    animation: fadeIn 0.4s 0.72s ease both; opacity:0;
}
.back-link {
    display:inline-flex; align-items:center; gap:6px;
    color:var(--text-muted); text-decoration:none;
    font-size:12.5px; transition:all .25s ease;
}
.back-link:hover { color:var(--accent); transform:translateX(-3px); }

.secure-badge {
    display:flex; align-items:center; gap:5px;
    font-size:11px; color:rgba(255,255,255,0.2);
}
.secure-badge i { color:var(--success); font-size:10px; }

/* ── RESPONSIVE ── */
@media (max-width: 700px) {
    .login-wrapper { grid-template-columns:1fr; }
    .brand-panel   { display:none; }
    .form-panel    { padding:40px 28px; border-radius:24px; }
    .form-panel::before { border-radius:24px 24px 0 0; }
}
@media (max-width: 400px) {
    .form-panel    { padding:32px 20px; }
    .form-heading  { font-size:22px; }
}
</style>
</head>
<body>

<div class="bg-image"></div>
<div class="bg-overlay"></div>

<div class="login-wrapper">

    <!-- ═══ LEFT: BRAND PANEL ═══ -->
    <div class="brand-panel">
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>

        <div class="brand-top">
            <div class="brand-logo">
                <div class="brand-logo-icon">
                    <i class="fa-solid fa-mug-hot"></i>
                </div>
                <div class="brand-logo-text">
                    <span class="brand-name">Bird's Nest Coffee</span>
                    <span class="brand-sub">Staff Portal</span>
                </div>
            </div>

            <h2 class="brand-heading">Manage your<br><span>café with ease.</span></h2>
            <p class="brand-tagline">One place for orders, sales, and everything that keeps your café running smoothly.</p>

            <div class="brand-features" id="brandFeatures">
                <div class="brand-feature" id="featCard0">
                    <div class="brand-feature-icon"><i class="fa-solid fa-clipboard-list" id="featIcon0"></i></div>
                    <div class="brand-feature-text">
                        <span class="brand-feature-title" id="featTitle0">Order Management</span>
                        <span class="brand-feature-desc" id="featDesc0">Track and process orders in real time</span>
                    </div>
                </div>
                <div class="brand-feature" id="featCard1">
                    <div class="brand-feature-icon"><i class="fa-solid fa-mug-hot" id="featIcon1"></i></div>
                    <div class="brand-feature-text">
                        <span class="brand-feature-title" id="featTitle1">Fast Counter Service</span>
                        <span class="brand-feature-desc" id="featDesc1">Built for fast-paced counter service</span>
                    </div>
                </div>
                <div class="brand-feature" id="featCard2">
                    <div class="brand-feature-icon"><i class="fa-solid fa-star" id="featIcon2"></i></div>
                    <div class="brand-feature-text">
                        <span class="brand-feature-title" id="featTitle2">Loyalty Rewards</span>
                        <span class="brand-feature-desc" id="featDesc2">Delight customers with points &amp; perks</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="brand-bottom">
            <div class="brand-divider"></div>
            <p class="brand-version">Bird's Nest Coffee &copy; <?= date('Y') ?> &nbsp;&middot;&nbsp; Staff Portal</p>
        </div>
    </div>

    <!-- ═══ RIGHT: FORM PANEL ═══ -->
    <div class="form-panel">
        <p class="time-greeting" id="timeGreeting">Welcome</p>
        <h1 class="form-heading">Welcome back 👋</h1>
        <p class="form-subtitle">Sign in to your staff account to continue</p>

        <?php if (!empty($error)): ?>
        <div class="error-banner">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" autocomplete="off">

            <!-- Username -->
            <div class="field-group" id="fg-username">
                <i class="fa-solid fa-user field-icon"></i>
                <input type="text" name="username" id="usernameInput" placeholder=" " required autofocus autocomplete="username">
                <label for="usernameInput">Username</label>
                <i class="fa-solid fa-check field-valid"></i>
            </div>

            <!-- Password -->
            <div class="field-group" id="fg-password">
                <i class="fa-solid fa-lock field-icon"></i>
                <input type="password" name="password" id="passwordInput" placeholder=" " required autocomplete="current-password">
                <label for="passwordInput">Password</label>
                <i class="fa-solid fa-check field-valid"></i>
                <button type="button" class="toggle-pass" id="togglePassBtn" title="Show / hide password">
                    <i class="fa-solid fa-eye" id="toggleIcon"></i>
                </button>
            </div>

            <!-- Remember me -->
            <div class="form-extras">
                <label class="toggle-switch">
                    <input type="checkbox" name="remember" id="rememberMe">
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Keep me signed in</span>
                </label>
            </div>

            <!-- Submit -->
            <button type="submit" class="submit-btn" id="loginBtn">
                <span class="btn-text">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
                </span>
                <span class="spinner"></span>
            </button>
        </form>

        <div class="form-footer">
            <a href="index.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to home
            </a>
            <a href="forgot_password.php" class="back-link" style="color:var(--accent);gap:6px">
                <i class="fa-solid fa-key"></i> Forgot Password?
            </a>
        </div>
        <div style="text-align:center;margin-top:14px">
            <div class="secure-badge" style="justify-content:center">
                <i class="fa-solid fa-shield-halved"></i>
                Secured &middot; Staff only
            </div>
        </div>
    </div>

</div>

<script>
// Time-based greeting
(function () {
    const h = new Date().getHours();
    const greet = h < 12 ? '☀️ Good morning' : h < 17 ? '🌤 Good afternoon' : '🌙 Good evening';
    document.getElementById('timeGreeting').textContent = greet;
})();

// Rotating highlights — cycles between sets that speak to different roles
// (the portal is shared by baristas, inventory clerks, supervisors, managers & admins)
(function () {
    const sets = [
        [
            {icon:'fa-clipboard-list', title:'Order Management',     desc:'Track and process orders in real time'},
            {icon:'fa-mug-hot',        title:'Fast Counter Service', desc:'Built for fast-paced counter service'},
            {icon:'fa-star',           title:'Loyalty Rewards',      desc:'Delight customers with points & perks'}
        ],
        [
            {icon:'fa-box-open',   title:'Inventory Tracking', desc:'Track ingredients and stock levels'},
            {icon:'fa-truck',      title:'Suppliers & Orders', desc:'Manage suppliers and purchase orders'},
            {icon:'fa-mug-saucer', title:'Recipe Management',  desc:'Keep every drink recipe consistent'}
        ],
        [
            {icon:'fa-chart-bar',     title:'Sales Dashboard', desc:'Monitor daily revenue and performance'},
            {icon:'fa-users',         title:'Team & Shifts',   desc:'Manage staff schedules and attendance'},
            {icon:'fa-shield-halved', title:'Full Control',    desc:'Configure roles and system settings'}
        ]
    ];
    const cards = [0, 1, 2].map(function (i) {
        return {
            card:  document.getElementById('featCard'  + i),
            icon:  document.getElementById('featIcon'  + i),
            title: document.getElementById('featTitle' + i),
            desc:  document.getElementById('featDesc'  + i)
        };
    });
    let setIndex = 0;
    setInterval(function () {
        setIndex = (setIndex + 1) % sets.length;
        cards.forEach(function (c) { c.card.classList.add('fading'); });
        setTimeout(function () {
            sets[setIndex].forEach(function (item, i) {
                cards[i].icon.className    = 'fa-solid ' + item.icon;
                cards[i].title.textContent = item.title;
                cards[i].desc.textContent  = item.desc;
                cards[i].card.classList.remove('fading');
            });
        }, 320);
    }, 5000);
})();

// Filled-state class for valid indicator
const usernameInput = document.getElementById('usernameInput');
const passwordInput = document.getElementById('passwordInput');

usernameInput.addEventListener('input', function () {
    document.getElementById('fg-username').classList.toggle('filled', this.value.trim().length > 0);
});
passwordInput.addEventListener('input', function () {
    document.getElementById('fg-password').classList.toggle('filled', this.value.length > 0);
});

// Password visibility toggle
document.getElementById('togglePassBtn').addEventListener('click', function () {
    const isPass = passwordInput.type === 'password';
    passwordInput.type = isPass ? 'text' : 'password';
    document.getElementById('toggleIcon').className = isPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
});

// Ripple on submit button
document.getElementById('loginBtn').addEventListener('click', function (e) {
    const btn  = this;
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const rpl  = document.createElement('span');
    rpl.className = 'ripple';
    rpl.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - rect.left - size/2}px;top:${e.clientY - rect.top - size/2}px`;
    btn.appendChild(rpl);
    rpl.addEventListener('animationend', () => rpl.remove());
});

// Loading state
document.getElementById('loginForm').addEventListener('submit', function () {
    document.getElementById('loginBtn').classList.add('loading');
});
</script>
</body>
</html>
