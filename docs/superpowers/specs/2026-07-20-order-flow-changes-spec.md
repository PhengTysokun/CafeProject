# Spec — Order-Flow Changes & Open Question (2026-07-20)

**Status:** implemented locally on `feat/product-addons` (not pushed), except §7 which is an open design question for review.
**Audience:** reviewers. Please push back on §7 especially.

---

## 1. Summary

A round of correctness + UX work on the order lifecycle: the Bakong QR page, the Find Orders queue, the dashboard, a loyalty double-count bug, and one hardcoded threshold made configurable. Plus one unresolved architectural question (§7: when should an order row be created?).

Nothing changes the database schema. All edits are PHP/JS on existing files.

---

## 2. Background — the order lifecycle today

An order row is created at **checkout confirm** (`confirm_order.php`), *before* any payment happens. Status is set by chosen method:

| Method | On confirm | Kitchen sees it? |
|---|---|---|
| Cash | `Preparing`, `is_open=0` | Yes (paid immediately) |
| Bakong | `PendingPayment`, `is_open=0` | **No** — only after payment confirms → `Preparing` |
| Pay Later | `Preparing`, `is_open=1` | Yes (prepare now, pay later) |

`order_id`, daily order number, token, and `order_items` are all assigned at confirm. The session cart is cleared at that point.

Settlement (Cash/Bakong/Pay-Later) drives `status → Paid`/closed and awards loyalty points. Unpaid/open orders live in **Find Orders**; cashiers are auto-locked to the Pay Later tab.

---

## 3. Bakong QR page (`payment.php`) — reduce to one job

**Requirement:** the QR page shows the QR, polls for payment, and lets the cashier leave. Nothing else.

- Show: order summary, KHQR image, live "Waiting for Bakong payment…" status (2s polling).
- Provide exactly **one** exit: a persistent **✕** in the card corner, present from load.
- ✕ opens a confirm: *"The order will wait in Find Orders → Pending Payment. You can collect it there later by Cash or Bakong — nothing is lost."* → returns to menu.
- **Removed:** the 15-min countdown badge; the Try Again / Pay with Cash / Print / Back button stack; the 20-second reveal timer.
- Rationale: cash re-collection, QR regeneration, and receipt printing already exist on **Find Orders → Pending Payment**. Duplicating them on the QR page was over-built and time-gated the exit (cashier was trapped for the first 20s).
- The Bakong-verification-error path must not reference the removed elements; it shows an inline hint pointing at ✕ / Find Orders.

**Non-goal:** converting to cash from this page. That path (`?action=switch_cash`) is left in the server for now but unlinked from the UI.

---

## 4. Find Orders (`find_order.php`, `_order_card.php`) — declutter & clarify

- **Tabs reduced 5 → 4:** `All Active · Pending Payment · Open Tabs · Pay Later`. The **Preparing** tab was removed — it duplicated the Barista Station and was the source of overlapping counts (a preparing paylater order was counted under both Preparing and Pay Later).
- **"Paid + Open" → "Open Tabs"**, recolored from green to amber (green implied "done", contradicting an open tab).
- **Total relabeled** "Outstanding $X" with a tooltip (it is uncollected balance, not revenue). Kept in sync through the AJAX refresh.
- **Overdue paylater timestamp** rendered bold-red.
- **Payment-guidance callout** dismissal persists in `localStorage` (never returns for that user).

---

## 5. Pay Later follow-up threshold — configurable

The "unpaid too long → flash red" window was hardcoded (`$diff > 1800`) in `_order_card.php`, while every other age threshold reads a Setting.

- New constant `PAYLATER_FOLLOWUP_MINUTES` (`config.php`), default **45**, clamped 5–240, same DB-settings pattern as `OVERDUE_MINUTES`.
- Editable in **Settings → Pay Later** (new section).
- Semantics: a *money-outstanding* clock measured from order creation — distinct from the barista prep clock. (We explicitly reject "measure from Completed" — this is about how long money is uncollected, not drink readiness.)

---

## 6. Loyalty double-points on cash settle — **bug fix (correctness)**

Pay Later awards loyalty points at **settlement**, and `orders.points_earned` is the single source of truth. Three award sites must all honor it:

1. Add-items to an open tab (`confirm_order.php`) — adjusts by delta, sets `points_earned`.
2. Cash settle (`admin_pay_cash.php`).
3. Bakong settle (`check_payment.php`) — guarded on `points_earned === 0`.

**Defect:** cash settle lacked that guard. If items were added to a loyalty-linked tab (already credited + `points_earned` set), settling by cash credited a **second** time.

**Fix:** add `points_earned` to the cash-settle SELECT and guard the award block with `=== 0`, mirroring the Bakong path. Cash settle is now idempotent.

---

## 7. OPEN QUESTION — when should the order row be created? (review this)

The recurring theme behind several issues (phantom unpaid orders, the stale test order, "can the cashier put it back in the cart?") is **eager order creation**: the row exists before payment.

### Option A — keep eager creation (current)
Order committed at confirm; unpaid Bakong orders park in Pending Payment; abandoned ones are cancelled/cleaned up.

- **Pros:** simple; order number/token assigned early; supports "prepare now, pay later" natively; Bakong orders correctly withhold from the kitchen until paid.
- **Cons:** abandoned Bakong checkouts leave *phantom* `PendingPayment` rows that need cancelling; you cannot "return the order to the cart" without deleting/renumbering.

### Option B — deferred creation ("stay in cart")
No order row until payment confirms; the cart persists; on ✕ the items stay in the cart to re-ring (e.g. as cash).

- **Pros:** no phantom orders; "retry as cash from the cart" becomes natural; cleaner order-number sequence (only real, paid-or-committed orders).
- **Cons:** large refactor of `confirm_order.php` + the whole Bakong flow; breaks the Pay Later model (which *needs* an order to prepare before payment); order number/token can't be shown until paid; concurrency around number assignment moves to settlement.

### Recommendation
**Keep Option A.** The stay-in-cart benefit is marginal because **Find Orders → Pending Payment → Cash already provides the same "retry as cash"** in one click, without fighting the data model. Phantom orders are a cleanup concern, not a correctness one, and Bakong PendingPayment already keeps unpaid orders out of the kitchen. Option B's cost (touching every order-creation path, breaking Pay Later's prepare-first premise) is not justified.

**Question for reviewers:** do you agree A is sufficient, or is the phantom-order cleanup burden bad enough to justify B (or a hybrid — e.g. a lightweight "draft" order state that's cheap to discard)?

### Option C — keep A, add scheduled cleanup (future, not built)
Raised in review: instead of changing when orders are created, periodically sweep abandoned unpaid Bakong checkouts.

- *Rule sketch:* `status = 'PendingPayment' AND is_open = 0 AND order_date < NOW() - INTERVAL 24 HOUR` → set `Cancelled`, reason "Abandoned Bakong checkout".
- *Delivery:* a nightly cron (cPanel supports it) **or** a lazy sweep on an admin page load, matching the app's existing lazy-migration pattern — avoids depending on cron infra.
- **Not built now, and deliberately.** Auto-cancelling orders is semi-destructive: it needs a conservative window (≥24h) and ideally manager-visible so a legitimately-unpaid tab is never silently killed. And the phantom problem is currently a cleanup convenience, not a correctness issue. Captured here so it isn't rediscovered; revisit if abandoned rows actually accumulate in production.

---

## 8. Verification done

- PHP `-l` lint clean on every touched file.
- **Browser E2E (admin, order #3):** QR page renders only QR + status + ✕ (no timer, no buttons); ✕ opens the reworded confirm; Cancel leaves the order untouched (still PendingPayment).
- Loyalty fix verified by tracing all three award sites (code review), not yet driven end-to-end.
- Stale test order (id 1864) cancelled in DB.

## 9. Deployment

All changes local on `feat/product-addons`, uncommitted, not pushed/deployed.

**Files:** `payment.php`, `find_order.php`, `_order_card.php`, `admin_pay_cash.php`, `config.php`, `settings.php`, `dashboard.php`.
