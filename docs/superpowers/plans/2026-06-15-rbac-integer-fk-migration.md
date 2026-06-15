# RBAC Integer FK Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `role VARCHAR(50)` slug references in `users` and `role_permissions` with `role_id INT` foreign keys pointing to `roles.id`, fixing the disconnected ER diagram and adding proper referential integrity.

**Architecture:** All DB schema changes run as `_migrate()` blocks in `config.php` so they apply automatically on next page load and never run twice. Session continues storing the role slug string (`$_SESSION['role']`) for all existing permission checks — we additionally store `$_SESSION['role_id']` (int) so the `can()` function can query by integer FK instead of string. No UI changes required.

**Tech Stack:** PHP 8+, MySQL/MariaDB (XAMPP), MySQLi, no ORM — raw SQL throughout.

---

## File Map

| File | What changes |
|------|-------------|
| `config.php` | Two new `_migrate()` blocks (schema); update all seed INSERT statements; update `can()` function |
| `login.php` | JOIN roles on login to get slug + id; store both in session |
| `auth.php` | JOIN roles in role re-sync query; store `role_id` in session |
| `admin_only.php` | JOIN roles when reading user role from DB |
| `admin_reset_password.php` | JOIN roles in user list query + force-reset role check |
| `manage_roles.php` | Rewrite all queries that reference `role_permissions.role` or `users.role` as VARCHAR |
| `manage_admin.php` | INSERT admin user with `role_id`; SELECT admins via JOIN |
| `employee_add.php` | INSERT user with `role_id` via subquery |
| `employees.php` | UPDATE users `role_id` in edit and quick_role handlers; fix GROUP BY role query |

---

## Task 1: Migrate `role_permissions` table to use `role_id INT`

**Files:**
- Modify: `config.php` (add after line 301 — after `rbac_barista_station_mgmt_fix_v1` migration)

- [ ] **Step 1: Add the migration block to config.php**

Find this line in `config.php` (after the `rbac_barista_station_mgmt_fix_v1` migration closes):
```php
// ── Audit log table ──
_migrate($conn, 'role_audit_log_v1', function($db) {
```

Insert the new migration block BEFORE it:
```php
// ── Migrate role_permissions: replace role VARCHAR with role_id INT FK ──
_migrate($conn, 'rbac_role_permissions_int_fk_v1', function($db) {
    $db->query("CREATE TABLE IF NOT EXISTS role_permissions_new (
        id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        role_id     INT NOT NULL,
        permission_id INT NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_role_perm (role_id, permission_id),
        CONSTRAINT fk_rp_role FOREIGN KEY (role_id)     REFERENCES roles(id)       ON DELETE CASCADE,
        CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4");

    $db->query("INSERT IGNORE INTO role_permissions_new (role_id, permission_id)
        SELECT r.id, rp.permission_id
        FROM role_permissions rp
        JOIN roles r ON r.slug = rp.role");

    $db->query("RENAME TABLE role_permissions TO role_permissions_old, role_permissions_new TO role_permissions");
    $db->query("DROP TABLE IF EXISTS role_permissions_old");
});

```

- [ ] **Step 2: Verify migration runs cleanly**

Open your browser to `http://localhost/Cafe/dashboard.php` (just load any page that includes `config.php`).

Then open phpMyAdmin → `db_coffee` → `role_permissions` and confirm:
- Table has columns: `id`, `role_id`, `permission_id`, `created_at`
- `role_id` has a FK icon linking to `roles.id`
- `permission_id` has a FK icon linking to `permissions.id`
- Data rows preserved (same count as before)

- [ ] **Step 3: Commit**

```bash
git add config.php
git commit -m "feat: migrate role_permissions to use role_id INT FK"
```

---

## Task 2: Migrate `users` table to use `role_id INT`

**Files:**
- Modify: `config.php` (add after Task 1's migration)

- [ ] **Step 1: Add the migration block**

Immediately after the `rbac_role_permissions_int_fk_v1` block you just added, insert:

```php
// ── Migrate users: replace role VARCHAR with role_id INT FK ──
_migrate($conn, 'rbac_users_role_id_v1', function($db) {
    // Add nullable column first
    $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id INT NULL");
    // Populate from slug
    $db->query("UPDATE users u JOIN roles r ON r.slug = u.role SET u.role_id = r.id WHERE u.role_id IS NULL");
    // Any user whose role slug doesn't match a role gets mapped to 'staff'
    $db->query("UPDATE users u SET u.role_id = (SELECT id FROM roles WHERE slug='staff') WHERE u.role_id IS NULL");
    // Make NOT NULL and add FK
    $db->query("ALTER TABLE users MODIFY role_id INT NOT NULL");
    $db->query("ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)");
    // Drop old column (safe — all code will be updated in subsequent tasks)
    $db->query("ALTER TABLE users DROP COLUMN IF EXISTS role");
});

```

- [ ] **Step 2: Verify migration runs**

Reload any page → phpMyAdmin → `users` table:
- `role` VARCHAR column is gone
- `role_id INT` column present with FK to `roles.id`
- All existing user rows have a valid `role_id`

- [ ] **Step 3: Commit**

```bash
git add config.php
git commit -m "feat: migrate users table to role_id INT FK"
```

---

## Task 3: Update seed INSERT statements in config.php

**Files:**
- Modify: `config.php` (all `INSERT IGNORE INTO role_permissions (role, ...)` lines)

The old pattern `INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'slug', id FROM permissions WHERE ...` must become `INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug='slug' AND p.slug IN (...)`.

- [ ] **Step 1: Update the initial seed block (around line 237)**

Find:
```php
    $conn->query("INSERT IGNORE INTO role_permissions (role,permission_id) SELECT 'manager',id FROM permissions WHERE slug IN ('dashboard','find_orders','view_orders','loyalty','products','ingredients','recipes','manage_recipes','suppliers','purchase_orders','report','announcements','attendance','promotions','reset_password')");

    // Default staff permissions
    $conn->query("INSERT IGNORE INTO role_permissions (role,permission_id) SELECT 'staff',id FROM permissions WHERE slug IN ('dashboard','find_orders','loyalty','tables')");
```

Replace with:
```php
    $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT r.id, p.id FROM roles r JOIN permissions p ON 1=1
        WHERE r.slug='manager' AND p.slug IN ('dashboard','find_orders','view_orders','loyalty','products','ingredients','recipes','manage_recipes','suppliers','purchase_orders','report','announcements','attendance','promotions','reset_password')");

    $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT r.id, p.id FROM roles r JOIN permissions p ON 1=1
        WHERE r.slug='staff' AND p.slug IN ('dashboard','find_orders','loyalty','tables')");
```

- [ ] **Step 2: Update `rbac_perm_upgrades_v1` migration block (around line 244)**

Find the entire block of INSERT statements inside `rbac_perm_upgrades_v1` that use `(role, permission_id) SELECT 'slug', id`:

```php
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='tables'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='manage_recipes'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='promotions'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista', id FROM permissions WHERE slug IN ('view_orders','recipes')");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug IN (
        'dashboard','find_orders','view_orders','tables','loyalty',
        'ingredients','recipes','manage_recipes','suppliers',
        'announcements','attendance'
    )");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'inventory_clerk', id FROM permissions WHERE slug IN ('products','ingredients','recipes','suppliers','purchase_orders')");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='reset_password'");
```

Replace with:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager' AND p.slug='tables'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager' AND p.slug='manage_recipes'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager' AND p.slug='promotions'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='barista' AND p.slug IN ('view_orders','recipes')");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1
        WHERE r.slug='supervisor' AND p.slug IN ('dashboard','find_orders','view_orders','tables','loyalty','ingredients','recipes','manage_recipes','suppliers','announcements','attendance')");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='inventory_clerk' AND p.slug IN ('products','ingredients','recipes','suppliers','purchase_orders')");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager' AND p.slug='reset_password'");
```

- [ ] **Step 3: Update remaining migration blocks** (`rbac_my_profile_v1`, `rbac_my_profile_v2`, `rbac_barista_station_recon_v1`, `rbac_customer_display_v1`)

For `rbac_my_profile_v1`, find:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'staff', id FROM permissions WHERE slug='my_profile'");
```
Replace with:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager' AND p.slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='staff' AND p.slug='my_profile'");
```

For `rbac_my_profile_v2`, find:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista', id FROM permissions WHERE slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'inventory_clerk', id FROM permissions WHERE slug='my_profile'");
```
Replace with:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='barista' AND p.slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='supervisor' AND p.slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='inventory_clerk' AND p.slug='my_profile'");
```

For `rbac_barista_station_recon_v1`, find all five INSERT lines for `barista_station` and two for `cash_reconciliation`:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'admin',    id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager',  id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor',id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'staff',    id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista',  id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'admin',   id FROM permissions WHERE slug='cash_reconciliation'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='cash_reconciliation'");
```
Replace with:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='admin'    AND p.slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager'  AND p.slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='supervisor' AND p.slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='staff'    AND p.slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='barista'  AND p.slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='admin'   AND p.slug='cash_reconciliation'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='manager' AND p.slug='cash_reconciliation'");
```

For `rbac_customer_display_v1`, find:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug='customer_display'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'staff',      id FROM permissions WHERE slug='customer_display'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista',    id FROM permissions WHERE slug='customer_display'");
```
Replace with:
```php
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='supervisor' AND p.slug='customer_display'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='staff'      AND p.slug='customer_display'");
    $db->query("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON 1=1 WHERE r.slug='barista'    AND p.slug='customer_display'");
```

For `rbac_barista_station_mgmt_fix_v1`, find:
```php
    $db->query("DELETE rp FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE p.slug = 'barista_station'
                  AND rp.role IN ('admin', 'manager', 'supervisor')");
```
Replace with:
```php
    $db->query("DELETE rp FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                JOIN roles r ON r.id = rp.role_id
                WHERE p.slug = 'barista_station'
                  AND r.slug IN ('admin', 'manager', 'supervisor')");
```

- [ ] **Step 4: Update `can()` function (around line 377)**

Find:
```php
            $role     = $_SESSION['role'] ?? 'staff';
            $is_admin = ($role === 'admin');
            if (!$is_admin) {
                $perms = [];
                $r = $conn->prepare("SELECT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role=?");
                $r->bind_param("s", $role);
```
Replace with:
```php
            $role     = $_SESSION['role'] ?? 'staff';
            $is_admin = ($role === 'admin');
            if (!$is_admin) {
                $perms = [];
                $r = $conn->prepare("SELECT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role_id=?");
                $r->bind_param("i", $_SESSION['role_id']);
```

- [ ] **Step 5: Commit**

```bash
git add config.php
git commit -m "feat: update all role_permissions inserts and can() to use role_id"
```

---

## Task 4: Update login.php and auth.php — store role_id in session

**Files:**
- Modify: `login.php` (line 25, 33-35)
- Modify: `auth.php` (line 153-164)

- [ ] **Step 1: Update login.php SELECT query**

Find in `login.php` (line 25):
```php
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username); $stmt->execute();
    $result = $stmt->get_result();
```
Replace with:
```php
    $stmt = $conn->prepare("SELECT u.*, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1");
    $stmt->bind_param("s", $username); $stmt->execute();
    $result = $stmt->get_result();
```

- [ ] **Step 2: Update login.php session assignment**

Find in `login.php` (around line 33):
```php
            $_SESSION['role']              = $user['role'];
```
Replace with:
```php
            $_SESSION['role']              = $user['role'];   // slug string — used by all existing checks
            $_SESSION['role_id']           = (int)$user['role_id'];
```

- [ ] **Step 3: Update auth.php role re-sync query**

Find in `auth.php` (line 153):
```php
$_rs = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$_rs->bind_param("i", $_SESSION['user_id']);
$_rs->execute();
$_rr = $_rs->get_result()->fetch_assoc();
if (!$_rr) {
    // Account deleted — force logout
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['role'] = $_rr['role'];
unset($_rs, $_rr);
```
Replace with:
```php
$_rs = $conn->prepare("SELECT u.role_id, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.user_id = ?");
$_rs->bind_param("i", $_SESSION['user_id']);
$_rs->execute();
$_rr = $_rs->get_result()->fetch_assoc();
if (!$_rr) {
    // Account deleted — force logout
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['role']    = $_rr['role'];
$_SESSION['role_id'] = (int)$_rr['role_id'];
unset($_rs, $_rr);
```

- [ ] **Step 4: Verify login works**

- Go to `http://localhost/Cafe/login.php`
- Log in with any account
- After landing on dashboard, check that the page loads without errors

- [ ] **Step 5: Commit**

```bash
git add login.php auth.php
git commit -m "feat: store role_id in session alongside role slug"
```

---

## Task 5: Update admin_only.php and admin_reset_password.php

**Files:**
- Modify: `admin_only.php` (line 22-27)
- Modify: `admin_reset_password.php` (lines 28-31, 80-85)

- [ ] **Step 1: Update admin_only.php role query**

Find in `admin_only.php` (line 22):
```php
$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || !in_array($user['role'], ['admin', 'manager'])) {
```
Replace with:
```php
$stmt = $conn->prepare("SELECT r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || !in_array($user['role'], ['admin', 'manager'])) {
```

- [ ] **Step 2: Update admin_reset_password.php force-reset role check**

Find in `admin_reset_password.php` (line 28):
```php
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $check = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
            $check->bind_param("i", $target);
            $check->execute();
            $tgt_role = $check->get_result()->fetch_assoc()['role'] ?? '';
```
Replace with:
```php
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $check = $conn->prepare("SELECT r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.user_id = ?");
            $check->bind_param("i", $target);
            $check->execute();
            $tgt_role = $check->get_result()->fetch_assoc()['role'] ?? '';
```

- [ ] **Step 3: Update admin_reset_password.php user list query**

Find (around line 80):
```php
$_user_where    = $_is_admin_role ? "1=1" : "role != 'admin'";
$users_result = $conn->query(
    "SELECT user_id, username, role, security_question, must_change_password
     FROM users
     WHERE {$_user_where}
     ORDER BY role DESC, username ASC"
);
```
Replace with:
```php
$_user_where    = $_is_admin_role ? "1=1" : "r.slug != 'admin'";
$users_result = $conn->query(
    "SELECT u.user_id, u.username, r.slug AS role, u.security_question, u.must_change_password
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE {$_user_where}
     ORDER BY r.slug DESC, u.username ASC"
);
```

- [ ] **Step 4: Verify admin pages load**

- Go to `http://localhost/Cafe/admin_reset_password.php` (log in as admin)
- Confirm the user list loads and shows roles correctly

- [ ] **Step 5: Commit**

```bash
git add admin_only.php admin_reset_password.php
git commit -m "feat: update admin pages to read role via JOIN on role_id"
```

---

## Task 6: Update manage_roles.php

**Files:**
- Modify: `manage_roles.php` (lines 32, 53, 63, 90, 109, 113, 130, 164-168, 173)

This file has the most changes. The approach for all write operations is: look up `role_id` from the slug once, then use it.

- [ ] **Step 1: Update create_role template copy (line 32)**

Find:
```php
            $st = $conn->prepare("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT ?, permission_id FROM role_permissions WHERE role=?");
            $st->bind_param("ss", $slug, $tpl);
```
Replace with:
```php
            $st = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT (SELECT id FROM roles WHERE slug=?), permission_id FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
            $st->bind_param("ss", $slug, $tpl);
```

- [ ] **Step 2: Update delete_role — reassign users (line 53)**

Find:
```php
            $sr = $conn->prepare("UPDATE users SET role=? WHERE role=?");
            $sr->bind_param("ss", $reassign, $slug);
```
Replace with:
```php
            $sr = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
            $sr->bind_param("ss", $reassign, $slug);
```

- [ ] **Step 3: Update delete_role — delete permissions (line 63)**

Find:
```php
        $sp = $conn->prepare("DELETE FROM role_permissions WHERE role=?");
        $sp->bind_param("s", $slug); $sp->execute();
```
Replace with:
```php
        $sp = $conn->prepare("DELETE FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
        $sp->bind_param("s", $slug); $sp->execute();
```

- [ ] **Step 4: Update role_employees AJAX query (line 90)**

Find:
```php
    $q = $conn->prepare("SELECT e.name FROM employees e JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) WHERE u.role=? ORDER BY e.name ASC LIMIT 20");
    $q->bind_param("s", $slug);
```
Replace with:
```php
    $q = $conn->prepare("SELECT e.name FROM employees e JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) JOIN roles r ON r.id = u.role_id WHERE r.slug=? ORDER BY e.name ASC LIMIT 20");
    $q->bind_param("s", $slug);
```

- [ ] **Step 5: Update save_permissions AJAX — delete and insert (lines 109-114)**

Find:
```php
    $sdp = $conn->prepare("DELETE FROM role_permissions WHERE role=?");
    $sdp->bind_param("s", $role); $sdp->execute();
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s  = $conn->prepare("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT ?, id FROM permissions WHERE id IN ($ph)");
        $s->bind_param('s' . str_repeat('i', count($ids)), $role, ...$ids);
```
Replace with:
```php
    $sdp = $conn->prepare("DELETE FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
    $sdp->bind_param("s", $role); $sdp->execute();
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s  = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT (SELECT id FROM roles WHERE slug=?), id FROM permissions WHERE id IN ($ph)");
        $s->bind_param('s' . str_repeat('i', count($ids)), $role, ...$ids);
```

- [ ] **Step 6: Update bulk_reassign (line 130)**

Find:
```php
            $su = $conn->prepare("UPDATE users SET role=? WHERE role=?");
            $su->bind_param("ss", $to, $from); $su->execute();
```
Replace with:
```php
            $su = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
            $su->bind_param("ss", $to, $from); $su->execute();
```

- [ ] **Step 7: Update data load — role_permissions query (line 164)**

Find:
```php
$res2 = $conn->query("SELECT role, permission_id FROM role_permissions");
while ($r = $res2->fetch_assoc()) {
    $role_perm_ids[$r['role']][$r['permission_id']] = true;
    if ($r['role'] !== 'admin') {
        $role_counts[$r['role']] = ($role_counts[$r['role']] ?? 0) + 1;
    }
}
```
Replace with:
```php
$res2 = $conn->query("SELECT ro.slug AS role, rp.permission_id FROM role_permissions rp JOIN roles ro ON ro.id = rp.role_id");
while ($r = $res2->fetch_assoc()) {
    $role_perm_ids[$r['role']][$r['permission_id']] = true;
    if ($r['role'] !== 'admin') {
        $role_counts[$r['role']] = ($role_counts[$r['role']] ?? 0) + 1;
    }
}
```

- [ ] **Step 8: Update emp_counts GROUP BY query (line 173)**

Find:
```php
$ec_res = $conn->query("SELECT COALESCE(u.role,'staff') AS emp_role, COUNT(*) AS cnt FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) GROUP BY emp_role");
```
Replace with:
```php
$ec_res = $conn->query("SELECT COALESCE(ro.slug,'staff') AS emp_role, COUNT(*) AS cnt FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) LEFT JOIN roles ro ON ro.id = u.role_id GROUP BY emp_role");
```

- [ ] **Step 9: Verify manage_roles page**

- Go to `http://localhost/Cafe/manage_roles.php`
- Confirm roles list loads with correct permission counts
- Try saving permissions for one role — check no errors

- [ ] **Step 10: Commit**

```bash
git add manage_roles.php
git commit -m "feat: update manage_roles.php to use role_id FK throughout"
```

---

## Task 7: Update employee_add.php and employees.php

**Files:**
- Modify: `employee_add.php` (line 82)
- Modify: `employees.php` (lines 113, 137, 173)

- [ ] **Step 1: Update employee_add.php INSERT users**

Find in `employee_add.php` (line 82):
```php
        $s2 = $conn->prepare("INSERT INTO users (username,password,role) VALUES (?,?,?)");
        $s2->bind_param("sss",$username,$hp,$role);
```
Replace with:
```php
        $s2 = $conn->prepare("INSERT INTO users (username,password,role_id) VALUES (?,?,(SELECT id FROM roles WHERE slug=?))");
        $s2->bind_param("sss",$username,$hp,$role);
```

- [ ] **Step 2: Update employees.php edit handler UPDATE users (line 113)**

Find:
```php
            $rs  = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
            $rs->bind_param("si", $new_role, $uid); $rs->execute();
```
Replace with:
```php
            $rs  = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE user_id=?");
            $rs->bind_param("si", $new_role, $uid); $rs->execute();
```

- [ ] **Step 3: Update employees.php quick_role handler UPDATE users (line 137)**

Find:
```php
                $ru  = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
                $ru->bind_param("si", $new_role, $uid); $ru->execute();
```
Replace with:
```php
                $ru  = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE user_id=?");
                $ru->bind_param("si", $new_role, $uid); $ru->execute();
```

- [ ] **Step 4: Update employees.php emp_counts GROUP BY query (line 173)**

Find:
```php
$ec_res = $conn->query("SELECT COALESCE(u.role,'staff') AS emp_role, COUNT(*) AS cnt FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) GROUP BY emp_role");
```
Replace with:
```php
$ec_res = $conn->query("SELECT COALESCE(ro.slug,'staff') AS emp_role, COUNT(*) AS cnt FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) LEFT JOIN roles ro ON ro.id = u.role_id GROUP BY emp_role");
```

- [ ] **Step 5: Verify employee management**

- Go to `http://localhost/Cafe/employees.php`
- Confirm employee list loads with correct role badges
- Try the quick-role dropdown on any employee
- Go to `http://localhost/Cafe/employee_add.php` and add a test employee

- [ ] **Step 6: Commit**

```bash
git add employee_add.php employees.php
git commit -m "feat: update employee add/edit to use role_id FK"
```

---

## Task 8: Update manage_admin.php

**Files:**
- Modify: `manage_admin.php` (lines 42-43, 53-58)

- [ ] **Step 1: Update INSERT admin user (line 42)**

Find:
```php
            $ins = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
            $ins->bind_param('ss', $username, $hashed);
```
Replace with:
```php
            $ins = $conn->prepare("INSERT INTO users (username, password, role_id) VALUES (?, ?, (SELECT id FROM roles WHERE slug='admin'))");
            $ins->bind_param('ss', $username, $hashed);
```

- [ ] **Step 2: Update SELECT admins queries (lines 53-58)**

Find:
```php
    try {
        $res = $conn->prepare("SELECT user_id, username, role, created_at FROM users WHERE role = 'admin' ORDER BY user_id ASC");
        $res->execute();
        $admins = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        $res = $conn->prepare("SELECT user_id, username, role FROM users WHERE role = 'admin' ORDER BY user_id ASC");
        $res->execute();
        $admins = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    }
```
Replace with:
```php
    try {
        $res = $conn->prepare("SELECT u.user_id, u.username, r.slug AS role, u.created_at FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' ORDER BY u.user_id ASC");
        $res->execute();
        $admins = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        $res = $conn->prepare("SELECT u.user_id, u.username, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' ORDER BY u.user_id ASC");
        $res->execute();
        $admins = $res->get_result()->fetch_all(MYSQLI_ASSOC);
    }
```

- [ ] **Step 3: Verify manage_admin page**

- Go to `http://localhost/Cafe/manage_admin.php`
- Confirm admin list loads
- Try adding a new admin account

- [ ] **Step 4: Commit**

```bash
git add manage_admin.php
git commit -m "feat: update manage_admin.php to use role_id FK"
```

---

## Task 9: Final verification

- [ ] **Step 1: Full smoke test**

Visit each page and confirm no PHP errors (white screen = error):
- `http://localhost/Cafe/login.php` — log in
- `http://localhost/Cafe/dashboard.php`
- `http://localhost/Cafe/employees.php`
- `http://localhost/Cafe/manage_roles.php`
- `http://localhost/Cafe/manage_admin.php`
- `http://localhost/Cafe/admin_reset_password.php`
- `http://localhost/Cafe/employee_add.php`

- [ ] **Step 2: Check ER diagram in phpMyAdmin**

Open phpMyAdmin → `db_coffee` → Designer tab.

Confirm the diagram now shows:
- `role_permissions.role_id` → `roles.id` (arrow)
- `role_permissions.permission_id` → `permissions.id` (arrow)
- `users.role_id` → `roles.id` (arrow)

- [ ] **Step 3: Test role permission changes**

- Log in as admin → go to `manage_roles.php`
- Remove a permission from the `manager` role → save
- Log in as a manager account → confirm that permission is gone from their nav

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: RBAC migration complete — role_id INT FK replaces role VARCHAR across all tables"
```
