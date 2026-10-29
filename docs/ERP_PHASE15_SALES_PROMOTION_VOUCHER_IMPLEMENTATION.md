# Phase 15 — Sales Promotion / Voucher Engine

## Scope and dependencies

Phase 15 applies one code based Sales promotion after authoritative Retail or Dealer pricing and before settlement. It depends on Product/SKU and Retail pricing (Phase 2), unified Sales Orders (Phase 4), Retail Checkout (Phase 5), Dealer pricing (Phase 8), Quick Order (Phase 9), Excel Import (Phase 10), Payment (Phase 11), Return/Refund (Phase 12), and Dealer Wallet (Phase 13). PayOS Wallet Top-Up (Phase 14) is separate and never receives a promotion.

Clinic appointment, review, and loyalty `vouchers` remain in their own domain. The Sales promotion tables are named `sales_promotions`, `sales_promotion_targets`, and `sales_promotion_redemptions`. A Retail cart retains its historical `voucher_code` field as draft input; it now resolves a **Sales promotion code** for commerce checkout. Historical Sales Orders with the previous Clinic voucher snapshot remain readable. No Clinic voucher records were migrated or reused as Sales promotions.

## Rules and financial authority

- Admin enters a unique code or asks the backend to generate one. Matching uses trimmed uppercase `normalized_code`; the database uniquely constrains it. The normalized code cannot change after any redemption.
- Date entry follows the application's `Asia/Ho_Chi_Minh` timezone. The backend checks validity with its own clock; browser time never determines eligibility.
- V1 accepts percentage (`>0`, `<=100`) or fixed amount (`>0`), an optional percentage cap, minimum **pre-discount** order subtotal, active dates, Retail/Dealer/both scope, total and per buyer usage limits, and optional Product or Category targets. No targets means all priced lines. A Product target covers its SKUs. Dealer usage belongs to the Dealer Account, not the member User; Retail usage belongs to the authenticated buyer User.
- RetailPricingService or effective Dealer Tier pricing and MOQ resolve the base unit price first. The promotion service sees trusted line amounts and Product IDs. It never changes a Price List, Tier, SKU price, Wallet Top-Up, or inventory rule. Frontend and XLSX supply only a code; submitted discount, price, total, and scope fields are rejected by their existing request validation.
- The eligible line subtotal determines the discount. A fixed amount is capped at eligible subtotal. `PromotionDiscountAllocator` distributes cents in proportion to eligible gross line amounts using largest remainders and stable ascending variant ID ties. The sum of item discounts equals the Order discount; no item discount exceeds its gross amount. `sales_orders` retains code/name/type/value and discounted total snapshots; `sales_order_items` retains base unit/line values, allocated discount, and net line total. Historical Orders never reprice when Admin changes a promotion.
- A percentage that rounds to zero at the current two-decimal money precision fails with `PROMOTION_DISCOUNT_TOO_SMALL`; it does not consume a usage slot for a zero-value discount.
- A zero total Retail or Dealer Order has `payment_status=unpaid`, `paid_amount=0`, and `outstanding_amount=0` until a real settled Payment exists. It creates no Wallet debit, Payment, or Payment Allocation, including no zero-value financial fact. Order confirmation and fulfillment follow the Sales Order state machine independently of settlement status. Positive Dealer Orders debit the final discounted grand total and create a settled `dealer_wallet` Payment and Allocation of that same amount. Retail settlement also uses the discounted Sales Order total. This retains the Phase 11 ledger semantics.

If an older Phase 15 build created a zero-total Order with a cached `paid` marker and no settled Payment, `payments:reconcile-orders --dry-run` reports it as an unsupported legacy marker. It must be reviewed against actual settlement evidence before correcting the cached status; reconciliation does not invent a Payment or silently rewrite that marker.

## Workflow and concurrency

- Retail cart apply, Retail checkout review, Dealer Quick Order review, and Excel preview only quote a code. They create no redemption or reservation. The review fingerprint includes promotion rule state, targets, and discount, so a changed rule cannot silently change the submitted total.
- Final checkout or Dealer submit recalculates under the Sales Order transaction. `SalesPromotionService::redeem` locks the Promotion, rechecks time/scope/targets/limits, writes line/order snapshots and one unique Redemption, then the existing Order reservation and Dealer payment path complete in that transaction. MySQL locking reads on redemptions prevent an old repeatable-read snapshot from admitting a second claim on the last use. Idempotent order retries reuse the original Order and never add a second redemption or Wallet debit.
- The canonical Excel template adds optional text `Voucher Code`. Every row sharing an External Order Ref must carry the same normalized code; otherwise its group reports `ORDER_GROUP_PROMOTION_MISMATCH`. Existing templates without the column remain valid. Invalid codes appear as group errors. Preview and batch Wallet requirement use discounted totals. Confirmation revalidates each group through Quick Order; successful groups remain committed if another group loses promotion availability.
- Only a successful Order cancellation releases redemption usage (`redeemed` → `released`) inside the cancellation transaction. Refund or Return alone does not release it. Orders retain promotion snapshots after cancellation. MySQL triggers prevent altering redemption financial/identity snapshots or deleting redemptions; only the one way release status transition is allowed.

## Return and refund

Return items record `return_value_snapshot`, a prorated share of the historical **net** Sales Order item line. Cumulative returned quantity determines cumulative net value; the final quantity absorbs the cent remainder. Earlier completed return value is subtracted, so successive partial returns cannot exceed the line's net total. Linked refunds use this value, with a legacy gross fallback for returns recorded before Phase 15. Overall refunds remain limited to actual settled Payments minus completed Refunds. `refunds:reconcile-orders` uses the same net snapshot.

## Admin, customer and dealer surfaces

- Admin: `GET/POST /api/admin/sales-promotions`, `GET/PUT/PATCH /api/admin/sales-promotions/{promotion}`, generated code, and activate/deactivate endpoints. Management is in `/admin/sales-promotions`, with list, search, status/scope filters, usage, create/edit fields, and Product/Category selection. Clinic voucher pages stay separate. Admin changes and redemption/release actions are audit logged.
- Retail: Cart code apply/remove, eligibility and discount summary; checkout review and Order detail display the discounted total and safe immutable promotion snapshot.
- Dealer: Quick Order code entry, discount, final Wallet requirement, projected Wallet balance or shortfall; Excel preview shows each group code, base total, discount, and final total; Dealer Order detail shows the snapshot. Account selection still scopes Wallet and per Dealer usage.
- Error responses use structured `PROMOTION_*` or `ORDER_GROUP_PROMOTION_MISMATCH` codes. The Admin API does not expose redemption buyer identities to Retail/Dealer clients.

## Reconciliation and operations

Run `php artisan sales-promotions:reconcile --dry-run` to audit Redemption↔Order linkage, channel/buyer/Dealer identity, code/discount snapshots, item allocation sums and bounds, cancellation release status, and total/per buyer usage limits. `--apply` remains audit only: it never invents redemptions or rewrites historical financial facts. An anomaly exits nonzero for operator review.

Apply the three Phase 15 migrations before using the Admin page or accepting codes: `2026_09_26_235631_create_sales_promotions_tables`, `2026_09_27_002655_guard_sales_promotion_redemptions`, and `2026_09_27_004945_tighten_sales_promotion_amount_checks`. They are additive. No production promotion is seeded. Existing code without a promotion continues through the same price, inventory, Wallet and Payment paths.

## Verification and limits

Focused tests cover normalized/duplicate codes, Admin authorization and edits, targets, percentage cap and dates, Retail and Dealer order snapshots, usage release, idempotency, Excel group rules, Wallet settlement, net partial Return/Refund, zero total, and real MySQL competition for the last use between Quick Order users and between Excel and Quick Order. After the final zero-total test was added, Promotion-focused tests passed **10 tests / 153 assertions**. The targeted Retail, Dealer, Excel, Wallet, PayOS, Payment, Return/Refund, Inventory, Clinic voucher and MySQL concurrency regression passed **183 tests / 1,828 assertions**. The subsequent full backend suite passed **542 tests / 3,934 assertions**. TypeScript, ESLint (0 errors; 18 existing Fast Refresh warnings), production build and Bun tests (**8 tests / 30 assertions**) passed. Development `sales-promotions:reconcile --dry-run` reported 0 anomalies and `payments:reconcile-orders --dry-run` reported 0 mismatches, 0 legacy markers and 0 structural anomalies; the development database had 0 Sales Orders at this check. Pint and Git whitespace audit passed.

Desktop/mobile browser visual QA remains unverified in this environment because no browser surface is available to the automation session. Responsive layouts were reviewed in source, and TypeScript, ESLint, frontend tests and the production build passed. This visual acceptance check remains open.

V1 supports one manually entered code per Order. It does not implement stacking, automatic best promotion, BOGO, free gifts, shipping vouchers, loyalty or membership points, referral, campaigns, promotion budgets, mixed tender, Retail Wallet, withdrawal, Wallet transfer, credit terms, or Auto Tier. No live provider integration is needed for Phase 15; PayOS Top-Up remains unchanged.
