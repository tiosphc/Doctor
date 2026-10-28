import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const backendDirectory = resolve(import.meta.dirname, '../..');
const stateDirectory = resolve(backendDirectory, '../.codex/browser-qa/state');
const checkoutRecipient = 'QA 8IC Checkout 20261011';
const checkoutRequestFile = resolve(stateDirectory, 'checkout-first-submit-8i-c.json');
const browserIssues = new WeakMap();

function qaSnapshot(label, body) {
  const code = `$identity = DB::selectOne("SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user");
    if ($identity->db_name !== "aesthetic_clinic_blade_qa" || $identity->db_user !== "doctor_blade_qa@127.0.0.1") {
      throw new RuntimeException("Outside isolated QA database");
    }
    ${body}
    echo "QA_${label}=".json_encode($result).PHP_EOL;`;
  const output = execFileSync('php', ['artisan', 'tinker', '--env=blade_qa', '--execute', code], {
    cwd: backendDirectory, encoding: 'utf8', timeout: 20_000,
  });
  const line = output.split(/\r?\n/).find((entry) => entry.startsWith(`QA_${label}=`));
  if (!line) throw new Error(`QA ${label} snapshot returned no result.`);
  return JSON.parse(line.slice(`QA_${label}=`.length));
}

function qaState(operationKey = null) {
  if (operationKey !== null && !/^[0-9a-f-]{36}$/i.test(operationKey)) throw new Error('Invalid QA operation key.');
  return qaSnapshot('STATE_8IC', `
    $buyer = DB::table("users")->where("email", "retail1@demo.local")->first();
    $variant = DB::table("product_variants")->where("sku", "DEMO-SKU-01-1")->first();
    $original = DB::table("sales_orders")->where("id", 42)->first();
    $originalCart = DB::table("carts")->where("converted_sales_order_id", 42)->first();
    $activeCart = DB::table("carts")->where("user_id", $buyer->id)->where("context", "retail")->where("status", "active")->first();
    $fixture = DB::table("sales_orders")->where("buyer_user_id", $buyer->id)
      ->where("recipient_name", "${checkoutRecipient}")->orderByDesc("id")->first();
    $operation = ${operationKey ? `DB::table("sales_orders")->where("creation_operation_key", "${operationKey}")->first()` : 'null'};
    $order = $operation ?: $fixture;
    $dealer = App\\Models\\User::query()->where("email", "dealer1@demo.local")->firstOrFail();
    $account = app(App\\Services\\DealerContextService::class)->primaryAccount($dealer);
    $wallet = DB::table("dealer_wallets")->where("dealer_account_id", $account->id)->first();
    $warehouse = DB::table("warehouses")->where("is_default_sales", true)->where("status", "active")->first();
    $availableStock = DB::table("inventory_balances")->where("warehouse_id", $warehouse?->id)
      ->where("product_variant_id", $variant->id)->first();
    $result = ["buyer_id" => $buyer->id, "variant_id" => $variant->id,
      "retail_order_count" => DB::table("sales_orders")->where("buyer_user_id", $buyer->id)->count(),
      "operation_order_count" => ${operationKey ? `DB::table("sales_orders")->where("creation_operation_key", "${operationKey}")->count()` : 'null'},
      "fixture_order_count" => DB::table("sales_orders")->where("buyer_user_id", $buyer->id)
        ->where("recipient_name", "${checkoutRecipient}")->count(),
      "original_order" => $original ? ["id" => $original->id, "channel" => $original->sales_channel,
        "source" => $original->order_source, "status" => $original->order_status,
        "payment_status" => $original->payment_status,
        "voucher_code" => $original->sales_voucher_code_snapshot,
        "redemption_count" => DB::table("sales_voucher_redemptions")
          ->where("sales_order_id", $original->id)->count(),
        "key_persisted" => $originalCart?->checkout_operation_key !== null,
        "fingerprint_persisted" => $originalCart?->checkout_payload_fingerprint !== null] : null,
      "active_cart" => $activeCart ? ["id" => $activeCart->id,
        "item_count" => DB::table("cart_items")->where("cart_id", $activeCart->id)->count(),
        "voucher_code" => $activeCart->voucher_code] : null,
      "available_stock" => $availableStock ? (float) $availableStock->on_hand_quantity - (float) $availableStock->reserved_quantity : 0,
      "stock" => DB::table("inventory_balances")->where("product_variant_id", $variant->id)
        ->orderBy("warehouse_id")->get(["warehouse_id", "on_hand_quantity", "reserved_quantity"]),
      "voucher_redemption_count" => DB::table("sales_voucher_redemptions")
        ->where("buyer_user_id", $buyer->id)->count(),
      "wallet_balance" => $wallet?->balance,
      "wallet_ledger_count" => $wallet ? DB::table("dealer_wallet_transactions")
        ->where("dealer_wallet_id", $wallet->id)->count() : null,
      "order" => $order ? ["id" => $order->id, "buyer_id" => $order->buyer_user_id,
        "channel" => $order->sales_channel, "source" => $order->order_source,
        "status" => $order->order_status, "payment_method" => $order->payment_method,
        "payment_status" => $order->payment_status, "warehouse_id" => $order->warehouse_id,
        "subtotal" => $order->subtotal, "discount_total" => $order->discount_total,
        "grand_total" => $order->grand_total,
        "voucher_code" => $order->sales_voucher_code_snapshot,
        "items" => DB::table("sales_order_items")->where("sales_order_id", $order->id)
          ->get(["sku_snapshot", "quantity", "unit_price_snapshot", "line_total", "discount_amount"]),
        "reservations" => DB::table("inventory_reservations")->where("sales_order_id", $order->id)
          ->get(["product_variant_id", "warehouse_id", "original_quantity", "status"]),
        "redemptions" => DB::table("sales_voucher_redemptions")
          ->where("sales_order_id", $order->id)->get(["sales_voucher_id", "discount_amount", "status"]),
        "payment_allocations" => DB::table("payment_allocations")
          ->where("sales_order_id", $order->id)->count(),
        "converted_cart_count" => DB::table("carts")
          ->where("converted_sales_order_id", $order->id)->count()] : null];
  `);
}

function financialState() {
  return qaSnapshot('FINANCE_8IC', `
    $request = DB::table("dealer_wallet_deposit_requests")->where("id", 2)->first();
    $wallet = $request ? DB::table("dealer_wallets")->where("id", $request->dealer_wallet_id)->first() : null;
    $sales = DB::table("sales_orders")->where("id", 45)->first();
    $return = DB::table("sales_returns")->where("id", 3)->first();
    $result = ["deposit" => $request ? ["id" => $request->id, "status" => $request->status,
      "amount" => $request->amount, "transaction_id" => $request->dealer_wallet_transaction_id,
      "wallet_balance" => $wallet?->balance,
      "wallet_ledger_count" => DB::table("dealer_wallet_transactions")
        ->where("dealer_wallet_id", $wallet?->id)->count()] : null,
      "sales" => $sales ? ["id" => $sales->id, "status" => $sales->order_status,
        "fulfillment_status" => $sales->fulfillment_status,
        "payment_method" => $sales->payment_method,
        "payment_status" => $sales->payment_status, "total" => $sales->grand_total,
        "settled_allocations" => DB::table("payment_allocations as a")
          ->join("payments as p", "p.id", "=", "a.payment_id")
          ->where("a.sales_order_id", $sales->id)->where("p.status", "settled")->count(),
        "refund_count" => DB::table("refunds")->where("sales_order_id", $sales->id)->count()] : null,
      "return" => $return ? ["id" => $return->id, "order_id" => $return->sales_order_id,
        "status" => $return->status, "items" => DB::table("sales_return_items")
          ->where("sales_return_id", $return->id)->get(["quantity", "restock_quantity", "return_value_snapshot"]),
        "refund_count" => DB::table("refunds")->where("sales_return_id", $return->id)->count()] : null];
  `);
}

function responsiveDraftState() {
  return qaSnapshot('DRAFT_8IC', `
    $order = DB::table("sales_orders")->where("recipient_name", "QA 8IC Responsive Draft")
      ->where("order_source", "admin")->orderByDesc("id")->first();
    $result = ["fixture_count" => DB::table("sales_orders")
      ->where("recipient_name", "QA 8IC Responsive Draft")->where("order_source", "admin")->count(),
      "order_count" => DB::table("sales_orders")->count(),
      "order" => $order ? ["id" => $order->id, "status" => $order->order_status,
        "channel" => $order->sales_channel, "source" => $order->order_source,
        "total" => $order->grand_total, "payment_status" => $order->payment_status,
        "item_count" => DB::table("sales_order_items")->where("sales_order_id", $order->id)->count(),
        "reservation_count" => DB::table("inventory_reservations")
          ->where("sales_order_id", $order->id)->count(),
        "allocation_count" => DB::table("payment_allocations")
          ->where("sales_order_id", $order->id)->count()] : null];
  `);
}

function collectIssues(page) {
  const issues = { console: [], javascript: [], network: [] };
  browserIssues.set(page, issues);
  page.on('console', (message) => { if (message.type() === 'error') issues.console.push(message.text()); });
  page.on('pageerror', (error) => issues.javascript.push(error.message));
  page.on('requestfailed', (request) => issues.network.push({
    path: new URL(request.url()).pathname, reason: request.failure()?.errorText,
  }));
  page.on('response', (response) => { if (response.status() >= 400) issues.network.push({
    path: new URL(response.url()).pathname, status: response.status(),
  }); });
  return issues;
}

async function visit(page, path) {
  const response = await page.goto(path);
  expect(response?.status(), path).toBeLessThan(400);
  await expect(page.locator('main')).toBeVisible();
}

async function attachMaskedScreenshot(page, testInfo, name) {
  const path = new URL(page.url()).pathname;
  const mask = [page.locator('input:not([type=hidden]), textarea'), page.locator('table tbody, dl dd')];
  if (/^\/(?:account\/profile|my-orders|dealer\/(?:addresses|wallet|orders)|admin\/dealer-wallet)/.test(path)) {
    mask.push(page.locator('main'));
  }
  await testInfo.attach(name, { body: await page.screenshot({ fullPage: false,
    mask,
  }), contentType: 'image/png' });
}

test.beforeEach(async ({ page }) => { collectIssues(page); });
test.afterEach(async ({ page }, testInfo) => {
  const issues = browserIssues.get(page);
  await testInfo.attach('console-javascript-network.json', {
    body: Buffer.from(JSON.stringify(issues, null, 2)), contentType: 'application/json',
  });
  if (!page.isClosed()) await attachMaskedScreenshot(page, testInfo, 'final-masked.png');
});

test.describe('Phase 8I-C financial evidence', () => {
  test.use({ storageState: resolve(stateDirectory, 'admin.json') });
  test('financial prerequisites remain unmodified', async ({ page }, testInfo) => {
    const before = financialState();
    expect(before.deposit).toMatchObject({ id: 2, status: 'pending', amount: '1000.00', transaction_id: null });
    expect(before.sales).toMatchObject({ id: 45, status: 'delivered', payment_status: 'unpaid', total: '560000.00', settled_allocations: 0 });
    expect(before.return).toMatchObject({ id: 3, order_id: 45, status: 'requested', refund_count: 0 });
    await visit(page, '/admin/dealer-wallet-deposit-requests/2');
    await attachMaskedScreenshot(page, testInfo, 'deposit-review-masked.png');
    await visit(page, '/admin/sales/orders/45');
    await attachMaskedScreenshot(page, testInfo, 'unpaid-sales-masked.png');
    await visit(page, '/admin/sales/returns/3');
    await attachMaskedScreenshot(page, testInfo, 'return-review-masked.png');
    expect(financialState()).toEqual(before);
    expect(browserIssues.get(page)).toEqual({ console: [], javascript: [], network: [] });
    await testInfo.attach('financial-prerequisites.json', {
      body: Buffer.from(JSON.stringify({ before, after: financialState(), approvals: 0,
        settlements: 0, refunds: 0 }, null, 2)), contentType: 'application/json',
    });
  });

});

test.describe('Phase 8I-C Retail Checkout', () => {
  test.use({ storageState: resolve(stateDirectory, 'retail.json') });

  test('03 exact Retail Checkout replay creates one QA order', async ({ page }, testInfo) => {
    const baseline = qaState();
    expect(baseline.original_order).toMatchObject({ id: 42, channel: 'retail',
      key_persisted: true, fingerprint_persisted: true });
    if (baseline.fixture_order_count === 1) {
      const archivedRequest = JSON.parse(readFileSync(checkoutRequestFile, 'utf8'));
      const entries = archivedRequest.entries;
      expect(entries).toHaveLength(14);
      expect(entries.some(([name]) => name === '_token')).toBe(false);
      const operationKey = entries.find(([name]) => name === 'checkout_operation_key')?.[1];
      const reviewFingerprint = entries.find(([name]) => name === 'checkout_review_fingerprint')?.[1];
      expect(operationKey).toMatch(/^[0-9a-f-]{36}$/i);
      expect(reviewFingerprint).toMatch(/^[a-f0-9]{64}$/);
      const beforeReplay = qaState(operationKey);
      expect(beforeReplay.operation_order_count).toBe(1);
      expect(beforeReplay.order).toMatchObject({ id: baseline.order.id, status: 'pending',
        payment_method: 'cod', payment_status: 'unpaid', payment_allocations: 0,
        converted_cart_count: 1 });
      expect(beforeReplay.order.items).toHaveLength(1);
      expect(beforeReplay.order.reservations).toHaveLength(0);
      await visit(page, `/my-orders/${beforeReplay.order.id}`);
      const csrf = await page.locator('form[action*="logout"] input[name=_token]').inputValue();
      const replayEntries = [['_token', csrf], ...entries];
      const replay = await page.evaluate(async ({ entries }) => {
        const response = await fetch('/checkout', { method: 'POST', credentials: 'same-origin',
          headers: { Accept: 'text/html' }, body: new URLSearchParams(entries) });
        return { status: response.status, path: new URL(response.url).pathname };
      }, { entries: replayEntries });
      expect(replay).toEqual({ status: 200, path: `/my-orders/${beforeReplay.order.id}` });
      const afterReplay = qaState(operationKey);
      expect(afterReplay).toEqual(beforeReplay);
      expect(browserIssues.get(page)).toEqual({ console: [], javascript: [], network: [] });
      await testInfo.attach('checkout-replay-reconciliation.json', {
        body: Buffer.from(JSON.stringify({ original_42: baseline.original_order,
          source: archivedRequest.source,
          validated_payload_digest: createHash('sha256').update(JSON.stringify(entries)).digest('hex'),
          csrf_transport_refreshed_for_new_login_session: true,
          operation_key_matches_persisted_order: true,
          before_replay: beforeReplay, replay, after_replay: afterReplay }, null, 2)),
        contentType: 'application/json',
      });
      return;
    }
    expect(baseline.fixture_order_count, 'Do not create another 8I-C QA order on rerun').toBe(0);
    expect(baseline.active_cart?.item_count ?? 0, 'Do not replace unrelated Cart items').toBe(0);
    expect(baseline.available_stock, 'QA default warehouse must have one available demo SKU').toBeGreaterThanOrEqual(1);
    await visit(page, '/shop/demo-product-01');
    const form = page.locator('form:has(select[name=product_variant_id])');
    const option = form.locator('select[name=product_variant_id] option').filter({ hasText: 'DEMO-SKU-01-1' });
    await form.locator('select[name=product_variant_id]').selectOption(String(await option.getAttribute('value')));
    await form.locator('input[name=quantity]').fill('1');
    await form.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/cart$/);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    await attachMaskedScreenshot(page, testInfo, 'cart-before-checkout-masked.png');
    const withCart = qaState();
    expect(withCart.retail_order_count).toBe(baseline.retail_order_count);
    await page.reload();
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    await visit(page, '/checkout');
    const beforeSubmit = qaState();
    expect(beforeSubmit.retail_order_count).toBe(baseline.retail_order_count);
    expect(beforeSubmit.voucher_redemption_count).toBe(baseline.voucher_redemption_count);
    expect(beforeSubmit.stock).toEqual(baseline.stock);
    await attachMaskedScreenshot(page, testInfo, 'checkout-preview-masked.png');
    const checkoutForm = page.locator('form[action$="/checkout"]');
    await checkoutForm.locator('input[name=recipient_name]').fill(checkoutRecipient);
    await checkoutForm.locator('input[name=recipient_phone]').fill('0900000001');
    await checkoutForm.locator('input[name=recipient_email]').fill('retail1@demo.local');
    await checkoutForm.locator('input[name=shipping_address_line1]').fill('QA 8IC Street');
    await checkoutForm.locator('input[name=shipping_district]').fill('QA District');
    await checkoutForm.locator('input[name=shipping_city]').fill('Ho Chi Minh');
    await checkoutForm.locator('input[name=shipping_province]').fill('Ho Chi Minh');
    await checkoutForm.locator('select[name=payment_method]').selectOption('cod');
    const request = await checkoutForm.evaluate((element) => ({
      action: element.action, entries: [...new FormData(element).entries()],
    }));
    const operationKey = request.entries.find(([name]) => name === 'checkout_operation_key')?.[1];
    const reviewFingerprint = request.entries.find(([name]) => name === 'checkout_review_fingerprint')?.[1];
    expect(operationKey).toMatch(/^[0-9a-f-]{36}$/i);
    expect(reviewFingerprint).toMatch(/^[a-f0-9]{64}$/);
    const requestDigest = createHash('sha256').update(JSON.stringify(request.entries)).digest('hex');
    writeFileSync(checkoutRequestFile, JSON.stringify({ source: 'captured browser form before first submit',
      entries: request.entries.filter(([name]) => name !== '_token') }, null, 2));
    page.once('dialog', (dialog) => dialog.accept());
    await checkoutForm.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/my-orders\/\d+$/);
    const afterFirst = qaState(operationKey);
    expect(afterFirst.retail_order_count).toBe(beforeSubmit.retail_order_count + 1);
    expect(afterFirst.operation_order_count).toBe(1);
    expect(afterFirst.fixture_order_count).toBe(1);
    expect(afterFirst.order).toMatchObject({ buyer_id: baseline.buyer_id, channel: 'retail',
      source: 'cart', status: 'pending', payment_method: 'cod', payment_status: 'unpaid', payment_allocations: 0,
      converted_cart_count: 1 });
    expect(afterFirst.order.items).toHaveLength(1);
    expect(afterFirst.order.items[0].sku_snapshot).toBe('DEMO-SKU-01-1');
    expect(Number(afterFirst.order.items[0].quantity)).toBe(1);
    expect(Number(afterFirst.order.grand_total)).toBeGreaterThan(0);
    expect(afterFirst.order.reservations).toHaveLength(0);
    expect(afterFirst.stock.map((row) => row.on_hand_quantity))
      .toEqual(beforeSubmit.stock.map((row) => row.on_hand_quantity));
    expect(afterFirst.wallet_balance).toBe(baseline.wallet_balance);
    expect(afterFirst.wallet_ledger_count).toBe(baseline.wallet_ledger_count);
    await attachMaskedScreenshot(page, testInfo, 'checkout-result-masked.png');
    const replay = await page.evaluate(async ({ action, entries }) => {
      const response = await fetch(action, { method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'text/html' }, body: new URLSearchParams(entries) });
      return { status: response.status, path: new URL(response.url).pathname };
    }, request);
    expect(replay).toEqual({ status: 200, path: `/my-orders/${afterFirst.order.id}` });
    const afterReplay = qaState(operationKey);
    expect(afterReplay).toEqual(afterFirst);
    expect(browserIssues.get(page)).toEqual({ console: [], javascript: [], network: [] });
    await testInfo.attach('checkout-replay-reconciliation.json', {
      body: Buffer.from(JSON.stringify({ original_42: baseline.original_order,
        request_digest: requestDigest, exact_form_entries_replayed: true,
        operation_key_matches_persisted_order: afterReplay.operation_order_count === 1,
        before: baseline, preview: beforeSubmit, after_first: afterFirst,
        replay, after_replay: afterReplay }, null, 2)), contentType: 'application/json',
    });
  });
});

test.describe('Phase 8I-C responsive interactions', () => {
  test('14 Admin, Retail and Dealer controls at 1440, 768 and 390 pixels', async ({ browser }, testInfo) => {
    testInfo.setTimeout(300_000);
    const before = qaState();
    expect(before.active_cart?.item_count ?? 0, 'Responsive QA must start with an empty Retail cart').toBe(0);
    const records = [];
    const temporaryCart = await browser.newContext({ baseURL: 'http://127.0.0.1:8001',
      storageState: resolve(stateDirectory, 'retail.json'), viewport: { width: 1440, height: 900 } });
    try {
      const cartPage = await temporaryCart.newPage();
      await visit(cartPage, '/shop/demo-product-01');
      const addForm = cartPage.locator('form:has(select[name=product_variant_id])');
      const option = addForm.locator('select[name=product_variant_id] option').filter({ hasText: 'DEMO-SKU-01-1' });
      await addForm.locator('select[name=product_variant_id]').selectOption(String(await option.getAttribute('value')));
      await addForm.locator('input[name=quantity]').fill('1');
      await addForm.locator('button[type=submit]').click();
      await expect(cartPage).toHaveURL(/\/cart$/);
      expect(qaState().retail_order_count).toBe(before.retail_order_count);

      for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 },
        { width: 390, height: 844 }]) {
        for (const [role, paths] of [
          ['admin', ['/admin', '/admin/products', '/admin/products/17', '/admin/sales/orders/45',
            '/admin/sales/orders/create', '/admin/inventory', '/admin/purchase-orders/10',
            '/admin/customers', '/admin/reports', '/admin/sales/returns/3']],
          ['retail', ['/shop', '/cart', '/checkout', '/account/profile', '/dealer/apply',
            '/my-orders/45/returns/3']],
          ['dealer', ['/dealer/products', '/dealer/products/demo-product-01', '/dealer/quick-order',
            '/dealer/addresses', '/dealer/wallet', '/dealer/orders/43']],
        ]) {
          const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8001',
            storageState: resolve(stateDirectory, `${role}.json`), viewport });
          try {
            const page = await context.newPage();
            const issues = collectIssues(page);
            for (const path of paths) {
              await visit(page, path);
              const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
              expect(overflow, `${viewport.width}px ${path} document overflow`).toBeLessThanOrEqual(1);
              await expect(page.locator('main')).toBeInViewport();
              const record = { width: viewport.width, role, path, overflow, interaction: 'visible' };
              if (path === '/admin' && viewport.width < 1024) {
                await page.getByRole('button', { name: 'Mở điều hướng' }).click();
                await expect(page.locator('[data-admin-sidebar]')).toBeInViewport();
                await page.keyboard.press('Escape');
                await expect(page.locator('[data-admin-sidebar]')).not.toBeInViewport();
                await page.getByRole('button', { name: 'Mở điều hướng' }).click();
                await page.locator('[data-admin-sidebar] nav a[href$="/admin/reports"]').click();
                await expect(page).toHaveURL(/\/admin\/reports$/);
                record.interaction = 'mobile sidebar open, Escape close and report navigation';
              } else if (path === '/admin/products') {
                await page.locator('form[aria-label="Tìm và lọc sản phẩm"] input[name=search]').fill('DEMO-PRD-01');
                await page.locator('form[aria-label="Tìm và lọc sản phẩm"] button[type=submit]').click();
                await expect(page).toHaveURL(/search=DEMO-PRD-01/);
                record.interaction = 'search and filter submit';
              } else if (path === '/admin/customers') {
                await page.locator('main form[action$="/admin/customers"] input[name=search]').fill('Retail Demo');
                await page.locator('main form[action$="/admin/customers"] button[type=submit]').click();
                await expect(page).toHaveURL(/search=Retail/);
                record.interaction = 'customer search submit';
              } else if (path === '/admin/reports') {
                await page.locator('form[aria-label="Lọc kỳ báo cáo"] select[name=channel]').selectOption('retail');
                await page.locator('form[aria-label="Lọc kỳ báo cáo"] button[type=submit]').click();
                await expect(page).toHaveURL(/channel=retail/);
                record.interaction = 'report select and filter submit';
              } else if (path === '/admin/inventory') {
                const search = page.locator('main form input[name=search]').first();
                if (await search.count()) {
                  await search.fill('DEMO-SKU-01-1');
                  await expect(search).toBeFocused();
                  record.interaction = 'inventory search accepts keyboard input';
                }
              } else if (viewport.width === 390 && ['/admin/purchase-orders/10',
                '/admin/sales/returns/3', '/admin/sales/orders/45'].includes(path)) {
                const scrollArea = page.locator('main .overflow-x-auto').filter({ has: page.locator('table') }).first();
                const scroll = await scrollArea.evaluate((element) => {
                  const available = element.scrollWidth - element.clientWidth;
                  element.scrollLeft = available;
                  return { available, reached: element.scrollLeft };
                });
                expect(scroll.available).toBeGreaterThan(0);
                expect(scroll.reached).toBeGreaterThan(0);
                record.interaction = 'wide table scrolls to final columns within viewport';
              } else if (path === '/shop') {
                await page.locator('#shop-search').fill('DEMO-SKU-01-1');
                await page.locator('#shop-sort').selectOption('name');
                await page.locator('form[action$="/shop"] button[type=submit]').click();
                await expect(page).toHaveURL(/search=DEMO-SKU-01-1/);
                record.interaction = 'shop search, sort and submit';
              } else if (path === '/checkout') {
                const address = page.locator('input[name=shipping_address_line1]');
                await address.fill('QA responsive address');
                await expect(address).toHaveValue('QA responsive address');
                await address.focus();
                await expect(address).toBeFocused();
                const form = page.locator('form[action$="/checkout"]');
                expect(await form.evaluate((element) => element.checkValidity())).toBe(false);
                record.interaction = 'checkout form input, focus and required validation';
              } else if (path === '/account/profile') {
                const phone = page.locator('#profile-phone');
                await phone.fill('0900000001');
                await phone.focus();
                await expect(phone).toBeFocused();
                record.interaction = 'profile form input and focus';
              } else if (path === '/dealer/apply') {
                const company = page.locator('#company_name');
                if (await company.count()) {
                  await company.fill('QA responsive input only');
                  await expect(company).toBeFocused();
                  record.interaction = 'dealer application accepts input without submit';
                }
              } else if (path === '/dealer/products') {
                await page.locator('form[action$="/dealer/products"] input[name=search]').fill('DEMO-SKU-01-1');
                await page.locator('form[action$="/dealer/products"] button').click();
                await expect(page).toHaveURL(/search=DEMO-SKU-01-1/);
                record.interaction = 'dealer catalog search submit';
              } else if (path === '/dealer/quick-order') {
                const search = page.locator('#dealer-sku-search');
                await search.fill('DEMO-SKU-01-1');
                await expect(search).toBeFocused();
                await expect(page.locator('main button[type=button]').filter({ hasText: 'DEMO-SKU-01-1' }).first()).toBeVisible();
                record.interaction = 'async SKU search results usable';
              } else if (path === '/dealer/wallet') {
                const amount = page.locator('form input[name=amount]');
                await amount.fill('1000');
                await expect(amount).toHaveValue('1000');
                record.interaction = 'deposit form amount accepts input without submit';
              } else if (path === '/dealer/addresses') {
                await page.getByRole('button', { name: 'Xóa', exact: true }).first().click();
                const dialog = page.getByRole('dialog', { name: 'Xóa địa chỉ giao hàng' });
                await expect(dialog).toBeVisible();
                await dialog.getByRole('button', { name: 'Đóng' }).click();
                await expect(dialog).not.toBeVisible();
                record.interaction = 'delete confirmation opens and closes without write';
              }
              const safeName = `${role}-${viewport.width}-${path.replace(/[^a-z0-9]+/gi, '-')}.png`;
              await attachMaskedScreenshot(page, testInfo, safeName);
              records.push(record);
            }
            if (role === 'dealer' && viewport.width < 1024) {
              await page.getByRole('button', { name: 'Mở điều hướng' }).click();
              await expect(page.locator('aside[aria-label="Điều hướng chính"]')).toBeInViewport();
              await page.locator('aside[aria-label="Điều hướng chính"] a[href$="/dealer/wallet"]').click();
              await expect(page).toHaveURL(/\/dealer\/wallet$/);
              await page.getByRole('button', { name: 'Mở điều hướng' }).click();
              await page.getByRole('button', { name: 'Đóng điều hướng' }).click();
              records.push({ width: viewport.width, role, path: 'sidebar', interaction: 'open, navigate and close' });
            }
            expect(issues, `${role} ${viewport.width}px browser issues`).toEqual({
              console: [], javascript: [], network: [],
            });
          } finally {
            await context.close();
          }
        }
      }
    } finally {
      const cleanupPage = await temporaryCart.newPage();
      try {
        await visit(cleanupPage, '/cart');
        const remove = cleanupPage.locator('form[action*="/cart/items/"]:has(input[name=_method][value=DELETE]) button[type=submit]').first();
        if (await remove.count()) {
          await remove.click();
          await expect(cleanupPage.locator('main')).not.toContainText('DEMO-SKU-01-1');
        }
      } finally {
        await temporaryCart.close();
      }
    }
    const after = qaState();
    expect(after.retail_order_count).toBe(before.retail_order_count);
    expect(after.active_cart?.item_count ?? 0).toBe(0);
    expect(after.stock).toEqual(before.stock);
    expect(after.voucher_redemption_count).toBe(before.voucher_redemption_count);
    expect(after.wallet_balance).toBe(before.wallet_balance);
    await testInfo.attach('responsive-interactions.json', {
      body: Buffer.from(JSON.stringify({ viewport_count: 3, pages: records,
        cart_cleanup: true, order_count_unchanged: true, stock_unchanged: true }, null, 2)),
      contentType: 'application/json',
    });
  });
});

test.describe('Phase 8I-C draft and dialogs', () => {
  test.use({ storageState: resolve(stateDirectory, 'admin.json') });

  test('14 Sales draft edit, form validation, dialogs and image at all viewports', async ({ page, browser }, testInfo) => {
    testInfo.setTimeout(180_000);
    const before = responsiveDraftState();
    expect(before.fixture_count).toBeLessThanOrEqual(1);
    if (!before.order) {
      await visit(page, '/admin/sales/orders/create');
      await page.locator('#buyer-search').fill('retail1@demo.local');
      await page.getByRole('button', { name: /retail1@demo\.local/ }).click();
      await page.locator('#sku-search').fill('DEMO-SKU-01-1');
      await page.getByRole('button', { name: /DEMO-SKU-01-1/ }).last().click();
      await page.locator('input[name="items[0][quantity]"]').fill('1');
      await page.locator('#recipient_name').fill('QA 8IC Responsive Draft');
      await page.locator('#recipient_phone').fill('0900000001');
      await page.locator('#recipient_email').fill('retail1@demo.local');
      await page.locator('#shipping_address_line1').fill('QA 8IC Draft Street');
      await page.locator('#shipping_city').fill('Ho Chi Minh');
      await page.locator('#shipping_province').fill('Ho Chi Minh');
      await page.locator('#shipping_country').fill('VN');
      await page.locator('select[name=payment_method]').selectOption('cod');
      await page.locator('textarea[name=delivery_note]').fill('QA 8IC responsive draft fixture');
      await page.getByRole('button', { name: 'Lưu đơn nháp' }).click();
      await expect(page).toHaveURL(/\/admin\/sales\/orders\/\d+$/);
    }
    const created = responsiveDraftState();
    expect(created.fixture_count).toBe(1);
    expect(created.order).toMatchObject({ status: 'draft', channel: 'retail', source: 'admin',
      payment_status: 'unpaid', item_count: 1, reservation_count: 0, allocation_count: 0 });
    expect(created.order_count).toBe(before.order_count + (before.order ? 0 : 1));
    const records = [];
    for (const viewport of [{ width: 1440, height: 900 }, { width: 768, height: 1024 },
      { width: 390, height: 844 }]) {
      const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8001', viewport,
        storageState: resolve(stateDirectory, 'admin.json') });
      try {
        const adminPage = await context.newPage();
        const issues = collectIssues(adminPage);
        await visit(adminPage, `/admin/sales/orders/${created.order.id}/edit`);
        expect(await adminPage.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        const recipient = adminPage.locator('#recipient_name');
        await recipient.fill('');
        await adminPage.getByRole('button', { name: 'Lưu thay đổi' }).click();
        expect(await recipient.evaluate((element) => element.validity.valueMissing)).toBe(true);
        await expect(recipient).toBeFocused();
        await attachMaskedScreenshot(adminPage, testInfo, `sales-draft-validation-${viewport.width}.png`);
        await visit(adminPage, `/admin/sales/orders/${created.order.id}`);
        await expect(adminPage.locator('main')).toContainText('QA 8IC Responsive Draft');
        await visit(adminPage, '/admin/sales/orders/45');
        await adminPage.getByRole('button', { name: 'Ghi nhận đã nhận tiền' }).click();
        await expect(adminPage.getByRole('dialog', { name: 'Xác nhận tiền đã nhận?' })).toBeVisible();
        await adminPage.keyboard.press('Escape');
        await expect(adminPage.getByRole('dialog', { name: 'Xác nhận tiền đã nhận?' })).not.toBeVisible();
        await attachMaskedScreenshot(adminPage, testInfo, `sales-dialog-closed-${viewport.width}.png`);
        expect(issues).toEqual({ console: [], javascript: [], network: [] });
        records.push({ width: viewport.width, draft_edit_validation: true, payment_dialog_open_close: true,
          overflow: 0, browser_issues: issues });
      } finally {
        await context.close();
      }
      const retail = await browser.newContext({ baseURL: 'http://127.0.0.1:8001', viewport,
        storageState: resolve(stateDirectory, 'retail.json') });
      try {
        const retailPage = await retail.newPage();
        const issues = collectIssues(retailPage);
        await visit(retailPage, '/shop/demo-product-01');
        const image = retailPage.locator('main img').first();
        await expect(image).toBeVisible();
        await expect.poll(() => image.evaluate((element) => element.complete && element.naturalWidth > 0)).toBe(true);
        expect(await retailPage.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
        expect(issues).toEqual({ console: [], javascript: [], network: [] });
        records.at(-1).retail_product_image_loaded = true;
      } finally {
        await retail.close();
      }
    }
    expect(responsiveDraftState()).toEqual(created);
    expect(financialState().sales.payment_status).toBe('unpaid');
    await testInfo.attach('draft-dialog-image-reconciliation.json', {
      body: Buffer.from(JSON.stringify({ before, created, after: responsiveDraftState(),
        records, settlement_performed: false }, null, 2)), contentType: 'application/json',
    });
  });
});
