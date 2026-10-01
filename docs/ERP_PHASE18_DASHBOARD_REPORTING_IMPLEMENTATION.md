# Phase 18 — ERP Dashboard & Reporting

**Final acceptance: PARTIAL.** Implementation and automated verification passed. Authenticated desktop/tablet/mobile visual QA remains open because no browser/Admin session was available.

## Scope and existing dashboard audit

Phase 18 reads the Phase 11–17 operational and immutable ledger facts. It adds no financial, stock, wallet, or procurement write path and does not introduce a reporting warehouse or a second accounting authority. The existing `/admin` dashboard was a real Clinic appointments/doctor/service dashboard. It contained an unused `revenue: null` response/type placeholder, which has been removed. No mocked production metrics or fabricated revenue formula was found. The existing Clinic widgets remain and an ERP overview is now shown above them. `/admin/reports` contains detailed reports and is linked from the Admin sidebar.

The initial Git working tree already contained uncommitted changes from earlier phases. Those changes were preserved. Phase 18 files are `ErpReportService`, `ReportPeriod`, `ErpReportRequest`, `ErpReportController`, `ErpReportTest`, the additive reporting index migration, the report API/types, the reports page and its route, and small changes to the Admin dashboard/sidebar and existing Clinic dashboard response.

## Reporting authority and formulas

All financial aggregates are computed by SQL over trusted backend facts and returned as decimal strings. No frontend value is used to establish an authoritative total.

| Metric | Authority | Event date |
| --- | --- | --- |
| Gross settled Sales | `SUM(payment_allocations.allocated_amount)` joined to `payments.status = settled` and matching Retail/Dealer Sales Order context | `payments.settled_at` |
| Completed Sales Refund | `SUM(refund_allocations.amount)` joined to completed `refunds`, the original Payment Allocation, settled Payment and Sales Order | `refunds.completed_at` |
| Net settled Sales | Gross minus completed Refund, using BCMath | Both settlement event dates independently |
| Orders created and status counts | `sales_orders` | `sales_orders.created_at` |
| Product ranking | Negative sum of `SALES_ORDER_SHIPMENT` Stock Movement quantities, joined to Sales Order Item and SKU | `stock_movements.occurred_at` |
| Physical Sales Returns | Completed `sales_return_items.quantity`, reported separately | `sales_returns.completed_at` |

Retail and Dealer totals are separate. Partial Payments contribute only settled allocated value. Pending/failed/cancelled Payments, pending Refunds, Return-only events, unpriced Orders, and zero-total Orders without Payment contribute no Sales revenue. A completed Refund may make a day's net negative even when the original Payment settled on an earlier day. Promotion discounts are already reflected in the actual settled amount; the promotion snapshot is not subtracted again. `sales_orders.grand_total` is operational Order value, **not revenue authority**. Wallet deposits, PayOS Top-Ups and Dealer Wallet balance are **not Sales revenue**.

An unexpected non-VND Payment, Refund or Sales Order currency in a selected Sales ledger slice returns `409 REPORT_LEDGER_CURRENCY_MISMATCH` instead of silently combining currencies.

## Date and filter semantics

`from` and `to` are inclusive `YYYY-MM-DD` dates interpreted in `Asia/Ho_Chi_Minh`. The backend translates them to a half-open SQL interval `[from 00:00, day after to 00:00)`. The default is the current Vietnamese calendar month through today. The UI offers today, last 7 days, last 30 days, current month, previous month and custom date inputs, plus Retail/Dealer/all where relevant. The backend validates date order, channel, optional existing Warehouse/Supplier/Dealer IDs and pagination inputs. Current Inventory and Wallet balances are explicitly labelled snapshots and are not historical period balances.

## Operational report definitions

- **Orders:** created count, channel/status distribution and most recent 20 Orders. Order grand total appears only as an Order field.
- **Products:** top 10 SKU ranks by fulfilled shipped quantity, stable SKU-ID tie-break; completed return quantity is separate. No invented per-SKU revenue attribution.
- **Inventory/Warehouse:** live Balance rows sum `on_hand`, `reserved` and `on_hand - reserved` by Warehouse. Low stock counts only positive available balances at or below a configured Product threshold; absent threshold does not imply low stock. Period Stock Movements are grouped by type. No monetary inventory valuation.
- **Procurement/Supplier:** PO count/status and noncancelled ordered value use PO creation date. Goods Receipt and Purchase Return values use each historical PO Item unit price times completed movement quantity and their own event dates. Net received value is received minus returned. Top Supplier ranking uses those receipt/return facts. These are operational purchasing values, **not Supplier Payments, Accounts Payable, cost of goods sold or profit**.
- **Dealer/Tier:** Dealer net uses the same settled Payment and completed Refund allocations; top Dealer ranking has deterministic ID tie-break. Base Tier and active override/effective Tier are displayed separately. Base Tier distribution is a current account snapshot, not a historical reconstruction.
- **Wallet/PayOS:** current balance, completed Deposit flow, paid PayOS Top-Up and immutable Wallet transaction types are reported in a distinct section. Deposit and Top-Up can represent the same funding event, so their displayed amounts are not summed together.
- **Promotion:** redemption counts and discount snapshots by current status, plus releases dated by `released_at`. This is usage/discount reporting, not an additional financial deduction.
- **Clinic:** appointment count/status by appointment date only; no Clinic revenue is fabricated.

## API, UI and authorization

Admin-only `GET /api/admin/reports/{overview,sales,orders,products,inventory,procurement,dealers,wallets,promotions,clinic-summary}` routes use existing Sanctum/Admin middleware and an Admin-authorizing Form Request. No patient, recipient, payer, email, phone or provider secret is included in the report payload. Filters are query parameters; the service contains the aggregate SQL. The frontend uses strict endpoint response types, React Query loading/error/retry states, bounded tables, an existing Recharts dependency and responsive card/grid/table overflow layouts. The dashboard and reports UI display backend totals without recomputing them.

## Query and index design

Aggregation happens in SQL, with bounded top-10/range groups and a 20-row recent Orders list. The additive `2026_09_27_212912_add_reporting_date_indexes` migration adds date/status indexes for settled Payments, completed Refunds, Order creation, Goods Receipts, Purchase Returns, Promotion redemption/release, paid Top-Ups and Wallet transaction flows. Existing Stock Movement `(movement_type, occurred_at)` and reference indexes are reused. MySQL `EXPLAIN` on the settlement aggregate selected `report_payment_settlement_idx`, then the Payment Allocation payment key and Sales Order primary key. The Goods Receipt value aggregate selected `report_receipt_date_idx`, then the receipt-item key and PO-item primary key. The development tables have very few rows, so these confirm access-path availability rather than a production-volume performance claim.

## Verification

- Focused `ErpReportTest`: **6 tests / 90 assertions**, passing on MySQL. Covers Admin-only access, invalid filters, empty reports, partial settled Payment vs pending Payment, completed Refund, Retail/Dealer split, physical shipment/Return distinct from money, independent settlement/refund dates, PO Receipt/Return historical value, Supplier ranking, Wallet deposit exclusion from Sales and Dealer ranking.
- Pint dirty: passed.
- Frontend TypeScript: passed. ESLint: 0 errors, 18 existing Fast Refresh warnings. Production build: passed. Bun: 8 tests / 30 assertions, passed.
- Full backend suite, run after the final backend test change: **565 tests / 4,213 assertions**, passing on MySQL. This includes the existing Sales, Inventory, Procurement, Retail, Dealer Foundation/Tier/Pricing/Quick Order/Excel, Wallet/PayOS, Promotion, Payment, Return/Refund, Auto Tier, Clinic and real MySQL concurrency coverage.
- `git diff --check`: passed. The reporting migration ran in development MySQL batch 9.
- Browser visual QA: open. The browser inventory exposed no active browser/tab or authenticated Admin session. Responsive source, TypeScript and production output were checked, but desktop/tablet/mobile screenshots were not verified.

## Known limits and deployment

The recent Orders table is bounded to 20 and the rankings to 10; full paginated drill-down/export, BI warehouse, tax/VAT, Inventory valuation, profit/margin, Supplier payment/AP and PDF reports are outside Phase 18. Tier distribution and Inventory/Wallet balances are current snapshots even when a historical date range is selected. The chart only plots dates with settlement or Refund events. Existing historical ledger anomalies are not auto-corrected by reporting; currency mismatches fail closed.

Deploy the additive reporting index migration before enabling the pages. Use the established MySQL/Asia-Ho-Chi-Minh application time configuration. No PayOS configuration is added by this phase; real PayOS settlement verification remains a separate deployment gate. Browser visual QA status is recorded after the final UI check. No Phase 19 feature work was started.
