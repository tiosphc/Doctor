import { expect, test } from '@playwright/test';
import { resolve } from 'node:path';

const retailState = resolve(import.meta.dirname, '../../../.codex/browser-qa/react-phase4/state/retail.json');

test('customer adds product, adjusts cart, and places a pending QA COD order', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'One QA order flow; responsive coverage is separate.');
  test.setTimeout(120_000);
  const context = await browser.newContext({
    baseURL: 'http://127.0.0.1:5173',
    storageState: retailState,
  });
  try {
    const page = await context.newPage();
    await page.goto('/cart');
    if (await page.getByRole('button', { name: 'Xóa giỏ hàng' }).isVisible()) {
      await page.getByRole('button', { name: 'Xóa giỏ hàng' }).click();
    }
    await page.goto('/products/demo-product-01');
    await expect(page.locator('main')).toContainText('Hydrating serum');
    await page.locator('#product-variant').selectOption('1');
    await page.locator('#retail-quantity').fill('1');
    await page.getByRole('button', { name: 'Thêm vào giỏ hàng' }).click();
    await expect(page.getByRole('dialog', { name: 'Thêm vào giỏ hàng thành công' })).toBeVisible();
    await page.goto('/cart');
    const line = page.locator('main article').filter({ hasText: 'DEMO-SKU-01-1' });
    await expect(line).toBeVisible();
    await line.locator('input[type=number]').fill('2');
    await line.getByRole('button', { name: 'Cập nhật' }).click();
    await expect(line.locator('input[type=number]')).toHaveValue('2');
    await expect(line.getByRole('button', { name: 'Cập nhật' })).toBeDisabled();
    await page.getByRole('link', { name: 'Tiến hành đặt hàng' }).click();
    await expect(page).toHaveURL(/\/checkout\/?$/);
    await page.getByLabel('Tên người nhận *').fill('QA React Retail');
    await page.getByLabel('Số điện thoại *').fill('0900000001');
    await page.getByLabel('Địa chỉ *').fill('QA React Street');
    await page.getByLabel('Thành phố *').fill('Ho Chi Minh');
    await page.getByLabel('Quận / huyện *').fill('Quan 1');
    await page.getByLabel('Tỉnh / Thành *').fill('Ho Chi Minh');
    await page.getByLabel('Quốc gia *').fill('VN');
    await page.getByLabel('Phương thức thanh toán *').selectOption('cod');
    await page.getByRole('button', { name: 'Đặt hàng', exact: true }).click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await page.getByRole('button', { name: 'Xác nhận đặt hàng' }).click();
    await expect(page).toHaveURL(/\/checkout\/success\/\d+$/);
    const orderId = page.url().match(/\/checkout\/success\/(\d+)$/)?.[1];
    expect(orderId).toBeTruthy();
    await page.goto('/my-orders');
    await expect(page.locator('main')).toBeVisible();
    await page.goto(`/my-orders/${orderId}`);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    const guest = await browser.newContext({ baseURL: 'http://127.0.0.1:5173' });
    try {
      const denied = await guest.newPage();
      await denied.goto(`/my-orders/${orderId}`);
      await expect(denied).toHaveURL(/\/login(?:\?|$)/);
    } finally {
      await guest.close();
    }
  } finally {
    await context.close();
  }
});
