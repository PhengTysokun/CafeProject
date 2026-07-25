# Inventory-Clerk Dashboard Redesign (StockMate layout)

**Date:** 2026-07-13
**Branch:** feat/product-addons
**Status:** Design approved — ready for implementation plan

## Goal

Give the `inventory_clerk` role a purpose-built landing page in `dashboard.php`, modeled on the user-supplied "StockMate" mockup, using **real data only** (no fabricated trend numbers). This mirrors the barista-station redesign precedent (a role-scoped layout fence inside a large shared file), but for the inventory dashboard instead of the orders screen.

Every other role (admin, manager, supervisor, staff/cashier, barista) must render **byte-for-byte unchanged**.

## Non-Goals (explicit scope guard)

- No daily-metrics snapshot table, no cron, no schema changes of any kind.
- No fabricated trend deltas ("+3", "−2 from yesterday", "2 arriving today"). Only deltas backed by queryable data ship.
- No "StockMate" rebrand — keep the existing **Bird's Nest Coffee** sidebar brand/logo. StockMate *layout* only.
- No changes to cashier / barista / manager / admin dashboards.
- No new PHP files — inline in `dashboard.php`.

## Architecture

### Location & gating
A single role fence in `dashboard.php`:

```php
if (($_SESSION['role'] ?? '') === 'inventory_clerk') {
    // new StockMate layout
} else {
    // existing branches, unchanged
}
```

All new metric SQL runs **only inside** this fence (or guarded by the same role check) so no extra queries execute for other roles.

**Injection-point discipline (do NOT trust line numbers):** before writing the fence, grep `dashboard.php` for the role branching (`$_is_mgr`, `$_SESSION['role']`, `$_role`, the `<?php else: /* non-admin/manager` marker near line 1530) to find the *exact* start/end of the current role-conditional layout block. The inventory_clerk layout is a **new branch in that existing chain** — it must fully replace the non-admin body for this role (early-return / distinct branch), not be appended where a later `else` can render the old layout on top of it. After insertion, diff-verify that the admin/manager and cashier/barista output is byte-for-byte unchanged.

Reuses existing infrastructure already present in `dashboard.php`:
- `auth.php` session + `can()` permission gates
- theme CSS variables (`--surface-2`, `--border`, `--border-hi`, `--amber`, `--red`, `--purple`, `--text`, `--text-muted`)
- `$low_stock` (already computed at `dashboard.php:47-48`)
- `$low_recipe_count` (`:51-57`)
- `$_unread_ann` (`:67-84`)
- announcements pipeline + `ann_dismissed` localStorage bell pattern (from barista redesign)

### New metric queries (inventory_clerk only)
| Metric | Query |
|---|---|
| Total Products | `SELECT COUNT(*) FROM products` |
| Low Stock Items | reuse `$low_stock` = `COUNT(*) FROM ingredients WHERE stock_quantity < minimum_stock` |
| Pending POs | `SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Draft','Ordered')` |
| Monthly Usage (this month) | `SELECT IFNULL(SUM(amount),0) FROM ingredient_history WHERE change_type='order_deduct' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())` |
| Monthly Usage (last month) | same, previous calendar month → for the `% vs last month` delta |
| Low-stock list | `SELECT ingredient_name, stock_quantity, minimum_stock, unit FROM ingredients WHERE stock_quantity < minimum_stock ORDER BY (stock_quantity/NULLIF(minimum_stock,0)) ASC` (worst-first) |
| Recent Activity | `SELECT ih.change_type, ih.amount, ih.created_at, i.ingredient_name FROM ingredient_history ih JOIN ingredients i ON ih.ingredient_id=i.ingredient_id WHERE ih.change_type NOT IN ('order_deduct','order_restore') ORDER BY ih.created_at DESC LIMIT 6` |

**Confirmed columns** (`ingredients`): `ingredient_id`, `ingredient_name`, `stock_quantity`, `minimum_stock`, `unit`, `cost_per_unit`, `supplier_id`. There is **no** `name` column — use `ingredient_name`.

**Why exclude `order_deduct`/`order_restore` from the feed:** those fire on every drink sale/void, which would flood the panel and bury the restock / PO-received / count-adjust events an inventory clerk actually acts on (and which the mockup shows). The feed is a *stock-management* log, not a sales log.

Monthly-usage `amount` sign for `order_deduct` to be confirmed at build (use `ABS()` if stored signed).

## Components

### 1. Sidebar
NOTE: the current non-manager dashboard branch renders **no sidebar** (only `$_is_mgr` gets `.sidebar`). The inventory layout builds its **own** `.sidebar` reusing existing classes (`.sidebar`, `.sidebar-profile`, `.sidebar-header`, `.sidebar-nav`, `.nav-item`, `.nav-item.active`, `.order-badge`). Bird's Nest brand/logo (`fa-mug-hot` + "Bird's Nest", unchanged), nav: Dashboard, Products, Ingredients, Recipes, Suppliers, Purchase Orders, Reports (if `can('report')`), Settings — each gated by existing `can()`. User profile block at bottom (existing pattern). The **Dashboard** nav item carries the active-pill state (amber rounded background) since this layout *is* the dashboard — the clerk should see where they are.

### 2. Header
`Good morning, {username}` + live date; right cluster = theme toggle, notifications bell (badge = `$_unread_ann`), Clock In/Out, Logout. Bell opens a dropdown that reuses the existing announcements render pipeline + `ann_dismissed` localStorage (identical mechanism to barista `#bNotifPanel`). "Good morning/afternoon/evening" chosen by server hour.

### 3. Low-stock banner
Full-width amber strip: `{N} items are low on stock — restock needed soon` with a "Review Stock →" link to `ingredients.php`. Rendered only when `$low_stock > 0`.

### 4. Stat cards (4) — all real values
| Card | Value | Sub-line |
|---|---|---|
| Total Products | `COUNT(products)` | "Active catalog" |
| Low Stock Items | `$low_stock` | "Restock needed soon" (red accent when > 0) |
| Pending Orders | pending PO count | "{n} awaiting delivery" |
| Monthly Usage | this-month usage | **"{±X}% vs last month"** — real; arrow + color per direction (up = higher usage) |

Only Monthly Usage carries a trend arrow. The other three show a descriptive sub-line, never a fabricated delta.

### 5. Tile sections
- **INVENTORY**: Products, Ingredients, Drink Recipes (with low-recipe badge = `$low_recipe_count`).
- **PROCUREMENT**: Suppliers, Purchase Orders.

Chevron-row styling per mockup. Each tile gated by its existing `can()` check and points at the existing href (`products.php`, `ingredients.php`, `recipes_view.php`, `suppliers.php`, `purchase_orders.php`).

### 6. Right rail (2 panels)
- **Low Stock**: ingredients below threshold, worst-first. Each row: name, real qty + unit (integer/decimal as stored — never derived from the ratio), `% of threshold` label, progress bar filled to `ratio = stock_quantity / minimum_stock` **clamped to 0–1**. Filter tabs **All / Low / Critical** wired client-side JS:
  - Critical = ratio < 0.10 (stock below 10% of minimum_stock)
  - Low = below minimum but not critical
  - All = both
  - "View all →" links to `ingredients.php`.
  - **Ratio is a bar fill-fraction only, not a quantity** — no special-casing for small `minimum_stock` (e.g. `min=1`): a `stock=1, min=1` item can't appear here because the list filter is `stock_quantity < minimum_stock`; `stock=0, min=1` → ratio 0 → Critical, already correct. `NULLIF(minimum_stock,0)` guards divide-by-zero.
- **Recent Activity**: last ~6 rows from `ingredient_history` (sales deductions excluded, see query note above) joined to ingredient name. Each row: colored dot per `change_type`, human text via a `change_type → label` map with a fallback, and time-ago. Time-ago via existing `timeAgo`-style helper or server-formatted timestamp.

  Label map (PHP array, with fallback `'Inventory updated'`):
  | change_type | label |
  |---|---|
  | `po_received` | "Purchase Order received — {name}" |
  | `quick_restock` | "{name} restocked" |
  | `count_adjust` | "{name} — stock count adjusted" |
  | `manual_adjust` | "{name} — stock adjusted" |

### 7. Responsive
- Desktop: 2-column (main content + right rail).
- < ~1000px: rail stacks below the tile sections.
- Stat cards grid: 4 → 2 → 1 across breakpoints.

### 8. Theme
Base-dark styles + `[data-theme=light]` overrides, per app theme convention. No new theme key; reuse the shared `theme` localStorage and existing CSS variables.

## Error / edge handling
- Zero low-stock → banner hidden; Low Stock panel shows an empty "Stock levels look healthy" state.
- Zero recent activity → Recent Activity panel shows an empty state.
- Last-month usage = 0 → suppress the % delta (avoid divide-by-zero), show a neutral sub-line (mirror the existing `$sales_trend` guard at `dashboard.php:40`).
- This-month usage = 0 (last month > 0) → would render a misleading "−100%". Show the value as `0` and **suppress the delta text** rather than a −100% arrow. (Partial-month comparison, e.g. day 2 of a month vs a full prior month, is accepted as-is — it's real data labeled "vs last month" — no special handling.)
- `minimum_stock = 0` → guarded with `NULLIF` in the ratio ordering; treat ratio as full/healthy.

## Testing / verification
- Browser-verify as `inventory_clerk` (`Clerk_Sokun`) — login lands via `loading.php`; navigate to `dashboard.php` directly.
- Regression: load `dashboard.php` as admin (`Sokun`), cashier (`Sok_Dara`), barista (`darasokun`) — confirm their layouts render unchanged (visual + no new queries firing for them).
- Verify each stat number against a direct SQL query.
- Verify theme toggle (dark ↔ light) on the new layout.
- Verify low-stock filter tabs (All/Low/Critical) filter the loaded list without reload.

## Related
- Barista precedent: `docs/superpowers/specs/2026-07-12-barista-station-redesign-design.md`
- Memory: `barista-station-redesign`, `project-overview`, `theme-convention`
