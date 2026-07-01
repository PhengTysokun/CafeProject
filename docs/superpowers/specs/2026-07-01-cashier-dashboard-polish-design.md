# Cashier Dashboard Polish

## Problem

`dashboard.php`'s cashier-focus layout (the `_redesign` compact-pro layout shipped in the
2026-06-17 restyle) reads as unfinished: a tall block of empty space below the 2-tile
quick-access grid, flat tile/card surfaces, and no visual distinction between the
priority focus card, the primary CTA, and secondary tiles.

## Scope

`dashboard.php` only — the `_focus_cashier` banner, `qx-hero`/`qx-tiles` markup and CSS
(lines ~860-990, ~1586-1660). No new DB queries; all data needed already exists in the
page's existing query block (`$sales`, `$sales_trend`, `$total_orders`, `$items_sold`).

## Design

### 1. Shift Snapshot strip

New `.qx-snapshot` row inserted between the focus banner and the quick-access grid.
Three compact stat chips, each icon + value + label:

- **Today's Sales** — `$sales` formatted as currency, with `$sales_trend` shown as a
  small up/down delta vs yesterday (reuses existing `$trend_class`/`$trend_icon`).
- **Orders Today** — `$total_orders`.
- **Items Sold** — `$items_sold`.

Desktop: 3-column row, equal width, same card language as tiles (surface + border,
radius `var(--r)`). Mobile (`max-width:768px`): stacks to single column, full width,
matching existing tile-stacking behavior.

Gated behind the same condition that selects `$_focus_cashier` (cashier / order-taking
roles) — inventory/barista focus views are unaffected.

### 2. Visual depth & hierarchy pass

- **Focus banner** (`_focus_cashier` link block): larger icon container, background
  tint uses the focus color at low opacity (already partially done via `$_focus['color']
  .'22'`) strengthened, count text bumped up one step so it outranks tile text visually.
- **Take New Order CTA** (`.qx-hero`): richer gradient + slightly deeper box-shadow so
  it's clearly the primary action, not just another row.
- **Quick-access tiles** (`.qx-tile`): add a resting shadow (currently shadow only
  appears on hover) and a subtle gradient on the icon badge background instead of flat
  `var(--amber-dim)`.
- **Group labels** (`.qx-group-label`): slightly larger font-size and letter-spacing.

### 3. Spacing

- Tighten `.qx-grid` gap now that the snapshot strip occupies the vertical space that
  was previously empty — no forced full-height filler.
- Add matching mobile rules for `.qx-snapshot` alongside the existing `.qx-tiles`/`.qx-hero`
  breakpoint block (`@media(max-width:768px)`).

## Out of scope

- Barista/inventory focus layouts (`_focus_barista`, `_focus_inventory`) — untouched.
- Legacy (non-`_redesign`) `.qa-*` layout — untouched.
- No new database queries.

## Testing

Manual verification in browser: cashier-role login, dark + light theme, desktop +
mobile breakpoint, focus banner in both pending/no-pending states.
