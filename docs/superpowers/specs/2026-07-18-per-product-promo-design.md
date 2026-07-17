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
  **snapshot** of the line's promo at order time, kept **for display only** (the "15% OFF"
  tag on receipts/edit screens). It does not drive any money math.
- **New column:** `order_items.orig_price` DECIMAL(10,2) NOT NULL DEFAULT `0` — the gross
  (pre-promo) unit price. Lets any report recover list-vs-charged precisely
  (`Σ (orig_price − price) × qty` = item-promo given) and lets receipts show a struck original,
  **without** perturbing the fragile `promotion_discount` receipt arithmetic (see below). On a
  no-promo line `orig_price == price`. Backfill for pre-feature rows is unnecessary — they have
  no promo and reports coalesce `orig_price` to `price` when `0`.
- The three migrations are independent (no FK between them); order doesn't matter.

### Store the NET price; promo is baked in at creation

**`order_items.price` stores the NET, actually-charged unit price** (drink after promo, plus
full-price add-ons). This is deliberate and is the key correctness decision.

Rationale: `order_items.price` is read for money by ~15+ downstream consumers —
`report.php`, `report_pdf.php`, `report_live.php`, `send_report.php`, `shift_report.php`, all
three receipts, `payment_cash.php`, `refund_order.php`, `update_status.php`, and the
add-to-order / edit-order recompute paths. If `price` were the gross list price, every one of
those would have to subtract the promo, and missing any single one silently mis-charges or
mis-reports. Storing the net makes all of them correct with **zero** changes, because the net
*is* the real line revenue. It also freezes the promo snapshot at creation for free — an
edited order never re-prices against today's promo.

`promo_percent` is stored alongside purely so a receipt/edit screen can show a "15% OFF" tag;
reports ignore it.

**Cart line fields** (session `cart`): existing fields unchanged in meaning, plus two new
fields. `price` continues to be the value every existing total sums — now it is the **net**
unit price, so all current cart totals stay correct automatically.

| field | meaning |
|---|---|
| `price` | **net** unit price actually charged = `net_drink + addon_sum` (was gross; now net) |
| `orig_price` | **new** — gross unit price before promo (`gross_drink + addon_sum`), for the struck-through display and the Item-Promos summing |
| `promo_percent` | **new** — snapshot of the product's promo at add time |
| `addons` | existing array of `{id,name,price}`; `addon_sum` = Σ their prices |

Derived at add time (`add_to_cart.php`), rounded **per unit** (standard for a stored unit
price; sub-cent vs. line-total rounding, and keeps every `Σ price×qty` site exact):
```
gross_drink = size/base price (before add-ons)
net_drink   = round(gross_drink × (1 − promo_percent/100), 2)
price       = net_drink   + addon_sum        ← stored, charged, summed everywhere
orig_price  = gross_drink + addon_sum        ← stored, for display + promo total
```
Item-promo saving for the summary row = `Σ (orig_price − price) × qty` (a plain subtraction of
two stored values — no re-derivation, no drift).

**Invariant (code-review checkpoint):** a cart line's `price`, `orig_price`, and
`promo_percent` are set together once at add time and never independently mutated afterward
(qty changes don't touch unit price). Flag any code path that writes one without the others.

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

`add_to_cart.php` computes `net_drink` from the size/base price (before add-ons are added),
then stores `price = net_drink + addon_sum` and `orig_price = gross_drink + addon_sum`.

**Rounding:** `net_drink = round(gross_drink × (1 − promo_percent/100), 2)` — rounded per unit,
which is standard when a unit price is stored and keeps every `Σ price×qty` total exact.

### Cart line display

Each promo line shows the original unit price (`orig_price`) struck through next to the
discounted unit price (`price`). When the line has add-ons, the struck/blended prices won't
differ by exactly the promo % (add-ons aren't discounted), so annotate with a small
`(15% off drink)` note to pre-empt the "why isn't the gap exactly 15%?" reaction.

### Stacking / order of operations

Item promo is intrinsic to the line, so it applies **first**, reducing the line and therefore
the subtotal. Store-wide promos then apply on top of the reduced subtotal:

Because the cart line `price` is already net, the existing subtotal loops need **no** promo
arithmetic — they keep summing `price × qty` and are automatically net of item promos:
```
subtotal     = Σ price × qty                                already net of item promos
item_promos  = Σ (orig_price − price) × qty                 shown as its own summary row
Happy Hour   = subtotal × HAPPY_HOUR_DISCOUNT%              on the (already-reduced) subtotal
after        = subtotal − Happy Hour
manual       = manual discount applied to `after`           (unchanged)
after       −= manual
tax          = after × TAX_RATE%
total        = after + tax
Buy-X-Get-1  = display only, never reduces                  (unchanged)
```
The only new computation any total site needs is `item_promos` for the display row; the
charged math is untouched.

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

- `confirm_order.php` writes each line's net `price` (as it already does — the value is now net)
  plus the new `order_items.promo_percent` and `order_items.orig_price` snapshots. The order
  `total` is already correct because the summed line prices are net.
- **`orders.promotion_discount` is left UNCHANGED** (still `happy_hour + buy3`). It must NOT
  gain the item-promo saving. Reason: `receipt_pdf.php` and `payment_cash.php` render the
  breakdown as `subtotal − promotion_discount`, and `subtotal` there is the sum of the
  now-**net** line prices. Adding item-promo to `promotion_discount` would subtract it a
  second time and the receipt breakdown would no longer add up (the stored-total fallback
  hides the grand total but leaves an inconsistent subtotal/discount line). The item promo is
  already reflected in `total` via the net line prices — counting it again is wrong.
- Item-promo "given" is still fully **reportable**, derived on demand as
  `Σ (orig_price − price) × qty` from `order_items`. No standalone report line is built now
  (YAGNI); the data is available when a report wants it.
- Receipts (`receipt_print.php`, `receipt_pdf.php`, `receipt_paylater.php`): line prices are
  already net (correct totals with no change). Enhancement: where a line's `promo_percent > 0`,
  show a small "15% OFF" tag on that line so the customer sees the promo was applied. Kept
  consistent across all three.

### Editing an existing order

`edit_order_items.php` needs **no** money-logic change: `order_items.price` is the frozen net
price, so its subtotal/total recompute is already correct and already "respects" the original
promo (the snapshot is baked into the stored price). Enhancement only: surface a "15% OFF" tag
on promo lines using the stored `promo_percent`.

## Affected files

- `config.php` — two `_migrate()` blocks (`products.promo_percent`, `order_items.promo_percent`).
- `add_product.php` — `promo_percent` input on the add form; validate `0`–`100`; persist on insert.
- `edit_product.php` — `promo_percent` input on the edit form; validate + persist on update;
  badge-override UX (grey out `badge_text`, live-preview derived badge).
- `menu.php` — badge derivation (cards, top-sellers, modal `data-product-promo` attr);
  cart-line struck-price display; `Item Promos` summary row (server render + JS renderer).
- `add_to_cart.php` — compute `net_drink`; store `price` (net), `orig_price` (gross),
  `promo_percent` on the cart line; add `promo_percent` to the identical-line merge key so a
  mid-session promo change never merges a net line with a full-price one.
- `cart_refresh.php` — emit `orig_price` + `promo_percent` per item and an `item_promos` total.
- `confirm_order.php` — write `order_items.promo_percent` and `order_items.orig_price`. Line
  `price` is already net (no charged-math change). **`promotion_discount` is NOT modified.**
- `edit_order_items.php` — display-only: show a promo tag on lines with `promo_percent > 0`
  (fetch the column). No money-logic change.
- `receipt_print.php`, `receipt_pdf.php`, `receipt_paylater.php` — fetch `promo_percent`, show a
  per-line "X% OFF" tag. No money-logic change (stored prices already net).

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
