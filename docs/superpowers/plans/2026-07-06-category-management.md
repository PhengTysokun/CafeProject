# Category Management (Catalog Settings — Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a `manage_categories.php` admin page giving admin/manager full CRUD + reorder + active-toggle over the existing `categories` table, and switch `products.php`'s category filter to read that table so new/empty categories appear.

**Architecture:** One new self-contained PHP page, `manage_categories.php`, following the established single-file pattern (POST action-router at the top that mutates the `categories` table then falls through to re-render the HTML list below). It reuses the `admin_only.php` gate, the `stands.php` CSRF pattern, and the `ingredients.php` topbar/table/theme styling. A second, smaller change points `products.php`'s filter chips at the `categories` table. No schema change — the `categories` table already has every needed column.

**Tech Stack:** PHP 8 + MySQLi (procedural + prepared statements), vanilla CSS (design-token custom properties already in the codebase), Font Awesome 6. No frontend test framework exists in this repo; verification is `php -l` (syntax) + targeted `php -r` DB assertions + manual browser checks (the same approach used by `docs/superpowers/plans/2026-06-27-attendance-history.md`).

## Global Constraints

- New page path: `manage_categories.php` (repo root, alongside `products.php`).
- Access gate: `require 'admin_only.php';` — admin/manager only (redirects others to `dashboard.php?denied=1`). Do NOT use `can('products')`.
- `categories` columns: `category_id` (PK, auto-inc), `slug` varchar(50), `name` varchar(100), `icon` varchar(50) default `fa-circle`, `display_order` int default 0, `is_active` tinyint(1) default 1.
- **slug is immutable after creation.** It is derived from Name by trim + collapse-internal-whitespace ONLY — never lowercased, never hyphenated (existing slugs are Title Case with spaces: `Iced`, `Hot`, `Milk Tea`, and the slug is shown to users as the product category badge).
- Duplicate-slug check is case-insensitive (`LOWER(slug) = LOWER(?)`).
- Delete is blocked when any product references the category (`products.category_id = ?`).
- All mutations are POST + CSRF-guarded: `if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));` and every handler checks `hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')`.
- All dynamic output escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Styling: dark default + `[data-theme="light"]` override, matching `ingredients.php`.
- All prepared statements use `bind_param`; no string interpolation of user input into SQL.

---

### Task 1: `manage_categories.php` — gate, scaffold, read-only list

**Files:**
- Create: `manage_categories.php`

**Interfaces:**
- Consumes: `admin_only.php` (gate), `config.php` (`$conn` MySQLi handle — pulled in transitively by `admin_only.php`, which `require_once 'config.php'`).
- Produces (later tasks depend on these):
  - PHP helper `cat_slug(string $name): string` — returns `preg_replace('/\s+/', ' ', trim($name))`.
  - A `$categories` array: each row `['category_id','slug','name','icon','display_order','is_active','product_count']`, ordered by `display_order ASC, category_id ASC`.
  - `$flash` = `['type' => 'success'|'error', 'msg' => string]` or `null`, rendered as a flash banner near the top of the list.
  - A POST action-router `switch ($_POST['action'] ?? '')` (empty in this task; later tasks add cases) guarded by the CSRF check.
  - HTML: a `.topbar` (back arrow → `products.php`, title "Manage Categories", theme toggle) and a `<table>` with columns Reorder / Icon / Name / Slug / Products / Active / Actions.

- [ ] **Step 1: Create the page skeleton (gate + CSRF + query + empty router)**

Create `manage_categories.php`:

```php
<?php
require 'admin_only.php';   // admin/manager only; pulls in config.php ($conn)

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function cat_slug(string $name): string { return preg_replace('/\s+/', ' ', trim($name)); }
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$flash = null;

// ── POST action router (CSRF-guarded). Cases added in later tasks. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please retry.'];
    } else {
        switch ($_POST['action'] ?? '') {
            // create / update / toggle / delete / reorder added in later tasks
        }
    }
}

// ── Load categories with product counts ──
$categories = [];
$res = $conn->query("
    SELECT c.category_id, c.slug, c.name, c.icon, c.display_order, c.is_active,
           (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id) AS product_count
    FROM categories c
    ORDER BY c.display_order ASC, c.category_id ASC
");
while ($row = $res->fetch_assoc()) $categories[] = $row;
?>
```

- [ ] **Step 2: Add the `<head>` + styles**

Append the HTML head. Copy the design-token `:root` / `[data-theme="light"]` blocks, `body`, `.topbar`, `.btn-nav`, and table styling verbatim from `ingredient_report.php` (lines ~82-246 of that file) so the page matches. Then add these page-specific rules inside the same `<style>`:

```css
.wrap { max-width:920px; margin:18px auto 60px; padding:0 20px; }
.flash { padding:11px 15px; border-radius:10px; font-size:13px; margin-bottom:14px; }
.flash.success { background:rgba(85,224,135,.12); color:var(--ok); border:1px solid rgba(85,224,135,.3); }
.flash.error   { background:rgba(255,95,95,.12);  color:var(--danger); border:1px solid rgba(255,95,95,.3); }
.cat-table { width:100%; border-collapse:collapse; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.cat-table th { padding:10px 12px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); border-bottom:1px solid var(--border); }
.cat-table td { padding:11px 12px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
.cat-table tr:last-child td { border-bottom:none; }
.cat-row.inactive { opacity:.55; background:rgba(255,255,255,.02); }
.cat-icon { width:30px; height:30px; border-radius:8px; background:rgba(209,144,75,.12); color:var(--accent); display:inline-flex; align-items:center; justify-content:center; }
.slug-muted { color:var(--text-muted); font-size:12px; }
.pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:50px; font-size:10px; font-weight:700; }
.pill-inactive { background:rgba(255,95,95,.12); color:var(--danger); }
.icon-btn { background:transparent; border:1px solid var(--border); color:var(--text-muted); border-radius:7px; width:30px; height:30px; cursor:pointer; transition:var(--transition); }
.icon-btn:hover:not(:disabled) { border-color:var(--accent); color:var(--accent); }
.icon-btn:disabled { opacity:.3; cursor:not-allowed; }
.act-link { color:var(--accent); text-decoration:none; font-size:12px; font-weight:600; margin-right:10px; cursor:pointer; background:none; border:none; font-family:inherit; }
.danger-link { color:var(--danger); }
.danger-link:disabled { opacity:.3; cursor:not-allowed; }
```

- [ ] **Step 3: Add the `<body>` — topbar, flash, list table**

```php
<body>
<div class="topbar">
    <a href="products.php" class="btn-nav icon-only" title="Back to Products"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="brand-icon"><i class="fa-solid fa-tags"></i></div>
    <div class="brand-text">
        <span class="brand-title">Manage Categories</span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; Catalog</span>
    </div>
    <div class="topbar-right">
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<div class="wrap">
    <?php if ($flash): ?>
    <div class="flash <?= he($flash['type']) ?>"><?= he($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Add form placeholder — filled in Task 2 -->

    <table class="cat-table">
        <thead>
            <tr>
                <th style="width:70px">Order</th>
                <th style="width:44px"></th>
                <th>Name</th>
                <th>Slug</th>
                <th style="width:90px">Products</th>
                <th style="width:80px">Active</th>
                <th style="width:150px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $i => $c): ?>
            <tr class="cat-row <?= $c['is_active'] ? '' : 'inactive' ?>">
                <td>
                    <button class="icon-btn" title="Move up"   <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                    <button class="icon-btn" title="Move down" <?= $i === count($categories) - 1 ? 'disabled' : '' ?>>&darr;</button>
                </td>
                <td><span class="cat-icon"><i class="fa-solid <?= he($c['icon'] ?: 'fa-circle') ?>"></i></span></td>
                <td><?= he($c['name']) ?><?php if (!$c['is_active']): ?> <span class="pill pill-inactive">Inactive</span><?php endif; ?></td>
                <td class="slug-muted"><?= he($c['slug']) ?></td>
                <td><?= (int)$c['product_count'] ?></td>
                <td><?= $c['is_active'] ? 'Yes' : 'No' ?></td>
                <td><!-- edit/delete/toggle controls added in Tasks 3-5 --></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
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

Also add the standard `<head>`/theme-bootstrap markup used by `ingredient_report.php` (the `<!DOCTYPE html>`, `<meta>`, the inline `localStorage.getItem('theme')` bootstrap script, Poppins + Font Awesome `<link>`s, and the opening `<style>` from Step 2) above `<body>`.

- [ ] **Step 4: Syntax check**

Run: `php -l manage_categories.php`
Expected: `No syntax errors detected in manage_categories.php`

- [ ] **Step 5: Verify the list query returns the seeded categories with counts**

Run:
```bash
php -r 'require "config.php"; $r=$conn->query("SELECT c.name,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.category_id) pc FROM categories c ORDER BY c.display_order"); while($x=$r->fetch_assoc()) echo $x["name"]." => ".$x["pc"]."\n";'
```
Expected: five lines, e.g. `Iced Beverages => 9` (non-zero counts for the seeded categories).

- [ ] **Step 6: Browser check (gate + render)**

Log in as admin (`Sokun`), open `manage_categories.php`. Expected: topbar + a table listing the 5 categories with icons, slugs, product counts, all "Active: Yes". Log in as cashier (`Sok_Dara`): expect redirect to `dashboard.php?denied=1`.

- [ ] **Step 7: Commit**

```bash
git add manage_categories.php
git commit -m "feat(categories): add manage_categories page gate + read-only list"
```

---

### Task 2: Create category (Add form + `action=create` + slug preview)

**Files:**
- Modify: `manage_categories.php`

**Interfaces:**
- Consumes: `cat_slug()`, the router `switch`, `$flash`, `$conn` from Task 1.
- Produces: `case 'create'` in the router; an Add form above the table posting `action=create`, `name`, `icon`, `is_active`; a live JS slug preview.

- [ ] **Step 1: Add the `create` case to the router**

Inside `switch ($_POST['action'] ?? '')`:

```php
            case 'create': {
                $name = trim((string)($_POST['name'] ?? ''));
                $icon = trim((string)($_POST['icon'] ?? '')) ?: 'fa-circle';
                $active = isset($_POST['is_active']) ? 1 : 0;
                $slug = cat_slug($name);
                if ($slug === '') { $flash = ['type'=>'error','msg'=>'Category name is required.']; break; }
                $dup = $conn->prepare("SELECT category_id FROM categories WHERE LOWER(slug) = LOWER(?) LIMIT 1");
                $dup->bind_param('s', $slug); $dup->execute();
                if ($dup->get_result()->fetch_assoc()) { $flash = ['type'=>'error','msg'=>"A category named \"$slug\" already exists."]; break; }
                $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 AS n FROM categories")->fetch_assoc()['n'];
                $ins = $conn->prepare("INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES (?, ?, ?, ?, ?)");
                $ins->bind_param('sssii', $slug, $name, $icon, $ord, $active);
                $ins->execute();
                $flash = ['type'=>'success','msg'=>"Category \"$slug\" added."];
                break;
            }
```

Note: `$name` is stored as-is in `name`; `$slug` (the trimmed/whitespace-collapsed form) goes to `slug`. For a single-word name they are identical; the split matters only when the admin later renames the display `name` (Task 3), which leaves `slug` untouched.

- [ ] **Step 2: Add the Add form markup** (replace the `<!-- Add form placeholder -->` comment)

```php
    <form method="POST" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Name</label>
            <input type="text" name="name" id="catName" required placeholder="e.g. Smoothies" oninput="updateSlugPreview()" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;font-family:'Poppins',sans-serif;">
            <span class="slug-muted" id="slugPreview">Slug will be: —</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;">
            <label style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Icon</label>
            <input type="text" name="icon" value="fa-tag" style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);font-size:13px;font-family:'Poppins',sans-serif;width:150px;">
            <span class="slug-muted">e.g. fa-mug-hot, fa-leaf, fa-blender</span>
        </div>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);padding-bottom:8px;">
            <input type="checkbox" name="is_active" checked> Active
        </label>
        <button type="submit" class="btn-nav" style="border-color:var(--accent);color:var(--accent);padding-bottom:8px;"><i class="fa-solid fa-plus"></i> Add Category</button>
    </form>
```

- [ ] **Step 3: Add the slug-preview JS** (inside the existing `<script>`)

```javascript
function updateSlugPreview() {
    const v = document.getElementById('catName').value.trim().replace(/\s+/g, ' ');
    document.getElementById('slugPreview').textContent = 'Slug will be: ' + (v || '—');
}
```

- [ ] **Step 4: Syntax check**

Run: `php -l manage_categories.php`
Expected: `No syntax errors detected in manage_categories.php`

- [ ] **Step 5: Browser check — add a category**

As admin, add name `Smoothies`, icon `fa-blender`, Active checked → submit. Expected: success flash "Category "Smoothies" added.", new row appears at the bottom (highest order) with count 0. Re-submit `Smoothies` again → error flash "already exists." Submit with empty name → error "name is required."

- [ ] **Step 6: Verify propagation to pickers**

Run:
```bash
php -r 'require "config.php"; $r=$conn->query("SELECT slug FROM categories WHERE is_active=1 ORDER BY display_order"); foreach($r as $x) echo $x["slug"]."\n";' 
```
Expected: the list ends with `Smoothies`. Then in the browser open `add_product.php` → the category dropdown includes `Smoothies`; open `menu.php` → category nav includes it.

- [ ] **Step 7: Commit**

```bash
git add manage_categories.php
git commit -m "feat(categories): add create with slug derivation, dup guard, preview"
```

---

### Task 3: Edit + toggle active (`action=update`, `action=toggle`)

**Files:**
- Modify: `manage_categories.php`

**Interfaces:**
- Consumes: router, `$flash`, `$conn`, `$categories`, `he()`.
- Produces: `case 'update'` and `case 'toggle'`; per-row Edit control (a small inline edit form or a modal) and an Active toggle form; the row's Actions cell now renders Edit.

- [ ] **Step 1: Add `update` and `toggle` cases to the router**

```php
            case 'update': {
                $id   = (int)($_POST['category_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $icon = trim((string)($_POST['icon'] ?? '')) ?: 'fa-circle';
                $active = isset($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || $name === '') { $flash = ['type'=>'error','msg'=>'Name is required.']; break; }
                $u = $conn->prepare("UPDATE categories SET name=?, icon=?, is_active=? WHERE category_id=?");
                $u->bind_param('ssii', $name, $icon, $active, $id);
                $u->execute();
                $flash = ['type'=>'success','msg'=>'Category updated.'];
                break;
            }
            case 'toggle': {
                $id = (int)($_POST['category_id'] ?? 0);
                if ($id > 0) {
                    $conn->query("UPDATE categories SET is_active = 1 - is_active WHERE category_id = " . $id);
                    $flash = ['type'=>'success','msg'=>'Category visibility updated.'];
                }
                break;
            }
```

Note: `$id` is cast to `int` before interpolation in the `toggle` query, so no injection is possible; `update` uses a bound statement. The slug is intentionally NOT in the UPDATE — it stays immutable.

- [ ] **Step 2: Render the Active toggle + Edit control in each row**

Replace the Active cell (`<td><?= $c['is_active'] ? 'Yes' : 'No' ?></td>`) with a toggle form:

```php
                <td>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <button type="submit" class="act-link"><?= $c['is_active'] ? 'On' : 'Off' ?></button>
                    </form>
                </td>
```

Replace the Actions cell with an Edit button that reveals an inline edit form (one hidden `<tr>` per category directly after its row):

```php
                <td>
                    <button type="button" class="act-link" onclick="toggleEdit(<?= (int)$c['category_id'] ?>)">Edit</button>
                    <!-- Delete control added in Task 4 -->
                </td>
            </tr>
            <tr id="edit-<?= (int)$c['category_id'] ?>" style="display:none;">
                <td colspan="7" style="background:rgba(255,255,255,.02);">
                    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <label class="slug-muted">Name</label>
                            <input type="text" name="name" value="<?= he($c['name']) ?>" required style="padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <label class="slug-muted">Icon</label>
                            <input type="text" name="icon" value="<?= he($c['icon']) ?>" style="padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg-input);color:var(--text);width:140px;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <label class="slug-muted">Slug (permanent)</label>
                            <input type="text" value="<?= he($c['slug']) ?>" readonly title="Slug is permanent — it links existing products and cannot be changed." style="padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg-input);color:var(--text-muted);width:140px;">
                        </div>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);padding-bottom:6px;">
                            <input type="checkbox" name="is_active" <?= $c['is_active'] ? 'checked' : '' ?>> Active
                        </label>
                        <button type="submit" class="btn-nav" style="border-color:var(--accent);color:var(--accent);padding-bottom:6px;">Save</button>
                    </form>
                </td>
            </tr>
```

- [ ] **Step 3: Add the `toggleEdit` JS** (inside `<script>`)

```javascript
function toggleEdit(id) {
    const row = document.getElementById('edit-' + id);
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
```

- [ ] **Step 4: Syntax check**

Run: `php -l manage_categories.php`
Expected: `No syntax errors detected in manage_categories.php`

- [ ] **Step 5: Browser check — edit + toggle**

As admin: click Edit on `Smoothies`, confirm the Slug field is read-only, change Name to `Fresh Smoothies`, Save → success flash, row Name updates, **Slug still `Smoothies`**. Verify with:
```bash
php -r 'require "config.php"; $r=$conn->query("SELECT name,slug FROM categories WHERE slug=\"Smoothies\"")->fetch_assoc(); echo $r["name"]."|".$r["slug"]."\n";'
```
Expected: `Fresh Smoothies|Smoothies`. Then click the Active toggle → row becomes muted with an "Inactive" pill; open `menu.php` → the category is gone from the nav. Toggle back On.

- [ ] **Step 6: Commit**

```bash
git add manage_categories.php
git commit -m "feat(categories): add edit (name/icon) and active toggle; slug stays immutable"
```

---

### Task 4: Delete with in-use block (`action=delete`)

**Files:**
- Modify: `manage_categories.php`

**Interfaces:**
- Consumes: router, `$flash`, `$conn`, `$categories` (each row has `product_count`), `he()`.
- Produces: `case 'delete'`; a Delete control in each row's Actions cell, disabled with a tooltip when `product_count > 0`.

- [ ] **Step 1: Add the `delete` case to the router**

```php
            case 'delete': {
                $id = (int)($_POST['category_id'] ?? 0);
                if ($id <= 0) { $flash = ['type'=>'error','msg'=>'Invalid category.']; break; }
                $chk = $conn->prepare("SELECT COUNT(*) AS n FROM products WHERE category_id = ?");
                $chk->bind_param('i', $id); $chk->execute();
                $n = (int)$chk->get_result()->fetch_assoc()['n'];
                if ($n > 0) { $flash = ['type'=>'error','msg'=>"$n product(s) use this category — reassign them (via each product's Edit page) or delete them first."]; break; }
                $d = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
                $d->bind_param('i', $id); $d->execute();
                $flash = ['type'=>'success','msg'=>'Category deleted.'];
                break;
            }
```

- [ ] **Step 2: Render the Delete control** (in the Actions cell, replacing the `<!-- Delete control added in Task 4 -->` comment)

```php
                    <?php if ((int)$c['product_count'] > 0): ?>
                    <button type="button" class="act-link danger-link" disabled title="Cannot delete: <?= (int)$c['product_count'] ?> product(s) use this category">Delete</button>
                    <?php else: ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <button type="submit" class="act-link danger-link">Delete</button>
                    </form>
                    <?php endif; ?>
```

- [ ] **Step 3: Syntax check**

Run: `php -l manage_categories.php`
Expected: `No syntax errors detected in manage_categories.php`

- [ ] **Step 4: Browser check — block + allow**

As admin: the Delete control on a seeded category with products (e.g. `Iced Beverages`) is disabled; hovering shows the "Cannot delete: N product(s)…" tooltip. The empty `Smoothies` (or `Fresh Smoothies`) category has an active Delete → confirm dialog → delete → success flash, row gone. Verify:
```bash
php -r 'require "config.php"; echo $conn->query("SELECT COUNT(*) c FROM categories WHERE slug=\"Smoothies\"")->fetch_assoc()["c"]."\n";'
```
Expected: `0`.

Also verify the server-side block cannot be bypassed: it re-checks `product_count` in the handler, so even a forged POST for an in-use category is refused with the count message.

- [ ] **Step 5: Commit**

```bash
git add manage_categories.php
git commit -m "feat(categories): add delete blocked while products reference the category"
```

---

### Task 5: Reorder (`action=reorder`)

**Files:**
- Modify: `manage_categories.php`

**Interfaces:**
- Consumes: router, `$flash`, `$conn`, `$categories` (already ordered by `display_order`), the up/down buttons rendered disabled-at-boundaries in Task 1.
- Produces: `case 'reorder'` that swaps a row's `display_order` with its neighbor; the up/down buttons wrapped in POST forms.

- [ ] **Step 1: Add the `reorder` case to the router**

```php
            case 'reorder': {
                $id  = (int)($_POST['category_id'] ?? 0);
                $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                if ($id > 0) {
                    // current row
                    $cur = $conn->query("SELECT category_id, display_order FROM categories WHERE category_id = " . $id)->fetch_assoc();
                    if ($cur) {
                        // neighbor in the chosen direction by display_order
                        $cmp = $dir === 'up' ? '<' : '>';
                        $ord = $dir === 'up' ? 'DESC' : 'ASC';
                        $nb = $conn->query("SELECT category_id, display_order FROM categories WHERE display_order $cmp " . (int)$cur['display_order'] . " ORDER BY display_order $ord LIMIT 1")->fetch_assoc();
                        if ($nb) {
                            $a = (int)$cur['display_order']; $b = (int)$nb['display_order'];
                            $ca = (int)$cur['category_id'];  $cb = (int)$nb['category_id'];
                            $conn->query("UPDATE categories SET display_order = $b WHERE category_id = $ca");
                            $conn->query("UPDATE categories SET display_order = $a WHERE category_id = $cb");
                            $flash = ['type'=>'success','msg'=>'Order updated.'];
                        }
                    }
                }
                break;
            }
```

All values interpolated here are `(int)`-cast, so no injection is possible. (If two rows share a `display_order`, the swap still produces a deterministic order because the neighbor is chosen by `ORDER BY display_order` then the secondary `category_id` tiebreak in the list query.)

- [ ] **Step 2: Wrap the up/down buttons in reorder forms** (replace the two `icon-btn` buttons from Task 1's Order cell)

```php
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="icon-btn" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <input type="hidden" name="dir" value="down">
                        <button type="submit" class="icon-btn" title="Move down" <?= $i === count($categories) - 1 ? 'disabled' : '' ?>>&darr;</button>
                    </form>
                </td>
```

- [ ] **Step 3: Syntax check**

Run: `php -l manage_categories.php`
Expected: `No syntax errors detected in manage_categories.php`

- [ ] **Step 4: Browser check — reorder**

As admin: note the current order (e.g. Iced, Hot, Frappe, Juice, Milk Tea). Click "Move down" on the first row → it swaps with the second. Verify the persisted order:
```bash
php -r 'require "config.php"; $r=$conn->query("SELECT name FROM categories ORDER BY display_order, category_id"); foreach($r as $x) echo $x["name"]."\n";'
```
Expected: the two categories are swapped. Open `add_product.php` → the dropdown reflects the new order. Move it back up to restore. Confirm the top row's Up button and bottom row's Down button are disabled (no-op at boundaries).

- [ ] **Step 5: Commit**

```bash
git add manage_categories.php
git commit -m "feat(categories): add up/down reorder via display_order swap"
```

---

### Task 6: `products.php` — filter from categories table + entry-point button

**Files:**
- Modify: `products.php` (filter build ~lines 85-95; Category filter row ~1448-1458; topbar ~1373-1385)

**Interfaces:**
- Consumes: `$conn`, `$products`, `$_can_manage_products`, `$catCounts` (existing).
- Produces: `$filterCats` — an ordered list of `['slug'=>string,'count'=>int]` built from the `categories` table plus an "Uncategorized" entry when products with no known category exist; a "Manage Categories" topbar link.

- [ ] **Step 1: Build the filter list from the categories table**

After the existing `$catCounts` loop (the block ending at `sort($realCategories);`, ~line 95), add:

```php
// Category filter chips are sourced from the categories table so that a newly created
// but still-empty category still appears. $catCounts (slug => product count) is already
// built above from the product rows.
$filterCats = [];
$activeCatRes = $conn->query("SELECT slug FROM categories WHERE is_active = 1 ORDER BY display_order, category_id");
$knownSlugs = [];
while ($cr = $activeCatRes->fetch_assoc()) {
    $slug = $cr['slug'];
    $knownSlugs[$slug] = true;
    $filterCats[] = ['slug' => $slug, 'count' => $catCounts[$slug] ?? 0];
}
// Any products whose category isn't an active known slug (incl. empty => 'Uncategorized')
$uncat = 0;
foreach ($catCounts as $slug => $n) {
    if ($slug === 'Uncategorized' || !isset($knownSlugs[$slug])) $uncat += $n;
}
if ($uncat > 0) $filterCats[] = ['slug' => 'Uncategorized', 'count' => $uncat];
```

- [ ] **Step 2: Render the Category filter row from `$filterCats`**

Replace the existing category `foreach` (lines ~1453-1457):

```php
            <?php foreach ($filterCats as $fc): ?>
            <button class="filter-tab" data-filter="<?= htmlspecialchars($fc['slug']) ?>">
                <?= htmlspecialchars($fc['slug']) ?> <span class="tab-count"><?= (int)$fc['count'] ?></span>
            </button>
            <?php endforeach; ?>
```

The `All` button and its `$totalProducts` count are unchanged. Product cards keep `data-category="<slug>"`, so client-side filtering still matches on the slug — an empty category's chip simply filters to zero cards.

- [ ] **Step 3: Add the "Manage Categories" topbar button**

After the "Add Product" anchor (inside the existing `<?php if ($_can_manage_products): ?>` block, right before its `<?php endif; ?>` at ~line 1385):

```php
        <!-- Manage Categories -->
        <a href="manage_categories.php" class="btn-add" style="background:transparent;border:1px solid var(--border,#2a2a2a);color:var(--text,#f5f5f5);">
            <i class="fa-solid fa-tags"></i>
            <span class="hide-sm">Categories</span>
        </a>
```

- [ ] **Step 4: Syntax check**

Run: `php -l products.php`
Expected: `No syntax errors detected in products.php`

- [ ] **Step 5: Browser check — empty category chip + entry point**

As admin: add a fresh empty category `Seasonal` via `manage_categories.php`, then open `products.php`. Expected: a `Seasonal 0` chip appears in the Category filter row; clicking it shows zero product cards (and the count matches). The topbar shows a "Categories" button linking to `manage_categories.php`. As cashier: no "Categories" button (gated by `$_can_manage_products`). Clean up: delete `Seasonal` from `manage_categories.php`.

- [ ] **Step 6: Commit**

```bash
git add products.php
git commit -m "feat(products): source category filter from categories table + manage-categories entry"
```

---

## Self-Review

**Spec coverage:**
- New `manage_categories.php` with `admin_only.php` gate → Task 1. ✓
- List view (icon/name/slug/count/active/actions, ordered) → Task 1. ✓
- Add (name+icon+active, slug derived, dup+empty guard, `COALESCE(MAX,0)+1`, slug preview, example icons, `fa-tag` default) → Task 2. ✓
- Edit (name/icon/active, slug read-only + help text, immutable) → Task 3. ✓
- Active toggle → Task 3. ✓
- Delete blocked while in use, disabled button + tooltip, server re-check → Task 4. ✓
- Inactive rows muted + pill → Task 1 (styling) / applied throughout. ✓
- Reorder up/down with boundary no-ops → Task 5. ✓
- CSRF (stands.php pattern) on every mutation → Tasks 1-5. ✓
- `products.php` filter from categories table + per-chip counts + Uncategorized fallback → Task 6. ✓
- "Manage Categories" entry gated by `$_can_manage_products` → Task 6. ✓
- Testing steps (admin allowed; cashier/inventory-clerk denied; dark/light) → covered per-task + final browser passes. ✓

**Placeholder scan:** No "TBD"/"add validation"/vague steps — every code step shows complete code. The Task 1 rows intentionally leave commented insertion points that Tasks 3-5 replace with concrete markup (each such replacement is fully specified in its task). ✓

**Type consistency:** `cat_slug()` (Task 1) used in Task 2; `$flash`/`$categories` shape consistent across tasks; router `action` values (`create`/`update`/`toggle`/`delete`/`reorder`) match the form `action` hidden inputs; `product_count` key produced in Task 1 consumed in Task 4. ✓

**Note on TDD:** this repo has no PHP unit-test harness; per the plan's Tech Stack note, each task's "test" cycle is `php -l` + a targeted `php -r` DB assertion + a scripted manual browser check, mirroring the attendance-history plan. No xUnit-style failing-test-first step is applicable.
