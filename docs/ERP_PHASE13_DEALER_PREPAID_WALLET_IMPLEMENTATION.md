# ERP Phase 13 — Dealer Prepaid Wallet / Balance

**Date:** 2026-09-26  
**Status:** PASS (acceptance gate completed; follow-up hardening included)

## Scope

Phase 13 implements one prepaid VND Wallet owned by each `DealerAccount`. The Wallet is a stored balance backed by an immutable ledger. A Dealer order must have sufficient available balance before it can be purchased. The original Phase 13 gate excluded online collection; a subsequent requested PayOS QR top-up addition is documented below. Dealer credit, debt, overdraft, withdrawal, wallet transfer, retail wallet, mixed tender, automatic Tier changes and promotions remain outside scope.

Wallet money is separate from the User account and separate from Retail pricing/payment context. Admin is the only actor that can record a manual deposit.

## Implemented behavior

- `dealer_wallets` stores one VND balance per Dealer Account.
- `dealer_wallet_transactions` is an immutable credit/debit ledger with balance-before/after snapshots, strict direction/type checks, unique order/refund links and idempotent operation keys.
- `dealer_wallet_deposits` is an immutable Admin deposit fact. Positive amount, normalized method/reference uniqueness and operation-key replay protection are enforced in the service and database.
- Dealer approval creates a zero-balance Wallet. `dealer-wallets:ensure` backfills Wallet rows for existing accounts without creating money.
- Admin can read a Wallet and record a manual `bank_transfer`, `cash` or `other_manual` deposit.
- Dealer can read its own Wallet balance and paginated ledger. Membership and account status remain authoritative.
- Quick Order review returns Wallet balance and `wallet_sufficient`. Submit atomically creates the shared Sales Order, reserves Inventory, debits Wallet, creates a settled `Payment` with `payment_method=dealer_wallet`, creates its `PaymentAllocation`, and marks the order paid. Any failure rolls back every fact.
- Excel Import uses the same Quick Order submit core. Revalidation performs a batch Wallet preflight and confirmation rechecks each group under locks. Each successful imported order has its own debit/payment transaction.
- A completed Refund using `refund_method=dealer_wallet` credits the Dealer Wallet exactly once through the unique Refund ledger link. Refund idempotency replays the existing Refund and never creates a second credit.
- `dealer-wallets:reconcile` audits ledger chains and cached balances. `--apply` repairs only safe cached balance drift when the immutable chain is valid; it never invents a deposit, debit or refund.
- Dealer Wallet and ledger UI is available at `/dealer/top-up`; it supports switching between all active Dealer Accounts for the signed-in user. Quick Order and Excel review screens show the live balance and block confirmation when insufficient. Admin Dealer detail includes Wallet summary, manual deposit form and recent transactions.
- Wallet factories now provide valid immutable-ledger fixtures for future feature and concurrency coverage. Dealer ledger reads always return the same paginated response shape, including for accounts backfilled without a prior Wallet row. Refund credit locks the Refund and its Sales Order together before changing the Wallet.

## API surface

Dealer-authenticated:

- `GET /api/dealer/accounts/{dealer}/wallet`
- `GET /api/dealer/accounts/{dealer}/wallet/transactions`
- Existing Quick Order and Excel Import routes now use Wallet settlement.

Admin-authenticated:

- `GET /api/admin/dealers/{dealer}/wallet`
- `POST /api/admin/dealers/{dealer}/wallet/deposits`
- `GET /api/admin/dealers/{dealer}/wallet/transactions`
- `GET /api/admin/dealers/{dealer}/wallet/deposits`

No client endpoint accepts a balance, debit amount, payment status, Tier, price, Warehouse or Payment authority field.

## PayOS QR top-up follow-up (2026-09-26)

- A Dealer member can create an idempotent VND top-up request from `/dealer/top-up`. The backend signs a PayOS payment-link request and returns the provider checkout URL, where PayOS displays the QR. Creation does not create a Deposit or change the Wallet balance.
- The browser return page `/dealer/top-up-result/{accountId}/{topUpId}` reads the scoped top-up status and Wallet balance. It cannot complete a deposit. A pending provider result also leaves the Wallet unchanged.
- `POST /api/webhooks/payos` is public to PayOS and verifies the signed payment `data` using the configured checksum key. Only a successful signed payment with matching order code, amount, VND currency and payment-link ID enters the credit transaction. The PayOS webhook success fields are the provider's paid signal; the browser return parameters are never trusted as payment proof.
- The transaction locks the Dealer Account, top-up request and Wallet, then creates one immutable `payos` Deposit and one `deposit_credit` ledger row, updates the cached Wallet balance and marks the top-up paid. Unique request, provider reference, Deposit link and ledger keys plus the lock make webhook replay idempotent. A completed webhook remains payable to the account even if its membership status changes after the provider collected the funds.
- Dealer API: `GET/POST /api/dealer/accounts/{dealer}/wallet/top-ups` and `GET /api/dealer/accounts/{dealer}/wallet/top-ups/{topUp}`. Amount is an integer VND amount from 2,000 to 1,000,000,000; an operation UUID is required.
- Configuration: set `PAYOS_CLIENT_ID`, `PAYOS_API_KEY`, `PAYOS_CHECKSUM_KEY`, `FRONTEND_URL` and the public backend `APP_URL` in the deployment environment. Register the backend `/api/webhooks/payos` endpoint in the PayOS payment channel. No credentials are stored in the repository. Live settlement remains unverified until real provider credentials and a publicly reachable HTTPS deployment are available.
- A 100,000,000 VND request was verified in tests: QR creation, status/return read and pending webhook leave Wallet at zero; a valid paid webhook creates exactly one 100,000,000 VND Deposit and credit; repeat delivery leaves the balance at 100,000,000 VND. Invalid signature, amount and payment-link ID do not credit. A MySQL two-process webhook race also yields one Deposit and credit.
- Final focused top-up verification passed 4 tests/35 assertions plus MySQL concurrency 1/10. Full backend regression passed 520 tests/3,687 assertions; it started before the fourth focused top-up test was added, and that final test passed separately. Frontend typecheck, ESLint (0 errors; 18 existing Fast Refresh warnings), Bun tests 8/30 and production build passed. Pint, migration status, route list, development Wallet dry-run (0 anomalies) and Git whitespace audit passed.

## Concurrency and idempotency

Order submission locks the Dealer Account, shared commercial context, Sales Order, Inventory reservation rows and Wallet row in one transaction. Wallet debit and Payment settlement are written only after all authoritative checks pass. Unique operation keys, order/refund links, external references and immutable database triggers protect retries and concurrent requests.

## Verification

- Targeted Wallet tests pass 4 tests/30 assertions; Quick Order 6/83; Excel Import 10/115 (including two settled Wallet payments/ledger debits); Quick Order/Excel concurrency 9/67; Payment Settlement 9/113; Return/Refund 7/102; Dealer Foundation 8/96.
- MySQL Quick Order/Excel concurrency tests cover competing stock, duplicate submit and import confirmation. A same-account concurrent-order case also proves that the Wallet row lock serializes debits and prevents overspending.
- Full backend PHPUnit regression passes 516 tests/3,645 assertions, including MySQL concurrency tests. Frontend `bunx tsc --noEmit`, ESLint (0 errors, 18 existing Fast Refresh warnings), `bun run build` and Bun tests (8/30) pass. Existing ESLint Fast Refresh warnings are unchanged and do not fail lint.
- `php artisan dealer-wallets:reconcile --dry-run` is the operational audit command; no opening balance or synthetic business transaction is seeded.

The focused and full PHPUnit runs after the follow-up changes completed successfully on the local MySQL instance. PHP lint, Pint, TypeScript, ESLint (0 errors), and the production build also pass.

## Explicitly deferred

Gateway refund, withdrawals, transfers, credit/debt, overdraft, Retail Wallet, mixed tender, automatic Tier progression, promotion and broader Phase 14 work remain outside this phase.

## Next phase

Do not start Phase 14 as part of this change.
