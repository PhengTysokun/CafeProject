# Permission-Driven Navigation — Design Spec

**Date:** 2026-07-14
**Branch:** feat/product-addons
**Status:** Design approved — pending spec review

## Problem

`manage_roles.php` lets an admin grant any of the ~22 permissions to any role (system or custom). Access control works: every page enforces its own `can('perm')` guard, so a granted permission genuinely makes that page reachable. But **navigation** does not consistently reflect grants — the app has three unrelated nav systems:

- **Manager sidebar** (`$_is_mgr` = admin/manager/supervisor): a large hardcoded, `can()`-gated grouped sidebar. Mostly grant-driven already.
- **Inventory sidebar** (`inventory_clerk`): a small local permission→link map, `can()`-gated (added 2026-07-14).
- **Cashier / barista / custom roles**: a "focus card + `qx-grid` tiles" layout, only partially `can()`-gated.

Consequences: granting a permission to a cashier or a custom role often produces no visible entry point; each layout duplicates route/icon knowledge; new pages must be wired into multiple places. A newly created custom role has no coherent "these are the things you can do" surface.

## Goal

A single canonical **permission → navigation** registry that every role's dashboard renders from (filtered by `can()`), so any permission granted in `manage_roles` automatically surfaces a nav entry — for every role, including custom ones — with no per-role hardcoding.

## Non-Goals

- No changes to the RBAC backend: `manage_roles.php` CRUD, `role_permissions`, `role_audit_log`, CSRF, create/edit/delete/reassign roles — all unchanged.
- No changes to page-level `can()` guards. Those remain the actual access control; navigation is only discovery.
- No forced visual unification. Each layout keeps its own look (grouped sidebar vs flat sidebar vs tile grid); they only share the same underlying data.
- Barista's `view_order.php` workstation screen stays as-is (it's a station, not a nav hub).
- No new permissions and no schema changes.

## Architecture

### Single source of truth: `nav_menu.php`
A new shared include (required by `dashboard.php` and any layout that renders nav). It defines an **href/icon registry** keyed by permission slug and a helper that joins it to the `permissions` table.

The `permissions` table already supplies **label** (`name`), **section** (`module`), and **order** (`sort_order`). The registry only adds what the DB lacks: **href** and **icon** (and an `admin_only` flag where needed). This keeps labels/renames/sections flowing from the DB while route/icon knowledge lives in one PHP place.

```php
// nav_menu.php
// Route/icon registry: permission slug => [href, icon, admin_only?]
// Perms NOT listed here are non-navigable (sub-actions like manage_recipes,
// or specially-placed items like my_profile) and never render as a nav link.
// NOTE: `dashboard` is deliberately NOT in this registry. The home link is
// hardcoded (indestructible) at the top of every layout, independent of can(),
// so a corrupted/empty permission set can never trap a user without a way home.
$NAV_REGISTRY = [
    'find_orders'         => ['find_order.php',             'fa-magnifying-glass'],
    'view_orders'         => ['view_order.php',             'fa-receipt'],
    'loyalty'             => ['loyalty_dashboard.php',      'fa-star'],
    'products'            => ['products.php',               'fa-cube'],
    'ingredients'         => ['ingredients.php',            'fa-flask'],
    'recipes'             => ['recipes_view.php',           'fa-utensils'],
    'suppliers'           => ['suppliers.php',              'fa-truck-ramp-box'],
    'purchase_orders'     => ['purchase_orders.php',        'fa-file-invoice'],
    'stock_count'         => ['stock_count.php',            'fa-clipboard-list'],
    'cash_reconciliation' => ['reconciliation_report.php',  'fa-cash-register'],
    'report'              => ['report.php',                 'fa-chart-column'],
    'barista_station'     => ['barista_display.php',        'fa-mug-hot'],
    'customer_display'    => ['customer_display.php',       'fa-display'],
    'employees'           => ['employees.php',              'fa-user-tie'],
    'attendance'          => ['attendance.php',             'fa-fingerprint'],
    'announcements'       => ['announcements.php',          'fa-bullhorn'],
    'promotions'          => ['settings.php',               'fa-gear'],
    'reset_password'      => ['admin_reset_password.php',   'fa-key'],
    'manage_roles'        => ['manage_roles.php',           'fa-shield-halved', true], // admin_only
    // NOT navigable (no entry): manage_recipes (sub-action of recipes),
    // my_profile (rendered as the profile chip, not a nav link),
    // dashboard (hardcoded home link per layout — see note above).
];

// Explicit section display order — do NOT rely on DB/module insertion order.
// Sections absent here (or DB modules not listed) fall to the end, tie-broken by name.
$SECTION_ORDER = [
    'Orders', 'Operations', 'Inventory', 'Procurement', 'Reconciliation',
    'Loyalty', 'Analytics', 'Staff', 'Admin',
];

/**
 * Returns the nav items the current user may see, each as:
 *   ['slug','label','href','icon','section','order','admin_only']
 * Filtered by can() (and admin_only). Sorted by ($SECTION_ORDER index of module,
 * then permissions.sort_order, then id) — the DB's own module/row order is never
 * trusted for display order.
 * Reads label/section/order from the permissions table; href/icon from registry.
 */
function nav_items(mysqli $conn): array { /* join registry × permissions, filter can(), sort */ }

/** Same items grouped by section in $SECTION_ORDER: ['Inventory'=>[...], ...]. */
function nav_items_grouped(mysqli $conn): array { /* ... */ }
```

Section display order is governed by the explicit `$SECTION_ORDER` array above (sorted via `usort`), so every layout groups identically regardless of DB indexing.

### How each layout consumes it
All layouts call `nav_items()` / `nav_items_grouped()` — they never hardcode routes again.

- **Manager sidebar** (`$_is_mgr`): render `nav_items_grouped()` as the existing collapsible nav groups (group label = section, items = links). Replaces the ~250 lines of hardcoded `can()` blocks. Same look, now map-driven.
- **Inventory sidebar** (`inventory_clerk`): render `nav_items()` as the flat `.inv-navitem` list (replaces the local `$inv_nav` array added 2026-07-14).
- **Cashier / generic / custom roles**: render `nav_items_grouped()` as the `qx-grid` tile sections (group label = section, tiles = items). Replaces the partially-gated hardcoded tiles.

### Custom roles
A custom role (`is_system = 0`, created via `manage_roles.php`) needs no bespoke code. It falls into the **generic dashboard branch** and renders `nav_items_grouped()` as tiles — showing exactly the permissions it was granted, grouped and ordered consistently.

**Permission-driven focus card (replaces today's role-hardcoded focus):** the landing focus card is chosen from what the role actually has, not from a hardcoded role→focus mapping. Pick the first of these the role can do (in this priority): `view_orders`/`find_orders` → "Focus on Orders"; `products`/`ingredients`/`stock_count` → "Focus on Inventory"; `barista_station` → "Focus on Barista"; else fall through. If the role has **no** navigable permission, show the actionable empty state (below) instead of a focus card. This makes even a role an admin threw together in 30 seconds land somewhere sensible.

### Special items (not plain nav links)
- **`dashboard`**: the home itself. Each layout renders a fixed "Dashboard" item at the top; it is not driven by a registry link (present for all authenticated users).
- **`my_profile`**: rendered as the profile chip/avatar (existing pattern), gated by `can('my_profile')`. Not a main-nav link.
- **`manage_recipes`**: a sub-action permission for editing within `recipes`/`manage_recipe.php`; no standalone nav entry.
- **`manage_roles`**: `admin_only` flag — shown only when the user is `admin` (the page also hard-guards `role==='admin'`), keeping nav consistent with the page guard.
- **`promotions`**: maps to `settings.php` labeled per its permission name; the Settings page itself guards on `can('promotions')`.

## The contract (rules this establishes)

1. **Access control lives on the page.** Every navigable page keeps its `if (!can('slug')) redirect` guard. Navigation never grants access; it only reveals reachable pages.
2. **Nav visibility = `can(slug)` over the registry.** Grant a permission → its link appears for that role; revoke → it disappears. Uniformly across all layouts.
3. **One registry entry per navigable page.** Route + icon are defined exactly once, in `nav_menu.php`.
4. **New-page checklist:** (a) create the page with its `can('slug')` guard, (b) ensure the permission row exists (config.php migration), (c) add one `$NAV_REGISTRY` entry. Skipping (c) leaves the page secured and URL-reachable but with no auto-link (acceptable, documented).
5. **A permission with no registry entry is non-navigable by design** (sub-actions, specially-placed items).

## Edge cases

- **Permission granted but page missing/removed:** registry entry absent → no link (safe). If entry present but file deleted, link 404s — caught by the new-page checklist / a smoke check.
- **Custom role with zero permissions:** generic dashboard shows the (indestructible) Dashboard home item + an **actionable** empty-state card — "No areas assigned yet. Contact your system administrator to adjust your permissions." — so the user is never a dead-end wondering what to do. No crash.
- **Renamed permission (admin edits label):** label flows from the `permissions` table, so nav updates automatically; href/icon unaffected.
- **New custom permission slug (future):** if an admin-defined perm has no registry entry, it simply doesn't appear in nav (documented); adding nav requires a registry line.
- **`sort_order` collisions** (e.g. `cash_reconciliation` and `customer_display` both = 21): tie-break by `id` for stable ordering.

## Phasing (for the implementation plan)

- **Phase 1:** Build `nav_menu.php` (registry + `nav_items`/`nav_items_grouped`). Switch the **inventory** sidebar and the **generic/custom** dashboard branch to consume it. Define the custom-role landing + empty state. (Lowest risk; inventory already proves the pattern.)
- **Phase 2:** Migrate the **manager grouped sidebar** to `nav_items_grouped()`, preserving the collapsible-group look. (Highest-surface change; do after P1 is proven.)
- **Phase 3:** Migrate the **cashier** tile layout to the grouped tiles from the map.

Each phase leaves the app fully working; a role's nav is identical or strictly more consistent after each.

## Testing / verification

- No unit-test framework in this repo → verify via `php -l` + browser as each role.
- **Per role:** log in as admin (`Sokun`), cashier (`Sok_Dara`), barista (`darasokun`), inventory (`Clerk_Sokun`); confirm each nav shows exactly its granted, registry-listed permissions, grouped/ordered consistently.
- **Grant/revoke round-trip:** as admin, grant a cross-domain perm (e.g. `cash_reconciliation`) to the inventory role in `manage_roles.php`; reload the clerk dashboard → the "Cash Count" link appears; revoke → it disappears. No code change.
- **Custom role:** create a role with a couple permissions; confirm it lands on the generic dashboard rendering exactly those, plus the empty-state when it has none.
- **Regression:** confirm each page's `can()` guard still blocks direct-URL access for ungranted perms (navigation change must not weaken guards).

## Related
- `docs/superpowers/specs/2026-07-13-inventory-dashboard-redesign-design.md` (the inventory nav that seeded this pattern)
- Memory: `inventory-dashboard-redesign`, `rbac-role-permissions-gotcha`, `project-overview`
