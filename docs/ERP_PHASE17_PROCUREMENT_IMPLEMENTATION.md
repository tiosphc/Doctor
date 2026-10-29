# Phase 17 — Procurement

Phase 17 adds an Admin-only Supplier master and operational Purchase Order, Goods Receipt and Purchase Return documents. The additive migration is `2026_09_27_190712_create_procurement_tables` (development MySQL batch 8). No demo procurement facts were seeded.

## Source of truth and lifecycle

- Supplier codes remain stable once referenced by a PO; inactive Suppliers cannot issue new POs.
- PO lines retain SKU/name, ordered quantity and VND purchase unit/line cost snapshots. Drafts can be edited; issue locks commercial details. Statuses are `draft`, `ordered`, `partially_received`, `received`, `cancelled`. Cancellation requires no receipt history.
- A PO does not change stock. Goods Receipt can receive part of each PO line and cannot exceed ordered quantity. Each accepted line writes an immutable positive `GOODS_RECEIPT` Stock Movement and updates Inventory Balance through `InventoryService` in the same transaction.
- Purchase Return references a Goods Receipt line, cannot exceed its unreturned quantity, and cannot reduce on-hand below reserved stock. Each accepted line writes a negative `PURCHASE_RETURN` Stock Movement through `InventoryService` in the same transaction. Returning goods does not reopen PO ordered quantity.
- Receipt and return UUID operation keys are idempotent. Same key and payload replay the fact; a changed payload conflicts. The locked PO serializes concurrent quantity updates. A failure in any line rolls back the document, movement and balance updates.

## API and UI

Admin routes under `/api/admin` expose `suppliers`, `purchase-orders`, `goods-receipts`, and `purchase-returns`. Creating a receipt or return uses `/purchase-orders/{purchaseOrder}/goods-receipts` or `/purchase-orders/{purchaseOrder}/returns`. The Admin sidebar links to `/admin/suppliers` and `/admin/purchase-orders`; the latter contains draft creation/editing, issue/cancel, receipt entry, return entry and document history. All endpoints require Sanctum Admin authorization.

## Boundaries and verification

Purchase costs are operational snapshots. Phase 17 does not create Accounts Payable, Supplier Payments, tax/landed cost allocation, lot/expiry receiving or inventory monetary valuation. Existing manual inventory receipts remain separate. Retail/Dealer prices, Sales Orders and Payment ledger are not written by Procurement.

Focused feature and real two-process MySQL tests passed **7 tests / 98 assertions**. The full backend suite after the final backend change passed **559 tests / 4,123 assertions**. Frontend TypeScript, ESLint (zero errors, 18 existing Fast Refresh warnings), production build and Bun tests (8/30) passed. Development inventory reconciliation found zero differences on an empty inventory dataset. Browser visual QA was not performed.
