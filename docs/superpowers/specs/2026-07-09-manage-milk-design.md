# Manage Milk — Design

**Date:** 2026-07-09
**Branch context:** feat/product-addons (local, not pushed)
**Status:** Approved, ready for implementation plan

## Problem

Milk options in the product modal are a hardcoded PHP array in `menu.php`
(`['Fresh Milk','Almond Milk','Soy Milk','Oat Milk']`, default `Fresh Milk`).
Milk is real stock the cafe carries — it changes over time (run out of oat, add a
brand). Admins cannot change the list without editing code. Make the milk list
admin-manageable, mirroring the existing Manage Add-ons / Manage Categories admin
pages.

Sweetness and Ice are explicitly **out of scope** — they are fixed universal
standards (0%–100%, No/Less/Normal/More Ice) whose choice-set never changes.
They stay hardcoded.

## Model

Milk stays **single-select, free** (one milk per drink, no price). This is a pure
CRUD layer over the existing array. No pricing is introduced — that would thread
through cart/order/loyalty and is not needed. If an alt-milk upcharge is ever
wanted, a `price` column can be added later.

Milk is stored on the order as a plain **string snapshot** (`item['milk']`), exactly
as today. Renaming or archiving a milk type does **not** rewrite historical orders —
past orders keep the text they were placed with (historical accuracy). Therefore
none of the ~28 files that decode/display milk change.

## Data

New table `milk_options`:

```sql
CREATE TABLE IF NOT EXISTS milk_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_active_order (is_active, display_order)
) DEFAULT CHARSET=utf8mb4
```

Bootstrapped in `config.php` alongside `addons`: `CREATE TABLE IF NOT EXISTS` raw,
seed via guarded migration `milk_options_seed_v1` (only when `COUNT(*)==0`) inserting
the 4 current values with `Fresh Milk` as `is_default=1`:

| name        | display_order | is_active | is_default |
|-------------|---------------|-----------|------------|
| Fresh Milk  | 1             | 1         | 1          |
| Almond Milk | 2             | 1         | 0          |
| Soy Milk    | 3             | 1         | 0          |
| Oat Milk    | 4             | 1         | 0          |

**Invariant:** exactly one `is_default=1` across all rows (active-scoped). Enforced
in app logic, not a DB constraint.

## Admin page: `manage_milk.php`

Clone the shape of `manage_addons.php`, minus the price field, plus a "default"
radio. Single list section titled "Milk Options".

- **Gate:** `require 'admin_only.php'` (admin/manager only), CSRF using the
  `stands.php` token pattern — matches manage_addons.php / manage_categories.php.
- **Per row:** rename (name), reorder (display_order), Archive/Restore
  (`is_active` toggle), default radio (`is_default`).
- **Add new:** a create row appends a milk with the next `display_order`, `is_active=1`,
  `is_default=0`.
- **Archive-only (no hard delete):** matches manage_addons.php exactly — an archived
  milk keeps its row (seasonal menus; a milk you stop carrying may come back), a
  "show archived" filter reveals archived rows, and the button reads Archive/Restore.
  **No `delete` action** — deliberately consistent with the add-ons page.
- **Entry button:** add a link/button on `products.php` beside the existing
  "Manage Add-ons" / "Categories" entry buttons, gated by the same
  `$_can_manage_products` condition those use.

### Handlers (POST actions on manage_milk.php) — same names as manage_addons.php
- `create` — insert new milk (`is_active=1`, `is_default=0`, next display_order).
- `update` — update name.
- `reorder` — update display_order (mirror manage_addons/manage_categories reorder).
- `archive` — toggle is_active both ways. If archiving the current default, promote
  the first remaining active milk to default (see invariant) **and set a flash
  message** naming the new default (see edge rules).
- `set_default` — set this row is_default=1 and clear is_default on all others
  (single UPDATE clearing others + one setting this).

All handlers require a valid CSRF token; invalid token rejects.

## menu.php changes

1. Load milks once near the existing category-option load
   (`$co_res` around [menu.php:177](../../../menu.php#L177)):
   `SELECT id,name,is_default FROM milk_options WHERE is_active=1 ORDER BY display_order`.
   Capture the default name into `$defaultMilk` (fallback: first row's name; if no
   rows, empty string).
2. Replace the hardcoded foreach at [menu.php:1092-1094](../../../menu.php#L1092-L1094)
   to iterate the loaded milks; a pill gets `active` when its name === `$defaultMilk`.
3. Emit the default to JS (e.g. `var MILK_DEFAULT = <?= json_encode($defaultMilk) ?>;`)
   and change the reset line [menu.php:1282](../../../menu.php#L1282) to compare against
   `MILK_DEFAULT` instead of the literal `'Fresh Milk'`.
4. Per-category `offer_milk` gate is unchanged (still shows/hides the whole Milk
   section per category).
5. If zero active milks exist, hide the Milk section entirely (belt-and-suspenders
   with the offer flag) so no empty section renders and `getPillValue('milkPills')`
   never returns a stray value.

## add_to_cart.php — required change (not optional)

`add_to_cart.php` **already validates** the posted milk. Line 39 holds a hardcoded
whitelist `$valid_milk = ['Fresh Milk','Almond Milk','Soy Milk','Oat Milk','']` and
lines 47-48 reject anything else with HTTP 400 "Invalid milk option".

This whitelist will desync from `milk_options` the moment an admin adds a new milk:
the menu would offer it, but add_to_cart would 400-reject it — the new milk is
un-orderable. So this MUST change with the feature.

**Fix:** source `$valid_milk` from the DB instead of the literal —
`SELECT name FROM milk_options WHERE is_active=1`, then append `''` (the "no milk"
case). Keep the existing `!in_array(...) → json_out(...400)` reject; do NOT switch to
a silent drop (the codebase's convention is 400-reject, matching add-ons and the
sweetness/ice checks). This also covers the stale-cached-tab case: an archived milk is
absent from the active set, so a stale POST is rejected rather than silently accepted.

Sweetness and Ice whitelists (lines 37-38) stay hardcoded — out of scope.

## Out of scope / untouched

- Sweetness and Ice: stay hardcoded arrays in menu.php and add_to_cart.php.
- Order storage, cart, receipts (print/pdf/paylater), barista_display, view_order,
  edit_order_items, reports: unchanged — milk remains a string snapshot. The ~28
  decoder/display files are untouched; only add_to_cart.php (the validator/writer)
  changes.

## Edge rules

- Archiving the current default milk auto-promotes the first remaining active milk
  (by display_order) to default, preserving the single-default invariant. On promote,
  set a flash message so the change isn't silent, e.g.
  "Fresh Milk archived — Almond Milk is now the default." Displayed on the page like
  the add-ons/categories success flashes.
- If all milks are archived, menu hides the Milk section; new orders simply carry no
  milk value (same as a category with `offer_milk=0`). No default exists in this state;
  the invariant re-applies as soon as one milk is restored/created.
- Archiving a milk never touches historical orders (string snapshot).

## Testing

- Migration: fresh load creates `milk_options`, seeds 4 rows, Fresh Milk default.
  Re-load does not duplicate (guard `COUNT(*)==0`).
- manage_milk.php: create / update / reorder / archive / restore / set-default each
  persist; default radio always leaves exactly one default; archiving the default
  promotes another and shows the flash message; "show archived" filter reveals archived
  rows; no hard-delete action exists. Non-admin is redirected; missing CSRF rejects.
- add_to_cart.php: a newly-added milk is accepted (not 400-rejected); an archived or
  unknown milk string is rejected with 400 "Invalid milk option"; empty milk ('')
  still allowed.
- menu.php: modal renders milk pills from DB in display_order; default pill is
  pre-selected; archived milk disappears from the modal but old order still shows its
  text; category with `offer_milk=0` still hides the section; zero active milks hides
  the section.
- Live browser verify as admin (Sokun) per the add-ons/categories verification pattern.
