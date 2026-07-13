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

All new metric SQL runs **only inside** this fence (or guarded by the same role check) so no extra queries execute for other roles. The fence sits in the same region as the current `$_is_mgr` / non-admin branching (around `dashboard.php:1530`).

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
| Low-stock list | `SELECT name, stock_quantity, minimum_stock, unit FROM ingredients WHERE stock_quantity < minimum_stock ORDER BY (stock_quantity/NULLIF(minimum_stock,0)) ASC` (worst-first; verify `unit`/`name` column names at build) |
| Recent Activity | `SELECT ih.change_type, ih.amount, ih.created_at, i.name FROM ingredient_history ih JOIN ingredients i ON ih.ingredient_id=i.ingredient_id ORDER BY ih.created_at DESC LIMIT 6` |

Monthly-usage `amount` sign for `order_deduct` to be confirmed at build (use `ABS()` if stored signed).

## Components

### 1. Sidebar
Bird's Nest brand/logo (unchanged), nav: Dashboard, Products, Ingredients, Recipes, Suppliers, Purchase Orders, Reports (if `can('report')`), Settings — each gated by existing `can()`. User profile block at bottom (existing pattern).

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
- **Low Stock**: ingredients below threshold, worst-first. Each row: name, qty + unit, `% of threshold` label, progress bar (`stock_quantity / minimum_stock`). Filter tabs **All / Low / Critical** wired client-side JS:
  - Critical = ratio < 0.10 (stock below 10% of minimum_stock)
  - Low = below minimum but not critical
  - All = both
  - "View all →" links to `ingredients.php`.
- **Recent Activity**: last ~6 rows from `ingredient_history` joined to ingredient name. Each row: colored dot per `change_type`, human text (e.g. `po_received` → "PO stock received: {name}", `quick_restock` → "{name} restocked", `count_adjust`/`manual_adjust` → "{name} adjusted"), and time-ago. Time-ago via existing `timeAgo`-style helper or server-formatted timestamp.

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
