# Cashier + Inventory Dashboard Redesign — Design

**Date:** 2026-06-17
**File touched:** `dashboard.php` (non-manager branch only)
**Scope:** Restyle the landing dashboard for the **Cashier** (`staff`) and **Inventory** (`inventory_clerk`) roles. **Barista** (`barista`) view stays byte-for-byte unchanged.

## Problem

The non-manager dashboard renders from one shared template (`!$_is_mgr` branch). For cashier/inventory it reads "ugly":

- An oversized amber **hero "Take New Order" button** — 104px tall, 22px font, with a perpetual `heroGlow` pulse animation and a shimmer `::after` sweep. Too loud, unprofessional.
- Large 158px **quick-access tiles** with big centered icons floating in lots of empty space.

Cashier, barista, and inventory all share this markup, so any change must avoid altering the barista view.

## Goals

- Professional, calmer look — "not too big, not too small."
- Shrink and de-glamorize the primary action button.
- Compact, POS-app-style tile grid (icon-left, denser).
- Leave the barista view completely unchanged.
- No backend / query / permission changes — presentation only.

## Non-Goals

- No changes to barista or manager/admin dashboards.
- No changes to PHP queries, `can()` permission logic, or which groups/tiles a role sees.
- No dedupe of the old `.qa-*` styles (kept for the barista path).

## Approach

Fork the non-manager render path by role. Introduce a single flag in the non-manager branch (after `$_role` is set, ~line 1462):

```php
$_redesign = in_array($_role, ['staff', 'inventory_clerk']);
```

- `$_redesign === true` → render the new compact markup using **new CSS classes** (`.qx-*`).
- otherwise (barista + any future non-manager role) → render the **existing** markup with the existing `.qa-*` classes, unchanged.

New classes are added **alongside** the existing `.qa-hero-btn` / `.qa-tile` / `.qa-group` rules — the old rules are not edited, so the barista path is unaffected.

Role slugs (confirmed against `db_coffee.roles`):

| slug | name | redesign? |
|------|------|-----------|
| `staff` | Cashier | yes |
| `inventory_clerk` | Inventory | yes |
| `barista` | Barista | no — unchanged |
| `admin` / `manager` | — | n/a (manager branch) |

## Component Details

### 1. Focus card — unchanged
The existing role-aware focus card (the "N orders awaiting payment" / "N items low on stock" strip, ~line 1506-1521) stays as-is. It already reads cleanly. Remains at the top of the page.

### 2. Primary action — shrink + calm (`.qx-hero`)
New button class for the cashier "Take New Order" action (rendered when `can('find_orders')`):

- min-height ~104px → **~60px**; font 22px → **16px**; padding tightened (e.g. `16px 24px`).
- **Remove** the `heroGlow` pulse animation and the shimmer `::after` sweep.
- Solid amber background (keep the brand gradient but static), subtle resting shadow, gentle hover lift (`translateY(-1px)`) only.
- Full width, sits directly under the focus card.
- Icon shrinks to match (smaller leading `+` icon box).

### 3. Tiles — compact pro grid (`.qx-group` / `.qx-tiles` / `.qx-tile`)
- min-height 158px → **~96px**.
- Icon moves to the **left** of the label (not stacked/centered). Icon box ~44px, rounded, `--amber-dim` background, amber glyph.
- Label 15px / weight 600, left-aligned beside the icon.
- Grid: `repeat(auto-fill, minmax(200px, 1fr))`, gap ~12px.
- Count badge (unpaid orders / low-stock / unread announcements) stays top-right of the tile (`.qx-tile-badge`).
- Group label header (`.qx-group-label`) mirrors the existing `.qa-group-label` style.

Groups rendered are driven by existing `can()` checks (unchanged):
- **Cashier** typically sees: Orders, Loyalty, Account.
- **Inventory** typically sees: Inventory, Procurement, Account.

### 4. Responsive
Add mobile rules for the new `.qx-*` classes mirroring the existing `@media(max-width:768px)` block (smaller tiles/hero on narrow screens).

## Data Flow

No change. All existing PHP variables (`$unpaid_count`, `$paylater_count`, `$low_stock`, `$low_recipe_count`, `$_unread_ann`, etc.) and `can()` gates are reused exactly as today. The only new PHP is the `$_redesign` boolean and an `if ($_redesign) { … } else { … }` fork around the hero + tile markup.

## Testing

Manual verification with Playwright using the existing test accounts (cashier / barista / inventory):

1. Log in as **cashier** (`staff`) → confirm: calm shrunk hero button (no pulse/shimmer), compact icon-left tiles, focus card intact.
2. Log in as **inventory** (`inventory_clerk`) → confirm: compact tiles for Inventory/Procurement/Account, inventory focus card.
3. Log in as **barista** → confirm the view is **identical** to the pre-change render (old hero button + 158px centered tiles still present).
4. Check mobile width (≤768px) for cashier renders without overflow.

## Risks / Trade-offs

- **CSS duplication:** new `.qx-*` rules sit beside the retained `.qa-*` rules. Accepted for clean barista isolation; can be deduped later if barista ever adopts the same treatment.
- **Markup fork** adds an `if/else` in the template — slightly longer file, but the two paths are simple and independent.
