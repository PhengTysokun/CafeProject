# Playwright E2E Testing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Set up a Playwright E2E test suite covering login, order creation, cash/pay-later payment, and receipt verification for the Bird's Nest Coffee POS.

**Architecture:** Node.js Playwright project lives in `tests/` alongside the PHP app. Tests run against the real XAMPP server at `http://localhost/Cafe`. A dedicated `test_cashier` staff account is seeded once via a PHP script. Each test creates real orders and cleans them up via `mysql2` after each run. No mocking — full stack.

**Tech Stack:** Node.js 18+, @playwright/test 1.45+, mysql2 3.x, PHP/MySQL on XAMPP

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `tests/package.json` | Create | Node.js project config and dependencies |
| `tests/playwright.config.js` | Create | Base URL, browser, screenshot-on-fail |
| `tests/helpers/auth.js` | Create | Shared login/logout helpers |
| `tests/helpers/db.js` | Create | MySQL order cleanup after each test |
| `tests/seed_test_user.php` | Create | One-time script to create test_cashier account |
| `tests/01-login.spec.js` | Create | Login, wrong password, logout |
| `tests/02-order.spec.js` | Create | Add item to cart, confirm order |
| `tests/03-payment.spec.js` | Create | Cash payment, pay-later flow |
| `tests/04-receipt.spec.js` | Create | Receipt print page, PDF receipt |

---

### Task 1: Verify Prerequisites

**Files:** None

- [ ] **Step 1: Check Node.js is installed**

```bash
node --version
npm --version
```

Expected: Node.js `v18.x.x` or higher. If not installed, download from nodejs.org.

- [ ] **Step 2: Verify XAMPP is running**

Open `http://localhost/Cafe/login.php` in a browser. You should see the login screen.

- [ ] **Step 3: Verify MySQL credentials**

In phpMyAdmin (`http://localhost/phpmyadmin`), confirm `cafe_pos` user can access `db_coffee`.

---

### Task 2: Initialize Node.js Test Project

**Files:**
- Create: `tests/package.json`
- Create: `tests/playwright.config.js`

- [ ] **Step 1: Create `tests/package.json`**

```json
{
  "name": "cafe-pos-tests",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "test": "playwright test",
    "test:headed": "playwright test --headed",
    "test:debug": "playwright test --debug"
  },
  "devDependencies": {
    "@playwright/test": "^1.45.0"
  },
  "dependencies": {
    "dotenv": "^16.4.0",
    "mysql2": "^3.9.0"
  }
}
```

- [ ] **Step 2: Create `tests/playwright.config.js`**

```javascript
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 30000,
  retries: 0,
  use: {
    baseURL: 'http://localhost/Cafe',
    headless: true,
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 10000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  outputDir: 'screenshots',
});
```

- [ ] **Step 3: Install dependencies**

```bash
cd c:\xampp\htdocs\Cafe\tests
npm install
```

Expected: `node_modules/` created, no errors.

- [ ] **Step 4: Install Chromium**

```bash
npx playwright install chromium
```

Expected: Chromium downloaded and installed.

- [ ] **Step 5: Commit**

```bash
git add tests/package.json tests/playwright.config.js tests/package-lock.json
git commit -m "chore: init Playwright test project"
```

---

### Task 3: Create Test User Seed Script

**Files:**
- Create: `tests/seed_test_user.php`

- [ ] **Step 1: Create `tests/seed_test_user.php`**

```php
<?php
require_once __DIR__ . '/../config.php';
$hash = password_hash('Test@1234', PASSWORD_DEFAULT);
$conn->query("INSERT IGNORE INTO users (username, password, role, full_name)
              VALUES ('test_cashier', '$hash', 'staff', 'Test Cashier')");
echo "Done. Rows affected: " . $conn->affected_rows . "\n";
echo "test_cashier / Test@1234 is ready.\n";
?>
```

- [ ] **Step 2: Run the seed script**

Open in browser: `http://localhost/Cafe/tests/seed_test_user.php`

Expected:
```
Done. Rows affected: 1
test_cashier / Test@1234 is ready.
```

`Rows affected: 0` means the user already exists — that is fine.

- [ ] **Step 3: Verify login manually**

Go to `http://localhost/Cafe/login.php`, log in as `test_cashier` / `Test@1234`. You should reach the dashboard.

- [ ] **Step 4: Commit**

```bash
git add tests/seed_test_user.php
git commit -m "chore: add test user seed script"
```

---

### Task 4: Create Shared Helpers

**Files:**
- Create: `tests/helpers/auth.js`
- Create: `tests/helpers/db.js`

- [ ] **Step 1: Create `tests/helpers/auth.js`**

```javascript
async function login(page, username = 'test_cashier', password = 'Test@1234') {
  await page.goto('/login.php');
  await page.fill('#u', username);
  await page.fill('#p', password);
  await page.click('button[type="submit"]');
  // loading.php uses a JS setTimeout redirect to dashboard.php
  await page.waitForURL('**/dashboard.php', { timeout: 15000 });
}

async function logout(page) {
  await page.goto('/logout.php');
  await page.waitForURL('**/login.php', { timeout: 5000 });
}

module.exports = { login, logout };
```

- [ ] **Step 2: Create `tests/.env`** (never committed — holds real credentials)

```
DB_HOST=localhost
DB_USER=cafe_pos
DB_PASS=<your db password from config.php>
DB_NAME=db_coffee
```

- [ ] **Step 3: Create `tests/helpers/db.js`**

```javascript
const mysql = require('mysql2/promise');
require('dotenv').config({ path: require('path').join(__dirname, '../.env') });

async function deleteOrder(orderId) {
  if (!orderId) return;
  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME || 'db_coffee',
  });
  await conn.execute('DELETE FROM order_payments WHERE order_id = ?', [orderId]);
  await conn.execute('DELETE FROM order_items WHERE order_id = ?', [orderId]);
  await conn.execute('DELETE FROM orders WHERE order_id = ?', [orderId]);
  await conn.end();
}

module.exports = { deleteOrder };
```

- [ ] **Step 3: Commit**

```bash
git add tests/helpers/auth.js tests/helpers/db.js
git commit -m "chore: add Playwright auth and DB cleanup helpers"
```

---

### Task 5: Write and Run Login Tests

**Files:**
- Create: `tests/01-login.spec.js`

- [ ] **Step 1: Create `tests/01-login.spec.js`**

```javascript
const { test, expect } = require('@playwright/test');
const { login, logout } = require('./helpers/auth');

test('valid credentials redirect to dashboard', async ({ page }) => {
  await page.goto('/login.php');
  await page.fill('#u', 'test_cashier');
  await page.fill('#p', 'Test@1234');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 15000 });
  expect(page.url()).toContain('dashboard.php');
});

test('wrong password redirects back with error message', async ({ page }) => {
  await page.goto('/login.php');
  await page.fill('#u', 'test_cashier');
  await page.fill('#p', 'definitely_wrong_password');
  await page.click('button[type="submit"]');
  await page.waitForURL(/login\.php.*error/, { timeout: 5000 });
  await expect(page.locator('.error-box')).toBeVisible();
});

test('logout redirects to login page', async ({ page }) => {
  await login(page);
  await logout(page);
  expect(page.url()).toContain('login.php');
});
```

- [ ] **Step 2: Run the login tests**

```bash
cd c:\xampp\htdocs\Cafe\tests
npx playwright test 01-login.spec.js --reporter=line
```

Expected: `3 passed`

If a test fails, check `tests/screenshots/` for a screenshot of the failure.

- [ ] **Step 3: Commit**

```bash
git add tests/01-login.spec.js
git commit -m "test: add login E2E tests (Playwright)"
```

---

### Task 6: Write and Run Order Tests

**Files:**
- Create: `tests/02-order.spec.js`

- [ ] **Step 1: Create `tests/02-order.spec.js`**

```javascript
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

test('add item to cart and confirm cash order', async ({ page }) => {
  let orderId = null;

  await login(page);

  // Quick-add the first available product on the menu
  await page.goto('/menu.php');
  await page.locator('.quick-add-btn').first().click();
  await page.waitForTimeout(800); // wait for AJAX response from add_to_cart.php

  // Navigate to cart and verify item is there
  await page.goto('/cart.php');
  await expect(page.locator('.cart-item').first()).toBeVisible();

  // Switch to Drink Out (avoids needing a table number)
  await page.click('#btnDrinkOut');

  // Fill customer name
  await page.fill('#customer_name', 'Test Customer');

  // Select cash payment
  await page.locator('.payment-method:has(input[value="cash"])').click();

  // Confirm the order
  await page.click('#confirmBtn');

  // Should redirect to payment_cash.php
  await page.waitForURL('**/payment_cash.php**', { timeout: 10000 });
  expect(page.url()).toContain('payment_cash.php');

  // Extract order_id from URL
  orderId = new URL(page.url()).searchParams.get('order_id');
  expect(orderId).not.toBeNull();

  // Cleanup
  await deleteOrder(orderId);
});
```

- [ ] **Step 2: Run the order test**

```bash
cd c:\xampp\htdocs\Cafe\tests
npx playwright test 02-order.spec.js --reporter=line
```

Expected: `1 passed`

If the test fails at the cart step, log in manually as `test_cashier` and verify you can add items and reach the cart.

- [ ] **Step 3: Commit**

```bash
git add tests/02-order.spec.js
git commit -m "test: add order creation E2E test (Playwright)"
```

---

### Task 7: Write and Run Payment Tests

**Files:**
- Create: `tests/03-payment.spec.js`

- [ ] **Step 1: Create `tests/03-payment.spec.js`**

```javascript
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

async function addItemAndGoToCart(page) {
  await page.goto('/menu.php');
  await page.locator('.quick-add-btn').first().click();
  await page.waitForTimeout(800);
  await page.goto('/cart.php');
  await expect(page.locator('.cart-item').first()).toBeVisible();
  await page.click('#btnDrinkOut');
  await page.fill('#customer_name', 'Test Customer');
}

test('cash payment lands on receipt page', async ({ page }) => {
  let orderId = null;
  await login(page);
  await addItemAndGoToCart(page);

  await page.locator('.payment-method:has(input[value="cash"])').click();
  await page.click('#confirmBtn');

  await page.waitForURL('**/payment_cash.php**', { timeout: 10000 });
  expect(page.url()).toContain('payment_cash.php');
  await expect(page.locator('body')).toContainText('Cash');

  orderId = new URL(page.url()).searchParams.get('order_id');
  await deleteOrder(orderId);
});

test('pay-later order lands on paylater page', async ({ page }) => {
  let orderId = null;
  await login(page);
  await addItemAndGoToCart(page);

  await page.locator('.payment-method:has(input[value="paylater"])').click();
  await page.click('#confirmBtn');

  await page.waitForURL('**/payment_paylater.php**', { timeout: 10000 });
  expect(page.url()).toContain('payment_paylater.php');
  await expect(page.locator('body')).toContainText(/pay later|unpaid|pending/i);

  orderId = new URL(page.url()).searchParams.get('order_id');
  await deleteOrder(orderId);
});
```

- [ ] **Step 2: Run the payment tests**

```bash
cd c:\xampp\htdocs\Cafe\tests
npx playwright test 03-payment.spec.js --reporter=line
```

Expected: `2 passed`

- [ ] **Step 3: Commit**

```bash
git add tests/03-payment.spec.js
git commit -m "test: add cash and pay-later payment E2E tests (Playwright)"
```

---

### Task 8: Write and Run Receipt Tests

**Files:**
- Create: `tests/04-receipt.spec.js`

- [ ] **Step 1: Create `tests/04-receipt.spec.js`**

```javascript
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

async function createCashOrder(page) {
  await page.goto('/menu.php');
  await page.locator('.quick-add-btn').first().click();
  await page.waitForTimeout(800);
  await page.goto('/cart.php');
  await expect(page.locator('.cart-item').first()).toBeVisible();
  await page.click('#btnDrinkOut');
  await page.fill('#customer_name', 'Test Customer');
  await page.locator('.payment-method:has(input[value="cash"])').click();
  await page.click('#confirmBtn');
  await page.waitForURL('**/payment_cash.php**', { timeout: 10000 });
  return new URL(page.url()).searchParams.get('order_id');
}

test('receipt print page shows customer name and total', async ({ page }) => {
  await login(page);
  const orderId = await createCashOrder(page);

  await page.goto(`/receipt_print.php?order_id=${orderId}`);
  await expect(page.locator('body')).toContainText('Test Customer');
  await expect(page.locator('body')).toContainText('$');

  await deleteOrder(orderId);
});

test('receipt PDF loads without HTTP error', async ({ page }) => {
  await login(page);
  const orderId = await createCashOrder(page);

  const response = await page.goto(`/receipt_pdf.php?order_id=${orderId}`);
  expect(response.status()).toBeLessThan(400);

  await deleteOrder(orderId);
});
```

- [ ] **Step 2: Run the receipt tests**

```bash
cd c:\xampp\htdocs\Cafe\tests
npx playwright test 04-receipt.spec.js --reporter=line
```

Expected: `2 passed`

- [ ] **Step 3: Commit**

```bash
git add tests/04-receipt.spec.js
git commit -m "test: add receipt E2E tests (Playwright)"
```

---

### Task 9: Run Full Suite and Clean Up

**Files:**
- Modify: `.gitignore` (root of project)

- [ ] **Step 1: Run all 8 tests**

```bash
cd c:\xampp\htdocs\Cafe\tests
npx playwright test --reporter=list
```

Expected:
```
  ✓  01-login.spec.js › valid credentials redirect to dashboard
  ✓  01-login.spec.js › wrong password redirects back with error message
  ✓  01-login.spec.js › logout redirects to login page
  ✓  02-order.spec.js › add item to cart and confirm cash order
  ✓  03-payment.spec.js › cash payment lands on receipt page
  ✓  03-payment.spec.js › pay-later order lands on paylater page
  ✓  04-receipt.spec.js › receipt print page shows customer name and total
  ✓  04-receipt.spec.js › receipt PDF loads without HTTP error

  8 passed
```

- [ ] **Step 2: Add test artifacts to .gitignore**

Add these lines to `c:\xampp\htdocs\Cafe\.gitignore` (create the file if it doesn't exist):

```
tests/node_modules/
tests/screenshots/
tests/test-results/
tests/.env
```

- [ ] **Step 3: Commit .gitignore**

```bash
git add .gitignore
git commit -m "chore: ignore Playwright node_modules, screenshots, and test-results"
```
