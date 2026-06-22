# Payment-After-Confirm Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the cart payment block in `menu.php` out of the inline panel into a modal that opens when the cashier clicks Confirm Order, so the total is shown first and payment is chosen second.

**Architecture:** Pure front-end relocation inside `menu.php`. The existing payment markup (`#cpPayMethods`, `#cpSplitInputs`, `#cpRielCalc`, `#cpChangeCalc`) moves into a new modal (`#cpPayModal`). The Confirm Order button stops submitting the form and instead opens the modal; a new Confirm Payment button inside the modal triggers the *existing* `#cpCheckoutForm` submit handler unchanged. No PHP, DB, or `confirm_order.php` changes.

**Tech Stack:** PHP (server render), vanilla JS, plain CSS. No build step. Verification via Playwright MCP browser against the live XAMPP server (`http://localhost/Cafe/`).

## Global Constraints

- Touch `menu.php` only. Do NOT modify `confirm_order.php`, `payment.php`, `cart.php`, or any DB schema.
- Reuse existing IDs and JS functions verbatim: `cpTogglePayment`, `cpGetSelected`, `cpUpdateConfirmBtn`, `cpUpdateSplitInputs`, `cpOnSplitChange`, `cpCalcChange`, `cpCalcRielChange`, `cpClickPayMethod`, and the `#cpCheckoutForm` submit handler. Do not rewrite payment logic.
- Preserve split behavior (Bakong+Cash auto-remainder), paylater/riel solo-only enforcement, change calc, riel calc.
- Match existing dark-theme support: any new element needs a `[data-theme="dark"]` rule mirroring nearby patterns.
- Mirror the existing modal pattern already in the file (`.modal` / `.modal-card` at ~line 976, and `openLoyaltyModal`/`closeLoyaltyModal`).
- The cashier is on a touch screen; buttons stay finger-sized (existing `.cp-pay-method` sizing is fine).

## File Structure

- `menu.php` — single file. Three regions change:
  - **CSS** (~lines 360–470): add `#cpPayModal` overlay/card styles + dark theme.
  - **Markup**: remove the inline payment block (lines ~857–900: the payment-methods `cp-section`, `#cpRielCalc`, `#cpChangeCalc`); add a new `#cpPayModal` near the other modals (~line 976+) containing that block plus a total breakdown and a Confirm Payment button.
  - **JS** (~lines 1473–1790): add `cpOpenPayModal()` / `cpClosePayModal()`; change Confirm Order button to call `cpOpenPayModal()`; point Confirm Payment button at the existing submit; adjust keyboard handlers so B/C/P/R and Enter act inside the modal.

---

### Task 1: Add the payment modal shell (markup + CSS), hidden by default

Add an empty-but-styled modal so we have a container to move the payment block into. Nothing wired yet.

**Files:**
- Modify: `menu.php` CSS region (after `.cp-change-calc` dark rules, ~line 470)
- Modify: `menu.php` markup, immediately after the product `#modal` block closes (~line 976 area; place after that modal's closing `</div>`)

**Interfaces:**
- Produces: DOM element `#cpPayModal` (overlay) containing `#cpPayModalCard`; helper classes `.cp-paymodal`, `.cp-paymodal.active`. Total breakdown elements `#cpPmSubtotal`, `#cpPmTax`, `#cpPmTotal`. Empty mount point `#cpPayModalBody` where Task 2 moves the payment block. Button `#cpConfirmPayBtn`. Close control `#cpPayModalClose`.

- [ ] **Step 1: Add modal CSS**

Insert after the existing `[data-theme="dark"] .cp-change-calc { ... }` rule (~line 470):

```css
    /* ── Payment modal ── */
    .cp-paymodal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(6px); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
    .cp-paymodal.active { display: flex; }
    .cp-paymodal-card { background: var(--bg-card,#fff); border: 1px solid var(--border,#e0d4c4); border-radius: 16px; width: 100%; max-width: 420px; padding: 22px 22px 18px; box-shadow: 0 12px 48px rgba(90,60,20,.18); position: relative; animation: cpPmIn .22s ease both; }
    @keyframes cpPmIn { from { opacity: 0; transform: translateY(16px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .cp-paymodal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .cp-paymodal-head h3 { font-size: 16px; font-weight: 700; color: var(--text,#1a1410); margin: 0; }
    .cp-paymodal-close { background: none; border: none; font-size: 20px; color: var(--text-muted,#9a8070); cursor: pointer; line-height: 1; }
    .cp-pm-breakdown { background: var(--bg,#f4efe9); border: 1px solid var(--border,#e0d4c4); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; }
    .cp-pm-row { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-sec,#5a4a3a); padding: 2px 0; }
    .cp-pm-row.total { border-top: 1px solid var(--border,#e0d4c4); margin-top: 6px; padding-top: 8px; font-size: 20px; font-weight: 700; color: var(--text,#1a1410); }
    .cp-pm-row.total span:last-child { color: #d1904b; }
    .cp-pm-confirm { width: 100%; margin-top: 14px; padding: 13px; border: none; border-radius: 11px; background: #d1904b; color: #fff; font-size: 15px; font-weight: 700; font-family: 'Poppins',sans-serif; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .cp-pm-confirm:hover { filter: brightness(1.07); }
    [data-theme="dark"] .cp-paymodal-card { background: #161616; border-color: #252525; }
    [data-theme="dark"] .cp-pm-breakdown { background: #1a1a1a; border-color: #252525; }
    [data-theme="dark"] .cp-pm-row.total { border-color: #252525; }
```

- [ ] **Step 2: Add modal markup**

Insert immediately after the product detail modal's closing `</div>` (the `#modal` block, ~line 976+). Use PHP `$cp_total` and the same subtotal/tax math the panel footer uses (subtotal = total / (1+rate), tax = total − subtotal):

```html
<!-- ── PAYMENT MODAL ── -->
<div id="cpPayModal" class="cp-paymodal">
  <div class="cp-paymodal-card" id="cpPayModalCard">
    <div class="cp-paymodal-head">
      <h3><i class="fa-solid fa-credit-card" style="color:#d1904b;"></i> Payment</h3>
      <button type="button" class="cp-paymodal-close" id="cpPayModalClose" onclick="cpClosePayModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="cp-pm-breakdown">
      <div class="cp-pm-row"><span>Subtotal</span><span id="cpPmSubtotal">$0.00</span></div>
      <div class="cp-pm-row"><span>Tax (<?= (int)TAX_RATE ?>%)</span><span id="cpPmTax">$0.00</span></div>
      <div class="cp-pm-row total"><span>Total</span><span id="cpPmTotal">$0.00</span></div>
    </div>
    <div id="cpPayModalBody"><!-- Task 2 moves the payment block here --></div>
    <button type="button" class="cp-pm-confirm" id="cpConfirmPayBtn">
      <i class="fa-solid fa-check"></i> <span>Confirm Payment</span>
    </button>
  </div>
</div>
```

- [ ] **Step 3: Verify it renders hidden**

Run via Playwright MCP:
- `browser_navigate` to `http://localhost/Cafe/menu.php` (log in first if redirected — use cashier test account).
- `browser_evaluate`: `() => { const m = document.getElementById('cpPayModal'); return { exists: !!m, visible: m ? getComputedStyle(m).display : 'n/a' }; }`

Expected: `{ exists: true, visible: "none" }`.

- [ ] **Step 4: Commit**

```bash
git add menu.php
git commit -m "feat(menu): add hidden payment modal shell"
```

---

### Task 2: Move the payment block into the modal body

Relocate the existing payment markup so the same IDs now live inside `#cpPayModalBody`. Inline copies are removed from the panel.

**Files:**
- Modify: `menu.php` markup — cut lines ~857–900 (the `<?php if (!$add_to_order_mode): ?>` payment `cp-section` containing `#cpPayMethods` + `#cpSplitInputs`, the `#cpRielCalc` block, and the `#cpChangeCalc` block) and paste into `#cpPayModalBody`.

**Interfaces:**
- Consumes: `#cpPayModalBody` from Task 1.
- Produces: `#cpPayMethods`, `#cpSplitInputs`, `#cpSplitRows`, `#cpRielCalc`, `#cpChangeCalc` (and children) now inside the modal. IDs unchanged so all existing JS keeps working.

- [ ] **Step 1: Cut the inline payment markup**

In the panel, remove the block from the `<!-- Payment methods -->` comment through the end of the `<!-- Change calculator -->` `cp-change-calc` div (lines ~858–900). Keep the surrounding `<?php if (!$add_to_order_mode): ?> ... <?php else: ?> ... <?php endif; ?>` structure intact: the **Order type** section and the add-to-order `else` note must remain in the panel.

Concretely, the panel `if (!$add_to_order_mode)` branch keeps ONLY the Order type `cp-section` (lines ~902–913). The payment-methods section, `#cpRielCalc`, and `#cpChangeCalc` are removed from here.

- [ ] **Step 2: Paste into the modal body**

Place the cut markup inside `#cpPayModalBody` (from Task 1), wrapped so it only renders in normal (non add-to-order) mode:

```html
<div id="cpPayModalBody">
  <?php if (!$add_to_order_mode): ?>
  <div class="cp-pay-methods" id="cpPayMethods">
    <div class="cp-pay-method" onclick="cpTogglePayment(this)">
      <input type="checkbox" value="bakong"> <span>&#x1F4F1; Bakong</span>
    </div>
    <div class="cp-pay-method" onclick="cpTogglePayment(this)">
      <input type="checkbox" value="cash"> <span>&#x1F4B5; Cash</span>
    </div>
    <div class="cp-pay-method" onclick="cpTogglePayment(this)">
      <input type="checkbox" value="paylater"> <span>&#x23F0; Later</span>
    </div>
    <div class="cp-pay-method" onclick="cpTogglePayment(this)">
      <input type="checkbox" value="riel"> <span>&#x1F1F0;&#x1F1ED; Riel &#x17DB;</span>
    </div>
  </div>
  <div class="cp-split-inputs" id="cpSplitInputs"><div id="cpSplitRows"></div></div>

  <div class="cp-change-calc" id="cpRielCalc">
    <label><i class="fa-solid fa-coins" style="color:#e74c3c;margin-right:4px;"></i> Amount in Riel (KHR)</label>
    <input type="number" id="cpRielReceived" step="1" min="0" placeholder="0" oninput="cpCalcRielChange()" onfocus="this.select()">
    <div class="cp-change-row">
      <span class="change-label">USD Equivalent</span>
      <span class="change-amount" id="cpRielUsdEquiv">$0.00</span>
    </div>
    <div class="cp-change-row" id="cpRielChangeRow" style="display:none;">
      <span class="change-label">Change (KHR)</span>
      <span class="change-amount" id="cpRielChangeKhr">&#x17DB;0</span>
    </div>
  </div>

  <div class="cp-change-calc" id="cpChangeCalc">
    <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:4px;"></i> Amount Received</label>
    <input type="number" id="cpCashReceived" step="0.01" min="0" placeholder="0.00" oninput="cpCalcChange()" onfocus="this.select()">
    <div class="cp-change-row">
      <span class="change-label">Change to give back</span>
      <span class="change-amount" id="cpChangeAmount">$0.00</span>
    </div>
  </div>
  <?php endif; ?>
</div>
```

- [ ] **Step 3: Verify IDs exist once, inside the modal**

Run via Playwright MCP `browser_evaluate`:
```js
() => ['cpPayMethods','cpSplitInputs','cpRielCalc','cpChangeCalc'].map(id => {
  const els = document.querySelectorAll('#'+id);
  const inModal = els[0] ? !!els[0].closest('#cpPayModal') : false;
  return { id, count: els.length, inModal };
})
```
Expected: each `count: 1` and `inModal: true`.

- [ ] **Step 4: Commit**

```bash
git add menu.php
git commit -m "refactor(menu): relocate payment block into the payment modal"
```

---

### Task 3: Wire open/close + Confirm Payment, populate the breakdown

Make Confirm Order open the modal and fill the total breakdown; make Confirm Payment submit; make close/Esc/backdrop dismiss.

**Files:**
- Modify: `menu.php` JS region (~line 1473+ and the submit handler ~1685+)

**Interfaces:**
- Consumes: `cpGetCartTotal()`, `cpGetSelected()`, `#cpCheckoutForm`, `#cpConfirmBtn`, `#cpPayModal`, `#cpConfirmPayBtn`, breakdown spans from Task 1.
- Produces: `cpOpenPayModal()`, `cpClosePayModal()` global functions; `window.TAX_RATE` JS constant for the breakdown.

- [ ] **Step 1: Expose TAX_RATE to JS**

Find where other JS constants are emitted (e.g. `CP_KHR_RATE`). Add alongside:

```php
<script>var CP_TAX_RATE = <?= (float)TAX_RATE ?>;</script>
```
(If `CP_KHR_RATE` is defined inline in the main script block, add `var CP_TAX_RATE = <?= (float)TAX_RATE ?>;` on the next line there instead — keep one definition.)

- [ ] **Step 2: Add open/close functions**

Add near the other `cp*` functions (after `cpUpdateConfirmBtn`, ~line 1561):

```js
function cpOpenPayModal() {
  var total = cpGetCartTotal();
  if (total <= 0) return; // empty cart guard
  var sub = total / (1 + CP_TAX_RATE / 100);
  var tax = total - sub;
  document.getElementById('cpPmSubtotal').textContent = '$' + sub.toFixed(2);
  document.getElementById('cpPmTax').textContent = '$' + tax.toFixed(2);
  document.getElementById('cpPmTotal').textContent = '$' + total.toFixed(2);
  document.getElementById('cpPayModal').classList.add('active');
}
function cpClosePayModal() {
  document.getElementById('cpPayModal').classList.remove('active');
}
```

- [ ] **Step 3: Make Confirm Order open the modal instead of submitting**

The form submit handler (~1689) currently does the real work. Change the **Confirm Order button** to open the modal, and move the actual submission trigger to Confirm Payment. In the `DOMContentLoaded` block, after the form is grabbed, replace the direct submit-on-form-submit wiring so that:

- The `<button id="cpConfirmBtn" type="submit">` becomes `type="button"` with an `onclick` opening the modal. Edit the markup button in the footer:

```html
<button type="button" class="cp-confirm-btn<?= $add_to_order_mode ? ' paylater' : '' ?>" id="cpConfirmBtn" onclick="cpOnConfirmOrderClick()">
```

- Add the handler near the open/close funcs:

```js
function cpOnConfirmOrderClick() {
  if (typeof ADD_TO_ORDER_MODE !== 'undefined' && ADD_TO_ORDER_MODE) {
    // add-to-order has no payment step — submit directly
    document.getElementById('cpCheckoutForm').requestSubmit();
    return;
  }
  cpOpenPayModal();
}
```

- [ ] **Step 4: Point Confirm Payment at the existing submit**

The existing `form.addEventListener('submit', ...)` handler (~1689) already builds hidden inputs and calls `form.submit()`. Keep it. Wire the modal button to request a submit (which fires that handler):

```js
document.getElementById('cpConfirmPayBtn').addEventListener('click', function() {
  document.getElementById('cpCheckoutForm').requestSubmit();
});
```

The existing handler's guard `if (selected.length === 0 ...) { alert('Please select a payment method.'); return; }` stays — it now fires from inside the modal.

- [ ] **Step 5: Backdrop + Esc close**

Add:

```js
document.getElementById('cpPayModal').addEventListener('click', function(e) {
  if (e.target === this) cpClosePayModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('cpPayModal').classList.contains('active')) {
    cpClosePayModal();
  }
});
```

- [ ] **Step 6: Verify each payment path in the browser**

Via Playwright MCP against `http://localhost/Cafe/menu.php` (cashier login). For each case: add one drink to cart, then:

1. **Open:** click Confirm Order → assert `#cpPayModal` has class `active` and `#cpPmTotal` matches `#cpTotal`.
2. **Cash:** click Cash → `#cpChangeCalc` visible; type received; click Confirm Payment → lands on `payment_cash.php`.
3. **Bakong:** reset, Bakong → Confirm Payment → lands on `payment.php?order_id=...`.
4. **Pay Later:** Later → Confirm Payment → lands on `payment_paylater.php`.
5. **Riel:** Riel → riel calc visible → Confirm Payment → lands on `payment_cash.php` with riel recorded.
6. **Split:** Bakong+Cash → split rows show auto-remainder summing to total → Confirm Payment → `payment.php` (Bakong leg pending).
7. **Empty cart:** clear cart → Confirm Order does nothing (no modal).
8. **Esc / backdrop:** open modal → press Esc → modal closes, no navigation.

Expected: all behave as listed. Capture a screenshot of the open modal with `browser_take_screenshot`.

- [ ] **Step 7: Commit**

```bash
git add menu.php
git commit -m "feat(menu): open payment modal on confirm, submit from modal"
```

---

### Task 4: Keyboard parity inside the modal

Make B/C/P/R pick methods and Enter confirm, scoped sensibly to whether the modal is open.

**Files:**
- Modify: `menu.php` keyboard handler (~line 1763+)

**Interfaces:**
- Consumes: `cpClickPayMethod(method)`, `cpOpenPayModal`, `cpClosePayModal`, `#cpPayModal`, `#cpConfirmPayBtn`, `#cpConfirmBtn`.

- [ ] **Step 1: Update the keydown handler**

Locate the existing `document.addEventListener('keydown', ...)` that maps b/c/p/r and enter (~1763). Modify so:
- B/C/P/R: if the modal is open, just pick the method; if closed, open the modal first then pick.
- Enter: if modal open → Confirm Payment; if closed → open modal (instead of submitting directly).

Replace the relevant branches:

```js
document.addEventListener('keydown', function(e) {
  var tag = document.activeElement.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  var modalOpen = document.getElementById('cpPayModal').classList.contains('active');
  var key = e.key.toLowerCase();
  if (['b','c','p','r'].includes(key)) {
    e.preventDefault();
    if (!modalOpen) cpOpenPayModal();
    var map = { b:'bakong', c:'cash', p:'paylater', r:'riel' };
    cpClickPayMethod(map[key]);
  } else if (key === 'enter') {
    e.preventDefault();
    if (modalOpen) document.getElementById('cpConfirmPayBtn').click();
    else cpOpenPayModal();
  }
});
```

Remove the now-superseded older b/c/p/r/enter branches so there is only one handler.

- [ ] **Step 2: Verify keyboard flow**

Via Playwright MCP (cart has 1 drink, focus on body not an input):
- Press `c` → modal opens AND Cash selected (`#cpChangeCalc` visible).
- Press `Enter` → submits (navigates to `payment_cash.php`).
- New order, press `Enter` first → modal opens (no nav). Press `b` → Bakong selected. `Enter` → `payment.php`.

Expected: as listed.

- [ ] **Step 3: Commit**

```bash
git add menu.php
git commit -m "feat(menu): keyboard shortcuts drive the payment modal"
```

---

### Task 5: Final review + spec verification

- [ ] **Step 1: Confirm `cart.php` scope decision**

Check whether `cart.php` is still linked from anywhere:

Run: `grep -rn "cart.php" --include=*.php .` (ignore `Cart_paylater.php`).
- If nothing user-facing links to it, note it as dead/legacy in the commit message and leave untouched (per spec).
- If it IS linked, do NOT change it now — record a follow-up TODO; this plan is menu.php-only.

- [ ] **Step 2: Lint**

Run: `php -l menu.php`
Expected: `No syntax errors detected in menu.php`.

- [ ] **Step 3: Regression pass**

Re-run the Task 3 Step 6 matrix once more end-to-end against a fresh cart to confirm nothing regressed after the keyboard changes. Confirm dark mode: toggle theme, open modal, verify breakdown + buttons are legible.

- [ ] **Step 4: Final commit (if any cleanup)**

```bash
git add menu.php
git commit -m "chore(menu): payment modal final cleanup"
```
