# Demo database seeding

`DemoDataSeeder` adds fictional Clinic, Retail, Dealer, Inventory and Procurement data for local demonstrations. It runs only when `APP_ENV` is `local` or `testing`.

## Command and credentials

From `backend/`:

```bash
php artisan db:seed --class=DemoDataSeeder --no-interaction
```

The development-only accounts include `admin@demo.local`, `retail1@demo.local` and `dealer1@demo.local`. Set `DEMO_ADMIN_PASSWORD` before seeding to choose their initial password. If unset in local/testing, newly created demo users receive `DemoOnly!2026`. The seeder never prints a password and never resets the password of an existing account. Never use this password in a deployed environment.

## Safety and replay

The seeder never truncates, deletes or resets existing data. **Never use `migrate:fresh` on a database whose contents must be preserved.** It creates master records by stable `DEMO-` codes and `@demo.local` emails, then reuses them on later runs. A matching email with a different name or role causes an error instead of overwriting that user. Procurement documents and business operations have deterministic demo keys; existing immutable Orders, Receipts, Returns, Payments, Refunds, Deposits and stock movements are reused or skipped. An existing non-demo default sales Warehouse is left unchanged. The Dealer Tier preset seeder provides fixed active Silver, Gold and Diamond tiers, with Silver as the initial tier. If a prior demo seed created `DEMO-INITIAL`, its live accounts, Dealer price lists and promotion targets are moved to Silver with new Tier history while the legacy row and Order snapshots remain intact. Revenue thresholds and the Auto Tier policy are not enabled automatically. Unexpected existing Tier codes cause the preset seeder to stop for review.

Demo records use fictional names, `@demo.local` contacts, `DEMO-` business codes where the domain permits, and `Demo data` notes. Dealer account codes and some ledger document codes are generated and immutable by their domain services, so those records are identified by their demo owner/application and deterministic operation key instead.

## Data included

One Admin, two reception staff, three doctors, ten Retail customers and five Dealer owners; 25 appointments across valid Clinic statuses; two Warehouses; 13 normally purchasable aesthetic-themed products with 30 variants, plus three Gift-only products with one SKU each; separate Retail and Dealer price lists; five approved Dealer accounts with owner memberships, initial Tier and VND Wallets; four manual Deposits; three discount promotions and three Buy A Get B promotions; five Suppliers; nine POs spanning draft, cancelled, partial and full receipt; eight Goods Receipts and three Purchase Returns; 25 Retail and 16 Dealer orders; settled Payments/Allocations, partial payment, physical Returns and four Refunds. Order activity spans today through approximately 82 days ago, including the last seven/thirty days and the previous month. Two normally purchasable SKUs start with no stock.

Order creation, confirmation, reservation, fulfillment, Payment, Refund, Promotion redemption, Dealer approval, Wallet Deposit/debit, Goods Receipt, Purchase Return and opening stock use the existing domain services. Demo SKUs also receive additive stock movements in an already configured default sales Warehouse when that Warehouse differs from the demo one; existing products and their stock are untouched. Appointment transitions use the Clinic service while notifications are faked for the seeding process so demo activity sends no messages. No PayOS settlement or webhook is fabricated. Deposits are Wallet funding, not Sales revenue. The seeder does not enable Automatic Tier policy.

## Verification

The focused test uses the disposable MySQL test database and seeds twice, checking existing data preservation, stable counts, Payment facts, Wallet/stock consistency, procurement and time distribution. After seeding the development database, run:

```bash
php artisan payments:reconcile-orders --dry-run
php artisan refunds:reconcile-orders --dry-run
php artisan dealer-wallets:reconcile --dry-run
php artisan sales-promotions:reconcile --dry-run
php artisan dealers:reconcile-auto-tiers --dry-run
php artisan returns:reconcile --dry-run
php artisan inventory:reconcile
```

`inventory:reconcile` is intrinsically read-only and has no `--dry-run` option. There is no Procurement reconciliation command in the current project; the focused test checks Goods Receipt/Purchase Return movement links and PO status. PayOS requery is deliberately omitted because it can call the live provider.

On 2026-09-28 the focused MySQL seed test passed 1 test / 36 assertions; the subsequent full backend suite passed 566 tests / 4,249 assertions. TypeScript, ESLint (0 errors, 18 existing warnings), Bun tests (8 / 30) and production build passed. Development dry runs reported 0 Payment, Refund, Wallet, Promotion, Return or Auto Tier anomalies; Inventory matched 56 balances and 89 movements. PayOS Top-Up reconciliation checked 0 requests, so it made no provider call.

## Limits

Historical times are assigned when demo Sales Orders are created; new same-day Orders are placed before the seeding time. Reruns keep original dates rather than rolling the dataset forward. Completed ledger facts are never rewritten for timestamp corrections. An early local seed on 2026-09-28 generated three same-day demo Orders with later clock times; they remain identifiable as demo facts and are preserved. Existing manually changed demo records are not forcibly reset. Dealer orders use the initial Tier price list; if an enabled Auto Tier policy changes their effective Tier, additional Dealer price configuration may be needed for later new orders. No real provider settlement, real patient data, certification claim, or external image asset is created.
