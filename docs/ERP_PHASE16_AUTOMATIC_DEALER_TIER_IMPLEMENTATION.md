# Phase 16 — Automatic Dealer Tier from lifetime net settled revenue

## Foundation and authority

Phase 16 reuses the Phase 7 `dealer_tiers`, `dealer_accounts.current_tier_id`, immutable `dealer_tier_histories`, `dealer_tier_overrides`, and `DealerTierService`. It changes the **base Tier** only. `DealerTierService::resolve()` still chooses an active manual override as the effective Tier for pricing. No second Tier hierarchy or Dealer price list was introduced.

`DealerNetRevenueService` calculates V1 lifetime VND revenue for one Dealer Account as:

`SUM(settled Dealer Sales Order Payment Allocations) - SUM(completed Refund Allocations linked to those Payment Allocations)`.

Both sides are database aggregates; BCMath compares and subtracts two-decimal strings. Dealer identity is checked on the Sales Order and Payment, and Refund identity is checked against the same Order. Retail settlements, pending/failed/cancelled Payments and Refunds, Wallet deposits and credits, PayOS Top-Ups, unpaid/zero-total Orders, and physical Returns without a completed Refund contribute zero. A Promotion changes this figure only through the actual discounted settled Payment. Order totals, Wallet balance, and client-provided values never act as revenue authority. Currency mismatch or negative net revenue fails closed with `AUTO_TIER_LEDGER_INVALID`.

## Rules and lifecycle

The additive `2026_09_27_124808_add_automatic_revenue_tiers` migration adds nullable `dealer_tiers.revenue_threshold`, automatic history evidence, and a singleton disabled policy. It creates no threshold and changes no Dealer Tier assignment. Admin configures each threshold in the existing Tier master. A null threshold makes a Tier manual only. To enable automation, the active default initial Tier must have threshold `0.00`, and participating active Tiers must have strictly increasing `sort_order` and thresholds. Invalid rules fail with `AUTO_TIER_RULES_INVALID`. Rule edits require first disabling the policy, preventing a partly edited enabled rule set. Enabling is an explicit Admin action; migration and preview never enable it. Policy version is recorded with each automatic history event.

`DealerAutoTierService` selects the highest active threshold no greater than the trusted net revenue. Equality qualifies. It serializes changes on the Dealer Account row, checks account/current Tier state, and writes a new `automatic_revenue` history with net revenue snapshot, policy version, timestamp and audit only when the base Tier changes. A repeat evaluation is a no-op. It upgrades after settlement and downgrades after a completed Refund, with no hysteresis. Suspended/inactive accounts remain readable but are not mutated. An inactive current base Tier is reported and not silently replaced.

Manual base changes retain their Phase 7 operation and can be corrected on the next automatic evaluation if the policy is enabled. For a deliberate temporary exception, Admin should use a Tier override. Automatic evaluation leaves overrides intact, including their expiry and effective pricing precedence.

`PaymentService`, Dealer Wallet order debit, and `RefundService` register evaluation only after the outer transaction commits. Duplicate/replayed financial requests do not register a new callback. Callback errors are reported and the scheduled reconciliation can repair a missed Tier change; financial facts remain committed. The daily 02:00 scheduled `dealers:reconcile-auto-tiers --apply` uses the same evaluator and is guarded by the disabled-by-default policy. No queue worker is required.

An Order that crosses a threshold keeps its prior Tier and price snapshots. Subsequent Orders resolve the new effective Tier. Existing Quick Order review fingerprints include base/effective Tier and reject a stale review with `DEALER_ORDER_CHANGED`. Excel Import revalidates each group through Quick Order; a Tier change between preview and confirmation requires fresh revalidation, and a multi-order batch can remain partially completed without repricing an already created group.

## API and UI

- Admin Tier master: `GET/POST /api/admin/dealer-tiers`, `PATCH /api/admin/dealer-tiers/{tier}` expose/configure `revenue_threshold`; `GET/PUT /api/admin/dealer-tiers/auto-policy` read and explicitly enable/disable the policy.
- Admin preview: `GET /api/admin/dealers/{dealer}/auto-tier` returns settled/refunded/net VND amounts, current/target/next Tier, next threshold, remaining amount, policy state, and `would_change`. The existing Admin Dealer Tier page shows progress and explains manual override behavior.
- Dealer preview: `GET /api/dealer/accounts/{dealer}/auto-tier` uses the existing Dealer membership context, so a member cannot read another Dealer's revenue. Dealer profile shows its own Tier progress. The frontend formats values for display only; the backend remains authoritative.
- Automatic history includes `net_revenue_snapshot` and `policy_version`; the existing Tier history endpoint and Admin panel display the source.

## Reconciliation and rollout

`php artisan dealers:reconcile-auto-tiers --dry-run` is read-only and is the default. `--dealer=ID` scopes one account; `--limit=N` caps a scan (`0` scans all accounts). Each row reports unchanged/proposed upgrade/proposed downgrade (or same-rank change), active override, and latest Tier History consistency; the summary counts proposed upgrades, downgrades and history anomalies. `would_change` is reported even while automation is disabled so Admin can review the rollout. `--apply` calls the same locked Tier service; it never fabricates Payment, Refund, Wallet or Promotion facts and makes no changes while policy is disabled. The scheduler invokes the same command daily after explicit enablement.

Safe rollout: deploy the migration; configure active thresholds in Admin; run dry-run and review every proposed change; check Payment/Refund/Wallet/Promotion reconciliation; explicitly enable the policy; run `--apply`; then keep the scheduler running. If rules must change, disable, edit, preview and re-enable. The existing Phase 7 initial Tier assignment remains the path for a newly approved Dealer.

## Verification

- Phase 16 focused feature and real MySQL two-process concurrency: **10 tests / 91 assertions passed** after the reconciliation-report assertions. Concurrency cases cover two Payments, Payment versus Refund, duplicate evaluation, manual base change versus automatic change, and an override race.
- Dealer, Tier, Pricing, Quick Order, Excel, Wallet, PayOS, Promotion, Payment, Refund, Inventory, Retail and Clinic focused regression: 122 tests / 1,352 assertions passed.
- Full backend suite after the final reconciliation-report assertions: **552 tests / 4,025 assertions passed**.
- Frontend TypeScript, production build and Bun tests (8 tests / 30 assertions) passed. ESLint passed with zero errors and 18 existing Fast Refresh warnings. Responsive Admin/Dealer source layouts were reviewed.
- The additive migration ran in development MySQL batch 7. Eight development reconciliation commands passed: automatic Tier, Payment, Refund, Wallet, Sales Promotion, PayOS Top-Up, Return and Inventory. This development database contained zero Dealer Accounts, Orders, Wallets and Top-Ups at verification time, so these dry runs prove command behavior on an empty dataset; ledger scenarios are exercised by the MySQL tests.

## Limits

V1 is lifetime VND net settled revenue. It has no rolling window, calendar reset, points, order-count/quantity Tier, manual revenue adjustment, Tier fee, Wallet/credit/debt Tier authority, or automatic Promotion eligibility. The scheduled evaluator is recovery for missed callbacks, not a substitute for a valid financial ledger. Live PayOS settlement and browser-based visual QA were not exercised in this Phase 16 implementation; prior PayOS integration remains unchanged. Phase 17 was not started.
