# Find Orders — Pagination, Date Scoping & Silent Refresh

**Date:** 2026-06-19
**File(s):** `find_order.php` (primary), new `_order_card.php` (extracted card partial)
**Roles affected:** all (cashier, admin, manager) — `find_order.php` is a single shared screen.

## Problem

`find_order.php` renders every matching order as a card in one long scroll, with no date scoping. With volume this becomes slow (all rows fetched + rendered) and tedious (scroll to the bottom). It also shows unpaid/open orders from any past day, mixed with today's, with no way to page through them.

## Goals

1. **Paginate** the order list — 10 records per page, page navigation control.
2. **Server-side** `LIMIT/OFFSET` so each request fetches only 10 rows (the performance win).
3. **AJAX** page switching — no full reload, no flash, no scroll reset.
4. **Date scoping** — show today's orders by default, but keep old **unpaid pay-later** orders visible until settled (never lose a debt).
5. **Unify auto-refresh** — replace the 5s full-page `location.reload()` with a silent re-fetch of the current page (fixes the prior reload-flash issue too).

Applies to every tab (Pay Later, Preparing, Pending, Paid-open) and every role, because it is one shared file.

## Non-Goals

- No date-range picker (just "today + old unpaid pay-later").
- No change to payment/settlement logic.
- No change to which orders qualify (the existing status/payment_method conditions stay; we only add a date clause and pagination).

## Design

### Data scoping (WHERE additions)

Existing qualifying conditions are unchanged:

```sql
(
  (status NOT IN ('Completed','Cancelled','Refunded') AND status != 'Paid')
  OR (status = 'Paid' AND is_open = 1)
  OR (payment_method = 'paylater' AND status = 'Completed')
)
```

Add a date clause:

```sql
AND ( business_date = CURDATE() OR payment_method = 'paylater' )
```

- `business_date = CURDATE()` → today's orders for all tabs.
- `OR payment_method = 'paylater'` → old pay-later orders stay. Because a settled pay-later order becomes `status = 'Paid'` (per the 2026-06-19 settlement fix), it already drops out of the qualifying conditions — so only **unpaid** old pay-later survives this clause.

This same date clause is applied to: the main list query, the tab-count query, and the poll/list signature query, so all three stay consistent.

### Pagination (server)

- `$perPage = 10`. `$page = max(1, (int)($_GET['page'] ?? 1))`. `$offset = ($page - 1) * $perPage`.
- Main list query gets `ORDER BY order_date DESC LIMIT $perPage OFFSET $offset`.
- A `COUNT(*)` query with the **same WHERE** (status conditions + date clause + search + tab filter) yields `$total`; `$totalPages = max(1, ceil($total / $perPage))`.
- **Clamp:** if `$page > $totalPages` (e.g. orders got paid and pages shrank), clamp to `$totalPages`.

### Card extraction

The per-card markup currently inlined in the render loop moves to **`_order_card.php`**, which expects an `$order` row (plus the existing context flags: `$is_cashier`, `$canAdd`, `$isPL`, time-ago helpers). Both the initial server render and the AJAX endpoint `include` this partial in a loop — single source of truth for card HTML.

### AJAX endpoint (`action=list`)

New branch near the existing `action=poll` / `action=close_order` handlers:

```
GET find_order.php?action=list&tab=<t>&search_type=<s>&search_value=<v>&page=<n>
```

Returns JSON:

```json
{
  "html": "<rendered cards for this page>",
  "page": 2,
  "perPage": 10,
  "total": 47,
  "totalPages": 5,
  "sig": "<md5 of order_id:status for this page>",
  "tabCounts": { "all": 47, "paylater": 12, "preparing": 5, "pending": 3, "paid_open": 1 }
}
```

`html` is produced by looping the page's rows through `_order_card.php` (same partial), captured via output buffering.

### Client behavior

- Markup: a `#orderList` container (cards) and a `#pagination` bar.
- `loadPage(n, { silent } = {})`:
  1. `fetch` the `action=list` URL for current tab/search + page `n`.
  2. On success: set `#orderList.innerHTML = html`, rebuild `#pagination`, update tab-count pills, store `currentPage` + `lastSig`.
  3. If `silent` and returned `sig === lastSig`, **skip** the DOM swap (no flicker).
  4. Call `bindCardHandlers()` after any swap.
- **Pagination control:** windowed — first/prev, current ±2, ellipsis for gaps, next/last (`« ‹ 1 … 4 [5] 6 … 12 › »`). Hidden when `totalPages <= 1`. Matches the dark theme.
- **Tab switch / search:** reset to page 1, then `loadPage(1)`.
- **Auto-refresh:** the existing 5s `setInterval` now calls `loadPage(currentPage, { silent: true })` instead of `location.reload()`. Keeps the existing `tableEditOpen` guard (skip while a table-number edit is open).

### Handler rebinding

`bindCardHandlers()` re-attaches anything bound via `addEventListener` after a swap:

- **Inline `onclick`** (Cash/Bakong `interceptPayLater`, `closeOrder`, `cancelOrderFromFind`, clock toggle) — these are HTML attributes and survive `innerHTML`; no rebinding needed.
- **Inline table-number edit** (`.table-edit-wrap`, currently bound via `querySelectorAll(...).forEach(addEventListener)`) — moved into `bindCardHandlers()` and re-run after each swap. The `tableEditOpen` shared flag is preserved.

## Edge Cases

| Case | Handling |
|------|----------|
| Page out of range after settlements | Server clamps `page` to `totalPages`; client requests clamped page on next poll. |
| Empty result set | `#orderList` shows the existing "no orders" empty message; `#pagination` hidden. |
| Search active | `COUNT` and list share the same search filter; pagination reflects filtered total. |
| Old unpaid pay-later | Kept via `OR payment_method='paylater'`; drops automatically once settled to `Paid`. |
| Stale tab counts after AJAX | Refreshed from `tabCounts` in each `action=list` response. |
| Table edit open during poll | Poll skipped while `tableEditOpen` (unchanged guard). |

## Files Touched

- **`find_order.php`** — add date clause to list/count/poll queries; add `$page`/`LIMIT/OFFSET`; add `COUNT(*)` total; add `action=list` JSON endpoint; replace inline card loop with `include _order_card.php`; add `#orderList`/`#pagination` containers; rewrite JS (poll → silent `loadPage`, add pagination + `bindCardHandlers`).
- **`_order_card.php`** (new) — extracted single-card markup, included by both initial render and `action=list`.

No other pages, roles, or payment logic affected.

## Testing

- Seed >10 qualifying orders; verify exactly 10 per page and correct page count.
- Click pages → correct rows, no full reload, scroll preserved.
- Settle an order on page 1 → silent refresh keeps you on your page; settled order gone; counts updated.
- Old unpaid pay-later (past `business_date`) still listed; a non-pay-later order from yesterday is hidden.
- Search + pagination combined; tab switch resets to page 1.
- Card buttons (Cash/Bakong/Edit/Add/close/cancel/table-edit) all work after an AJAX swap.
