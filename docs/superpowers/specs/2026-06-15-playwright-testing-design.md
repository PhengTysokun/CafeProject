# Playwright Testing Design — Bird's Nest Coffee POS

**Date:** 2026-06-15
**Approach:** Hybrid — MCP live verification + persistent automated test suite

---

## Goal

Add end-to-end browser tests to the POS system covering all critical cashier flows: login, order-taking, payment, and receipt generation. Tests run against the real XAMPP app at `http://localhost/Cafe` using a real MySQL database.

---

## Folder Structure

```
c:\xampp\htdocs\Cafe\
└── tests/
    ├── playwright.config.js        ← base URL, browser settings, screenshot on failure
    ├── helpers/
    │   └── auth.js                 ← shared login/logout helper used by all test files
    ├── 01-login.spec.js            ← login success, wrong password, logout
    ├── 02-order.spec.js            ← add items to cart, confirm order
    ├── 03-payment.spec.js          ← cash payment, pay-later flow
    └── 04-receipt.spec.js          ← receipt print page, PDF receipt
```

Screenshots on failure are saved to `tests/screenshots/`.

---

## Test Data Strategy

### Test Accounts (created once via seed SQL)

| Username | Password | Role | Purpose |
|---|---|---|---|
| `test_cashier` | `Test@1234` | staff | All cashier flow tests |

Test accounts are inserted into the `users` table once and never deleted.

### Order Data

- Tests create real orders during the test run
- Each test cleans up its own orders from the `orders` and `order_items` tables after completion
- Tests read existing products from the menu — they do not create or modify products
- No separate test database is needed

---

## Test Flow Details

### `01-login.spec.js`
1. Navigate to `login.php`
2. Submit valid `test_cashier` credentials → assert redirect to `dashboard.php`
3. Submit wrong password → assert error message visible on page
4. Log out → assert redirect back to `login.php`

### `02-order.spec.js`
1. Log in as `test_cashier`
2. Navigate to cart/POS screen
3. Click first available product → assert item appears in cart with correct price
4. Add a second item → assert cart total updates correctly
5. Click "Confirm Order" → assert order confirmation visible
6. Cleanup: delete test order from DB

### `03-payment.spec.js`
1. Log in, create a test order
2. **Cash flow**: select cash payment, enter amount → assert change is calculated → confirm paid → assert success
3. **Pay-later flow**: mark order as pay-later → verify shows as unpaid → navigate to `find_order.php` → locate order → mark as paid → assert status updates
4. Cleanup: delete test orders from DB

### `04-receipt.spec.js`
1. Complete a paid cash order
2. Open `receipt_print.php?order_id=X` → assert order items, total, and date visible
3. Open `receipt_pdf.php?order_id=X` → assert page loads without HTTP error
4. Cleanup: delete test order from DB

---

## Configuration (`playwright.config.js`)

- **Base URL:** `http://localhost/Cafe`
- **Browser:** Chromium (default)
- **Timeout:** 10 seconds per action
- **On failure:** save screenshot to `tests/screenshots/`
- **Retries:** 0 (tests should be deterministic)

---

## What Tests Do NOT Touch

- Products, categories, or menu items
- Employee records
- Loyalty cards or points
- Settings or promotions
- Ingredient stock levels

---

## Running Tests

```bash
# Install once
cd c:\xampp\htdocs\Cafe\tests
npm install
npx playwright install chromium

# Run all tests
npx playwright test

# Run a specific file
npx playwright test 01-login.spec.js

# Run with visible browser (headed mode — useful for debugging)
npx playwright test --headed
```

---

## Seed SQL (run once in phpMyAdmin)

```sql
-- Create test cashier account (password: Test@1234)
INSERT IGNORE INTO users (username, password, role, full_name)
VALUES ('test_cashier', SHA2('Test@1234', 256), 'staff', 'Test Cashier');
```

> Note: verify the password hashing matches what `login.php` uses before running.

---

## Success Criteria

- All 4 test files pass with `npx playwright test`
- A failed test produces a screenshot in `tests/screenshots/`
- Tests can be run repeatedly without leaving leftover data in the DB
- MCP live session used to verify flows before/during test writing
