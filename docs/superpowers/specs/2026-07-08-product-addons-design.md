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
- No per-add-on **sales reporting**. Order storage is a denormalized JSON snapshot, not a child table.
- No add-on-level inventory/recipe deduction. (Future work; out of scope.)
- No **hard delete** of add-ons. Coffee menus are seasonal (a "Pumpkin Spice" add-on leaves in Jan,
  returns in Oct); a hard delete would orphan every product mapping. Archive via `is_active=0` only.

## Future (V2, out of scope)

- **Add-on sets / presets** — e.g. "Make it vegan" = oat milk + no whip, applied in one tap. The library +
  per-product model already supports doing this manually; a preset layer is a pure convenience add-on later.
  Flag now so the data model stays compatible; do not build.

## Data Model

### New table: `addons` (the library)
| column | type | notes |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | |
| `name` | VARCHAR(100) | e.g. "Boba", "Whipped Cream" |
| `price` | DECIMAL(10,2) | added to line price when selected |
| `is_active` | TINYINT(1) DEFAULT 1 | `0` = archived: hidden from assignment + menu, kept for reactivation |
| `display_order` | INT DEFAULT 0 | render order everywhere (menu, chips, barista ticket); mirrors `product_sizes.sort_order` |

### New table: `product_addons` (mapping)
| column | type | notes |
|---|---|---|
| `product_id` | INT | FK → products.product_id |
| `addon_id` | INT | FK → addons.id |
| PRIMARY KEY (`product_id`, `addon_id`) | | dedupe pair |

Mappings **persist through archiving** — archiving an add-on (`is_active=0`) leaves its `product_addons`
rows intact, so reactivating it in October restores all assignments with zero re-work. Rows are removed
only when a product is unassigned in the editor, or the product itself is deleted.

### Altered table: `order_items`
- Add `addons` TEXT NULL — denormalized **JSON snapshot** at checkout, an ordered array:
  `[{"id":5,"name":"Extra Shot","price":1.00},{"id":2,"name":"Oat Milk","price":0.75}]`.
- JSON (not a "+$" string) so every display surface can render it its own way — barista ticket highlights
  names, receipts show name+price — without parsing text. Array order = `display_order`, preserved end to end.
- Add-on prices are **already folded into** the existing `price` column (per-unit line price),
  so all downstream math (totals, loyalty, change) is untouched. The `addons` JSON is display-only.

`config.php` schema-bootstrap block gets matching `CREATE TABLE IF NOT EXISTS` / `ALTER` guards
so a fresh DB provisions correctly (follow existing bootstrap pattern; do NOT recreate zombie tables).

## Admin UI

### `manage_addons.php` (new) — library CRUD
- Clone the structure and CSRF/uiConfirm patterns of `manage_categories.php`.
- List rows with name, price, up/down reorder (swap `display_order`), edit, and an **Archive/Restore**
  toggle (`is_active`). No hard-delete button.
- Default list shows active add-ons; a "Show archived" toggle reveals inactive ones for restoring.
- Entry point: a "Manage Add-ons" button on `products.php`, beside the existing "Manage Categories" entry.

### `add_product.php` / `edit_product.php` — per-product assignment
- New "Available Add-ons" section rendering a chip per **active** library add-on: `name +$price`.
- Chip toggled on = selected; posts `addon_id[]`.
- On save: replace this product's `product_addons` rows with the posted set (delete-all + insert, same
  transactional pattern as `product_sizes`).
- `edit_product.php` pre-selects chips from existing `product_addons` rows.

## Customer UI (`menu.php`)

**Data delivery — mirror the existing sizes pattern, no N+1.** menu.php already batch-loads all sizes in
one query into `$sizesByProduct` and embeds them per-card as a `data-product-sizes` JSON attribute
([menu.php:143-148](menu.php#L143), [:664](menu.php#L664)). Add-ons do the same: one
`SELECT ... FROM product_addons JOIN addons ... WHERE is_active=1 ORDER BY product_id, display_order ASC`
into `$addonsByProduct`, embedded as `data-product-addons`. One query for the whole 40+ drink menu.

- Product modal gains an **Add-ons** `option-section` (styled like existing Sweetness/Ice/Milk sections),
  shown only when the product has ≥1 active assigned add-on.
- Rendered from the product's `data-product-addons`, **in `display_order` ascending** (the query already
  sorts it); each pill shows `name +$price` and carries `data-addon-id` + `data-addon-price`.
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
- Build an **ordered** list of `{id, name, price}` for the valid selections, sorted by the add-on's
  `display_order` (so barista/receipt order is deterministic and matches the menu).
- Fold `Σ price` into the cart item's per-unit `price`.
- Store on the cart item:
  - `addons` => array of `{id, name, price}` (structured, for cart edit/merge and JSON snapshot)
- **Merge key:** two lines are identical only if product, size, sweetness, ice, milk **and** the sorted
  add-on id set match. Add add-on signature to the merge comparison.

### `confirm_order.php`
- All **3** `INSERT INTO order_items (...)` sites gain the `addons` column, bound from
  `json_encode($item['addons'] ?? [])`.
- No change to total, loyalty, change, or stock-deduction logic (price already includes add-ons).

### Display surfaces (read-only, additive)
Each surface `json_decode`s `order_items.addons` (or reads the cart item's `addons` array) and renders it
where sweetness/ice/milk already appear: `cart.php`, `view_order.php`, `edit_order_items.php`,
`barista_display.php`, `receipt_print.php` / `receipt_pdf.php` / `receipt_paylater.php`, and the menu cart panel.

**Barista ticket priority (coffee workflow):** add-ons are the modification the barista must see first.
On `barista_display.php`, the current `mods` list is `[sweetness, ice, milk]` ([barista_display.php:290-292](barista_display.php#L290));
**prepend** add-on names so a ticket reads `Latte → Oat Milk, Extra Shot, 50%, Normal ice` — add-ons above
size/sweetness/ice. Preserve the stored array order. Receipts show `name +$price`; barista display shows
names only (price is noise at the machine).

## Error Handling & Edge Cases

- Posted add-on not assigned/active → `add_to_cart.php` rejects with 400 (consistent with option whitelist).
- Add-on **archived** (`is_active=0`) after it's in a cart → cart line already snapshotted price + name;
  leave as-is. Menu simply stops offering it; mapping row survives for later reactivation.
- Add-on archived after being on past orders → orders keep the JSON snapshot; no broken FK because
  order storage is JSON text, not a join.
- Product with zero assigned add-ons → no Add-ons section renders; behaviour identical to today.
- Price edit in library → affects only future selections; existing carts/orders keep snapshot price.
- Malformed/empty `order_items.addons` (legacy rows = NULL) → `json_decode` yields null; render nothing.

## Testing

- **DB/bootstrap:** fresh `config.php` run creates `addons`, `product_addons`, and the `order_items.addons`
  column without error; re-run is idempotent (no zombie recreation).
- **Library CRUD:** add / edit / reorder / archive / restore round-trips in `manage_addons.php`.
- **Archive keeps mapping:** assign add-on to a product, archive it, restore it → product still has it assigned.
- **Assignment:** assign 2 add-ons to a product, reload edit page → chips pre-selected; unassign one → persists.
- **Menu math:** select 2 add-ons, qty 3 → total = (size price + a1 + a2) × 3; matches server total from `add_to_cart`.
- **Order & display order:** add-ons render in `display_order` on menu, barista ticket, and receipt (not insertion order).
- **Validation:** POST an add-on id not assigned to the product → 400 rejected.
- **Merge:** add same drink twice with identical add-ons → one line qty 2; differing add-ons → two lines.
- **Checkout end-to-end:** place order with add-ons → `order_items.addons` holds valid JSON; `json_decode`
  on receipt + barista display + view_order renders correctly; barista shows add-ons above size/sweet; grand total correct.
- **Legacy safety:** an order row with `addons = NULL` renders with no add-on line, no PHP warning.
- **Browser verification** (Playwright, per project convention): drive menu modal, confirm live total and
  cart label; screenshot light + dark.

## Rollout Notes

- New branch `feat/product-addons` off `main`. NOT auto-pushed/deployed (per project deploy discipline).
- `config.php` must not be uploaded raw on deploy (contains DB creds) — schema guards travel with the code,
  applied on target separately.
