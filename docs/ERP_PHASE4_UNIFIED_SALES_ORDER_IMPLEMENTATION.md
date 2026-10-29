# ERP Phase 4 — Unified Sales Order Core

## Scope and starting state

Phase 4 adds **one** Sales Order engine: `sales_orders`, `sales_order_items`, Retail price snapshots, inventory reservations, explicit state transitions, shipment movements, Admin APIs/UI, and reconciliation. Retail Checkout, Dealer Quick Order, and Dealer Excel Import can later call the same service. The production Phase 4 create path is **Retail/Admin only**; Dealer Account does not exist and the service returns `UNSUPPORTED_CHANNEL` for Dealer creation. No `dealer_account_id` placeholder is stored; a real FK can be added in the Dealer Account phase.

The initial Git tree already contained uncommitted ERP architecture, Phase 2 Product/Pricing, Phase 3 Warehouse/Inventory, and Add Product wizard changes. They were preserved. This phase did not create a commit. The wizard's earlier Dealer Tier master/pricing configuration predates Phase 4 and does not expose Dealer ordering.

## Schema

Migration `2026_09_24_085113_create_sales_orders_and_inventory_reservations.php` creates four tables:

| Table | Purpose and constraints |
| --- | --- |
| `sales_orders` | Unique immutable `ORD` code derived from inserted primary key; unique creation key/fingerprint; Retail/Admin channel/source; buyer User FK; one Warehouse FK; recipient and shipping snapshots; VND currency; independent order, payment, fulfillment states; nonnegative stored totals; actor/timestamp fields. Historical FKs restrict deletion. |
| `sales_order_items` | Unique order/SKU pair, Product/SKU/variant/Unit and authoritative Retail price/source snapshots, exact decimal quantity, line amounts. Item and price source FKs restrict deletion. |
| `inventory_reservations` | One row per order item; original, consumed, and released decimal quantities; remaining commitment is derived as original minus consumed minus released. Nonnegative CHECK and unique item/operation key. |
| `sales_order_operations` | Unique idempotency key per transition, operation type and payload fingerprint. |

Migration `2026_09_24_090416_enforce_sales_order_code_immutability.php` adds a trigger that permits only the initial temporary-to-PK-derived code update and rejects later changes. Both migrations are additive and have rollback methods. They ran in development batches 26 and 27. An initial migration attempt failed at a MySQL index-name length limit; the three just-created, empty tables were verified empty and removed before rerunning the corrected migration. No business rows were deleted or seeded.

## Buyer, recipient, warehouse and money

`buyer_user_id` is a real User identity. Recipient name/contact/address are an independent shipping snapshot; creating an order never creates or merges a User/Customer. An Admin explicitly chooses one active Warehouse. Inactivation later preserves order history. Currency is VND; no conversion exists.

The backend uses `RetailPricingService` for each active Product and Retail-sellable, inventory-tracked SKU. It rejects missing Retail price without Dealer fallback. Quantity must match the SKU Unit precision. Duplicate SKU lines are rejected. The Phase 4 V1 limit is inventory-tracked Retail SKUs only; non-stock fulfillment has not been defined. No frontend price, subtotal, grand total, or price-list ID is accepted. Base and grand totals use BCMath decimal arithmetic; discount, tax and shipping are zero because those engines are not in scope. Price/source/quantity fingerprints detect a stale Draft at confirmation.

## State transitions

| Command | Allowed source | Result |
| --- | --- | --- |
| Create Draft | New | `draft / unpaid / unfulfilled`; snapshots current Retail prices; no stock reservation or movement. |
| Reprice | Draft | Re-resolves current Product/SKU and Retail price; replaces Draft snapshots/totals; no reservation. |
| Confirm | Draft | Re-resolves and compares price fingerprints, checks active Warehouse and all available balances, reserves all items atomically; `confirmed / unpaid / reserved`. Changed price returns `ORDER_PRICE_CHANGED`, shortage returns `INSUFFICIENT_STOCK` with SKU/requested/available/warehouse. |
| Cancel | Draft or unfulfilled Confirmed | Draft: status only. Confirmed: releases remaining commitments and reduces reserved quantity without changing on-hand. Requires actor, reason and operation key. After any fulfillment, whole-order cancellation is rejected. |
| Fulfill | Confirmed or Processing | Consumes requested reserved quantities, reduces both on-hand and reserved, writes one immutable negative `SALES_ORDER_SHIPMENT` stock movement per line and audits consumption. Partial shipment sets `processing / partially_fulfilled`; final shipment sets `completed / fulfilled`. |

Payment remains `unpaid`; no API can mark paid/refunded. There is no generic status PATCH. No automatic reservation expiry is configured. Cart does not exist and would not reserve; only Confirm reserves.

Transactions lock the order first, then Warehouse, and iterate SKU/reservation/balance keys in ascending Product Variant ID order (one Warehouse per order). The inventory domain service owns reservation and shipment balance writes. All-item availability is checked before writing any reservation. MySQL balance CHECK constraints and row locks prevent oversell/overconsumption. Unique keys and transition records make repeated create/confirm/cancel/fulfill requests idempotent; changed payload under an existing key conflicts. The stock movement table's existing UPDATE/DELETE triggers also protect shipment movements. Audits record order and reservation transitions without copying recipient PII.

Confirm also locks referenced Product rows and current Retail Price List rows before its authoritative re-resolution, consistent with the Retail Pricing edit path. This serializes relevant Product status and Retail price edits against the confirmation check. Inventory locking then follows the Warehouse and ascending SKU order.

The physical reconciliation remains `expected_on_hand = SUM(stock_movements.quantity)`. Reservations do not make physical movements. `ReservationReconciliationService` reports each Warehouse/SKU's expected remaining reservation, actual balance reserved, and difference without repairing it. It is exposed read-only to Admin at `GET /api/admin/sales-orders/reservation-reconciliation`.

## API and Admin UI

All routes use existing `auth:sanctum` plus `admin` middleware:

- `GET/POST /api/admin/sales-orders` — paginated, filtered list and idempotent Draft creation.
- `GET /api/admin/sales-orders/{order}` — eager-loaded detail with commercial and recipient snapshots, items and reservations.
- `POST /api/admin/sales-orders/{order}/{reprice|confirm|cancel|fulfill}` — explicit commands.
- `GET /api/admin/sales-orders/buyers` — bounded customer User lookup for the Admin create form.
- `GET /api/admin/sales-orders/reservation-reconciliation` — read-only reservation ledger check.

The Admin routes `/admin/sales-orders`, `/admin/sales-orders/new`, and `/admin/sales-orders/:id` provide list/search/status filter/pagination, real buyer/Warehouse selection, recipient and SKU entry, commercial snapshots, reprice, confirm, cancel, and partial/final shipment controls. Confirm, cancel and fulfill ask for confirmation. A stale-price conflict offers reprice; shortages display the server's SKU/requested/available details. Frontend sends operation keys for retries and never sends authoritative prices. Other current roles and public clients cannot use these Admin endpoints.

## Verification and known limits

Focused feature tests cover Draft snapshots/no reservation, order code and creation idempotency, recipient distinction, missing price/Dealer fallback rejection, inactive Product/SKU/Warehouse, Unit precision, duplicate SKUs, client-price prohibition, stale price/reprice, atomic reservation, shortage, cancellation, partial/final shipment, immutable shipment and code, authorization, filters and read-only reconciliation. A guarded MySQL subprocess test resets **only** `aesthetic_clinic_testing` and exercises competing confirms for the last stock, duplicate confirm, duplicate fulfillment, and cancel/fulfill races. Physical and reservation reconciliation remain clean after valid shipments.

Phase 4 deliberately does not implement Cart, Retail Checkout, My Orders, Guest Checkout, Dealer Account/ordering/import, Payment, Refund, Return, Promotion, Warehouse transfer, lot allocation, Clinic Material Usage, Supplier or Procurement. Dealer tier configuration that was already present in the wizard remains separate from this Retail-only Sales Order path.

Final acceptance: Phase 4 focused backend tests passed (11 tests/129 assertions, including guarded MySQL concurrency). The combined Sales Order and Product/Pricing targeted run passed (32 tests/357 assertions); the full backend suite passed (405 tests/2,441 assertions). Pint and `git diff --check` passed. Frontend TypeScript, ESLint (zero errors and the 18 existing Fast Refresh warnings), Bun wizard tests (7 tests/27 expectations), and production build passed. Development server HTTP smoke returned 200 for Admin list, new, and detail routes; authenticated behavior is covered by API feature tests rather than browser automation. Final Git audit found Phase 4 Sales Order files plus the previously uncommitted ERP architecture, Phase 2/3, and wizard work, with no new out-of-scope Commerce workflow. No commit was created.
