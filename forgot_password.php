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
            "SELECT user_id, security_question FROM users WHERE username = ? LIMIT 1"
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --gold:         #d4933a;
    --gold-light:   #f0b96a;
    --gold-dark:    #a06820;
    --bg:           #0d0a07;
    --card-bg:      #13100c;
    --border:       rgba(212,147,58,0.15);
    --text:         #f0ebe4;
    --muted:        #6b5e4e;
    --danger:       #c0392b;
    --success:      #27ae60;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; min-height: 100%; }

body {
    font-family: 'Outfit', sans-serif;
    background: var(--bg);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    overflow-x: hidden;
    padding: 24px 16px;
}

/* ── Background ── */
.bg-layer {
    position: fixed; inset: 0; z-index: 0;
    overflow: hidden;
}

/* Concentric rings */
.ring {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    border: 1px solid rgba(212,147,58,0.06);
    pointer-events: none;
}
.ring-1 { width: 600px;  height: 600px; }
.ring-2 { width: 900px;  height: 900px; border-color: rgba(212,147,58,0.04); }
.ring-3 { width: 1200px; height: 1200px; border-color: rgba(212,147,58,0.025); }

/* Ambient orbs */
.orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    pointer-events: none;
    opacity: 0.55;
}
.orb-1 {
    width: 500px; height: 500px;
    top: -120px; left: -100px;
    background: radial-gradient(circle, rgba(180,100,20,0.35) 0%, transparent 65%);
    animation: orbDrift1 18s ease-in-out infinite;
}
.orb-2 {
    width: 400px; height: 400px;
    bottom: -80px; right: -60px;
    background: radial-gradient(circle, rgba(130,70,10,0.28) 0%, transparent 65%);
    animation: orbDrift2 22s ease-in-out infinite;
}
.orb-3 {
    width: 300px; height: 300px;
    top: 55%; left: 60%;
    background: radial-gradient(circle, rgba(212,147,58,0.12) 0%, transparent 65%);
    animation: orbDrift3 16s ease-in-out infinite;
}

/* Grid overlay */
.bg-grid {
    position: absolute; inset: 0;
    background-image:
        linear-gradient(rgba(212,147,58,0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(212,147,58,0.025) 1px, transparent 1px);
    background-size: 60px 60px;
}

@keyframes orbDrift1 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(30px,20px)} }
@keyframes orbDrift2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-25px,-18px)} }
@keyframes orbDrift3 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(15px,-25px)} }

/* ── Wordmark ── */
.wordmark {
    position: relative; z-index: 2;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 24px;
    animation: fadeUp 0.5s ease both;
}
.wordmark-icon {
    width: 42px; height: 42px; border-radius: 11px;
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #fff;
    box-shadow: 0 4px 18px rgba(212,147,58,0.35);
    flex-shrink: 0;
}
.wordmark-text { display: flex; flex-direction: column; }
.wordmark-name {
    font-family: 'Syne', sans-serif;
    font-size: 15px; font-weight: 700;
    color: var(--text); line-height: 1.1;
}
.wordmark-sub {
    font-size: 10px; font-weight: 500; letter-spacing: 1.5px;
    text-transform: uppercase; color: var(--gold);
    margin-top: 2px;
}

/* ── Card ── */
.card {
    position: relative; z-index: 2;
    width: 100%; max-width: 460px;
    background: var(--card-bg);
    border: 1px solid rgba(212,147,58,0.12);
    border-radius: 20px;
    padding: 36px 40px 32px;
    box-shadow: 0 24px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,147,58,0.05);
    animation: fadeUp 0.5s 0.08s ease both;
}
.card::before {
    content: '';
    position: absolute; top: 0; left: 20px; right: 20px; height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold), var(--gold-light), var(--gold), transparent);
    border-radius: 0 0 4px 4px;
}
/* Corner accent */
.card::after {
    content: '';
    position: absolute; bottom: 0; right: 0;
    width: 80px; height: 80px;
    background: radial-gradient(circle at 100% 100%, rgba(212,147,58,0.08) 0%, transparent 65%);
    border-radius: 0 0 20px 0;
    pointer-events: none;
}

/* ── Progress bar ── */
.progress-bar {
    display: flex; align-items: center; gap: 0;
    margin-bottom: 28px;
}
.progress-step {
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    flex: 1; position: relative;
}
.progress-step:not(:last-child)::after {
    content: '';
    position: absolute; top: 13px; left: calc(50% + 14px);
    width: calc(100% - 28px); height: 1px;
    background: rgba(212,147,58,0.12);
}
.progress-step.done:not(:last-child)::after,
.progress-step.active:not(:last-child)::after {
    background: linear-gradient(90deg, var(--gold), rgba(212,147,58,0.2));
}
.ps-circle {
    width: 26px; height: 26px; border-radius: 50%;
    border: 1.5px solid rgba(212,147,58,0.15);
    background: rgba(212,147,58,0.04);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 600; color: var(--muted);
    transition: all 0.3s ease; position: relative; z-index: 1;
    font-family: 'Outfit', sans-serif;
}
.progress-step.active .ps-circle {
    border-color: var(--gold);
    background: rgba(212,147,58,0.12);
    color: var(--gold);
    box-shadow: 0 0 0 4px rgba(212,147,58,0.08);
}
.progress-step.done .ps-circle {
    border-color: rgba(39,174,96,0.5);
    background: rgba(39,174,96,0.1);
    color: #55e087;
}
.ps-label {
    font-size: 9.5px; letter-spacing: 0.3px; color: var(--muted);
    font-weight: 500; white-space: nowrap;
}
.progress-step.active .ps-label { color: var(--gold); font-weight: 600; }
.progress-step.done  .ps-label  { color: #55e087; }

/* ── Step heading ── */
.step-tag {
    font-size: 10px; font-weight: 600; letter-spacing: 2px;
    text-transform: uppercase; color: var(--gold);
    margin-bottom: 6px;
}
.step-title {
    font-family: 'Syne', sans-serif;
    font-size: 26px; font-weight: 800;
    color: var(--text); line-height: 1.2;
    margin-bottom: 6px;
}
.step-subtitle {
    font-size: 13.5px; font-weight: 300; color: var(--muted);
    margin-bottom: 22px; line-height: 1.5;
}

/* ── Alerts ── */
.alert {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 11px 14px; border-radius: 10px;
    font-size: 13px; font-weight: 400; margin-bottom: 16px;
    line-height: 1.4;
}
.alert-danger  { background: rgba(192,57,43,0.08);  border: 1px solid rgba(192,57,43,0.2); color: #e07070; animation: shake .45s ease; }
.alert-info    { background: rgba(212,147,58,0.07); border: 1px solid rgba(212,147,58,0.18); color: #c8934a; }
.alert i { flex-shrink: 0; margin-top: 1px; font-size: 13px; }

@keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-6px)} 40%,80%{transform:translateX(6px)} }

/* ── Fields ── */
.field-wrap { position: relative; margin-bottom: 14px; }
.field-label {
    display: block;
    font-size: 9.5px; font-weight: 600; letter-spacing: 1.8px;
    text-transform: uppercase; color: var(--muted);
    margin-bottom: 7px;
}
.field-inner { position: relative; }
.field-icon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 13px; pointer-events: none;
    transition: color .2s;
}
.field-inner input {
    width: 100%; height: 48px;
    padding: 0 44px 0 40px;
    border-radius: 10px;
    border: 1px solid rgba(212,147,58,0.12);
    background: rgba(255,255,255,0.03);
    color: var(--text);
    font-size: 14px; font-weight: 400;
    font-family: 'Outfit', sans-serif;
    outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.field-inner input::placeholder { color: rgba(107,94,78,0.6); }
.field-inner input:focus {
    border-color: rgba(212,147,58,0.45);
    background: rgba(212,147,58,0.04);
    box-shadow: 0 0 0 3px rgba(212,147,58,0.08);
}
.field-inner input:focus ~ .field-icon { color: var(--gold); }
.toggle-pass {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--muted);
    font-size: 13px; cursor: pointer; padding: 4px;
    transition: color .2s;
}
.toggle-pass:hover { color: var(--text); }

/* ── Question box ── */
.question-box {
    display: flex; align-items: flex-start; gap: 12px;
    background: rgba(212,147,58,0.06);
    border: 1px solid rgba(212,147,58,0.18);
    border-radius: 10px; padding: 13px 15px;
    margin-bottom: 14px;
}
.question-box i { color: var(--gold); margin-top: 2px; flex-shrink: 0; }
.question-text { font-size: 13.5px; font-weight: 500; color: var(--text); line-height: 1.45; }

/* ── Strength meter ── */
.strength-meter { margin: -4px 0 14px; }
.s-bars { display: flex; gap: 4px; margin-bottom: 6px; }
.s-bar {
    flex: 1; height: 3px; border-radius: 3px;
    background: rgba(255,255,255,0.07);
    transition: background 0.3s;
}
.strength-label { font-size: 11px; font-weight: 500; color: var(--muted); }
.s-reqs { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.req {
    display: flex; align-items: center; gap: 4px;
    font-size: 10px; color: var(--muted);
    padding: 3px 8px; border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.02);
    transition: all 0.25s;
}
.req.met { color: #55e087; border-color: rgba(85,224,135,0.2); background: rgba(85,224,135,0.06); }
.req i { font-size: 8px; }

/* ── Match hint ── */
.match-hint { font-size: 11.5px; margin: -6px 0 12px; min-height: 18px; }

/* ── Button ── */
.btn-primary {
    position: relative; overflow: hidden;
    width: 100%; height: 50px; border: none; border-radius: 10px;
    background: linear-gradient(135deg, var(--gold-light) 0%, var(--gold) 50%, var(--gold-dark) 100%);
    color: #1a0e00;
    font-family: 'Outfit', sans-serif;
    font-size: 14px; font-weight: 700; letter-spacing: 0.3px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: transform .2s, box-shadow .2s;
    box-shadow: 0 4px 20px rgba(212,147,58,0.3);
    margin-top: 6px;
}
.btn-primary::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.25) 50%, transparent 100%);
    background-size: 200% 100%;
    background-position: -200%;
    transition: background-position 0.5s ease;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(212,147,58,0.4); }
.btn-primary:hover::after { background-position: 200%; }
.btn-primary:active { transform: translateY(0); }
.btn-primary:disabled { opacity: 0.45; cursor: not-allowed; transform: none; box-shadow: none; }

/* ── Footer row ── */
.card-footer {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 20px; padding-top: 18px;
    border-top: 1px solid rgba(212,147,58,0.08);
}
.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 500; color: var(--muted);
    text-decoration: none; transition: all .2s;
}
.back-link:hover { color: var(--gold); transform: translateX(-3px); }
.secure-tag {
    display: flex; align-items: center; gap: 5px;
    font-size: 10.5px; color: rgba(107,94,78,0.5);
}
.secure-tag i { color: #55e087; font-size: 9px; }

/* ── Success / Lock states ── */
.state-center { text-align: center; padding: 16px 0; }
.state-icon {
    width: 72px; height: 72px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; margin: 0 auto 20px;
}
.state-icon.success {
    background: rgba(39,174,96,0.1);
    border: 1.5px solid rgba(39,174,96,0.25);
    color: #55e087;
}
.state-icon.locked {
    background: rgba(192,57,43,0.1);
    border: 1.5px solid rgba(192,57,43,0.25);
    color: #e07070;
}
.state-title {
    font-family: 'Syne', sans-serif;
    font-size: 24px; font-weight: 800; color: var(--text);
    margin-bottom: 8px;
}
.state-msg {
    font-size: 13.5px; font-weight: 300; color: var(--muted);
    line-height: 1.6; margin-bottom: 24px;
}

/* ── Page footer ── */
.page-footer {
    position: relative; z-index: 2;
    margin-top: 20px;
    font-size: 10.5px; letter-spacing: 1.5px;
    text-transform: uppercase; color: rgba(107,94,78,0.4);
    display: flex; align-items: center; gap: 10px;
}
.page-footer i { font-size: 9px; color: #55e087; }

/* ── Animations ── */
@keyframes fadeUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
.step-body { animation: fadeUp 0.3s ease both; }

@media (max-width: 500px) {
    .card { padding: 28px 22px 24px; border-radius: 16px; }
    .step-title { font-size: 22px; }
}
</style>
</head>
<body>

<div class="bg-layer">
    <div class="bg-grid"></div>
    <div class="ring ring-1"></div>
    <div class="ring ring-2"></div>
    <div class="ring ring-3"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<!-- Wordmark -->
<div class="wordmark">
    <div class="wordmark-icon"><i class="fa-solid fa-mug-hot"></i></div>
    <div class="wordmark-text">
        <span class="wordmark-name">Bird's Nest Coffee</span>
        <span class="wordmark-sub">Account Recovery</span>
    </div>
</div>

<!-- Card -->
<div class="card">

    <?php if ($step >= 1 && $step <= 3): ?>
    <div class="progress-bar">
        <?php $steps = [1 => 'Find Account', 2 => 'Verify', 3 => 'New Password']; ?>
        <?php foreach ($steps as $n => $label): ?>
        <div class="progress-step <?= $step > $n ? 'done' : ($step === $n ? 'active' : '') ?>">
            <div class="ps-circle">
                <?php if ($step > $n): ?><i class="fa-solid fa-check"></i><?php else: ?><?= $n ?><?php endif; ?>
            </div>
            <span class="ps-label"><?= $label ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── LOCKED ── -->
    <?php if ($step === 0): ?>
    <div class="step-body state-center">
        <div class="state-icon locked"><i class="fa-solid fa-lock"></i></div>
        <h1 class="state-title">Account Locked</h1>
        <p class="state-msg">For security, access has been temporarily suspended.</p>
        <div class="alert alert-danger">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    </div>

    <!-- ── STEP 1 ── -->
    <?php elseif ($step === 1): ?>
    <div class="step-body">
        <p class="step-tag">Step 1 of 3</p>
        <h1 class="step-title">Forgot Password?</h1>
        <p class="step-subtitle">Enter your staff username to begin recovery.</p>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="find_user">
            <div class="field-wrap">
                <label class="field-label" for="usernameInput">Staff Username</label>
                <div class="field-inner">
                    <i class="fa-solid fa-user field-icon"></i>
                    <input type="text" name="username" id="usernameInput" placeholder="Enter your username" required autofocus autocomplete="username">
                </div>
            </div>
            <div class="alert alert-info" style="margin-bottom:16px">
                <i class="fa-solid fa-shield-halved"></i>
                <span>You must have a security question set up to proceed. If not, contact your manager.</span>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-arrow-right"></i> Continue
            </button>
        </form>
    </div>

    <!-- ── STEP 2 ── -->
    <?php elseif ($step === 2): ?>
    <div class="step-body">
        <p class="step-tag">Step 2 of 3</p>
        <h1 class="step-title">Verify Identity</h1>
        <p class="step-subtitle">Answer your security question to proceed.</p>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="verify_answer">
            <div class="question-box">
                <i class="fa-solid fa-circle-question"></i>
                <span class="question-text"><?= $fp_question ?></span>
            </div>
            <div class="field-wrap">
                <label class="field-label" for="answerInput">Your Answer</label>
                <div class="field-inner">
                    <i class="fa-solid fa-key field-icon"></i>
                    <input type="text" name="security_answer" id="answerInput" placeholder="Not case-sensitive" required autofocus autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-shield-check"></i> Verify Answer
            </button>
        </form>
    </div>

    <!-- ── STEP 3 ── -->
    <?php elseif ($step === 3): ?>
    <div class="step-body">
        <p class="step-tag">Step 3 of 3</p>
        <h1 class="step-title">New Password</h1>
        <p class="step-subtitle">Choose a strong password to secure your account.</p>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST" id="step3Form">
            <input type="hidden" name="action" value="reset_password">

            <div class="field-wrap">
                <label class="field-label" for="newPassInput">New Password</label>
                <div class="field-inner">
                    <i class="fa-solid fa-lock field-icon"></i>
                    <input type="password" name="new_password" id="newPassInput" placeholder="Enter new password" required autocomplete="new-password">
                    <button type="button" class="toggle-pass" id="toggleNew"><i class="fa-solid fa-eye" id="toggleNewIcon"></i></button>
                </div>
            </div>

            <div class="strength-meter">
                <div class="s-bars">
                    <div class="s-bar" id="sb1"></div>
                    <div class="s-bar" id="sb2"></div>
                    <div class="s-bar" id="sb3"></div>
                    <div class="s-bar" id="sb4"></div>
                </div>
                <span class="strength-label" id="strengthLabel">Enter a password</span>
                <div class="s-reqs">
                    <span class="req" id="req-len"><i class="fa-solid fa-circle-dot"></i> 8+ chars</span>
                    <span class="req" id="req-upper"><i class="fa-solid fa-circle-dot"></i> Uppercase</span>
                    <span class="req" id="req-num"><i class="fa-solid fa-circle-dot"></i> Number</span>
                    <span class="req" id="req-sym"><i class="fa-solid fa-circle-dot"></i> Symbol</span>
                </div>
            </div>

            <div class="field-wrap">
                <label class="field-label" for="confirmPassInput">Confirm Password</label>
                <div class="field-inner">
                    <i class="fa-solid fa-lock-open field-icon"></i>
                    <input type="password" name="confirm_password" id="confirmPassInput" placeholder="Repeat new password" required autocomplete="new-password">
                    <button type="button" class="toggle-pass" id="toggleConfirm"><i class="fa-solid fa-eye" id="toggleConfirmIcon"></i></button>
                </div>
            </div>
            <div class="match-hint" id="matchHint"></div>

            <button type="submit" class="btn-primary" id="resetBtn" disabled>
                <i class="fa-solid fa-key"></i> Reset Password
            </button>
        </form>
    </div>

    <!-- ── STEP 4: Success ── -->
    <?php elseif ($step === 4): ?>
    <div class="step-body state-center">
        <div class="state-icon success"><i class="fa-solid fa-check"></i></div>
        <h1 class="state-title">Password Reset!</h1>
        <p class="state-msg">Your password has been updated successfully.<br>You can now sign in with your new credentials.</p>
        <a href="login.php" class="btn-primary" style="text-decoration:none;width:auto;padding:0 36px;margin:0 auto">
            <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In Now
        </a>
    </div>
    <?php endif; ?>

    <div class="card-footer">
        <a href="login.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Sign In
        </a>
        <div class="secure-tag">
            <i class="fa-solid fa-shield-halved"></i> Secured
        </div>
    </div>
</div>

<div class="page-footer">
    <i class="fa-solid fa-shield-halved"></i>
    Staff Only &nbsp;·&nbsp; Secured &nbsp;·&nbsp; <?= date('Y') ?>
</div>

<script>
// ── Password strength ────────────────────────────────────────────────
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
    const colors = ['#c0392b','#e67e22','#f1c40f','#55e087'];
    const labels = ['Too Weak','Weak','Fair','Strong','Very Strong'];

    function getScore(v) {
        let s = 0;
        if (v.length >= 8)               s++;
        if (/[A-Z]/.test(v))             s++;
        if (/[0-9]/.test(v))             s++;
        if (/[^a-zA-Z0-9]/.test(v))      s++;
        return s;
    }

    function updateStrength() {
        const v = newPass.value;
        const score = getScore(v);
        bars.forEach((b, i) => {
            b.style.background = i < score ? colors[score - 1] : 'rgba(255,255,255,0.07)';
        });
        label.textContent = v.length ? labels[score] : 'Enter a password';
        label.style.color  = v.length ? colors[Math.max(0, score - 1)] : 'var(--muted)';
        reqs.len.classList.toggle('met',   v.length >= 8);
        reqs.upper.classList.toggle('met', /[A-Z]/.test(v));
        reqs.num.classList.toggle('met',   /[0-9]/.test(v));
        reqs.sym.classList.toggle('met',   /[^a-zA-Z0-9]/.test(v));
        checkSubmit();
    }

    function checkSubmit() {
        const v = newPass.value;
        const c = confirmPass ? confirmPass.value : '';
        const strong = getScore(v) >= 3;
        const match  = v === c && v.length > 0;
        if (resetBtn) resetBtn.disabled = !(strong && match);
        if (confirmPass && c.length > 0) {
            const hint = document.getElementById('matchHint');
            hint.innerHTML = match
                ? '<i class="fa-solid fa-check" style="color:#55e087;margin-right:5px"></i><span style="color:#55e087">Passwords match</span>'
                : '<i class="fa-solid fa-xmark" style="color:#e07070;margin-right:5px"></i><span style="color:#e07070">Passwords do not match</span>';
        }
    }

    newPass.addEventListener('input', updateStrength);
    if (confirmPass) confirmPass.addEventListener('input', checkSubmit);
}

// ── Toggle visibility ────────────────────────────────────────────────
function setupToggle(btnId, iconId, inputEl) {
    const btn  = document.getElementById(btnId);
    const icon = document.getElementById(iconId);
    if (!btn || !inputEl) return;
    btn.addEventListener('click', () => {
        const show = inputEl.type === 'password';
        inputEl.type = show ? 'text' : 'password';
        icon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });
}
setupToggle('toggleNew',     'toggleNewIcon',     document.getElementById('newPassInput'));
setupToggle('toggleConfirm', 'toggleConfirmIcon', document.getElementById('confirmPassInput'));
</script>
</body>
</html>
