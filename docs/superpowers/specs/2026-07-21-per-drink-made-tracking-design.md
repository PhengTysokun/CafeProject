# Per-Drink Made Tracking (Dim, Not Hide)

**Date:** 2026-07-21
**Branch:** feat/product-addons
**Status:** Design — approved, pending spec review

## Problem

The barista station currently tracks "made" as a single flag per order-item **row**
(`order_items.made_at`) and **hides** made rows. Two problems:

1. **No per-drink progress.** A barista finishing one of three lattes can't mark just that
   one. Completion is all-or-nothing per order.
2. **Added drinks vanish.** Because "made" is per row, bumping an already-made row's
   quantity (Edit page `+`) raises the count on a hidden row — the extra drinks never
   reappear on the barista station.

The user wants: each finished drink **dims** (stays visible, greyed) instead of hiding; and
adding a drink shows up as a **new active (undimmed) drink** to make.

## Goal

Track made **per unit** (a made *count* per row), and on the barista station render each
drink as its own line — made ones dimmed, unmade ones active and tappable. Tapping a drink
marks it made; the order auto-completes when the last drink is tapped. This **supersedes**
the current hide-made behaviour.

## Model

Add `order_items.made_qty INT NOT NULL DEFAULT 0` — how many units of that row are made
(0 … `quantity`).

- A row is **fully made** when `made_qty >= quantity`.
- Keep `made_at` as the timestamp the row *became* fully made — set when `made_qty` reaches
  `quantity`, cleared to NULL if it later drops below. It still drives the "Returning tab"
  signal and audit; `is_made` (0/1) in the feed = "row fully made" as today.
- Because units of the same row are identical, a **count** is enough — the barista card dims
  the first `made_qty` of the row's lines; which specific unit was tapped doesn't matter.

Backfill: `made_qty = quantity` for rows already fully made (`made_at` set by the prior
feature), else 0.

## Data flow

### 1. Migration — `config.php`
Via the existing `_migrate($conn,'<id>',fn)` helper (runs once, tracked in
`schema_migrations`):

```sql
ALTER TABLE order_items ADD COLUMN IF NOT EXISTS made_qty INT NOT NULL DEFAULT 0;
UPDATE order_items SET made_qty = quantity WHERE made_at IS NOT NULL AND made_qty = 0;
```

### 2. Feed exposes made_qty — `view_order.php` (`$map` builder, ~line 2980)
Add `oi.made_qty` to the item SELECT (and GROUP BY), and to each item object:
`"made_qty" => (int)$r["made_qty"]` alongside `quantity`. Redefine the per-item made flag off
the count, **not** the timestamp: `$is_made = ((int)$r["made_qty"] >= (int)$r["quantity"] &&
(int)$r["quantity"] > 0) ? 1 : 0`. `is_returning` is then computed exactly as today (Preparing
order with ≥1 fully-made row) but reads this count-derived `is_made`. Because the badge keys on
`made_qty >= quantity` per row — never on `made_at` — undoing one drink (which NULLs that row's
`made_at`) does **not** drop the badge while any other row is still fully made.

### 3. Barista card renders per-unit, dims made — `buildBaristaCardInner` (~line 1956)
Replace the current "filter to unmade, one line per row" block. For each item, emit
`quantity` lines; the first `made_qty` carry a `bitem-made` (dimmed) class, the rest are
active with `onclick="markDrink(<item_id>, +1)"`. A dimmed line's onclick is
`markDrink(<item_id>, -1)` (undo). Chips (size/sweet/etc.) render on each line. No more
hide filter — every drink shows; made ones are greyed.

New CSS: `.bitem-made` = dimmed (`opacity:.4`) with a small green check icon prefixed to
the drink name; active `.bitem` gets `cursor:pointer` and a hover state. The dim + check is
the only "made" affordance — no separate badge.

### 4. Mark-made endpoint — `view_order.php?action=mark_made`
New action, JSON, barista_station-gated (same as `action=complete`). Params: `item_id`,
`delta` (+1 or −1). Resolve the item's `order_id`, then inside a transaction **lock all of
that order's rows** — `SELECT item_id, quantity, made_qty FROM order_items WHERE order_id = ?
FOR UPDATE` — not just the tapped row. Locking the whole order serialises concurrent taps on
*different* items of the same order, so two taps can't both read "not yet all made" and both
try to auto-complete.

- Clamp `made_qty` to `[0, quantity]` after applying delta; persist the tapped row.
- Recompute that row's `made_at`: set `NOW()` when `made_qty` reaches `quantity` and it was
  NULL; set NULL when `made_qty` drops below `quantity`. `made_at` is **audit/ordering only**
  — no downstream logic branches on it (see the returning-tab note below).
- After the update, evaluate the **whole order** under the same lock: the order is fully made
  when **no row has `made_qty < quantity`** (`SELECT COUNT(*) … WHERE order_id = ? AND
  made_qty < quantity` returns 0). This predicate is naturally correct for a degenerate
  zero-quantity row (`0 < 0` is false, so it neither blocks nor forces completion). If fully
  made, auto-complete (status `Completed`, `is_open=0`, `completed_at=NOW()`, `prepared_by`)
  — same effect as `action=complete`. Return `{ ok:1, completed:0|1 }` so the client knows
  whether the card left the queue.

### 5. Complete-all button — `action=complete` (~line 3085)
Keep the whole-order Complete button and its `completeOrder(id)` flow. Extend the
`action=complete` handler to also `UPDATE order_items SET made_qty = quantity WHERE
order_id = ? AND made_qty < quantity` (so a one-tap complete marks all drinks made,
consistent with the per-drink model). The existing `made_at` stamp stays.

### 6. Client tap handler — `view_order.php` JS
`async function markDrink(itemId, delta)`: optimistic — dim/un-dim the tapped line
immediately, POST `action=mark_made`. On success, `loadOrders()` to reconcile; on
`completed:1` show the existing "order completed" toast + `callOrder`. **On failure (network
error or `ok:0`), immediately revert the optimistic dim on that line in the `catch`** — do not
wait for a poll, so the barista never sees a drink that looks made but isn't. Disable the line
while in flight to avoid double-taps.

## Point-2 fix (falls out)

Editing quantity `+` raises `quantity`, never `made_qty`, so the added units render as new
**active** lines on the barista card. The vanishing-drink bug disappears with no extra work.

## Explicitly out of scope / unchanged

- **Totals, tax, loyalty points, stock, Buy-X gift model, pay-later flows** — untouched.
- **Manager/admin order views** (`buildCardInner`) — unchanged; per-unit dimming is
  barista-station-only.
- **`edit_order_items.php`** — no change; its `+` already raises `quantity`, which now
  surfaces correctly on the station.
- The **"Returning tab"** badge stays.
- **Partial serve** (hand out the finished drinks while the rest are still being made) is a
  separate feature — the order stays in Preparing until every drink is made. Dimming just
  shows progress; it does not split or partially close the order.

## Edge cases

- **Undo after auto-complete:** once the last drink is tapped the order leaves the Preparing
  queue, so its card is gone — undo is only possible while the order is still in queue
  (before the final drink). Acceptable; a mis-complete is recovered via the existing
  Preparing/remake paths.
- **Concurrent taps** (two baristas, same order): the endpoint locks **all** the order's rows
  `FOR UPDATE`, so taps serialise — only one can pass the all-made check and complete; clamp
  keeps `made_qty` within `[0, quantity]`; `loadOrders()` reconciles the displayed state.
- **Row with quantity 0** (shouldn't occur): renders no lines; the all-made check
  (`no row with made_qty < quantity`) treats it as neither blocking nor forcing completion
  (`0 < 0` is false). No special guard needed.

## Testing (manual E2E, as barista)

1. **Per-drink dim:** order with 3 drinks → tap one → it dims, other two active, order stays
   in queue. Tap the remaining two → order auto-completes and leaves the queue.
2. **Undo:** tap a made drink → it re-activates; `made_qty` decremented in DB.
3. **Complete-all:** press the whole-order Complete → all drinks dim + order completes; DB
   `made_qty = quantity` for every row.
4. **Added drink appears (point 2):** on a re-opened tab whose old drinks are made (dimmed),
   Edit `+` a made item → the added unit shows as a **new active line**; barista taps it →
   completes.
5. **Backfill:** historical completed orders show `made_qty = quantity` (no active lines if
   ever re-opened).
