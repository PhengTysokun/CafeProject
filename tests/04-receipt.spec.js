const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

async function createCashOrder(page) {
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
  await page.locator('.payment-method:has(input[value="cash"])').click();
  await page.click('#confirmBtn');
  await page.waitForURL('**/payment_cash.php**', { timeout: 10000 });
  return new URL(page.url()).searchParams.get('order_id');
}

test('receipt print page shows customer name and total', async ({ page }) => {
  let orderId = null;
  try {
    await login(page);
    orderId = await createCashOrder(page);

    await page.goto(`receipt_print.php?order_id=${orderId}`);
    await expect(page.locator('body')).toContainText('Test Customer');
    await expect(page.locator('body')).toContainText('$');
  } finally {
    if (orderId) await deleteOrder(orderId);
  }
});

test('receipt PDF loads without HTTP error', async ({ page }) => {
  let orderId = null;
  try {
    await login(page);
    orderId = await createCashOrder(page);

    // receipt_pdf.php serves a PDF download; use request API to check HTTP status
    const response = await page.request.get(`receipt_pdf.php?order_id=${orderId}`);
    expect(response.status()).toBeLessThan(400);
  } finally {
    if (orderId) await deleteOrder(orderId);
  }
});
