# Product Add-ons / Toppings — Design Spec

**Date:** 2026-07-08
**Branch (proposed):** `feat/product-addons`
**Status:** Approved design, pending implementation plan

## Problem

Admin has no way to define add-ons/toppings (boba, jelly, whipped cream, extra shot…),
and the customer menu modal (`menu.php`) offers only Size / Sweetness / Ice / Milk. Customers
cannot add priced toppings, and those extras never reach the cart total or the kitchen ticket.

Reference vision: a "Available Add-ons" chip section on the product editor, each add-on carrying
its own price, mirrored on the customer product modal where selecting them raises the total.

## Goals

- Admin maintains a **reusable add-on library** (add / edit / toggle / reorder). Adding one row
  makes it available to assign anywhere.
- Admin assigns which library add-ons a **specific product** offers (per-product chips).
- Customer picks any subset of a product's add-ons in the menu modal; each raises the live total.
- Selected add-ons persist through checkout and appear on cart, order views, and kitchen/receipt output.

## Non-Goals (YAGNI)

- No per-add-on **quantity** ("2× boba"). Add-ons are on/off toggles.
- No separate bilingual name column. Add-on `name` is a single field (Khmer can be typed inline,
  same as product names today).
- No per-add-on **sales reporting**. Order storage is a denormalized text snapshot, not a child table.
- No add-on-level inventory/recipe deduction. (Future work; out of scope.)

## Data Model

### New table: `addons` (the library)
| column | type | notes |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | |
| `name` | VARCHAR(100) | e.g. "Boba", "Whipped Cream" |
| `price` | DECIMAL(10,2) | added to line price when selected |
| `is_active` | TINYINT(1) DEFAULT 1 | inactive = hidden from assignment + menu |
| `display_order` | INT DEFAULT 0 | for reorder, mirrors `product_sizes.sort_order` style |

### New table: `product_addons` (mapping)
| column | type | notes |
|---|---|---|
| `product_id` | INT | FK → products.product_id |
| `addon_id` | INT | FK → addons.id |
| PRIMARY KEY (`product_id`, `addon_id`) | | dedupe pair |

Deleting an add-on from the library removes its `product_addons` rows (app-level or ON DELETE CASCADE).

### Altered table: `order_items`
- Add `addons` TEXT NULL — denormalized snapshot at checkout, e.g. `"Boba +$0.50, Whipped Cream +$0.75"`.
- The add-on prices are **already folded into** the existing `price` column (per-unit line price),
  so all downstream math (totals, loyalty, change) is untouched. `addons` is display-only.

`config.php` schema-bootstrap block gets matching `CREATE TABLE IF NOT EXISTS` / `ALTER` guards
so a fresh DB provisions correctly (follow existing bootstrap pattern; do NOT recreate zombie tables).

## Admin UI

### `manage_addons.php` (new) — library CRUD
- Clone the structure and CSRF/uiConfirm patterns of `manage_categories.php`.
- List rows with name, price, active toggle, up/down reorder (swap `display_order`), edit, delete.
- Delete blocked or cascades cleanly re: `product_addons` references (mirror category delete-guard decision:
  simplest = cascade the mapping rows and allow delete, since add-ons carry no order FKs — snapshot is text).
- Entry point: a "Manage Add-ons" button on `products.php`, beside the existing "Manage Categories" entry.

### `add_product.php` / `edit_product.php` — per-product assignment
- New "Available Add-ons" section rendering a chip per **active** library add-on: `name +$price`.
- Chip toggled on = selected; posts `addon_id[]`.
- On save: replace this product's `product_addons` rows with the posted set (delete-all + insert, same
  transactional pattern as `product_sizes`).
- `edit_product.php` pre-selects chips from existing `product_addons` rows.

## Customer UI (`menu.php`)

- Product modal gains an **Add-ons** `option-section` (styled like existing Sweetness/Ice/Milk sections),
  shown only when the product has ≥1 active assigned add-on.
- Rendered from the product's assigned add-ons; each pill shows `name +$price` and carries
  `data-addon-id` + `data-addon-price`.
- Pills are **multi-select** toggles (unlike the single-select option groups). Reuse a variant of
  `selectPill` that does not clear siblings for this group.
- Live **Total** recompute: base/size price + Σ(selected add-on prices), × qty. Extend the existing
  total-calculation JS.
- Section hides for product types where it makes no sense only if no add-ons are assigned — assignment
  already controls visibility, so no drink-type special-casing needed.

## Cart & Checkout Flow

### `add_to_cart.php`
- Accept `addons[]` (array of addon ids) from POST.
- Fetch the product's **assigned + active** add-ons from `product_addons ⋈ addons`; validate every posted
  id is in that set (reject otherwise, like the current sweetness/ice/milk whitelist checks).
- Build an ordered list of `{id, name, price}` for the valid selections.
- Fold `Σ price` into the cart item's per-unit `price`.
- Store on the cart item:
  - `addons` => array of `{id, name, price}` (structured, for cart edit/merge)
  - `addons_label` => `"Boba +$0.50, Whipped Cream +$0.75"` (display + checkout snapshot)
- **Merge key:** two lines are identical only if product, size, sweetness, ice, milk **and** the sorted
  add-on id set match. Add add-on signature to the merge comparison.

### `confirm_order.php`
- All **3** `INSERT INTO order_items (...)` sites gain the `addons` column, bound from `$item['addons_label'] ?? ''`.
- No change to total, loyalty, change, or stock-deduction logic (price already includes add-ons).

### Display surfaces (read-only, additive)
Show `addons_label` wherever sweetness/ice/milk already render:
`cart.php`, `view_order.php`, `edit_order_items.php`, `barista_display.php`,
`receipt_print.php` / `receipt_pdf.php` / `receipt_paylater.php`, and the menu cart panel.
For order views this reads the new `order_items.addons` column.

## Error Handling & Edge Cases

- Posted add-on not assigned/active → `add_to_cart.php` rejects with 400 (consistent with option whitelist).
- Add-on made inactive **after** it's in a cart → cart line already snapshotted price + label; leave as-is.
  Menu simply stops offering it.
- Add-on deleted from library after being on past orders → orders keep the text snapshot; no broken FK
  because order storage is text, not a join.
- Product with zero assigned add-ons → no Add-ons section renders; behaviour identical to today.
- Price edit in library → affects only future selections; existing carts/orders keep snapshot price.

## Testing

- **DB/bootstrap:** fresh `config.php` run creates `addons`, `product_addons`, and the `order_items.addons`
  column without error; re-run is idempotent (no zombie recreation).
- **Library CRUD:** add / edit / toggle / reorder / delete round-trips in `manage_addons.php`.
- **Assignment:** assign 2 add-ons to a product, reload edit page → chips pre-selected; unassign one → persists.
- **Menu math:** select 2 add-ons, qty 3 → total = (size price + a1 + a2) × 3; matches server total from `add_to_cart`.
- **Validation:** POST an add-on id not assigned to the product → 400 rejected.
- **Merge:** add same drink twice with identical add-ons → one line qty 2; differing add-ons → two lines.
- **Checkout end-to-end:** place order with add-ons → `order_items.addons` populated; receipt + barista
  display + view_order show the label; grand total correct.
- **Browser verification** (Playwright, per project convention): drive menu modal, confirm live total and
  cart label; screenshot light + dark.

## Rollout Notes

- New branch `feat/product-addons` off `main`. NOT auto-pushed/deployed (per project deploy discipline).
- `config.php` must not be uploaded raw on deploy (contains DB creds) — schema guards travel with the code,
  applied on target separately.
