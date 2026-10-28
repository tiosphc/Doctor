import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const stateDirectory = resolve(import.meta.dirname, '../../../.codex/browser-qa/state');
const issuesByPage = new WeakMap();

function qaPassword() {
  const content = readFileSync(resolve(import.meta.dirname, '../../.env.blade_qa'), 'utf8');
  const entry = content.split(/\r?\n/).find((line) => line.startsWith('DEMO_ADMIN_PASSWORD='));
  const value = entry?.slice('DEMO_ADMIN_PASSWORD='.length).trim();
  if (!value) {
    throw new Error('QA password is not configured.');
  }
  return value.length > 1 && value[0] === value.at(-1) && ["'", '"'].includes(value[0])
    ? value.slice(1, -1)
    : value;
}

function captureIssues(page) {
  const issues = { console: [], javascript: [], network: [] };
  issuesByPage.set(page, issues);
  page.on('console', (message) => {
    if (message.type() === 'error') {
      issues.console.push(message.text());
    }
  });
  page.on('pageerror', (error) => issues.javascript.push(error.message));
  page.on('requestfailed', (request) => {
    issues.network.push({ path: new URL(request.url()).pathname, reason: request.failure()?.errorText });
  });
  page.on('response', (response) => {
    if (response.status() >= 400) {
      issues.network.push({ path: new URL(response.url()).pathname, status: response.status() });
    }
  });
}

async function visit(page, path) {
  const response = await page.goto(path);
  expect(response, `No browser response for ${path}`).not.toBeNull();
  expect(response.status(), `Browser response for ${path}`).toBeLessThan(400);
  await expect(page.locator('main')).toBeVisible();
}

function blocked(testInfo, reason) {
  testInfo.annotations.push({ type: 'qa-blocked', description: reason });
}

function retailQaSnapshot(operationKey = '') {
  const code = `$identity = DB::selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user');
    $user = App\\Models\\User::query()->where('email', 'retail1@demo.local')->firstOrFail();
    $variant = App\\Models\\ProductVariant::query()->where('sku', 'DEMO-SKU-01-1')->firstOrFail();
    $cart = App\\Models\\Cart::query()->where('user_id', $user->id)->where('context', 'retail')->where('status', 'active')->first();
    $customer = App\\Models\\Customer::query()->where('user_id', $user->id)->firstOrFail();
    $order = App\\Models\\SalesOrder::query()->where('creation_operation_key', '${operationKey}')->first();
    $qaOrder = App\\Models\\SalesOrder::query()->where('buyer_user_id', $user->id)->where('recipient_name', 'QA Browser Retail')->orderByDesc('id')->first();
    echo 'QA_SNAPSHOT='.json_encode(['database' => $identity->db_name, 'db_user' => $identity->db_user,
      'buyer_id' => $user->id, 'user_name' => $user->name, 'user_phone' => $user->phone,
      'customer_name' => $customer->name, 'customer_phone' => $customer->primary_phone,
      'variant_id' => $variant->id, 'active_cart_id' => $cart?->id,
      'cart_quantity' => $cart?->items()->where('product_variant_id', $variant->id)->first()?->quantity,
      'order_count' => App\\Models\\SalesOrder::query()->where('buyer_user_id', $user->id)->count(),
      'stock' => App\\Models\\InventoryBalance::query()->where('product_variant_id', $variant->id)->orderBy('warehouse_id')->pluck('on_hand_quantity')->toArray(),
      'qa_order' => $qaOrder ? ['id' => $qaOrder->id, 'payment_status' => $qaOrder->payment_status] : null,
      'order' => $order ? ['id' => $order->id, 'buyer_id' => $order->buyer_user_id, 'payment_status' => $order->payment_status, 'payment_method' => $order->payment_method] : null,
    ]).PHP_EOL;`;
  const output = execFileSync('php', ['artisan', 'tinker', '--env=blade_qa', '--execute', code], {
    cwd: resolve(import.meta.dirname, '../..'), encoding: 'utf8', timeout: 20_000,
  });
  const line = output.split(/\r?\n/).find((entry) => entry.startsWith('QA_SNAPSHOT='));
  if (!line) {
    throw new Error('QA snapshot query failed.');
  }
  const snapshot = JSON.parse(line.slice('QA_SNAPSHOT='.length));
  if (snapshot.database !== 'aesthetic_clinic_blade_qa' || snapshot.db_user !== 'doctor_blade_qa@127.0.0.1') {
    throw new Error('QA snapshot points outside the isolated QA database.');
  }
  return snapshot;
}

function dealerApplicantSnapshot() {
  const code = `$identity = DB::selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user');
    $user = App\\Models\\User::query()->where('email', 'qa.browser.applicant.20261011@demo.local')->first();
    $application = App\\Models\\DealerApplication::query()->where('email', 'qa.browser.applicant.20261011@demo.local')->first();
    echo 'QA_APPLICANT='.json_encode(['database' => $identity->db_name, 'db_user' => $identity->db_user,
      'user_id' => $user?->id, 'role' => $user?->role,
      'application_id' => $application?->id, 'status' => $application?->status,
      'application_count' => App\\Models\\DealerApplication::query()->where('email', 'qa.browser.applicant.20261011@demo.local')->count(),
      'account_count' => $application ? App\\Models\\DealerAccount::query()->where('source_application_id', $application->id)->count() : 0,
      'account_id' => $application?->approvedAccount?->id,
      'membership_count' => $user ? App\\Models\\DealerAccountUser::query()->where('user_id', $user->id)->where('status', 'active')->count() : 0,
    ]).PHP_EOL;`;
  const output = execFileSync('php', ['artisan', 'tinker', '--env=blade_qa', '--execute', code], {
    cwd: resolve(import.meta.dirname, '../..'), encoding: 'utf8', timeout: 20_000,
  });
  const line = output.split(/\r?\n/).find((entry) => entry.startsWith('QA_APPLICANT='));
  if (!line) {
    throw new Error('QA applicant query failed.');
  }
  const snapshot = JSON.parse(line.slice('QA_APPLICANT='.length));
  if (snapshot.database !== 'aesthetic_clinic_blade_qa' || snapshot.db_user !== 'doctor_blade_qa@127.0.0.1') {
    throw new Error('QA applicant query points outside the isolated QA database.');
  }
  return snapshot;
}

function dealerAddressSnapshot() {
  const code = `$identity = DB::selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user');
    $user = App\\Models\\User::query()->where('email', 'dealer1@demo.local')->firstOrFail();
    $account = app(App\\Services\\DealerContextService::class)->primaryAccount($user);
    $address = App\\Models\\DealerShippingAddress::query()->where('recipient_name', 'QA Browser Address 20261011')->first();
    echo 'QA_ADDRESS='.json_encode(['database' => $identity->db_name, 'db_user' => $identity->db_user,
      'account_id' => $account?->id, 'address_id' => $address?->id,
      'address_account_id' => $address?->dealer_account_id,
      'address_count' => App\\Models\\DealerShippingAddress::query()->where('dealer_account_id', $account?->id)->count(),
    ]).PHP_EOL;`;
  const output = execFileSync('php', ['artisan', 'tinker', '--env=blade_qa', '--execute', code], {
    cwd: resolve(import.meta.dirname, '../..'), encoding: 'utf8', timeout: 20_000,
  });
  const line = output.split(/\r?\n/).find((entry) => entry.startsWith('QA_ADDRESS='));
  if (!line) {
    throw new Error('QA address query failed.');
  }
  const snapshot = JSON.parse(line.slice('QA_ADDRESS='.length));
  if (snapshot.database !== 'aesthetic_clinic_blade_qa' || snapshot.db_user !== 'doctor_blade_qa@127.0.0.1'
    || !snapshot.account_id || (snapshot.address_id && snapshot.address_account_id !== snapshot.account_id)) {
    throw new Error('QA address preflight points outside the synthetic dealer account.');
  }
  return snapshot;
}

test.beforeEach(async ({ page }) => captureIssues(page));

test.afterEach(async ({ page }, testInfo) => {
  const issues = issuesByPage.get(page) ?? { console: [], javascript: [], network: [] };
  const redacted = JSON.stringify(issues, null, 2)
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[email]')
    .replace(/\b(?:\+?84|0)\d{9,10}\b/g, '[phone]');
  await testInfo.attach('console-javascript-network.json', {
    body: Buffer.from(redacted),
    contentType: 'application/json',
  });

  if (!page.isClosed()) {
    const sensitivePath = /^\/(?:my-orders\/\d+|dealer\/application\/\d+|dealer\/wallet|dealer\/addresses|admin\/customers|admin\/dealer-applications\/\d+|admin\/sales\/orders\/\d+)/.test(new URL(page.url()).pathname);
    const screenshot = await page.screenshot({
      fullPage: false,
      mask: [
        page.locator('input:not([type=hidden]), textarea'),
        page.locator('table tbody, dl dd'),
        page.locator('[href*="/proof"]'),
        ...(sensitivePath ? [page.locator('main')] : []),
      ],
    });
    await testInfo.attach('masked-screenshot.png', { body: screenshot, contentType: 'image/png' });
  }
});

test.describe('Guest', () => {
  test('02 Retail Shop search, filters, sort, detail and empty result', async ({ page }) => {
    await visit(page, '/shop');
    await page.locator('#shop-search').fill('DEMO-SKU-01-1');
    await page.getByRole('button', { name: 'Áp dụng' }).click();
    await expect(page).toHaveURL(/search=DEMO-SKU-01-1/);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');

    await page.locator('#shop-search').fill('');
    if (await page.locator('#shop-category option').count() > 1) {
      await page.locator('#shop-category').selectOption({ index: 1 });
    }
    if (await page.locator('#shop-brand option').count() > 1) {
      await page.locator('#shop-brand').selectOption({ index: 1 });
    }
    await page.locator('#shop-sort').selectOption('name');
    await page.getByRole('button', { name: 'Áp dụng' }).click();
    await expect(page).toHaveURL(/sort=name/);

    await visit(page, '/shop/demo-product-01');
    await expect(page.locator('main')).toContainText('DEMO-PRD-01');
    await expect(page.locator('#retail-variant')).toContainText('DEMO-SKU-01-1');

    await visit(page, '/shop?search=QA-NONEXISTENT-SKU-8I');
    await expect(page.locator('main')).toContainText('Không có sản phẩm phù hợp');
  });
});

test.describe('Admin', () => {
  test.use({ storageState: resolve(stateDirectory, 'admin.json') });

  test('01A Admin navigation and logout protection', async ({ page, browser }) => {
    const guest = await browser.newContext({
      baseURL: 'http://127.0.0.1:8001',
      storageState: { cookies: [], origins: [] },
    });
    try {
      const guestPage = await guest.newPage();
      await guestPage.goto('/admin');
      await expect(guestPage).toHaveURL(/\/login/);
    } finally {
      await guest.close();
    }

    await visit(page, '/admin');
    const entries = [
      ['Tổng quan', '/admin/reports'],
      ['Quản lý sản phẩm', '/admin/products'],
      ['Kho & tồn kho', '/admin/inventory'],
      ['Mua hàng & nhập hàng', '/admin/purchase-orders'],
      ['Quản lý bán hàng', '/admin/sales/orders'],
      ['Giá bán & khuyến mãi', '/admin/prices/retail'],
      ['Quản lý đại lý', '/admin/dealers'],
      ['Tài chính đại lý', '/admin/dealer-wallets'],
      ['Hệ thống & tiện ích', '/admin/audit-logs'],
    ];
    for (const [group, path] of entries) {
      if (page.viewportSize().width < 1024) {
        await page.getByRole('button', { name: 'Mở điều hướng' }).click();
      }
      const sidebar = page.locator('[data-admin-sidebar]');
      const groupButton = sidebar.getByRole('button', { name: group });
      if (await groupButton.getAttribute('aria-expanded') !== 'true') {
        await groupButton.click();
      }
      await sidebar.locator(`nav a[href$="${path}"]`).click();
      await expect(page).toHaveURL(new RegExp(`${path.replaceAll('/', '\\/')}$`));
      await expect(page.locator('main')).toBeVisible();
    }

    const logoutContext = await browser.newContext({
      baseURL: 'http://127.0.0.1:8001',
      storageState: { cookies: [], origins: [] },
    });
    try {
      const logoutPage = await logoutContext.newPage();
      await logoutPage.goto('/login');
      await logoutPage.locator('input[name=email]').fill('admin@demo.local');
      await logoutPage.locator('input[name=password]').fill(qaPassword());
      await logoutPage.getByRole('button', { name: 'Đăng nhập' }).click();
      await expect(logoutPage).toHaveURL(/\/admin$/);
      await logoutPage.getByRole('button', { name: 'Đăng xuất' }).click();
      await logoutPage.goto('/admin');
      await expect(logoutPage).toHaveURL(/\/login/);
    } finally {
      await logoutContext.close();
    }
  });

  test('01B Admin product, pricing, inventory and purchase screens', async ({ page }, testInfo) => {
    for (const path of ['/admin/products', '/admin/prices/retail', '/admin/prices/dealer', '/admin/inventory', '/admin/purchase-orders']) {
      await visit(page, path);
    }
    await visit(page, '/admin/products');
    await expect(page.locator('main')).toContainText('DEMO-PRD-01');
    blocked(testInfo, 'Product, price, PO and Goods Receipt writes need dedicated QA fixture and before/after stock and price reconciliation.');
  });

  test('05+06 Synthetic customer applies and Admin approves once', async ({ page, browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'A single synthetic application is created and approved on desktop only.');
    const before = dealerApplicantSnapshot();
    expect(before.application_count).toBeLessThanOrEqual(1);
    expect(before.account_count).toBeLessThanOrEqual(1);

    const applicant = await browser.newContext({
      baseURL: 'http://127.0.0.1:8001', storageState: { cookies: [], origins: [] },
    });
    try {
      const applicantPage = await applicant.newPage();
      if (!before.user_id) {
        await applicantPage.goto('/register');
        await applicantPage.locator('input[name=name]').fill('QA Browser Applicant 20261011');
        await applicantPage.locator('input[name=email]').fill('qa.browser.applicant.20261011@demo.local');
        await applicantPage.locator('input[name=password]').fill(qaPassword());
        await applicantPage.locator('input[name=password_confirmation]').fill(qaPassword());
        await applicantPage.getByRole('button', { name: 'Tạo tài khoản' }).click();
        await expect(applicantPage).toHaveURL(/\/shop$/);
      } else {
        await applicantPage.goto('/login');
        await applicantPage.locator('input[name=email]').fill('qa.browser.applicant.20261011@demo.local');
        await applicantPage.locator('input[name=password]').fill(qaPassword());
        await applicantPage.getByRole('button', { name: 'Đăng nhập' }).click();
      }

      let current = dealerApplicantSnapshot();
      expect(current.user_id).toBeTruthy();
      expect(current.role).toBe('customer');
      if (!current.application_id) {
        await applicantPage.goto('/dealer/apply');
        await applicantPage.locator('input[name=company_name]').fill('QA Browser Dealer 20261011');
        await applicantPage.locator('input[name=contact_name]').fill('QA Browser Applicant');
        await applicantPage.locator('input[name=email]').fill('qa.browser.applicant.20261011@demo.local');
        await applicantPage.locator('input[name=phone]').fill('invalid-phone');
        await applicantPage.locator('input[name=business_address_line1]').fill('QA Browser Street 2');
        await applicantPage.locator('input[name=city]').fill('Ho Chi Minh');
        await applicantPage.locator('input[name=province]').fill('Ho Chi Minh');
        await applicantPage.getByRole('button', { name: 'Gửi đơn đăng ký' }).click();
        await expect(applicantPage.locator('#phone-error')).toBeVisible();
        expect(dealerApplicantSnapshot().application_id).toBeNull();
        await applicantPage.locator('input[name=phone]').fill('0900000098');
        await applicantPage.getByRole('button', { name: 'Gửi đơn đăng ký' }).click();
        await expect(applicantPage).toHaveURL(/\/dealer\/application\/\d+$/);
        current = dealerApplicantSnapshot();
        expect(current.status).toBe('pending');
        expect(current.account_count).toBe(0);
        expect(current.membership_count).toBe(0);
      }

      const outsider = await browser.newContext({
        baseURL: 'http://127.0.0.1:8001',
        storageState: resolve(stateDirectory, 'retail.json'),
      });
      try {
        const outsiderPage = await outsider.newPage();
        const denied = await outsiderPage.goto(`/dealer/application/${current.application_id}`);
        expect(denied.status()).toBe(404);
      } finally {
        await outsider.close();
      }

      if (current.status === 'pending') {
        await visit(page, `/admin/dealer-applications/${current.application_id}`);
        await page.getByRole('button', { name: 'Duyệt đơn' }).first().click();
        await page.getByRole('dialog', { name: 'Duyệt đơn đăng ký đại lý' })
          .getByRole('button', { name: 'Duyệt đơn' }).click();
      }
      const after = dealerApplicantSnapshot();
      expect(after.status).toBe('approved');
      expect(after.application_count).toBe(1);
      expect(after.account_count).toBe(1);
      expect(after.membership_count).toBe(1);
      await visit(page, `/admin/dealer-applications/${after.application_id}`);
      expect(dealerApplicantSnapshot().account_count).toBe(1);
      await applicantPage.goto('/dealer/orders');
      await expect(applicantPage.locator('main')).toBeVisible();
      await testInfo.attach('qa-dealer-application-before-after.json', {
        body: Buffer.from(JSON.stringify({ before, after }, null, 2)),
        contentType: 'application/json',
      });
    } finally {
      await applicant.close();
    }
  });

  test('06 Admin dealer application and account review', async ({ page }) => {
    for (const path of ['/admin/dealer-applications', '/admin/dealers', '/admin/dealer-tiers']) {
      await visit(page, path);
    }
    const applicant = dealerApplicantSnapshot();
    expect(applicant.status).toBe('approved');
    expect(applicant.application_count).toBe(1);
    expect(applicant.account_count).toBe(1);
    expect(applicant.membership_count).toBe(1);
    await visit(page, `/admin/dealer-applications/${applicant.application_id}`);
  });

  test('11 Admin sales order detail and lifecycle review', async ({ page }, testInfo) => {
    await visit(page, '/admin/sales/orders');
    const orderLink = page.locator('main a[aria-label^="Xem chi tiết đơn"]').first();
    if (await orderLink.count()) {
      await orderLink.click();
      await expect(page).toHaveURL(/\/admin\/sales\/orders\/\d+$/);
    }
    blocked(testInfo, 'Confirm, fulfillment and manual payment require a dedicated new QA order and financial before/after reconciliation.');
  });

  test('12 Admin return and refund review', async ({ page }, testInfo) => {
    await visit(page, '/admin/sales/returns');
    blocked(testInfo, 'Return and refund writes require an eligible synthetic delivered and paid order with reconciled stock and payment baseline.');
  });

  test('13 Reports, audit logs and customers are readable', async ({ page }) => {
    await visit(page, '/admin/reports');
    await expect(page.getByRole('heading', { name: 'Báo cáo ERP' })).toBeVisible();
    await visit(page, '/admin/audit-logs');
    await visit(page, '/admin/customers');
  });

  test('14 Admin role protection and responsive layout', async ({ page, browser }) => {
    await visit(page, '/admin');
    if (page.viewportSize().width < 1024) {
      await page.getByRole('button', { name: 'Mở điều hướng' }).click();
      await expect(page.locator('[data-admin-sidebar]')).toBeInViewport();
      await page.getByRole('button', { name: 'Đóng điều hướng' }).click();
    }
    const guest = await browser.newContext({
      baseURL: 'http://127.0.0.1:8001',
      storageState: { cookies: [], origins: [] },
    });
    try {
      const guestPage = await guest.newPage();
      await guestPage.goto('/admin/sales/orders');
      await expect(guestPage).toHaveURL(/\/login/);
      await guestPage.goto('/login?return_to=https%3A%2F%2Fevil.example');
      const externalReturn = guestPage.locator('input[name=return_to]');
      if (await externalReturn.count()) {
        expect(await externalReturn.inputValue()).not.toContain('evil.example');
      }
      await guestPage.goto('/dealer/orders');
      await expect(guestPage).toHaveURL(/\/login/);
    } finally {
      await guest.close();
    }

    for (const role of ['retail', 'dealer']) {
      const context = await browser.newContext({
        baseURL: 'http://127.0.0.1:8001',
        storageState: resolve(stateDirectory, `${role}.json`),
      });
      try {
        const rolePage = await context.newPage();
        const denied = await rolePage.goto('/admin/sales/orders');
        expect(denied.status()).toBe(403);
      } finally {
        await context.close();
      }
    }

    for (const path of ['/admin/products', '/admin/sales/orders', '/admin/reports']) {
      await visit(page, path);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `${path} must not overflow the viewport`).toBeLessThanOrEqual(1);
    }
  });
});

test.describe('Retail customer', () => {
  test.use({ storageState: resolve(stateDirectory, 'retail.json') });

  test('03 Cart and checkout review', async ({ page }, testInfo) => {
    const before = retailQaSnapshot();
    if (before.qa_order) {
      await visit(page, `/my-orders/${before.qa_order.id}`);
      await expect(page.locator('main')).toContainText('QA Browser Retail');
      if (testInfo.project.name !== 'desktop-1440') {
        blocked(testInfo, 'Tablet/mobile reviewed the synthetic order; cart and checkout submit were run once on desktop.');
      }
      return;
    }
    expect(before.active_cart_id).toBeTruthy();
    expect(Number(before.cart_quantity)).toBeGreaterThan(0);
    await visit(page, '/cart');
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    if (testInfo.project.name === 'desktop-1440') {
      const originalQuantity = Number(before.cart_quantity);
      const quantity = page.locator('form[action*="/cart/items/"] input[name=quantity]').first();
      await quantity.fill(String(originalQuantity + 1));
      await quantity.locator('xpath=../..').getByRole('button', { name: 'Cập nhật' }).click();
      await expect(page.locator('form[action*="/cart/items/"] input[name=quantity]').first()).toHaveValue(String(originalQuantity + 1));
      await page.reload();
      const restoredQuantity = page.locator('form[action*="/cart/items/"] input[name=quantity]').first();
      await expect(restoredQuantity).toHaveValue(String(originalQuantity + 1));
      await restoredQuantity.fill(String(originalQuantity));
      await restoredQuantity.locator('xpath=../..').getByRole('button', { name: 'Cập nhật' }).click();
      await expect(page.locator('form[action*="/cart/items/"] input[name=quantity]').first()).toHaveValue(String(originalQuantity));
      await page.locator('input[name=code]').fill('QA-BLADE-V5');
      await page.getByRole('button', { name: 'Áp dụng' }).click();
      await expect(page.locator('main')).toContainText('QA-BLADE-V5');
    }
    await visit(page, '/checkout');
    await expect(page.locator('main')).toContainText('Xác nhận đặt hàng');
    if (testInfo.project.name !== 'desktop-1440') {
      blocked(testInfo, 'Cart mutation and checkout submit were executed only at desktop; this viewport was reviewed without a second order.');
      return;
    }

    const beforeSubmit = retailQaSnapshot();
    expect(beforeSubmit.order_count).toBe(before.order_count);
    expect(beforeSubmit.stock).toEqual(before.stock);
    const operationKey = await page.locator('input[name=checkout_operation_key]').inputValue();
    expect(operationKey).toMatch(/^[0-9a-f-]{36}$/i);
    await page.locator('input[name=recipient_name]').fill('QA Browser Retail');
    await page.locator('input[name=recipient_phone]').fill('0900000001');
    await page.locator('input[name=recipient_email]').fill('retail1@demo.local');
    await page.locator('input[name=shipping_address_line1]').fill('QA Browser Street 1');
    await page.locator('input[name=shipping_district]').fill('QA District');
    await page.locator('input[name=shipping_city]').fill('Ho Chi Minh');
    await page.locator('input[name=shipping_province]').fill('Ho Chi Minh');
    await page.locator('select[name=payment_method]').selectOption('cod');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Xác nhận đặt hàng' }).click();
    await expect(page).toHaveURL(/\/my-orders\/\d+$/);

    const after = retailQaSnapshot(operationKey);
    expect(after.order_count).toBe(before.order_count + 1);
    expect(after.stock).toEqual(before.stock);
    expect(after.order).toMatchObject({ buyer_id: before.buyer_id, payment_status: 'unpaid', payment_method: 'cod' });
    expect(Number(new URL(page.url()).pathname.split('/').at(-1))).toBe(after.order.id);
    await testInfo.attach('qa-checkout-before-after.json', {
      body: Buffer.from(JSON.stringify({ before, beforeSubmit, after }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('04 Customer profile and own orders', async ({ page }, testInfo) => {
    const before = retailQaSnapshot();
    await visit(page, '/account/profile');
    if (testInfo.project.name === 'desktop-1440') {
      expect(before.user_name).toBe(before.customer_name);
      expect(before.user_phone).toBe(before.customer_phone);
      try {
        await page.locator('#profile-name').fill('QA Browser Profile');
        await page.locator('#profile-phone').fill('0999999999');
        await page.getByRole('button', { name: 'Lưu thông tin' }).click();
        await expect(page.locator('#profile-name')).toHaveValue('QA Browser Profile');
        const changed = retailQaSnapshot();
        expect(changed.user_name).toBe('QA Browser Profile');
        expect(changed.customer_name).toBe('QA Browser Profile');
        expect(changed.user_phone).toBe('0999999999');
        expect(changed.customer_phone).toBe('0999999999');
      } finally {
        await visit(page, '/account/profile');
        await page.locator('#profile-name').fill(before.user_name);
        await page.locator('#profile-phone').fill(before.user_phone ?? '');
        await page.getByRole('button', { name: 'Lưu thông tin' }).click();
      }
      const after = retailQaSnapshot();
      expect(after.user_name).toBe(before.user_name);
      expect(after.customer_name).toBe(before.customer_name);
      expect(after.user_phone).toBe(before.user_phone);
      expect(after.customer_phone).toBe(before.customer_phone);
      await testInfo.attach('qa-profile-before-after.json', {
        body: Buffer.from(JSON.stringify({ before, after }, null, 2)),
        contentType: 'application/json',
      });
    }
    await visit(page, '/my-orders');
    if (testInfo.project.name !== 'desktop-1440') {
      blocked(testInfo, 'Profile update and canonical Customer synchronization were executed on desktop only.');
    }
  });

  test('05 Dealer application form and status', async ({ page }) => {
    await visit(page, '/dealer/apply');
    const applicant = dealerApplicantSnapshot();
    expect(applicant.status).toBe('approved');
    expect(applicant.application_count).toBe(1);
    expect(applicant.account_count).toBe(1);
    expect(applicant.membership_count).toBe(1);
  });
});

test.describe('Dealer', () => {
  test.use({ storageState: resolve(stateDirectory, 'dealer.json') });

  test('07 Dealer catalog, profile, addresses and promotions', async ({ page, browser }, testInfo) => {
    for (const path of ['/dealer/orders', '/dealer/products', '/dealer/products/demo-product-01', '/dealer/profile', '/dealer/addresses', '/dealer/promotions']) {
      await visit(page, path);
      if (path === '/dealer/products/demo-product-01') {
        await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
      }
    }
    if (testInfo.project.name !== 'desktop-1440') {
      return;
    }
    const before = dealerAddressSnapshot();
    if (!before.address_id) {
      await visit(page, '/dealer/addresses/create');
      await page.locator('input[name=recipient_name]').fill('QA Browser Address 20261011');
      await page.locator('input[name=recipient_phone]').fill('0900000077');
      await page.locator('input[name=address_line]').fill('QA Browser Street 7');
      const province = page.locator('select[name=province_code]');
      const options = await province.locator('option').count();
      expect(options).toBeGreaterThan(1);
      await province.selectOption({ index: 1 });
      await expect(page.locator('select[name=ward_code] option')).not.toHaveCount(1);
      await page.locator('select[name=ward_code]').selectOption({ index: 1 });
      if (options > 2) {
        await province.selectOption({ index: 2 });
        await expect(page.locator('select[name=ward_code]')).toHaveValue('');
        await province.selectOption({ index: 1 });
        await expect(page.locator('select[name=ward_code] option')).not.toHaveCount(1);
        await page.locator('select[name=ward_code]').selectOption({ index: 1 });
      }
      await page.getByRole('button', { name: 'Lưu địa chỉ' }).click();
      await expect(page).toHaveURL(/\/dealer\/addresses$/);
    }
    const after = dealerAddressSnapshot();
    expect(after.address_id).toBeTruthy();
    expect(after.address_account_id).toBe(before.account_id);
    expect(after.address_count).toBe(before.address_count + (before.address_id ? 0 : 1));

    const applicant = await browser.newContext({ baseURL: 'http://127.0.0.1:8001', storageState: { cookies: [], origins: [] } });
    try {
      const outsider = await applicant.newPage();
      await outsider.goto('/login');
      await outsider.locator('input[name=email]').fill('qa.browser.applicant.20261011@demo.local');
      await outsider.locator('input[name=password]').fill(qaPassword());
      await outsider.getByRole('button', { name: 'Đăng nhập' }).click();
      const denied = await outsider.goto(`/dealer/addresses/${after.address_id}/edit`);
      expect(denied.status()).toBe(404);
    } finally {
      await applicant.close();
    }
    await testInfo.attach('qa-address-before-after.json', {
      body: Buffer.from(JSON.stringify({ before, after }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('08 Product SKU opens Quick Order preselection', async ({ page }, testInfo) => {
    await visit(page, '/dealer/products/demo-product-01');
    const skuLink = page.getByRole('link', { name: /Đặt SKU DEMO-SKU-01-1/ });
    await expect(skuLink).toBeVisible();
    const selectedUrl = await skuLink.getAttribute('href');
    await skuLink.click();
    await expect(page).toHaveURL(/\/dealer\/quick-order\?variant_id=\d+/);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    await visit(page, '/dealer/quick-order?variant_id=999999999');
    await expect(page.locator('main')).toContainText('Đặt hàng nhanh');
    testInfo.annotations.push({ type: 'fixture', description: selectedUrl });
    blocked(testInfo, 'Order confirmation needs wallet, stock and reservation before/after checks.');
  });

  test('09 Dealer wallet, deposit request and ledger review', async ({ page }, testInfo) => {
    await visit(page, '/dealer/wallet');
    await expect(page.getByRole('heading', { name: 'Ví đại lý' })).toBeVisible();
    blocked(testInfo, 'Deposit approval is disabled until a new request has an inspected proof and wallet/ledger before/after reconciliation.');
  });

  test('10 Dealer XLSX import form and template', async ({ page }, testInfo) => {
    await visit(page, '/dealer/orders/import');
    await expect(page.locator('input[type=file][accept*=xlsx]')).toBeVisible();
    const template = await page.request.get('/dealer/orders/import/template');
    expect(template.status()).toBe(200);
    expect(template.headers()['content-type']).toMatch(/spreadsheet|octet-stream/);
    blocked(testInfo, 'Upload/confirm needs a unique XLSX fixture and wallet, stock, order and idempotency baselines.');
  });
});
