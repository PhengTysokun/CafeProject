# Stock Count Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a manager/admin apply a submitted stock count to system inventory — overwriting `ingredients.stock_quantity` with the physical count and logging every change to `ingredient_history` — so the count stops being audit-only.

**Architecture:** New `reconcile` POST action in `stock_count.php` (role-gated, CSRF, single transaction, atomic claim). Two guarded migrations in `config.php` add reconciliation columns to `stock_counts` and a `count_adjust` value to the `ingredient_history.change_type` enum. `ingredient_history.php` learns to classify signed `count_adjust` rows. `reconciliation_report.php` shows Pending/Reconciled per count.

**Tech Stack:** PHP 8 (procedural), mysqli prepared statements, MySQL/MariaDB (XAMPP), vanilla JS + fetch. No unit-test framework — verification is curl E2E + direct DB inspection + browser (admin account **Sokun**), matching this project's established pattern.

**Spec:** `docs/superpowers/specs/2026-07-11-stock-count-reconciliation-design.md` (approved, two review rounds folded in).

## Global Constraints

- Branch: **feat/product-addons** (local only, do NOT push). Commit each task.
- `ingredients.stock_quantity` is **INT** — counted `actual_qty` (DECIMAL) is rounded to nearest int on write; raw value preserved in the log reference.
- `ingredient_history.amount` sign convention: positive magnitude for single-direction types; **`manual_adjust` and now `count_adjust` are SIGNED**. Store `delta = newStock − oldStock`.
- Guarded migrations only, via existing `_migrate($conn, 'id', fn)` helper (config.php:68) backed by `schema_migrations`. Never mutate an already-applied migration id; add a new one. Migrations must be idempotent.
- App uses a single global `$_SESSION['csrf_token']`, bootstrapped lazily with `if (empty(...)) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));`, verified with `hash_equals`. Do NOT namespace it (spec: rejected).
- Apply gate is a **role check** `in_array($_SESSION['role'] ?? '', ['admin','manager'], true)` — NO new permission row. (`$_SESSION['role']` is set in auth.php:164.)
- Overwrite semantics: `stock_quantity = actual_qty` for counted items; uncounted (`actual_qty IS NULL`) untouched; `delta === 0` skipped (no write, no log).

---

### Task 1: Migrations — reconciliation columns + `count_adjust` enum

**Files:**
- Modify: `config.php:263` (base CREATE enum, fresh installs)
- Modify: `config.php` (add two `_migrate(...)` blocks after the `ingredient_history_enum_v1` migration at ~line 274)

**Interfaces:**
- Produces: `stock_counts.reconciled_at DATETIME NULL`, `stock_counts.reconciled_by VARCHAR(100) NULL`; `ingredient_history.change_type` enum gains `'count_adjust'`. Later tasks read/write these.

- [ ] **Step 1: Add `count_adjust` to the base CREATE TABLE enum (fresh installs)**

In `config.php:263`, change:
```php
    change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust') NOT NULL,
```
to:
```php
    change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust') NOT NULL,
```

- [ ] **Step 2: Add the two migrations**

Immediately AFTER the existing `ingredient_history_enum_v1` block (ends config.php:274), insert:
```php
_migrate($conn, 'ingredient_history_count_adjust_v1', function($db) {
    $db->query("ALTER TABLE ingredient_history MODIFY COLUMN change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust') NOT NULL");
});
_migrate($conn, 'stock_counts_reconciled_v1', function($db) {
    $db->query("ALTER TABLE stock_counts
        ADD COLUMN IF NOT EXISTS reconciled_at DATETIME NULL,
        ADD COLUMN IF NOT EXISTS reconciled_by VARCHAR(100) NULL");
});
```
(Note: the `stock_counts` table is created later in config.php, but `_migrate` runs top-to-bottom AFTER the whole file's table creation on the NEXT request; on a fresh DB the CREATE at ~655 runs first this request and the ALTER runs next request. `ADD COLUMN IF NOT EXISTS` is safe either way. If you prefer belt-and-suspenders, you may instead place the `stock_counts_reconciled_v1` block right after the `stock_counts` CREATE at config.php:665 — either location is correct because migrations are id-guarded.)

- [ ] **Step 3: Trigger migrations + verify schema**

Load any page that includes config.php (forces the migration run), then inspect the DB:
```bash
php -r "require 'config.php'; \$r=\$conn->query(\"SHOW COLUMNS FROM ingredient_history LIKE 'change_type'\")->fetch_assoc(); echo \$r['Type'],\"\n\"; \$c=\$conn->query(\"SHOW COLUMNS FROM stock_counts\")->fetch_all(MYSQLI_ASSOC); foreach(\$c as \$col) echo \$col['Field'],' '; echo \"\n\"; print_r(\$conn->query(\"SELECT id FROM schema_migrations WHERE id IN('ingredient_history_count_adjust_v1','stock_counts_reconciled_v1')\")->fetch_all(MYSQLI_NUM));"
```
Expected: enum type string contains `count_adjust`; `stock_counts` columns include `reconciled_at` and `reconciled_by`; both migration ids present.

- [ ] **Step 4: Verify idempotency**

Re-run the same command (or reload the page) once more.
Expected: no errors, same output — migrations not re-applied (guarded by `schema_migrations`).

- [ ] **Step 5: Commit**

```bash
git add config.php
git commit -m "feat(stock-count): migrations for reconciliation cols + count_adjust enum"
```

---

### Task 2: Ledger wiring — classify `count_adjust` in ingredient_history.php

**Files:**
- Modify: `ingredient_history.php:14` (`$valid_types`)
- Modify: `ingredient_history.php:102-105` (SQL aggregation deduct classifier)
- Modify: `ingredient_history.php:514-515` (PHP row `$isDeduct`)
- Modify: `ingredient_history.php:516-523` (PHP `$typeLabel` match)
- Modify: `ingredient_history.php:633-639` (JS `TYPE_META`)
- Modify: `ingredient_history.php:643-644` (JS `buildRow` `isDeduct`)

**Interfaces:**
- Consumes: `count_adjust` enum from Task 1.
- Produces: correct deduction totals + type filter + label/icon for `count_adjust` rows. NOTE: spec named only the SQL spot (102-105); the deduct classifier is duplicated in **4 places** (SQL + PHP + 2× JS) — all four must include `count_adjust AND amount<0`.

- [ ] **Step 1: Add `count_adjust` to `$valid_types`**

`ingredient_history.php:14`, change:
```php
$valid_types = ['order_deduct','order_restore','quick_restock','po_received','manual_adjust'];
```
to:
```php
$valid_types = ['order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust'];
```

- [ ] **Step 2: Extend the SQL aggregation classifier (4 CASE clauses)**

`ingredient_history.php:102-105`. In each of the four `CASE WHEN` conditions, the deduct predicate is currently:
```sql
h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0)
```
Replace **every occurrence** in that SELECT (lines 102, 103, and the two `NOT(...)` on 104, 105) with:
```sql
h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0)
```
Result (lines 102-105):
```php
        SUM(CASE WHEN h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0) THEN 1 ELSE 0 END) AS cnt_deduct,
        SUM(CASE WHEN h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0) THEN ABS(h.amount) ELSE 0 END) AS total_deducted,
        SUM(CASE WHEN NOT(h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0)) THEN 1 ELSE 0 END) AS cnt_add,
        SUM(CASE WHEN NOT(h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0)) THEN ABS(h.amount) ELSE 0 END) AS total_added,
```

- [ ] **Step 3: Extend the PHP row classifier**

`ingredient_history.php:514-515`, change:
```php
                $isDeduct  = $r['change_type'] === 'order_deduct'
                          || ($r['change_type'] === 'manual_adjust' && $rawAmt < 0);
```
to:
```php
                $isDeduct  = $r['change_type'] === 'order_deduct'
                          || ($r['change_type'] === 'manual_adjust' && $rawAmt < 0)
                          || ($r['change_type'] === 'count_adjust' && $rawAmt < 0);
```

- [ ] **Step 4: Add `count_adjust` label to the PHP `$typeLabel` match**

`ingredient_history.php:521`, add a line after the `manual_adjust` entry:
```php
                    'manual_adjust' => ['Manual Adjustment','fa-sliders'],
                    'count_adjust'  => ['Stock Count',       'fa-clipboard-check'],
```

- [ ] **Step 5: Add `count_adjust` to JS `TYPE_META` + `buildRow` classifier**

`ingredient_history.php:638`, add after the `manual_adjust` line:
```js
    manual_adjust: { label: 'Manual Adjustment',icon: 'fa-sliders'          },
    count_adjust:  { label: 'Stock Count',       icon: 'fa-clipboard-check' },
```
Then `ingredient_history.php:643-644`, change:
```js
    const isDeduct = r.change_type === 'order_deduct'
                  || (r.change_type === 'manual_adjust' && rawAmt < 0);
```
to:
```js
    const isDeduct = r.change_type === 'order_deduct'
                  || (r.change_type === 'manual_adjust' && rawAmt < 0)
                  || (r.change_type === 'count_adjust' && rawAmt < 0);
```

- [ ] **Step 6: Verify — no syntax errors + filter accepts count_adjust**

```bash
php -l ingredient_history.php
```
Expected: `No syntax errors detected`.
Then confirm the filter whitelist: grep should show `count_adjust` in `$valid_types`:
```bash
grep -n "count_adjust" ingredient_history.php
```
Expected: matches on lines ~14, 102-105 (×4), 515, 521, 638, 644.

- [ ] **Step 7: Commit**

```bash
git add ingredient_history.php
git commit -m "feat(stock-count): classify signed count_adjust in ledger (filter, totals, label)"
```

---

### Task 3: `reconcile` POST handler + CSRF bootstrap in stock_count.php

**Files:**
- Modify: `stock_count.php:1-4` (CSRF bootstrap after requires)
- Modify: `stock_count.php` (new `reconcile` action block, after the `submit` handler at line 76)

**Interfaces:**
- Consumes: `stock_counts.reconciled_at/reconciled_by` + `count_adjust` enum (Task 1); ledger classifier (Task 2).
- Produces: JSON `{ok:true, adjusted:N, skipped:M}` on success; `{ok:false, msg:...}` on any precondition failure. Writes `ingredients.stock_quantity` + `ingredient_history` rows + sets `reconciled_at/reconciled_by`. Consumed by Task 4 UI.

- [ ] **Step 1: Bootstrap the CSRF token**

`stock_count.php:1-4`, after the two `require` lines and the `can()` gate, add:
```php
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
```
(Session is already started by `auth.php`. This mirrors manage_addons.php:4.)

- [ ] **Step 2: Add the `reconcile` handler**

Insert AFTER the `submit` handler block (after `stock_count.php:76`, before the "LOAD OR CREATE" section). Full block:
```php
/* ══════════════════════════════════════════════
   AJAX: reconcile — apply a submitted count to stock
   (manager/admin only; overwrites ingredients.stock_quantity)
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reconcile') {
    header('Content-Type: application/json');

    // Role gate: counter cannot approve own count; separate from stock_count perm
    if (!in_array($_SESSION['role'] ?? '', ['admin','manager'], true)) {
        echo json_encode(['ok'=>false,'msg'=>'Not authorized to apply counts.']); exit;
    }
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid session token. Reload and try again.']); exit;
    }
    $count_id = (int)($_POST['count_id'] ?? 0);
    if ($count_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid count.']); exit; }
    $by = $_SESSION['username'] ?? '';

    $conn->begin_transaction();
    try {
        // 1) Atomically claim: only if submitted + not yet reconciled (TOCTOU-safe,
        //    mirrors the submit guard at stock_count.php:68). Single-writer POS —
        //    a multi-server deploy would need SELECT ... FOR UPDATE on ingredients.
        $claim = $conn->prepare("UPDATE stock_counts
            SET reconciled_at=NOW(), reconciled_by=?
            WHERE count_id=? AND status='submitted' AND reconciled_at IS NULL");
        $claim->bind_param("si", $by, $count_id);
        $claim->execute();
        if ($claim->affected_rows === 0) {
            $conn->rollback();
            echo json_encode(['ok'=>false,'msg'=>'Already reconciled or not submitted.']); exit;
        }
        $claim->close();

        // fetch business_date for the log reference
        $bd_q = $conn->prepare("SELECT business_date FROM stock_counts WHERE count_id=?");
        $bd_q->bind_param("i", $count_id);
        $bd_q->execute();
        $business_date = $bd_q->get_result()->fetch_assoc()['business_date'] ?? '';
        $bd_q->close();

        // 2) Per counted item: overwrite stock, log signed delta
        $items_q = $conn->prepare("
            SELECT sci.ingredient_id, sci.expected_qty, sci.actual_qty, i.stock_quantity
            FROM stock_count_items sci
            JOIN ingredients i ON i.ingredient_id = sci.ingredient_id
            WHERE sci.count_id=? AND sci.actual_qty IS NOT NULL");
        $items_q->bind_param("i", $count_id);
        $items_q->execute();
        $rows = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);
        $items_q->close();

        $upd = $conn->prepare("UPDATE ingredients SET stock_quantity=? WHERE ingredient_id=?");
        $log = $conn->prepare("INSERT INTO ingredient_history
            (ingredient_id, change_type, amount, order_id, reference, created_by)
            VALUES (?, 'count_adjust', ?, NULL, ?, ?)");

        $adjusted = 0; $skipped = 0;
        foreach ($rows as $r) {
            $iid      = (int)$r['ingredient_id'];
            $newStock = (int)round((float)$r['actual_qty']);
            $oldStock = (int)$r['stock_quantity'];
            $delta    = $newStock - $oldStock;
            if ($delta === 0) { $skipped++; continue; }

            $upd->bind_param("ii", $newStock, $iid);
            $upd->execute();

            $ref = sprintf(
                'Stock count %s #%d: expected %s, counted %s (was %d, now %d)',
                $business_date, $count_id,
                rtrim(rtrim(number_format((float)$r['expected_qty'], 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float)$r['actual_qty'],   4, '.', ''), '0'), '.'),
                $oldStock, $newStock
            );
            $amt = (float)$delta; // SIGNED per convention
            $log->bind_param("idss", $iid, $amt, $ref, $by);
            $log->execute();
            $adjusted++;
        }
        $upd->close();
        $log->close();

        $conn->commit();
        echo json_encode(['ok'=>true,'adjusted'=>$adjusted,'skipped'=>$skipped]);
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['ok'=>false,'msg'=>'Apply failed — nothing changed.']);
    }
    exit;
}
```

- [ ] **Step 3: Verify syntax**

```bash
php -l stock_count.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verify handler rejects unauthorized (curl, no valid session)**

```bash
curl -s -X POST "http://localhost/Cafe/stock_count.php" -d "action=reconcile&count_id=1"
```
Expected: redirected to login OR JSON `{"ok":false,...}` — NOT a stock write. (Real happy-path test is Task 6 with a logged-in admin session.)

- [ ] **Step 5: Commit**

```bash
git add stock_count.php
git commit -m "feat(stock-count): reconcile handler — role-gated, CSRF, atomic claim, txn"
```

---

### Task 4: stock_count.php UI — Apply button / reconciled banner / zero-count message

**Files:**
- Modify: `stock_count.php` (near the locked-banner block ~425-430, and the `<script>` head ~632)

**Interfaces:**
- Consumes: `reconcile` action (Task 3); `$session['reconciled_at']`, `$session['reconciled_by']` (loaded by the existing `SELECT *` at stock_count.php:81/130).
- Produces: an **Apply to stock** button (manager/admin + submitted + not reconciled + ≥1 counted item), a "Reconciled by … at …" banner (already reconciled), or a "No counted items to apply." note (submitted, zero counted). Clerks never see the button.

- [ ] **Step 1: Compute UI flags in PHP**

After `stock_count.php:137` (`$is_locked = ...`), add:
```php
$is_manager     = in_array($_SESSION['role'] ?? '', ['admin','manager'], true);
$is_reconciled  = !empty($session['reconciled_at']);
```
(`$counted_count` is computed later at line 154; the button block below runs in the body AFTER that, so it's available.)

- [ ] **Step 2: Render the Apply UI**

Replace the existing locked-banner block at `stock_count.php:425-430`:
```php
<?php if ($is_locked): ?>
<div class="locked-banner">
    <i class="fa-solid fa-circle-check"></i>
    Count submitted and locked. View-only mode.
</div>
<?php endif; ?>
```
with:
```php
<?php if ($is_locked): ?>
<div class="locked-banner">
    <i class="fa-solid fa-circle-check"></i>
    Count submitted and locked. View-only mode.
</div>
    <?php if ($is_reconciled): ?>
    <div class="locked-banner" style="background:rgba(96,165,250,.1);border-color:rgba(96,165,250,.25);color:#93c5fd;">
        <i class="fa-solid fa-clipboard-check" style="color:#60a5fa;"></i>
        Reconciled by <strong style="color:#bfdbfe;">&nbsp;<?= h($session['reconciled_by'] ?? '') ?>&nbsp;</strong>
        at <strong style="color:#bfdbfe;">&nbsp;<?= h(date('g:i A, M j', strtotime($session['reconciled_at']))) ?></strong>
    </div>
    <?php elseif ($is_manager && $counted_count > 0): ?>
    <div class="locked-banner" style="background:var(--amber-dim);border-color:var(--amber-border);color:#fbbf24;justify-content:space-between;">
        <span><i class="fa-solid fa-scale-balanced" style="color:var(--amber);"></i>
            Review complete? Apply these counts to system stock.</span>
        <button class="submit-btn" id="applyBtn" onclick="applyReconcile()" style="padding:8px 18px;">
            <i class="fa-solid fa-check-double"></i> Apply to Stock
        </button>
    </div>
    <?php elseif ($is_manager): ?>
    <div class="locked-banner" style="background:rgba(255,255,255,.04);border-color:var(--border);color:var(--muted2);">
        <i class="fa-solid fa-circle-info"></i> No counted items to apply.
    </div>
    <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 3: Emit CSRF token + counted count to JS**

At `stock_count.php:632`, after `const COUNT_ID = ...;`, add:
```js
const CSRF_TOKEN  = <?= json_encode($_SESSION['csrf_token']) ?>;
const COUNTED_NOW = <?= (int)$counted_count ?>;
```

- [ ] **Step 4: Add the `applyReconcile()` JS function**

Before the final `goPage(1);` line (`stock_count.php:853`), add:
```js
/* ── Reconcile (manager/admin) ── */
function applyReconcile() {
    const btn = document.getElementById('applyBtn');
    if (!confirm(`Set system stock to the counted values for ${COUNTED_NOW} counted item(s)? `
              + `This adjusts inventory and is logged. Uncounted items are left unchanged.`)) return;
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border:2px solid rgba(0,0,0,.2);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></div> Applying…';
    const fd = new FormData();
    fd.append('action', 'reconcile');
    fd.append('count_id', COUNT_ID);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('stock_count.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                alert(`Reconciled — ${d.adjusted} adjusted, ${d.skipped} unchanged.`);
                document.body.classList.add('fading');
                setTimeout(() => location.reload(), 400);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Apply to Stock';
                alert('Error: ' + (d.msg || 'Unknown error'));
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Apply to Stock';
            alert('Network error — nothing changed.');
        });
}
```

- [ ] **Step 5: Verify syntax**

```bash
php -l stock_count.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add stock_count.php
git commit -m "feat(stock-count): Apply-to-stock button, reconciled banner, zero-count note"
```

---

### Task 5: reconciliation_report.php — Pending / Reconciled column

**Files:**
- Modify: `reconciliation_report.php:27-46` (`$sc_rows` SELECT)
- Modify: `reconciliation_report.php:547-557` (table header)
- Modify: `reconciliation_report.php:559-582+` (row render — add cell)

**Interfaces:**
- Consumes: `sc.reconciled_at`, `sc.reconciled_by` (Task 1).
- Produces: a "Reconciled" column showing a pill per submitted row (Reconciled + name, or Pending); drafts show neither.

- [ ] **Step 1: Add columns to the `$sc_rows` SELECT**

`reconciliation_report.php:32-33`, after `sc.submitted_at,` add the two columns:
```php
            sc.submitted_by,
            sc.submitted_at,
            sc.reconciled_at,
            sc.reconciled_by,
            sc.notes,
```

- [ ] **Step 2: Add the header cell**

`reconciliation_report.php:553-555`, after the `Submitted By` header and before `Notes`, add a `Reconciled` column:
```php
                    <th>Submitted By</th>
                    <th>Reconciled</th>
                    <th>Notes</th>
```

- [ ] **Step 3: Render the pill cell**

Find the row's "Submitted By" `<td>` inside the `foreach ($sc_rows as $scr)` loop (after line 582). After that `<td>`, add a new cell BEFORE the Notes cell:
```php
                <td>
                    <?php if ($scr['status'] !== 'submitted'): ?>
                        <span style="color:var(--muted2)">—</span>
                    <?php elseif (!empty($scr['reconciled_at'])): ?>
                        <span class="badge match" title="<?= date('d M Y, g:i A', strtotime($scr['reconciled_at'])) ?>">
                            <i class="fa-solid fa-clipboard-check"></i> <?= htmlspecialchars($scr['reconciled_by'] ?? '', ENT_QUOTES) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background:var(--amber-dim,rgba(209,144,75,.12));color:#fbbf24;border:1px solid rgba(209,144,75,.25)">
                            <i class="fa-solid fa-hourglass-half"></i> Pending
                        </span>
                    <?php endif; ?>
                </td>
```
(If `reconciliation_report.php` uses a different escape helper — check its top for `he()`/`h()` — match it; otherwise `htmlspecialchars` is fine.)

- [ ] **Step 4: Verify syntax + column count**

```bash
php -l reconciliation_report.php
```
Expected: `No syntax errors detected`. Manually confirm the added `<td>` count matches the added `<th>` (one new column) so the table stays aligned.

- [ ] **Step 5: Commit**

```bash
git add reconciliation_report.php
git commit -m "feat(stock-count): Pending/Reconciled column in reconciliation report"
```

---

### Task 6: End-to-end verification (browser + DB)

**Files:** none (verification only).

No new code — drive the full flow as the project's pattern requires and confirm real DB effects. Use test accounts from memory (admin **Sokun**; a clerk account for the permission test).

- [ ] **Step 1: Seed a submitted count with a known variance**

In the browser (or via DB), ensure a `stock_counts` row for some date is `status='submitted'` with at least one `stock_count_items.actual_qty` differing from the ingredient's current `stock_quantity`, and at least one `delta = 0` item, and at least one uncounted (`actual_qty IS NULL`). Record the `count_id` and the affected `ingredient_id`s + their current `stock_quantity`.

- [ ] **Step 2: Happy path — Apply as admin**

Log in as **Sokun** (admin). Open `stock_count.php?date=<that date>`. Confirm the **Apply to Stock** button shows. Click it, confirm the dialog, accept.
Expected: banner "Reconciled — N adjusted, M unchanged"; page reloads to "Reconciled by Sokun at …".

- [ ] **Step 3: Verify DB effects**

```bash
php -r "require 'config.php'; \$cid=CID; \$r=\$conn->query(\"SELECT reconciled_at,reconciled_by FROM stock_counts WHERE count_id=\$cid\")->fetch_assoc(); print_r(\$r); print_r(\$conn->query(\"SELECT ingredient_id,change_type,amount,reference,created_by FROM ingredient_history WHERE change_type='count_adjust' AND reference LIKE '%#\$cid:%' ORDER BY id DESC\")->fetch_all(MYSQLI_ASSOC));"
```
(Replace `CID` with the real count_id.)
Expected: `reconciled_at`/`reconciled_by` set; one `count_adjust` row **per non-zero-delta counted item** with a **signed** `amount` (negative when counted < system), a self-describing reference (`expected … counted … was … now …`); NO row for the zero-delta or uncounted items. Separately confirm each affected `ingredients.stock_quantity` now equals the rounded `actual_qty`.

- [ ] **Step 4: Sign / ledger view**

Open `ingredient_history.php`. Confirm the `count_adjust` rows render with the "Stock Count" label, a negative count adjustment appears under **deductions** (red/`amount-neg`), and `count_adjust` is selectable in the type filter and filters correctly.

- [ ] **Step 5: Apply-once + report**

Re-submit the `reconcile` POST for the same count (e.g. reload isn't enough — use curl with a fresh admin session cookie, or click a stale button) → expect `{"ok":false,"msg":"Already reconciled or not submitted."}` and no further history rows. Open `reconciliation_report.php`: the count shows the **Reconciled** pill with "Sokun"; other submitted-but-unapplied counts show **Pending**; drafts show "—".

- [ ] **Step 6: Permission + zero-count guards**

As a **clerk** (non-manager) account, open the same reconciled/other submitted count → no Apply button; a direct `reconcile` POST is rejected ("Not authorized"). Open a submitted count where every `actual_qty IS NULL` → "No counted items to apply." shown, no button.

- [ ] **Step 7: Final commit (if any doc/notes updated)**

If verification surfaced fixes, commit them. Otherwise no commit — verification is observation.

---

## Notes for the implementer

- **Do not** retrofit CSRF onto the existing `save_item`/`submit` actions — explicitly out of scope (spec §CSRF note), noted as an adjacent gap.
- **Do not** add a separate reconciliation-log table, compare-and-swap, or a namespaced CSRF key — all three were considered and rejected in the spec (§Review decisions). `reconciled_by`/`reconciled_at` + per-item `count_adjust` rows are the complete audit trail.
- Deferred (NOT in this plan): time-gap warning, partial reconcile, large-variance highlighting, rounding preview. Don't add them.
- If the repo has no `manager` role yet, the `['admin','manager']` gate simply resolves to admin-only — correct and future-proof; leave it.
