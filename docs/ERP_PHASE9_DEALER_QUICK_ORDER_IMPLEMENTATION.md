# ERP Phase 9 — Dealer Quick Order and unified Sales Order

## Scope and architecture

Phase 9 adds authenticated Dealer Quick Order to the existing `sales_orders`, `sales_order_items`, `SalesOrderService`, `InventoryReservationService`, and Admin fulfillment path. The buyer is the authenticated Customer-role User; the commercial owner is an active Dealer Account in which that User has active membership. Orders are scoped to the Account, so active coworkers can see the same history. Retail Cart, Retail Checkout, public Product catalog, and Retail pricing retain their existing paths. There is no `dealer_orders` table or Dealer Cart.

## Schema and historical snapshots

Migration `2026_09_24_161938_add_dealer_sales_order_support.php` adds nullable `sales_orders.dealer_account_id` with a restricted foreign key, immutable-at-creation Dealer code/name and effective Tier ID/code/name/source/override snapshots, and `sales_order_items.minimum_quantity_snapshot`. Indexed Account/status/date and channel/source/date access supports Dealer history and Admin filters. MySQL checks require Dealer Account and Dealer pricing context on Dealer orders, Retail pricing context and no Dealer Account on Retail orders, and positive non-null MOQ. Existing Retail rows remain valid. Rollback refuses to discard snapshots while Dealer orders exist. The shared Order retains selected price-list/item IDs, price-resolution fingerprints, unit price, line amount, Warehouse, recipient, buyer, state, and timestamps. Confirmed, cancelled, and completed history is never hard deleted by Phase 9.

## Authoritative Review and Submit

`POST /api/dealer/accounts/{dealer}/quick-order/review` accepts one to 50 distinct SKU variant IDs with positive Unit-precision quantities. It resolves active Dealer membership, effective Tier and override, the single active default sales Warehouse, active Dealer-sellable SKUs, Tier-specific VND prices/MOQ, and available stock. Product, price, and stock reads are batched. The response includes line errors, per-line price/MOQ/availability, totals, Dealer/Tier/Warehouse and recipient defaults; exact stock and internal price-list IDs are not exposed. The SHA-256 Review fingerprint covers Account/membership, Tier/override, Warehouse, SKU eligibility and selected Dealer price/MOQ facts. Review writes no Sales Order or reservation. Stock is checked again at Submit; a stock change alone does not silently change price.

`POST /api/dealer/accounts/{dealer}/quick-order` accepts those lines, the Review fingerprint, recipient/shipping fields, and a UUID operation key. It rejects client-supplied buyer, Account, Tier, Warehouse, price, totals, channel, and source. The backend derives all commercial authority. A short transaction locks Account/membership, Product and Dealer Price Lists, re-resolves current context/pricing/warehouse and Review, then creates a Dealer Draft and confirms it through `SalesOrderService`. Mismatched commercial Review returns `DEALER_ORDER_CHANGED` with a fresh Review. An invalid line, missing/ambiguous Dealer price, unmet MOQ, inactive SKU, or insufficient stock fails closed. Draft, confirmation, reservation and audit commit together; failure leaves no partial Order. Confirmation checks Dealer context and price fingerprints again. Shared reservation locks Warehouse and SKU balances in deterministic order; it increases reserved stock only. Admin fulfillment later emits the existing `SALES_ORDER_SHIPMENT` movement and deducts on-hand stock. Admin cancellation releases reservation when valid. No payment capture is implied; Order starts unpaid.

Creation operation keys are globally unique. Replaying the same key/payload returns the one committed Order; changing payload with the same key conflicts. Confirmation uses a deterministic derived operation key inside the same transaction. Retail Draft creation remains Retail-only in the Admin Request; Dealer Quick Order is the Dealer creation entry point. `SalesOrderService` now dispatches pricing and SKU sellability by channel and stores a Dealer MOQ snapshot without giving Dealer price to Retail.

## API, authorization, and UI

| Method | Route | Behavior |
| --- | --- | --- |
| POST | `/api/dealer/accounts/{dealer}/quick-order/review` | Read-only authoritative Review |
| POST | `/api/dealer/accounts/{dealer}/quick-order` | Atomic Dealer Submit and Confirm |
| GET | `/api/dealer/accounts/{dealer}/orders` | Paginated Account history, excluding Drafts |
| GET | `/api/dealer/accounts/{dealer}/orders/{order}` | Account-scoped historical detail |

The Dealer history resource returns safe Dealer/Tier/Product/price/MOQ/recipient snapshots and state, without Admin internals. Active Customer-role members can view their Account's orders, including orders placed by coworkers; a different Account or inactive membership receives 404. Dealer self-cancel and fulfillment are unavailable. Admin Sales Order list supports `sales_channel`, `order_source`, and `dealer_account_id` filters; existing Admin detail, cancel and fulfill endpoints handle Dealer orders. Admin UI shows Dealer identity/Tier and Dealer price/MOQ snapshots.

`/dealer/quick-order` provides Account selection, SKU search from the priced Dealer catalog, multiple lines, MOQ-prefilled editable quantities, Review and recipient editing. A changed Review refreshes the current data and requires another explicit Submit; a pending request disables another click. The success page loads the committed Order by ID. `/dealer/orders` and its detail route show Account history and snapshots. Dealer navigation, profile and Product detail link to Quick Order; desktop, tablet and mobile layouts use responsive Tailwind grids and wrapping. The UI calls real Laravel APIs and has no fake success state.

## Verification and operational notes

Feature tests cover read-only Review, confirmed snapshots, no Retail fallback, MOQ/stock/price errors, spoof rejection, changed price/Tier/override/Warehouse, suspended Account and membership, replay, Account isolation, coworker history, Admin filters/detail/fulfill/cancel, and shared inventory effects. Guarded multi-process MySQL tests use only disposable `aesthetic_clinic_testing` and exercise last stock, double Submit, price edit, Tier change and suspension races. The development database was migrated additively; no business Dealer Account, Tier, Warehouse, Product, Price or Order was seeded. Frontend checks cover TypeScript, ESLint, production build and existing Bun tests. Automated authenticated browser interaction was not available; responsive behavior was checked from component classes and route contracts.

Dealer Cart, Dealer order Excel/CSV Import, Payment, credit/Công nợ, automatic revenue Tier, Promotion, Return/Refund, Warehouse Transfer, Lot/Batch/FEFO, procurement and Clinic Material Usage are **not implemented**. The pre-existing Product pricing editor's Dealer price CSV helper is separate from order import. Notifications were not added because there is no existing safe Dealer Order notification path. A real Dealer UI smoke requires an Admin configured initial Tier, approved active Dealer Account, Dealer-priced SKU and stocked default sales Warehouse. Phase 10 has not started.

## Final verification

- Additive migration ran in development batch 31; `migrate:status` reports it Ran. No Dealer business data was seeded.
- Focused Quick Order and Sales Order tests: 16 passed, 189 assertions. Guarded real-MySQL Quick Order concurrency: 5 passed, 38 assertions. Full backend regression: 470 passed, 3,112 assertions.
- Frontend TypeScript passed, ESLint has zero errors (the existing Fast Refresh warnings remain), production build passed, and Bun tests passed 8/8 with 30 expectations. Pint and `git diff --check` passed.
- Git audit preserved the pre-existing uncommitted Phase 5–8 and Admin changes. Phase 9 added only the schema extension, shared Order/Inventory changes, Dealer APIs/UI, Admin Order filters/display, tests and this document. No commit was made.
