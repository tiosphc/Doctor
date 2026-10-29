import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const backendDirectory = resolve(import.meta.dirname, '../..');
const stateDirectory = resolve(backendDirectory, '../.codex/browser-qa/state');
const issuesByPage = new WeakMap();
const quickOrderKey = '8f848a6f-5d12-4bd6-9c1b-88b120d94a9c';
const depositReference = 'QA-8IB-DEPOSIT-20261011';
const salesDraftKey = '0bde8f21-a8bf-4f35-9e20-7cb89182c819';
const salesReturnKey = '3b2f4b9a-4d45-49da-9e2e-76eea54d2668';
const productBaseSku = 'QA-8IB-PROD';
const untrackedBaseSku = 'QA-8IB-UNTRACKED';
const purchaseNote = 'QA-8IB-PROCUREMENT';
const qaProof = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');

function qaPassword() {
  const content = readFileSync(resolve(backendDirectory, '.env.blade_qa'), 'utf8');
  const entry = content.split(/\r?\n/).find((line) => line.startsWith('DEMO_ADMIN_PASSWORD='));
  const value = entry?.slice('DEMO_ADMIN_PASSWORD='.length).trim();
  if (!value) throw new Error('QA password is not configured.');
  return value.length > 1 && value[0] === value.at(-1) && ["'", '"'].includes(value[0])
    ? value.slice(1, -1) : value;
}

function readQaSnapshot(label, body) {
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
  if (!line) {
    throw new Error(`QA ${label} snapshot returned no result.`);
  }
  return JSON.parse(line.slice(`QA_${label}=`.length));
}

function retailState() {
  return readQaSnapshot('RETAIL', `
    $user = DB::table("users")->where("email", "retail1@demo.local")->first();
    $cart = DB::table("carts")->where("user_id", $user->id)->where("context", "retail")->where("status", "active")->first();
    $product = DB::table("products")->where("product_code", "DEMO-PRD-01")->first();
    $result = ["order_count" => DB::table("sales_orders")->where("buyer_user_id", $user->id)->count(),
      "cart_id" => $cart?->id,
      "cart_item_count" => $cart ? DB::table("cart_items")->where("cart_id", $cart->id)->count() : 0,
      "category_id" => $product?->product_category_id, "brand_id" => $product?->brand_id];
  `);
}

function profileState() {
  return readQaSnapshot('PROFILE', `
    $user = DB::table("users")->where("email", "retail1@demo.local")->first();
    $customer = DB::table("customers")->where("user_id", $user->id)->first();
    $order = DB::table("sales_orders")->where("buyer_user_id", $user->id)
      ->where("recipient_name", "QA Browser Retail")->orderByDesc("id")->first();
    $result = ["user_id" => $user->id, "name" => $user->name, "phone" => $user->phone,
      "email" => $user->email, "role" => $user->role,
      "customer_name" => $customer?->name, "customer_phone" => $customer?->primary_phone,
      "order" => $order ? ["id" => $order->id, "recipient_name" => $order->recipient_name,
        "grand_total" => (string) $order->grand_total, "payment_status" => $order->payment_status] : null];
  `);
}

function reportState() {
  return readQaSnapshot('REPORT', `
    $payments = DB::table("payment_allocations as a")
      ->join("payments as p", "p.id", "=", "a.payment_id")
      ->join("sales_orders as o", "o.id", "=", "a.sales_order_id")
      ->where("p.status", "settled")->whereIn("o.sales_channel", ["retail", "dealer"])
      ->whereColumn("p.payment_context", "o.sales_channel")
      ->where("p.settled_at", ">=", "2026-01-01 00:00:00")
      ->where("p.settled_at", "<", "2027-01-01 00:00:00");
    $audit = DB::table("audit_logs")->whereNotNull("target_name")->orderByDesc("id")->first();
    $result = ["gross_settled_allocations" => (string) $payments->sum("a.allocated_amount"),
      "audit_count" => DB::table("audit_logs")->count(),
      "audit" => $audit ? ["id" => $audit->id, "module" => $audit->module,
        "action" => $audit->action, "actor_id" => $audit->actor_id,
        "target_name" => $audit->target_name] : null];
  `);
}

function proofState() {
  return readQaSnapshot('PROOF', `
    $record = DB::table("dealer_wallet_deposit_requests")
      ->where("transaction_reference", "QA-PROOF-001")->first();
    $result = ["request_id" => $record?->id, "dealer_account_id" => $record?->dealer_account_id,
      "status" => $record?->status, "proof_exists" => $record
        ? Storage::disk("local")->exists($record->payment_proof_path) : false];
  `);
}

function dealerCatalogAndAddressState() {
  return readQaSnapshot('DEALER_ADDRESS', `
    $user = App\\Models\\User::query()->where("email", "dealer1@demo.local")->firstOrFail();
    $account = app(App\\Services\\DealerContextService::class)->primaryAccount($user);
    $address = DB::table("dealer_shipping_addresses")
      ->where("recipient_name", "QA Browser Address 20261011")->first();
    $variant = App\\Models\\ProductVariant::query()->where("sku", "DEMO-SKU-01-1")->firstOrFail();
    $pricing = app(App\\Services\\DealerEffectivePricingService::class);
    $context = $pricing->context($user, $account);
    $price = $pricing->catalogPrices($context, collect([$variant]))[$variant->id] ?? null;
    $result = ["account_id" => $account?->id, "address_id" => $address?->id,
      "address_account_id" => $address?->dealer_account_id,
      "address_count" => DB::table("dealer_shipping_addresses")->where("dealer_account_id", $account?->id)->count(),
      "address_line" => $address?->address_line, "postal_code" => $address?->postal_code,
      "province_code" => $address?->province_code, "ward_code" => $address?->ward_code,
      "tier_name" => $context["tier"]->name, "variant_id" => $variant->id,
      "unit_price" => $price["unit_price"] ?? null, "moq" => $price["minimum_quantity"] ?? null,
      "untracked_sellable_count" => App\\Models\\ProductVariant::query()
        ->where("status", "active")->where("sellable_dealer", true)
        ->where("track_inventory", false)->count()];
  `);
}

function financialFixtureState() {
  return readQaSnapshot('FINANCIAL_FIXTURE', `
    $user = App\\Models\\User::query()->where("email", "dealer1@demo.local")->firstOrFail();
    $account = app(App\\Services\\DealerContextService::class)->primaryAccount($user);
    $variant = DB::table("product_variants")->where("sku", "DEMO-SKU-01-1")->first();
    $wallet = DB::table("dealer_wallets")->where("dealer_account_id", $account?->id)->first();
    $retailOrder = DB::table("sales_orders")->where("id", 42)->first();
    $quickOrder = DB::table("sales_orders")->where("creation_operation_key", "${quickOrderKey}")->first();
    $result = ["account_id" => $account?->id, "wallet_id" => $wallet?->id,
      "wallet_balance" => $wallet?->balance,
      "wallet_transaction_count" => $wallet ? DB::table("dealer_wallet_transactions")
        ->where("dealer_wallet_id", $wallet->id)->count() : 0,
      "dealer_order_count" => DB::table("sales_orders")->where("dealer_account_id", $account?->id)->count(),
      "dealer_import_count" => DB::table("dealer_order_imports")->where("dealer_account_id", $account?->id)->count(),
      "deposit_request_count" => DB::table("dealer_wallet_deposit_requests")
        ->where("dealer_account_id", $account?->id)->count(),
      "stock" => DB::table("inventory_balances")->where("product_variant_id", $variant?->id)
        ->orderBy("warehouse_id")->get(["warehouse_id", "on_hand_quantity", "reserved_quantity"]),
      "quick_order" => $quickOrder ? ["id" => $quickOrder->id, "dealer_account_id" => $quickOrder->dealer_account_id,
        "buyer_user_id" => $quickOrder->buyer_user_id, "status" => $quickOrder->order_status,
        "payment_status" => $quickOrder->payment_status, "warehouse_id" => $quickOrder->warehouse_id,
        "grand_total" => $quickOrder->grand_total,
        "items" => DB::table("sales_order_items")->where("sales_order_id", $quickOrder->id)
          ->get(["sku_snapshot", "quantity", "unit_price_snapshot", "minimum_quantity_snapshot", "is_gift"]),
        "reservations" => DB::table("inventory_reservations")->where("sales_order_id", $quickOrder->id)
          ->get(["product_variant_id", "warehouse_id", "original_quantity", "status"]),
        "wallet_transactions" => DB::table("dealer_wallet_transactions")->where("sales_order_id", $quickOrder->id)
          ->get(["direction", "amount", "balance_before", "balance_after"])] : null,
      "retail_order" => $retailOrder ? ["id" => $retailOrder->id,
        "status" => $retailOrder->order_status, "payment_status" => $retailOrder->payment_status,
        "payment_method" => $retailOrder->payment_method,
        "reservation_count" => DB::table("inventory_reservations")
          ->where("sales_order_id", $retailOrder->id)->count(),
        "payment_allocation_count" => DB::table("payment_allocations")
          ->where("sales_order_id", $retailOrder->id)->count()] : null];
  `);
}

function depositState() {
  return readQaSnapshot('DEPOSIT', `
    $user = App\\Models\\User::query()->where("email", "dealer1@demo.local")->firstOrFail();
    $account = app(App\\Services\\DealerContextService::class)->primaryAccount($user);
    $wallet = DB::table("dealer_wallets")->where("dealer_account_id", $account->id)->first();
    $request = DB::table("dealer_wallet_deposit_requests")
      ->where("transaction_reference", "${depositReference}")->first();
    $result = ["account_id" => $account->id, "wallet_id" => $wallet->id,
      "balance" => (string) $wallet->balance,
      "ledger_count" => DB::table("dealer_wallet_transactions")->where("dealer_wallet_id", $wallet->id)->count(),
      "other_wallets" => DB::table("dealer_wallets")->where("dealer_account_id", "!=", $account->id)
        ->orderBy("id")->get(["id", "balance"]),
      "request" => $request ? ["id" => $request->id, "account_id" => $request->dealer_account_id,
        "wallet_id" => $request->dealer_wallet_id, "amount" => (string) $request->amount,
        "status" => $request->status, "transaction_id" => $request->dealer_wallet_transaction_id,
        "proof_exists" => Storage::disk("local")->exists($request->payment_proof_path),
        "proof_path" => $request->payment_proof_path] : null];
  `);
}

function importState(kind) {
  if (!['valid', 'invalid'].includes(kind)) throw new Error('Unsupported QA import fixture.');
  return readQaSnapshot(`IMPORT_${kind.toUpperCase()}`, `
    $user = App\\Models\\User::query()->where("email", "dealer1@demo.local")->firstOrFail();
    $account = app(App\\Services\\DealerContextService::class)->primaryAccount($user);
    $import = DB::table("dealer_order_imports")
      ->where("dealer_account_id", $account->id)
      ->where("original_filename", "qa-dealer-import-${kind}.xlsx")
      ->orderByDesc("id")->first();
    $groups = $import ? DB::table("dealer_order_import_groups")
      ->where("dealer_order_import_id", $import->id)->orderBy("id")->get() : collect();
    $orderIds = $groups->pluck("sales_order_id")->filter()->values();
    $result = $import ? ["id" => $import->id, "account_id" => $import->dealer_account_id,
      "uploaded_by" => $import->uploaded_by, "status" => $import->status,
      "row_count" => $import->row_count, "order_count" => $import->order_count,
      "valid_order_count" => $import->valid_order_count,
      "invalid_order_count" => $import->invalid_order_count,
      "preview_fingerprint" => $import->preview_fingerprint,
      "preview_summary" => json_decode($import->preview_summary, true),
      "confirm_operation_key" => $import->confirm_operation_key,
      "groups" => $groups->map(fn ($group) => ["id" => $group->id, "status" => $group->status,
        "order_id" => $group->sales_order_id, "errors" => json_decode($group->preview, true)["errors"] ?? []]),
      "orders" => DB::table("sales_orders")->whereIn("id", $orderIds)
        ->get(["id", "dealer_account_id", "order_status", "payment_status", "warehouse_id", "grand_total"]),
      "items" => DB::table("sales_order_items")->whereIn("sales_order_id", $orderIds)
        ->get(["sales_order_id", "sku_snapshot", "quantity", "unit_price_snapshot", "minimum_quantity_snapshot"]),
      "reservations" => DB::table("inventory_reservations")->whereIn("sales_order_id", $orderIds)
        ->get(["sales_order_id", "warehouse_id", "product_variant_id", "original_quantity"]),
      "wallet_transactions" => DB::table("dealer_wallet_transactions")->whereIn("sales_order_id", $orderIds)
        ->get(["sales_order_id", "direction", "amount"])] : null;
  `);
}

function productAndProcurementState() {
  return readQaSnapshot('PRODUCT_PROCUREMENT', `
    $variant = DB::table("product_variants")->where("sku", "DEMO-SKU-01-1")->first();
    $result = ["products" => DB::table("products")->count(),
      "variants" => DB::table("product_variants")->count(),
      "purchase_orders" => DB::table("purchase_orders")->count(),
      "price_lists" => DB::table("price_lists")->count(),
      "demo_stock" => DB::table("inventory_balances")
        ->where("product_variant_id", $variant->id)->orderBy("warehouse_id")
        ->get(["warehouse_id", "on_hand_quantity", "reserved_quantity"])];
  `);
}

function productFixtureState() {
  return readQaSnapshot('PRODUCT_FIXTURE', `
    $product = DB::table("products")->where("base_sku", "${productBaseSku}")->first();
    $variant = $product ? DB::table("product_variants")->where("product_id", $product->id)->first() : null;
    $order = DB::table("purchase_orders")->where("note", "${purchaseNote}")->orderByDesc("id")->first();
    $orderItems = $order ? DB::table("purchase_order_items")->where("purchase_order_id", $order->id)
      ->get(["id", "product_variant_id", "ordered_quantity", "received_quantity", "unit_price"]) : collect();
    $receipts = $order ? DB::table("goods_receipts")->where("purchase_order_id", $order->id)
      ->orderBy("id")->get(["id", "operation_key"]) : collect();
    $result = ["product_count" => DB::table("products")->count(),
      "variant_count" => DB::table("product_variants")->count(),
      "product" => $product ? ["id" => $product->id, "code" => $product->product_code,
        "name" => $product->name, "category_id" => $product->product_category_id,
        "brand_id" => $product->brand_id, "status" => $product->status] : null,
      "variant" => $variant ? ["id" => $variant->id, "sku" => $variant->sku,
        "unit_id" => $variant->unit_id, "sellable_retail" => $variant->sellable_retail,
        "sellable_dealer" => $variant->sellable_dealer] : null,
      "prices" => $variant ? DB::table("price_list_items as item")
        ->join("price_lists as list", "list.id", "=", "item.price_list_id")
        ->where("item.product_variant_id", $variant->id)
        ->orderBy("list.pricing_context")->orderBy("list.id")
        ->get(["list.pricing_context", "list.scope_type", "item.unit_price", "item.minimum_quantity"]) : collect(),
      "order" => $order ? ["id" => $order->id, "supplier_id" => $order->supplier_id,
        "warehouse_id" => $order->warehouse_id, "status" => $order->status,
        "payment_status" => $order->payment_status,
        "items" => $orderItems,
        "receipts" => $receipts,
        "receipt_items" => DB::table("goods_receipt_items")
          ->whereIn("goods_receipt_id", $receipts->pluck("id"))
          ->get(["goods_receipt_id", "purchase_order_item_id", "quantity", "stock_movement_id"])] : null,
      "stock" => $variant ? DB::table("inventory_balances")
        ->where("product_variant_id", $variant->id)
        ->orderBy("warehouse_id")->get(["warehouse_id", "on_hand_quantity", "reserved_quantity"]) : collect()];
  `);
}

function untrackedFixtureState() {
  return readQaSnapshot('UNTRACKED_FIXTURE', `
    $product = DB::table("products")->where("base_sku", "${untrackedBaseSku}")->first();
    $variant = $product ? DB::table("product_variants")->where("product_id", $product->id)->first() : null;
    $result = ["product" => $product ? ["id" => $product->id, "slug" => $product->slug,
      "track_inventory" => $product->track_inventory] : null,
      "variant" => $variant ? ["id" => $variant->id, "sku" => $variant->sku,
        "track_inventory" => $variant->track_inventory, "sellable_dealer" => $variant->sellable_dealer] : null,
      "dealer_price" => $variant ? DB::table("price_list_items as item")
        ->join("price_lists as list", "list.id", "=", "item.price_list_id")
        ->where("item.product_variant_id", $variant->id)
        ->where("list.pricing_context", "dealer")->value("item.unit_price") : null];
  `);
}

function salesFixtureState() {
  return readQaSnapshot('SALES_FIXTURE', `
    $buyer = DB::table("users")->where("email", "retail1@demo.local")->first();
    $variant = DB::table("product_variants")->where("sku", "DEMO-SKU-01-1")->first();
    $order = DB::table("sales_orders")->where("creation_operation_key", "${salesDraftKey}")->first();
    $items = $order ? DB::table("sales_order_items")->where("sales_order_id", $order->id)
      ->get(["id", "sku_snapshot", "quantity", "unit_price_snapshot"]) : collect();
    $result = ["buyer_id" => $buyer->id, "variant_id" => $variant->id,
      "order_count" => DB::table("sales_orders")->count(),
      "stock" => DB::table("inventory_balances")->where("product_variant_id", $variant->id)
        ->orderBy("warehouse_id")->get(["warehouse_id", "on_hand_quantity", "reserved_quantity"]),
      "order" => $order ? ["id" => $order->id, "buyer_id" => $order->buyer_user_id,
        "source" => $order->order_source, "channel" => $order->sales_channel,
        "status" => $order->order_status, "fulfillment_status" => $order->fulfillment_status,
        "payment_status" => $order->payment_status, "warehouse_id" => $order->warehouse_id,
        "payment_method" => $order->payment_method, "grand_total" => $order->grand_total,
        "delivery_note" => $order->delivery_note,
        "items" => $items,
        "reservations" => DB::table("inventory_reservations")->where("sales_order_id", $order->id)
          ->get(["id", "sales_order_item_id", "original_quantity", "consumed_quantity", "released_quantity", "status"]),
        "movements" => DB::table("stock_movements")->where("reference_type", "SALES_ORDER_ITEM")
          ->whereIn("reference_id", $items->pluck("id")->map(fn ($id) => (string) $id))
          ->get(["id", "movement_type", "quantity"]),
        "allocations" => DB::table("payment_allocations")->where("sales_order_id", $order->id)
          ->get(["id", "allocated_amount"]),
        "history_count" => DB::table("sales_order_histories")->where("sales_order_id", $order->id)->count()] : null];
  `);
}

function salesReturnState() {
  return readQaSnapshot('SALES_RETURN', `
    $order = DB::table("sales_orders")->where("creation_operation_key", "${salesDraftKey}")->first();
    $return = DB::table("sales_returns")->where("operation_key", "${salesReturnKey}")->first();
    $result = ["order_id" => $order?->id, "payment_status" => $order?->payment_status,
      "allocation_count" => $order ? DB::table("payment_allocations")
        ->where("sales_order_id", $order->id)->count() : 0,
      "return_count" => $order ? DB::table("sales_returns")->where("sales_order_id", $order->id)->count() : 0,
      "refund_count" => $return ? DB::table("refunds")->where("sales_return_id", $return->id)->count() : 0,
      "return" => $return ? ["id" => $return->id, "order_id" => $return->sales_order_id,
        "status" => $return->status, "source" => $return->request_source,
        "requested_by" => $return->requested_by_user_id,
        "items" => DB::table("sales_return_items")->where("sales_return_id", $return->id)
          ->get(["sales_order_item_id", "quantity", "restock_quantity", "return_value_snapshot"])] : null];
  `);
}

async function visit(page, path) {
  const response = await page.goto(path);
  expect(response?.status(), `Browser response for ${path}`).toBeLessThan(400);
  await expect(page.locator('main')).toBeVisible();
}

function collectBrowserIssues(page, expectedStatuses = []) {
  const issues = { console: [], expectedConsole: [], javascript: [], network: [], expectedNetwork: [] };
  issuesByPage.set(page, issues);
  page.on('console', (message) => {
    if (message.type() === 'error') {
      const value = message.text();
      if (/^Failed to load resource: the server responded with a status of (403|404)/.test(value)
        && expectedStatuses.some(([, status]) => value.includes(`status of ${status}`))) {
        issues.expectedConsole.push(value);
      } else {
        issues.console.push(value);
      }
    }
  });
  page.on('pageerror', (error) => issues.javascript.push(error.message));
  page.on('requestfailed', (request) => {
    const item = { path: new URL(request.url()).pathname, reason: request.failure()?.errorText };
    if (item.path === '/dealer/orders/import/template' && item.reason === 'net::ERR_ABORTED') {
      issues.expectedNetwork.push(item);
    } else {
      issues.network.push(item);
    }
  });
  page.on('response', (response) => {
    if (response.status() >= 400) {
      const item = { path: new URL(response.url()).pathname, status: response.status() };
      if ((response.request().method() === 'POST' && item.path === '/cart/items' && item.status === 419)
        || expectedStatuses.some(([path, status]) => item.path === path && item.status === status)) {
        issues.expectedNetwork.push(item);
      } else {
        issues.network.push(item);
      }
    }
  });
  return issues;
}

test.beforeEach(async ({ page }) => {
  collectBrowserIssues(page);
});

test.afterEach(async ({ page }, testInfo) => {
  const issues = issuesByPage.get(page) ?? { console: [], javascript: [], network: [] };
  await testInfo.attach('console-javascript-network.json', {
    body: Buffer.from(JSON.stringify(issues, null, 2)), contentType: 'application/json',
  });
  if (!page.isClosed()) {
    const sensitive = /^\/(?:account\/profile|my-orders|dealer\/(?:addresses|quick-order|orders|wallet)|admin\/(?:audit-logs|customers|dealer-wallet|sales)|checkout|cart)/.test(new URL(page.url()).pathname);
    const screenshot = await page.screenshot({
      fullPage: false,
      mask: [page.locator('input:not([type=hidden]), textarea'), page.locator('table tbody, dl dd'),
        ...(sensitive ? [page.locator('main')] : [])],
    });
    await testInfo.attach('masked-screenshot.png', { body: screenshot, contentType: 'image/png' });
  }
});

test.describe('Phase 8I-B Retail', () => {
  test.use({ storageState: resolve(stateDirectory, 'retail.json') });

  test('02 Retail Shop newest/name, filter reset, pagination and image', async ({ page }, testInfo) => {
    const before = retailState();
    expect(before.category_id).toBeTruthy();
    expect(before.brand_id).toBeTruthy();

    await visit(page, '/shop');
    await page.locator('#shop-category').selectOption(String(before.category_id));
    await page.locator('#shop-brand').selectOption(String(before.brand_id));
    await page.locator('#shop-sort').selectOption('name');
    await page.getByRole('button', { name: 'Áp dụng' }).click();
    expect(new URL(page.url()).searchParams.get('category')).toBe(String(before.category_id));
    expect(new URL(page.url()).searchParams.get('brand')).toBe(String(before.brand_id));
    expect(new URL(page.url()).searchParams.get('sort')).toBe('name');
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');

    await page.locator('#shop-sort').selectOption('newest');
    await page.getByRole('button', { name: 'Áp dụng' }).click();
    expect(new URL(page.url()).searchParams.get('sort')).toBe('newest');
    await page.getByRole('link', { name: 'Xóa lọc' }).click();
    await expect(page).toHaveURL(/\/shop$/);
    await expect(page.locator('#shop-sort')).toHaveValue('newest');

    const pageTwo = page.locator('nav[aria-label="Phân trang"] a[href*="page=2"]').first();
    if (await pageTwo.count()) {
      await pageTwo.click();
      expect(new URL(page.url()).searchParams.get('page')).toBe('2');
      await expect(page.locator('article').first()).toBeVisible();
    } else {
      testInfo.annotations.push({ type: 'qa-blocked', description: 'QA catalog has no second page for real pagination click.' });
    }

    await visit(page, '/shop/demo-product-01');
    const image = page.locator('main img').first();
    await expect(image).toBeVisible();
    await expect.poll(() => image.evaluate((element) => element.complete && element.naturalWidth > 0)).toBe(true);
    await testInfo.attach('product-detail.png', { body: await page.screenshot(), contentType: 'image/png' });

    const after = retailState();
    expect(after.order_count).toBe(before.order_count);
    expect(after.cart_id).toBe(before.cart_id);
    expect(after.cart_item_count).toBe(before.cart_item_count);
    await testInfo.attach('shop-no-write.json', {
      body: Buffer.from(JSON.stringify({ before: { order_count: before.order_count, cart_item_count: before.cart_item_count },
        after: { order_count: after.order_count, cart_item_count: after.cart_item_count } }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('03 Retail Cart invalid quantity, checkout preview and remove do not create order', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Cart mutation is checked once on desktop.');
    const before = retailState();
    if (before.cart_item_count !== 0) {
      testInfo.annotations.push({ type: 'qa-blocked', description: 'Active Retail cart is not empty; no unrelated items were changed.' });
      return;
    }
    await visit(page, '/shop/demo-product-01');
    const form = page.locator('form:has(select[name=product_variant_id])');
    const skuOption = form.locator('select[name=product_variant_id] option').filter({ hasText: 'DEMO-SKU-01-1' });
    await form.locator('select[name=product_variant_id]').selectOption(String(await skuOption.getAttribute('value')));
    await form.locator('input[name=quantity]').fill('1');
    await form.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/cart$/);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    const withItem = retailState();
    expect(withItem.cart_item_count).toBe(1);
    expect(withItem.order_count).toBe(before.order_count);

    const update = page.locator('form[action*="/cart/items/"]:has(input[name=quantity])').first();
    const quantity = update.locator('input[name=quantity]');
    await quantity.evaluate((element) => element.removeAttribute('min'));
    await quantity.fill('0');
    await update.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/cart$/);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    expect(retailState().cart_item_count).toBe(1);
    await testInfo.attach('cart-invalid.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });

    await visit(page, '/checkout');
    await expect(page.locator('input[name=checkout_operation_key]')).toHaveValue(/^[0-9a-f-]{36}$/i);
    expect(retailState().order_count).toBe(before.order_count);
    await testInfo.attach('checkout-preview.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });
    await visit(page, '/cart');
    await page.locator('form[action*="/cart/items/"]:has(input[name=_method][value=DELETE]) button[type=submit]').first().click();
    await expect(page.locator('main')).not.toContainText('DEMO-SKU-01-1');
    const after = retailState();
    expect(after.order_count).toBe(before.order_count);
    expect(after.cart_item_count).toBe(0);
    testInfo.annotations.push({ type: 'qa-blocked', description: 'This run did not create a second Checkout order or replay the prior #42 operation key.' });
    await testInfo.attach('cart-preview-remove.json', {
      body: Buffer.from(JSON.stringify({ before, withItem, after, invalid_quantity_rejected: true,
        preview_created_order: false }, null, 2)), contentType: 'application/json',
    });
  });

  test('04 Invalid profile input leaves User, Customer and order snapshot intact', async ({ page }, testInfo) => {
    const before = profileState();
    expect(before.order?.id).toBeTruthy();
    await visit(page, '/account/profile');
    await expect(page.locator('main')).toContainText(before.email);

    if (testInfo.project.name === 'desktop-1440') {
      await page.locator('#profile-phone').evaluate((element) => element.removeAttribute('maxlength'));
      await page.locator('#profile-phone').fill('x'.repeat(31));
      await page.getByRole('button', { name: 'Lưu thông tin' }).click();
      await expect(page.locator('#profile-phone-error')).toBeVisible();
      expect(profileState()).toEqual(before);
      await testInfo.attach('profile-invalid.png', {
        body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
      });
    }

    await visit(page, `/my-orders/${before.order.id}`);
    await expect(page.locator('main')).toContainText('QA Browser Retail');
    const after = profileState();
    expect(after).toEqual(before);
    await testInfo.attach('profile-order-unchanged.json', {
      body: Buffer.from(JSON.stringify({ order_id: before.order.id, user_customer_unchanged: true,
        order_snapshot_unchanged: true }, null, 2)), contentType: 'application/json',
    });
  });

  test('12 Customer synthetic return request creates pending case without stock or refund', async ({ page, browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'A dedicated synthetic return request is submitted once on desktop.');
    const orderBefore = salesFixtureState();
    const before = salesReturnState();
    expect(orderBefore.order?.status).toBe('delivered');
    expect(before.payment_status).toBe('unpaid');
    if (!before.return) {
      await visit(page, `/my-orders/${before.order_id}/returns/create`);
      await page.locator('fieldset input[type=checkbox]').first().check();
      await page.locator('input[name="items[0][quantity]"]').fill('1');
      await page.locator('select[name=reason_code]').selectOption('other');
      await page.locator('textarea[name=note]').fill('QA synthetic return request 8IB; no real refund.');
      await page.locator('input[name=operation_key]').evaluate((element, value) => { element.value = value; }, salesReturnKey);
      await page.getByRole('button', { name: 'Gửi yêu cầu' }).click();
      await expect(page).toHaveURL(new RegExp(`/my-orders/${before.order_id}/returns/\\d+$`));
    }
    const after = salesReturnState();
    expect(after.return?.id).toBeTruthy();
    expect(after.return.order_id).toBe(before.order_id);
    expect(after.return.status).toBe('requested');
    expect(after.return.source).toBe('retail');
    expect(after.return.requested_by).toBe(orderBefore.buyer_id);
    expect(after.return.items).toHaveLength(1);
    expect(Number(after.return.items[0].quantity)).toBe(1);
    expect(Number(after.return.items[0].restock_quantity)).toBe(0);
    expect(after.refund_count).toBe(0);
    expect(after.allocation_count).toBe(before.allocation_count);
    expect(salesFixtureState().stock).toEqual(orderBefore.stock);
    await visit(page, `/my-orders/${after.order_id}/returns/${after.return.id}`);
    const admin = await browser.newContext({ baseURL: 'http://127.0.0.1:8001',
      storageState: resolve(stateDirectory, 'admin.json') });
    try {
      const adminPage = await admin.newPage();
      const issues = collectBrowserIssues(adminPage);
      await visit(adminPage, `/admin/sales/returns/${after.return.id}`);
      await expect(adminPage.locator('main')).toContainText('requested');
      expect(issues.console).toEqual([]);
      expect(issues.javascript).toEqual([]);
      expect(issues.network).toEqual([]);
      await testInfo.attach('return-admin-review.png', {
        body: await adminPage.screenshot({ mask: [adminPage.locator('main')] }), contentType: 'image/png',
      });
    } finally {
      await admin.close();
    }
    testInfo.annotations.push({ type: 'qa-blocked', description: 'Order remains unpaid; approval, receipt, restock and refund were not performed without financial evidence.' });
    await testInfo.attach('return-request-before-after.json', {
      body: Buffer.from(JSON.stringify({ before, after, stock_before: orderBefore.stock,
        stock_after: salesFixtureState().stock }, null, 2)), contentType: 'application/json',
    });
  });
});

test.describe('Phase 8I-B Admin reads', () => {
  test.use({ storageState: resolve(stateDirectory, 'admin.json') });

  test('01B Admin Product validation and procurement baseline', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Product validation is submitted once on desktop.');
    const before = productAndProcurementState();
    await visit(page, '/admin/products/create');
    await page.getByRole('button', { name: /4\. Xem lại/ }).click();
    await page.getByRole('button', { name: 'Lưu sản phẩm' }).click();
    await expect(page).toHaveURL(/\/admin\/products\/create$/);
    await page.getByRole('button', { name: /1\. Thông tin/ }).click();
    await expect(page.locator('main')).toContainText('Tên sản phẩm là bắt buộc.');
    await testInfo.attach('product-invalid.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });
    await visit(page, '/admin/products');
    await expect(page.locator('main')).toContainText('DEMO-PRD-01');
    await visit(page, '/admin/prices/retail');
    await visit(page, '/admin/prices/dealer');
    await visit(page, '/admin/inventory');
    await visit(page, '/admin/purchase-orders');
    expect(productAndProcurementState()).toEqual(before);
    await testInfo.attach('product-procurement-no-write.json', {
      body: Buffer.from(JSON.stringify({ before, after: productAndProcurementState() }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('01B Isolated Product, Retail/Dealer prices, PO and two receipts reconcile stock', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'An isolated Product and PO are created once on desktop.');
    test.setTimeout(120_000);
    let state = productFixtureState();
    if (!state.product) {
      const before = state;
      await visit(page, '/admin/products/create');
      await page.locator('#name').fill('QA Browser Product 8IB');
      await page.locator('#slug').fill('qa-browser-product-8ib');
      await page.locator('#product_category_id').selectOption({ index: 1 });
      await page.locator('#brand_id').selectOption({ index: 1 });
      await page.locator('#base_sku').fill(productBaseSku);
      await page.getByRole('button', { name: /2\. SKU/ }).click();
      await page.locator('input[name="variants[0][variant_name]"]').fill('Standard');
      await page.locator('select[name="variants[0][unit_id]"]').selectOption({ index: 1 });
      await page.locator('input[name="variants[0][sellable_dealer]"][type=checkbox]').check();
      await page.getByRole('button', { name: /4\. Xem lại/ }).click();
      await page.getByRole('button', { name: 'Lưu sản phẩm' }).click();
      await expect(page).toHaveURL(/\/admin\/products\/\d+$/);
      state = productFixtureState();
      expect(state.product?.id).toBe(Number(new URL(page.url()).pathname.split('/').at(-1)));
      expect(state.product_count).toBe(before.product_count + 1);
      expect(state.variant_count).toBe(before.variant_count + 1);
      expect(state.variant?.sku).toMatch(/^QA-8IB-PROD/);
      expect(Boolean(state.variant.sellable_retail)).toBe(true);
      expect(Boolean(state.variant.sellable_dealer)).toBe(true);
      expect(state.stock).toHaveLength(0);
      await testInfo.attach('product-created.json', {
        body: Buffer.from(JSON.stringify({ before, after: state }, null, 2)), contentType: 'application/json',
      });
    }

    if (!state.prices.some((price) => price.pricing_context === 'retail')) {
      await visit(page, `/admin/prices/retail?product_id=${state.product.id}`);
      const row = page.locator('tbody tr').filter({ hasText: state.variant.sku }).first();
      await row.locator('summary').click();
      await row.locator('input[name=unit_price]').fill('50000');
      await row.getByRole('button', { name: 'Lưu giá' }).click();
      state = productFixtureState();
      expect(state.prices.some((price) => price.pricing_context === 'retail'
        && Number(price.unit_price) === 50000)).toBe(true);
    }
    if (!state.prices.some((price) => price.pricing_context === 'dealer')) {
      await visit(page, `/admin/prices/dealer?product_id=${state.product.id}`);
      const row = page.locator('tbody tr').filter({ hasText: state.variant.sku }).first();
      await row.locator('summary').click();
      await row.locator('input[name=unit_price]').fill('45000');
      await row.locator('input[name=minimum_quantity]').fill('2');
      await row.getByRole('button', { name: 'Lưu giá' }).click();
      state = productFixtureState();
      expect(state.prices.some((price) => price.pricing_context === 'dealer'
        && Number(price.unit_price) === 45000 && Number(price.minimum_quantity) === 2)).toBe(true);
    }
    await testInfo.attach('product-prices.json', {
      body: Buffer.from(JSON.stringify({ product_id: state.product.id,
        variant_id: state.variant.id, prices: state.prices }, null, 2)), contentType: 'application/json',
    });

    if (!state.order) {
      const before = state;
      await visit(page, '/admin/purchase-orders/create');
      await page.locator('select[name=supplier_id]').selectOption({ index: 1 });
      const warehouseOption = page.locator('select[name=warehouse_id] option').filter({ hasText: 'DEMO-WH-HCM' });
      await page.locator('select[name=warehouse_id]').selectOption(String(await warehouseOption.getAttribute('value')));
      await page.locator('textarea[name=note]').fill(purchaseNote);
      const skuOption = page.locator('select[name="items[0][product_variant_id]"] option').filter({ hasText: state.variant.sku });
      await page.locator('select[name="items[0][product_variant_id]"]').selectOption(String(await skuOption.getAttribute('value')));
      await page.locator('input[name="items[0][quantity]"]').fill('2');
      await page.locator('input[name="items[0][unit_price]"]').fill('10000');
      await page.getByRole('button', { name: 'Lưu phiếu mua' }).click();
      await expect(page).toHaveURL(/\/admin\/purchase-orders\/\d+$/);
      state = productFixtureState();
      expect(state.order?.id).toBe(Number(new URL(page.url()).pathname.split('/').at(-1)));
      expect(state.order.status).toBe('draft');
      expect(state.order.items).toHaveLength(1);
      expect(state.order.items[0].product_variant_id).toBe(state.variant.id);
      expect(Number(state.order.items[0].ordered_quantity)).toBe(2);
      expect(state.order.receipts).toHaveLength(0);
      expect(state.stock).toEqual(before.stock);
      await testInfo.attach('purchase-draft-no-stock.json', {
        body: Buffer.from(JSON.stringify({ before, after: state }, null, 2)), contentType: 'application/json',
      });
    }

    await visit(page, `/admin/purchase-orders/${state.order.id}`);
    if (state.order.status === 'draft') {
      await page.getByRole('button', { name: 'Phát hành' }).click();
      state = productFixtureState();
      expect(state.order.status).toBe('ordered');
      expect(state.order.receipts).toHaveLength(0);
      expect(state.stock).toHaveLength(0);
    }
    const receive = async () => {
      const form = page.locator('form[action$="/goods-receipts"]');
      const operationKey = await form.locator('input[name=operation_key]').inputValue();
      const csrf = await form.locator('input[name=_token]').inputValue();
      const action = await form.getAttribute('action');
      await form.locator('select[name="items[0][purchase_order_item_id]"]')
        .selectOption(String(state.order.items[0].id));
      await form.locator('input[name="items[0][quantity]"]').fill('1');
      await form.getByRole('button', { name: 'Xác nhận nhận hàng' }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/purchase-orders/${state.order.id}$`));
      return { operationKey, csrf, action };
    };
    if (state.order.receipts.length === 0) {
      const receipt = await receive();
      const afterFirst = productFixtureState();
      expect(afterFirst.order.status).toBe('partially_received');
      expect(afterFirst.order.receipts).toHaveLength(1);
      expect(afterFirst.order.receipt_items).toHaveLength(1);
      expect(Number(afterFirst.order.items[0].received_quantity)).toBe(1);
      expect(Number(afterFirst.stock.find((line) => line.warehouse_id === afterFirst.order.warehouse_id)?.on_hand_quantity)).toBe(1);
      expect(Number(afterFirst.stock.find((line) => line.warehouse_id === afterFirst.order.warehouse_id)?.reserved_quantity)).toBe(0);
      const replayResponse = await page.evaluate(async ({ action, csrf, operationKey, itemId }) => {
        const response = await fetch(action, { method: 'POST', credentials: 'same-origin',
          body: new URLSearchParams({ _token: csrf, _operation: 'receipt', operation_key: operationKey,
            'items[0][purchase_order_item_id]': String(itemId), 'items[0][quantity]': '1' }) });
        return response.status;
      }, { ...receipt, itemId: state.order.items[0].id });
      expect(replayResponse).toBe(200);
      expect(productFixtureState()).toEqual(afterFirst);
      state = afterFirst;
      await testInfo.attach('receipt-first-and-replay.json', {
        body: Buffer.from(JSON.stringify({ after: state, replay_status: replayResponse }, null, 2)),
        contentType: 'application/json',
      });
    }
    if (state.order.status === 'partially_received') {
      await visit(page, `/admin/purchase-orders/${state.order.id}`);
      const beforeSecond = state;
      await receive();
      state = productFixtureState();
      expect(state.order.status).toBe('received');
      expect(state.order.receipts).toHaveLength(2);
      expect(state.order.receipt_items).toHaveLength(2);
      expect(Number(state.order.items[0].received_quantity)).toBe(2);
      const priorStock = beforeSecond.stock.find((line) => line.warehouse_id === state.order.warehouse_id);
      const finalStock = state.stock.find((line) => line.warehouse_id === state.order.warehouse_id);
      expect(Number(finalStock.on_hand_quantity) - Number(priorStock.on_hand_quantity)).toBe(1);
      expect(Number(finalStock.reserved_quantity)).toBe(0);
      await testInfo.attach('receipt-complete-before-after.json', {
        body: Buffer.from(JSON.stringify({ before: beforeSecond, after: state }, null, 2)),
        contentType: 'application/json',
      });
    }
    expect(state.order.status).toBe('received');
    expect(state.order.receipts).toHaveLength(2);
    expect(state.order.receipt_items).toHaveLength(2);
    expect(Number(state.order.items[0].received_quantity)).toBe(2);
    expect(Number(state.stock.find((line) => line.warehouse_id === state.order.warehouse_id)?.on_hand_quantity)).toBe(2);
    expect(Number(state.stock.find((line) => line.warehouse_id === state.order.warehouse_id)?.reserved_quantity)).toBe(0);
    expect(state.prices.some((price) => price.pricing_context === 'retail' && Number(price.unit_price) === 50000)).toBe(true);
    expect(state.prices.some((price) => price.pricing_context === 'dealer'
      && Number(price.unit_price) === 45000 && Number(price.minimum_quantity) === 2)).toBe(true);
    await testInfo.attach('procurement-final.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });
  });

  test('07 Admin creates isolated untracked SKU with Dealer price', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'The isolated untracked SKU is created once on desktop.');
    let state = untrackedFixtureState();
    if (!state.product) {
      await visit(page, '/admin/products/create');
      await page.locator('#name').fill('QA Browser Untracked 8IB');
      await page.locator('#slug').fill('qa-browser-untracked-8ib');
      await page.locator('#product_category_id').selectOption({ index: 1 });
      await page.locator('#base_sku').fill(untrackedBaseSku);
      await page.locator('input[name=track_inventory][type=checkbox]').uncheck();
      await page.getByRole('button', { name: /2\. SKU/ }).click();
      await page.locator('input[name="variants[0][variant_name]"]').fill('Standard');
      await page.locator('select[name="variants[0][unit_id]"]').selectOption({ index: 1 });
      await page.locator('input[name="variants[0][sellable_dealer]"][type=checkbox]').check();
      await page.getByRole('button', { name: /4\. Xem lại/ }).click();
      await page.getByRole('button', { name: 'Lưu sản phẩm' }).click();
      await expect(page).toHaveURL(/\/admin\/products\/\d+$/);
      state = untrackedFixtureState();
    }
    expect(Boolean(state.product?.track_inventory)).toBe(false);
    expect(Boolean(state.variant?.track_inventory)).toBe(false);
    expect(Boolean(state.variant?.sellable_dealer)).toBe(true);
    if (!state.dealer_price) {
      await visit(page, `/admin/prices/dealer?product_id=${state.product.id}`);
      const row = page.locator('tbody tr').filter({ hasText: state.variant.sku }).first();
      await row.locator('summary').click();
      await row.locator('input[name=unit_price]').fill('39000');
      await row.locator('input[name=minimum_quantity]').fill('1');
      await row.getByRole('button', { name: 'Lưu giá' }).click();
      state = untrackedFixtureState();
    }
    expect(Number(state.dealer_price)).toBe(39000);
    await testInfo.attach('untracked-dealer-fixture.json', {
      body: Buffer.from(JSON.stringify(state, null, 2)), contentType: 'application/json',
    });
  });

  test('11 Admin synthetic draft edit, confirm and partial fulfillment reconcile stock', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Dedicated synthetic Sales Order is processed once on desktop.');
    test.setTimeout(120_000);
    const before = salesFixtureState();
    let state = before;
    if (!state.order) {
      await visit(page, '/admin/sales/orders/create');
      await page.locator('#buyer-search').fill('retail1@demo.local');
      await page.getByRole('button', { name: /retail1@demo\.local/ }).click();
      await page.locator('#sku-search').fill('DEMO-SKU-01-1');
      await page.getByRole('button', { name: /DEMO-SKU-01-1/ }).last().click();
      await page.locator('input[name="items[0][quantity]"]').fill('2');
      expect(await page.locator('#warehouse_id').inputValue()).not.toBe('');
      await page.locator('#recipient_name').fill('QA Browser Sales 8IB');
      await page.locator('#recipient_phone').fill('0900000089');
      await page.locator('#recipient_email').fill('retail1@demo.local');
      await page.locator('#shipping_address_line1').fill('QA Sales Street 1');
      await page.locator('#shipping_city').fill('Ho Chi Minh');
      await page.locator('#shipping_province').fill('Ho Chi Minh');
      await page.locator('#shipping_country').fill('VN');
      await page.locator('select[name=payment_method]').selectOption('cod');
      await page.locator('textarea[name=delivery_note]').fill('QA-8IB-SALES-DRAFT');
      await page.locator('input[name=operation_key]').evaluate((element, value) => { element.value = value; }, salesDraftKey);
      await page.getByRole('button', { name: 'Lưu đơn nháp' }).click();
      await expect(page).toHaveURL(/\/admin\/sales\/orders\/\d+$/);
      state = salesFixtureState();
      expect(state.order?.id).toBe(Number(new URL(page.url()).pathname.split('/').at(-1)));
      expect(state.order_count).toBe(before.order_count + 1);
      expect(state.order.source).toBe('admin');
      expect(state.order.channel).toBe('retail');
      expect(state.order.buyer_id).toBe(before.buyer_id);
      expect(state.order.status).toBe('draft');
      expect(state.order.items).toHaveLength(1);
      expect(Number(state.order.items[0].quantity)).toBe(2);
      expect(state.order.reservations).toHaveLength(0);
      expect(state.order.allocations).toHaveLength(0);
      expect(state.stock).toEqual(before.stock);
      await testInfo.attach('sales-draft-before-after.json', {
        body: Buffer.from(JSON.stringify({ before, after: state }, null, 2)), contentType: 'application/json',
      });
    }

    await visit(page, `/admin/sales/orders/${state.order.id}`);
    if (state.order.status === 'draft') {
      await page.getByRole('link', { name: 'Chỉnh sửa bản nháp' }).click();
      await page.locator('textarea[name=delivery_note]').fill('QA-8IB-SALES-EDITED');
      await page.getByRole('button', { name: 'Lưu thay đổi' }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/sales/orders/${state.order.id}$`));
      const edited = salesFixtureState();
      expect(edited.order.id).toBe(state.order.id);
      expect(edited.order.delivery_note).toBe('QA-8IB-SALES-EDITED');
      expect(edited.order.reservations).toHaveLength(0);
      expect(edited.stock).toEqual(state.stock);
      state = edited;

      await page.getByRole('button', { name: 'Xác nhận đơn' }).click();
      await page.getByRole('dialog').getByRole('button', { name: 'Xác nhận thao tác' }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/sales/orders/${state.order.id}$`));
      const confirmed = salesFixtureState();
      expect(confirmed.order.status).toBe('confirmed');
      expect(confirmed.order.payment_status).toBe('unpaid');
      expect(confirmed.order.reservations).toHaveLength(1);
      expect(Number(confirmed.order.reservations[0].original_quantity)).toBe(2);
      expect(confirmed.order.movements).toHaveLength(0);
      expect(confirmed.stock.map((line) => line.on_hand_quantity)).toEqual(state.stock.map((line) => line.on_hand_quantity));
      const warehouseBefore = state.stock.find((line) => line.warehouse_id === confirmed.order.warehouse_id);
      const warehouseAfter = confirmed.stock.find((line) => line.warehouse_id === confirmed.order.warehouse_id);
      expect(Number(warehouseAfter.reserved_quantity) - Number(warehouseBefore.reserved_quantity)).toBe(2);
      state = confirmed;
      await testInfo.attach('sales-confirmed.json', {
        body: Buffer.from(JSON.stringify({ before: edited, after: confirmed }, null, 2)), contentType: 'application/json',
      });
    }

    const fulfillOnce = async (quantity) => {
      await page.getByRole('button', { name: 'Xuất hàng', exact: true }).click();
      const dialog = page.getByRole('dialog', { name: /Xuất hàng cho/ });
      await dialog.getByRole('checkbox', { name: 'Chọn DEMO-SKU-01-1' }).check();
      await dialog.locator('input[name$="[quantity]"]').fill(String(quantity));
      await dialog.getByRole('button', { name: 'Xác nhận xuất kho' }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/sales/orders/${state.order.id}$`));
      return salesFixtureState();
    };
    if (state.order.status === 'confirmed') {
      const partial = await fulfillOnce(1);
      expect(partial.order.status).toBe('processing');
      expect(partial.order.fulfillment_status).toBe('partially_fulfilled');
      expect(Number(partial.order.reservations[0].consumed_quantity)).toBe(1);
      expect(partial.order.movements).toHaveLength(1);
      const warehouseBefore = state.stock.find((line) => line.warehouse_id === state.order.warehouse_id);
      const warehouseAfter = partial.stock.find((line) => line.warehouse_id === state.order.warehouse_id);
      expect(Number(warehouseBefore.on_hand_quantity) - Number(warehouseAfter.on_hand_quantity)).toBe(1);
      expect(Number(warehouseBefore.reserved_quantity) - Number(warehouseAfter.reserved_quantity)).toBe(1);
      state = partial;
      await testInfo.attach('sales-partial-fulfillment.json', {
        body: Buffer.from(JSON.stringify(partial, null, 2)), contentType: 'application/json',
      });
    }
    if (state.order.status === 'processing') {
      const delivered = await fulfillOnce(1);
      expect(delivered.order.status).toBe('delivered');
      expect(delivered.order.fulfillment_status).toBe('fulfilled');
      expect(delivered.order.payment_status).toBe('unpaid');
      expect(delivered.order.allocations).toHaveLength(0);
      expect(Number(delivered.order.reservations[0].consumed_quantity)).toBe(2);
      expect(delivered.order.movements).toHaveLength(2);
      const warehouseBefore = state.stock.find((line) => line.warehouse_id === state.order.warehouse_id);
      const warehouseAfter = delivered.stock.find((line) => line.warehouse_id === state.order.warehouse_id);
      expect(Number(warehouseBefore.on_hand_quantity) - Number(warehouseAfter.on_hand_quantity)).toBe(1);
      expect(Number(warehouseBefore.reserved_quantity) - Number(warehouseAfter.reserved_quantity)).toBe(1);
      state = delivered;
      await testInfo.attach('sales-delivered-unpaid.json', {
        body: Buffer.from(JSON.stringify(delivered, null, 2)), contentType: 'application/json',
      });
    }
    expect(state.order.status).toBe('delivered');
    testInfo.annotations.push({ type: 'qa-blocked', description: 'Manual payment was not settled without an independently verified QA receipt; Return refund remains unavailable.' });
  });

  test('13 Report period and group, audit filters and Customer order detail', async ({ page }, testInfo) => {
    const facts = reportState();
    expect(facts.audit?.id).toBeTruthy();

    await visit(page, '/admin/reports');
    await page.locator('input[name=from]').fill('2026-01-01');
    await page.locator('input[name=to]').fill('2026-12-31');
    await page.getByRole('button', { name: 'Xem báo cáo' }).click();
    expect(new URL(page.url()).searchParams.get('from')).toBe('2026-01-01');
    expect(new URL(page.url()).searchParams.get('to')).toBe('2026-12-31');
    const grossText = await page.locator('section[aria-label="Chỉ số ERP"] > div').nth(1).locator('p').nth(1).textContent();
    expect(Number(grossText.replace(/\D/g, ''))).toBe(Number(facts.gross_settled_allocations));
    await page.getByRole('navigation', { name: 'Nhóm báo cáo' }).getByRole('link', { name: 'Doanh thu' }).click();
    await expect(page).toHaveURL(/\/admin\/reports\/sales/);
    await expect(page.locator('input[name=from]')).toHaveValue('2026-01-01');
    await testInfo.attach('report-reconciliation.json', {
      body: Buffer.from(JSON.stringify({ period: '2026-01-01..2026-12-31',
        gross_source: 'settled payment_allocations',
        gross_settled_allocations: facts.gross_settled_allocations }, null, 2)),
      contentType: 'application/json',
    });

    await visit(page, '/admin/audit-logs');
    if (facts.audit_count > 25) {
      const secondPage = page.locator('nav a[href*="page=2"]').first();
      await expect(secondPage).toBeVisible();
      await secondPage.click();
      expect(new URL(page.url()).searchParams.get('page')).toBe('2');
      await visit(page, '/admin/audit-logs');
    } else {
      testInfo.annotations.push({ type: 'qa-blocked', description: 'Audit fixture has at most 25 rows, so real pagination cannot be clicked.' });
    }
    await page.locator('input[name=search]').fill(facts.audit.target_name);
    await page.locator('select[name=module]').selectOption(facts.audit.module);
    await page.locator('select[name=action]').selectOption(facts.audit.action);
    if (facts.audit.actor_id) await page.locator('input[name=actor_id]').fill(String(facts.audit.actor_id));
    await page.getByRole('button', { name: 'Lọc' }).click();
    expect(new URL(page.url()).searchParams.get('module')).toBe(facts.audit.module);
    expect(new URL(page.url()).searchParams.get('action')).toBe(facts.audit.action);
    await expect(page.locator(`a[href$="/admin/audit-logs/${facts.audit.id}"]`)).toBeVisible();
    await page.locator(`a[href$="/admin/audit-logs/${facts.audit.id}"]`).click();
    await expect(page).toHaveURL(new RegExp(`/admin/audit-logs/${facts.audit.id}$`));
    await expect(page.locator('main')).toContainText(facts.audit.target_name);
    await expect(page.locator('main')).not.toContainText(/APP_KEY=|Bearer\s+[A-Za-z0-9._-]+/);

    await visit(page, '/admin/customers');
    await page.locator('input[name=search]').fill('retail1@demo.local');
    await page.getByRole('button', { name: 'Tìm kiếm' }).click();
    await expect(page.locator('table tbody tr')).toHaveCount(1);
    await page.getByRole('link', { name: 'Xem chi tiết' }).click();
    await expect(page.locator('main a[href$="/admin/sales/orders/42"]')).toBeVisible();
  });
});

test.describe('Phase 8I-B Dealer catalog', () => {
  test.use({ storageState: resolve(stateDirectory, 'dealer.json') });

  test('00 QA financial fixture preflight is read-only', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'One QA financial fixture snapshot is sufficient.');
    const before = financialFixtureState();
    await visit(page, '/dealer/wallet');
    const after = financialFixtureState();
    expect(after).toEqual(before);
    await testInfo.attach('qa-financial-fixture.json', {
      body: Buffer.from(JSON.stringify(after, null, 2)), contentType: 'application/json',
    });
  });

  test('07 Dealer Tier price, MOQ and isolated address edit', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Address edit and restore run once on desktop.');
    const before = dealerCatalogAndAddressState();
    expect(before.address_id).toBeTruthy();
    expect(before.address_account_id).toBe(before.account_id);
    expect(before.unit_price).toBeTruthy();
    expect(before.moq).toBeTruthy();

    await visit(page, '/dealer/profile');
    await expect(page.locator('main')).toContainText(before.tier_name);
    await visit(page, '/dealer/products/demo-product-01');
    const card = page.locator('article').filter({ hasText: 'DEMO-SKU-01-1' }).first();
    await expect(card).toContainText(`MOQ: ${before.moq}`);
    const displayedPrice = await card.locator('p').filter({ hasText: '₫' }).first().textContent();
    expect(Number(displayedPrice.replace(/\D/g, ''))).toBe(Number(before.unit_price));

    let changed;
    try {
      await visit(page, `/dealer/addresses/${before.address_id}/edit`);
      await page.locator('input[name=address_line]').fill('QA Browser Street 7B');
      await page.locator('input[name=postal_code]').fill('QA8IB');
      await page.getByRole('button', { name: 'Lưu địa chỉ' }).click();
      await expect(page).toHaveURL(/\/dealer\/addresses$/);
      changed = dealerCatalogAndAddressState();
      expect(changed.address_line).toBe('QA Browser Street 7B');
      expect(changed.postal_code).toBe('QA8IB');
      expect(changed.address_count).toBe(before.address_count);
      expect(changed.address_account_id).toBe(before.account_id);
      expect(changed.province_code).toBe(before.province_code);
      expect(changed.ward_code).toBe(before.ward_code);
    } finally {
      await visit(page, `/dealer/addresses/${before.address_id}/edit`);
      await page.locator('input[name=address_line]').fill(before.address_line);
      await page.locator('input[name=postal_code]').fill(before.postal_code ?? '');
      await page.getByRole('button', { name: 'Lưu địa chỉ' }).click();
    }
    const after = dealerCatalogAndAddressState();
    expect(after).toEqual(before);
    const untracked = untrackedFixtureState();
    expect(before.untracked_sellable_count).toBeGreaterThan(0);
    expect(Boolean(untracked.variant?.track_inventory)).toBe(false);
    await visit(page, `/dealer/products/${untracked.product.slug}`);
    const untrackedCard = page.locator('article').filter({ hasText: untracked.variant.sku }).first();
    await expect(untrackedCard).toContainText('không hỗ trợ đặt qua Đặt hàng nhanh');
    await expect(untrackedCard.getByRole('link', { name: /Đặt SKU/ })).toHaveCount(0);
    await testInfo.attach('dealer-address-edit-reconciled.json', {
      body: Buffer.from(JSON.stringify({ address_id: before.address_id, account_id: before.account_id,
        count_before: before.address_count, count_after: after.address_count,
        changed_then_restored: changed.address_line !== before.address_line && after.address_line === before.address_line,
        tier_name: before.tier_name, unit_price: before.unit_price, moq: before.moq }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('08 Dealer Quick Order validates MOQ and reconciles one wallet order', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'One synthetic wallet order is created at desktop only.');
    const before = financialFixtureState();
    const catalog = dealerCatalogAndAddressState();
    expect(catalog.account_id).toBe(before.account_id);
    expect(catalog.address_account_id).toBe(before.account_id);
    expect(catalog.address_id).toBeTruthy();
    expect(Number(before.wallet_balance)).toBeGreaterThan(0);

    await visit(page, '/dealer/products/demo-product-01');
    await page.getByRole('link', { name: /Đặt SKU DEMO-SKU-01-1/ }).click();
    expect(new URL(page.url()).searchParams.get('variant_id')).toBe(String(catalog.variant_id));
    await page.locator('select[name=shipping_address_id]').selectOption(String(catalog.address_id));
    const quantity = page.locator('input[name="items[0][quantity]"]');
    const expectedQuantity = Math.ceil(Number(catalog.moq));
    expect(expectedQuantity).toBeGreaterThan(0);

    if (!before.quick_order) {
      const invalidQuantity = expectedQuantity > 1 ? '1' : '0';
      if (invalidQuantity === '0') await quantity.evaluate((element) => element.removeAttribute('min'));
      await quantity.fill(invalidQuantity);
      await page.getByRole('button', { name: 'Xem trước giá, kho và ví' }).click();
      await expect(page).toHaveURL(/\/dealer\/quick-order(?:\?.*)?$/);
      if (invalidQuantity === '0') {
        await expect(page.getByRole('alert').first()).toBeVisible();
      } else {
        await expect(page.getByText('Chưa thể đặt hàng. Kiểm tra SKU, MOQ, tồn kho hoặc số dư ví.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Xác nhận đặt hàng' })).toBeDisabled();
      }
      expect(financialFixtureState()).toEqual(before);
      await testInfo.attach('quick-order-invalid.png', {
        body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
      });
    }

    await visit(page, `/dealer/quick-order?variant_id=${catalog.variant_id}`);
    await page.locator('select[name=shipping_address_id]').selectOption(String(catalog.address_id));
    await page.locator('input[name="items[0][quantity]"]').fill(String(expectedQuantity));
    await page.getByRole('button', { name: 'Xem trước giá, kho và ví' }).click();
    await expect(page.locator('section').filter({ hasText: 'Xem trước từ máy chủ' }).first()).toBeVisible();
    await expect(page.locator('table tbody tr').first()).toContainText('DEMO-SKU-01-1');
    const previewTotal = await page.getByText('Tổng thanh toán:').first().textContent();
    const authoritativeTotal = Number(previewTotal.replace(/\D/g, ''));
    expect(authoritativeTotal).toBeGreaterThan(0);

    if (before.quick_order) {
      expect(before.quick_order.dealer_account_id).toBe(before.account_id);
      const retry = page.getByRole('button', { name: 'Xác nhận đặt hàng' });
      if (await retry.isEnabled()) {
        await page.locator('input[name=operation_key]').evaluate((element, value) => { element.value = value; }, quickOrderKey);
        page.once('dialog', (dialog) => dialog.accept());
        await retry.click();
        await expect(page).toHaveURL(new RegExp(`/dealer/orders/${before.quick_order.id}$`));
        expect(financialFixtureState()).toEqual(before);
      } else {
        testInfo.annotations.push({ type: 'qa-blocked', description: 'Quick Order replay preview was disabled; no repeat debit was attempted.' });
      }
      await visit(page, `/dealer/orders/${before.quick_order.id}`);
      await testInfo.attach('quick-order-existing.json', {
        body: Buffer.from(JSON.stringify({ order_id: before.quick_order.id, status: before.quick_order.status,
          new_order_created: false, financial_state_unchanged: financialFixtureState().wallet_balance === before.wallet_balance }, null, 2)), contentType: 'application/json',
      });
      return;
    }

    const submit = page.getByRole('button', { name: 'Xác nhận đặt hàng' });
    if (!(await submit.isEnabled())) {
      testInfo.annotations.push({ type: 'qa-blocked', description: 'Server preview did not permit submit; no order or wallet debit was attempted.' });
      expect(financialFixtureState()).toEqual(before);
      return;
    }
    expect(financialFixtureState()).toEqual(before);
    await page.locator('input[name=operation_key]').evaluate((element, value) => { element.value = value; }, quickOrderKey);
    page.once('dialog', (dialog) => dialog.accept());
    await submit.click();
    await expect(page).toHaveURL(/\/dealer\/orders\/\d+$/);

    const after = financialFixtureState();
    expect(after.quick_order?.id).toBe(Number(new URL(page.url()).pathname.split('/').at(-1)));
    expect(after.quick_order.dealer_account_id).toBe(before.account_id);
    expect(after.dealer_order_count).toBe(before.dealer_order_count + 1);
    expect(Number(after.quick_order.grand_total)).toBe(authoritativeTotal);
    expect(Number(before.wallet_balance) - Number(after.wallet_balance)).toBe(authoritativeTotal);
    expect(after.wallet_transaction_count).toBe(before.wallet_transaction_count + 1);
    expect(after.quick_order.wallet_transactions).toHaveLength(1);
    expect(after.quick_order.wallet_transactions[0].direction).toBe('debit');
    expect(Number(after.quick_order.wallet_transactions[0].amount)).toBe(authoritativeTotal);
    const item = after.quick_order.items.find((line) => line.sku_snapshot === 'DEMO-SKU-01-1');
    expect(Number(item.quantity)).toBe(expectedQuantity);
    expect(Number(item.minimum_quantity_snapshot)).toBe(Number(catalog.moq));
    expect(Number(item.unit_price_snapshot)).toBe(Number(catalog.unit_price));
    const reservation = after.quick_order.reservations.find((line) => line.product_variant_id === catalog.variant_id);
    expect(Number(reservation.original_quantity)).toBe(expectedQuantity);
    expect(after.stock.map((line) => line.on_hand_quantity)).toEqual(before.stock.map((line) => line.on_hand_quantity));
    const stockBefore = before.stock.find((line) => line.warehouse_id === after.quick_order.warehouse_id);
    const stockAfter = after.stock.find((line) => line.warehouse_id === after.quick_order.warehouse_id);
    expect(Number(stockAfter.reserved_quantity) - Number(stockBefore.reserved_quantity)).toBe(expectedQuantity);
    await testInfo.attach('quick-order-before-after.json', {
      body: Buffer.from(JSON.stringify({ before, after, authoritative_preview_total: authoritativeTotal }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('09 Dealer deposit request remains pending with private QA proof and no credit', async ({ page, browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'A synthetic deposit request is submitted at desktop only.');
    const before = depositState();
    await visit(page, '/dealer/wallet');
    if (!before.request) {
      const form = page.locator('form:has(input[name=amount])');
      await form.locator('input[name=amount]').fill('1000');
      await form.locator('input[name=payment_proof]').setInputFiles({
        name: 'qa-synthetic-proof.png', mimeType: 'image/png', buffer: qaProof,
      });
      await form.locator('input[name=transaction_reference]').fill(depositReference);
      await form.locator('textarea[name=note]').fill('QA synthetic proof only; no bank receipt has been verified.');
      await form.locator('button[type=submit]').click();
      await expect(page).toHaveURL(/\/dealer\/wallet\/deposit-requests\/\d+$/);
    } else {
      await visit(page, `/dealer/wallet/deposit-requests/${before.request.id}`);
    }

    const after = depositState();
    expect(after.request?.id).toBe(Number(new URL(page.url()).pathname.split('/').at(-1)));
    expect(after.request.account_id).toBe(before.account_id);
    expect(after.request.wallet_id).toBe(before.wallet_id);
    expect(after.request.amount).toBe('1000.00');
    expect(after.request.status).toBe('pending');
    expect(after.request.transaction_id).toBeNull();
    expect(after.request.proof_exists).toBe(true);
    expect(after.request.proof_path).toMatch(/^dealer-wallet-proofs\//);
    expect(after.balance).toBe(before.balance);
    expect(after.ledger_count).toBe(before.ledger_count);
    expect(after.other_wallets).toEqual(before.other_wallets);
    await expect(page.locator('main')).toContainText(depositReference);

    const dealerProof = await page.request.get(`/dealer/wallet/deposit-requests/${after.request.id}/proof`);
    expect(dealerProof.status()).toBe(200);
    expect(dealerProof.headers()['content-type']).toMatch(/^image\//);
    const admin = await browser.newContext({ baseURL: 'http://127.0.0.1:8001', storageState: resolve(stateDirectory, 'admin.json') });
    try {
      const adminPage = await admin.newPage();
      const adminIssues = collectBrowserIssues(adminPage);
      await visit(adminPage, `/admin/dealer-wallet-deposit-requests/${after.request.id}`);
      await expect(adminPage.locator('main')).toContainText(depositReference);
      const adminProof = await admin.request.get(`/admin/dealer-wallet-deposit-requests/${after.request.id}/proof`);
      expect(adminProof.status()).toBe(200);
      await testInfo.attach('deposit-admin-review.png', {
        body: await adminPage.screenshot({ mask: [adminPage.locator('main')] }), contentType: 'image/png',
      });
      expect(adminIssues.console).toEqual([]);
      expect(adminIssues.javascript).toEqual([]);
      expect(adminIssues.network).toEqual([]);
      await testInfo.attach('deposit-admin-browser-issues.json', {
        body: Buffer.from(JSON.stringify(adminIssues, null, 2)), contentType: 'application/json',
      });
    } finally {
      await admin.close();
    }
    testInfo.annotations.push({ type: 'qa-blocked', description: 'No independently verified bank receipt exists for the synthetic proof; Admin credit was not approved.' });
    await testInfo.attach('deposit-pending-before-after.json', {
      body: Buffer.from(JSON.stringify({ before, after, dealer_proof_status: dealerProof.status(), admin_proof_status: 200,
        admin_approval_performed: false }, null, 2)), contentType: 'application/json',
    });
  });

  test('10 Dealer XLSX template with no order rows is rejected without wallet debit', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Template upload is checked once on desktop.');
    const before = financialFixtureState();
    await visit(page, '/dealer/orders/import');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Tải mẫu XLSX' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('dealer_order_template.xlsx');
    const workbook = readFileSync(await download.path());
    expect(workbook.subarray(0, 2).toString()).toBe('PK');
    const form = page.locator('form:has(input[name=file])');
    await form.locator('input[name=file]').setInputFiles({
      name: 'qa-empty-dealer-order.xlsx',
      mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      buffer: workbook,
    });
    await form.getByRole('button', { name: 'Tải lên và xem trước' }).click();
    await expect(page).toHaveURL(/\/dealer\/orders\/import$/);
    await expect(page.locator('main')).toContainText('IMPORT_EMPTY');
    const after = financialFixtureState();
    expect(after).toEqual(before);
    await testInfo.attach('import-empty-template.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });
    await testInfo.attach('import-empty-no-write.json', {
      body: Buffer.from(JSON.stringify({ template_bytes: workbook.length,
        dealer_import_count_before: before.dealer_import_count, dealer_import_count_after: after.dealer_import_count,
        wallet_balance_before: before.wallet_balance, wallet_balance_after: after.wallet_balance }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('10 Dealer XLSX invalid SKU, valid preview, confirm and idempotent replay', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'One synthetic Excel order is created at desktop only.');
    const initial = financialFixtureState();
    const upload = async (kind) => {
      await visit(page, '/dealer/orders/import');
      const form = page.locator('form:has(input[name=file])');
      await form.locator('input[name=file]').setInputFiles(resolve(backendDirectory,
        `../.codex/browser-qa/fixtures/qa-dealer-import-${kind}.xlsx`));
      await form.getByRole('button', { name: 'Tải lên và xem trước' }).click();
      await expect(page).toHaveURL(/\/dealer\/orders\/import\/\d+$/);
    };

    let invalid = importState('invalid');
    if (!invalid) await upload('invalid');
    invalid = importState('invalid');
    expect(invalid?.account_id).toBe(initial.account_id);
    expect(invalid.row_count).toBe(1);
    expect(invalid.order_count).toBe(1);
    expect(invalid.invalid_order_count).toBe(1);
    expect(invalid.groups[0].errors.some((error) => error.code === 'SKU_NOT_FOUND')).toBe(true);
    expect(invalid.orders).toHaveLength(0);
    let afterInvalid = financialFixtureState();
    expect(afterInvalid.dealer_order_count).toBe(initial.dealer_order_count);
    expect(afterInvalid.wallet_balance).toBe(initial.wallet_balance);
    expect(afterInvalid.wallet_transaction_count).toBe(initial.wallet_transaction_count);
    expect(afterInvalid.stock).toEqual(initial.stock);
    await visit(page, `/dealer/orders/import/${invalid.id}`);
    await expect(page.locator('main')).toContainText('SKU_NOT_FOUND');
    await testInfo.attach('import-invalid-preview.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });

    let valid = importState('valid');
    if (!valid) await upload('valid');
    valid = importState('valid');
    expect(valid?.account_id).toBe(initial.account_id);
    expect(valid.row_count).toBe(1);
    expect(valid.order_count).toBe(1);
    expect(valid.valid_order_count).toBe(1);
    expect(valid.invalid_order_count).toBe(0);
    expect(valid.preview_fingerprint).toMatch(/^[a-f0-9]{64}$/);
    const previewTotal = Number(valid.preview_summary.estimated_total);
    expect(previewTotal).toBeGreaterThan(0);
    await visit(page, `/dealer/orders/import/${valid.id}`);
    await expect(page.locator('main')).toContainText('DEMO-SKU-01-1');
    await testInfo.attach('import-valid-preview.png', {
      body: await page.screenshot({ mask: [page.locator('main')] }), contentType: 'image/png',
    });
    const beforeConfirm = financialFixtureState();
    expect(beforeConfirm.dealer_order_count).toBe(afterInvalid.dealer_order_count);
    expect(beforeConfirm.wallet_balance).toBe(afterInvalid.wallet_balance);
    expect(beforeConfirm.stock).toEqual(afterInvalid.stock);

    if (valid.status === 'preview_ready') {
      const form = page.locator('form[action$="/confirm"]');
      const operationKey = await form.locator('input[name=operation_key]').inputValue();
      const fingerprint = await form.locator('input[name=preview_fingerprint]').inputValue();
      const csrf = await form.locator('input[name=_token]').inputValue();
      const action = await form.getAttribute('action');
      page.once('dialog', (dialog) => dialog.accept());
      await form.getByRole('button', { name: 'Xác nhận tạo đơn' }).click();
      await expect(page).toHaveURL(new RegExp(`/dealer/orders/import/${valid.id}$`));
      valid = importState('valid');
      expect(valid.status).toBe('completed');
      expect(valid.confirm_operation_key).toBe(operationKey);
      expect(valid.groups[0].status).toBe('created');
      expect(valid.orders).toHaveLength(1);
      const afterConfirm = financialFixtureState();
      expect(afterConfirm.dealer_order_count).toBe(beforeConfirm.dealer_order_count + 1);
      expect(Number(beforeConfirm.wallet_balance) - Number(afterConfirm.wallet_balance)).toBe(previewTotal);
      expect(afterConfirm.wallet_transaction_count).toBe(beforeConfirm.wallet_transaction_count + 1);
      expect(afterConfirm.stock.map((line) => line.on_hand_quantity))
        .toEqual(beforeConfirm.stock.map((line) => line.on_hand_quantity));
      expect(valid.items).toHaveLength(1);
      expect(valid.items[0].sku_snapshot).toBe('DEMO-SKU-01-1');
      expect(Number(valid.items[0].quantity)).toBe(1);
      expect(valid.reservations).toHaveLength(1);
      expect(Number(valid.reservations[0].original_quantity)).toBe(1);
      const warehouseBefore = beforeConfirm.stock.find((line) => line.warehouse_id === valid.orders[0].warehouse_id);
      const warehouseAfter = afterConfirm.stock.find((line) => line.warehouse_id === valid.orders[0].warehouse_id);
      expect(Number(warehouseAfter.reserved_quantity) - Number(warehouseBefore.reserved_quantity)).toBe(1);
      expect(valid.wallet_transactions).toHaveLength(1);
      expect(valid.wallet_transactions[0].direction).toBe('debit');
      expect(Number(valid.wallet_transactions[0].amount)).toBe(previewTotal);

      const replayResponse = await page.evaluate(async ({ action, csrf, operationKey, fingerprint }) => {
        const response = await fetch(action, { method: 'POST', credentials: 'same-origin',
          body: new URLSearchParams({ _token: csrf, operation_key: operationKey,
            preview_fingerprint: fingerprint }) });
        return { status: response.status, path: new URL(response.url).pathname };
      }, { action, csrf, operationKey, fingerprint });
      expect(replayResponse).toEqual({ status: 200, path: `/dealer/orders/import/${valid.id}` });
      expect(importState('valid')).toEqual(valid);
      expect(financialFixtureState()).toEqual(afterConfirm);
      await testInfo.attach('import-valid-before-after.json', {
        body: Buffer.from(JSON.stringify({ before: beforeConfirm, preview: valid.preview_summary,
          after: afterConfirm, order: valid, replay: replayResponse }, null, 2)), contentType: 'application/json',
      });
    } else {
      expect(valid.status).toBe('completed');
      expect(valid.orders).toHaveLength(1);
      expect(valid.groups[0].status).toBe('created');
      expect(valid.reservations).toHaveLength(1);
      expect(valid.wallet_transactions).toHaveLength(1);
      expect(valid.wallet_transactions[0].direction).toBe('debit');
    }
  });
});

test.describe('Phase 8I-B Security', () => {
  test.use({ storageState: resolve(stateDirectory, 'retail.json') });

  test('14 CSRF, cross-owner order, private proof and external redirect', async ({ page, browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Security boundary writes and login are checked once on desktop.');
    const before = retailState();
    const order = profileState().order;
    const proof = proofState();
    expect(order?.id).toBeTruthy();
    expect(proof.request_id).toBeTruthy();
    expect(proof.proof_exists).toBe(true);
    const mainIssues = collectBrowserIssues(page, [
      [`/admin/dealer-wallet-deposit-requests/${proof.request_id}/proof`, 403],
    ]);

    await visit(page, '/cart');
    const csrfResponse = await page.request.post('/cart/items', {
      data: {}, maxRedirects: 0, headers: { Accept: 'text/html' },
    });
    expect(csrfResponse.status()).toBe(419);
    expect(retailState()).toEqual(before);

    const deniedProof = await page.goto(`/admin/dealer-wallet-deposit-requests/${proof.request_id}/proof`);
    expect(deniedProof.status()).toBe(403);
    expect(mainIssues.console).toEqual([]);
    expect(mainIssues.javascript).toEqual([]);
    expect(mainIssues.network).toEqual([]);
    expect(mainIssues.expectedNetwork).toContainEqual({
      path: `/admin/dealer-wallet-deposit-requests/${proof.request_id}/proof`, status: 403,
    });
    const admin = await browser.newContext({ baseURL: 'http://127.0.0.1:8001',
      storageState: resolve(stateDirectory, 'admin.json') });
    const applicant = await browser.newContext({ baseURL: 'http://127.0.0.1:8001',
      storageState: { cookies: [], origins: [] } });
    const guest = await browser.newContext({ baseURL: 'http://127.0.0.1:8001',
      storageState: { cookies: [], origins: [] } });
    try {
      const adminPage = await admin.newPage();
      const adminIssues = collectBrowserIssues(adminPage);
      const authorizedProof = await adminPage.goto(`/admin/dealer-wallet-deposit-requests/${proof.request_id}/proof`);
      expect(authorizedProof.status()).toBe(200);
      expect(authorizedProof.headers()['content-type']).toMatch(/^image\//);

      const applicantPage = await applicant.newPage();
      const applicantIssues = collectBrowserIssues(applicantPage, [
        [`/my-orders/${order.id}`, 404], [`/dealer/wallet/deposit-requests/${proof.request_id}/proof`, 404],
      ]);
      await applicantPage.goto('/login');
      await applicantPage.locator('input[name=email]').fill('qa.browser.applicant.20261011@demo.local');
      await applicantPage.locator('input[name=password]').fill(qaPassword());
      await applicantPage.getByRole('button', { name: 'Đăng nhập' }).click();
      await expect(applicantPage).toHaveURL(/\/dealer\/orders$/);
      const deniedOrder = await applicantPage.goto(`/my-orders/${order.id}`);
      expect(deniedOrder.status()).toBe(404);
      const deniedDealerProof = await applicantPage.goto(`/dealer/wallet/deposit-requests/${proof.request_id}/proof`);
      expect(deniedDealerProof.status()).toBe(404);

      const guestPage = await guest.newPage();
      const guestIssues = collectBrowserIssues(guestPage);
      await guestPage.goto('/login?return_to=https%3A%2F%2Fevil.example');
      await guestPage.locator('input[name=email]').fill('admin@demo.local');
      await guestPage.locator('input[name=password]').fill(qaPassword());
      await guestPage.getByRole('button', { name: 'Đăng nhập' }).click();
      await expect(guestPage).toHaveURL(/\/admin$/);
      expect(new URL(guestPage.url()).origin).toBe('http://127.0.0.1:8001');
      for (const roleIssues of [adminIssues, applicantIssues, guestIssues]) {
        expect(roleIssues.console).toEqual([]);
        expect(roleIssues.javascript).toEqual([]);
        expect(roleIssues.network).toEqual([]);
      }
      expect(applicantIssues.expectedNetwork).toHaveLength(2);
      expect(applicantIssues.expectedConsole).toHaveLength(2);
      await testInfo.attach('security-aux-browser-issues.json', {
        body: Buffer.from(JSON.stringify({ admin: adminIssues, otherDealer: applicantIssues, guest: guestIssues }, null, 2)),
        contentType: 'application/json',
      });
    } finally {
      await admin.close();
      await applicant.close();
      await guest.close();
    }

    expect(proofState()).toEqual(proof);
    await testInfo.attach('security-boundaries.json', {
      body: Buffer.from(JSON.stringify({ csrf_status: csrfResponse.status(), csrf_transport: 'Playwright request using browser session', retail_admin_proof: 403,
        admin_proof: 200, other_customer_order: 404, other_dealer_proof: 404,
        external_return_to_stayed_local: true, data_unchanged: true }, null, 2)),
      contentType: 'application/json',
    });
  });

  test('14 Responsive Admin, Retail and Dealer controls', async ({ page, browser }, testInfo) => {
    const viewport = page.viewportSize();
    const screens = [
      ['admin', ['/admin/products', '/admin/sales/orders', '/admin/reports', '/admin/audit-logs']],
      ['retail', ['/shop', '/cart', '/checkout', '/account/profile']],
      ['dealer', ['/dealer/products', '/dealer/quick-order', '/dealer/wallet', '/dealer/orders/import']],
    ];
    for (const [role, paths] of screens) {
      const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8001', viewport,
        storageState: resolve(stateDirectory, `${role}.json`) });
      try {
        const rolePage = await context.newPage();
        const roleIssues = collectBrowserIssues(rolePage);
        for (const path of paths) {
          await visit(rolePage, path);
          const overflow = await rolePage.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
          expect(overflow, `${testInfo.project.name} ${path} horizontal overflow`).toBeLessThanOrEqual(1);
          await expect(rolePage.locator('main')).toBeInViewport();
        }
        if (role === 'dealer' && viewport.width < 1024) {
          await rolePage.getByRole('button', { name: 'Mở điều hướng' }).click();
          await expect(rolePage.locator('aside[aria-label="Điều hướng chính"]').getByRole('link', { name: 'Ví & nạp tiền' })).toBeVisible();
          await rolePage.getByRole('button', { name: 'Đóng điều hướng' }).click();
        }
        await testInfo.attach(`${role}-${testInfo.project.name}.png`, {
          body: await rolePage.screenshot({ mask: [rolePage.locator('main')] }),
          contentType: 'image/png',
        });
        expect(roleIssues.console).toEqual([]);
        expect(roleIssues.javascript).toEqual([]);
        expect(roleIssues.network).toEqual([]);
        await testInfo.attach(`${role}-${testInfo.project.name}-browser-issues.json`, {
          body: Buffer.from(JSON.stringify(roleIssues, null, 2)), contentType: 'application/json',
        });
      } finally {
        await context.close();
      }
    }
  });
});
