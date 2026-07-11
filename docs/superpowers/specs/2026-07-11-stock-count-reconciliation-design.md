# Stock Count Reconciliation — Design

**Date:** 2026-07-11
**Branch context:** feat/product-addons (local, not pushed)
**Status:** Approved, ready for implementation plan

## Problem

Submitting a stock count today is **audit-only**: it records each ingredient's
counted `actual_qty` and the `variance` (actual − expected), but it never writes
back to `ingredients.stock_quantity`. So the system stock stays wrong after a
count — `expected_qty` is wrong at the next count, low-stock alerts fire on
phantom numbers, and recipes deduct from a stale base. The count flags
discrepancies but never corrects them.

Add a **reconcile** step: a manager/admin reviews a submitted count and applies it,
setting system stock to the counted values, with every change logged.

## Roles & flow (two-person control)

- **Clerk** (perm `stock_count`) counts + submits — unchanged. Submitting locks the
  session (`status='submitted'`) as the audit record.
- **Manager/admin** opens the submitted count, reviews the per-line variances, and
  clicks **Apply to stock**. The counter cannot approve their own count: the Apply
  action is gated to `admin`/`manager` roles, separate from the `stock_count`
  permission that lets clerks count.

## Semantics

Apply **overwrites** system stock to the physical count: for each counted item,
`ingredients.stock_quantity = actual_qty`. This matches "make the system match the
shelf." `ingredients.stock_quantity` is `INT`, so the counted value is rounded to
the nearest integer on write (counts are whole units in practice; `actual_qty` is
stored `DECIMAL`).

**Caveat (documented, accepted):** overwrite ignores any orders placed between
*submit* and *apply* — those deductions are overwritten. For end-of-day counts
applied the same day this drift is negligible. Guidance: apply promptly after
submit. (A variance-delta approach was considered and rejected as less intuitive
for the cafe's end-of-day workflow.)

Uncounted items (`actual_qty IS NULL`) are left untouched. Items whose counted
value already equals current stock (`delta = 0`) are skipped (no write, no log
noise).

## Data model (2 migrations, guarded, in config.php)

1. `stock_counts_reconciled_v1`:
   `ALTER TABLE stock_counts
      ADD COLUMN IF NOT EXISTS reconciled_at DATETIME NULL,
      ADD COLUMN IF NOT EXISTS reconciled_by VARCHAR(100) NULL`
   `reconciled_at IS NULL` = pending; set once when applied.

2. `ingredient_history_count_adjust_v1`:
   add a `count_adjust` value to the `ingredient_history.change_type` enum
   (`MODIFY COLUMN change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust') NOT NULL`).
   A dedicated type keeps count adjustments filterable/reportable apart from generic
   `manual_adjust`. The migration reads the current enum and only adds the value if
   absent (idempotent).

### `ingredient_history.amount` sign convention (must match existing)

The existing convention (verified in confirm_order.php:648 and the aggregation in
ingredient_history.php:102-105): `amount` is stored as a **positive magnitude** for
single-direction types (`order_deduct`, `po_received`, …), while **`manual_adjust`
stores a SIGNED amount** — the report explicitly treats `manual_adjust AND amount<0`
as a deduction and uses `ABS(amount)` for totals.

`count_adjust` is bidirectional (a count can raise or lower stock), so it follows the
`manual_adjust` precedent: store the **signed** `delta` (`newStock − oldStock`). To
keep the ledger totals correct, two edits to `ingredient_history.php`:
- Add `count_adjust` to `$valid_types` (line 14) so it appears in the type filter.
- Extend the deduct classification (lines 102-105) from
  `change_type='order_deduct' OR (change_type='manual_adjust' AND amount<0)` to also
  include `OR (change_type='count_adjust' AND amount<0)`, mirroring `manual_adjust`,
  so a negative count adjustment counts as a deduction (not an addition) and `ABS` is
  applied to its magnitude.

No new permission row: the Apply gate is a role check
(`in_array($_SESSION['role'] ?? '', ['admin','manager'], true)`).

## Apply handler (new POST action `reconcile` in stock_count.php)

Preconditions (all required, else reject):
- `admin`/`manager` role (403/redirect otherwise).
- Valid CSRF token (see CSRF note below).
- Target session exists, `status='submitted'`, and `reconciled_at IS NULL`.

Steps, wrapped in a single transaction (all-or-nothing):
1. Atomically claim the session:
   `UPDATE stock_counts SET reconciled_at=NOW(), reconciled_by=? WHERE count_id=? AND status='submitted' AND reconciled_at IS NULL`.
   If `affected_rows = 0` → already reconciled or not submitted; roll back and return
   "Already reconciled or not submitted." (TOCTOU-safe, mirrors the existing submit
   guard at stock_count.php:68.)
2. For each `stock_count_items` row of this count with `actual_qty IS NOT NULL`:
   - `newStock = (int)round(actual_qty)`; read current `ingredients.stock_quantity` as `oldStock`.
     (`stock_quantity` is `INT`; the counted value is rounded to a whole unit for the
     stock write. The raw `actual_qty` is preserved verbatim in the log reference below,
     so a fractional count is never silently lost.)
   - `delta = newStock − oldStock`; if `delta === 0`, skip (no write, no log).
   - `UPDATE ingredients SET stock_quantity = ? WHERE ingredient_id = ?` (newStock).
   - `INSERT INTO ingredient_history (ingredient_id, change_type, amount, order_id, reference, created_by)
      VALUES (?, 'count_adjust', ?, NULL, ?, ?)` where `amount = delta` (SIGNED, per the
      convention above) and
      `reference = "Stock count <business_date> #<count_id>: expected <expected_qty>, counted <actual_qty> (was <oldStock>, now <newStock>)"`,
      `created_by = manager username`. Including `was → now` makes each row self-explanatory
      when auditing, since `expected_qty` (fixed at count time) can differ from `oldStock`
      (live at apply time).
3. Commit. On any failure, roll back (stock + history + reconciled flag all revert).

Response: JSON `{ok:true, adjusted:N, skipped:M}` for AJAX (also surfaced in the
post-apply banner, e.g. "Reconciled — 12 adjusted, 37 unchanged"), or a redirect/flash
for a full-page submit. No separate reconciliation-log table is needed: `reconciled_by`
/`reconciled_at` on `stock_counts` already record who applied the session and when, and
the per-item `count_adjust` history rows record exactly what changed.

## UI

**stock_count.php** (viewing a session):
- Manager/admin + `status='submitted'` + `reconciled_at IS NULL` → show an
  **"Apply to stock"** button with a confirm dialog:
  *"Set system stock to the counted values for N counted items? This adjusts
  inventory and is logged. Uncounted items are left unchanged."*
- After apply (or if already reconciled) → a banner
  *"Reconciled by <name> at <time>"* and no button.
- Clerks (non-manager) never see the button; the server gate also rejects them.
- Draft (not yet submitted) → no Apply (must submit first).

**reconciliation_report.php** Inventory Stock Counts list:
- Add a reconciliation indicator per submitted row: **Reconciled** (with
  `reconciled_by`) vs **Pending**. Drafts show neither. This reuses the existing
  `$sc_rows` query — add `sc.reconciled_at, sc.reconciled_by` to its SELECT and
  render a pill in a new "Reconciled" column appended to the stock table.

## CSRF note

stock_count.php's existing `save_item` and `submit` actions currently have no CSRF
token. The new `reconcile` action **must** be CSRF-protected (it changes real
inventory): add a session `csrf_token` (bootstrap if absent), emit it to the page,
and verify it in the handler with `hash_equals`. Retrofitting CSRF onto the
pre-existing `save_item`/`submit` actions is **out of scope** here but noted as an
adjacent gap.

## Out of scope / untouched

- No change to how counts are created, saved per-row, submitted, or how
  `expected_qty`/`variance` are computed.
- No change to order-time stock deduction, recipes, or the low-stock alert logic —
  alerts simply re-evaluate against the corrected `stock_quantity` on next load.
- No manager approval *workflow* beyond the single Apply click (no multi-step
  approvals, no partial apply of selected items — it's all counted items or none).

## Edge cases

- **Apply exactly once:** the atomic claim `UPDATE ... WHERE reconciled_at IS NULL`
  guarantees a second Apply (double-click, two managers) is a no-op with a clear
  message.
- **Partial count:** only counted items are applied; uncounted ones stay as-is.
- **Zero variance rows:** skipped (no write, no log).
- **Counted value 0:** valid — sets stock to 0 (ingredient counted empty).
- **Stock is INT:** counted decimal is rounded; log records the integer delta.
- **Rollback:** any mid-apply failure rolls the whole transaction back, including the
  `reconciled_at` claim, so the session stays pending and can be retried.
- **Deleted ingredient between submit and apply:** cannot occur. `stock_count_items.ingredient_id
  → ingredients` is an `ON DELETE RESTRICT` FK, so any ingredient present in a count
  cannot be deleted. No special handling needed (the `UPDATE ingredients` will always
  hit a live row).
- **Concurrent stock change during apply:** the overwrite semantic already accepts that
  intervening movement is clobbered (see Semantics). Reads and writes are adjacent; a
  logged `delta` could be marginally off only under rare same-instant concurrency on a
  single-writer POS — accepted, not guarded with compare-and-swap (over-engineering here).

## Review decisions (deferred / rejected)

Recorded so they aren't re-litigated:
- **Rejected — separate reconciliation-log table:** redundant with `reconciled_by`/`reconciled_at`
  on `stock_counts` + the per-item history rows.
- **Rejected — compare-and-swap on the stock write:** over-engineering for a single-writer,
  end-of-day cafe; overwrite already documents intervening-change behavior.
- **Rejected — CSRF generated at login:** lazy `if(empty($_SESSION['csrf_token']))` at page
  top runs before the form renders, so the token exists for that load; matches the
  existing codebase pattern.
- **Deferred to future (not now):** time-gap warning showing orders processed between
  submit and apply; partial reconciliation (uncheck suspicious rows before applying);
  large-variance highlighting in the confirm dialog. None block the core feature.

## Testing

- Migrations: fresh load adds the two `stock_counts` columns + the `count_adjust`
  enum value; re-load is idempotent (no duplicate/failed ALTER).
- Apply (happy path): a submitted count with mixed variances → each counted item's
  `ingredients.stock_quantity` equals its `actual_qty`; one `ingredient_history`
  `count_adjust` row per non-zero-delta item with the correct signed `amount` and
  reference; zero-delta and uncounted items produce no history row; `reconciled_at`
  /`reconciled_by` set.
- Sign/ledger: a **negative** `count_adjust` (stock counted lower than system) is logged
  with a negative `amount`, appears under the deduction totals in ingredient_history.php
  (not additions), and `count_adjust` is selectable in the type filter.
- Apply-once: second Apply on the same session → rejected, no further writes.
- Permission: a clerk (perm `stock_count`, non-manager) gets no button and the
  `reconcile` POST is rejected; missing CSRF is rejected.
- Draft session: Apply not offered/rejected (must be submitted).
- Report: submitted rows show Pending before apply, Reconciled (with name) after.
- Live verify (admin Sokun, plus a clerk account) via browser/DB per the project's
  established pattern.
