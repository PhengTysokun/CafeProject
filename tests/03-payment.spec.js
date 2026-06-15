const { test, expect } = require('@playwright/test');
const { login, loginAs } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

async function addItemAndGoToCart(page) {
  await page.goto('menu.php');
  const [response] = await Promise.all([
    page.waitForResponse(r => r.url().includes('add_to_cart.php') && r.status() === 200),
    page.locator('.quick-add-btn').first().click(),
  ]);
  expect(response.status()).toBe(200);
  await page.goto('cart.php');
  await expect(page.locator('.cart-item').first()).toBeVisible();
  await page.click('#btnDrinkOut');
  await page.fill('#customer_name', 'Test Customer');
}

test('cash payment lands on receipt page', async ({ page }) => {
  let orderId = null;
  try {
    await login(page);
    await addItemAndGoToCart(page);

    await page.locator('.payment-method:has(input[value="cash"])').click();
    await page.click('#confirmBtn');

    await page.waitForURL('**/payment_cash.php**', { timeout: 10000 });
    expect(page.url()).toContain('payment_cash.php');
    await expect(page.locator('body')).toContainText('Cash');

    orderId = new URL(page.url()).searchParams.get('order_id');
  } finally {
    if (orderId) await deleteOrder(orderId);
  }
});

test('pay-later order lands on paylater page', async ({ page }) => {
  let orderId = null;
  try {
    // payment_paylater.php requires admin/manager role
    await loginAs(page, 'test_admin');
    await addItemAndGoToCart(page);

    await page.locator('.payment-method:has(input[value="paylater"])').click();
    await page.click('#confirmBtn');

    await page.waitForURL('**/payment_paylater.php**', { timeout: 10000 });
    expect(page.url()).toContain('payment_paylater.php');
    await expect(page.locator('body')).toContainText(/pay later|unpaid|pending/i);

    orderId = new URL(page.url()).searchParams.get('order_id');
  } finally {
    if (orderId) await deleteOrder(orderId);
  }
});
