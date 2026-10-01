# Phase 14 — Online Dealer Wallet Top-Up (PayOS)

## Scope and dependency

Phase 13 Dealer Wallet, Deposit, immutable Wallet Transaction ledger, and reconciliation are prerequisites. Phase 14 adds online Top-Up requests. A Top-Up funds a Dealer Wallet; it is **not** a Sales Order Payment or Payment Allocation. Manual Deposits from Phase 13 remain available to Admin.

PayOS is the only configured provider. `WalletTopUpGateway` is the provider boundary; `PayOsClient` owns API calls, request/response signatures, and provider status mapping. Controllers never accept a browser claim that money was paid.

## Lifecycle and financial invariant

- `initiating` → `pending` when PayOS returns a signed payment link. The link expires after `PAYOS_TOP_UP_TTL_MINUTES` (default 30).
- Signed PayOS status lookup can move an unpaid Top-Up to `expired`, `failed`, or `cancelled`. `PROCESSING` and `UNDERPAID` remain pending. An authenticated Dealer may request a backend refresh; the backend queries PayOS and verifies its signed response.
- A verified PayOS paid webhook or signed paid lookup enters the **same** completion method. It locks the Dealer Account, Top-Up, and Wallet; creates one completed PayOS Deposit and one Wallet Credit; updates balance; and marks the Top-Up `paid` in one database transaction.
- A paid Top-Up never regresses. Repeated webhook/requery is idempotent. A later verified paid result can resolve a previously terminal unpaid status when PayOS actually settled.
- QR creation, browser return, pending, failed, expired, and cancelled states do **not** credit the Wallet. Pending funds are not spendable. Only the verified settlement creates Deposit + Wallet Credit.
- Top-Up order code, payment link ID, provider reference, and Deposit linkage have database uniqueness constraints. Wallet transactions are immutable. MySQL row locks serialize concurrent completion attempts.

## APIs and UI

- Dealer: `POST /api/dealer/accounts/{dealer}/wallet/top-ups`, list, detail, and `POST /api/dealer/accounts/{dealer}/wallet/top-ups/{topUp}/refresh` (authenticated and rate limited). Dealer UI shows QR/link, pending and terminal status, history, balance, and account switching. The result page is informational and can request a backend refresh.
- PayOS webhook: `POST /api/webhooks/payos`, rate limited, outside Dealer Sanctum authentication. It verifies HMAC before any financial write. API routing does not use web CSRF middleware; no broad CSRF exception was added.
- Admin: `GET /api/admin/dealer-wallet-top-ups` lists code, Dealer, amount, provider, status, provider reference, and create/paid/completion times. There is no manual “mark PayOS paid” action.

## Reconciliation

`php artisan dealer-wallet-topups:reconcile --dry-run` audits signed provider status for unpaid requests and checks completed Top-Up → Deposit → Wallet Credit, amount/currency, reference uniqueness, and linked Dealer/Wallet. It reports orphan PayOS Deposits. `--apply` only refreshes a Top-Up through the same provider verification and completion service; it does not invent a Deposit or Wallet Credit to repair a broken ledger. `--dealer={id}` limits the scan. A nonzero exit reports anomalies.

## Deployment

Configure `PAYOS_CLIENT_ID`, `PAYOS_API_KEY`, `PAYOS_CHECKSUM_KEY`, `FRONTEND_URL`, `APP_URL`, and optionally `PAYOS_TOP_UP_TTL_MINUTES`. Keep credentials in environment/config only; never place them in frontend bundles or logs. Use HTTPS for production frontend/backend and register `https://<backend-host>/api/webhooks/payos` as the PayOS webhook URL. Allow PayOS to reach that URL and verify a real payment and webhook before production use.

## Verification and limits

Automated tests cover unsigned/mismatched webhooks, replay, out-of-order events, signed requery, lifecycle, Admin authorization, reconciliation, and real MySQL concurrent webhook replay. [Official PayOS API](https://payos.vn/docs/api/) documents payment-link status lookup and the webhook signature; [return URL documentation](https://payos.vn/docs/du-lieu-tra-ve/return-url/) confirms the browser redirect is distinct from settlement.

**Live PayOS verification:** Pending. This environment has no live PayOS credentials or externally reachable registered webhook, so no real bank transfer was performed. Provider API behavior is validated with signed test fixtures. PayOS's reported paid transactions must total the full Top-Up amount and contain unique references; malformed or ambiguous responses fail closed for operator review. Phase 14 does not include withdrawal, Wallet transfer, credit terms, mixed tender, Retail Wallet, Auto Tier, or Promotion.

## Acceptance verification (2026-09-26)

- Focused Top-Up, MySQL concurrency, Wallet, Quick Order, Excel Import, Payment, and Return/Refund regression: **66 tests / 699 assertions passed**.
- Full backend suite, run after the final Phase 14 test was added: **528 tests / 3,749 assertions passed**.
- Frontend TypeScript, ESLint (zero errors; 18 existing Fast Refresh warnings), production build, and Bun tests (8 tests) passed.
- Development migration `2026_09_26_230514_add_lifecycle_to_dealer_wallet_top_up_requests` ran; route list and `git diff --check` passed.
- Code acceptance is complete. Production acceptance still requires a real PayOS settlement and webhook verification with deployment credentials and HTTPS endpoint.
