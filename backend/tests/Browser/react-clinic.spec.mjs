import { expect, test } from '@playwright/test';
import { resolve } from 'node:path';

const stateDirectory = resolve(import.meta.dirname, '../../../.codex/browser-qa/react-phase4/state');
const api = 'http://127.0.0.1:8001';

test('guest can browse services, doctors, booking steps, and product detail', async ({ page }) => {
  const services = await page.request.get(`${api}/api/services`, { headers: { Accept: 'application/json' } });
  expect(services.status()).toBe(200);
  const service = (await services.json()).data[0];
  expect(service?.id).toBeTruthy();
  await page.goto('/services');
  await expect(page.locator('main')).toContainText('Demo Clinic');
  await page.goto('/doctors');
  await expect(page.locator('main')).toBeVisible();
  await page.goto('/booking');
  await page.locator('main button').filter({ hasText: service.name }).first().click();
  await page.getByRole('button', { name: /Tiếp tục/ }).click();
  await expect(page.locator('main')).toContainText('Doctor Demo');
  await page.locator('main button').filter({ hasText: 'Doctor Demo' }).first().click();
  await page.getByRole('button', { name: /Tiếp tục/ }).click();
  await expect(page.locator('input[type=date]')).toBeAttached();
  await page.goto('/products/demo-product-12');
  await expect(page.locator('main')).toContainText('Hyaluronic mist');
});

test('admin clinic sections load and ERP links are absent from primary navigation', async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: 'http://127.0.0.1:5173',
    storageState: resolve(stateDirectory, 'admin.json'),
  });
  try {
    const page = await context.newPage();
    for (const path of [
      '/admin', '/admin/appointments', '/admin/services', '/admin/service-categories',
      '/admin/doctors', '/admin/staff', '/admin/customers', '/admin/reviews',
      '/admin/voucher-management', '/admin/products', '/admin/sales-orders/retail',
      '/admin/blogs', '/admin/notifications', '/admin/audit-logs',
    ]) {
      await page.goto(path);
      await expect(page).toHaveURL(new RegExp(`${path}/?$`));
      await expect(page.locator('main')).toBeVisible();
    }
    const nav = page.getByRole('navigation', { name: 'Điều hướng quản trị' }).first();
    for (const path of [
      '/admin/suppliers', '/admin/purchase-orders', '/admin/inventory',
      '/admin/warehouses', '/admin/dealers', '/admin/dealer-wallet-top-ups',
      '/admin/reports',
    ]) {
      await expect(nav.locator(`a[href="${path}"]`)).toHaveCount(0);
    }
    const audit = await page.request.get(`${api}/api/admin/audit-logs`, {
      headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:5173', Referer: 'http://127.0.0.1:5173/' },
    });
    expect(audit.status()).toBe(200);
    const payload = JSON.stringify(await audit.json());
    expect(payload).not.toMatch(/password_hash|remember_token|verification_token|api_token/i);
  } finally {
    await context.close();
  }
});

test('customer, doctor, and receptionist appointment screens load by role', async ({ browser }) => {
  for (const [role, path] of [
    ['retail', '/account/appointments'],
    ['doctor', '/doctor/appointments'],
    ['receptionist', '/receptionist/appointments'],
  ]) {
    const context = await browser.newContext({
      baseURL: 'http://127.0.0.1:5173',
      storageState: resolve(stateDirectory, `${role}.json`),
    });
    try {
      const page = await context.newPage();
      await page.goto(path);
      await expect(page).toHaveURL(new RegExp(`${path}/?$`));
      await expect(page.locator('main')).toBeVisible();
    } finally {
      await context.close();
    }
  }
});

test('landing, auth, booking, account and portals fit the viewport', async ({ browser }, testInfo) => {
  const screenshotPaths = [
    ['guest', '/', 'landing'],
    ['guest', '/login', 'login'],
    ['guest', '/register', 'register'],
    ['guest', '/booking', 'booking'],
    ['retail', '/account', 'account'],
    ['admin', '/admin', 'admin'],
    ['doctor', '/doctor', 'doctor'],
    ['receptionist', '/receptionist', 'receptionist'],
  ];
  for (const [role, path, name] of screenshotPaths) {
    const context = await browser.newContext({
      baseURL: 'http://127.0.0.1:5173',
      viewport: testInfo.project.use.viewport,
      ...(role === 'guest' ? {} : { storageState: resolve(stateDirectory, `${role}.json`) }),
    });
    try {
      const page = await context.newPage();
      await page.goto(path);
      await expect(page.locator('main')).toBeVisible();
      await page.waitForLoadState('networkidle');
      if (name === 'admin') {
        await expect(page.locator('[aria-label="Đang tải dữ liệu tổng quan"]')).toHaveCount(0);
      }
      await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      expect(overflow, `${name} horizontal overflow`).toBeLessThanOrEqual(1);
    } finally {
      await context.close();
    }
  }
});

test('site and admin navigation remain usable at each viewport', async ({ browser }, testInfo) => {
  const width = testInfo.project.use.viewport.width;
  const guest = await browser.newContext({ baseURL: 'http://127.0.0.1:5173', viewport: testInfo.project.use.viewport });
  try {
    const page = await guest.newPage();
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    if (width < 1280) {
      await page.getByRole('button', { name: 'Mở menu điều hướng' }).click();
      const menu = page.getByRole('dialog', { name: 'Menu điều hướng' });
      await expect(menu).toBeVisible();
      await expect(menu.getByRole('link', { name: 'Đặt lịch', exact: true })).toBeVisible();
      await menu.getByRole('button', { name: 'Đóng menu' }).click();
    } else {
      await expect(page.getByRole('navigation', { name: 'Điều hướng chính' })).toBeVisible();
    }
  } finally {
    await guest.close();
  }
  const admin = await browser.newContext({
    baseURL: 'http://127.0.0.1:5173',
    viewport: testInfo.project.use.viewport,
    storageState: resolve(stateDirectory, 'admin.json'),
  });
  try {
    const page = await admin.newPage();
    await page.goto('/admin');
    if (width < 1024) {
      await page.getByRole('button', { name: 'Mở menu quản trị' }).click();
      await expect(page.getByRole('dialog').getByRole('navigation', { name: 'Điều hướng quản trị' })).toBeVisible();
    } else {
      await expect(page.getByRole('navigation', { name: 'Điều hướng quản trị' })).toBeVisible();
    }
  } finally {
    await admin.close();
  }
});
