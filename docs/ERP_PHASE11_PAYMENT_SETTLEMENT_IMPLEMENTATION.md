# ERP Phase 11 — Payment & Settlement Foundation

## Existing payment audit

Before this phase, shared Sales Orders stored only `payment_status` and a requested `payment_method`. Admin could set Retail Cart orders to `paid` through `/mark-paid`, and COD delivery also set `paid` without a receipt. There was no Payment or Allocation ledger. Before the additive migration, the development database had one Sales Order with `unpaid` status and no historical `paid` orders. No historical Payment rows were fabricated. Deployments with older `paid` or `partially_paid` markers but no ledger entries need manual review; reconciliation reports and preserves those markers, and new manual settlement on them is blocked until reviewed.

## Data model and financial rules

Migrations `2026_09_25_101143_create_payments_table.php` and `2026_09_25_101146_create_payment_allocations_table.php` add separate `payments` and `payment_allocations`; `2026_09_25_135124_guard_settled_payment_allocation_inserts.php` closes the post-settlement Allocation insert path. A Payment has immutable business code, context (`retail` or `dealer`), Dealer Account for Dealer orders, optional payer, currency, decimal amount, method, status, optional external reference/note, Admin recorder, UUID operation key, request fingerprint, and settlement timestamp. An Allocation points to a Sales Order and has a positive decimal amount. The schema permits a Payment to allocate to multiple orders later, while this phase creates exactly one Payment and one Allocation per Admin action. Restricted foreign keys, positive amount checks, uniqueness of `(payment_id, sales_order_id)` and `(payment_method, normalized external_reference)`, and MySQL triggers protect settled Payment and Allocation rows from later insert/update/delete. The payment code is `PAY` plus ULID. No Payment seed data was created.

The lifecycle schema allows `pending`, `settled`, `cancelled`, `failed`; the only V1 write command records verified money directly as `settled`. Only settled Allocations count. `paid_amount` is their decimal sum for the order; `outstanding_amount = grand_total - paid_amount`. Zero paid means `unpaid`, a positive amount below the total means `partially_paid`, and exact total means `paid`. `sales_orders.payment_status` is a derived cache synchronized in the settlement transaction. Nonsettled records contribute zero. A zero-total order stays `unpaid` without a fabricated zero-value Payment and rejects positive settlement. No overpayment, currency conversion, or stored credit is supported.

No `payment_events` table was added. The existing Admin audit log records the successful settlement with Payment code, Order reference, amount, method and actor, without copying recipient address or other order PII.

The backend derives currency, channel, Dealer Account and optional payer from the Sales Order. Dealer commercial identity comes from `sales_orders.dealer_account_id`, independent of current membership. `payment_method` on an Order remains the checkout choice, not proof that money arrived. Cash, bank transfer and other manual are the only Payment methods. The client cannot supply status, currency, totals, Dealer Account, context, payer or recorder. Amount validation uses decimal strings and BCMath with at most two fractional digits; invalid precision or nonpositive values are rejected rather than rounded.

## Recording, locking and idempotency

Only Admin can `POST /api/admin/sales-orders/{order}/payments`. The command locks the Sales Order, reads current settled allocations and outstanding balance, then creates a transient `pending` Payment and Allocation, settles the Payment, updates the cached status, and writes a PII-limited audit entry in one transaction. No transient state is committed or exposed to another request. Draft and cancelled orders cannot accept a Payment. It never changes inventory, fulfillment, Sales Order pricing or Dealer Tier. Fulfillment is still allowed before payment. Ordinary cancellation of any order with a positive settled paid amount returns `PAID_ORDER_REQUIRES_REFUND`; unpaid cancellation keeps its existing reservation behavior. The old `/mark-paid` route and COD auto-paid transition were removed.

Required `operation_key` is a UUID. The same key and normalized request returns the same Payment without a second Allocation; another payload or order returns `PAYMENT_OPERATION_CONFLICT`. The unique database key is the final race guard. External references are trimmed and uppercased for comparison without removing punctuation. Reusing the same reference with the same Payment method returns `PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED`; null references are permitted. Concurrent settlements serialize on the Sales Order row; a second request sees the new outstanding amount and cannot overpay. Errors are structured codes rather than SQL details.

## API and UI

| Access | Endpoint or view | Behavior |
| --- | --- | --- |
| Admin | `GET /api/admin/sales-orders/{order}/payments` | Summary and receipt history |
| Admin | `POST /api/admin/sales-orders/{order}/payments` | Record one verified received Payment |
| Admin | `GET /api/admin/payments/{payment}` | Inspect a Payment and allocations |
| Admin | Sales Order detail | Total, paid, outstanding, status, history, and confirmation form |
| Retail owner | Existing `/api/retail/orders` list/detail | Read-only paid/outstanding/status; no Admin note or recorder |
| Active Dealer Account member | Existing Account-scoped Dealer orders list/detail | Read-only paid/outstanding/status for that Account |

Admin Sales Order list retains its payment-status filter and now includes paid/outstanding values. The detail form accepts amount, method, optional reference/note; an explicit confirmation dialog precedes the write and a centered success dialog displays the returned Payment code after the server responds. The Payment table shows code, date, method, amount, reference, recorder and status. Fully paid orders hide the form. Retail and Dealer UI display safe totals, without Payment mutation controls. Their existing ownership and Account authorization remain in force. No separate Admin Payments page was needed because order detail provides the V1 workflow.

## Reconciliation and legacy status

Run `php artisan payments:reconcile-orders --dry-run` (also the default) to scan settled sums against cached statuses. It reports scanned/unpaid/partial/paid counts, mismatches, overpaid orders, currency mismatches, orphaned allocations, allocation totals exceeding Payment amounts, legacy paid markers, and repairs. `--apply` updates only a safe derived `sales_orders.payment_status` after locking/re-reading the order. It never changes Payment amounts or invents historical receipts. Any structural/currency anomaly blocks automatic repair; a legacy paid marker without ledger is reported and preserved for manual evidence-based migration. The command returns failure for those anomalies.

## Verification and limits

The additive core migrations ran in development batch 34 and the insert guard in batch 35. The development dry run scanned one unpaid Sales Order and reported zero mismatches or anomalies. Focused tests exercise partial/full payment, exact replay, changed-payload conflict, overpayment, reference duplication, amount and authority validation, cancellation protection across cancellable and completed states, settled row immutability including post-settlement insert rejection, nonsettled exclusion, Retail ownership, Dealer Account scope and context, zero-total policy, legacy preservation, reconciliation, currency mismatch, and absence of COD invented receipts. `PaymentSettlementTest` passed 9 tests/110 assertions; the guarded multi-process MySQL `PaymentConcurrencyTest` passed 4/33, covering simultaneous full payments, simultaneous partial overpay attempts, same-key replay and external-reference races. Existing Retail and Sales Order focused suites passed 12/161 and 10/107 respectively. The final full backend suite passed 499 tests/3,446 assertions. Frontend TypeScript, ESLint (zero errors, 18 existing Fast Refresh warnings), production build and Bun tests (8/30) passed. Pint, migration status, reconciliation dry run and Git whitespace audit passed.

QR/Gateway, payment webhooks, Refund, Dealer Credit/Công nợ, and automatic Dealer Tier calculation are **not implemented**. Settled Payment corrections require a future refund/adjustment workflow; records are deliberately immutable. Production needs MySQL support for the migration's `CHECK` constraints and triggers. The development audit found no legacy paid order, but production data must be audited before any reconciliation apply. No Phase 12 work was started.

No authenticated visual browser session was used; the UI was verified through TypeScript, lint, production build, and source review. No automatic commit was made.
