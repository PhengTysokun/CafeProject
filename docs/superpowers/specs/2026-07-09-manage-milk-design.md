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
- **Per row:** rename (name), reorder (display_order), active toggle (`is_active`),
  default radio (`is_default`), delete.
- **Add new:** a create row appends a milk with the next `display_order`, `is_active=1`,
  `is_default=0`.
- **Soft-archive:** deactivating via `is_active=0` keeps the row (seasonal menus),
  same convention as add-ons. Hard delete is also allowed (see edge rules).
- **Entry button:** add a link/button on `products.php` beside the existing
  "Manage Add-ons" / "Categories" entry buttons, gated by the same
  `$_can_manage_products` condition those use.

### Handlers (POST actions on manage_milk.php)
- `add` — insert new milk.
- `rename` — update name.
- `toggle_active` — flip is_active. If deactivating the current default, promote
  the first remaining active milk to default (see invariant).
- `set_default` — set this row is_default=1 and clear is_default on all others
  (single UPDATE clearing others + one setting this).
- `reorder` — update display_order (mirror manage_categories reorder approach).
- `delete` — hard delete the row. If it was the default, promote the first
  remaining active milk to default.

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

## Out of scope / untouched

- Sweetness and Ice: stay hardcoded arrays in menu.php.
- Order storage, cart, receipts (print/pdf/paylater), barista_display, view_order,
  edit_order_items, reports: unchanged — milk remains a string snapshot.
- `add_to_cart.php` does not validate the posted milk string against the active list.
  This matches today's behavior (any milk string is accepted); milk is free so there
  is no price/security impact. **Known-minor, deferred.**

## Edge rules

- Archiving or deleting the current default milk auto-promotes the first remaining
  active milk (by display_order) to default, preserving the single-default invariant.
- If all milks are archived/deleted, menu hides the Milk section; new orders simply
  carry no milk value (same as a category with `offer_milk=0`).
- Deleting a milk never touches historical orders (string snapshot).

## Testing

- Migration: fresh load creates `milk_options`, seeds 4 rows, Fresh Milk default.
  Re-load does not duplicate (guard `COUNT(*)==0`).
- manage_milk.php: add / rename / reorder / toggle-active / set-default / delete each
  persist; default radio always leaves exactly one default; deactivating/deleting the
  default promotes another. Non-admin is redirected; missing CSRF rejects.
- menu.php: modal renders milk pills from DB in display_order; default pill is
  pre-selected; archived milk disappears from the modal but old order still shows its
  text; category with `offer_milk=0` still hides the section; zero active milks hides
  the section.
- Live browser verify as admin (Sokun) per the add-ons/categories verification pattern.
