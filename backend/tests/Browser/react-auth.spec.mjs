import { expect, test } from '@playwright/test';
import { resolve } from 'node:path';

const stateDirectory = resolve(import.meta.dirname, '../../../.codex/browser-qa/react-phase4/state');
const api = 'http://127.0.0.1:8001';
const browserHeaders = { Accept: 'application/json', Origin: 'http://127.0.0.1:5173', Referer: 'http://127.0.0.1:5173/' };

test('guest is redirected from protected React portals and API returns 401', async ({ page }) => {
  for (const path of ['/account', '/admin', '/doctor', '/receptionist', '/dealer']) {
    await page.goto(path);
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
  }
  const response = await page.request.get(`${api}/api/admin/dashboard`, {
    headers: { Accept: 'application/json' },
  });
  expect(response.status()).toBe(401);
});

for (const [role, email, destination] of [
  ['admin', 'admin@demo.local', '/admin'],
  ['retail', 'retail1@demo.local', '/account'],
  ['dealer', 'dealer1@demo.local', '/dealer'],
  ['doctor', null, '/doctor'],
  ['receptionist', null, '/receptionist'],
]) {
  test(`${role} session reaches its React portal`, async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: 'http://127.0.0.1:5173',
      storageState: resolve(stateDirectory, `${role}.json`),
    });
    try {
      const page = await context.newPage();
      await page.goto(destination);
      await expect(page).toHaveURL(new RegExp(`${destination}/?$`));
      await expect(page.locator('main')).toBeVisible();
      const identity = await page.request.get(`${api}/api/user`, { headers: browserHeaders });
      expect(identity.status()).toBe(200);
      const user = (await identity.json()).data;
      expect(user.role).toBe(role === 'retail' || role === 'dealer' ? 'customer' : role);
      if (email) expect(user.email).toBe(email);
      const cookies = await context.cookies(api);
      expect(cookies.some((cookie) => cookie.name.endsWith('_session'))).toBe(true);
      expect(cookies.some((cookie) => cookie.name === 'XSRF-TOKEN')).toBe(true);
    } finally {
      await context.close();
    }
  });
}

test('customer and doctor cannot use admin UI or API', async ({ browser }) => {
  for (const role of ['retail', 'doctor']) {
    const context = await browser.newContext({
      baseURL: 'http://127.0.0.1:5173',
      storageState: resolve(stateDirectory, `${role}.json`),
    });
    try {
      const page = await context.newPage();
      await page.goto('/admin');
      await expect(page).toHaveURL(role === 'doctor' ? /\/doctor\/?$/ : /\/account\/?$/);
      const response = await page.request.get(`${api}/api/admin/dashboard`, { headers: browserHeaders });
      expect(response.status()).toBe(403);
    } finally {
      await context.close();
    }
  }
});

test('customer can register and log out through React with Sanctum cookies', async ({ page, context }, testInfo) => {
  const email = `qa.react.phase4.${testInfo.project.name}.${Date.now()}@demo.local`;
  const password = 'QaOnlySecurePassword!2026';
  await page.goto('/register');
  await page.locator('input[name=name]').fill('QA React Customer');
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=phone]').fill('0900000001');
  await page.locator('input[name=password]').fill(password);
  await page.locator('input[name=password_confirmation]').fill(password);
  await page.locator('form button[type=submit]').click();
  await expect(page).toHaveURL(/\/account\/?$/);
  expect((await context.cookies(api)).some((cookie) => cookie.name.endsWith('_session'))).toBe(true);
  if (testInfo.project.use.viewport.width < 768) {
    await page.getByRole('button', { name: 'Tài khoản của tôi' }).click();
  }
  await page.getByRole('button', { name: /Đăng xuất/ }).first().click();
  await expect(page).toHaveURL(/\/login\/?$/);
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(password);
  await page.locator('form button[type=submit]').click();
  await expect(page).toHaveURL(/\/account\/?$/);
});

test('legacy web links redirect to React and a deep link stays in React', async ({ page }) => {
  const response = await page.request.get(`${api}/shop`, { maxRedirects: 0 });
  expect(response.status()).toBe(302);
  expect(response.headers().location).toBe('http://127.0.0.1:5173/products');
  await page.goto('/products/demo-product-12');
  await expect(page).toHaveURL(/\/products\/demo-product-12$/);
  await expect(page.locator('main')).toBeVisible();
});

test('React register page hydrates without a browser mismatch', async ({ page }) => {
  const hydrationErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error' && message.text().includes('hydrated but some attributes')) {
      hydrationErrors.push(message.text());
    }
  });
  await page.goto('/register');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('input[name=password_confirmation]')).toBeVisible();
  expect(hydrationErrors).toEqual([]);
});
