# Per-Drink Made Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track "made" per drink (a count per order-item), and on the barista station render each drink as its own line — made ones dimmed, unmade ones tappable — auto-completing the order when the last drink is tapped.

**Architecture:** Add `order_items.made_qty` (0…quantity). The barista feed ships it; the card renders `quantity` lines per item and dims the first `made_qty`. A new `action=mark_made` endpoint (locks the whole order's rows) increments/decrements a row's `made_qty`, recomputes `made_at`, and auto-completes when no row has unmade units. Supersedes the previous hide-made behaviour.

**Tech Stack:** PHP 8 + MySQL (mysqli), vanilla JS. No test framework — verify with `php -l`, direct MySQL, and browser E2E (Playwright).

## Global Constraints

- Branch: `feat/product-addons` (local, not pushed).
- MySQL DB: `db_coffee`. CLI: `/c/xampp/mysql/bin/mysql -uroot db_coffee`.
- Schema changes go through the existing `_migrate($conn,'<id>',fn)` helper in `config.php` (tracked in `schema_migrations`, runs once). Do NOT hand-write `SHOW COLUMNS` guards.
- App forces HTTPS. If Playwright cert-blocks, first navigate to `http://localhost/...` (redirects to https) to clear the cached cert-error state. Authenticated curl alt: `curl -sk -c jar -d "username=Sokun&password=@Sokun9811" https://localhost/cafe/login.php` (no CSRF), then `curl -sk -b jar "https://localhost/cafe/view_order.php?action=fetch"`.
- Lint every touched PHP file: `/c/xampp/php/php.exe -l <file>`.
- Barista login: `darasokun` / `@Darasokun2026`. Admin: `Sokun` / `@Sokun9811`.
- Do NOT restart Apache from CLI (user restarts via XAMPP Control Panel). Migrations apply on next page load.
- The barista Complete button hits `view_order.php?action=complete` — NOT `update_status.php`. Any completion logic lives in the `action=complete` handler (~line 3126) and the new `action=mark_made`.
- A row is fully made when `made_qty >= quantity`. The order is fully made when **no row has `made_qty < quantity`**. `made_at` is audit/ordering only — no logic branches on it.

---

### Task 1: Add `made_qty` column + backfill

**Files:**
- Modify: `config.php` (after the `order_items_made_at_v1` migration, ~line 124)

**Interfaces:**
- Produces: `order_items.made_qty INT NOT NULL DEFAULT 0` — units of that row already made (0…quantity). Rows already fully made (`made_at` set by the prior feature) are backfilled to `quantity`.

- [ ] **Step 1: Add the migration**

In `config.php`, immediately after the `order_items_made_at_v1` migration block, add:

```php
// Per-drink made tracking: made_qty = how many units of a row are made (0..quantity).
// Backfill rows already fully made (made_at set) to their full quantity.
_migrate($conn, 'order_items_made_qty_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS made_qty INT NOT NULL DEFAULT 0");
    $db->query("UPDATE order_items SET made_qty = quantity WHERE made_at IS NOT NULL AND made_qty = 0");
});
```

- [ ] **Step 2: Lint**

Run: `/c/xampp/php/php.exe -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 3: Trigger + verify column**

Run:
```bash
curl -sk https://localhost/cafe/login.php -o /dev/null
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "SHOW COLUMNS FROM order_items LIKE 'made_qty';"
```
Expected: one row, `made_qty  int ... NO ... 0`.

- [ ] **Step 4: Verify backfill**

Run:
```bash
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "
SELECT SUM(made_at IS NOT NULL AND made_qty=quantity) AS made_rows_ok,
       SUM(made_at IS NOT NULL AND made_qty<>quantity) AS made_rows_bad
FROM order_items;"
```
Expected: `made_rows_bad = 0` (every fully-made row has `made_qty = quantity`).

- [ ] **Step 5: Commit**

```bash
git add config.php
git commit -m "feat(barista): add order_items.made_qty column + backfill

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Expose `made_qty` and count-derived `is_made` in the feed

**Files:**
- Modify: `view_order.php` — item SELECT (~line 2911) + GROUP BY (~line 2932) + item builder (~line 2993)

**Interfaces:**
- Consumes: `order_items.made_qty` (Task 1).
- Produces: each feed item gains `"made_qty"` (int) and `"is_made"` (0/1) now derived from `made_qty >= quantity`. `is_returning` (order-level) unchanged in meaning, but computed off the count-derived `is_made`.

- [ ] **Step 1: Add `oi.made_qty` to the SELECT and GROUP BY**

The item columns already include `oi.made_at,` (added by the prior feature). Add `oi.made_qty,` right after it in the SELECT list (~line 2912):

```php
            oi.made_at,
            oi.made_qty,
            oi.product_id,
```

And in the `GROUP BY` (~line 2932), add `oi.made_qty,` after `oi.made_at,`:

```php
        GROUP BY o.order_id, oi.item_id, oi.product_name, oi.sweetness, oi.ice, oi.milk, oi.size_label, oi.addons_snapshot, oi.quantity, oi.made_at, oi.made_qty, oi.product_id, p.category
```

- [ ] **Step 2: Derive `is_made` from the count + ship `made_qty`**

Replace the item-build block (currently ~line 2993-3010):

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

with (is_made now off the count; made_qty shipped):

```php
        if (!empty($r['product_name'])) {
            $qty      = (int)$r["quantity"];
            $made_qty = (int)$r["made_qty"];
            $is_made  = ($qty > 0 && $made_qty >= $qty) ? 1 : 0;   // row fully made — from the count, not made_at
            $item = [
                "item_id"      => (int)$r["item_id"],
                "product_name" => $r["product_name"],
                "size"         => $r["size_label"],
                "sweetness"    => $r["sweetness"],
                "ice"          => $r["ice"],
                "milk"         => $r["milk"],
                "addons"       => array_map(fn($a) => $a['name'], json_decode($r["addons_snapshot"] ?? '[]', true) ?: []),
                "quantity"     => $qty,
                "made_qty"     => $made_qty,
                "is_made"      => $is_made
            ];
            if ($__isBarista) { $item["category"] = $r["category"] ?? ''; }
            $map[$id]["items"][] = $item;
            // A Preparing order that already has a fully-made row = a re-opened ("returning") tab.
            if ($is_made && $map[$id]["status"] === 'Preparing') {
                $map[$id]["is_returning"] = 1;
            }
        }
```

- [ ] **Step 3: Lint**

Run: `/c/xampp/php/php.exe -l view_order.php`
Expected: `No syntax errors detected in view_order.php`

- [ ] **Step 4: Verify payload**

Run:
```bash
J=/tmp/jar; curl -sk -c $J -d "username=Sokun&password=@Sokun9811" https://localhost/cafe/login.php -o /dev/null
curl -sk -b $J "https://localhost/cafe/view_order.php?action=fetch" | python -c "
import json,sys
d=json.load(sys.stdin)
for o in d['orders'][:5]:
    print(o['daily_order_no'], o['status'], [(i.get('made_qty'),i['quantity'],i['is_made']) for i in o.get('items',[])])
"
```
Expected: each item shows `(made_qty, quantity, is_made)`; `is_made` is 1 exactly when `made_qty >= quantity`.

- [ ] **Step 5: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): feed ships made_qty + count-derived is_made

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: `mark_made` endpoint (tap a drink)

**Files:**
- Modify: `view_order.php` — add a new `if ($action === "mark_made")` block immediately before the `if ($action === "complete")` block (~line 3085)

**Interfaces:**
- Consumes: `order_items.made_qty`, `quantity` (Task 1).
- Produces: endpoint `view_order.php?action=mark_made` (GET), params `item_id` (int), `delta` (±1). Returns JSON `{ok:1, made_qty:<int>, completed:0|1}` or `{ok:0, error:"..."}`. Auto-completes the order (status Completed, is_open=0, completed_at, prepared_by) when no row of the order has `made_qty < quantity`.

- [ ] **Step 1: Add the endpoint**

In `view_order.php`, immediately BEFORE the `/* ==== COMPLETE ORDER ==== */` comment / `if ($action === "complete")` block (~line 3082), insert:

```php
/* ===============================
   MARK ONE DRINK MADE / UNDO
================================ */
if ($action === "mark_made") {
    header('Content-Type: application/json');
    if (!can('barista_station')) { http_response_code(403); echo json_encode(["ok"=>0,"error"=>"Not allowed"]); exit; }

    $item_id = (int)($_GET['item_id'] ?? 0);
    $delta   = (int)($_GET['delta'] ?? 0);
    if ($item_id <= 0 || ($delta !== 1 && $delta !== -1)) {
        echo json_encode(["ok"=>0,"error"=>"Invalid request"]); exit;
    }

    $conn->begin_transaction();
    try {
        // Which order does this item belong to?
        $st = $conn->prepare("SELECT order_id FROM order_items WHERE item_id = ?");
        $st->bind_param("i", $item_id); $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) { throw new Exception("Item not found"); }
        $order_id = (int)$row['order_id'];

        // Lock the order row + ALL its item rows so concurrent taps (and a concurrent
        // whole-order Complete) serialise before the auto-complete UPDATE.
        $lockOrd = $conn->prepare("SELECT order_id FROM orders WHERE order_id = ? FOR UPDATE");
        $lockOrd->bind_param("i", $order_id); $lockOrd->execute(); $lockOrd->get_result();
        $lock = $conn->prepare("SELECT item_id, quantity, made_qty FROM order_items WHERE order_id = ? FOR UPDATE");
        $lock->bind_param("i", $order_id); $lock->execute();
        $rows = $lock->get_result()->fetch_all(MYSQLI_ASSOC);

        // Apply delta to the tapped row (clamped to [0, quantity]); recompute its made_at.
        $new_made = 0;
        foreach ($rows as $r) {
            if ((int)$r['item_id'] !== $item_id) continue;
            $qty      = (int)$r['quantity'];
            $new_made = max(0, min($qty, (int)$r['made_qty'] + $delta));
            $made_at_sql = ($new_made >= $qty && $qty > 0) ? "NOW()" : "NULL";
            $up = $conn->prepare("UPDATE order_items SET made_qty = ?, made_at = $made_at_sql WHERE item_id = ?");
            $up->bind_param("ii", $new_made, $item_id); $up->execute();
        }

        // Order fully made = no row still has unmade units.
        $chk = $conn->prepare("SELECT COUNT(*) AS unmade FROM order_items WHERE order_id = ? AND made_qty < quantity");
        $chk->bind_param("i", $order_id); $chk->execute();
        $unmade = (int)$chk->get_result()->fetch_assoc()['unmade'];

        $completed = 0;
        if ($unmade === 0) {
            // Only complete an order that is still open/preparing (don't touch terminal states).
            $prepared_by      = $_SESSION['username'] ?? '';
            $prepared_by_role = $_SESSION['role']     ?? '';
            $cu = $conn->prepare("UPDATE orders SET status = 'Completed', is_open = 0, completed_at = NOW(), prepared_by = ?, prepared_by_role = ? WHERE order_id = ? AND status = 'Preparing'");
            $cu->bind_param("ssi", $prepared_by, $prepared_by_role, $order_id); $cu->execute();
            $completed = $cu->affected_rows > 0 ? 1 : 0;
        }

        $conn->commit();
        echo json_encode(["ok"=>1, "made_qty"=>$new_made, "completed"=>$completed]);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["ok"=>0, "error"=>$e->getMessage()]);
        exit;
    }
}
```

- [ ] **Step 2: Lint**

Run: `/c/xampp/php/php.exe -l view_order.php`
Expected: `No syntax errors detected in view_order.php`

- [ ] **Step 3: Verify against a real Preparing order (controller runs authenticated)**

Pick a Preparing order and one of its items:
```bash
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "
SELECT oi.item_id, oi.product_name, oi.quantity, oi.made_qty, o.order_id, o.status
FROM orders o JOIN order_items oi ON oi.order_id=o.order_id
WHERE o.status='Preparing' AND o.business_date=CURDATE() LIMIT 5;"
```
Log in as barista in the browser, then hit (replace `<IID>`):
```
https://localhost/cafe/view_order.php?action=mark_made&item_id=<IID>&delta=1
```
Expected JSON: `{"ok":1,"made_qty":1,"completed":0}` (or `completed":1` if it was the last drink). Then confirm in DB the row's `made_qty` incremented and, when it hit quantity, `made_at` is set. Send `delta=-1` and confirm it decrements and `made_at` returns to NULL.

- [ ] **Step 4: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): mark_made endpoint — tap a drink, auto-complete on last

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Complete-all also marks every drink made

**Files:**
- Modify: `view_order.php` — the `action=complete` handler (~line 3133, the existing `made_at` stamp)

**Interfaces:**
- Consumes: `order_items.made_qty`, `quantity`.
- Produces: pressing the whole-order Complete button sets `made_qty = quantity` on every row (so per-unit state agrees with the completed order).

- [ ] **Step 1: Set made_qty on complete-all**

In the `action=complete` handler, the existing stamp is:

```php
        // Stamp the drinks made at this completion — only those still unmade. First completion
        // stamps all items; a re-opened pay-later tab stamps only its newly-added rows. This is
        // the primary barista "Complete" path (the button hits action=complete, not update_status.php).
        $stmt_made = $conn->prepare("UPDATE order_items SET made_at = NOW() WHERE order_id = ? AND made_at IS NULL");
        $stmt_made->bind_param("i", $order_id);
        $stmt_made->execute();
```

Replace with (also bring made_qty up to quantity for any partially/unmade row):

```php
        // Whole-order Complete = every drink made. Stamp made_at on still-unmade rows and
        // bring made_qty up to quantity so per-unit state agrees with the completed order.
        $stmt_made = $conn->prepare("UPDATE order_items SET made_qty = quantity, made_at = NOW() WHERE order_id = ? AND made_qty < quantity");
        $stmt_made->bind_param("i", $order_id);
        $stmt_made->execute();
```

- [ ] **Step 2: Lint**

Run: `/c/xampp/php/php.exe -l view_order.php`
Expected: `No syntax errors detected in view_order.php`

- [ ] **Step 3: Verify**

Log in as barista, find a Preparing order with a partially-made item (set one via Task 3's mark_made, `made_qty=1` of `quantity=2`), press Complete in the UI (or hit `view_order.php?action=complete&id=<OID>`). Then:
```bash
/c/xampp/mysql/bin/mysql -uroot db_coffee -e "SELECT item_id, quantity, made_qty, made_at FROM order_items WHERE order_id=<OID>;"
```
Expected: every row `made_qty = quantity`, `made_at` set; order status `Completed`.

- [ ] **Step 4: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): whole-order Complete sets made_qty=quantity on all rows

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Barista card renders per-drink lines (dim + tap)

**Files:**
- Modify: `view_order.php` — `buildBaristaCardInner` items block (~line 1951-1963), a new `markDrink` JS function (near `completeOrder`, ~line 2637), and one CSS rule near `.bitem` styles.

**Interfaces:**
- Consumes: `i.item_id`, `i.quantity`, `i.made_qty` per feed item (Task 2); endpoint `action=mark_made` (Task 3).

- [ ] **Step 1: Render each drink as its own line, dim the made ones**

In `buildBaristaCardInner`, replace the current items block:

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

with (one line per unit; first `made_qty` dimmed; tap toggles):

```php
    // One tappable line per drink. The first made_qty units of a row are dimmed (made);
    // tap an active line to mark it made, tap a made line to undo. No hiding — dim instead.
    const _lines = [];
    (o.items || []).forEach(i => {
        const qty  = Number(i.quantity)  || 0;
        const made = Number(i.made_qty)  || 0;
        for (let u = 0; u < qty; u++) {
            const isMade = u < made;
            const delta  = isMade ? -1 : 1;
            _lines.push(`
        <div class="bitem${isMade ? ' bitem-made' : ''}" onclick="markDrink(${Number(i.item_id)}, ${delta}, this)">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="bitem-name">${isMade ? '<i class="fa-solid fa-check" style="color:var(--success);margin-right:5px"></i>' : ''}1× ${escapeHtml(i.product_name)}</span>
                ${i.category ? `<span class="bcat">${escapeHtml(i.category)}</span>` : ''}
            </div>
            <div class="bchips">${baristaItemChips(i)}</div>
        </div>`);
        }
    });
    const items = _lines.join('') || '<div style="color:var(--text-muted);font-size:12px;padding-left:8px">No items</div>';
```

- [ ] **Step 2: Add the `markDrink` JS (optimistic + revert on failure)**

Immediately before `async function completeOrder(id) {` (~line 2637), add:

```javascript
// Tap a drink line on the barista card: mark it made (dim) or undo. Optimistic —
// revert immediately on failure. On the last drink the order auto-completes.
let _markBusy = false;
async function markDrink(itemId, delta, el) {
    if (_markBusy) return;
    _markBusy = true;
    if (el) el.style.pointerEvents = 'none';
    const wasMade = el && el.classList.contains('bitem-made');
    if (el) el.classList.toggle('bitem-made', delta === 1);   // optimistic dim/un-dim
    try {
        const r = await fetch(`view_order.php?action=mark_made&item_id=${itemId}&delta=${delta}`, { cache: 'no-store' });
        const res = await r.json();
        if (!res.ok) throw new Error(res.error || 'failed');
        if (res.completed) { showToast('✅ Order completed'); }
        // Reconcile in its own try — a failed poll must NOT revert a mark the server accepted;
        // the regular 30s poll will reconcile shortly anyway.
        try { await loadOrders(); } catch (e) { /* self-heals on next poll */ }
    } catch (err) {
        if (el) el.classList.toggle('bitem-made', wasMade);   // revert immediately
        showToast('❌ ' + (err.message || 'Request failed'), 'error');
    } finally {
        _markBusy = false;
        if (el) el.style.pointerEvents = '';
    }
}
```

- [ ] **Step 3: Add the CSS**

Search `view_order.php` for `.bitem {` (the barista item style). Immediately after that rule, add:

```css
    .bitem { cursor: pointer; transition: opacity .15s, background .15s; }
    .bitem:hover { background: rgba(255,255,255,.03); }
    .bitem-made { opacity: .4; }
    .bitem-made:hover { background: transparent; }
```

(If `.bitem` already sets `cursor`/`transition`, merge rather than duplicate — keep one `.bitem` base rule and add the `-made` + hover rules.)

- [ ] **Step 4: Lint**

Run: `/c/xampp/php/php.exe -l view_order.php`
Expected: `No syntax errors detected in view_order.php`

- [ ] **Step 5: Browser E2E (barista)**

Log in as barista (`darasokun`/`@Darasokun2026`) at `https://localhost/cafe/view_order.php`. Use a Preparing order with ≥2 drinks (create one as cashier if needed).

1. **Dim one:** tap a drink line → it dims + shows a green check; order stays in queue. DB: that item's `made_qty` incremented.
2. **Undo:** tap the dimmed line → it re-activates; `made_qty` back down.
3. **Auto-complete:** tap every remaining drink → on the last, toast "Order completed" and the card leaves the Preparing queue. DB: order `Completed`, all `made_qty = quantity`.
4. **Added drink appears:** re-open a completed tab (Add Items, +1 drink) → its old drinks show dimmed, the new one active; tap the new one → completes.

- [ ] **Step 6: Commit**

```bash
git add view_order.php
git commit -m "feat(barista): per-drink lines — tap to dim/undo, made drinks greyed

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- `made_qty` column + backfill → Task 1. ✓
- Feed ships `made_qty` + count-derived `is_made`; `is_returning` off the count → Task 2. ✓
- `mark_made` endpoint: lock all order rows, clamp, recompute `made_at`, auto-complete when no row `< quantity` → Task 3. ✓
- Complete-all sets `made_qty = quantity` → Task 4. ✓
- Barista card per-unit dim + tap, `markDrink` optimistic + revert, CSS → Task 5. ✓
- Point-2 fix (added qty appears) → falls out of Task 5's per-unit render; verified Task 5 Step 5.4. ✓
- Out of scope (totals/points/stock/manager view/edit page/partial-serve) → untouched; no task. ✓

**Placeholder scan:** none — every step has concrete code + commands.

**Type consistency:** `made_qty` (int), `is_made` (0/1), endpoint `action=mark_made(item_id, delta)` returning `{ok,made_qty,completed}`, JS `markDrink(itemId, delta, el)` — consistent across Tasks 2/3/5. Migration id `order_items_made_qty_v1` consistent Task 1. `made_at` recompute rule (`NOW()` at full, `NULL` below) consistent Tasks 3 & 4.
