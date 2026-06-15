async function loginAs(page, username, password = 'Test@1234') {
  await page.goto('login.php');
  await page.fill('#u', username);
  await page.fill('#p', password);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 15000 });
}

async function login(page, username = 'test_cashier', password = 'Test@1234') {
  await page.goto('login.php');
  await page.fill('#u', username);
  await page.fill('#p', password);
  await page.click('button[type="submit"]');
  // loading.php uses a JS setTimeout redirect to dashboard.php
  await page.waitForURL('**/dashboard.php', { timeout: 15000 });
}

async function logout(page) {
  await page.goto('logout.php');
  await page.waitForURL('**/login.php', { timeout: 5000 });
}

module.exports = { login, loginAs, logout };
