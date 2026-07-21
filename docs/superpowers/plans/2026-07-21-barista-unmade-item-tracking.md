# Barista Unmade-Item Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the barista only *unmade* drinks, so a re-opened pay-later tab surfaces just the newly-added drinks instead of the whole (already-made) order.

**Architecture:** Add `order_items.made_at` (NULL = unmade). Stamp it on the barista's Complete action for whatever was still unmade. The barista station's item list filters to unmade rows; a re-opened tab (Preparing + has already-made items) gets a "Returning tab" badge. Totals/points/stock are untouched.

**Tech Stack:** PHP 8 + MySQL (mysqli), vanilla JS front-end. No automated test framework — verification is `php -l` lint + direct MySQL queries + browser E2E.

## Global Constraints

- Branch: `feat/product-addons` (local, not pushed).
- MySQL DB name: `db_coffee`. CLI: `/c/xampp/mysql/bin/mysql -uroot db_coffee`.
- Schema changes go through the existing `_migrate($conn, '<id>', fn)` helper in `config.php` (tracked in `schema_migrations`, runs exactly once). Do NOT hand-write `SHOW COLUMNS` guards.
- App forces HTTPS; base URL `https://localhost/cafe/`.
- Lint every touched PHP file with `/c/xampp/php/php.exe -l <file>` before commit.
- Barista role slug = `barista`. Cashier = `staff`. Admin login = `Sokun` / `@Sokun9811`; barista = `darasokun` / `@Darasokun2026`.
- Do NOT restart Apache from the CLI (user restarts via XAMPP Control Panel). Migrations apply on the next page load.

---

### Task 1: Add `made_at` column + one-time backfill

**Files:**
- Modify: `config.php` (after the `order_items_orig_price` migration, line ~122)

**Interfaces:**
- Produces: `order_items.made_at DATETIME NULL` — NULL means the drink is not yet made. All historical orders not currently in the queue are backfilled non-NULL so they never appear as unmade.

- [ ] **Step 1: Add the migration**

In `config.php`, immediately after the `order_items_orig_price` migration block (ends line ~122), add:

```php
// Barista unmade-item tracking: made_at NULL = drink not yet made (in the queue).
// Stamped when the barista completes an order. Backfill everything not currently in the
// queue so history doesn't flood the barista station on first load.
_migrate($conn, 'order_items_made_at_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS made_at DATETIME NULL DEFAULT NULL");
    $db->query("
        UPDATE order_items oi
        JOIN orders o ON o.order_id = oi.order_id
        SET oi.made_at = COALESCE(o.completed_at, o.order_date)
        WHERE oi.made_at IS NULL
          AND o.status NOT IN ('Preparing','PendingPayment')
    ");
});
```

- [ ] **Step 2: Lint**

Run: `/c/xampp/php/php.exe -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 3: Trigger the migration**

The migration runs on the next authenticated page load. Trigger it and confirm the column exists:

Run:
```bash
curl -sk https://localhost/cafe/loading.php -o /dev/null
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "SHOW COLUMNS FROM order_items LIKE 'made_at';"
```
Expected: one row, `made_at  datetime  YES ... NULL`.

(If curl doesn't trigger it — auth may 302 before config runs — instead open `https://localhost/cafe/dashboard.php` in the browser while logged in, then re-run the SHOW COLUMNS query.)

- [ ] **Step 4: Verify backfill correctness**

Run:
```bash
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "
SELECT o.status, COUNT(*) rows_total, SUM(oi.made_at IS NULL) unmade
FROM order_items oi JOIN orders o ON o.order_id=oi.order_id
GROUP BY o.status ORDER BY o.status;"
```
Expected: `Preparing` and `PendingPayment` rows have `unmade` = their row count (still NULL). Every other status has `unmade = 0` (all backfilled).

- [ ] **Step 5: Confirm migration recorded**

Run: `/c/xampp/mysql/bin/mysql -uroot db_coffee -e "SELECT id FROM schema_migrations WHERE id='order_items_made_at_v1';"`
Expected: one row. (Guarantees the backfill won't run again.)

- [ ] **Step 6: Commit**

```bash
git add config.php
git commit -m "feat(barista): add order_items.made_at column + backfill

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Stamp `made_at` when the barista completes an order

**Files:**
- Modify: `update_status.php:60-65` (after the order-status UPDATE, before the loyalty block)

**Interfaces:**
- Consumes: `order_items.made_at` (Task 1).
- Produces: on transition to `Completed`, every still-unmade item of that order is stamped `made_at = NOW()`. On a first completion that's all items; on a re-opened tab it's only the newly-added rows (old rows already carry a timestamp and are skipped by `made_at IS NULL`).

- [ ] **Step 1: Add the stamp**

In `update_status.php`, the order UPDATE executes at line ~63-65:

```php
$stmt = $conn->prepare("UPDATE orders SET status = ? $extra_sql WHERE order_id = ?");
$stmt->bind_param("si", $new_status, $order_id);
$stmt->execute();
```

Immediately after that `$stmt->execute();` (and before the `// ── LOYALTY` comment on line ~67), insert:

```php
// Stamp the drinks made at this completion — only those still unmade. First completion
// stamps all items; a re-opened pay-later tab stamps only its newly-added rows.
if ($new_status === 'Completed') {
    $stmt_made = $conn->prepare("UPDATE order_items SET made_at = NOW() WHERE order_id = ? AND made_at IS NULL");
    $stmt_made->bind_param("i", $order_id);
    $stmt_made->execute();
}
```

- [ ] **Step 2: Lint**

Run: `/c/xampp/php/php.exe -l update_status.php`
Expected: `No syntax errors detected in update_status.php`

- [ ] **Step 3: Verify against a real Preparing order**

Find a Preparing order and confirm its items are unmade, complete it, then confirm they're stamped.

Run:
```bash
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "
SELECT o.order_id, o.daily_order_no, COUNT(*) items, SUM(oi.made_at IS NULL) unmade
FROM orders o JOIN order_items oi ON oi.order_id=o.order_id
WHERE o.status='Preparing' GROUP BY o.order_id LIMIT 5;"
```
Pick an `order_id` with `unmade > 0` (call it `<OID>`), then complete it via the app endpoint (logged in as barista/admin in the browser, or):
```bash
curl -sk "https://localhost/cafe/update_status.php?order_id=<OID>&status=Completed&ajax=1"
```
Expected JSON: `{"ok":true,"error":null}`.

Then:
```bash
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "SELECT item_id, made_at FROM order_items WHERE order_id=<OID>;"
```
Expected: every row now has a non-NULL `made_at`.

- [ ] **Step 4: Commit**

```bash
git add update_status.php
git commit -m "feat(barista): stamp made_at on order completion

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Expose `is_made` per item and `is_returning` per order in the barista feed

**Files:**
- Modify: `view_order.php` — the order-items SELECT (~line 2905-2925), and the `$map` builder (~line 2947-2991)

**Interfaces:**
- Consumes: `order_items.made_at` (Task 1).
- Produces: each order object in the JSON `allOrders` feed gains `is_returning` (0/1); each item object gains `is_made` (0/1). Consumed by Task 4's renderer.

- [ ] **Step 1: Add `made_at` to the SELECT and its GROUP BY**

In `view_order.php`, the item columns are selected around line 2905-2914. Add `oi.made_at,` to the column list — put it right after `oi.quantity,` (line 2912):

```php
            oi.quantity,
            oi.made_at,
            oi.product_id,
            p.category
```

The query has an explicit `GROUP BY` (line ~2925). Add `oi.made_at` to it so the column is valid under `ONLY_FULL_GROUP_BY`:

```php
        GROUP BY o.order_id, oi.item_id, oi.product_name, oi.sweetness, oi.ice, oi.milk, oi.size_label, oi.addons_snapshot, oi.quantity, oi.made_at, oi.product_id, p.category
```

- [ ] **Step 2: Initialise `is_returning` on the order map**

In the `if (!isset($map[$id]))` block (~line 2948-2970), add an `is_returning` seed. Insert it right before `"items" => []` (line ~2969):

```php
                "is_returning" => 0,
                "items" => []
```

- [ ] **Step 3: Add `is_made` to each item and flag returning tabs**

The item is assembled at ~line 2978-2990. Replace that block:

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

with:

```php
        if (!empty($r['product_name'])) {
            $is_made = !empty($r["made_at"]) ? 1 : 0;
            $item = [
                "item_id"      => (int)$r["item_id"],
                "product_name" => $r["product_name"],
                "size"         => $r["size_label"],
                "sweetness"    => $r["sweetness"],
                "ice"          => $r["ice"],
                "milk"         => $r["milk"],
                "addons"       => array_map(fn($a) => $a['name'], json_decode($r["addons_snapshot"] ?? '[]', true) ?: []),
                "quantity"     => $r["quantity"],
                "is_made"      => $is_made
            ];
            if ($__isBarista) { $item["category"] = $r["category"] ?? ''; }
            $map[$id]["items"][] = $item;
            // A Preparing order that already has a made item = a re-opened ("returning") tab.
            if ($is_made && $map[$id]["status"] === 'Preparing') {
                $map[$id]["is_returning"] = 1;
            }
        }
```

- [ ] **Step 4: Lint**

Run: `/c/xampp/php/php.exe -l view_order.php`
Expected: `No syntax errors detected in view_order.php`

- [ ] **Step 5: Verify the feed payload**

Log in as barista in the browser (`darasokun` / `@Darasokun2026`) and load `https://localhost/cafe/view_order.php`. Open DevTools console and run:

```js
copy(JSON.stringify(allOrders.map(o => ({id:o.daily_order_no, status:o.status, ret:o.is_returning, items:(o.items||[]).map(i=>i.is_made)}))))
```
Expected: Preparing orders show items with `is_made:0`; any re-opened tab shows a mix of `0` and `1` and `ret:1`. Completed orders show all `is_made:1`.

- [ ] **Step 6: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): expose is_made per item and is_returning per order

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Barista card shows only unmade drinks + "Returning tab" badge

**Files:**
- Modify: `view_order.php` — `buildBaristaCardInner` items map (~line 1950-1957) and its `.bcard-top` (~line 1965-1968); plus one CSS rule near the `.bcard-badge` styles.

**Interfaces:**
- Consumes: `o.is_returning`, `i.is_made` (Task 3).

- [ ] **Step 1: Filter the item list to unmade (with all-made fallback)**

In `buildBaristaCardInner` (~line 1950), replace:

```php
    const items = (o.items || []).map(i => `
        <div class="bitem">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="bitem-name">${escapeHtml(String(i.quantity))}× ${escapeHtml(i.product_name)}</span>
                ${i.category ? `<span class="bcat">${escapeHtml(i.category)}</span>` : ''}
            </div>
            <div class="bchips">${baristaItemChips(i)}</div>
        </div>`).join('') || '<div style="color:var(--text-muted);font-size:12px;padding-left:8px">No items</div>';
```

with:

```php
    // Barista sees only unmade drinks. If somehow every item is already made on a
    // still-open card, fall back to the full list rather than render an empty card.
    const _all = o.items || [];
    const _unmade = _all.filter(i => !i.is_made);
    const _shown = _unmade.length ? _unmade : _all;
    const items = _shown.map(i => `
        <div class="bitem">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="bitem-name">${escapeHtml(String(i.quantity))}× ${escapeHtml(i.product_name)}</span>
                ${i.category ? `<span class="bcat">${escapeHtml(i.category)}</span>` : ''}
            </div>
            <div class="bchips">${baristaItemChips(i)}</div>
        </div>`).join('') || '<div style="color:var(--text-muted);font-size:12px;padding-left:8px">No items</div>';
```

- [ ] **Step 2: Add the "Returning tab" badge**

In the same function's return template, the header is (~line 1965-1968):

```php
        <div class="bcard-top">
            <span class="bcard-num">#${escapeHtml(String(o.daily_order_no))}</span>
            ${badge}
        </div>
```

Replace with:

```php
        <div class="bcard-top">
            <span class="bcard-num">#${escapeHtml(String(o.daily_order_no))}</span>
            ${badge}
            ${o.is_returning ? '<span class="bcard-badge returning"><i class="fa-solid fa-rotate-right"></i> Returning tab</span>' : ''}
        </div>
```

- [ ] **Step 3: Add the badge CSS**

Find the existing `.bcard-badge` rule (search `/c/xampp/htdocs/Cafe/view_order.php` for `.bcard-badge`). Immediately after the `.bcard-badge.prep` / `.bcard-badge.overdue` rules, add:

```css
    .bcard-badge.returning { background: rgba(155,89,182,.16); color: #b07cc6; border: 1px solid rgba(155,89,182,.4); }
```

(If `.bcard-badge` has no color-variant rules to anchor to, place this rule directly after the base `.bcard-badge { ... }` block.)

- [ ] **Step 4: Lint**

Run: `/c/xampp/php/php.exe -l view_order.php`
Expected: `No syntax errors detected in view_order.php`

- [ ] **Step 5: Browser E2E — the three core paths**

Logged in as barista (`darasokun` / `@Darasokun2026`) at `https://localhost/cafe/view_order.php`:

1. **Fresh tab:** create a pay-later order (as cashier `Sok_Dara` / `@Sokdara5678` via the menu), then as barista confirm the card shows all its drinks. Tap Complete → card leaves the Preparing queue.
2. **Re-open + add:** as cashier, on that now-Completed tab in `find_order.php?tab=paylater` click **Add Items**, add 2 drinks, confirm. As barista, the card reappears in Preparing showing **only the 2 new drinks** with a purple **Returning tab** badge. Tap Complete → card leaves the queue.
3. **Qty bump:** on a still-Preparing tab, use **Edit** to raise a drink 1→3. Barista card shows all 3 (no filtering, no returning badge). No regression.

Expected: each path behaves as described; no blank cards; no already-made drinks reappear.

- [ ] **Step 6: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): hide made drinks + Returning tab badge on station cards

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Model (`made_at` column) → Task 1. ✓
- Completion stamps delta (`update_status.php`) → Task 2. ✓
- Re-open path unchanged (`confirm_order.php` inserts NULL made_at) → no task needed; INSERT at confirm_order.php:189-211 lists explicit columns and omits `made_at`, so new rows default NULL. Verified by Task 4 Step 5 path 2. ✓
- Barista feed hides made drinks (`is_made`, filter) → Tasks 3 + 4. ✓
- Returning-tab marker → Tasks 3 (`is_returning`) + 4 (badge). ✓
- Migration + backfill → Task 1 (via `_migrate`, supersedes spec's SHOW COLUMNS suggestion — same effect, run-once guarantee). ✓
- Out-of-scope (totals/points/stock/edit page/displays) → untouched; no task. ✓
- Edge: all-made fallback → Task 4 Step 1 (`_shown = _unmade.length ? _unmade : _all`). ✓

**Placeholder scan:** none — every step has concrete code and commands.

**Type consistency:** `is_made` (item, 0/1) and `is_returning` (order, 0/1) defined in Task 3, consumed by exact names in Task 4. `made_at` column name consistent across Tasks 1-3. Migration id `order_items_made_at_v1` consistent Task 1 Steps 1/5.
