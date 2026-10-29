# ERP Phase 12 — Return & Refund Foundation

## Scope

Phase 12 separates physical Sales Returns from financial Refunds on the shared Retail/Dealer Sales Order. Admin records both operations explicitly. Retail owners and active Dealer Account members can read safe order summaries and history. Customer and Dealer self-service Return/Refund requests are not available.

The Phase 11 Payment ledger remains immutable. A Refund is a separate positive financial fact allocated back to settled Payment Allocations; it is never a negative Payment. A Return does not automatically create a Refund. A Refund does not touch stock. A Return restock does not move money.

## Existing facts audited

- Payments and Payment Allocations hold settled receipts. Phase 11 MySQL triggers reject edits/deletes after settlement and late allocation inserts. Payment status and paid/outstanding values derive from settled allocations.
- Sales Order Items keep historical unit-price and quantity snapshots.
- Inventory Reservation consumed quantity is the authoritative fulfilled quantity per Order Item. Shipment writes negative SALES_ORDER_SHIPMENT Stock Movements. Partial fulfillment is represented per reservation.
- Inventory Balances store on-hand and reserved quantities separately. Existing inventory reconciliation sums signed Stock Movements.

## Data model

Additive migration 2026_09_25_142652_create_returns_and_refunds.php adds:

- sales_returns: immutable business code (RET + ULID), Order, original Warehouse, reason/note, actor, UUID operation key, fingerprint, status and completion time.
- sales_return_items: historical Order Item, quantity, restock quantity, non-restockable quantity and Order unit-price snapshot. Database CHECK requires positive quantity, nonnegative dispositions and an exact split.
- refunds: immutable business code (REF + ULID), Order, optional completed Return, currency, positive amount, manual method, reason, optional normalized external reference/note, actor, UUID operation key, fingerprint, status and completion time.
- refund_allocations: positive amount allocated to the original Payment Allocation, with a unique Refund/Payment Allocation pair.
- sales_orders.refund_status: derived cache with none, partially_refunded and fully_refunded.

Historical foreign keys use RESTRICT. Unique codes and operation keys guard replay; method plus normalized reference prevents duplicate outgoing references. Completed Refund/Return rows and child allocations/items are protected by MySQL triggers. A transient pending parent and its children become completed within one transaction, so the normal path commits no pending operation.

## Financial rules

Admin POST /api/admin/sales-orders/{order}/refunds accepts amount, manual method (cash, bank_transfer, other_manual), reason (return, order_cancel, price_adjustment, service_recovery, other), optional Return/reference/note and UUID operation key. Backend derives currency, actor, status and limits. The Order row is locked before replay check, calculation and writes. It rejects invalid amount, no settled money, over-refund, unsupported legacy paid markers, Payment currency anomalies, cross-Order Return, linked Return over-value and duplicate reference.

Refund Allocation uses **oldest settled Payment Allocation first**, with row locks, and never exceeds its unrefunded balance. Linked Return value sums returned quantity times historical Order Item unit-price snapshot; completed Refunds already linked to that Return consume the value. An unlinked Refund requires a reason and is limited only by remaining settled money. The completed Refund audit excludes Admin note and customer details.

| Field | Definition |
| --- | --- |
| paid_amount | Sum of settled Payment Allocations (gross) |
| refunded_amount | Sum of completed Refund Allocations |
| refundable_amount | paid_amount minus refunded_amount |
| net_settled_amount | paid_amount minus refunded_amount |
| outstanding_amount | Order grand total minus gross paid_amount |
| payment_status | Gross receipt state, unchanged by Refund |
| refund_status | None, partial or full relative to money actually settled |

Recording a later settled Payment recomputes derived Refund status from gross settled money and completed Refund Allocations. The existing cancellation state machine still controls operational eligibility. Cancellation blocks while refundable money is retained. A fully refunded but unfulfilled eligible Order can be cancelled and its reservation released. A fulfilled Order does not become cancellable solely because it was refunded. The shared Order row serializes Refund and cancellation.

## Return and inventory rules

Admin POST /api/admin/sales-orders/{order}/returns accepts reason, optional note, UUID operation key and distinct item rows with quantity and restock quantity. The Order row is locked, then each item is checked against fulfilled quantity minus previously completed returns. Values must be positive, within Unit precision and never silently clamped. The Return Warehouse is always the Order Warehouse. Each line stores the non-restockable remainder.

Only positive restock quantity increments on-hand and writes a positive SALES_RETURN Stock Movement referencing the Return Item. Reserved quantity is unchanged. Non-restockable quantity produces no sellable stock or movement. The Return and inventory writes commit in one transaction. Completed history is immutable and audited.

## API and UI

Admin routes: GET/POST /api/admin/sales-orders/{order}/refunds, GET /api/admin/refunds/{refund}, GET/POST /api/admin/sales-orders/{order}/returns, GET /api/admin/returns/{salesReturn}, and GET /api/admin/sales-orders/{order}/returnable-items.

Admin Order detail shows gross payment, Refund status, refunded/refundable/net settled values, histories and separate confirmation flows. Return input shows per-line fulfilled/returnable quantity and restock split. Success appears only after API commit. Retail and Dealer Order resources expose safe Refund summary/history; detail also includes safe Return quantity history. Internal note, actor and external reference are excluded from customer-facing resources. Dealer access stays Account scoped; Retail access stays buyer scoped.

## Reconciliation

- refunds:reconcile-orders --dry-run checks Refund amount/allocations, source Payment/Order/currency, linked Return value, per-Payment Allocation limit, negative refundable values and cached status. --apply repairs only a safe derived refund_status after locking and re-reading; it never changes ledger facts.
- returns:reconcile --dry-run checks total returned versus fulfilled, disposition split and restock movement quantity/reference/Warehouse. --apply reports only; it never invents movements or changes physical facts.
- Existing payments:reconcile-orders and inventory reconciliation remain applicable.

## Verification

Targeted feature tests cover settlement, replay/conflict, linked Return value, restock split, unfulfilled/over-return, cancellation, immutable rows, authorization, Retail/Dealer safe output and reconciliation. Return/Refund plus Payment tests passed 16 tests/218 assertions. Guarded real-MySQL process tests exercise simultaneous over-refund, refund replay, excess Return, Return replay and Refund/cancellation races. The final full backend suite passed **511 tests/3,606 assertions**. Frontend TypeScript, ESLint (0 errors, 18 existing Fast Refresh warnings), production build and Bun tests (8 pass/30 expectations) passed. Development reconciliation dry runs found zero anomalies; migration ran in batch 36 and Git whitespace check passed. No authenticated visual browser session was run.

## Boundaries

There is no Gateway Refund, Dealer Wallet/Công nợ, Store Credit, exchange, replacement, supplier return, customer/Dealer self-service Return, Promotion or automatic Dealer Tier update. No fake Refund/Return business rows are seeded. Admin confirms money was already returned before recording a manual Refund. Restocking is limited to the original Warehouse; damaged/quarantine stock and Warehouse Transfer are later domains.
