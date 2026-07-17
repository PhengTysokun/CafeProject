# Per-Product Promo — Design

**Date:** 2026-07-18
**Status:** Approved (design)
**Branch:** feat/product-addons

## Problem

The `products.badge_text` field ("15% OFF") is purely cosmetic — free text, attached to no
real discount. The only discount mechanisms are store-wide: Happy Hour (% off whole cart,
time-windowed) and Buy-X-Get-1 (display only), plus a manual cart-wide discount.

So when a product carries a "15% OFF" badge, the cashier has no way to discount *only that
product*. The workaround — the manual "Add Discount" — applies to the whole cart, discounting
every unrelated line too. The badge promises a discount the system cannot deliver.

## Goal

A product can carry a real promotion percentage. Adding that product to the cart applies the
discount to **only that line**. The badge stops lying — it is auto-generated from the real
promo number. The existing cart-wide manual discount is untouched (still available for
courtesy discounts).

Out of scope (explicit YAGNI): per-line *manual* discounts, promo start/end dates. Both can
be added later; this design does not preclude them.

## Design

### Data model

- **New column:** `products.promo_percent` TINYINT UNSIGNED NOT NULL DEFAULT `0`, range
  `0`–`100`. `0` = no promo. Added via a `_migrate()` block in `config.php` (mirror
  `products_badge_text`).
- **New column:** `order_items.promo_percent` TINYINT UNSIGNED NOT NULL DEFAULT `0` — a
  **snapshot** of the line's promo at order time. `order_items.price` stays the **full** unit
  price; the net paid for the line is derived, never stored. This keeps historical receipts
  truthful (list price + savings both recoverable) and keeps a promo change from mutating
  past orders.
- The two migrations are independent (no FK between them); order doesn't matter.

### Single source of truth: store full price, derive net

Neither the cart line nor `order_items` ever stores a *net* (post-promo) price. Both store the
**full** unit price plus `promo_percent`; every consumer derives the net. This avoids drift
between a stored net and a stored percent.

**Cart line fields** (session `cart`): existing fields unchanged, plus one new field:

| field | meaning |
|---|---|
| `price` | full pre-promo unit price = `drink_price + addon_sum` (unchanged from today) |
| `addons` | existing array of `{id,name,price}`; `addon_sum` = Σ their prices |
| `promo_percent` | **new** — snapshot of the product's promo at add time |

Derived per line:
```
drink_price   = price − addon_sum                 (the promo-eligible portion)
promo_saving  = round(drink_price × qty × promo_percent / 100, 2)   (half-up, on line total)
line_net_tot  = price × qty − promo_saving
```
Add-ons are never discounted, so subtracting them out gives the eligible base cleanly without
adding a redundant `drink_price` column.

### Badge derivation (one badge slot, promo wins)

Wherever a product badge renders today (menu cards, top-sellers, product modal):

- If `promo_percent > 0` → show auto badge `"{promo_percent}% OFF"`.
- Else → show existing free-text `badge_text` (unchanged behaviour).

`badge_text` stays free-text for non-promo labels ("New!", "Limited"). The admin product
form keeps `badge_text` and gains a `promo_percent` input (0 = none). When `promo_percent > 0`
the form visually indicates the override — grey out / disable the `badge_text` input and show
a live preview of the derived `"{promo_percent}% OFF"` badge — so the admin isn't surprised
that their typed badge is hidden.

### What the percent applies to

**The drink price only** (base price, or the chosen size's price) — **not add-ons.**

Rationale: "Iced Lemon Tea is 15% off" means the drink is on promo. Add-ons are separately
priced items the customer chose to add; discounting a $1 extra shot under a drink promo is
surprising and awkward to explain on a receipt. Add-ons remain full price.

The drink-eligible portion is recovered as `drink_price = price − addon_sum` (see "Single
source of truth" above) — no separate drink-price column needed. `add_to_cart.php` keeps
storing `price` as the full merged unit price and adds the `promo_percent` snapshot.

**Rounding:** the promo saving is rounded to the cent on the **line total**, half-up:
`round(drink_price × qty × promo_percent / 100, 2)`. Rounding on the line total (not per unit)
avoids per-unit rounding drift on multi-qty lines.

### Cart line display

Each promo line shows the original unit price struck through with the discounted unit price
beneath it (consistent with how sizes/add-ons already annotate the line). When the line has
add-ons, the struck/blended prices won't differ by exactly the promo % (add-ons aren't
discounted), so annotate with a small `(15% off drink)` note to pre-empt the "why isn't the
gap exactly 15%?" reaction.

### Stacking / order of operations

Item promo is intrinsic to the line, so it applies **first**, reducing the line and therefore
the subtotal. Store-wide promos then apply on top of the reduced subtotal:

```
promo_saving = round(drink_price × qty × promo% / 100, 2)   per line
subtotal     = Σ (price × qty − promo_saving)               net of item promos
Happy Hour   = subtotal × HAPPY_HOUR_DISCOUNT%              on the reduced subtotal
after        = subtotal − Happy Hour
manual       = manual discount applied to `after`           (unchanged)
after       −= manual
tax          = after × TAX_RATE%
total        = after + tax
Buy-X-Get-1  = display only, never reduces                  (unchanged)
```

A promo item **does** also get Happy Hour (stacks). Both are the shop's own settings.

**Buy-X-Get-1:** the tally is quantity-based and price-agnostic, so promo status doesn't
affect who qualifies. The free-item *value* shown in the display row uses the line's net
(post-promo) price, matching what the customer sees on that line.

### Summary row

New discount summary row `Item Promos  −$X.XX` = `Σ (drink_price × promo% × qty)`, shown in
the cart panel next to the existing Happy Hour / manual rows. Rendered server-side
(`menu.php` cart panel) and in the live-refresh JS (`cart_refresh.php` + the JS summary
builder in `menu.php`).

### Persistence & reporting

- `confirm_order.php` writes `order_items.promo_percent` per line and includes item-promo
  savings in the order's discount accounting so `total` is correct and reports are truthful.
- Where the order-level `promotion_discount` is written (currently `happy_hour + buy3`),
  add the aggregate item-promo saving so the order's recorded promotion total reflects reality.
- Receipts (`receipt_print.php`, `receipt_pdf.php`, `receipt_paylater.php`) show the item
  promo consistently across all three — an aggregate `Item Promos −$X.XX` discount line
  (same presentation the receipts already use for Happy Hour / manual). If the three receipts
  currently format discounts differently, align them to one presentation here.

### Editing an existing order

`edit_order_items.php` recomputes an edited order using the **snapshot**
`order_items.promo_percent`, not the product's current `promo_percent`. The admin is editing
a placed order, not repricing it against today's promos — the price the customer agreed to
stands even if the product's promo has since changed or ended.

## Affected files

- `config.php` — two `_migrate()` blocks (`products.promo_percent`, `order_items.promo_percent`).
- `add_product.php` — `promo_percent` input on add + edit; validate `0`–`100`; persist.
- `menu.php` — badge derivation (cards, top-sellers, modal); cart-line struck-price display;
  `Item Promos` summary row (server render + JS summary builder + openModal promo data attr).
- `add_to_cart.php` — snapshot `promo_percent` onto the cart line; compute net line price
  (drink only), keep add-on sum full.
- `cart_refresh.php` — include item-promo in live totals + summary row.
- `confirm_order.php` — persist `order_items.promo_percent`; fold item-promo into order
  discount total; correct `total`.
- `edit_order_items.php` — respect promo when recomputing an edited order.
- `receipt_print.php`, `receipt_pdf.php`, `receipt_paylater.php` — surface item promo.

## Testing

- Add one promo product + one non-promo product → only the promo line is discounted;
  non-promo line unchanged. (The reported bug.)
- Promo drink **with** add-ons → drink portion discounted, add-on full price.
- Sized promo drink → promo applies to the chosen size's price.
- Promo + Happy Hour active → Happy Hour computed on the reduced subtotal (stacks).
- Promo + cart-wide manual discount → both apply, correct order.
- `promo_percent = 0` → behaves exactly as today; free-text `badge_text` still shows.
- Confirm order → `order_items.promo_percent` stored; `total` and `promotion_discount`
  correct; receipt shows the promo.
- Edit an existing order with a promo line → totals recompute correctly.
