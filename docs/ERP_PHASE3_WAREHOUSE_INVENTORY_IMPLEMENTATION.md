# ERP Phase 3 — Warehouse and Inventory Core

## Scope and starting state

Phase 3 adds Warehouse, Inventory Balance, immutable physical Stock Movement, Opening Stock, Goods Receipt, Stock Adjustment, read-only reconciliation, and Admin APIs/UI. Phase 2 Product/SKU and Retail Pricing remain the source of SKU identity and pricing. The task began with the complete Phase 2 package and the preceding `ERP_CORE_DESIGN.md` edit still uncommitted. Those changes were preserved; no commit was created.

The implementation uses Laravel 13, PHP 8.4, MySQL 8.4, Sanctum Admin middleware, Eloquent read models, Laravel Form Requests, `AuditLogger`, TanStack Query, and the existing responsive Admin shell. The dedicated MySQL test database is `aesthetic_clinic_testing`.

## Schema and lifecycle

One additive migration creates:

| Table | Key fields | Integrity |
| --- | --- | --- |
| `warehouses` | normalized unique `code`, name, optional address/timezone/type, active/inactive status, explicit sales/clinic default flags | No delete API. Generated unique slots permit at most one **active** default per purpose; no first-row fallback. Code cannot change after a balance or movement exists. |
| `inventory_balances` | `warehouse_id`, `product_variant_id`, `on_hand_quantity`, `reserved_quantity` | Unique Warehouse/SKU; `DECIMAL(18,3)`; DB CHECK for nonnegative on-hand/reserved and reserved ≤ on-hand. Available is derived with decimal arithmetic. No quantity mutation API or normal Eloquent save/delete. |
| `stock_movements` | Warehouse/SKU, movement type, signed quantity, before/after, unique operation key, optional operational reference/reason, actor/source/time | `DECIMAL(18,3)`; DB CHECK for before + quantity = after and nonnegative snapshots. MySQL triggers reject UPDATE and DELETE. No update/delete API or normal Eloquent save/delete. |

All Warehouse/SKU/actor foreign keys preserve historical references. No Product or SKU stock column was added. No Warehouse or opening balance was seeded. Existing Warehouse/SKU history remains readable after an entity becomes inactive; new operations reject inactive Warehouse, inactive SKU/Product and `track_inventory=false`. Once stock history exists, Admin cannot change that SKU's identifier, Unit, or inventory-tracking flag. Product and SKU status can still be changed through their existing lifecycle.

There may be multiple Warehouse masters. Admin explicitly marks a default for sales or clinic if required; this phase never automatically chooses a sales warehouse. Warehouse operator assignments and scoped RBAC are deferred because only Admin performs these operations in V1.

## Movement and service contract

`InventoryService` is the only application write path for on-hand quantity. It supports:

- `OPENING_BALANCE`: positive signed quantity; only when current on-hand is zero **and** no earlier physical movement exists for Warehouse/SKU. A repeat with a new key returns an explicit conflict.
- `GOODS_RECEIPT`: positive signed quantity; optional free operational reference, without a Purchase Order or Supplier entity.
- `ADJUSTMENT_IN`: positive signed quantity and required reason code/detail.
- `ADJUSTMENT_OUT`: negative signed quantity and required reason code/detail; rejects an after value below zero or below reserved.

Every successful operation satisfies `after_on_hand = before_on_hand + movement.quantity`. Quantity uses string decimal/BCMath, never floating point, and is checked against the SKU Unit's `decimal_precision` (0–3). Corrections use a new reasoned Adjustment, optionally referencing an earlier movement; historical movements cannot be edited. There is no generic reversal workflow.

Each command requires a client-generated UUID operation key. A retry with the same key and identical Warehouse/SKU, signed quantity, type, reason, reference and actor returns the original movement without another balance change or audit entry. Reusing the key with different content returns `OPERATION_KEY_CONFLICT` (HTTP 409). The unique database index is the final race guard.

Writes use short MySQL transactions. The service locks the Warehouse row, checks active state, locks the SKU row, safely inserts a zero balance under the unique Warehouse/SKU key, then locks the balance row and rechecks all invariants. The Warehouse lock serializes first balance creation and operations for that Warehouse; this is intentionally coarse in V1. Future multi-SKU commands must acquire locks in ascending `(warehouse_id, product_variant_id)` order. Movement insert, balance update and `AuditLogger` entry commit atomically. Opening, manual adjustment, receipt and Warehouse status changes are audited separately from the mathematical ledger.

`reserved_quantity` remains zero in normal Phase 3 writes. The CHECK constraint and adjustment rule already protect `reserved ≤ on_hand`, but **there is no Inventory Reservation workflow or reservation ledger yet**. No Sales Order, Cart, Checkout, Dealer, transfer, lot allocation, or Clinic Material Consumption was implemented.

## Reconciliation

`InventoryReconciliationService` compares every balance with grouped movement sums using set-based queries:

`expected_on_hand = SUM(stock_movements.quantity)`; `difference = actual_on_hand - expected_on_hand`.

It reports matched/mismatched balances, missing balances, movement count and orphan movement count. It does not repair anything. `php artisan inventory:reconcile --no-interaction` prints a read-only summary; `--warehouse=ID`, `--sku=ID`, and `--fail-on-difference` are available. The Admin reconciliation API uses the same service. A mismatch requires investigation and an explicit controlled correction; no automatic direct balance update is offered.

## Admin API and UI

All routes are under `auth:sanctum` + `admin`:

- `GET/POST /api/admin/warehouses`, `GET/PATCH /api/admin/warehouses/{warehouse}`: search, status, sort and pagination; create/edit/status lifecycle.
- `GET /api/admin/inventory`: paginated Warehouse/SKU balances, Product/Unit, on-hand, reserved, derived available, last movement and derived low-stock; Warehouse/SKU/Product/status/search filters.
- `POST /api/admin/inventory/opening-stock`, `/receipts`, `/adjustments`: explicit commands; client before/after/reserved fields are prohibited.
- `GET /api/admin/stock-movements`, `/stock-movements/{movement}`: read-only paginated history with Warehouse/SKU/type/date/reference/actor filters.
- `GET /api/admin/inventory/reconciliation`: read-only ledger comparison with optional Warehouse/SKU filter.

`/admin/warehouses` manages the master and explicit defaults. `/admin/inventory` displays balances, low-stock flags, read-only movement history, reconciliation and operation forms. Each stock operation has a confirmation step showing Warehouse, SKU, current on-hand, signed change, projected on-hand and reason. Laravel recalculates and revalidates all values after confirmation. The UI uses the real API and keeps one operation key across a retry of the same unchanged command. Public Product APIs are unchanged and expose no exact stock quantity.

## Verification and limits

The focused MySQL feature tests cover Warehouse uniqueness/defaults/status, opening/receipt/adjustment and their signed ledger, idempotency, negative/reserved safety, Unit precision, inactive/untracked SKUs, authorization, API filtering, immutable movement triggers, derived stock, reconciliation mismatch/missing-balance reporting, and public Product stock privacy. A dedicated MySQL concurrency test starts independent PHP processes for competing first receipts, concurrent adjustments, near-zero Adjustment Out, and same-key retries. It resets **only** the explicitly checked disposable `aesthetic_clinic_testing` database before/after execution because a legacy Booking migration intentionally refuses rollback. No test performs a destructive reset on the development database.

The migration was applied to the development database in batch 24 without inserting any Warehouse or stock records. `inventory:reconcile --fail-on-difference` reported zero balances, movements and differences there. Focused Product/Pricing + Inventory tests passed 25 tests/281 assertions. The full backend suite passed 385 tests/2,216 assertions. Pint passed. Frontend TypeScript, ESLint and production build passed; ESLint reported zero errors and the same 18 pre-existing Fast Refresh warnings. Vite development HTTP smoke returned 200 for `/admin/warehouses`, `/admin/inventory` and `/products`; the temporary server was stopped. The frontend has no dedicated browser-test framework, so authenticated form interaction was verified through API feature tests and frontend static/build checks rather than an automated browser login.

An attempted `vite preview` smoke returned 500 on every page, including the unchanged `/products` route, because that preview command expected `dist/server/server.js` while this project's build writes Nitro output under `.output`. This was a preview-command mismatch, not an Inventory route failure; the supported Vite development server returned 200 on all three routes.

Known V1 limits: no Warehouse operator role/scope, no automatic sales-warehouse selection, no bin/lot/expiry allocation, no warehouse transfer, and no bulk opening import. Reconciliation returns a complete read-only report rather than a paginated one. Unit definition changes after historical movements require operational care; the SKU's Unit identity itself is locked once stock history exists. Opening Stock is a one-time controlled operation per Warehouse/SKU.

## Next phase

Unified Sales Order Core is the next recommended phase. It was not started here.
