# Revenue = Payment-Received Implementation Plan

> **For agentic workers:** execute task-by-task; each task lints + commits. Money-critical — verify the paid gate against every payment type before finishing.

**Goal:** Revenue (and sales-quantity) metrics count money that has actually been collected, not orders a barista marked `Completed`. Fixes the dashboard reading `$0.00` while cash is in the drawer, and stops Pay-Later orders being counted before the customer pays.

**Root cause:** every money/quantity query gates on `status='Completed'`, which is set by the **barista completing the drink** (update_status.php) — a fulfillment event, not a payment event. Cash orders sit at `Preparing` after payment, so they never count until (and unless) a barista clicks complete.

**The paid gate (single definition):**
```
is_open = 0 AND status NOT IN ('PendingPayment','Cancelled','Refunded','Void')
```
Verified against all flows: Cash/Riel `is_open=0` at checkout ✅; Bakong `PendingPayment` until scan ✅; Pay Later `is_open=1` until settled ✅; Cancelled/Refunded excluded ✅. Barista-complete does NOT flip payment, so it correctly no longer gates money.

**Scope:** money (SUM total, AVG) **and** quantity metrics (SUM qty, top drinks, category mix) — both move to the paid gate. Time basis (order_date vs business_date) per query is left unchanged (orthogonal).

## Global Constraints
- Single source of truth: a `paid_orders_where($alias)` helper in `config.php`. Every swapped query calls it — no copy-pasted literals, so the definition can never drift.
- Preserve each query's existing date column and SELECT columns; only replace the status gate.
- App forces HTTPS; curl uses `https://localhost/Cafe` `-k`. Admin login = Sokun.
- No CLI Apache restart.

---

### Task 1: Shared paid-orders gate helper (config.php)
- Add near `product_badge_label` (top-level, after migrations):
```php
if (!function_exists('paid_orders_where')) {
    /**
     * WHERE fragment selecting orders whose money is actually collected:
     * cash/riel at checkout, bakong after scan, pay-later after settle.
     * Excludes pending, cancelled, refunded, void. $alias e.g. 'o' -> "o.".
     */
    function paid_orders_where(string $alias = ''): string {
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}is_open = 0 AND {$p}status NOT IN ('PendingPayment','Cancelled','Refunded','Void')";
    }
}
```
- Verify: `php -l config.php`; `php -r "require 'config.php'; echo paid_orders_where('o');"` → prints the fragment.
- Commit: `feat(revenue): add paid_orders_where() gate helper`.

### Task 2: Dashboard KPIs (dashboard.php + dashboard_data.php + api/preload.php)
Swap `status='Completed'` → `" . paid_orders_where() . "` (bare) or `paid_orders_where('o')` where aliased:
- `dashboard.php:35` today sales, `:38` yesterday sales, `:144` items sold (alias o), `:152` top selling (alias o).
- `dashboard_data.php:15` sales.
- `api/preload.php:21` sales + orders count.
Verify: `php -l` each; load dashboard as admin → Today's Revenue reflects paid cash orders (not $0). Commit: `fix(revenue): dashboard KPIs count paid orders, not Completed`.

### Task 3: Emailed report (send_report.php)
Swap `:29` sales, `:37` avg order, `:41` items sold, `:73` top, `:81` category (all alias `o` except the two bare). Verify `php -l`. Commit: `fix(revenue): daily email report counts paid orders`.

### Task 4: Analytics report (report.php)
Swap `:133`, `:300`, `:329`, `:351`, `:457`. Preserve the `order_date BETWEEN` ranges. Verify `php -l`; load report.php as admin, confirm totals populate. Commit: `fix(revenue): analytics report counts paid orders`.

### Task 5: Live + PDF report (report_live.php, report_pdf.php)
Swap report_live `:53`, `:149`; report_pdf `:63`, `:197`. Verify `php -l` both. Commit: `fix(revenue): live + pdf report count paid orders`.

### Task 6: Per-cashier revenue (employees.php)
Replace the buggy lowercase `status NOT IN ('cancelled','refunded','void')` at `:50` and `:213` with `paid_orders_where()` (bare — these query `FROM orders` without alias). This both fixes the case bug (was matching nothing → counting cancelled) and gates on paid. Verify `php -l`; load employees.php, confirm per-cashier revenue looks sane. Commit: `fix(revenue): per-cashier revenue counts paid orders (fixes case-mismatch)`.

### Task 7: End-to-end verification (all payment types)
Drive real orders and confirm each lands in / stays out of revenue correctly:
- Cash order → revenue increases immediately (before any barista action).
- Bakong order → NOT counted while PendingPayment; counted after scan/confirm.
- Pay Later order → NOT counted while open; counted after Mark-as-Paid.
- Cancel a paid order → drops out.
Cross-check dashboard vs report show the same day total. Soft-cancel/clean test orders. No commit (verification only) unless a fix is needed.

## Manual verification checklist
- [ ] Dashboard Today's Revenue = sum of paid orders today (cash counts instantly).
- [ ] Pay Later unpaid excluded; after settle included.
- [ ] Bakong pending excluded; after scan included.
- [ ] Dashboard total == report.php total for the same range.
- [ ] Per-cashier revenue excludes cancelled + unpaid.
- [ ] Quantity metrics (items sold / top drinks) match the paid set.
