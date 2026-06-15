const { test, expect } = require('@playwright/test');
const { login, logout } = require('./helpers/auth');

test('valid credentials redirect to dashboard', async ({ page }) => {
  await page.goto('login.php');
  await page.fill('#u', 'test_cashier');
  await page.fill('#p', 'Test@1234');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 15000 });
  expect(page.url()).toContain('dashboard.php');
});

test('wrong password redirects back with error message', async ({ page }) => {
  await page.goto('login.php');
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
