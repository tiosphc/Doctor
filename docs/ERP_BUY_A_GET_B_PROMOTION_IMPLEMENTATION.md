# Buy A Get B / Free Gift Sales Promotion

## Status and dependency

This implementation extends the existing Phase 15 Sales Promotion engine. It reuses `sales_promotions`, redemption/usage limits, the Cart voucher code, Dealer Quick Order and Excel promotion code, Sales Order, Inventory Reservation, Payment and Return services. It does not create a second promotion authority or a duplicate Product/SKU. The migration has been exercised by automated tests against disposable MySQL and was applied to the local development database on 2026-09-28 for demo Gift seeding.

## Data model

Additive migration: `backend/database/migrations/2026_09_28_102009_add_buy_a_get_b_sales_promotions.php`.

- `products.can_be_gift` permits a real Product to be given away. `products.gift_only` also requires `can_be_gift` and excludes the Product from normal Retail/Dealer catalogs, pricing and direct purchase.
- `sales_promotions.discount_type=buy_a_get_b` uses `discount_value=0` and the existing scope, schedule, status and usage limits. Percentage/fixed rules remain supported.
- `sales_promotion_gift_rules` has one rule per Promotion: Buy Product, optional exact Buy SKU, minimum paid quantity, exact Gift Product/SKU, Gift quantity and repeat flag. `sales_promotion_dealer_tiers` optionally targets Effective Dealer Tiers.
- `sales_order_items.is_gift` and `source_promotion_id` mark the real physical Gift line. The unique key includes `is_gift`, so a paid and a free line may share the same SKU. Database constraints require a Gift line to have zero unit price, base, discount, tax and net value.
- `sales_orders.promotion_gift_snapshot` records the rule used at purchase time for historical Return entitlement. Gift Order Item and promotion redemption remain separately traceable.

Rollback intentionally refuses while historical Gift Orders or Buy A Get B promotions exist. Production deployment must run the additive migration before serving the new API/frontend.

## Rules and financial behavior

Admin selects a normally purchasable Buy Product, optionally one exact Buy SKU, and one exact active inventory-tracked Gift SKU belonging to a Product marked `can_be_gift`. An inactive or mismatched SKU is rejected. A Product may be sold and gifted; a `gift_only` Product is never accepted as a paid line. For `both` scope, the Buy selection must be sellable in both channels (a Product-wide rule may use separate eligible SKUs per channel).

Only paid Buy-line quantities qualify. Exact decimal arithmetic calculates `floor(paid_quantity / minimum_buy_quantity) × gift_quantity` when repeat is on; when off, the configured Gift quantity is awarded once at the threshold. A Gift line never qualifies itself. One Order applies one promotion code; there is no promotion stacking or automatic best-promotion selection. A below-threshold code is shown in review as progress and creates no redemption/Gift when submitted.

The server derives the Gift from the saved promotion rule. Client fields and XLSX columns cannot supply the Gift or its zero price. Cart, checkout, Quick Order and Excel review show the derived Gift; submit revalidates the current Product, Tier, price, usage and stock state. The Gift does not change subtotal, discount, grand total, MOQ, Dealer Wallet debit, Payment, Payment Allocation, Refund value or Dealer net settled revenue. Paid goods retain their authoritative Retail/Dealer prices. A Gift-only SKU needs no Retail/Dealer price list entry.

Retail Cart now automatically selects the first eligible active Gift offer by promotion code when no voucher was entered and the paid quantities and Gift stock qualify. The Gift appears in Cart and Checkout Review without code entry, and Checkout revalidates the same code on the server before reserving stock. A customer-entered voucher takes precedence and replaces the automatic Gift offer for that Order. If Gift stock or eligibility disappears, an unselected automatic offer does not block purchase of the paid items; Checkout detects any changed review fingerprint.

## Inventory, cancellation and Returns

Retail Gift checkout atomically creates the Order, redemption, physical Gift item and reservations before returning the pending Order. Dealer Quick Order and Excel use the shared Dealer confirmation path, with Order, both reservations, Wallet debit and settled Payment/Allocation in one transaction. Demand is aggregated per SKU, including when paid and Gift lines use the same SKU. Unavailable Gift stock fails the promotion/order; it never creates a negative balance or unreserved Gift. Shipment consumes each reservation and writes the ordinary shipment Stock Movement once. Cancellation releases remaining paid/Gift reservations and promotion usage through the existing state machine.

After shipment, a Gift may be physically returned/restocked; its historical financial return/refund amount is zero. Returning paid qualifying A is rejected with `GIFT_RETURN_REQUIRED` if the remaining Gift quantity would exceed entitlement calculated from the immutable Order snapshot. The caller must include enough Gift quantity in the same Return. Refund alone never restocks inventory. Historical Return logic does not read mutable current promotion settings.

## API and UI

- Existing Admin Product create/edit/wizard and list expose Gift flags/filter; the Product Wizard clears sellable flags and price rows for Gift-only Products.
- Existing Admin Sales Promotion APIs and `/admin/sales-promotions` support rule, Tier targets, active/inactive control, Gift unit totals, returned units and a bounded list of related Orders.
- `GET /api/gift-promotions` lists active Retail Gift offers. Authenticated `GET /api/dealer/accounts/{dealer}/gift-promotions` applies the Dealer's Effective Tier. Public and Dealer Product cards/details display eligible offers, while `/promotions` and `/dealer/promotions` provide offer listings. List visibility also checks active Products/SKUs and Gift stock; the submit path remains authoritative.
- Retail Cart/Checkout/My Orders and Dealer Quick Order/Excel/Order details display the server-derived Gift line and its zero price. Dealer promotion pages support account switching.

## Reconciliation and security

`php artisan sales-promotions:reconcile --dry-run` checks the existing redemption, allocation and usage invariants plus the Gift rule snapshot, Gift item relation, quantity and zero monetary values. The command does not rewrite financial or usage records. `inventory:reconcile`, `dealer-wallets:reconcile --dry-run` and `payments:reconcile-orders --dry-run` remain separate authorities for stock, Wallet and Payment facts. Admin Product/Promotion APIs keep Admin authorization; Dealer endpoints use account authorization. Price/Gift selection and stock checks stay server-side.

## Verification

Focused Gift, Excel, existing Promotion and real MySQL concurrency tests passed **34 tests / 405 assertions** after the final backend change. They cover rule validation, exact repeat calculation, Gift-only direct-purchase rejection, Retail/Dealer/Excel order creation, same-SKU stock, reservation/fulfillment, cancellation, Return clawback, Wallet amount, last-Gift race and normal-sale-versus-Gift race. The full backend suite after the final code/test change passed **576 tests / 4,354 assertions** on MySQL. Frontend TypeScript and production build passed; ESLint reported zero errors and 18 existing Fast Refresh warnings; Bun passed **9 tests / 34 assertions**. Pint and Git whitespace audit passed. No development migration, seed or automatic commit was performed.

Read-only reconciliation against `aesthetic_clinic_testing` passed for Inventory, Payment, Refund and Sales Promotion with zero anomalies. The disposable testing database contained zero business rows after the full suite, so these CLI runs verify command/schema integrity but are not a populated-data reconciliation. The Gift feature test also ran `sales-promotions:reconcile --dry-run` against a fulfilled/returned Gift Order and passed. Subsequently, the user requested Gift demo data, and the migration was applied to the local development database on 2026-09-28. `DemoGiftPromotionSeeder` added three Gift-only SKUs with 60, 50 and 40 units of opening stock and three active Buy A Get B codes; the populated development Sales Promotion dry run reported zero anomalies.

Acceptance status: **PARTIAL** only because authenticated desktop/tablet/mobile visual QA of the new UI was not performed. The implementation and automated acceptance gates above passed; live browser layout and interaction remain unverified.

## Known limits

- One Promotion code per Order and one Gift rule per Buy A Get B promotion; no stacking or automatic best-offer optimizer.
- Offer listing is informational. It does not reserve stock and may become stale before checkout; submit revalidates and fails closed.
- No live browser session was used for visual desktop/mobile acceptance. Automated frontend checks cover compilation, lint and existing Bun tests, but do not prove the rendered layouts.
