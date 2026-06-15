const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { deleteOrder } = require('./helpers/db');

test('add item to cart and confirm cash order', async ({ page }) => {
  let orderId = null;

  await login(page);

  // Quick-add the first available product on the menu
  await page.goto('menu.php');
  await page.locator('.quick-add-btn').first().click();
  await page.waitForTimeout(800); // wait for AJAX response from add_to_cart.php

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

  // Cleanup
  await deleteOrder(orderId);
});
