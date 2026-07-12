# Admin-Required Security Question Setup — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admin/manager flag an employee ("Require setup") from `admin_reset_password.php` so the employee is prompted to set their own security question at next login; the admin never sets or learns the answer.

**Architecture:** New `users.must_set_security` flag (guarded migration). `admin_reset_password.php` gets two POST actions (`require_security` / `cancel_security`, same guards as `force_reset`), a 3-state security badge, and a summary stat. `login.php` routes flagged users to `profile.php`. `profile.php` shows a persistent "Remind me later" banner and clears the flag when the employee saves their security question.

**Tech Stack:** PHP 8 procedural, mysqli prepared statements, MySQL/MariaDB (XAMPP). No unit-test framework — verify via `php -l`, DB inspection, curl, and browser (admin **Sokun** + a non-admin test account).

**Spec:** `docs/superpowers/specs/2026-07-12-admin-require-security-question.md`.

## Global Constraints

- Branch **feat/product-addons** (local only, do NOT push). Commit each task.
- Admin never sets/learns the answer — the admin actions only toggle the `must_set_security` flag. The employee sets their own hashed answer via the existing `save_security` handler.
- New POST actions carry the **same guards** as `force_reset` (admin_reset_password.php:25-37): CSRF `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])`, page perm `can('reset_password')` (already gating the file), and **non-admin cannot target an admin account**. All DB writes are prepared statements with a bound int `user_id` (`$target = (int)$_POST['target_user_id']`).
- Soft enforcement only (no hard lockout), matching the existing `must_change_password` login-redirect behavior. Banner appears at next login / next `profile.php` load, not mid-session.
- The `profile.php` password-change handler (line 56-64) must NOT touch `must_set_security`.
- **profile.php has no JS tabs** — it is stacked cards (Change Password, then Security Question). `$active_tab` is only toast context. So "prompt on the security tab" = a banner at the top + the existing Security Question card below (optionally auto-scrolled). Do not build a tab system.

---

### Task 1: Migration — `users.must_set_security`

**Files:**
- Modify: `config.php` (add one `_migrate(...)` block near the other user/security migrations)

**Interfaces:**
- Produces: `users.must_set_security TINYINT(1) NOT NULL DEFAULT 0`. `1` = admin required setup, not yet done. Consumed by Tasks 2-4.

- [ ] **Step 1: Add the guarded migration**

Add this `_migrate` block in `config.php` alongside the other one-time migrations (anywhere in the migration run, e.g. right after the `ingredient_history_count_adjust_v1` / `stock_counts_reconciled_v1` blocks added earlier, or near other `users` migrations — placement is id-guarded so order doesn't matter):
```php
_migrate($conn, 'users_must_set_security_v1', function($db) {
    $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_set_security TINYINT(1) NOT NULL DEFAULT 0");
});
```

- [ ] **Step 2: Trigger + verify the column**

Load any page (forces migrations) or run:
```bash
php -r "require 'config.php'; \$c=[]; \$r=\$conn->query(\"SHOW COLUMNS FROM users\"); while(\$x=\$r->fetch_assoc())\$c[\$x['Field']]=\$x['Type']; echo isset(\$c['must_set_security'])?('OK '.\$c['must_set_security']):'MISSING',\"\n\"; echo \$conn->query(\"SELECT COUNT(*) FROM schema_migrations WHERE id='users_must_set_security_v1'\")->fetch_row()[0],\"\n\";"
```
Expected: `OK tinyint(1)` and `1` (migration recorded).

- [ ] **Step 3: Verify idempotency**

Re-run the same command.
Expected: same output, no error (guarded by `schema_migrations`).

- [ ] **Step 4: Commit**

```bash
git add config.php
git commit -m "feat(security): migration for users.must_set_security flag"
```

---

### Task 2: admin_reset_password.php — require/cancel actions, 3-state badge, stat

**Files:**
- Modify: `admin_reset_password.php` (POST handler ~60-69; user query line 82; stats line 90-92; stat cards ~363-379; security badge ~430-436)

**Interfaces:**
- Consumes: `users.must_set_security` (Task 1).
- Produces: sets/clears the flag via POST; shows badge state + count. No interface for later tasks.

- [ ] **Step 1: Add the two POST actions**

In `admin_reset_password.php`, after the `clear_flag` block (which ends at line 69, before the closing `}` of the POST handler), add:
```php
    // Require the employee to set up their security question
    elseif ($action === 'require_security' && $target > 0) {
        // Non-admin cannot target an admin account (mirrors force_reset guard)
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $check = $conn->prepare("SELECT r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.user_id = ?");
            $check->bind_param("i", $target);
            $check->execute();
            $tgt_role = $check->get_result()->fetch_assoc()['role'] ?? '';
            if ($tgt_role === 'admin') {
                $toast = "You cannot change an admin account.";
                $toast_type = 'error';
                goto render;
            }
        }
        $stmt = $conn->prepare("UPDATE users SET must_set_security = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $toast = "Security-question setup required for this employee. They'll be prompted at next login.";
        $toast_type = 'success';
    }

    // Cancel the requirement
    elseif ($action === 'cancel_security' && $target > 0) {
        $stmt = $conn->prepare("UPDATE users SET must_set_security = 0 WHERE user_id = ?");
        $stmt->bind_param("i", $target);
        $stmt->execute();
        $toast = "Security-question requirement cleared.";
        $toast_type = 'success';
    }
```
(The `require_security` guard mirrors `force_reset` at lines 27-37, including the `goto render;` early-exit; `cancel_security` only clears a flag so it needs no admin-target guard beyond the shared CSRF/perm gate — but keep it symmetric and harmless.)

- [ ] **Step 2: Load the flag in the user list query**

`admin_reset_password.php:82`, change:
```php
    "SELECT u.user_id, u.username, r.slug AS role, u.security_question, u.must_change_password
```
to:
```php
    "SELECT u.user_id, u.username, r.slug AS role, u.security_question, u.must_change_password, u.must_set_security
```

- [ ] **Step 3: Compute the summary stat**

After `admin_reset_password.php:92` (`$must_change = ...`), add:
```php
$sec_required = count(array_filter($all_users, fn($u) => !empty($u['must_set_security'])));
```

- [ ] **Step 4: Render the stat card**

After the "Pending Password Change" stat card (ends ~line 379-380, the `</div>` closing that `.stat-card`), add a 4th card mirroring it:
```php
        <div class="stat-card">
            <div style="display:flex;align-items:center;gap:12px">
            <div>
                <div class="stat-val" style="color:<?= $sec_required > 0 ? 'var(--warning)' : 'var(--success)' ?>"><?= $sec_required ?></div>
                <div class="stat-lbl">Security Setup Required</div>
            </div>
            </div>
        </div>
```
(Match the exact inner structure of the neighbouring "Pending Password Change" card — copy its wrapper divs/icon layout so the four cards render uniformly. If that card wraps its value in an icon+flex row, replicate it verbatim with the new label/value.) Also add `.stat-card:nth-child(4){animation-delay:.29s}` next to the existing `nth-child` rules (~line 165).

- [ ] **Step 5: Replace the 2-state security badge with 3 states + action**

`admin_reset_password.php:430-436`, replace:
```php
                    <td>
                        <?php if (!empty($u['security_question'])): ?>
                        <span class="badge badge-ok"><i class="fa-solid fa-check"></i> Set</span>
                        <?php else: ?>
                        <span class="badge badge-missing"><i class="fa-solid fa-exclamation"></i> Not set</span>
                        <?php endif; ?>
                    </td>
```
with:
```php
                    <td>
                        <?php if (!empty($u['security_question'])): ?>
                        <span class="badge badge-ok"><i class="fa-solid fa-check"></i> Set</span>
                        <?php elseif (!empty($u['must_set_security'])): ?>
                        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                            <span class="badge badge-alert"><i class="fa-solid fa-hourglass-half"></i> Setup required</span>
                            <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="cancel_security">
                                <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                                <button type="submit" class="btn-sm"><i class="fa-solid fa-xmark"></i> Cancel</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                            <span class="badge badge-missing"><i class="fa-solid fa-exclamation"></i> Not set</span>
                            <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="require_security">
                                <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                                <button type="submit" class="btn-sm"><i class="fa-solid fa-user-shield"></i> Require setup</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
```
(`badge-alert`, `badge-missing`, `badge-ok`, `btn-sm` are existing classes on this page. The `$u['user_id'] != $_SESSION['user_id']` guard hides the action on the admin's own row, matching the existing Reset-button pattern at line 446.)

- [ ] **Step 6: Verify syntax**

```bash
php -l admin_reset_password.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add admin_reset_password.php
git commit -m "feat(security): require/cancel security-setup actions + 3-state badge + stat"
```

---

### Task 3: login.php — route flagged users to profile.php

**Files:**
- Modify: `login.php:53`

**Interfaces:**
- Consumes: `must_set_security` (already available — login selects `u.*`).
- Produces: redirect behavior. No interface for later tasks.

- [ ] **Step 1: Extend the post-login redirect**

`login.php:53`, change:
```php
            if (!empty($user['must_change_password'])) { header("Location: profile.php"); exit; }
```
to:
```php
            if (!empty($user['must_change_password']) || !empty($user['must_set_security'])) { header("Location: profile.php"); exit; }
```
(Password change still takes priority when both are set: the employee changes the password first, then is re-prompted for security on the following login. `SELECT u.*` at login.php:25 already includes the new column, so no query change.)

- [ ] **Step 2: Verify syntax**

```bash
php -l login.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add login.php
git commit -m "feat(security): route must_set_security users to profile on login"
```

---

### Task 4: profile.php — required banner + clear flag on save

**Files:**
- Modify: `profile.php` (user SELECT line 10; flags ~97-98; `save_security` UPDATE line 87-92; banner block ~404-410)

**Interfaces:**
- Consumes: `must_set_security` (Task 1); routed here by Task 3.
- Produces: employee-facing prompt; clears the flag on save.

- [ ] **Step 1: Load the flag**

`profile.php:10`, change:
```php
$stmt = $conn->prepare("SELECT username, security_question, must_change_password FROM users WHERE user_id = ?");
```
to:
```php
$stmt = $conn->prepare("SELECT username, security_question, must_change_password, must_set_security FROM users WHERE user_id = ?");
```

- [ ] **Step 2: Compute the flag near the other view flags**

After `profile.php:98` (`$has_sq = ...`), add:
```php
$must_set_sec = !empty($user['must_set_security']) && !$has_sq;
```
(Only "required" when the flag is set AND no question exists yet — a saved question makes the requirement moot.)

- [ ] **Step 3: Clear the flag when the employee saves their security question**

`profile.php:87-92`, change the success branch of `save_security`:
```php
            $hashed_ans = password_hash($answer, PASSWORD_DEFAULT);
            $stmt3 = $conn->prepare("UPDATE users SET security_question = ?, security_answer = ? WHERE user_id = ?");
            $stmt3->bind_param("ssi", $question, $hashed_ans, $user_id);
            $stmt3->execute();
            $toast = "Security question saved successfully!";
            $toast_type = 'success';
            $user['security_question'] = $question;
```
to:
```php
            $hashed_ans = password_hash($answer, PASSWORD_DEFAULT);
            $stmt3 = $conn->prepare("UPDATE users SET security_question = ?, security_answer = ?, must_set_security = 0 WHERE user_id = ?");
            $stmt3->bind_param("ssi", $question, $hashed_ans, $user_id);
            $stmt3->execute();
            $toast = "Security question saved successfully!";
            $toast_type = 'success';
            $user['security_question']  = $question;
            $user['must_set_security']  = 0;
```
(The `$has_sq`/`$must_set_sec` computations at lines 97-98 run AFTER the POST handler, so they pick up the updated `$user` and the banner disappears on the post-save render.)

- [ ] **Step 4: Show the required banner ("Remind me later")**

`profile.php:404-410`, replace the existing security-missing tip:
```php
    <!-- Security question missing tip -->
    <?php if (!$has_sq): ?>
    <div class="sq-missing-banner">
        <i class="fa-solid fa-shield-exclamation"></i>
        <span><strong>No security question set.</strong> Set one up below so you can recover your account if you ever forget your password — without needing to contact an admin.</span>
    </div>
    <?php endif; ?>
```
with:
```php
    <!-- Security question required by manager -->
    <?php if ($must_set_sec): ?>
    <div class="sq-missing-banner" style="border-color:rgba(243,156,18,.35);background:rgba(243,156,18,.08)">
        <i class="fa-solid fa-user-shield" style="color:var(--warning)"></i>
        <span style="color:var(--text)">
            <strong>Your manager asked you to set a security question</strong> so you can recover your own password if you forget it. Please set one below.
            &nbsp;<a href="<?= $home_url ?>" style="color:var(--text-muted);text-decoration:underline">Remind me later</a>
        </span>
    </div>
    <?php elseif (!$has_sq): ?>
    <div class="sq-missing-banner">
        <i class="fa-solid fa-shield-exclamation"></i>
        <span><strong>No security question set.</strong> Set one up below so you can recover your account if you ever forget your password — without needing to contact an admin.</span>
    </div>
    <?php endif; ?>
```
("Remind me later" points to `$home_url` (line 99). The flag stays `1`, so it re-prompts next login / next `profile.php` load — a deferral, not a dismissal.)

- [ ] **Step 5: Verify syntax**

```bash
php -l profile.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add profile.php
git commit -m "feat(security): profile prompt for manager-required security setup + clear flag on save"
```

---

### Task 5: End-to-end verification (browser + DB)

**Files:** none (verification only).

Use admin **Sokun** and a **non-admin test account**. Avoid tripping the login rate-limiter (5 fails/15 min per IP) — log in with correct credentials only.

- [ ] **Step 1: Require setup (admin)**

As Sokun, open `admin_reset_password.php`. Find a non-admin employee whose security badge is **"Not set"**. Click **"Require setup"**.
Expected: success flash; badge flips to **"Setup required"** with a **Cancel** button; the "Security Setup Required" stat increments. DB check:
```bash
php -r "require 'config.php'; print_r(\$conn->query(\"SELECT user_id,username,must_set_security FROM users WHERE must_set_security=1\")->fetch_all(MYSQLI_ASSOC));"
```
Expected: the targeted user has `must_set_security=1`.

- [ ] **Step 2: Non-admin cannot target admin**

While logged in as Sokun this can't be exercised (Sokun is admin). Instead confirm the guard by code + one negative POST: as a **manager/non-admin** account (if available), a `require_security` POST targeting an admin `user_id` returns the "cannot change an admin account" flash and does not set the flag. If no manager account exists, note this is covered by the `force_reset` guard it mirrors (verified in review) and skip the live step.

- [ ] **Step 3: Employee prompt on login**

Log out; log in as the flagged employee.
Expected: lands on `profile.php`; the amber **"Your manager asked you to set a security question"** banner shows with a **"Remind me later"** link. Click "Remind me later" → reaches the role home. Log out and back in → banner reappears (flag still 1).

- [ ] **Step 4: Employee sets it → flag clears**

As the flagged employee on `profile.php`, fill the Security Question card (pick a question, type an answer, confirm current password) and save.
Expected: "Security question saved" toast; banner gone. DB check:
```bash
php -r "require 'config.php'; \$r=\$conn->query(\"SELECT username,must_set_security,security_question IS NOT NULL AS has_q FROM users WHERE username='<THAT_USER>'\")->fetch_assoc(); print_r(\$r);"
```
Expected: `must_set_security=0`, `has_q=1`. Back on `admin_reset_password.php` (as Sokun) the badge now reads **"Set"**.

- [ ] **Step 5: Recovery works + password change doesn't clear flag**

- Confirm `forgot_password.php` now advances past step 1 for that user (they have a question).
- Re-flag a *different* test user (`require_security`), then have them change their **password** via profile (not security). Verify `must_set_security` stays `1` afterward (the password handler must not clear it), and they're re-prompted for security on next login. Clean up test flags afterward:
```bash
php -r "require 'config.php'; \$conn->query(\"UPDATE users SET must_set_security=0 WHERE username IN ('<TESTUSERS>')\"); echo 'cleared test flags';"
```

- [ ] **Step 6: Cancel action**

As Sokun, on a "Setup required" row click **Cancel** → badge returns to "Not set", flag `0`, stat decrements.

---

## Notes for the implementer

- Do NOT add `must_set_security` to the password-change UPDATE in `profile.php` (line 58). The flag must survive a password change.
- Do NOT build a JS tab system in `profile.php` — it uses stacked cards. The banner + existing Security Question card is the whole surface.
- Do NOT audit-log the require/cancel actions (spec: rejected — the only audit table is role-scoped). The flash + badge state are the record.
- Keep the admin actions flag-only. Never let the admin set the question text or the answer.
