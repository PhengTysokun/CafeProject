# Touch-First Restyle — Non-Manager Dashboard Home

**Date:** 2026-06-17
**Scope:** Visual restyle of the non-manager (cashier / barista / inventory clerk) home in `dashboard.php`. Same content, modern touch-first layout. No PHP logic, queries, permissions, or manager/admin view changes.

## Problem

The non-manager dashboard renders each nav group's tiles in a centered flex-wrap row. With only one or two tiles per group, every tile floats alone in the middle of a wide empty band — sparse, disconnected, lots of dead space, no hierarchy. The primary device is a touchscreen/tablet, where hover-dependent affordances and centered floaty layouts read especially poorly.

## Constraints

- **Device:** touchscreen / tablet. Large tap targets, no hover dependence, layout fills the screen.
- **Content:** unchanged. Every tile stays gated by its existing `can()` check. Nothing added or removed.
- **Theme:** stay within existing amber/dark CSS variables. No new colors.
- **Blast radius:** only the `else` branch (non-admin/manager) of the dashboard render + its scoped CSS (`.qa-*`). The focus-card logic, manager/admin view, data queries, and badge counts are untouched.

## Design

### 1. Aligned grid
- `.qa-tiles`: replace centered flex-wrap with CSS Grid: `display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:20px;`. Tiles left-align and stretch to fill each row edge-to-edge.
- `.qa-grid`: widen `max-width` 1000px → 1280px to fill the tablet screen. Keep the existing section-label dividers (`.qa-group-label`).

### 2. Hero "Take New Order" → full-width banner
- Currently a centered floating square (`align-self:center`). Change to a tall, full-width amber-gradient banner directly under the focus strip. Still only rendered for roles with `find_orders` (unchanged condition).

### 3. Touch behavior
- Add `:active` press feedback to `.qa-tile`, `.qa-hero-btn` (slight scale-down + brightness) so taps feel responsive.
- Keep `:hover` as progressive enhancement for mouse stations.
- Tiles remain ≥150px tall.

### 4. Tile polish
- Give each `.qa-tile` icon a soft tinted "chip" background (rounded square behind the glyph) and consistent elevation/border so tiles read as intentional cards. All within existing CSS variables.

## Per-role (identical content)
- **Cashier (`staff`):** focus card (payments) + Take New Order banner + Orders group (Orders, Find) + Loyalty + Account.
- **Barista:** focus card (drinks) + whatever `can()` grants (Orders / recipes) + Account.
- **Inventory clerk:** focus card (stock) + Inventory group + Procurement + Account.

## Out of scope
- Manager/admin dashboard view.
- Focus-card selection logic and any PHP/data changes.
- Adding live widgets, merging screens, or reprioritizing content (declined — "restyle, same content").

## Verification
Log in as each of the three test roles (see test-accounts memory) and confirm in-browser via Playwright that the home fills the screen, tiles align in a grid, the hero banner spans full width, tap feedback works, and no tile/content was lost vs. the current build.
