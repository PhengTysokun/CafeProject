# Permission-Driven Navigation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.
> **STATUS: DRAFT — the spec is not yet finalized (user may revise). Do NOT start executing until the spec is approved.**

**Goal:** Replace the app's three separate hardcoded navigation systems with one canonical permission→nav registry (`nav_menu.php`) that every role layout renders from, so any permission granted in `manage_roles` surfaces a nav entry uniformly — including for custom roles.

**Architecture:** A shared `nav_menu.php` defines an href/icon registry keyed by permission slug + explicit section order, and helpers `nav_items()` / `nav_items_grouped()` that join it to the `permissions` table (label/section/order) and filter by `can()`. Each dashboard layout iterates these instead of hardcoding routes. Access control stays on each page's `can()` guard.

**Tech Stack:** PHP 7 + MySQLi (procedural), inline HTML/CSS in `dashboard.php`, FontAwesome. No framework, no schema changes.

**Spec:** `docs/superpowers/specs/2026-07-14-permission-driven-navigation-design.md`

## Global Constraints

- Single source of truth: route+icon defined exactly once, in `nav_menu.php`.
- Nav visibility = `can(slug)` over the registry. Grant → link appears; revoke → hides. All layouts.
- `dashboard` is NOT in the registry — each layout hardcodes an indestructible "Dashboard" home link (never gated by `can()`).
- `$NAV_SECTION_ORDER` governs grouping order; never trust DB module/insertion order. Tie-break: section index → `sort_order` → `id`.
- `manage_roles` registry entry is `admin_only` → shown only when `$_SESSION['role']==='admin'`.
- Non-navigable perms (no registry entry): `manage_recipes`, `my_profile` (profile chip), `dashboard`.
- Page-level `can()` guards are UNCHANGED — navigation is discovery only, never access.
- No RBAC-backend changes (`manage_roles.php` CRUD, `role_permissions`, audit), no schema changes, no new permissions.
- No unit-test framework in this repo → each task ends with `php -l` + browser verification as the relevant role(s) + a byte/behavior regression check on roles not targeted by the task.
- Confirmed columns: `permissions(id, slug, name, module, sort_order)`. `can($slug)` + `$conn` (mysqli) are in scope wherever `nav_menu.php` is included (after `auth.php`).

---

## Phase 1 — Foundation + inventory + generic/custom

### Task 1: Build `nav_menu.php` (registry + helpers)

**Files:**
- Create: `nav_menu.php`

**Interfaces:**
- Produces: global `$NAV_REGISTRY`, `$NAV_SECTION_ORDER`; functions `nav_items(mysqli $conn): array` and `nav_items_grouped(mysqli $conn): array`. Each item = `['slug','label','href','icon','section','admin_only']` (plus internal sort keys), sorted by section order then `sort_order` then `id`.

- [ ] **Step 1: Create `nav_menu.php` with the full registry + helpers**

```php
<?php
// nav_menu.php — canonical permission→navigation registry (single source of truth).
// Access control stays on each page (can() guards); this drives discovery/links only.
// Requires: auth.php already loaded (can() available) and a mysqli $conn.

// Route/icon registry keyed by permission slug: [href, icon, admin_only?].
// Perms absent here are non-navigable by design (manage_recipes, my_profile).
// `dashboard` is intentionally absent — the home link is hardcoded per layout.
$NAV_REGISTRY = [
    'find_orders'         => ['find_order.php',            'fa-magnifying-glass'],
    'view_orders'         => ['view_order.php',            'fa-receipt'],
    'loyalty'             => ['loyalty_dashboard.php',     'fa-star'],
    'products'            => ['products.php',              'fa-cube'],
    'ingredients'         => ['ingredients.php',           'fa-flask'],
    'recipes'             => ['recipes_view.php',          'fa-utensils'],
    'suppliers'           => ['suppliers.php',             'fa-truck-ramp-box'],
    'purchase_orders'     => ['purchase_orders.php',       'fa-file-invoice'],
    'stock_count'         => ['stock_count.php',           'fa-clipboard-list'],
    'cash_reconciliation' => ['reconciliation_report.php', 'fa-cash-register'],
    'report'              => ['report.php',                'fa-chart-column'],
    'barista_station'     => ['barista_display.php',       'fa-mug-hot'],
    'customer_display'    => ['customer_display.php',      'fa-display'],
    'employees'           => ['employees.php',             'fa-user-tie'],
    'attendance'          => ['attendance.php',            'fa-fingerprint'],
    'announcements'       => ['announcements.php',         'fa-bullhorn'],
    'promotions'          => ['settings.php',              'fa-gear'],
    'reset_password'      => ['admin_reset_password.php',  'fa-key'],
    'manage_roles'        => ['manage_roles.php',          'fa-shield-halved', true],
];

// Explicit section display order — never trust DB module/insertion order.
$NAV_SECTION_ORDER = ['Orders','Operations','Inventory','Procurement','Reconciliation','Loyalty','Analytics','Staff','Admin'];

/**
 * Nav items the current user may see. Each: slug,label,href,icon,section,admin_only.
 * Filtered by admin_only + can(slug). Sorted by (section index, sort_order, id).
 */
function nav_items(mysqli $conn): array {
    global $NAV_REGISTRY, $NAV_SECTION_ORDER;
    $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
    $rows = [];
    $res = $conn->query("SELECT id, slug, name, module, sort_order FROM permissions");
    while ($res && $p = $res->fetch_assoc()) {
        $slug = $p['slug'];
        if (!isset($NAV_REGISTRY[$slug])) continue;                 // non-navigable
        [$href, $icon, $adminOnly] = array_pad($NAV_REGISTRY[$slug], 3, false);
        if ($adminOnly && !$isAdmin) continue;
        if (!can($slug)) continue;                                  // grant gate
        $secIdx = array_search($p['module'], $NAV_SECTION_ORDER, true);
        $rows[] = [
            'slug'=>$slug, 'label'=>$p['name'], 'href'=>$href, 'icon'=>$icon,
            'section'=>$p['module'], 'admin_only'=>(bool)$adminOnly,
            '_sec'=>($secIdx===false ? PHP_INT_MAX : $secIdx),
            '_ord'=>(int)$p['sort_order'], '_id'=>(int)$p['id'],
        ];
    }
    usort($rows, fn($a,$b) => [$a['_sec'],$a['_ord'],$a['_id']] <=> [$b['_sec'],$b['_ord'],$b['_id']]);
    return $rows;
}

/** nav_items() grouped by section, groups already in $NAV_SECTION_ORDER. */
function nav_items_grouped(mysqli $conn): array {
    $g = [];
    foreach (nav_items($conn) as $it) $g[$it['section']][] = $it;
    return $g;
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l nav_menu.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test the helper against real data (deterministic, no browser)**

Run (adjust creds from config.php if needed):
```bash
php -r '
require "config.php";                 // gives $conn + can(); or stub can() if config bootstraps session
session_start();
$_SESSION["role"]="inventory_clerk"; $_SESSION["user_id"]=/* a clerk user_id */ 0;
require "auth.php"; require "nav_menu.php";
foreach (nav_items($conn) as $i) echo $i["section"]."\t".$i["slug"]."\t".$i["href"]."\n";
'
```
Expected: prints the clerk's granted, registry-listed perms, grouped by section in `$NAV_SECTION_ORDER`. (If bootstrapping `can()` outside a real request is impractical, defer this to the Task 2 browser check and note it.)

- [ ] **Step 4: Commit**

```bash
git add nav_menu.php
git commit -m "feat(nav): canonical permission->nav registry + nav_items helpers"
```

---

### Task 2: Inventory sidebar consumes `nav_items()`

**Files:**
- Modify: `dashboard.php` (inventory `.inv-nav` block; add `require_once 'nav_menu.php'` near top with the other includes)

**Interfaces:**
- Consumes: `nav_items($conn)` from Task 1. Replaces the local `$inv_nav` array added 2026-07-14.

- [ ] **Step 1: Include the registry once**

Near the top of `dashboard.php` (after `auth.php` is required), add:
```php
require_once __DIR__ . '/nav_menu.php';
```

- [ ] **Step 2: Replace the inventory nav loop**

Grep the `.inv-nav` block (the `$inv_nav = [ ... ]; foreach(...)` added 2026-07-14) and replace its body with the shared helper, keeping the hardcoded Dashboard item:
```php
<nav class="inv-nav">
  <a class="inv-navitem active" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a>
  <?php foreach (nav_items($conn) as $it): ?>
    <a class="inv-navitem" href="<?= htmlspecialchars($it['href']) ?>"><i class="fa-solid <?= htmlspecialchars($it['icon']) ?>"></i><span><?= htmlspecialchars($it['label']) ?></span></a>
  <?php endforeach; ?>
</nav>
```
Delete the now-dead local `$inv_nav` array.

- [ ] **Step 3: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 4: Browser-verify as inventory clerk (`Clerk_Sokun`)**

Login → `dashboard.php`. Expected: sidebar shows Dashboard + exactly the clerk's granted registry perms (Products, Ingredients, Stock Count, Recipes, Suppliers, Purchase Orders), same as before this change — proves the map reproduces the prior hardcoded output with zero visual change.

- [ ] **Step 5: Grant/revoke round-trip**

As admin (`Sokun`) in `manage_roles.php`, grant `Cash Count` (cash_reconciliation) to the Inventory role → reload clerk dashboard → a "Cash Count" link appears under Reconciliation. Revoke → it disappears. No code change.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "refactor(nav): inventory sidebar renders from shared nav_items()"
```

---

### Task 3: Generic/custom dashboard branch — map-driven tiles + permission-driven focus + empty state

**Files:**
- Modify: `dashboard.php` (the non-admin/manager `else` branch that is NOT inventory_clerk — the focus-card + `qx-grid` tiles serving cashier/staff, barista, and custom roles)

**Interfaces:**
- Consumes: `nav_items($conn)`, `nav_items_grouped($conn)`.
- Produces: a permission-driven focus card + grouped tile grid + zero-permission empty state for every non-mgr, non-inventory role.

- [ ] **Step 1: Replace the hardcoded `qx-grid` tile groups with grouped map tiles**

In the generic branch, render one tile section per group from `nav_items_grouped($conn)` (group heading = section name; tiles = items), reusing the existing `.qx-group`/`.qx-tile` classes:
```php
<?php $__groups = nav_items_grouped($conn); ?>
<div class="qx-grid fu">
  <?php foreach ($__groups as $section => $items): ?>
    <div class="qx-group">
      <div class="qx-group-label"><?= htmlspecialchars($section) ?></div>
      <div class="qx-tiles">
        <?php foreach ($items as $it): ?>
          <a href="<?= htmlspecialchars($it['href']) ?>" class="qx-tile">
            <i class="fa-solid <?= htmlspecialchars($it['icon']) ?>"></i>
            <span><?= htmlspecialchars($it['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
```
Remove the old hardcoded `qx-group`/`can()` tile blocks in this branch (keep any non-nav elements like the "Take New Order" hero, still gated by `can('find_orders')`).

- [ ] **Step 2: Permission-driven focus card**

Replace the role-hardcoded `$_focus` selection with a priority scan over the role's permissions:
```php
<?php
$__has = fn($s) => can($s);
$_focus = null;
if ($__has('view_orders') || $__has('find_orders')) {
    $_focus = ['icon'=>'fa-cash-register','label'=>'orders to handle','href'=>'find_order.php','cta'=>'Open Orders','color'=>'#9b59b6'];
} elseif ($__has('products') || $__has('ingredients') || $__has('stock_count')) {
    $_focus = ['icon'=>'fa-triangle-exclamation','label'=>'stock to review','href'=>'ingredients.php','cta'=>'Review Stock','color'=>'#ff6b6b'];
} elseif ($__has('barista_station')) {
    $_focus = ['icon'=>'fa-mug-hot','label'=>'drinks to prepare','href'=>'barista_display.php','cta'=>'Barista Station','color'=>'#ff8a3d'];
}
// (counts/sublines may reuse existing $low_stock / $unpaid_count etc. where the perm matches)
?>
```
Render the focus card only when `$_focus !== null` (existing markup). Keep it minimal — reuse existing focus-card markup/styles.

- [ ] **Step 3: Zero-permission empty state**

When `nav_items($conn)` is empty (custom role with no navigable perms), render an actionable empty-state card instead of tiles/focus:
```php
<?php if (!$__groups): ?>
  <div class="qx-empty fu" style="text-align:center;padding:40px 24px;color:var(--text-muted)">
    <i class="fa-solid fa-lock" style="font-size:26px;opacity:.6"></i>
    <div style="margin-top:12px;font-weight:600;color:var(--text)">No areas assigned yet</div>
    <div style="margin-top:4px">Contact your system administrator to adjust your permissions.</div>
  </div>
<?php endif; ?>
```

- [ ] **Step 4: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 5: Browser-verify cashier + custom role**

- As cashier (`Sok_Dara`): tiles now render from the map (Orders/Loyalty/etc. per grants); focus card = Orders. Confirm no missing/duplicated tiles vs. intent.
- As admin, create a throwaway custom role with only `ingredients`; assign a test user; log in → generic dashboard shows just the Inventory group + "Focus on stock"; remove all perms → empty-state card. Delete the throwaway role after.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "refactor(nav): generic/custom dashboard tiles + focus + empty state from nav map"
```

---

## Phase 2 — Manager sidebar (do only after Phase 1 is verified)

> **STATUS 2026-07-19: SKIPPED by decision.** Phase 1 (T1–T3) shipped + verified.
> The manager/admin sidebar already hardcodes + `can()`-gates the ENTIRE registry and only
> serves system roles (admin/manager/supervisor; custom roles use the generic branch fixed in
> T3), so it already surfaces every granted permission. Migrating it buys only DRY-ness while
> reordering the admin's primary nav, splitting `manage_roles` into an Admin group, dropping
> `my_profile` from the sidebar, and needing Stands + 5 badges special-cased. Cost >> benefit —
> left as-is. Revisit only if the manager sidebar itself needs changes.

### Task 4: Manager grouped sidebar consumes `nav_items_grouped()` — SKIPPED (see status above)

**Files:**
- Modify: `dashboard.php` (the `$_is_mgr` sidebar nav groups, ~lines 1155-1360)

**Interfaces:**
- Consumes: `nav_items_grouped($conn)`. Replaces the ~200 lines of hardcoded `can()`-gated `nav-group`/`nav-item` blocks. Preserves the collapsible-group look (`toggleGroup`, `.nav-group-label`, `.nav-group-items`).

- [ ] **Step 1: Capture a before-baseline**

Browser as admin (`Sokun`): screenshot the current sidebar; list the exact groups + items + order. This is the visual contract to preserve.

- [ ] **Step 2: Replace the hardcoded nav groups with a rendered loop**

Keep the fixed "Overview → Dashboard" group at top (hardcoded, active-aware). Then render the rest from the map, preserving collapsible markup:
```php
<?php foreach (nav_items_grouped($conn) as $section => $items): ?>
  <div class="nav-group-label" onclick="toggleGroup(this)" data-group="<?= htmlspecialchars(strtolower($section)) ?>">
    <span><?= htmlspecialchars($section) ?></span><i class="fa-solid fa-chevron-right nav-chevron"></i>
  </div>
  <div class="nav-group-items collapsed" id="grp-<?= htmlspecialchars(strtolower($section)) ?>">
    <?php foreach ($items as $it): ?>
      <a class="nav-item<?= basename($_SERVER['PHP_SELF'])===$it['href'] ? ' active' : '' ?>" href="<?= htmlspecialchars($it['href']) ?>">
        <i class="fa-solid <?= htmlspecialchars($it['icon']) ?>"></i>
        <span class="nav-label"><?= htmlspecialchars($it['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
```
Preserve any badges the old sidebar showed (e.g. low-stock count on Ingredients, unpaid count on Find Orders) by special-casing those slugs inside the item loop — enumerate them explicitly so no badge is lost. Also preserve the manager-only extras that aren't `can()`-perms if any (e.g. the Stands/`$_can_stands` item — keep its existing hardcoded block if it has no permission slug).

- [ ] **Step 3: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 4: Visual regression vs baseline**

Browser as admin: compare sidebar to the Step-1 baseline — same groups, same items, same order, badges intact, collapsible behavior works. Then as manager (`Sokun` is admin; use a manager account if available) confirm manager sees its subset.

- [ ] **Step 5: Commit**

```bash
git add dashboard.php
git commit -m "refactor(nav): manager sidebar renders groups from shared nav map"
```

---

## Notes / deferred

- **Phase 3 (cashier tiles) folds into Task 3**: cashier(staff) is already inside the generic non-mgr branch, so Task 3 covers it. No separate cashier task unless the cashier gets its own layout later.
- **Badges** (counts on nav items) are the main non-mechanical risk in Task 4 — they live in the old hardcoded blocks and must be re-attached per-slug. Enumerate them before deleting the old markup.
- **`can()` availability in the CLI smoke test** (Task 1 Step 3) may be impractical if `auth.php` assumes a live request; the browser check in Task 2 is the authoritative verification.

## Self-Review Notes

- Spec coverage: registry+helpers (T1), inventory (T2), generic/custom incl. focus + empty state (T3), manager (T4). Custom-role landing + empty state + permission-driven focus all in T3. Section order + admin_only + dashboard-hardcode in T1. Cashier = T3. All spec sections mapped.
- Risk hotspots called out: Task 4 badges + collapsible markup; CLI `can()` bootstrap.
- Every task ends with `php -l` + role-scoped browser verification + (T2) grant/revoke round-trip, matching the no-test-framework reality.
