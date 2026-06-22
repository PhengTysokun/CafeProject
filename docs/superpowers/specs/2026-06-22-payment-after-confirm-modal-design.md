# Payment-After-Confirm Modal — Design

**Date:** 2026-06-22
**Status:** Approved (design), pending implementation plan
**Scope:** `menu.php` cart panel only

## Problem

Real-world cashier flow: customer tells order → cashier adds drinks → confirms →
total is calculated → *then* cashier asks how to pay (Bakong / Cash / Pay Later /
Riel, or a Bakong+Cash split).

Current UI puts payment selection inline in the cart panel **above** the Confirm
Order button, so the cashier must choose payment before seeing the finalized
total context. The order of operations is backwards from how the counter actually
works.

## Goal

Move payment selection into a **modal that opens when Confirm Order is clicked**,
showing the total prominently with payment buttons beneath it. Split payment
(Bakong+Cash) is preserved.

## Key finding — no backend work

The backend already fully supports this:

- `order_payments` table stores multiple methods/amounts/references per order.
- `confirm_order.php` already reads `payment_methods[]`, `payment_amounts[]`,
  `payment_references[]`, validates split combos (paylater/riel are solo-only),
  and sets order status accordingly (`PendingPayment` for Bakong, `Preparing`
  for cash, etc.).
- `menu.php` already has multi-select payment + split logic
  (`cpTogglePayment`, `cpUpdateSplitInputs`, `cpOnSplitChange`, change/riel
  calculators) — it just lives inline in the panel today.

So this is a **UI relocation**, not new functionality.

## Design

### Cart panel (after change) keeps
Items list, loyalty, **order type**, **customer name**, **stand number**, total
row, and the **Confirm Order** button. The button no longer submits the form —
it **opens the payment modal** (after validating the cart is non-empty).

### Payment modal (new)
Contains the relocated payment block:

```
+---------------------------------+
|  Payment              [x]       |
|                                 |
|  Subtotal            $3.50      |
|  Tax (5%)            $0.18      |
|  TOTAL               $3.68      |   <- prominent
|  -----------------------------  |
|  [📱 Bakong] [💵 Cash]          |
|  [⏰ Later]  [🇰🇭 Riel]          |   <- multi-select; Bakong+Cash = split
|                                 |
|  (split rows / change calc /    |
|   riel calc appear here)        |
|                                 |
|  [ Confirm Payment ]            |
+---------------------------------+
```

- Payment-method checkboxes, split inputs (`#cpSplitInputs`), change calculator
  (`#cpChangeCalc`), riel calculator (`#cpRielCalc`) are **moved** from the panel
  into the modal body. Same IDs, same handlers — no logic rewrite.
- **Confirm Payment** triggers the existing submit handler that builds the hidden
  `payment_methods[]/amounts[]/references[]` inputs and posts to
  `confirm_order.php`.

### Behavior decisions (approved)
1. Customer name / stand number / order type **stay in the panel** (set while
   building the order). Modal = total + payment only.
2. Keyboard: **Enter** in the panel opens the modal; **B/C/P/R** inside the modal
   pick a method; **Enter** in the modal = Confirm Payment; **Esc** closes the
   modal.
3. Validation: modal cannot open on an empty cart; a payment method must be
   selected before Confirm Payment submits (reuse existing "select a payment
   method" guard).
4. Scope is `menu.php` only. The standalone `cart.php` has its own copy of this
   block — leave it untouched for now; confirm whether `cart.php` is still
   reachable/used before considering a follow-up.

### Out of scope
- `cart.php` standalone page changes.
- Any change to `confirm_order.php`, `payment.php`, `order_payments` schema, or
  payment processing logic.
- New payment methods or new split combos beyond what exists today.

## Affected files
- `menu.php` — wrap payment block in modal markup; change Confirm Order to open
  the modal; repoint the submit trigger to the modal's Confirm Payment button;
  adjust keyboard handlers.

## Testing
- Cash only → modal shows, change calc works, order saved as Preparing.
- Bakong only → modal shows, redirect to QR page.
- Pay Later only → solo enforced, redirect to paylater page.
- Riel only → solo enforced, KHR calc + reference stored.
- Bakong+Cash split → auto-remainder, both legs recorded, status PendingPayment.
- Empty cart → Confirm Order does not open modal.
- Esc closes modal without submitting; reopening preserves selection sanity.
- Keyboard B/C/P/R/Enter/Esc behave per decision 2.
