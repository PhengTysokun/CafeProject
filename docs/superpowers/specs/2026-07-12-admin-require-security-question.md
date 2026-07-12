# Admin-Required Security Question Setup — Design

**Date:** 2026-07-12
**Branch context:** feat/product-addons (local, not pushed)
**Status:** Approved pending user spec review

## Problem

`admin_reset_password.php` shows each employee's security-question status; when
absent it renders a static **"Not set"** badge. There's no way for an admin/manager
to act on it. A security question is what lets an employee **self-recover** a
forgotten password via `forgot_password.php`; without one, the only recovery is an
admin password reset.

Goal: let admin/manager **require** an employee to set up their security question,
straight from that page.

## Key decision — the employee sets their own answer

The security **answer** is a recovery secret, stored **hashed** (`password_hash`,
verified with `password_verify`; normalized `strtolower(trim())`). If an admin typed
the answer, (a) the admin would know it — a silent recovery backdoor the employee
never sees, and (b) it would be useless for its actual purpose, since the *employee*
wouldn't know the answer to self-recover.

Therefore the admin action is **"Require setup"**, not "set the answer." The admin
flags the account; the **employee sets their own question + answer** at next login,
reusing the existing self-service flow in `profile.php` (`save_security` handler).
The admin never learns the secret.

## Enforcement — persistent prompt (soft), matching existing `must_change_password`

`must_change_password` today is a **soft login redirect** (`login.php:53` sends the
user to `profile.php` on login; it is not hard-enforced on every page). To stay
consistent, `must_set_security` is also soft:

- At next login the employee lands on `profile.php` (security tab) with a banner:
  *"Your manager asked you to set a security question so you can recover your own
  password."*
- They may **"Remind me later"** and use the POS/dashboard; they are **re-prompted every
  login** until they save one. (If they navigate back to `profile.php` in the same
  session the banner reappears — the flag is still `1` and the security tab is the GET
  default — so "later" means "next visit," not a permanent dismissal.)
- Saving a security question **clears the flag**.

No hard lockout — a cashier is never blocked from working mid-shift over this.

**Timing note:** the banner is driven by `$user`, loaded at page start, so it appears
on the employee's **next login / next `profile.php` load** — not mid-session. If an
admin sets the flag while the employee is already sitting on a page, the employee sees
it on their next navigation to `profile.php` or next login. This is fine for the soft
model and is intended, not a bug.

## Data model (1 migration, guarded, in config.php)

`users_must_set_security_v1`:
`ALTER TABLE users ADD COLUMN IF NOT EXISTS must_set_security TINYINT(1) NOT NULL DEFAULT 0`

`must_set_security = 1` → admin has requested setup and it isn't done yet. Cleared to
`0` when the employee saves a security question (in `profile.php`).

## Files & changes

### 1. config.php
- Add the guarded migration above (via existing `_migrate($conn, 'id', fn)` helper).

### 2. admin_reset_password.php
- **New POST action `require_security`** (mirrors the existing `force_reset` guards):
  - CSRF: `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])` (already
    bootstrapped at top of file).
  - Permission: page already gated by `can('reset_password')`.
  - **Non-admin cannot target an admin account** — mirror the existing check
    (`admin_reset_password.php:27-32`): if the actor's role isn't `admin`, look up the
    target's role and reject if it's `admin`.
  - `UPDATE users SET must_set_security = 1 WHERE user_id = ?` (prepared, int-bound).
  - Flash confirmation ("Security-question setup required for <username>.").
- **New action `cancel_security`** (same guards): `UPDATE users SET
  must_set_security = 0 WHERE user_id = ?` — lets an admin undo the requirement.
  (Paired with `require_security` — consistent verb-noun naming in the handler block.)
- **Load the flag**: add `u.must_set_security` to the user list query
  (`admin_reset_password.php:82`).
- **Badge rendering** (replaces the current 2-state Set / "Not set" at ~431-434) —
  three states:
  - Has `security_question` → existing "Set" badge (unchanged).
  - No question **and** `must_set_security = 1` → **"Setup required"** badge (amber/
    hourglass) + a small **"Cancel"** form (`cancel_security`).
  - No question and not required → **"Not set"** badge + a **"Require setup"** button
    (`require_security`) inside a POST form with CSRF + target `user_id`.
- **Summary stat (included):** a "N require setup" count alongside the existing
  `must_change` count — `$sec_required = count(array_filter($all_users, fn($u) =>
  !empty($u['must_set_security'])))` (computed from the already-loaded `$all_users`, no
  extra query), rendered next to the existing counts. Gives the admin at-a-glance
  visibility of how many employees are pending — the point of the feature.

### 3. login.php
- The successful-login redirect (`login.php:53`) currently: `if
  must_change_password → profile.php`. Extend so `must_set_security = 1` **also**
  routes to `profile.php` (password change takes priority when both are set — the
  employee changes the password first, then is re-prompted for security on the next
  login). `SELECT u.*` already includes the new column, so no query change needed.
  Redirect target: `profile.php` (the page auto-selects the security tab from the
  flag — see below).

### 4. profile.php
- Load `must_set_security` in the user SELECT (`profile.php:10`).
- When `must_set_security = 1`:
  - Force `$active_tab = 'security'` on GET load (unless the user explicitly clicked
    another tab) and render a **persistent banner** above the security form with the
    "manager asked you…" copy and a **"Skip for now"** link to the role's home
    (`$home_url`, already computed at `profile.php:99`).
- In the `save_security` success branch (`profile.php:85-92`): also set
  `must_set_security = 0` in the same UPDATE (or a follow-up UPDATE), and update the
  in-memory `$user` so the banner disappears on the post-save render.
- **The password-change handler (`profile.php:56-64`) must NOT touch
  `must_set_security`** — it already only writes `password` + `must_change_password`
  (verified). The flag survives a password change on purpose: an employee with both
  flags changes their password first, then is re-prompted for the security question on
  the following login. Do not add `must_set_security` to that UPDATE.
- **"Remind me later" link:** points to `$home_url` (`profile.php:99`); relabelled from
  "Skip for now" to make clear it's a deferral, not a permanent dismissal (re-prompts
  next visit).

## Security & correctness invariants

- Admin never sets or learns the employee's answer (answer stays employee-chosen,
  hashed). "Require setup" only toggles a flag.
- New POST actions carry the **same** guards as `force_reset`: CSRF, `can('reset_password')`,
  and the non-admin-cannot-target-admin rule.
- All DB writes are prepared statements with bound (int) `user_id`.
- Soft enforcement only — no page is hard-blocked; consistent with existing
  `must_change_password` behavior. A user can always reach the POS.

## Out of scope / rejected

- **Admin sets the question or answer directly** — rejected: leaks the secret /
  defeats self-recovery (see Key decision).
- **Hard lockout until set** — rejected by user: risks blocking staff mid-shift.
- **Auto-requiring security setup after a password reset** — `force_reset` already
  NULLs the security Q; auto-setting `must_set_security` there would be reasonable but
  is left out to keep reset behavior unchanged and the requirement an explicit admin
  action. Deferred, not built.
- Changing how the answer is hashed/verified, or the `forgot_password.php` flow.
- **Audit-logging the require/cancel action** — considered, rejected. The only audit
  table (`role_audit_log`) is role-scoped (`action, role_slug, detail, performed_by`,
  no user/target column) and doesn't fit a per-employee event; a new general audit
  table for one flag toggle is over-engineering. The flash + the visible badge state
  are sufficient. Revisit if a general user-action audit log is introduced later.

## Testing

- Migration: fresh load adds `must_set_security` (default 0); re-load idempotent.
- Require: admin clicks "Require setup" on a "Not set" employee → flag=1, badge flips
  to "Setup required"; a manager is rejected when targeting an admin row; missing/bad
  CSRF rejected.
- Cancel: "Cancel" clears the flag, badge returns to "Not set".
- Login routing: an employee with `must_set_security=1` lands on `profile.php`
  security tab with the banner; "Skip for now" reaches the dashboard; the prompt
  returns on the next login.
- Save clears: employee saves a security question → flag=0, badge shows "Set", no
  further prompt; `forgot_password.php` recovery now works for that employee.
- Both flags: an employee with `must_change_password=1` and `must_set_security=1` is
  sent to change the password first, then prompted for security on the following login.
- Live verify (admin Sokun + a non-admin test account) per the project's browser/DB
  pattern.
