<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'config.php';

$step     = (int)($_SESSION['fp_step']    ?? 1);
$attempts = (int)($_SESSION['fp_attempts'] ?? 0);
$locked   = (int)($_SESSION['fp_locked_until'] ?? 0);
$error    = '';
$success  = '';

// Lockout check
$is_locked = ($locked > time());
if ($is_locked) {
    $wait_min = ceil(($locked - time()) / 60);
    $error = "Too many incorrect answers. Please try again in {$wait_min} minute(s).";
    $step  = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    $action = $_POST['action'] ?? '';

    // ── STEP 1: look up username ──────────────────────────────
    if ($action === 'find_user') {
        $username = trim($_POST['username'] ?? '');
        $stmt = $conn->prepare(
            "SELECT user_id, security_question FROM users WHERE username = ? AND role != 'customer' LIMIT 1"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            $error = "No staff account found with that username.";
            $step  = 1;
        } elseif (empty($row['security_question'])) {
            $error = "This account does not have a security question set up. Please contact your administrator.";
            $step  = 1;
        } else {
            $_SESSION['fp_user_id']  = $row['user_id'];
            $_SESSION['fp_question'] = $row['security_question'];
            $_SESSION['fp_step']     = 2;
            $_SESSION['fp_attempts'] = 0;
            $step = 2;
        }
    }

    // ── STEP 2: verify security answer ───────────────────────
    elseif ($action === 'verify_answer' && $step === 2) {
        $answer     = strtolower(trim($_POST['security_answer'] ?? ''));
        $fp_user_id = (int)($_SESSION['fp_user_id'] ?? 0);

        $stmt = $conn->prepare("SELECT security_answer FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $fp_user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row && password_verify($answer, $row['security_answer'])) {
            $_SESSION['fp_step']     = 3;
            $_SESSION['fp_attempts'] = 0;
            $step = 3;
        } else {
            $attempts++;
            $_SESSION['fp_attempts'] = $attempts;
            $remaining = max(0, 5 - $attempts);
            if ($attempts >= 5) {
                $_SESSION['fp_locked_until'] = time() + 900;
                unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_question']);
                $error = "Too many incorrect answers. Please wait 15 minutes before trying again.";
                $step  = 0;
            } else {
                $error = "Incorrect answer. {$remaining} attempt(s) remaining.";
                $step  = 2;
            }
        }
    }

    // ── STEP 3: set new password ──────────────────────────────
    elseif ($action === 'reset_password' && $step === 3) {
        $new_pass = $_POST['new_password']      ?? '';
        $confirm  = $_POST['confirm_password']  ?? '';
        $fp_user_id = (int)($_SESSION['fp_user_id'] ?? 0);

        $pass_errors = [];
        if (strlen($new_pass) < 8)                               $pass_errors[] = "at least 8 characters";
        if (!preg_match('/[A-Z]/', $new_pass))                   $pass_errors[] = "one uppercase letter";
        if (!preg_match('/[0-9]/', $new_pass))                   $pass_errors[] = "one number";
        if (!preg_match('/[^a-zA-Z0-9]/', $new_pass))            $pass_errors[] = "one special character";
        if ($new_pass !== $confirm)                              $pass_errors[] = "passwords must match";

        if ($pass_errors) {
            $error = "Password requires: " . implode(', ', $pass_errors) . ".";
            $step  = 3;
        } elseif (!$fp_user_id) {
            $error = "Session expired. Please start again.";
            $step  = 1;
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "UPDATE users SET password = ?, must_change_password = 0, reset_token = NULL, reset_token_expires = NULL WHERE user_id = ?"
            );
            $stmt->bind_param("si", $hashed, $fp_user_id);
            $stmt->execute();

            unset(
                $_SESSION['fp_step'], $_SESSION['fp_user_id'],
                $_SESSION['fp_question'], $_SESSION['fp_attempts'],
                $_SESSION['fp_locked_until']
            );
            $success = "Your password has been reset successfully. You may now sign in.";
            $step = 4;
        }
    }
}

$fp_question = htmlspecialchars($_SESSION['fp_question'] ?? '');

// Step labels for progress indicator
$step_labels = ['Find Account', 'Verify Identity', 'New Password'];
$current_step_index = max(0, min(3, $step)) - 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | Bird's Nest Coffee</title>
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
    --warning:      #f39c12;
    --shadow-accent: 0 8px 40px rgba(209,144,75,0.4);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; min-height: 100%; }

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    overflow-x: hidden;
    position: relative;
}

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

@keyframes slideUp    { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideLeft  { from{opacity:0;transform:translateX(-28px)} to{opacity:1;transform:translateX(0)} }
@keyframes slideRight { from{opacity:0;transform:translateX(28px)} to{opacity:1;transform:translateX(0)} }
@keyframes fadeIn     { from{opacity:0} to{opacity:1} }
@keyframes float      { 0%,100%{transform:translateY(0) rotate(0deg)} 50%{transform:translateY(-10px) rotate(6deg)} }
@keyframes floatAlt   { 0%,100%{transform:translateY(0) rotate(0deg)} 50%{transform:translateY(9px) rotate(-6deg)} }
@keyframes pulse      { 0%,100%{box-shadow:0 0 0 0 rgba(209,144,75,0.45)} 50%{box-shadow:0 0 0 14px rgba(209,144,75,0)} }
@keyframes shimmer    { from{background-position:-200% center} to{background-position:200% center} }
@keyframes shake      { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-8px)} 40%,80%{transform:translateX(8px)} }
@keyframes spin       { to{transform:rotate(360deg)} }
@keyframes blobDrift  { 0%,100%{transform:scale(1) translate(0,0)} 40%{transform:scale(1.06) translate(12px,-10px)} 70%{transform:scale(0.95) translate(-8px,8px)} }
@keyframes stepFadeIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

/* ── WRAPPER ── */
.login-wrapper {
    position: relative; z-index: 2;
    display: grid;
    grid-template-columns: 1fr 1.15fr;
    width: min(920px, calc(100vw - 32px));
    min-height: 580px;
    border-radius: 24px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.07);
    box-shadow: 0 40px 100px rgba(0,0,0,0.75), 0 0 0 1px rgba(209,144,75,0.06);
    animation: slideUp 0.55s ease both;
    margin: 24px auto;
}

/* ── BRAND PANEL ── */
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
.brand-panel::before {
    content:''; position:absolute; top:-70px; left:-70px;
    width:300px; height:300px;
    background:radial-gradient(circle, rgba(209,144,75,0.2) 0%, transparent 68%);
    border-radius:50%;
    animation: blobDrift 9s ease-in-out infinite;
}
.brand-panel::after {
    content:''; position:absolute; bottom:-50px; right:-50px;
    width:240px; height:240px;
    background:radial-gradient(circle, rgba(209,144,75,0.12) 0%, transparent 68%);
    border-radius:50%;
    animation: blobDrift 11s 2s ease-in-out infinite reverse;
}
.deco-dot { position:absolute; border-radius:50%; background:var(--accent); pointer-events:none; }
.deco-dot:nth-child(1){ width:11px;height:11px;opacity:.18;top:20%;left:14%;animation:float 4.2s ease-in-out infinite; }
.deco-dot:nth-child(2){ width:7px;height:7px;opacity:.14;top:52%;left:26%;animation:floatAlt 5.1s 1s ease-in-out infinite; }
.deco-dot:nth-child(3){ width:5px;height:5px;opacity:.12;top:77%;left:10%;animation:float 6.3s .5s ease-in-out infinite; }
.deco-dot:nth-child(4){ width:9px;height:9px;opacity:.16;top:33%;left:76%;animation:floatAlt 4.8s 1.5s ease-in-out infinite; }
.deco-dot:nth-child(5){ width:13px;height:13px;opacity:.1;top:84%;left:70%;animation:float 5.6s .9s ease-in-out infinite; }
.brand-top { position:relative; z-index:1; }
.brand-logo { display:flex; align-items:center; gap:13px; margin-bottom:34px; }
.brand-logo-icon {
    width:50px; height:50px; border-radius:15px;
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    display:flex; align-items:center; justify-content:center;
    font-size:22px; color:#fff;
    box-shadow:0 4px 24px rgba(209,144,75,0.45);
    animation: pulse 3.2s ease-in-out infinite; flex-shrink:0;
}
.brand-logo-text { display:flex; flex-direction:column; }
.brand-name { font-size:15px; font-weight:700; color:var(--text); line-height:1.2; }
.brand-sub  { font-size:11px; color:var(--accent); font-weight:500; letter-spacing:.4px; margin-top:1px; }
.brand-heading { font-size:24px; font-weight:800; color:var(--text); line-height:1.35; margin-bottom:10px; }
.brand-heading span { color:var(--accent); }
.brand-tagline { font-size:12.5px; color:var(--text-muted); line-height:1.65; margin-bottom:28px; }

/* Security steps guide */
.security-steps { display:flex; flex-direction:column; gap:12px; }
.sec-step {
    display:flex; align-items:flex-start; gap:13px;
    padding:12px 14px; border-radius:12px;
    border:1px solid rgba(209,144,75,0.1);
    background:rgba(209,144,75,0.04);
    transition:all 0.3s ease;
}
.sec-step.active {
    border-color:rgba(209,144,75,0.4);
    background:rgba(209,144,75,0.1);
}
.sec-step-num {
    width:26px; height:26px; border-radius:50%;
    background:rgba(209,144,75,0.12);
    border:1px solid rgba(209,144,75,0.25);
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:700; color:var(--accent);
    flex-shrink:0; margin-top:1px;
}
.sec-step.active .sec-step-num {
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    border-color:transparent; color:#fff;
    box-shadow:0 2px 10px rgba(209,144,75,0.4);
}
.sec-step-text { display:flex; flex-direction:column; }
.sec-step-title { font-size:13px; font-weight:600; color:var(--text); }
.sec-step-desc  { font-size:11px; color:var(--text-muted); margin-top:2px; }

.brand-bottom { position:relative; z-index:1; }
.brand-divider { height:1px; background:linear-gradient(90deg,transparent,rgba(209,144,75,0.22),transparent); margin:22px 0 14px; }
.brand-version { font-size:11px; color:rgba(255,255,255,0.18); text-align:center; }

/* ── FORM PANEL ── */
.form-panel {
    background:#0e0e0e;
    padding:48px 46px;
    display:flex; flex-direction:column; justify-content:center;
    position:relative;
    animation: slideRight 0.6s 0.12s ease both;
}
.form-panel::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg,var(--accent),var(--accent-light),var(--accent));
    background-size:200% 100%;
    animation: shimmer 2.5s ease-in-out infinite;
}

/* Progress bar */
.progress-bar {
    display:flex; align-items:center; gap:0;
    margin-bottom:30px;
    animation: fadeIn 0.4s 0.2s ease both; opacity:0;
}
.progress-step {
    display:flex; flex-direction:column; align-items:center; gap:4px;
    flex:1; position:relative;
}
.progress-step:not(:last-child)::after {
    content:''; position:absolute;
    top:14px; left:calc(50% + 14px);
    width:calc(100% - 28px); height:2px;
    background:rgba(255,255,255,0.08);
    border-radius:2px;
}
.progress-step.done:not(:last-child)::after,
.progress-step.active:not(:last-child)::after {
    background:linear-gradient(90deg,var(--accent),rgba(209,144,75,0.3));
}
.ps-circle {
    width:28px; height:28px; border-radius:50%;
    border:2px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.04);
    display:flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:700; color:var(--text-muted);
    transition:all 0.3s ease; position:relative; z-index:1;
}
.progress-step.active .ps-circle {
    border-color:var(--accent);
    background:rgba(209,144,75,0.15);
    color:var(--accent);
    box-shadow:0 0 0 4px rgba(209,144,75,0.1);
}
.progress-step.done .ps-circle {
    border-color:var(--success);
    background:rgba(85,224,135,0.15);
    color:var(--success);
}
.ps-label { font-size:10px; color:var(--text-muted); font-weight:500; white-space:nowrap; }
.progress-step.active .ps-label { color:var(--accent); font-weight:600; }
.progress-step.done  .ps-label  { color:var(--success); }

.form-heading {
    font-size:26px; font-weight:800; color:var(--text);
    margin-bottom:4px;
    animation: slideUp 0.4s 0.3s ease both; opacity:0;
}
.form-subtitle {
    font-size:13px; color:var(--text-muted);
    margin-bottom:22px; line-height:1.5;
    animation: slideUp 0.4s 0.36s ease both; opacity:0;
}

.alert {
    display:flex; align-items:flex-start; gap:10px;
    padding:12px 15px; border-radius:10px;
    font-size:13px; font-weight:500; margin-bottom:18px;
}
.alert-danger  { background:rgba(231,76,60,0.08);  border:1px solid rgba(231,76,60,0.25); color:#e87070; animation:shake .5s ease; }
.alert-success { background:rgba(85,224,135,0.08); border:1px solid rgba(85,224,135,0.2);  color:var(--success); }
.alert-info    { background:rgba(209,144,75,0.08); border:1px solid rgba(209,144,75,0.2);  color:var(--accent-light); }
.alert i { flex-shrink:0; margin-top:1px; }

/* Step content */
.step-body { animation: stepFadeIn 0.35s ease both; }

/* Floating label fields */
.field-group { position:relative; margin-bottom:16px; }
.field-icon {
    position:absolute; left:16px; top:50%; transform:translateY(-50%);
    color:var(--text-muted); font-size:15px; pointer-events:none; z-index:1;
    transition:color .25s ease;
}
.field-group input, .field-group select {
    width:100%; height:58px;
    padding:22px 48px 8px 46px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,0.08);
    background:rgba(255,255,255,0.03);
    color:var(--text);
    font-size:14px; font-family:'Poppins',sans-serif;
    outline:none;
    transition:border-color .25s, box-shadow .25s, background .25s;
}
.field-group select { padding:22px 14px 8px 46px; cursor:pointer; }
.field-group select option { background:#1a1a1a; }
.field-group input::placeholder { opacity:0; }
.field-group label {
    position:absolute; left:46px; top:50%; transform:translateY(-50%);
    font-size:14px; color:var(--text-muted); pointer-events:none;
    transition:all .2s ease; z-index:1;
}
.field-group input:focus ~ label,
.field-group input:not(:placeholder-shown) ~ label,
.field-group select:focus ~ label,
.field-group.has-value label {
    top:12px; transform:translateY(0);
    font-size:10px; font-weight:600; letter-spacing:.5px; text-transform:uppercase;
    color:var(--accent);
}
.field-group input:focus,
.field-group select:focus {
    border-color:rgba(209,144,75,0.5);
    background:rgba(209,144,75,0.04);
    box-shadow:0 0 0 3px rgba(209,144,75,0.09);
}
.field-group input:focus ~ .field-icon,
.field-group select:focus ~ .field-icon { color:var(--accent); }
.toggle-pass {
    position:absolute; right:14px; top:50%; transform:translateY(-50%);
    background:none; border:none; color:var(--text-muted);
    font-size:14px; cursor:pointer; padding:4px; z-index:2;
    transition:color .2s;
}
.toggle-pass:hover { color:var(--text); }

/* Question display box */
.question-box {
    background:rgba(209,144,75,0.06);
    border:1px solid rgba(209,144,75,0.2);
    border-radius:12px; padding:14px 16px;
    margin-bottom:16px;
    display:flex; align-items:flex-start; gap:12px;
}
.question-box i { color:var(--accent); margin-top:2px; flex-shrink:0; }
.question-text { font-size:14px; font-weight:500; color:var(--text); line-height:1.4; }

/* Password strength meter */
.strength-meter { margin:-8px 0 16px; }
.strength-bars {
    display:flex; gap:4px; margin-bottom:6px;
}
.s-bar {
    flex:1; height:4px; border-radius:4px;
    background:rgba(255,255,255,0.08);
    transition:background 0.3s ease;
}
.strength-label {
    font-size:11px; font-weight:600; color:var(--text-muted);
    transition:color 0.3s ease;
}
.strength-requirements {
    display:flex; flex-wrap:wrap; gap:6px;
    margin-top:8px;
}
.req {
    display:flex; align-items:center; gap:5px;
    font-size:10.5px; color:var(--text-muted);
    padding:4px 8px; border-radius:20px;
    border:1px solid rgba(255,255,255,0.07);
    background:rgba(255,255,255,0.03);
    transition:all 0.25s ease;
}
.req.met {
    color:var(--success);
    border-color:rgba(85,224,135,0.25);
    background:rgba(85,224,135,0.07);
}
.req i { font-size:9px; }

/* Submit button */
.submit-btn {
    width:100%; height:52px; border:none; border-radius:12px;
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    color:#fff; font-family:'Poppins',sans-serif;
    font-size:14px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:9px;
    transition:transform .2s, box-shadow .2s;
    box-shadow:0 4px 20px rgba(209,144,75,0.3);
    margin-top:6px;
}
.submit-btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-accent); }
.submit-btn:active { transform:translateY(0); }
.submit-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }

/* Back + footer */
.form-footer {
    display:flex; align-items:center; justify-content:space-between;
    margin-top:18px;
}
.back-link {
    display:inline-flex; align-items:center; gap:6px;
    color:var(--text-muted); text-decoration:none;
    font-size:12.5px; transition:all .25s;
}
.back-link:hover { color:var(--accent); transform:translateX(-3px); }
.secure-badge {
    display:flex; align-items:center; gap:5px;
    font-size:11px; color:rgba(255,255,255,0.2);
}
.secure-badge i { color:var(--success); font-size:10px; }

/* Success state */
.success-card {
    text-align:center; padding:20px 0;
    animation: stepFadeIn 0.5s ease both;
}
.success-icon {
    width:80px; height:80px; border-radius:50%;
    background:linear-gradient(135deg,rgba(85,224,135,0.2),rgba(85,224,135,0.05));
    border:2px solid rgba(85,224,135,0.3);
    display:flex; align-items:center; justify-content:center;
    font-size:36px; color:var(--success);
    margin:0 auto 20px;
    animation: pulse 2s ease-in-out infinite;
}
.success-title { font-size:22px; font-weight:800; color:var(--text); margin-bottom:8px; }
.success-msg   { font-size:13.5px; color:var(--text-muted); line-height:1.6; margin-bottom:24px; }

/* Lockout state */
.lock-icon {
    width:70px; height:70px; border-radius:50%;
    background:rgba(231,76,60,0.1); border:2px solid rgba(231,76,60,0.25);
    display:flex; align-items:center; justify-content:center;
    font-size:30px; color:var(--danger); margin:0 auto 16px;
}

@media (max-width:700px) {
    .login-wrapper { grid-template-columns:1fr; }
    .brand-panel   { display:none; }
    .form-panel    { padding:36px 24px; border-radius:24px; }
    .form-panel::before { border-radius:24px 24px 0 0; }
}
</style>
</head>
<body>
<div class="bg-image"></div>
<div class="bg-overlay"></div>

<div class="login-wrapper">
    <!-- ══ LEFT: BRAND PANEL ══ -->
    <div class="brand-panel">
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>
        <div class="deco-dot"></div>

        <div class="brand-top">
            <div class="brand-logo">
                <div class="brand-logo-icon"><i class="fa-solid fa-mug-hot"></i></div>
                <div class="brand-logo-text">
                    <span class="brand-name">Bird's Nest Coffee</span>
                    <span class="brand-sub">Staff Portal</span>
                </div>
            </div>

            <h2 class="brand-heading">Account<br><span>Recovery</span></h2>
            <p class="brand-tagline">Verify your identity in three simple steps to regain access to your account.</p>

            <div class="security-steps">
                <div class="sec-step <?= $step === 1 ? 'active' : '' ?>">
                    <div class="sec-step-num"><?= ($step > 1 || $step === 4) ? '<i class="fa-solid fa-check" style="font-size:10px"></i>' : '1' ?></div>
                    <div class="sec-step-text">
                        <span class="sec-step-title">Find Your Account</span>
                        <span class="sec-step-desc">Enter your staff username</span>
                    </div>
                </div>
                <div class="sec-step <?= $step === 2 ? 'active' : '' ?>">
                    <div class="sec-step-num"><?= ($step > 2 || $step === 4) ? '<i class="fa-solid fa-check" style="font-size:10px"></i>' : '2' ?></div>
                    <div class="sec-step-text">
                        <span class="sec-step-title">Verify Your Identity</span>
                        <span class="sec-step-desc">Answer your security question</span>
                    </div>
                </div>
                <div class="sec-step <?= $step === 3 ? 'active' : '' ?>">
                    <div class="sec-step-num"><?= ($step === 4) ? '<i class="fa-solid fa-check" style="font-size:10px"></i>' : '3' ?></div>
                    <div class="sec-step-text">
                        <span class="sec-step-title">Set New Password</span>
                        <span class="sec-step-desc">Choose a strong password</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="brand-bottom">
            <div class="brand-divider"></div>
            <p class="brand-version">Bird's Nest Coffee &copy; <?= date('Y') ?> &nbsp;&middot;&nbsp; Secure Recovery</p>
        </div>
    </div>

    <!-- ══ RIGHT: FORM PANEL ══ -->
    <div class="form-panel">

        <?php if ($step >= 1 && $step <= 3): ?>
        <!-- Progress bar -->
        <div class="progress-bar">
            <?php $step_info = [1=>'Find Account', 2=>'Verify Identity', 3=>'New Password']; ?>
            <?php foreach ($step_info as $n => $label): ?>
            <div class="progress-step <?= $step > $n ? 'done' : ($step == $n ? 'active' : '') ?>">
                <div class="ps-circle">
                    <?php if ($step > $n): ?><i class="fa-solid fa-check" style="font-size:9px"></i><?php else: ?><?= $n ?><?php endif; ?>
                </div>
                <span class="ps-label"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── LOCKED ── -->
        <?php if ($step === 0): ?>
        <div class="step-body" style="text-align:center;padding:20px 0">
            <div class="lock-icon"><i class="fa-solid fa-lock"></i></div>
            <h1 class="form-heading" style="text-align:center">Account Locked</h1>
            <p class="form-subtitle" style="text-align:center">For security, access has been temporarily suspended.</p>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        </div>

        <!-- ── STEP 1: Find Account ── -->
        <?php elseif ($step === 1): ?>
        <div class="step-body">
            <h1 class="form-heading">Forgot Password?</h1>
            <p class="form-subtitle">Enter your staff username and we'll verify your identity.</p>

            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <form method="POST" id="step1Form">
                <input type="hidden" name="action" value="find_user">
                <div class="field-group">
                    <i class="fa-solid fa-user field-icon"></i>
                    <input type="text" name="username" id="usernameInput" placeholder=" " required autofocus autocomplete="username">
                    <label for="usernameInput">Staff Username</label>
                </div>
                <div class="alert alert-info" style="margin-bottom:16px">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>You must have set up a security question on your account to proceed. If you haven't, contact your manager.</span>
                </div>
                <button type="submit" class="submit-btn">
                    <i class="fa-solid fa-arrow-right"></i> Continue
                </button>
            </form>
        </div>

        <!-- ── STEP 2: Security Question ── -->
        <?php elseif ($step === 2): ?>
        <div class="step-body">
            <h1 class="form-heading">Verify Identity</h1>
            <p class="form-subtitle">Answer your security question to prove it's you.</p>

            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <form method="POST" id="step2Form">
                <input type="hidden" name="action" value="verify_answer">
                <div class="question-box">
                    <i class="fa-solid fa-question-circle"></i>
                    <span class="question-text"><?= $fp_question ?></span>
                </div>
                <div class="field-group">
                    <i class="fa-solid fa-key field-icon"></i>
                    <input type="text" name="security_answer" id="answerInput" placeholder=" " required autofocus autocomplete="off">
                    <label for="answerInput">Your Answer</label>
                </div>
                <p style="font-size:11.5px;color:var(--text-muted);margin:-8px 0 16px;line-height:1.5;">
                    <i class="fa-solid fa-circle-info" style="color:var(--accent);margin-right:5px"></i>
                    Answers are not case-sensitive.
                </p>
                <button type="submit" class="submit-btn">
                    <i class="fa-solid fa-shield-check"></i> Verify Answer
                </button>
            </form>
        </div>

        <!-- ── STEP 3: New Password ── -->
        <?php elseif ($step === 3): ?>
        <div class="step-body">
            <h1 class="form-heading">Set New Password</h1>
            <p class="form-subtitle">Choose a strong password to protect your account.</p>

            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <form method="POST" id="step3Form">
                <input type="hidden" name="action" value="reset_password">

                <div class="field-group">
                    <i class="fa-solid fa-lock field-icon"></i>
                    <input type="password" name="new_password" id="newPassInput" placeholder=" " required autocomplete="new-password">
                    <label for="newPassInput">New Password</label>
                    <button type="button" class="toggle-pass" id="toggleNew" title="Show/hide">
                        <i class="fa-solid fa-eye" id="toggleNewIcon"></i>
                    </button>
                </div>

                <!-- Strength meter -->
                <div class="strength-meter">
                    <div class="strength-bars">
                        <div class="s-bar" id="sb1"></div>
                        <div class="s-bar" id="sb2"></div>
                        <div class="s-bar" id="sb3"></div>
                        <div class="s-bar" id="sb4"></div>
                    </div>
                    <span class="strength-label" id="strengthLabel">Enter a password</span>
                    <div class="strength-requirements">
                        <span class="req" id="req-len"><i class="fa-solid fa-circle-dot"></i> 8+ characters</span>
                        <span class="req" id="req-upper"><i class="fa-solid fa-circle-dot"></i> Uppercase</span>
                        <span class="req" id="req-num"><i class="fa-solid fa-circle-dot"></i> Number</span>
                        <span class="req" id="req-sym"><i class="fa-solid fa-circle-dot"></i> Symbol</span>
                    </div>
                </div>

                <div class="field-group">
                    <i class="fa-solid fa-lock-open field-icon"></i>
                    <input type="password" name="confirm_password" id="confirmPassInput" placeholder=" " required autocomplete="new-password">
                    <label for="confirmPassInput">Confirm Password</label>
                    <button type="button" class="toggle-pass" id="toggleConfirm" title="Show/hide">
                        <i class="fa-solid fa-eye" id="toggleConfirmIcon"></i>
                    </button>
                </div>
                <div id="matchHint" style="font-size:11.5px;margin:-8px 0 14px;display:none"></div>

                <button type="submit" class="submit-btn" id="resetBtn" disabled>
                    <i class="fa-solid fa-key"></i> Reset Password
                </button>
            </form>
        </div>

        <!-- ── STEP 4: Success ── -->
        <?php elseif ($step === 4): ?>
        <div class="success-card">
            <div class="success-icon"><i class="fa-solid fa-check"></i></div>
            <h1 class="success-title">Password Reset!</h1>
            <p class="success-msg">Your password has been updated successfully.<br>You can now sign in with your new password.</p>
            <a href="login.php" class="submit-btn" style="text-decoration:none;display:inline-flex;width:auto;padding:0 32px">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In Now
            </a>
        </div>
        <?php endif; ?>

        <div class="form-footer" style="margin-top:20px">
            <a href="login.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Sign In
            </a>
            <div class="secure-badge">
                <i class="fa-solid fa-shield-halved"></i> Secured &middot; Staff only
            </div>
        </div>
    </div>
</div>

<script>
// ── Password strength meter ──────────────────────────────────────────
const newPass     = document.getElementById('newPassInput');
const confirmPass = document.getElementById('confirmPassInput');
const resetBtn    = document.getElementById('resetBtn');

if (newPass) {
    const bars  = [1,2,3,4].map(i => document.getElementById('sb' + i));
    const label = document.getElementById('strengthLabel');
    const reqs  = {
        len:   document.getElementById('req-len'),
        upper: document.getElementById('req-upper'),
        num:   document.getElementById('req-num'),
        sym:   document.getElementById('req-sym'),
    };

    const colors  = ['#e74c3c','#f39c12','#f1c40f','#55e087'];
    const labels  = ['Too Weak','Weak','Fair','Strong','Very Strong'];

    function getScore(v) {
        let s = 0;
        if (v.length >= 8) s++;
        if (/[A-Z]/.test(v)) s++;
        if (/[0-9]/.test(v)) s++;
        if (/[^a-zA-Z0-9]/.test(v)) s++;
        return s;
    }

    function updateStrength() {
        const v = newPass.value;
        const score = getScore(v);

        bars.forEach((b, i) => {
            b.style.background = i < score ? colors[score - 1] : 'rgba(255,255,255,0.08)';
        });
        label.textContent = v.length ? labels[score] : 'Enter a password';
        label.style.color  = v.length ? colors[Math.max(0, score - 1)] : 'var(--text-muted)';

        reqs.len.classList.toggle('met',   v.length >= 8);
        reqs.upper.classList.toggle('met', /[A-Z]/.test(v));
        reqs.num.classList.toggle('met',   /[0-9]/.test(v));
        reqs.sym.classList.toggle('met',   /[^a-zA-Z0-9]/.test(v));

        checkCanSubmit();
    }

    function checkCanSubmit() {
        const v = newPass.value;
        const c = confirmPass ? confirmPass.value : '';
        const strong = getScore(v) >= 3;
        const match  = v === c && v.length > 0;

        if (resetBtn) resetBtn.disabled = !(strong && match);

        if (confirmPass && c.length > 0) {
            const hint = document.getElementById('matchHint');
            hint.style.display = 'block';
            if (match) {
                hint.innerHTML = '<i class="fa-solid fa-check" style="color:var(--success);margin-right:5px"></i><span style="color:var(--success)">Passwords match</span>';
            } else {
                hint.innerHTML = '<i class="fa-solid fa-xmark" style="color:var(--danger);margin-right:5px"></i><span style="color:var(--danger)">Passwords do not match</span>';
            }
        }
    }

    newPass.addEventListener('input', updateStrength);
    if (confirmPass) confirmPass.addEventListener('input', checkCanSubmit);
}

// ── Toggle password visibility ───────────────────────────────────────
function setupToggle(btnId, iconId, inputEl) {
    const btn  = document.getElementById(btnId);
    const icon = document.getElementById(iconId);
    if (!btn || !inputEl) return;
    btn.addEventListener('click', function() {
        const show = inputEl.type === 'password';
        inputEl.type = show ? 'text' : 'password';
        icon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });
}
setupToggle('toggleNew',    'toggleNewIcon',     document.getElementById('newPassInput'));
setupToggle('toggleConfirm','toggleConfirmIcon',  document.getElementById('confirmPassInput'));
</script>
</body>
</html>
