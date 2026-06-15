const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

test('add item to cart and confirm cash order', async ({ page }) => {
  let orderId = null;

  try {
    await login(page);

    // Quick-add the first available product; wait for AJAX instead of fixed timeout
    await page.goto('menu.php');
    const [response] = await Promise.all([
      page.waitForResponse(r => r.url().includes('add_to_cart.php') && r.status() === 200),
      page.locator('.quick-add-btn').first().click(),
    ]);
    expect(response.status()).toBe(200);

    // Navigate to cart and verify item is there
    await page.goto('cart.php');
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
  } finally {
    if (orderId) await deleteOrder(orderId);
  }
});
