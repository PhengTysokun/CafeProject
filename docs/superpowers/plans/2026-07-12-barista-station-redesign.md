# Barista Station Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Visual tasks (3, 4) should additionally use the **frontend-design** skill to polish the look — the markup here is a correct, working starting point, not a pixel-final design.

**Goal:** Give the barista role a denser, station-style Orders screen (left sidebar + at-a-glance stats + redesigned cards with overdue prioritization), reusing the existing orders-fetch, socket live-update, and Call/Complete actions. Cashier and manager keep the current view unchanged.

**Architecture:** `view_order.php` (2808 lines, single shared file) already branches on `$_SESSION['role']`. The page chrome (identity block → status tabs → search → orders grid, ~lines 1236–1492) is rendered in PHP; order cards are built in JS by `buildCardInner(o)`; order data comes from the PHP `action=fetch` JSON endpoint (~line 2541). This plan adds: (1) an `OVERDUE_MINUTES` Setting, (2) three extra fields in the fetch payload (`category` per item, `started_at`, `completed_at` per order), (3) a barista-only PHP shell (sidebar + main), (4) a barista-only JS card renderer, (5) JS stat computation from `allOrders`. All changes are gated on `role === 'barista'`; other roles' code paths are untouched.

**Tech Stack:** PHP 8 procedural, mysqli prepared statements, MySQL/MariaDB (XAMPP), vanilla JS (no framework), Font Awesome, Poppins. No unit-test framework — verify via `php -l`, DB inspection, and browser (Playwright).

## Global Constraints

- Branch **feat/product-addons** (local only, do NOT push). Commit each task.
- **Barista-only:** every new markup/CSS/JS branch is gated on `role === 'barista'` (`$r`/`$_SESSION['role']` in PHP, `userRole` in JS). Cashier and manager output must be byte-for-byte unchanged.
- **No money on the barista screen** — no revenue/total-sales stat. (Per-order `$total` already shows on cards today; leave that as-is, it is the order price a barista may reference, not a sales metric.)
- **No schema changes.** Only one new `settings` row (`overdue_minutes`).
- **Age basis** (shared by Overdue state + Avg Wait): `started_at` when present, else `order_date`.
- Reuse existing handlers: `action=fetch`, `action=complete`, `callOrder()`, `toggleClock()`, socket update. Do NOT modify them except the additive fetch-payload fields in Task 2.
- Existing test accounts: barista **darasokun** / `@Darasokun2026`; cashier **Sok_Dara** / `@Sokdara5678`; admin **Sokun** / `@Sokun9811`. App forces HTTPS (`https://localhost/Cafe/...`).

---

### Task 1: `OVERDUE_MINUTES` Setting

**Files:**
- Modify: `config.php` (near the other `define(...)` settings, ~line 62 where `STAND_COUNT` is defined)
- Modify: `settings.php` (numeric-settings form + save handler)

**Interfaces:**
- Produces: `OVERDUE_MINUTES` PHP constant (int, 1–120, default 10). Consumed by Tasks 2, 3, 5.
- Produces: `settings` row key `overdue_minutes`.

- [ ] **Step 1: Define the constant in config.php**

Find the `STAND_COUNT` define (config.php ~line 62):
```php
if (!defined('STAND_COUNT'))         define('STAND_COUNT',          max(1, min(100, (int)($_cafe_settings['stand_count'] ?? 20))));
```
Add immediately after it:
```php
if (!defined('OVERDUE_MINUTES'))     define('OVERDUE_MINUTES',      max(1, min(120, (int)($_cafe_settings['overdue_minutes'] ?? 10))));
```
(Note: `$_cafe_settings` is `unset()` a few lines later — add the define BEFORE that `unset(...)` call, alongside the other defines.)

- [ ] **Step 2: Verify the constant loads**

Run:
```bash
"/c/xampp/php/php.exe" -r "require 'config.php'; echo OVERDUE_MINUTES;"
```
Expected: `10`.

- [ ] **Step 3: Add the field to settings.php**

Locate an existing numeric setting in `settings.php` (e.g. the `stand_count` input and its save handler — grep `stand_count`). Mirror it for `overdue_minutes`:
- In the save/POST handler, alongside the other `settings` upserts, add an upsert for key `overdue_minutes` (clamp 1–120):
```php
$overdue = max(1, min(120, (int)($_POST['overdue_minutes'] ?? 10)));
$s = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('overdue_minutes', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$s->bind_param("s", $overdue); $s->execute();
```
(Match the EXACT upsert pattern already used in settings.php for other keys — if it uses a helper, use the helper. The snippet above is the fallback if there is no helper.)
- In the form, add a number input near the other numeric fields:
```html
<label>Overdue threshold (minutes)
  <input type="number" name="overdue_minutes" min="1" max="120"
         value="<?= (int)(defined('OVERDUE_MINUTES') ? OVERDUE_MINUTES : 10) ?>">
</label>
```
(Use the page's existing label/input markup + classes, not raw HTML, so it matches the other settings visually.)

- [ ] **Step 4: Verify syntax**

Run:
```bash
"/c/xampp/php/php.exe" -l config.php && "/c/xampp/php/php.exe" -l settings.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Verify save round-trips**

As admin **Sokun**, open `settings.php`, set Overdue threshold to `8`, save. Then:
```bash
"/c/xampp/php/php.exe" -r "require 'config.php'; echo OVERDUE_MINUTES;"
```
Expected: `8`. Set it back to `10` and save.

- [ ] **Step 6: Commit**

```bash
git add config.php settings.php
git commit -m "feat(settings): OVERDUE_MINUTES threshold (default 10)"
```

---

### Task 2: Fetch payload — add category (barista-only emit), started_at, completed_at

**Files:**
- Modify: `view_order.php` (the `action === "fetch"` block, ~lines 2541–2654)

**Interfaces:**
- Consumes: nothing new.
- Produces: each order object in the `fetch` JSON gains `started_at` (string|null) and `completed_at` (string|null). Each item object gains `category` (string) **only when the requester is a barista**. Consumed by Tasks 4 (card) and 5 (stats).

- [ ] **Step 1: Add columns to the SELECT + JOIN**

In the `action === "fetch"` SQL (starts ~line 2544), add `o.started_at`, `o.completed_at` to the order columns, and add `oi.product_id`, `p.category` to the item columns. Add the products join. Concretely:
- After `o.order_date,` add:
```sql
            o.started_at,
            o.completed_at,
```
- After `oi.quantity` (last item column, ~line 2572) add:
```sql
            ,oi.product_id,
            p.category
```
- After `LEFT JOIN order_items oi ON o.order_id = oi.order_id` (~line 2578) add:
```sql
        LEFT JOIN products p ON p.product_id = oi.product_id
```
- In the `GROUP BY` (~line 2582), append `, oi.product_id, p.category` so ONLY_FULL_GROUP_BY is satisfied.

- [ ] **Step 2: Add started_at/completed_at to the order map**

In the `if (!isset($map[$id]))` block (~line 2604), after `"order_date" => $r['order_date'],` add:
```php
                "started_at"    => $r['started_at'],
                "completed_at"  => $r['completed_at'],
```

- [ ] **Step 3: Emit category per item, barista-only**

Just before the `while` loop (after `$map = [];`, ~line 2599) compute the role flag once:
```php
    $__isBarista = ($_SESSION['role'] ?? '') === 'barista';
```
In the item push (`$map[$id]["items"][] = [ ... ]`, ~line 2630), add the category key conditionally by appending after the array is built. Replace the item push block:
```php
        if (!empty($r['product_name'])) {
            $item = [
                "item_id"      => (int)$r["item_id"],
                "product_name" => $r["product_name"],
                "size"         => $r["size_label"],
                "sweetness"    => $r["sweetness"],
                "ice"          => $r["ice"],
                "milk"         => $r["milk"],
                "addons"       => array_map(fn($a) => $a['name'], json_decode($r["addons_snapshot"] ?? '[]', true) ?: []),
                "quantity"     => $r["quantity"]
            ];
            if ($__isBarista) { $item["category"] = $r["category"] ?? ''; }
            $map[$id]["items"][] = $item;
        }
```
(This keeps the cashier/manager JSON byte-identical — `category` never appears for them — while the products join, one varchar by PK, is negligible.)

- [ ] **Step 4: Verify syntax**

```bash
"/c/xampp/php/php.exe" -l view_order.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verify payload as barista + regression as cashier**

Log in via browser as barista **darasokun**, open DevTools Network (or use Playwright `browser_evaluate` to `fetch('view_order.php?action=fetch').then(r=>r.json())`), confirm an item has a `category` field and each order has `started_at`/`completed_at`. Then as cashier **Sok_Dara**, confirm items have **no** `category` field (regression: cashier payload unchanged apart from the two new order-level nullable fields, which their UI ignores).

- [ ] **Step 6: Commit**

```bash
git add view_order.php
git commit -m "feat(orders): fetch returns started_at/completed_at + barista-only item category"
```

---

### Task 3: Barista shell — sidebar + main layout (PHP/CSS)

**Files:**
- Modify: `view_order.php` (wrap the chrome ~lines 1236–1492 in a role branch; add barista CSS in the `<style>` block)

**Interfaces:**
- Consumes: `$_is_clocked_in`, `$_clock_since`, `$_greeting`, `$_vo_username`, `$_role_label`, `$_role_color`, `can('my_profile')`, `OVERDUE_MINUTES`.
- Produces: DOM ids the JS relies on — MUST keep `#ordersBody`, `#annContainer`, `#clockBtn` (with `onclick="toggleClock()"` + `data-clocked`), `#searchInput` (barista search optional — see below). Adds new stat ids: `#stat-queue`, `#stat-overdue`, `#stat-done`, `#stat-avgwait`. Consumed by Task 5.

- [ ] **Step 1: Add barista shell CSS**

In the `<style>` block (before the closing `</style>` ~line 1221, after the light-theme rules), add:
```css
/* ── Barista Station Shell ── */
body.barista-mode { padding: 0; }
.bstation { display: flex; min-height: 100vh; }
.bsidebar {
    width: 250px; flex-shrink: 0; position: sticky; top: 0; align-self: flex-start;
    height: 100vh; overflow-y: auto;
    background: rgba(255,255,255,0.02); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; gap: 22px; padding: 22px 18px;
}
.bsidebar-brand { display:flex; align-items:center; gap:11px; font-weight:800; font-size:16px; color:var(--text); }
.bsidebar-brand .logo { width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--accent-dark));display:flex;align-items:center;justify-content:center;color:#000;flex-shrink:0; }
.buser { display:flex; align-items:center; gap:11px; }
.buser .avatar { width:40px;height:40px;border-radius:11px;background:rgba(209,144,75,.15);border:1px solid rgba(209,144,75,.25);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--accent); }
.bclock-pill { display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;padding:6px 12px;border-radius:9px; }
.bstats { display:flex; flex-direction:column; gap:9px; }
.bstats-label { font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:var(--text-muted);font-weight:700; }
.bstat-row { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; border-radius:10px; background:rgba(255,255,255,.03); border:1px solid var(--border); }
.bstat-row .k { font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:8px; }
.bstat-row .v { font-size:16px; font-weight:800; color:var(--text); }
.bstat-row.is-alert .v { color: var(--danger); }
.bnav { display:flex; flex-direction:column; gap:6px; margin-top:auto; }
.bnav a, .bnav button {
    display:flex; align-items:center; gap:10px; text-decoration:none;
    font-size:13px; font-weight:600; color:var(--text-muted);
    padding:10px 12px; border-radius:10px; border:1px solid transparent;
    background:none; cursor:pointer; font-family:'Poppins',sans-serif; text-align:left; width:100%;
    transition:all .18s;
}
.bnav a:hover, .bnav button:hover { color:var(--text); background:rgba(255,255,255,.04); }
.bmain { flex:1; min-width:0; padding: 24px 28px 60px; }
.bmain-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; margin-bottom:20px; }
.bmain-head h1 { font-size:22px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px; }
.barista-mode .orders-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
@media (max-width: 820px) {
    .bstation { flex-direction: column; }
    .bsidebar { width:100%; height:auto; position:static; flex-direction:row; flex-wrap:wrap; align-items:center; }
    .bnav { flex-direction:row; margin-top:0; flex-wrap:wrap; }
}
[data-theme="light"] .bsidebar { background:#F5F7FA; }
```

- [ ] **Step 2: Branch the chrome on role**

Wrap the existing chrome. Find the top-left identity block (`<!-- Top-left: Nav + Identity -->`, ~line 1236) and the orders grid close (`</div>` after `#ordersBody`, ~line 1492). Structure it so:
```php
<?php if ($r === 'barista'): ?>
<script>document.body.classList.add('barista-mode');</script>
<div class="bstation">
  <aside class="bsidebar">
     <div class="bsidebar-brand"><span class="logo"><i class="fa-solid fa-mug-hot"></i></span> Bird's Nest</div>
     <div class="buser">
        <div class="avatar"><?= strtoupper(substr($_vo_username,0,1)) ?></div>
        <div>
           <div style="font-weight:700;font-size:14px;color:var(--text)"><?= $_vo_username ?></div>
           <div style="font-size:11px;color:<?= $_role_color ?>"><?= $_role_label ?></div>
        </div>
     </div>
     <?php
        $bc = $_is_clocked_in;
        $bcBg = $bc ? 'rgba(85,224,135,.1)' : 'rgba(255,95,95,.08)';
        $bcCol = $bc ? '#55e087' : '#ff6b6b';
     ?>
     <div class="bclock-pill" style="background:<?= $bcBg ?>;color:<?= $bcCol ?>">
        <span style="width:7px;height:7px;border-radius:50%;background:currentColor"></span>
        <?= $bc ? ('Clocked in '.htmlspecialchars($_clock_since)) : 'Not clocked in' ?>
     </div>
     <div class="bstats">
        <div class="bstats-label">Today</div>
        <div class="bstat-row" id="stat-queue-row"><span class="k"><i class="fa-solid fa-hourglass-half"></i> In Queue</span><span class="v" id="stat-queue">0</span></div>
        <div class="bstat-row" id="stat-overdue-row"><span class="k"><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span><span class="v" id="stat-overdue">0</span></div>
        <div class="bstat-row" id="stat-done-row"><span class="k"><i class="fa-solid fa-check"></i> Done Today</span><span class="v" id="stat-done">0</span></div>
        <div class="bstat-row" id="stat-avg-row"><span class="k"><i class="fa-regular fa-clock"></i> Avg Wait</span><span class="v" id="stat-avgwait">—</span></div>
     </div>
     <nav class="bnav">
        <a href="recipes_view.php"><i class="fa-solid fa-book-open"></i> Drink Recipes</a>
        <?php if (can('my_profile')): ?><a href="profile.php"><i class="fa-solid fa-circle-user"></i> Profile</a><?php endif; ?>
        <button id="clockBtn" data-clocked="<?= $_is_clocked_in ? '1':'0' ?>" onclick="toggleClock()">
           <i class="fa-solid fa-<?= $_is_clocked_in ? 'right-from-bracket':'fingerprint' ?>"></i> <?= $_is_clocked_in ? 'Clock Out':'Clock In' ?>
        </button>
        <a href="shift_report.php" style="color:#ff6b6b"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
     </nav>
  </aside>
  <main class="bmain">
     <div class="bmain-head">
        <h1><i class="fa-solid fa-receipt"></i> Orders <span class="vo-live-badge"><span class="dot"></span> Live</span></h1>
        <input type="text" id="searchInput" placeholder="Search name, order #, drink…" oninput="searchOrders()"
               style="width:280px;max-width:100%;padding:10px 16px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);font-family:'Poppins',sans-serif;font-size:14px;outline:none">
     </div>
     <div id="annContainer" style="margin-bottom:14px"></div>
     <div class="container" style="max-width:none;margin:0">
        <div class="orders-grid" id="ordersBody"></div>
     </div>
  </main>
</div>
<?php else: ?>
<!-- ...existing chrome (identity block, header, status tabs, search, grid) verbatim... -->
<?php endif; ?>
```
IMPORTANT: The `else` branch must contain the ENTIRE current chrome unchanged (the top-left nav, top-right controls, header, announcements container, status tabs, search bar, and orders grid — everything currently at lines 1236–1492). Only the barista branch is new. There must be exactly one `#ordersBody` and one `#annContainer` in whichever branch renders. Do not leave a duplicate outside the branch.

Note: the barista branch omits the status tabs (barista only ever sees Preparing) — Task 5's stat computation + the existing default filter handle this. If the existing JS calls `filterStatus('Preparing')` on load for barista, keep that; if it references removed tab elements, guard those calls with `if (document.getElementById('statusTabs'))`.

- [ ] **Step 3: Verify syntax**

```bash
"/c/xampp/php/php.exe" -l view_order.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Browser-verify shell + regression**

As barista **darasokun**: sidebar renders (brand, user, clock pill, 4 stat rows, nav); main area shows header + grid; cards still load into `#ordersBody`; clock button toggles; no console errors (`browser_console_messages`). As cashier **Sok_Dara** and admin **Sokun**: Orders screen looks exactly as before (regression). Use frontend-design skill to refine spacing/hierarchy if it looks rough.

- [ ] **Step 5: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): station sidebar + main layout shell (barista-only)"
```

---

### Task 4: Barista card renderer (JS)

**Files:**
- Modify: `view_order.php` (JS: `buildCardInner`, ~line 1689; add `buildBaristaCardInner`, `elapsedShort`, category/overflow helpers)

**Interfaces:**
- Consumes: order object `o` from fetch (now with `started_at`, `completed_at`; items with `category`), `userRole`, `OVERDUE_MINUTES` (see Step 1), existing `getActionButtons(o)`, `escapeHtml`, `getStatusBadge`.
- Produces: barista card HTML. No new consumers.

- [ ] **Step 1: Expose OVERDUE_MINUTES to JS**

Near the other PHP→JS constants (`const userRole = ...`, ~line 1597) add:
```php
const OVERDUE_MINUTES = <?= (int)OVERDUE_MINUTES ?>;
```

- [ ] **Step 2: Add helpers**

Above `buildCardInner` (~line 1688) add:
```javascript
// Readable elapsed: minutes → "Xm", "Xh", "Xh Ym", "Xd"
function elapsedShort(ts) {
    if (!ts) return '';
    const m = Math.max(0, Math.floor((Date.now() - new Date(ts.replace(' ','T'))) / 60000));
    if (m < 60) return m + 'm';
    if (m < 1440) { const h = Math.floor(m/60), r = m%60; return r ? `${h}h ${r}m` : `${h}h`; }
    return Math.floor(m/1440) + 'd';
}
// Age basis: started_at when present else order_date
function orderAgeMin(o) {
    const basis = o.started_at || o.order_date;
    return Math.max(0, Math.floor((Date.now() - new Date(String(basis).replace(' ','T'))) / 60000));
}
// Chips for one item, capped with "+N more"
function baristaItemChips(i) {
    const chips = [];
    if (i.size)      chips.push(escapeHtml(i.size));
    if (i.sweetness) chips.push(escapeHtml(i.sweetness));
    if (i.ice)       chips.push(escapeHtml(i.ice));
    if (i.milk)      chips.push(escapeHtml(i.milk));
    if (i.addons && i.addons.length) i.addons.forEach(a => chips.push(escapeHtml(a)));
    const CAP = 5;
    let shown = chips.slice(0, CAP).map(c => `<span class="bchip">${c}</span>`).join('');
    if (chips.length > CAP) shown += `<span class="bchip bchip-more">+${chips.length - CAP} more</span>`;
    return shown;
}
```

- [ ] **Step 3: Add barista card CSS**

In the `<style>` block add (near the barista shell CSS from Task 3):
```css
.order-card.bcard { padding:16px 18px; }
.order-card.bcard.is-overdue { border-left:4px solid var(--danger); }
.order-card.bcard.is-warn    { border-left:4px solid var(--warning); }
.bcard-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.bcard-num { font-size:20px; font-weight:800; color:var(--accent); }
.bcard-badge { font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; }
.bcard-badge.overdue { background:rgba(255,92,92,.14); color:var(--danger); }
.bcard-badge.prep    { background:rgba(241,196,15,.14); color:#f1c40f; }
.bcard-sub { display:flex; align-items:center; justify-content:space-between; font-size:12px; color:var(--text-muted); margin-bottom:10px; }
.bcat { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; padding:2px 8px; border-radius:6px; background:rgba(91,192,222,.14); color:#5bc0de; }
.bitem { margin-bottom:10px; }
.bitem-name { font-size:16px; font-weight:700; color:var(--text-light); }
.bchips { display:flex; flex-wrap:wrap; gap:5px; margin-top:6px; }
.bchip { font-size:11px; padding:2px 9px; border-radius:20px; background:rgba(255,255,255,.06); color:var(--text-muted); }
.bchip-more { background:rgba(255,255,255,.03); font-style:italic; }
```

- [ ] **Step 4: Branch buildCardInner for barista**

At the very top of `buildCardInner(o)` (~line 1689) add:
```javascript
    if (userRole === 'barista') return buildBaristaCardInner(o);
```
Then define `buildBaristaCardInner` right after `buildCardInner`:
```javascript
function buildBaristaCardInner(o) {
    const age = orderAgeMin(o);
    const overdue = o.status === 'Preparing' && age >= OVERDUE_MINUTES;
    const warn    = o.status === 'Preparing' && !overdue && age >= Math.floor(OVERDUE_MINUTES * 0.7);
    const items = (o.items || []).map(i => `
        <div class="bitem">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="bitem-name">${escapeHtml(String(i.quantity) )}× ${escapeHtml(i.product_name)}</span>
                ${i.category ? `<span class="bcat">${escapeHtml(i.category)}</span>` : ''}
            </div>
            <div class="bchips">${baristaItemChips(i)}</div>
        </div>`).join('') || '<div style="color:var(--text-muted);font-size:12px">No items</div>';
    return `
        <div class="bcard-top">
            <span class="bcard-num">#${escapeHtml(String(o.daily_order_no))}</span>
            <span class="bcard-badge ${overdue ? 'overdue' : 'prep'}">
                ${overdue ? '<i class="fa-solid fa-triangle-exclamation"></i> Overdue' : '<i class="fa-solid fa-hourglass-half"></i> Preparing'}
            </span>
        </div>
        <div class="bcard-sub">
            <span><i class="fa-regular fa-user"></i> ${escapeHtml(o.customer_name || 'Guest')}</span>
            <span data-timestamp="${escapeHtml(o.order_date)}"><i class="fa-regular fa-clock"></i> ${elapsedShort(o.started_at || o.order_date)}</span>
        </div>
        ${items}
        <div class="card-footer" style="margin-top:8px">
            <div class="card-employee"><span style="opacity:.6;font-size:10px">Taken by:</span> ${escapeHtml(o.employee_name || 'Unknown')}</div>
        </div>
        <div class="card-actions">${getActionButtons(o)}</div>
    `;
}
```

- [ ] **Step 5: Apply overdue/warn class on the card element**

In `addRow(o)` (~line 1742), after `card.className = ...`, add:
```javascript
    if (userRole === 'barista' && o.status === 'Preparing') {
        card.classList.add('bcard');
        const age = orderAgeMin(o);
        if (age >= OVERDUE_MINUTES) card.classList.add('is-overdue');
        else if (age >= Math.floor(OVERDUE_MINUTES * 0.7)) card.classList.add('is-warn');
    }
```
(There is a second `card.innerHTML = buildCardInner(o)` at ~line 1844 in the socket-update path — if it constructs a fresh card element, apply the same class logic there; if it only refreshes innerHTML of an existing card, the border class persists and no change is needed. Verify which during implementation.)

- [ ] **Step 6: Verify syntax + browser**

```bash
"/c/xampp/php/php.exe" -l view_order.php
```
As barista **darasokun**: cards show `N× Drink Name` as the hero, a category badge, capped chips with `+N more` when >5, elapsed reads `8h` (not `520m`), and orders older than `OVERDUE_MINUTES` show the red left border + "Overdue" badge. Call + Complete still work. Verify a card with many add-ons keeps uniform height. Use frontend-design skill to refine.

- [ ] **Step 7: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): redesigned order card — hero name, category, capped chips, readable age, overdue"
```

---

### Task 5: Barista stats computation (JS)

**Files:**
- Modify: `view_order.php` (JS: hook into `loadOrders`, ~line 1928, where `allOrders` is populated)

**Interfaces:**
- Consumes: `allOrders` (array of order objects with `status`, `order_date`, `started_at`, `completed_at`), `OVERDUE_MINUTES`, `orderAgeMin` (Task 4), the stat DOM ids from Task 3.
- Produces: live sidebar stat updates. No consumers.

- [ ] **Step 1: Add the stat updater**

Add a function (near `buildBaristaCardInner`):
```javascript
function updateBaristaStats() {
    if (userRole !== 'barista') return;
    const el = id => document.getElementById(id);
    if (!el('stat-queue')) return;
    let queue = 0, overdue = 0, done = 0, waitSum = 0, waitN = 0;
    (allOrders || []).forEach(o => {
        if (o.status === 'Preparing') {
            queue++;
            if (orderAgeMin(o) >= OVERDUE_MINUTES) overdue++;
        } else if (o.status === 'Completed') {
            done++;
            const basis = o.started_at || o.order_date;
            if (o.completed_at && basis) {
                const mins = (new Date(String(o.completed_at).replace(' ','T')) - new Date(String(basis).replace(' ','T'))) / 60000;
                if (mins >= 0) { waitSum += mins; waitN++; }
            }
        }
    });
    el('stat-queue').textContent   = queue;
    el('stat-overdue').textContent = overdue;
    el('stat-done').textContent    = done;
    el('stat-avgwait').textContent = waitN ? (waitSum / waitN).toFixed(1) + 'm' : '—';
    el('stat-overdue-row').classList.toggle('is-alert', overdue > 0);
}
```

- [ ] **Step 2: Call it after each render**

In `loadOrders()` (~line 1928), after `allOrders` is assigned and cards are rendered (end of the success path), add:
```javascript
    updateBaristaStats();
```
Also call it inside the 30s `setInterval` tick (~line 2521, alongside `refreshAgeBadges()`) so Overdue/Avg Wait re-evaluate as time passes:
```javascript
    updateBaristaStats();
```

- [ ] **Step 3: Verify syntax + browser + DB cross-check**

```bash
"/c/xampp/php/php.exe" -l view_order.php
```
As barista **darasokun**: the 4 sidebar stats populate. Cross-check against DB for today's business date:
```bash
"/c/xampp/php/php.exe" -r "require 'config.php';
\$bd = (new DateTime())->format('H') < 6 ? (new DateTime('-1 day'))->format('Y-m-d') : date('Y-m-d');
\$p = \$conn->query(\"SELECT status, COUNT(*) c FROM orders WHERE business_date='\$bd' GROUP BY status\");
while(\$x=\$p->fetch_assoc()) echo \$x['status'].': '.\$x['c'].\"\n\";"
```
Expected: In Queue == Preparing count; Done Today == Completed count. Let an order age past `OVERDUE_MINUTES` (or temporarily lower it in Settings to 1) and confirm Overdue increments + turns red.

- [ ] **Step 4: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): live sidebar stats — queue, overdue, done, avg wait"
```

---

### Task 6: End-to-end verification + regression

**Files:** none (verification only).

- [ ] **Step 1: Barista happy path**

As **darasokun**: sidebar + 4 stats render; cards show hero name + category + capped chips + readable age; an overdue order shows red border + badge + increments Overdue stat; Call opens the call modal; Complete moves the order out of the queue and Done Today increments; clock in/out works; live update (socket or 30s poll) refreshes cards + stats. Screenshot for the record.

- [ ] **Step 2: Cashier regression**

As cashier **Sok_Dara**: Orders screen is visually identical to before — centered layout, all tabs (Pending/Preparing/Completed/Cancelled), payment actions (Paid) present and working. No barista sidebar. No console errors.

- [ ] **Step 3: Manager regression**

As admin **Sokun** (manager-equivalent view): All tab, Refunded tab, cancel/refund/remake modals all present and working. No console errors.

- [ ] **Step 4: Overdue threshold config**

As **Sokun**, set Overdue threshold to `1` in Settings; as barista confirm nearly all Preparing orders flag Overdue; set back to `10`.

- [ ] **Step 5: Final commit (if any verification fixes were needed)**

```bash
git add -A
git commit -m "fix(barista): verification pass adjustments"
```
(Skip if nothing changed.)

---

## Notes for the implementer

- The whole point is **isolation**: every branch keys on `role === 'barista'`. If you ever find yourself editing shared cashier/manager markup or the shared fetch output for non-baristas, stop — that violates the scope.
- Cards are JS-built; the PHP shell only owns the sidebar/header/grid container. Do not try to render cards in PHP.
- Use the **frontend-design** skill on Tasks 3 and 4 to make the station look intentional — the markup here is correct and complete but deliberately plain; polish typography, spacing, and the overdue treatment (subtle left bar, no glow/pulse/red-fill).
- Do NOT add a Revenue stat. Do NOT touch payment/cancel/refund/remake handlers.
- `elapsedShort`/`orderAgeMin` normalize the MySQL datetime (`YYYY-MM-DD HH:MM:SS`) to ISO by replacing the space with `T` so Safari/strict parsers don't return NaN.
```
