# Merch / Non-Drink Sellable Items — Design

**Date:** 2026-07-18
**Status:** Approved (design)
**Branch:** feat/product-addons

## Problem

The POS only sells drinks. A customer who wants a shirt, mug, or other non-drink item can't
be rung up. Everything in the catalog assumes a drink: sweetness/ice/milk options, a recipe
that deducts ingredient stock, and loyalty points ("1 point per item").

## Goal

Sell non-drink items (merch) for money through the normal order flow — cart, all payment
types, receipts, refunds, and the paid-revenue gate — with **no size/sweet/ice/milk/recipe**
and **without earning loyalty points**. Reuse the existing catalog rather than building a
separate retail system.

Out of scope (explicit YAGNI): merch inventory/stock tracking; excluding merch from
"top drinks"/best-seller stats (cosmetic — merch may appear if sold heavily; revisit later —
leave a one-line TODO comment at the best-seller query so it's not a future mystery);
"open item" / cashier-typed arbitrary price (add only if genuinely random one-offs appear).

## Design

The whole drink pipeline already supports a "no-options, no-recipe" product: a category can
toggle off sweetness/ice/milk/add-ons, and a product with no `product_ingredients` rows skips
stock deduction. So merch is a **category configuration plus one real behavioural change** —
merch must not earn loyalty points.

### Data model

- **New column:** `categories.earns_points` TINYINT(1) NOT NULL DEFAULT `1`. `1` = items in
  this category earn loyalty points (drinks); `0` = they don't (merch). Added via `_migrate`,
  mirroring the existing `categories_offer_addons_v1` migration.
- **New column:** `order_items.earns_points` TINYINT(1) NOT NULL DEFAULT `1` — a **snapshot**
  of the item's point-eligibility at order time (same pattern as `order_items.promo_percent`).
  Keeps loyalty recomputes on order edits correct and independent of later category changes.
- **Cart line field:** `earns_points` (0/1), stamped at add-to-cart from the product's category.

### Category management (`manage_categories.php`)

Add an **"Earns points"** checkbox to the add and edit category forms (default checked),
following the existing `offer_addons` checkbox exactly:
- POST read on add + edit (`$ep = isset($_POST['earns_points']) ? 1 : 0;`).
- Include `earns_points` in the INSERT and UPDATE column lists + binds.
- Include `c.earns_points` in the category-list SELECT.
- Render the checkbox in both forms, placed alongside the existing offer toggles so the admin
  sees the full config in one place. A "No points" pill on the category card is optional —
  skip it if it complicates the diff.

The shop then creates a **"Merch"** category: name "Merch", offers sweetness/ice/milk/add-ons
all OFF, earns_points OFF. Products (T-Shirt, Mug…) are added under it with name + price +
image, `has_sizes` off, no recipe. Pure configuration — no code beyond the flag.

### Snapshot at add-to-cart (`add_to_cart.php`)

The product fetch currently selects from `products` alone. Change it to a single **LEFT JOIN**
on `categories` so the boolean comes back in the same query (one round-trip, not two):
`SELECT p.product_id, p.name, p.price, p.image, p.has_sizes, p.promo_percent,
COALESCE(c.earns_points,1) AS earns_points FROM products p
LEFT JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ?`.
LEFT JOIN + COALESCE→1 means a product with no/again-unresolved category still earns points
(safe default). Stamp `earns_points` (0/1) onto the cart line alongside the existing fields.

### Loyalty points math (the one real behavioural change)

Points must count only point-earning, chargeable items. Today three spots compute points; each
already excludes $0 gifts, and now also excludes non-earning categories:

**Rule:** `points_qty = Σ item.qty WHERE item.earns_points = 1 AND item.price > 0`.

- `confirm_order.php` **new-order path**: currently sets `points_earned = total_qty` (counts
  everything). Change to sum the cart's point-earning, priced items.
- `confirm_order.php` **add-to-order path**: the existing `$points_qty` loop (counts `price>0`)
  also gates on the cart line's `earns_points`; existing DB items are re-summed from
  `order_items.earns_points`.
- `edit_order_items.php` **loyalty sync**: the `$points_qty` recompute reads
  `order_items.earns_points` (add the column to its item SELECT) and gates on it.

Persist `order_items.earns_points` in all three `order_items` INSERTs in `confirm_order.php`
(new, add-to-order, reward-gift → gift = 0), same as the `promo_percent`/`orig_price` snapshots.

### What is reused unchanged

Menu rendering (a merch product shows just qty + Add to Cart because its category offers are
off), cart, all payment methods, receipts (print/pdf/paylater), refunds, and the paid-revenue
gate (`paid_orders_where()`). A merch sale counts as revenue exactly like a drink.

**Verified — `paid_orders_where()` is merch-safe:** it filters on `orders.is_open` and
`orders.status` only, with no join to `products`/`categories` and no drink-specific column, so
a merch order counts as revenue on the same terms as a drink. No change needed there.

**Migrations run `categories.earns_points` before `order_items.earns_points`** (no hard FK
dependency; ordered for clarity).

## Affected files

- `config.php` — two `_migrate` blocks (`categories.earns_points`, `order_items.earns_points`).
- `manage_categories.php` — earns_points checkbox on add/edit forms; INSERT/UPDATE/SELECT; card pill.
- `add_to_cart.php` — fetch category `earns_points`; stamp it on the cart line.
- `confirm_order.php` — persist `order_items.earns_points` (3 INSERTs); points = earning+priced items (new + add-to-order paths).
- `edit_order_items.php` — read `order_items.earns_points`; gate the loyalty-sync points recompute.

No changes needed to menu.php, receipts, or payment flows — merch rides the existing rails.

## Testing

- Create a Merch category (options off, earns_points off) + a "T-Shirt $10" product.
- Add the shirt to a cart → shows only qty + Add to Cart (no size/sweet/ice/milk), charges $10.
- Checkout with a loyalty card + 1 shirt + 1 drink → card earns points for the **drink only**,
  not the shirt.
- Receipt shows the shirt line + correct total; revenue (paid gate) includes the shirt sale.
- Shirt has no recipe → no stock deduction, no shortfall warning.
- Edit a Pay Later order containing a shirt + drink → loyalty points recompute counts the drink
  only; totals stay correct.
- A drink category still earns points normally (regression).
