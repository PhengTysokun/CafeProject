# Manage Milk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the drink-modal milk list admin-manageable via a new `manage_milk.php` page backed by a `milk_options` table, replacing the hardcoded array in menu.php and add_to_cart.php.

**Architecture:** Mirror the existing add-ons feature exactly. New `milk_options` table (id, name, display_order, is_active, is_default) bootstrapped in config.php. New `manage_milk.php` is a near-clone of `manage_addons.php` minus price, plus a single-default concept. menu.php renders milk pills from the active set; add_to_cart.php sources its milk whitelist from the same set. Milk stays a free single-select string snapshot on the order — no cart/receipt/report changes.

**Tech Stack:** PHP 7+ procedural + MySQLi, jQuery/vanilla JS front end, MySQL `db_coffee`. No unit-test framework — verification is via `mysql` CLI queries and live browser checks (admin login), matching how add-ons/categories were verified.

## Global Constraints

- DB: `db_coffee`, MySQL client at `C:/xampp/mysql/bin/mysql.exe`, user `root`, no password.
- App base URL: `http://localhost/Cafe/` (Windows is case-insensitive; `/cafe/` also works). Apache (XAMPP) is already running — do NOT restart it; the user manages XAMPP via the Control Panel.
- Migrations run automatically whenever any page requiring `config.php` is loaded. Trigger them with `curl -sk https://localhost/cafe/login.php -o /dev/null` (or open any page in a browser).
- CSRF pattern: `hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')` — copy verbatim from manage_addons.php.
- Admin gate: `require 'admin_only.php';` (admin/manager only; it pulls in config.php and `$conn`).
- Invariant: exactly one `is_default=1` among active milks at all times (unless zero active milks exist).
- Milk is stored as a plain string snapshot; the ~28 decoder/display files must NOT change. Only add_to_cart.php (validator) and menu.php (renderer) change on the consuming side.
- Admin test login for browser verification: username `Sokun`, password `@Sokun9811`.
- Commit after every task. End commit messages with:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

---

## File Structure

- **Create** `manage_milk.php` — admin CRUD page for milk options (create/update/reorder/archive/set_default + list, archived filter). Self-contained page like manage_addons.php.
- **Modify** `config.php` (after the add-ons block, ~line 168) — `CREATE TABLE milk_options` + `milk_options_seed_v1` migration.
- **Modify** `products.php` (~line 1409, after the Manage Add-ons entry button) — add a "Milk" entry button.
- **Modify** `menu.php` — load active milks (~line 187), render pills from DB (~line 1089), emit `MILK_DEFAULT` (~line 1249), use it in the modal reset (~line 1282).
- **Modify** `add_to_cart.php` (line 39) — source `$valid_milk` from `milk_options` instead of a literal.

---

### Task 1: Create `milk_options` table + seed

**Files:**
- Modify: `config.php` (insert after line 168, i.e. after the `categories_offer_addons_v1` migration block)

**Interfaces:**
- Produces: table `milk_options(id INT PK, name VARCHAR(50), display_order INT, is_active TINYINT, is_default TINYINT)` seeded with Fresh Milk (default), Almond Milk, Soy Milk, Oat Milk. All later tasks read this table.

- [ ] **Step 1: Verify the table does not yet exist (expected failure)**

Run:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SHOW TABLES LIKE 'milk_options';"
```
Expected: empty output (table absent).

- [ ] **Step 2: Add the CREATE + seed to config.php**

Insert immediately after line 168 (the closing `});` of the `categories_offer_addons_v1` migration):

```php

// ── Milk options library (admin-managed via manage_milk.php) ──
$conn->query("CREATE TABLE IF NOT EXISTS milk_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_active_order (is_active, display_order)
) DEFAULT CHARSET=utf8mb4");

// Seed the current hardcoded milk set once (only if the table is empty).
// Fresh Milk is the default — matches the prior hardcoded default in menu.php.
_migrate($conn, 'milk_options_seed_v1', function($db) {
    $n = (int)$db->query("SELECT COUNT(*) AS n FROM milk_options")->fetch_assoc()['n'];
    if ($n === 0) {
        $db->query("INSERT INTO milk_options (name, display_order, is_active, is_default) VALUES
            ('Fresh Milk', 1, 1, 1),
            ('Almond Milk', 2, 1, 0),
            ('Soy Milk', 3, 1, 0),
            ('Oat Milk', 4, 1, 0)");
    }
});
```

- [ ] **Step 3: Trigger migrations by loading a page**

Run:
```bash
curl -sk https://localhost/cafe/login.php -o /dev/null && echo loaded
```
Expected: `loaded` (any non-error page load runs config.php migrations).

- [ ] **Step 4: Verify table, rows, and single default**

Run:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT id,name,display_order,is_active,is_default FROM milk_options ORDER BY display_order; SELECT COUNT(*) AS defaults FROM milk_options WHERE is_default=1;"
```
Expected: 4 rows in order Fresh Milk / Almond Milk / Soy Milk / Oat Milk, all `is_active=1`, only Fresh Milk `is_default=1`; `defaults` = 1.

- [ ] **Step 5: Verify the seed is idempotent (re-load does not duplicate)**

Run:
```bash
curl -sk https://localhost/cafe/login.php -o /dev/null && C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT COUNT(*) AS n FROM milk_options;"
```
Expected: `n` = 4 (unchanged — the `_migrate` guard already marked `milk_options_seed_v1` applied).

- [ ] **Step 6: Commit**

```bash
git add config.php
git commit -m "feat(milk): add milk_options table + seed migration

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Create `manage_milk.php` admin page + products.php entry button

**Files:**
- Create: `manage_milk.php`
- Modify: `products.php` (insert after line 1407, the closing `</a>` of the Manage Categories button — i.e. right before the `<!-- Manage Add-ons -->` block at line 1409; either position is fine as long as it's inside the `if ($_can_manage_products)` block)

**Interfaces:**
- Consumes: `milk_options` table from Task 1.
- Produces: a working admin page reachable at `manage_milk.php` with POST actions `create`, `update`, `reorder`, `archive`, `set_default`; maintains the single-default invariant and flashes on auto-promote.

- [ ] **Step 1: Create `manage_milk.php` with the full content below**

```php
<?php
require 'admin_only.php';   // admin/manager only; pulls in config.php ($conn)

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$flash = null;

// ── POST action router (CSRF-guarded) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please retry.'];
    } else {
        switch ($_POST['action'] ?? '') {
            case 'create': {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') { $flash = ['type'=>'error','msg'=>'Milk name is required.']; break; }
                $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 AS n FROM milk_options")->fetch_assoc()['n'];
                // If no active default currently exists (e.g. all were archived), the new milk becomes default.
                $hasDefault = (int)$conn->query("SELECT COUNT(*) c FROM milk_options WHERE is_default=1 AND is_active=1")->fetch_assoc()['c'];
                $isDef = $hasDefault === 0 ? 1 : 0;
                $ins = $conn->prepare("INSERT INTO milk_options (name, display_order, is_active, is_default) VALUES (?, ?, 1, ?)");
                $ins->bind_param('sii', $name, $ord, $isDef);
                $ins->execute();
                $flash = ['type'=>'success','msg'=>"Milk \"$name\" added."];
                break;
            }
            case 'update': {
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                if ($id <= 0 || $name === '') { $flash = ['type'=>'error','msg'=>'Name is required.']; break; }
                $u = $conn->prepare("UPDATE milk_options SET name=? WHERE id=?");
                $u->bind_param('si', $name, $id);
                $u->execute();
                $flash = ['type'=>'success','msg'=>'Milk updated.'];
                break;
            }
            case 'archive': {  // toggles is_active both ways; auto-promote default on archive
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $row = $conn->query("SELECT name, is_active, is_default FROM milk_options WHERE id = " . $id)->fetch_assoc();
                    if ($row) {
                        $conn->query("UPDATE milk_options SET is_active = 1 - is_active WHERE id = " . $id);
                        // We just archived the current default → promote the first remaining active milk.
                        if ((int)$row['is_active'] === 1 && (int)$row['is_default'] === 1) {
                            $conn->query("UPDATE milk_options SET is_default = 0 WHERE id = " . $id);
                            $next = $conn->query("SELECT id, name FROM milk_options WHERE is_active = 1 ORDER BY display_order ASC, id ASC LIMIT 1")->fetch_assoc();
                            if ($next) {
                                $conn->query("UPDATE milk_options SET is_default = 1 WHERE id = " . (int)$next['id']);
                                $flash = ['type'=>'success','msg'=>$row['name']." archived — ".$next['name']." is now the default."];
                            } else {
                                $flash = ['type'=>'success','msg'=>$row['name']." archived. No active milks remain."];
                            }
                        } else {
                            $flash = ['type'=>'success','msg'=>'Milk visibility updated.'];
                        }
                    }
                }
                break;
            }
            case 'set_default': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $chk = $conn->query("SELECT is_active FROM milk_options WHERE id = " . $id)->fetch_assoc();
                    if ($chk && (int)$chk['is_active'] === 1) {
                        $conn->query("UPDATE milk_options SET is_default = 0");
                        $conn->query("UPDATE milk_options SET is_default = 1 WHERE id = " . $id);
                        $flash = ['type'=>'success','msg'=>'Default milk updated.'];
                    } else {
                        $flash = ['type'=>'error','msg'=>'Only an active milk can be the default.'];
                    }
                }
                break;
            }
            case 'reorder': {
                $id  = (int)($_POST['id'] ?? 0);
                $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                if ($id > 0) {
                    $cur = $conn->query("SELECT id, display_order FROM milk_options WHERE id = " . $id)->fetch_assoc();
                    if ($cur) {
                        $cmp = $dir === 'up' ? '<' : '>';
                        $ord = $dir === 'up' ? 'DESC' : 'ASC';
                        $nb = $conn->query("SELECT id, display_order FROM milk_options WHERE display_order $cmp " . (int)$cur['display_order'] . " ORDER BY display_order $ord LIMIT 1")->fetch_assoc();
                        if ($nb) {
                            $a=(int)$cur['display_order']; $b=(int)$nb['display_order'];
                            $ca=(int)$cur['id']; $cb=(int)$nb['id'];
                            $conn->query("UPDATE milk_options SET display_order = $b WHERE id = $ca");
                            $conn->query("UPDATE milk_options SET display_order = $a WHERE id = $cb");
                            $flash = ['type'=>'success','msg'=>'Order updated.'];
                        }
                    }
                }
                break;
            }
        }
    }
}

$showArchived = isset($_GET['archived']);
$where = $showArchived ? '' : 'WHERE is_active = 1';
$milks = [];
$res = $conn->query("SELECT id, name, is_active, is_default, display_order FROM milk_options $where ORDER BY display_order ASC, id ASC");
while ($row = $res->fetch_assoc()) $milks[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Milk | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:48px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.brand-icon  { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text  { display:flex; flex-direction:column; line-height:1.2; }
.brand-title { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub   { font-size:10px; color:var(--text-muted); }
.topbar-sep  { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-right { display:flex; align-items:center; gap:6px; margin-left:auto; flex-wrap:wrap; }

.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.icon-only { padding:7px 10px; }

.wrap { max-width:920px; margin:18px auto 60px; padding:0 20px; }
.flash { padding:11px 15px; border-radius:10px; font-size:13px; margin-bottom:14px; }
.flash.success { background:rgba(85,224,135,.12); color:var(--ok); border:1px solid rgba(85,224,135,.3); }
.flash.error   { background:rgba(255,95,95,.12);  color:var(--danger); border:1px solid rgba(255,95,95,.3); }
.cat-table { width:100%; border-collapse:collapse; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.cat-table th { padding:10px 12px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); border-bottom:1px solid var(--border); }
.cat-table td { padding:11px 12px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
.cat-table tr:last-child td { border-bottom:none; }
.cat-row.inactive { opacity:.55; background:rgba(255,255,255,.02); }
.slug-muted { color:var(--text-muted); font-size:12px; }
.pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:50px; font-size:10px; font-weight:700; }
.pill-inactive { background:rgba(255,95,95,.12); color:var(--danger); }
.pill-default  { background:rgba(85,224,135,.12); color:var(--ok); }
.icon-btn { background:transparent; border:1px solid var(--border); color:var(--text-muted); border-radius:7px; width:30px; height:30px; cursor:pointer; transition:var(--transition); }
.icon-btn:hover:not(:disabled) { border-color:var(--accent); color:var(--accent); }
.icon-btn:disabled { opacity:.3; cursor:not-allowed; }
.act-link { color:var(--accent); text-decoration:none; font-size:12px; font-weight:600; margin-right:10px; cursor:pointer; background:none; border:none; font-family:inherit; }
.danger-link { color:var(--danger); }
</style>
</head>
<body>
<div class="topbar">
    <a href="products.php" class="btn-nav icon-only" title="Back to Products"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="brand-icon"><i class="fa-solid fa-bottle-water"></i></div>
    <div class="brand-text">
        <span class="brand-title">Manage Milk</span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; Catalog</span>
    </div>
    <div class="topbar-right">
        <a href="manage_categories.php" class="btn-nav"><i class="fa-solid fa-tags"></i> Categories</a>
        <a href="manage_addons.php" class="btn-nav"><i class="fa-solid fa-plus-circle"></i> Add-ons</a>
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<div class="wrap">
    <?php if ($flash): ?>
    <div class="flash <?= he($flash['type']) ?>"><?= he($flash['msg']) ?></div>
    <?php endif; ?>

    <form method="POST" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Milk name</label>
            <input type="text" name="name" required placeholder="e.g. Coconut Milk" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;font-family:'Poppins',sans-serif;">
        </div>
        <button type="submit" class="btn-nav" style="border-color:var(--accent);color:var(--accent);padding-bottom:8px;"><i class="fa-solid fa-plus"></i> Add Milk</button>
        <a href="?<?= $showArchived ? '' : 'archived=1' ?>" class="btn-nav" style="padding-bottom:8px;margin-left:auto;"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a>
    </form>

    <table class="cat-table">
        <thead>
            <tr>
                <th style="width:70px">Order</th>
                <th>Name</th>
                <th style="width:130px">Default</th>
                <th style="width:80px">Active</th>
                <th style="width:150px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($milks as $i => $m): ?>
            <tr class="cat-row <?= $m['is_active'] ? '' : 'inactive' ?>">
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="icon-btn" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="dir" value="down">
                        <button type="submit" class="icon-btn" title="Move down" <?= $i === count($milks) - 1 ? 'disabled' : '' ?>>&darr;</button>
                    </form>
                </td>
                <td><?= he($m['name']) ?><?php if (!$m['is_active']): ?> <span class="pill pill-inactive">Inactive</span><?php endif; ?></td>
                <td>
                    <?php if ($m['is_default']): ?>
                        <span class="pill pill-default"><i class="fa-solid fa-star"></i> Default</span>
                    <?php elseif ($m['is_active']): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="set_default">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="act-link">Set default</button>
                        </form>
                    <?php else: ?>
                        <span class="slug-muted">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td><?= $m['is_active'] ? 'Yes' : 'No' ?></td>
                <td>
                    <button type="button" class="act-link" onclick="toggleEdit(<?= (int)$m['id'] ?>)">Edit</button>
                    <form method="POST" style="display:inline;" <?= $m['is_active'] ? "onsubmit=\"return confirm('Archive this milk? It disappears from every product modal until restored.');\"" : '' ?>>
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="archive">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="act-link <?= $m['is_active'] ? 'danger-link' : '' ?>"><?= $m['is_active'] ? 'Archive' : 'Restore' ?></button>
                    </form>
                </td>
            </tr>
            <tr id="edit-<?= (int)$m['id'] ?>" style="display:none;">
                <td colspan="5" style="background:rgba(255,255,255,.02);">
                    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <label class="slug-muted">Name</label>
                            <input type="text" name="name" value="<?= he($m['name']) ?>" required style="padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);">
                        </div>
                        <button type="submit" class="btn-nav" style="border-color:var(--accent);color:var(--accent);padding-bottom:6px;">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($milks)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px;">No milk options<?= $showArchived ? '' : ' — add one above or show archived' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function toggleEdit(id) {
    const row = document.getElementById('edit-' + id);
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
function toggleTheme() {
    const html = document.documentElement, icon = document.getElementById('themeIcon');
    if (html.getAttribute('data-theme') === 'light') { html.removeAttribute('data-theme'); icon.className = 'fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else { html.setAttribute('data-theme','light'); icon.className = 'fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light') document.getElementById('themeIcon').className = 'fa-solid fa-sun';
});
</script>
</body>
</html>
```

- [ ] **Step 2: Add the entry button to products.php**

Insert after line 1407 (the `</a>` closing the Manage Categories button), before the `<!-- Manage Add-ons -->` comment:

```php
        <!-- Manage Milk -->
        <a href="manage_milk.php" class="btn-add" style="background:transparent;border:1px solid var(--border,#2a2a2a);color:var(--text,#f5f5f5);">
            <i class="fa-solid fa-bottle-water"></i>
            <span class="hide-sm">Milk</span>
        </a>

```

- [ ] **Step 3: Verify the page loads and CRUD works (browser, admin Sokun)**

Log in at `https://localhost/cafe/login.php` as `Sokun` / `@Sokun9811`, then open `https://localhost/cafe/manage_milk.php`. Verify:
- The 4 seeded milks list in order; Fresh Milk shows the green "Default" pill; the others show a "Set default" button.
- Add "Coconut Milk" via the top form → appears at the bottom, Active=Yes, not default.
- Click "Set default" on Almond Milk → Almond gets the Default pill, Fresh Milk gets a "Set default" button (exactly one default).
- Reorder Coconut up once → its row moves up.
- Edit Coconut → rename to "Coconut" → name updates.
- Archive Almond Milk (the current default) → confirm dialog, then flash reads `Almond Milk archived — <next active milk> is now the default.`; the promoted milk shows the Default pill. Confirm exactly one default remains.
- "Show archived" reveals the archived Almond row with a Restore button; "Hide archived" hides it again.

- [ ] **Step 4: Verify the single-default invariant in the DB after those actions**

Run:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT COUNT(*) AS active_defaults FROM milk_options WHERE is_default=1 AND is_active=1;"
```
Expected: `active_defaults` = 1.

- [ ] **Step 5: Reset the test data back to the seed state**

Run (removes the Coconut test row and restores Fresh Milk as the sole default):
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM milk_options WHERE name IN ('Coconut','Coconut Milk'); UPDATE milk_options SET is_active=1; UPDATE milk_options SET is_default = (name='Fresh Milk');"
```
Then verify:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT name,is_active,is_default FROM milk_options ORDER BY display_order;"
```
Expected: the original 4 rows, all active, only Fresh Milk default.

- [ ] **Step 6: Commit**

```bash
git add manage_milk.php products.php
git commit -m "feat(milk): manage_milk.php admin page + products entry button

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Render milk pills from the DB in menu.php

**Files:**
- Modify: `menu.php` — load block (after line 187), pill markup (lines 1089-1096), JS emit (after line 1249), modal reset (line 1282)

**Interfaces:**
- Consumes: `milk_options` table (Task 1).
- Produces: `$milkOptions` (PHP array of active milk names) and `$defaultMilk` (string) used by the modal; JS global `MILK_DEFAULT`.

- [ ] **Step 1: Load active milks after the category-options block**

Insert immediately after line 187 (the closing `}` of the `$categoryOpts` while-loop) and before the `?>` on line 189:

```php

/* ── MILK OPTIONS (admin-managed via manage_milk.php) ── */
$milkOptions = [];
$defaultMilk = '';
$mk_res = $conn->query("SELECT name, is_default FROM milk_options WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
if ($mk_res) {
    while ($mk = $mk_res->fetch_assoc()) {
        $milkOptions[] = $mk['name'];
        if ((int)$mk['is_default'] === 1 && $defaultMilk === '') $defaultMilk = $mk['name'];
    }
}
if ($defaultMilk === '' && !empty($milkOptions)) $defaultMilk = $milkOptions[0];
```

- [ ] **Step 2: Replace the hardcoded milk pill markup**

Replace lines 1089-1096 (the entire `<div id="optMilk" ...>` block, currently using the literal array) with:

```php
      <?php if (!empty($milkOptions)): ?>
      <div id="optMilk" class="option-section">
        <div class="option-label">Milk</div>
        <div class="pill-group" id="milkPills">
          <?php foreach ($milkOptions as $mk): ?>
          <button class="option-pill <?= $mk === $defaultMilk ? 'active' : '' ?>" data-group="milk" data-value="<?= htmlspecialchars($mk, ENT_QUOTES) ?>" onclick="selectPill(this)"><?= htmlspecialchars($mk, ENT_QUOTES) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
```

- [ ] **Step 3: Emit the default to JS**

Insert immediately after line 1249 (`var CATEGORY_OPTS = ...;`):

```php
var MILK_DEFAULT = <?= json_encode($defaultMilk, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
```

- [ ] **Step 4: Use MILK_DEFAULT in the modal reset**

Replace line 1282:

```javascript
  document.querySelectorAll('#milkPills .option-pill').forEach(function(pill)     { pill.classList.toggle('active', pill.dataset.value === 'Fresh Milk'); });
```

with:

```javascript
  document.querySelectorAll('#milkPills .option-pill').forEach(function(pill)     { pill.classList.toggle('active', pill.dataset.value === MILK_DEFAULT); });
```

- [ ] **Step 5: Verify the modal renders milk from the DB (browser)**

Open `https://localhost/cafe/menu.php` (logged in). Open a milk-eligible product (a category with `offer_milk=1`, e.g. a milk tea). Verify:
- The Milk section lists the 4 seeded milks in display_order.
- Fresh Milk is pre-selected (has the `active` highlight).
- Open a second product and confirm Fresh Milk is again pre-selected (the reset uses MILK_DEFAULT).
- Open a `offer_milk=0` product (a Juice) → no Milk section (per-category gate still works).

- [ ] **Step 6: Verify dynamic add + default change flows through to the menu**

In the DB, add a temporary milk and make it default, then reload the menu:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "INSERT INTO milk_options (name,display_order,is_active,is_default) VALUES ('Coconut Milk',5,1,0); UPDATE milk_options SET is_default=(name='Coconut Milk');"
```
Reload the product modal → "Coconut Milk" appears as a pill AND is the pre-selected default. Then restore the seed state:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM milk_options WHERE name='Coconut Milk'; UPDATE milk_options SET is_default=(name='Fresh Milk');"
```

- [ ] **Step 7: Commit**

```bash
git add menu.php
git commit -m "feat(milk): render menu milk pills from milk_options table

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Source add_to_cart.php milk whitelist from the DB

**Files:**
- Modify: `add_to_cart.php:39`

**Interfaces:**
- Consumes: `milk_options` table (Task 1); `$conn` (already required at add_to_cart.php:3).
- Produces: no new interface; closes the desync where a newly-added milk would be 400-rejected.

- [ ] **Step 1: Confirm the current hardcoded whitelist rejects a non-seed milk (expected failure)**

Temporarily add a milk not in the hardcoded list:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "INSERT INTO milk_options (name,display_order,is_active,is_default) VALUES ('Coconut Milk',5,1,0);"
```
In the browser (logged in), open a milk-eligible product, pick "Coconut Milk", set quantity 1, and add to cart.
Expected: it FAILS with "Invalid milk option" (the hardcoded array at line 39 doesn't include Coconut Milk).

- [ ] **Step 2: Replace the hardcoded `$valid_milk` with a DB query**

Replace line 39:

```php
$valid_milk      = ['Fresh Milk', 'Almond Milk', 'Soy Milk', 'Oat Milk', ''];
```

with:

```php
// Milk whitelist is admin-managed (milk_options); build it from the active set + '' (no milk).
$valid_milk = [''];
$mk_wl = $conn->query("SELECT name FROM milk_options WHERE is_active = 1");
if ($mk_wl) { while ($mw = $mk_wl->fetch_assoc()) $valid_milk[] = $mw['name']; }
```

Leave lines 37-38 (`$valid_sweetness`, `$valid_ice`) and the reject block at lines 47-48 unchanged.

- [ ] **Step 3: Verify the newly-added milk is now accepted**

In the browser, repeat Step 1's action (open a milk-eligible product, pick "Coconut Milk", add to cart).
Expected: it SUCCEEDS — the item is added with Milk: Coconut Milk (no "Invalid milk option").

- [ ] **Step 4: Verify an archived / unknown milk is still rejected (400)**

Archive Coconut Milk, then attempt to add it via a crafted request:
```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "UPDATE milk_options SET is_active=0 WHERE name='Coconut Milk';"
```
Then post directly (replace `<PID>` with a real milk-eligible product_id and `<TOKEN>`/`<COOKIE>` with a logged-in session's CSRF token and PHPSESSID cookie — capture them from the browser dev tools):
```bash
curl -sk https://localhost/cafe/add_to_cart.php -b "PHPSESSID=<COOKIE>" \
  --data-urlencode "csrf_token=<TOKEN>" --data-urlencode "id=<PID>" \
  --data-urlencode "qty=1" --data-urlencode "milk=Coconut Milk"
```
Expected: JSON with success=false and message "Invalid milk option" (HTTP 400) — an archived milk is not in the active whitelist.

Note: if capturing a live token is impractical, this case is equivalently covered by Step 1 (an inactive/unknown milk name is treated identically to Coconut Milk before it was seeded active). Record which check was used.

- [ ] **Step 5: Restore the seed state**

```bash
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "DELETE FROM milk_options WHERE name='Coconut Milk'; UPDATE milk_options SET is_active=1; UPDATE milk_options SET is_default=(name='Fresh Milk');"
C:/xampp/mysql/bin/mysql.exe -u root db_coffee -e "SELECT name,is_active,is_default FROM milk_options ORDER BY display_order;"
```
Expected: the original 4 rows, all active, only Fresh Milk default.

- [ ] **Step 6: Commit**

```bash
git add add_to_cart.php
git commit -m "fix(milk): source add_to_cart milk whitelist from milk_options (was hardcoded)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification (after all tasks)

- [ ] End-to-end (browser, admin Sokun): add a new milk in manage_milk.php → it appears in the menu modal → an order with it adds to cart and reaches the barista ticket with the milk name shown. Then archive it and confirm it disappears from the modal but the DB seed state can be restored.
- [ ] `git log --oneline -5` shows the four feature commits.
- [ ] Confirm no changes leaked into the ~28 milk-decoder files: `git diff --name-only main...HEAD` (or `git show --stat` per commit) lists only config.php, manage_milk.php, products.php, menu.php, add_to_cart.php, and the plan/spec docs.

## Notes for the implementer

- This branch (`feat/product-addons`) is **local only** — do NOT push or merge without explicit instruction (it sits atop ~39 unrelated unmerged commits).
- Do NOT restart Apache/MySQL from the CLI — the user restarts XAMPP via the Control Panel.
- The "default radio" from the spec is implemented as a per-row **"Set default" action button** plus a "Default" pill, not an HTML `<input type=radio>`. Reason: this page uses one `<form>` per row-action, and radio inputs in separate forms can't form a single group. The button+pill gives the same single-select UX and matches the page's existing form-per-action pattern.
