# ERP Phase 5 — Retail Commerce

## Scope and baseline

Phase 5 connects the public Product Detail to a logged-in Retail Cart, Checkout Review, Checkout Confirm, and My Orders. Checkout is an adapter over the existing **one Unified Sales Order Core**. It calls `SalesOrderService::createDraft(..., source: 'cart')` and `SalesOrderService::confirm`; it does not create another order table or copy order reservation and fulfillment logic. The Git tree was clean before Phase 5. No commit is created by this implementation.

## Cart schema and lifecycle

Migration `2026_09_24_093835_create_retail_carts_tables.php` adds `carts` and `cart_items`. A stored generated `active_retail_user_id` with a unique index ensures at most one active Retail Cart per User in MySQL. `cart_items` has a unique `(cart_id, product_variant_id)` pair and positive decimal quantity CHECK. Cart aggregate child rows may cascade if a Cart is deliberately deleted; Product Variant and converted Sales Order references restrict deletion. The application does not delete converted Carts.

An authenticated User owns an active Retail Cart. Adding the same SKU increments its quantity; Update sets an absolute quantity. User row locks serialize Cart creation and mutations, while the database unique indexes defend against duplicate active Carts and duplicate lines. Remove and Clear change only Cart items. A successful Checkout marks that Cart `converted`, stores the resulting `converted_sales_order_id`, a unique Checkout operation UUID and payload fingerprint. The next Cart is created lazily on a later Cart read/Add. A failed Checkout leaves the submitted Cart active.

Cart stores SKU and quantity, **not authoritative price or totals**. Read-time preview uses `RetailPricingService` for active Retail SKUs and current quantity breaks. It reads available quantity (`on_hand - reserved`) only from the current active default sales Warehouse. Invalid lines remain visible with line errors, including inactive Product/SKU, non-Retail or unsupported non-stock SKU, missing price, and shortage. Add/Update validate active Retail eligibility, inventory tracking, Unit precision and current Retail price. Cart and Add to Cart create **no reservation and no Stock Movement**. The header badge counts Cart lines.

## Checkout Review and default Warehouse

`GET /api/retail/checkout/review` resolves exactly one active `is_default_sales` Warehouse. Missing or ambiguous configuration fails with `CHECKOUT_WAREHOUSE_NOT_CONFIGURED` or `CHECKOUT_WAREHOUSE_AMBIGUOUS`. Review returns current Retail prices, Warehouse availability, exact decimal preview totals, line errors, optional User-based recipient defaults and a SHA-256 fingerprint. The fingerprint covers User/Cart identity, sorted SKU/quantity, current Retail price/source and selected Warehouse. Stock availability is a preview, not a promise. Review creates **no Sales Order, reservation or movement**.

The backend does not accept a buyer ID, Warehouse ID, price, total, status or channel from the Retail Checkout request. Buyer is the authenticated User. Recipient name, phone, optional email and address are shipping snapshots; they may differ from the buyer and never create or alter a User or canonical Customer. Currency is VND, shipping/tax/discount totals remain zero, and payment remains unpaid.

## Checkout transaction, idempotency and races

`RetailCheckoutService` locks the User and active Cart, then referenced Product and Retail Price List rows in deterministic order, followed by the active default Warehouse. It re-renders the current Cart preview and compares the submitted Review fingerprint. Cart, price or Warehouse changes return `CHECKOUT_CHANGED` and require a fresh review; invalid lines and shortages return structured conflicts. It then calls the shared Sales Order Draft and Confirm commands in one outer database transaction. Nested service transactions participate in that transaction; if Confirm fails, the Draft and all reservation work roll back, so no user-visible failed Draft remains. Only after Confirm succeeds does the Cart convert. Confirm remains the sole inventory reservation authority and atomically reserves all lines or none.

The converted Cart is the durable Checkout operation record. A retry with the same operation UUID and payload fingerprint returns the same Sales Order. Reusing the key with another payload conflicts. The Cart-to-Order link, operation fingerprint, confirmed order and reservations commit together. Two competing buyers for the last unit cannot both confirm; same-User double submit cannot create a second order or reservation. There is no automatic reservation expiry.

## My Orders and authorization

The Retail API uses `auth:sanctum`. `GET /api/retail/orders` and `GET /api/retail/orders/{order}` scope every query to `sales_channel=retail`, `buyer_user_id=auth User`, and visible statuses `confirmed`, `processing`, `completed`, `cancelled`; Drafts are excluded. List is paginated and ordered by ID descending. Detail returns historical Product/SKU/Unit/price/recipient snapshots from `sales_orders` and `sales_order_items`, without Admin actors, audit fields or reservation internals. Cross-user detail returns 404. Retail My Orders is read-only; self-cancellation, fulfillment and payment changes are not exposed. Admin Sales Order routes remain separate and unchanged.

## API and frontend

- `GET /api/retail/cart`, `POST /api/retail/cart/items`, `PATCH/DELETE /api/retail/cart/items/{cartItem}`, `DELETE /api/retail/cart` — own Cart and read-time preview.
- `GET /api/retail/checkout/review`, `POST /api/retail/checkout` — server review and atomic Checkout Confirm.
- `GET /api/retail/orders`, `GET /api/retail/orders/{order}` — own paginated Retail orders and snapshot detail.

The public Product Detail now has a real variant-aware quantity/Add to Cart control. Anonymous visitors are directed to Login before adding; no anonymous Cart is created. `/cart`, `/checkout`, `/checkout/success/:orderId`, `/my-orders` and `/my-orders/:orderId` use real APIs, loading/error/empty states and responsive stacked mobile layouts. Checkout refreshes Review before showing a confirmation dialog. Changed review and stock errors are shown without silently charging a new price. Success displays the actual order code, total and unpaid state; order detail still checks ownership on the server. React Query keys include User ID for Cart/Review/orders and are cleared on logout.

## Verification and limits

Focused PHPUnit covers Cart ownership and mutations, precision/eligibility, read-time invalid lines, Review/no reservation, default Warehouse, source/buyer/recipient snapshots, shared Confirm, idempotency, changed Cart/price/Warehouse, shortage rollback, My Orders ownership and historical snapshots. Guarded multi-process MySQL tests use only the disposable `aesthetic_clinic_testing` database for last-stock Checkout, duplicate Checkout, price edit while Checkout waits, Cart edit while Checkout waits, and concurrent Add.

Final acceptance on 2026-09-24: Phase 5 feature tests passed (9 tests, 111 assertions); guarded MySQL concurrency tests passed (5 tests, 42 assertions); combined Retail Commerce, Phase 4 Sales Order and Product/Pricing tests passed (36 tests, 392 assertions). The full backend suite passed (419 tests, 2,594 assertions). Pint passed. Frontend TypeScript, ESLint (0 errors, 18 pre-existing Fast Refresh warnings), client/SSR/Nitro build and existing Bun tests (7 tests, 27 expectations) passed. All nine Retail API routes are registered and the Cart migration is applied in development batch 28. `git diff --check` passed; initial Git tree was clean and all resulting changes are within Phase 5. No commit was made. The responsive layouts were checked in source and build; authenticated visual browser interaction was not run in this session.

V1 does not implement Payment, Guest Cart/Checkout/Lookup, Dealer Account/ordering/pricing/import, Promotion, Return/Refund, shipping provider/tracking, Warehouse transfer, lot allocation or Clinic Material Usage. Phase 6 Dealer Application/Approval and Account/Membership is the next recommended phase; it is not started here.
