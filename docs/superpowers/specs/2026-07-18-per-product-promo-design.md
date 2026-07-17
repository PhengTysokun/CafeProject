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

- **New column:** `products.promo_percent` TINYINT UNSIGNED, `0`–`100`, default `0`.
  `0` = no promo. Added via a `_migrate()` block in `config.php` (mirror
  `products_badge_text`).
- **New column:** `order_items.promo_percent` TINYINT UNSIGNED default `0` — a **snapshot**
  of the line's promo at order time. `order_items.price` stays the **full** unit price; the
  net paid for the line is `price × (1 − promo_percent/100)`. This keeps historical receipts
  truthful (list price + savings both recoverable) and keeps a promo change from mutating
  past orders.

### Badge derivation (one badge slot, promo wins)

Wherever a product badge renders today (menu cards, top-sellers, product modal):

- If `promo_percent > 0` → show auto badge `"{promo_percent}% OFF"`.
- Else → show existing free-text `badge_text` (unchanged behaviour).

`badge_text` stays free-text for non-promo labels ("New!", "Limited"). The admin product
form keeps `badge_text` and gains a `promo_percent` input (0 = none), with a note that a
non-zero promo overrides the badge text.

### What the percent applies to

**The drink price only** (base price, or the chosen size's price) — **not add-ons.**

Rationale: "Iced Lemon Tea is 15% off" means the drink is on promo. Add-ons are separately
priced items the customer chose to add; discounting a $1 extra shot under a drink promo is
surprising and awkward to explain on a receipt. Add-ons remain full price.

Implication for the cart line: today `add_to_cart.php` stores a single merged
`price = size_price + addon_sum`. To discount the drink portion only, the line must keep the
drink price and the add-on sum distinguishable. Store `promo_percent` on the line and compute
the promo saving as `drink_price × promo_percent/100` (drink_price = size/base price, the
value *before* `$line_price += $addon_sum`). The line's displayed/charged unit price becomes
`(drink_price − promo_saving) + addon_sum`.

### Cart line display

Each promo line shows the original unit price struck through with the discounted unit price
beneath it (consistent with how sizes/add-ons already annotate the line).

### Stacking / order of operations

Item promo is intrinsic to the line, so it applies **first**, reducing the line and therefore
the subtotal. Store-wide promos then apply on top of the reduced subtotal:

```
line_net   = (drink_price − drink_price×promo%) + addon_sum      (per unit)
subtotal   = Σ line_net × qty
Happy Hour = subtotal × HAPPY_HOUR_DISCOUNT%        (on the reduced subtotal)
after      = subtotal − Happy Hour
manual     = manual discount applied to `after`     (unchanged)
after     −= manual
tax        = after × TAX_RATE%
total      = after + tax
Buy-X-Get-1 = display only, never reduces           (unchanged)
```

A promo item **does** also get Happy Hour (stacks). Both are the shop's own settings.

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
  promo — either per-line (struck original) or an aggregate promo line, matching each
  receipt's existing discount presentation.

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
