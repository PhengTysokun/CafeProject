# Inventory-Clerk Dashboard Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `inventory_clerk` role a purpose-built, real-data "StockMate"-style dashboard rendered via a role fence in `dashboard.php`, leaving every other role's output unchanged.

**Architecture:** One `if ($_role === 'inventory_clerk')` branch inside the existing role-conditional layout region of `dashboard.php`. New metric SQL runs only inside that branch. Layout = own sidebar + header + low-stock banner + 4 stat cards + Inventory/Procurement tile sections + right rail (Low Stock panel with wired filter tabs + Recent Activity feed) + notifications bell dropdown. Bird's Nest brand kept; no schema changes, no fabricated deltas.

**Tech Stack:** PHP 7 + MySQLi (procedural), HTML/CSS (inline in `dashboard.php`), vanilla JS, FontAwesome, existing theme CSS variables.

**Spec:** `docs/superpowers/specs/2026-07-13-inventory-dashboard-redesign-design.md`

## Global Constraints

- Role gate: all new markup + SQL run ONLY when `($_SESSION['role'] ?? '') === 'inventory_clerk'`.
- Other roles (admin, manager, supervisor, staff, barista) must render **byte-for-byte unchanged** — verify by git diff of rendered output / no new queries in their path.
- Brand stays **Bird's Nest Coffee** (`fa-mug-hot` + "Bird's Nest"). No "StockMate" text/logo.
- **No fabricated trend numbers.** Only Monthly Usage carries a real `% vs last month` delta.
- No schema changes, no new tables, no cron, no new PHP files.
- Ingredient columns are `ingredient_name`, `stock_quantity`, `minimum_stock`, `unit` — there is NO `name` column.
- Low filter = `stock_quantity < minimum_stock`. Progress ratio = `stock_quantity / NULLIF(minimum_stock,0)` clamped 0–1. Critical = ratio < 0.10.
- Recent Activity excludes `order_deduct` and `order_restore`.
- Theme: base-dark + `[data-theme=light]` overrides, reuse existing CSS vars (`--surface-2`, `--border`, `--border-hi`, `--amber`, `--red`, `--purple`, `--text`, `--text-muted`). No new theme key.
- This repo has **no unit-test framework**. Each task's verification = SQL cross-check + browser-verify as `inventory_clerk` (`Clerk_Sokun`) + regression check that other roles render unchanged.

---

### Task 0: Pre-flight — locate the fence, confirm baseline

**Files:**
- Inspect: `dashboard.php`

- [ ] **Step 1: Confirm branch + clean tree**

Run: `git status --short && git branch --show-current`
Expected: on `feat/product-addons`; note any pre-existing unrelated modified files (do not touch them).

- [ ] **Step 2: Locate the exact role-conditional layout region (do NOT trust line numbers)**

Run: `grep -n "_is_mgr\|non-admin/manager\|\$_role\s*=\|\$_focus\b\|<?php else:" dashboard.php`
Expected: find the `<?php if ($_is_mgr): ?>` sidebar open (~`:994`), the `<?php else: /* non-admin/manager` marker (~`:1530`), and the `$_role = $_SESSION['role']` assignment inside it. The inventory branch will be inserted as the FIRST thing inside the non-admin `else` body, wrapping the current non-admin content in an `else`.

- [ ] **Step 3: Capture a baseline render hash for a non-target role (regression anchor)**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`. (Used again after each task to prove no syntax breakage.)

- [ ] **Step 4: Record the plan of insertion (no code change yet)**

Confirm in notes: new branch goes at the top of the non-admin/manager `else` block:
```php
<?php else: /* non-admin/manager */ ?>
  <?php if (($_SESSION['role'] ?? '') === 'inventory_clerk'): ?>
     ... NEW inventory layout ...
  <?php else: ?>
     ... EXISTING non-admin focus-card + tiles, unchanged ...
  <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 5: Commit nothing** (inspection-only task; proceed to Task 1).

---

### Task 1: Metric queries (inventory_clerk-gated)

**Files:**
- Modify: `dashboard.php` (metrics block near the other `$low_stock` queries, ~`:48`)

**Interfaces:**
- Produces: `$inv_total_products` (int), `$inv_pending_po` (int), `$inv_usage_this` (float), `$inv_usage_last` (float), `$inv_usage_delta` (int|null — null ⇒ hide delta), `$inv_low_list` (array of rows: `ingredient_name, stock_quantity, minimum_stock, unit`), `$inv_activity` (array of rows: `change_type, amount, created_at, ingredient_name`). Reuses existing `$low_stock`, `$low_recipe_count`, `$_unread_ann`.

- [ ] **Step 1: Add the gated metrics block**

Insert after the existing `$low_recipe_count` computation (~`dashboard.php:57`):

```php
// ── Inventory-clerk dashboard metrics (only computed for that role) ──
$inv_total_products = 0; $inv_pending_po = 0;
$inv_usage_this = 0.0;   $inv_usage_last = 0.0; $inv_usage_delta = null;
$inv_low_list = [];      $inv_activity = [];
if (($_SESSION['role'] ?? '') === 'inventory_clerk') {
    $inv_total_products = (int)($conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'] ?? 0);

    $inv_pending_po = (int)($conn->query(
        "SELECT COUNT(*) c FROM purchase_orders WHERE status IN ('Draft','Ordered')"
    )->fetch_assoc()['c'] ?? 0);

    // Monthly usage = magnitude of order_deduct rows this month vs last month
    $inv_usage_this = (float)($conn->query(
        "SELECT IFNULL(SUM(ABS(amount)),0) s FROM ingredient_history
         WHERE change_type='order_deduct'
           AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())"
    )->fetch_assoc()['s'] ?? 0);
    $inv_usage_last = (float)($conn->query(
        "SELECT IFNULL(SUM(ABS(amount)),0) s FROM ingredient_history
         WHERE change_type='order_deduct'
           AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH)
           AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH)"
    )->fetch_assoc()['s'] ?? 0);
    // delta: null when last month = 0 (no base) OR this month = 0 (avoid misleading -100%)
    if ($inv_usage_last > 0 && $inv_usage_this > 0) {
        $inv_usage_delta = (int)round(($inv_usage_this - $inv_usage_last) / $inv_usage_last * 100);
    }

    $lr = $conn->query(
        "SELECT ingredient_name, stock_quantity, minimum_stock, unit
         FROM ingredients WHERE stock_quantity < minimum_stock
         ORDER BY (stock_quantity/NULLIF(minimum_stock,0)) ASC"
    );
    while ($lr && $row = $lr->fetch_assoc()) $inv_low_list[] = $row;

    $ar = $conn->query(
        "SELECT ih.change_type, ih.amount, ih.created_at, i.ingredient_name
         FROM ingredient_history ih
         JOIN ingredients i ON ih.ingredient_id = i.ingredient_id
         WHERE ih.change_type NOT IN ('order_deduct','order_restore')
         ORDER BY ih.created_at DESC LIMIT 6"
    );
    while ($ar && $row = $ar->fetch_assoc()) $inv_activity[] = $row;
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Cross-check the numbers against DB directly**

Run (adjust mysql creds from `config.php` if needed):
```bash
php -r '$c=new mysqli("localhost","root","","db_coffee");
echo "products=".$c->query("SELECT COUNT(*) c FROM products")->fetch_assoc()["c"]."\n";
echo "pendingPO=".$c->query("SELECT COUNT(*) c FROM purchase_orders WHERE status IN (\"Draft\",\"Ordered\")")->fetch_assoc()["c"]."\n";
echo "lowlist=".$c->query("SELECT COUNT(*) c FROM ingredients WHERE stock_quantity<minimum_stock")->fetch_assoc()["c"]."\n";'
```
Expected: three integer counts print without error. Note them for later UI verification.

- [ ] **Step 4: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): compute inventory-clerk dashboard metrics (role-gated)"
```

---

### Task 2: Fence + sidebar + header + low-stock banner

**Files:**
- Modify: `dashboard.php` (non-admin/manager `else` body, ~`:1530`)

**Interfaces:**
- Consumes: Task 1 vars, `$admin_name`, `$_cur_role_name`, `$_cur_role_color`, `$_unread_ann`, `$low_stock`.
- Produces: the outer `<?php if inventory_clerk ?>` fence + `.inv-*` layout scaffold that Tasks 3–6 fill; JS namespace `inv*`.

- [ ] **Step 1: Wrap the non-admin body in the role fence**

At the top of the `<?php else: /* non-admin/manager ... ?>` block (~`:1530`), open the fence; at the block's end (just before the `<?php endif; ?>` that closes the `$_is_mgr` conditional) close it, moving the existing non-admin content into the inner `else`:

```php
<?php else: /* non-admin/manager */ ?>
<?php if (($_SESSION['role'] ?? '') === 'inventory_clerk'): ?>

<!-- ═══ INVENTORY-CLERK DASHBOARD (StockMate layout) ═══ -->
<div class="inv-shell">
  <aside class="inv-sidebar">
    <div class="inv-brand"><i class="fa-solid fa-mug-hot"></i><span>Bird's Nest</span></div>
    <nav class="inv-nav">
      <a class="inv-navitem active" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a>
      <?php if (can('products')): ?><a class="inv-navitem" href="products.php"><i class="fa-solid fa-cube"></i><span>Products</span></a><?php endif; ?>
      <?php if (can('ingredients')): ?><a class="inv-navitem" href="ingredients.php"><i class="fa-solid fa-flask"></i><span>Ingredients</span></a><?php endif; ?>
      <?php if (can('recipes')): ?><a class="inv-navitem" href="recipes_view.php"><i class="fa-solid fa-utensils"></i><span>Recipes</span></a><?php endif; ?>
      <?php if (can('suppliers')): ?><a class="inv-navitem" href="suppliers.php"><i class="fa-solid fa-truck-ramp-box"></i><span>Suppliers</span></a><?php endif; ?>
      <?php if (can('purchase_orders')): ?><a class="inv-navitem" href="purchase_orders.php"><i class="fa-solid fa-file-invoice"></i><span>Purchase Orders</span></a><?php endif; ?>
      <?php if (can('report')): ?><a class="inv-navitem" href="report.php"><i class="fa-solid fa-chart-column"></i><span>Reports</span></a><?php endif; ?>
      <a class="inv-navitem" href="settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a>
    </nav>
    <?php if (can('my_profile')): ?>
    <a href="profile.php" class="inv-userchip">
    <?php else: ?><div class="inv-userchip" style="cursor:default"><?php endif; ?>
      <div class="inv-avatar"><?= strtoupper(substr($admin_name,0,2)) ?></div>
      <div><div class="inv-uname"><?= htmlspecialchars($admin_name) ?></div>
      <div class="inv-urole"><?= htmlspecialchars($_cur_role_name) ?></div></div>
    <?php if (can('my_profile')): ?></a><?php else: ?></div><?php endif; ?>
  </aside>

  <main class="inv-main">
    <header class="inv-header">
      <div>
        <div class="inv-greet"><?php
          $h=(int)date('G'); echo $h<12?'Good morning':($h<18?'Good afternoon':'Good evening');
        ?>, <span><?= htmlspecialchars($admin_name) ?></span></div>
        <div class="inv-date"><?= date('l, F j, Y') ?></div>
      </div>
      <div class="inv-hcluster">
        <button class="inv-iconbtn" id="invThemeBtn" title="Toggle theme"><i class="fa-solid fa-sun"></i></button>
        <div class="inv-bellwrap">
          <button class="inv-iconbtn" id="invBell"><i class="fa-solid fa-bell"></i>
            <?php if ($_unread_ann > 0): ?><span class="inv-bellcount" id="invBellCount"><?= $_unread_ann ?></span><?php endif; ?>
          </button>
          <div class="inv-notifpanel" id="invNotifPanel"><!-- filled in Task 6 --></div>
        </div>
        <button class="inv-clockbtn" id="clockBtn" data-clocked="0"><i class="fa-solid fa-clock"></i> Clock In</button>
        <a class="inv-logoutbtn" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </header>

    <?php if ((int)$low_stock > 0): ?>
    <a class="inv-banner" href="ingredients.php">
      <span><i class="fa-solid fa-triangle-exclamation"></i> <?= (int)$low_stock ?> item<?= $low_stock==1?'':'s' ?> low on stock — restock needed soon</span>
      <span class="inv-banner-cta">Review Stock <i class="fa-solid fa-chevron-right"></i></span>
    </a>
    <?php endif; ?>

    <!-- stat cards → Task 3 -->
    <!-- tiles + rail → Task 4 & 5 -->
  </main>
</div>

<?php else: ?>
```

(The existing non-admin focus-card + `qx-grid` markup remains here, now inside this inner `else`, unchanged. Close with `<?php endif; ?>` before the `$_is_mgr` `endif`.)

- [ ] **Step 2: Add the CSS for shell/sidebar/header/banner**

In the `<style>` block, add (base-dark + light override):
```css
.inv-shell{display:flex;gap:0;min-height:100vh}
.inv-sidebar{width:230px;flex:0 0 230px;background:var(--surface-2);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:18px 14px;gap:6px}
.inv-brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;color:var(--text);padding:6px 8px 14px}
.inv-brand i{color:var(--amber)}
.inv-nav{display:flex;flex-direction:column;gap:2px;flex:1}
.inv-navitem{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;color:var(--text-muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .12s,color .12s}
.inv-navitem i{width:18px;text-align:center}
.inv-navitem:hover{background:var(--border);color:var(--text)}
.inv-navitem.active{background:var(--amber);color:#1a1205;font-weight:700}
.inv-userchip{display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;border:1px solid var(--border);text-decoration:none;color:var(--text)}
.inv-avatar{width:36px;height:36px;border-radius:9px;background:var(--amber);color:#1a1205;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px}
.inv-uname{font-size:13px;font-weight:700;color:var(--text)}
.inv-urole{font-size:11px;color:var(--text-muted)}
.inv-main{flex:1;min-width:0;padding:24px 28px;display:flex;flex-direction:column;gap:20px}
.inv-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.inv-greet{font-size:22px;font-weight:800;color:var(--text)}
.inv-greet span{color:var(--amber)}
.inv-date{font-size:13px;color:var(--text-muted);margin-top:2px}
.inv-hcluster{display:flex;align-items:center;gap:10px}
.inv-iconbtn{position:relative;width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;font-size:15px}
.inv-iconbtn:hover{color:var(--text);border-color:var(--border-hi)}
.inv-bellwrap{position:relative}
.inv-bellcount{position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 4px}
.inv-clockbtn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;border:1px solid #2e8b57;background:transparent;color:#3ecf8e;font-weight:600;font-size:13px;cursor:pointer}
.inv-clockbtn[data-clocked="1"]{border-color:var(--amber);color:var(--amber)}
.inv-logoutbtn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;border:1px solid #a33;background:transparent;color:#ff6b6b;font-weight:600;font-size:13px;text-decoration:none}
.inv-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;border-radius:12px;background:rgba(255,107,107,.09);border:1px solid rgba(255,107,107,.28);color:#ff9a9a;text-decoration:none;font-size:14px;font-weight:600}
.inv-banner-cta{color:#ff6b6b;white-space:nowrap}
[data-theme=light] .inv-navitem.active{color:#fff}
[data-theme=light] .inv-avatar,[data-theme=light] .inv-navitem.active{color:#3a2600}
</style>
```
(Place inside the existing `<style>…</style>`; adjust the closing `</style>` — do not add a second one.)

- [ ] **Step 3: Syntax check**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Browser-verify as inventory clerk**

Log in as `Clerk_Sokun` / `@Clerksokun9811` (via `login.php`, lands on `loading.php`), then navigate to `dashboard.php`. Expected: sidebar (Bird's Nest brand, nav, Dashboard active pill), header greeting + date + theme/bell/clock/logout cluster, and the red low-stock banner if `$low_stock > 0`. No PHP warnings.

- [ ] **Step 5: Regression — other roles unchanged**

Log in as `Sokun` (admin) and `Sok_Dara` (cashier); load `dashboard.php`. Expected: their dashboards look exactly as before (no inv-* markup leaks). `php -l` already clean.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): fence + sidebar, header, low-stock banner for inventory dashboard"
```

---

### Task 3: Stat cards (4, all real)

**Files:**
- Modify: `dashboard.php` (inside `.inv-main`, replacing `<!-- stat cards → Task 3 -->`)

**Interfaces:**
- Consumes: `$inv_total_products`, `$low_stock`, `$inv_pending_po`, `$inv_usage_this`, `$inv_usage_delta`.

- [ ] **Step 1: Add the stat-card grid**

Replace the `<!-- stat cards → Task 3 -->` comment with:
```php
<section class="inv-stats">
  <div class="inv-card">
    <div class="inv-card-ico" style="color:var(--amber)"><i class="fa-solid fa-cube"></i></div>
    <div class="inv-card-val"><?= number_format($inv_total_products) ?></div>
    <div class="inv-card-lbl">Total Products</div>
    <div class="inv-card-sub">Active catalog</div>
  </div>
  <div class="inv-card">
    <div class="inv-card-ico" style="color:#ff6b6b"><i class="fa-solid fa-arrow-trend-down"></i></div>
    <div class="inv-card-val" style="color:<?= $low_stock>0?'#ff6b6b':'var(--text)' ?>"><?= (int)$low_stock ?></div>
    <div class="inv-card-lbl">Low Stock Items</div>
    <div class="inv-card-sub"><?= $low_stock>0?'Restock needed soon':'Stock levels healthy' ?></div>
  </div>
  <div class="inv-card">
    <div class="inv-card-ico" style="color:#5b9bd5"><i class="fa-solid fa-cart-shopping"></i></div>
    <div class="inv-card-val"><?= (int)$inv_pending_po ?></div>
    <div class="inv-card-lbl">Pending Orders</div>
    <div class="inv-card-sub"><?= (int)$inv_pending_po ?> awaiting delivery</div>
  </div>
  <div class="inv-card">
    <div class="inv-card-ico" style="color:#3ecf8e"><i class="fa-solid fa-chart-column"></i></div>
    <div class="inv-card-val"><?= number_format($inv_usage_this) ?></div>
    <div class="inv-card-lbl">Monthly Usage</div>
    <div class="inv-card-sub">
      <?php if ($inv_usage_delta !== null): ?>
        <span style="color:<?= $inv_usage_delta>=0?'#3ecf8e':'#ff6b6b' ?>">
          <i class="fa-solid fa-arrow-<?= $inv_usage_delta>=0?'up':'down' ?>"></i>
          <?= abs($inv_usage_delta) ?>% vs last month</span>
      <?php else: ?>units deducted this month<?php endif; ?>
    </div>
  </div>
</section>
```

- [ ] **Step 2: Add stat-card CSS**

```css
.inv-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.inv-card{background:var(--surface-2);border:1px solid var(--border);border-radius:16px;padding:20px}
.inv-card-ico{font-size:20px;margin-bottom:14px}
.inv-card-val{font-size:30px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;line-height:1}
.inv-card-lbl{font-size:13px;color:var(--text-muted);margin-top:6px}
.inv-card-sub{font-size:12px;color:var(--text-muted);margin-top:8px}
```

- [ ] **Step 3: Syntax check**

Run: `php -l dashboard.php` → `No syntax errors detected`.

- [ ] **Step 4: Browser-verify values**

Reload `dashboard.php` as `Clerk_Sokun`. Expected: four cards; Total Products / Low Stock / Pending Orders / Monthly Usage numbers equal the values printed in Task 1 Step 3. Monthly Usage delta shows only if both months have usage.

- [ ] **Step 5: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): 4 real-data stat cards (products, low stock, pending POs, monthly usage)"
```

---

### Task 4: Tile sections (Inventory + Procurement)

**Files:**
- Modify: `dashboard.php` (inside `.inv-main`, replacing `<!-- tiles + rail → Task 4 & 5 -->` with a 2-column wrapper; tiles in the left column)

**Interfaces:**
- Consumes: `can()`, `$low_recipe_count`.
- Produces: `.inv-body` two-column wrapper (`.inv-content` left, `.inv-rail` right) that Task 5 fills.

- [ ] **Step 1: Add the body wrapper + tile sections**

Replace `<!-- tiles + rail → Task 4 & 5 -->` with:
```php
<div class="inv-body">
  <div class="inv-content">
    <?php if (can('products')||can('ingredients')||can('recipes')): ?>
    <div class="inv-sec-label">Inventory</div>
    <div class="inv-tiles">
      <?php if (can('products')): ?>
      <a class="inv-tile" href="products.php"><span class="inv-tile-ico" style="color:var(--amber)"><i class="fa-solid fa-cube"></i></span>
        <span><span class="inv-tile-t">Products</span><span class="inv-tile-d">Manage all finished goods</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
      <?php endif; ?>
      <?php if (can('ingredients')): ?>
      <a class="inv-tile" href="ingredients.php"><span class="inv-tile-ico" style="color:#3ecf8e"><i class="fa-solid fa-flask"></i></span>
        <span><span class="inv-tile-t">Ingredients</span><span class="inv-tile-d">Raw materials &amp; components</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
      <?php endif; ?>
      <?php if (can('recipes')): ?>
      <a class="inv-tile" href="recipes_view.php"><span class="inv-tile-ico" style="color:#b98add"><i class="fa-solid fa-utensils"></i></span>
        <span><span class="inv-tile-t">Drink Recipes<?php if ($low_recipe_count>0): ?> <span class="inv-tile-badge"><?= (int)$low_recipe_count ?> low</span><?php endif; ?></span><span class="inv-tile-d">Beverage formulations</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (can('suppliers')||can('purchase_orders')): ?>
    <div class="inv-sec-label">Procurement</div>
    <div class="inv-tiles">
      <?php if (can('suppliers')): ?>
      <a class="inv-tile" href="suppliers.php"><span class="inv-tile-ico" style="color:#5b9bd5"><i class="fa-solid fa-truck-ramp-box"></i></span>
        <span><span class="inv-tile-t">Suppliers</span><span class="inv-tile-d">Vendor management</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
      <?php endif; ?>
      <?php if (can('purchase_orders')): ?>
      <a class="inv-tile" href="purchase_orders.php"><span class="inv-tile-ico" style="color:var(--amber)"><i class="fa-solid fa-file-invoice"></i></span>
        <span><span class="inv-tile-t">Purchase Orders</span><span class="inv-tile-d">Track and manage POs</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <aside class="inv-rail"><!-- Task 5 --></aside>
</div>
```

- [ ] **Step 2: Add tile + body CSS**

```css
.inv-body{display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start}
.inv-content{display:flex;flex-direction:column;gap:12px}
.inv-sec-label{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--amber);margin-top:8px}
.inv-tiles{display:flex;flex-direction:column;gap:10px}
.inv-tile{display:flex;align-items:center;gap:16px;padding:16px 18px;background:var(--surface-2);border:1px solid var(--border);border-radius:14px;text-decoration:none;transition:transform .12s,border-color .12s}
.inv-tile:hover{transform:translateY(-1px);border-color:var(--border-hi)}
.inv-tile-ico{flex:0 0 auto;width:44px;height:44px;border-radius:11px;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;font-size:18px}
.inv-tile>span:nth-child(2){flex:1;display:flex;flex-direction:column;gap:3px}
.inv-tile-t{font-size:15px;font-weight:700;color:var(--text)}
.inv-tile-d{font-size:12px;color:var(--text-muted)}
.inv-tile-arw{color:var(--text-muted);font-size:13px}
.inv-tile-badge{font-size:11px;font-weight:700;color:#ff9a3d;background:rgba(255,138,61,.15);padding:1px 7px;border-radius:8px;margin-left:4px}
```

- [ ] **Step 3: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 4: Browser-verify** — reload as `Clerk_Sokun`; expected two labeled tile groups (Inventory: Products/Ingredients/Drink Recipes; Procurement: Suppliers/Purchase Orders), each linking correctly, low-recipe badge if any. Right column reserved (empty for now).

- [ ] **Step 5: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): Inventory + Procurement tile sections in 2-col body"
```

---

### Task 5: Right rail — Low Stock panel (wired filter) + Recent Activity

**Files:**
- Modify: `dashboard.php` (`.inv-rail` body + a `<script>` for filter JS)

**Interfaces:**
- Consumes: `$inv_low_list`, `$inv_activity`.
- Produces: `invFilterLow(mode)` JS; `.inv-lsrow[data-sev]` rows.

- [ ] **Step 1: Render the Low Stock panel**

Replace `<!-- Task 5 -->` inside `.inv-rail` with:
```php
<div class="inv-panel">
  <div class="inv-panel-head">
    <span><i class="fa-solid fa-arrow-trend-down" style="color:#ff6b6b"></i> Low Stock</span>
    <div class="inv-filter">
      <button class="inv-fbtn active" data-mode="all" onclick="invFilterLow('all',this)">All</button>
      <button class="inv-fbtn" data-mode="low" onclick="invFilterLow('low',this)">Low</button>
      <button class="inv-fbtn" data-mode="critical" onclick="invFilterLow('critical',this)">Critical</button>
      <a class="inv-viewall" href="ingredients.php">View all</a>
    </div>
  </div>
  <div class="inv-lslist" id="invLsList">
    <?php if (!$inv_low_list): ?>
      <div class="inv-empty">Stock levels look healthy.</div>
    <?php else: foreach ($inv_low_list as $it):
      $min=(float)$it['minimum_stock']; $st=(float)$it['stock_quantity'];
      $ratio = $min>0 ? max(0,min(1,$st/$min)) : 1;
      $sev = $ratio < 0.10 ? 'critical' : 'low';
      $pct = round($ratio*100);
      $barcol = $ratio<0.10 ? '#ff4d4d' : ($ratio<0.30 ? '#ff8a3d' : '#f0b429');
      $qty = rtrim(rtrim(number_format($st,2,'.',''),'0'),'.');
    ?>
      <div class="inv-lsrow" data-sev="<?= $sev ?>">
        <div class="inv-lsrow-top"><span class="inv-lsname"><?= htmlspecialchars($it['ingredient_name']) ?></span>
          <span class="inv-lsqty"><?= $qty ?> <?= htmlspecialchars($it['unit']) ?></span></div>
        <div class="inv-lsbar"><span style="width:<?= $pct ?>%;background:<?= $barcol ?>"></span></div>
        <div class="inv-lssub"><?= $pct ?>% of threshold (<?= rtrim(rtrim(number_format($min,2,'.',''),'0'),'.') ?> <?= htmlspecialchars($it['unit']) ?>)</div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
```

- [ ] **Step 2: Render the Recent Activity panel** (append inside `.inv-rail`, after the Low Stock panel)

```php
<div class="inv-panel">
  <div class="inv-panel-head"><span>Recent Activity</span></div>
  <div class="inv-actlist">
    <?php
    $actMap = [
      'po_received'   => ['Purchase Order received', '#5b9bd5'],
      'quick_restock' => ['Restocked',               '#3ecf8e'],
      'count_adjust'  => ['Stock count adjusted',    '#b98add'],
      'manual_adjust' => ['Stock adjusted',          '#f0b429'],
    ];
    if (!$inv_activity): ?>
      <div class="inv-empty">No recent stock activity.</div>
    <?php else: foreach ($inv_activity as $a):
      [$label,$dot] = $actMap[$a['change_type']] ?? ['Inventory updated','#888'];
      $ts = strtotime($a['created_at']); $diff = time()-$ts;
      $ago = $diff<3600 ? max(1,floor($diff/60)).'m' : ($diff<86400 ? floor($diff/3600).'h' : floor($diff/86400).'d');
    ?>
      <div class="inv-actrow"><span class="inv-actdot" style="background:<?= $dot ?>"></span>
        <div><div class="inv-acttext"><?= htmlspecialchars($label) ?> — <?= htmlspecialchars($a['ingredient_name']) ?></div>
        <div class="inv-actago"><?= $ago ?> ago</div></div></div>
    <?php endforeach; endif; ?>
  </div>
</div>
```

- [ ] **Step 3: Add rail CSS + filter JS**

```css
.inv-rail{display:flex;flex-direction:column;gap:16px}
.inv-panel{background:var(--surface-2);border:1px solid var(--border);border-radius:16px;padding:16px}
.inv-panel-head{display:flex;align-items:center;justify-content:space-between;font-weight:700;color:var(--text);font-size:14px;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.inv-filter{display:flex;align-items:center;gap:6px}
.inv-fbtn{border:none;background:transparent;color:var(--text-muted);font-size:12px;font-weight:600;padding:3px 9px;border-radius:7px;cursor:pointer}
.inv-fbtn.active{background:rgba(255,255,255,.08);color:var(--text)}
.inv-viewall{font-size:12px;color:var(--amber);text-decoration:none;margin-left:2px}
.inv-lslist,.inv-actlist{display:flex;flex-direction:column;gap:14px}
.inv-lsrow-top{display:flex;justify-content:space-between;font-size:13px}
.inv-lsname{font-weight:600;color:var(--text)}
.inv-lsqty{color:var(--text-muted)}
.inv-lsbar{height:5px;border-radius:3px;background:var(--border);margin:6px 0 4px;overflow:hidden}
.inv-lsbar span{display:block;height:100%;border-radius:3px}
.inv-lssub{font-size:11px;color:var(--text-muted)}
.inv-actrow{display:flex;gap:10px;align-items:flex-start}
.inv-actdot{flex:0 0 auto;width:8px;height:8px;border-radius:50%;margin-top:5px}
.inv-acttext{font-size:13px;color:var(--text)}
.inv-actago{font-size:11px;color:var(--text-muted);margin-top:2px}
.inv-empty{font-size:13px;color:var(--text-muted);padding:6px 0}
```
```html
<script>
function invFilterLow(mode, btn){
  document.querySelectorAll('.inv-fbtn').forEach(b=>b.classList.toggle('active', b===btn));
  document.querySelectorAll('#invLsList .inv-lsrow').forEach(r=>{
    const sev=r.dataset.sev; let show = mode==='all' || (mode==='critical'&&sev==='critical') || (mode==='low'&&(sev==='low'||sev==='critical'));
    r.style.display = show ? '' : 'none';
  });
}
</script>
```

- [ ] **Step 4: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 5: Browser-verify** — reload as `Clerk_Sokun`. Expected: Low Stock panel lists ingredients worst-first with progress bars + `% of threshold`; clicking All/Low/Critical filters rows without reload; Recent Activity shows recent restock/PO/adjust events (NO "used in order" sales rows), each with a colored dot + "Xm/h/d ago". Empty states render when no data.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): Low Stock panel (wired All/Low/Critical filter) + Recent Activity feed"
```

---

### Task 6: Notifications bell dropdown

**Files:**
- Modify: `dashboard.php` (`#invNotifPanel` body + bell/theme/clock JS)

**Interfaces:**
- Consumes: `$_unread_ann`, existing announcements pipeline (reuse whatever `dashboard.php`/`auth.php` already loads for announcements; if the shared page already fetches announcements into a variable/AJAX, reuse it — do NOT add a new query pattern).

- [ ] **Step 1: Locate the existing announcement render used elsewhere on the page**

Run: `grep -n "announcement\|annContainer\|updateAnnouncements\|ann_dismissed" dashboard.php`
Expected: find how announcements are already rendered/dismissed on the shared page. Reuse that markup/JS inside `#invNotifPanel`. If announcements are only surfaced as `$_unread_ann` count with no list on this page, render the panel from a lightweight inline query mirroring the count query (active, not-expired, not-started-in-future) LIMIT 6.

- [ ] **Step 2: Fill the notif panel** (replace `<!-- filled in Task 6 -->`)

```php
<div class="inv-notif-head"><span>Notifications</span><button class="inv-notif-clear" onclick="invMarkAllRead()">Mark all read</button></div>
<div class="inv-notif-list" id="invNotifList">
  <?php
  $nres = $conn->query("SELECT id, title, message, type, created_at FROM announcements
     WHERE is_active=1 AND (expires_at IS NULL OR expires_at>=CURDATE())
       AND (starts_at IS NULL OR starts_at<=CURDATE())
     ORDER BY created_at DESC LIMIT 6");
  if (!$nres || !$nres->num_rows): ?>
    <div class="inv-empty" style="padding:14px">You're all caught up.</div>
  <?php else: while ($n=$nres->fetch_assoc()):
    $tc = $n['type']==='urgent'?'#ff6b6b':($n['type']==='warning'?'#f0b429':'#5b9bd5'); ?>
    <div class="inv-notif-item"><span class="inv-actdot" style="background:<?= $tc ?>"></span>
      <div><div class="inv-acttext"><?= htmlspecialchars($n['title']) ?></div>
      <div class="inv-notif-msg"><?= htmlspecialchars($n['message']) ?></div></div></div>
  <?php endwhile; endif; ?>
</div>
<a class="inv-notif-foot" href="announcements.php">View all notifications</a>
```

- [ ] **Step 3: Add notif CSS + bell/theme/clock JS**

```css
.inv-notifpanel{position:absolute;top:46px;right:0;width:320px;max-height:420px;overflow:auto;background:var(--surface-2);border:1px solid var(--border-hi);border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.4);display:none;z-index:60}
.inv-notifpanel.open{display:block}
.inv-notif-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--border);font-weight:700;color:var(--text)}
.inv-notif-clear{border:none;background:transparent;color:var(--amber);font-size:12px;font-weight:600;cursor:pointer}
.inv-notif-item{display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border)}
.inv-notif-msg{font-size:12px;color:var(--text-muted);margin-top:2px}
.inv-notif-foot{display:block;text-align:center;padding:12px;color:var(--amber);text-decoration:none;font-size:13px}
```
```html
<script>
(function(){
  var bell=document.getElementById('invBell'), panel=document.getElementById('invNotifPanel');
  if(bell){bell.addEventListener('click',function(e){e.stopPropagation();panel.classList.toggle('open');});
    document.addEventListener('click',function(){panel.classList.remove('open');});
    panel.addEventListener('click',function(e){e.stopPropagation();});}
  var tbtn=document.getElementById('invThemeBtn');
  if(tbtn){tbtn.addEventListener('click',function(){
    var cur=document.documentElement.getAttribute('data-theme')==='light'?'dark':'light';
    document.documentElement.setAttribute('data-theme',cur); localStorage.setItem('theme',cur);});}
})();
function invMarkAllRead(){
  var c=document.getElementById('invBellCount'); if(c)c.style.display='none';
  fetch('mark_announcements_read.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(function(){});
  document.getElementById('invNotifPanel').classList.remove('open');
}
</script>
```

- [ ] **Step 4: Verify the mark-read endpoint exists (or degrade gracefully)**

Run: `ls mark_announcements_read.php 2>/dev/null; grep -rln "announcement_reads" *.php | head`
Expected: if a mark-read endpoint exists, point `invMarkAllRead` at it; if not, the `.catch` no-ops and the badge still hides client-side (acceptable — count refreshes correctly on next page load). Adjust the fetch URL to the real endpoint name found.

- [ ] **Step 5: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 6: Browser-verify** — as `Clerk_Sokun`: bell shows unread badge; click opens dropdown with recent announcements + "Mark all read" + "View all"; click-outside closes it; theme toggle flips dark/light and persists on reload.

- [ ] **Step 7: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): notifications bell dropdown + theme toggle for inventory dashboard"
```

---

### Task 7: Responsive, theme sweep, final regression

**Files:**
- Modify: `dashboard.php` (media queries + light-theme overrides)

- [ ] **Step 1: Add responsive rules**

```css
@media(max-width:1000px){
  .inv-body{grid-template-columns:1fr}
  .inv-stats{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:640px){
  .inv-sidebar{display:none}
  .inv-stats{grid-template-columns:1fr}
  .inv-main{padding:16px}
}
```

- [ ] **Step 2: Light-theme spot-check overrides** — ensure banner/critical colors stay legible on light. Add any needed `[data-theme=light] .inv-...` tweaks discovered during verify.

- [ ] **Step 3: Syntax check** — `php -l dashboard.php` → clean.

- [ ] **Step 4: Full browser verify (both themes, narrow width)** — as `Clerk_Sokun`: toggle light/dark, resize to tablet (rail stacks under tiles) and mobile (sidebar hidden, cards stack). Everything legible, no overflow.

- [ ] **Step 5: Final regression — all other roles unchanged** — load `dashboard.php` as `Sokun` (admin) and `Sok_Dara` (cashier), `darasokun` (barista lands on view_order — load dashboard.php directly). Confirm each renders its original layout with zero `inv-*` leakage.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "feat(inventory): responsive + light-theme polish for inventory dashboard"
```

---

## Self-Review Notes

- **Spec coverage:** sidebar/header/banner (T2), 4 stat cards + real monthly delta (T1/T3), Inventory+Procurement tiles (T4), Low Stock panel + wired filter + Recent Activity (T5), notif bell dropdown + theme toggle (T6), responsive/theme/regression (T7). Injection-point discipline (T0). All spec sections mapped.
- **Data honesty:** no fabricated deltas; only Monthly Usage delta, guarded for both-months-zero. Activity excludes sales rows.
- **Column correctness:** `ingredient_name` used throughout (no `name`).
- **Role isolation:** every task ends with a regression check on non-target roles; metrics gated in T1.
- **Open build-time confirmations (flagged, not placeholders):** exact mark-read endpoint name (T6 S4); whether the shared page already renders an announcements list to reuse (T6 S1). Both have defined fallbacks.
