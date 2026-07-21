# Barista Unmade-Item Tracking (Pay-Later Add-Ons)

**Date:** 2026-07-21
**Branch:** feat/product-addons
**Status:** Design — approved, pending spec review

## Problem

A pay-later tab that the barista has already **Completed** (drinks made and handed to the
customer) can be re-opened when the customer comes back and adds more drinks. Today the
whole order flips `Completed → Preparing` and re-enters the barista's Preparing queue
showing **every** item — including the drinks already made and handed over. The barista
cannot tell which drinks are new, so they may remake the whole order. Wasted product.

Example: Order #9 was Completed (3 Iced Matcha + 3 Iced Americano handed over). Customer
returns, adds 2 Mix Berry Frappe. Order re-opens to Preparing and the card shows all 8
drinks. Only the 2 Mix Berry are actually unmade.

## Goal

The barista should only ever see **unmade** drinks. New additions to a re-opened tab flow
to the queue; already-made drinks never reappear. No change to totals, points, stock, or
the Buy-X-Get-1-Free gift model — those are already correct. This is a display concern plus
one timestamp.

## Model

Add `order_items.made_at DATETIME NULL`.

- `made_at IS NULL` → the drink has not been made yet (it belongs in the barista queue).
- `made_at` set → the drink was made and handed over (hidden from the queue).

`made_at` is stamped **at the moment the barista marks the order Completed** — never earlier.
This keeps the rule uniform: a brand-new order has all-NULL items (all unmade) until the
barista completes it; a re-opened tab's old items already carry a `made_at` from their first
completion, while the freshly-inserted rows are NULL.

## Data flow

### 1. Completion stamps the delta — `update_status.php`
The single place a barista completes an order is `update_status.php` on the transition to
`Completed` (line ~61, which already sets `is_open = 0, completed_at = NOW()`). Add, in the
same handler, immediately after the order UPDATE:

```sql
UPDATE order_items SET made_at = NOW() WHERE order_id = ? AND made_at IS NULL
```

This stamps exactly the drinks that were unmade at completion time — i.e. on a first
completion, all of them; on a re-open completion, only the newly-added ones (the old rows
already have a `made_at` and are skipped by the `IS NULL` guard). Single Complete tap,
reuses the existing flow — no per-drink checkoff.

### 2. Re-open path already flips status — `confirm_order.php`
The add-to-existing branch (~line 157) already resets `Completed → Preparing` for pay-later
re-opens and inserts the new `order_items` rows with `made_at` defaulting to NULL. **No change
needed** beyond confirming the INSERT does not set `made_at`. The order re-enters the queue
with only its new rows unmade.

### 3. Barista feed hides made drinks — `view_order.php`
The order-items feed builds each order's `items` array around line 2978. Changes:

- Add `oi.made_at` to the `order_items` SELECT that populates `$r`.
- Add one boolean field to each item in the `$item` array (~line 2979):
  `"is_made" => $r["made_at"] ? 1 : 0`. (No need to ship the raw timestamp to the client.)
- In the client `buildItems(o.items)` renderer (~line 1950) **when the viewer is a barista**,
  filter to `!i.is_made` before rendering. Non-barista roles (manager/admin/cashier) keep
  seeing the full item list — they audit the whole order, so no filtering there.

### 4. "Returning tab" marker — `view_order.php`
A re-opened tab should be visually distinct from a fresh order so the barista knows it is an
add-on run. Signal, computed server-side per order: the order is `Preparing` **and** has at
least one item with `made_at IS NOT NULL` (i.e. something was already made). Expose as
`is_returning` on the order map and render a small `⟳ Returning tab` badge on the card,
alongside the existing `is-remade` treatment. Purely informational.

## Migration & backfill

Add the column using the app's existing "add column if missing" migration guard (the same
`SHOW COLUMNS … ADD COLUMN` pattern already used for `order_type` / `completed_at`).

One-time backfill so historical orders do not flood the queue: stamp `made_at` on every
existing `order_items` row whose order is **not currently in the queue** — i.e. status not in
(`Preparing`, `PendingPayment`). Use the parent order's `completed_at` when present, else its
`order_date`:

```sql
UPDATE order_items oi
JOIN orders o ON o.order_id = oi.order_id
SET oi.made_at = COALESCE(o.completed_at, o.order_date)
WHERE oi.made_at IS NULL
  AND o.status NOT IN ('Preparing','PendingPayment')
```

Currently-`Preparing` orders keep `made_at = NULL` (correct — still unmade, still in queue).

## Explicitly out of scope / unchanged

- **Totals, tax, points, stock deduction, Buy-X-Get-1-Free gift model** — already correct;
  untouched.
- **Edit page (`edit_order_items.php`)** — gated to `Preparing` orders only (never shown for
  Completed tabs), so it never crosses the made/unmade boundary. A qty increase on a
  still-Preparing order adds unmade units to an order that never left the queue; the barista
  makes them normally. No change.
- **Per-drink checkoff** — rejected (YAGNI at café scale). One Complete tap stamps all unmade.
- **`barista_display.php` / `customer_display.php`** — separate display surfaces; not part of
  this change. If either later needs the same hide-made behavior it can reuse `made_at`.

## Edge cases

- **All items already made on a Preparing order** (shouldn't occur, but guard it): if the
  barista filter would leave a card with zero items, fall back to rendering the order normally
  rather than a blank card.
- **Non-pay-later orders** — unaffected; they complete once, all items stamp, done.
- **Cancel/refund of a re-opened tab** — no special handling; `made_at` values are irrelevant
  once cancelled.

## Testing (manual E2E)

1. **Fresh tab:** create pay-later order → barista queue shows all drinks → Complete → drinks
   disappear from queue; row `made_at` all set.
2. **Re-open + add:** on the Completed tab, Add Items (+2 Mix Berry) → tab returns to
   Preparing → barista card shows **only** the 2 Mix Berry + `⟳ Returning tab` badge → Complete
   → all items now made, card leaves queue.
3. **Qty bump on Preparing tab:** Edit order, raise a drink 1→3 → still Preparing → card shows
   all 3 (order never left queue). No regression.
4. **Backfill:** confirm historical Completed/Paid/Cancelled orders do not appear as unmade in
   the queue after migration.
