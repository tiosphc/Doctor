# ERP Phase 7 — Dealer Tier Foundation

## Existing Tier audit

The repository already had one `dealer_tiers` master table (`id`, unique `code`, `name`, `status`, timestamps), `DealerTier` model/factory, Admin list/create/update API, and `/admin/dealer-tiers` page. Product wizard and Admin Product pricing persisted Tier/SKU rows in the existing `price_lists` (`pricing_context=dealer`, `scope_type=tier`, `dealer_tier_id`) and `price_list_items` (`min_quantity` as MOQ and unit price). A pre-existing `DealerPricingService` can resolve a Tier/SKU price for an explicitly supplied Tier, but Phase 7 does not connect Dealer Account context, catalog, Cart or Sales Order to that service. Retail price resolution remains separate.

The existing master lacked rank/order, description and a configured initial flag. Dealer Accounts lacked current Tier, history, override and effective Tier resolution. Phase 7 reuses the existing master and adds these relationships. No duplicate Tier master was created.

## Schema and rules

Migration `2026_09_24_142603_add_dealer_tier_foundation.php` adds `sort_order`, optional `description`, `is_default_initial` and a generated unique active-default slot to `dealer_tiers`; nullable `dealer_accounts.current_tier_id` with a restricted Tier FK; immutable `dealer_tier_histories`; and `dealer_tier_overrides`. MySQL triggers reject Tier code changes and Tier History UPDATE/DELETE. History has Dealer, previous/new Tier, source, reason, effective time, actor and unique optional operation key. Override has Dealer/Tier, start/end, reason, active/cancelled state and actor. Historical Tier FKs restrict deletion.

At most one active initial Tier is allowed by validation and the generated unique index. There is no fallback to the first, lowest-ranked or named `SILVER` Tier. Missing configuration returns `DEALER_INITIAL_TIER_NOT_CONFIGURED` (409). A new approval fails and rolls back before producing an incomplete Dealer Account. Admin must configure one initial Tier in the existing master UI. The development database has no configured initial Tier and no Dealer Accounts; no Tier or Dealer business data was seeded.

`sort_order` controls display order only. Changing it does not recalculate or rewrite Account assignments. An inactive Tier cannot be newly assigned or used for an override. Inactivation is blocked while it is the current Tier of an active Dealer Account or is referenced by an active/future override on an active Dealer Account. Suspended/inactive Accounts keep their Tier and history. Reactivation does not itself change Tier.

## Assignment, history and backfill

Approval still creates Account and owner membership in one transaction, and now also resolves the configured initial Tier, writes `current_tier_id`, and appends exactly one initial history row before marking the application approved. Retrying approval returns the existing Account without duplicating history. Tier belongs to Dealer Account, never User or membership.

`php artisan dealers:assign-initial-tier --dry-run --limit=100 --no-interaction` reports scanned, already assigned, needing assignment, configured default, assigned and conflicts. Optional `--dealer=ID` narrows scope; `--limit=1..1000` bounds each run. Run the dry run first, configure a default if needed, then run without `--dry-run`. The service locks each Account, skips an existing assignment and atomically appends a `migration` history row with a system actor. It never overwrites an assigned Tier. On the development database the dry run and controlled execution both scanned 0 Accounts and assigned 0, with 0 conflicts; no default was configured.

Admin manual change requires active target Tier, reason and UUID operation key. Account row locking serializes concurrent changes; each successful change writes current Tier plus one immutable history row and audit event in one transaction. Repeating the same operation key and payload returns the same history; reusing it with another target/reason conflicts. Selecting the already-current Tier with a new key conflicts explicitly. Admin can read history; no edit/delete API exists.

## Overrides and effective Tier

An Admin override changes effective Tier for a configured time interval without rewriting current/base Tier or its assignment history. Start is inclusive and end is exclusive. End may be open. Creation locks the Dealer Account, rejects overlap with any active override period and rejects inactive target Tiers. Cancellation records actor/time and removes the override from effective resolution; past records remain visible. Expiry is evaluated from timestamps, so no scheduler is needed. Overlapping effective rows, if introduced outside the service, return `DEALER_TIER_OVERRIDE_AMBIGUOUS` rather than silently selecting one.

`DealerTierService::resolve()` is the authoritative Tier resolver. It returns base Tier, effective Tier, source (`current`, `manual_override`, `unassigned`), effective date and public override dates. Future Dealer commercial pricing should call this resolver after resolving active Dealer context. Phase 7 does not call the pre-existing Dealer price resolver from User/Account flows.

## API and UI

- Existing Admin `/api/admin/dealer-tiers` list/create/update now supports order, description and initial flag. Tier code cannot be updated.
- Admin `/api/admin/dealers/{dealer}/tier`, `/tier/change`, `/tier-history`, `/tier-overrides`, and `POST /tier-overrides/{override}/cancel` expose explicit read/change/override operations. Admin history and override responses include reason and actor.
- Authenticated Dealer member `GET /api/dealer/accounts/{dealer}/tier` uses `DealerContextService`; it requires active membership plus active Account and returns only effective/base Tier information. Cross-Dealer access returns 404. No history, reason, actor or pricing internals are exposed.
- Existing Admin Tier page configures order/description/default. Admin Dealer detail shows current/effective Tier, confirmed manual change, override creation/cancellation and read-only history. Dealer profile shows its own effective Tier and clearly labels an override. The UI does not show revenue progress or Dealer price.

Admin routes use existing `auth:sanctum` plus `admin` middleware. Tier is a commercial classification and grants no permissions. Retail Cart, Checkout, My Orders and Retail price remain unchanged; Dealer Sales Order creation remains disabled.

## Verification and limits

Focused feature tests cover master uniqueness/lifecycle/code, missing initial configuration, approval rollback/initial history/idempotency, dry-run/backfill, manual change validation and operation-key replay, immutable history, inactive Tier, effective override/expiry/cancellation/overlap, active membership and cross-Dealer access. Guarded real-MySQL subprocess tests cover simultaneous initial assignment, same/different target changes, approval retry and overlapping override creation. The concurrency tests reset only `aesthetic_clinic_testing`.

Automatic revenue-based Tier calculation is **not implemented**. Trusted settled/captured Dealer payments minus completed attributable refunds would be needed for future qualification; created orders or Retail purchases are not reliable qualification facts. Dealer pricing integration, Dealer Catalog/Cart/Checkout/Orders, Quick Order, Excel Import, credit/debt, Payment, Return/Refund and Promotion are **not implemented**. The pre-existing Tier/SKU price configuration and explicit `DealerPricingService` remain disconnected from Dealer Accounts in this phase.

Verification: focused Phase 6 + Phase 7 Dealer tests passed 24/24 with 242 assertions, including five guarded real-MySQL concurrency tests for Phase 7. The full backend suite passed 449/449 with 2,863 assertions. Frontend TypeScript, ESLint (0 errors, 18 existing Fast Refresh warnings), production build and current Bun tests (8/30 expectations) passed. Pint, migration status, route inspection and Git whitespace check passed. An authenticated visual browser session was not run; responsive UI structure was reviewed from the React/Tailwind implementation.

Git audit found the uncommitted Phase 5 Retail Commerce and earlier Product wizard/Tier-SKU pricing work already present before Phase 7. Phase 7 only extends Tier identity, assignment, history, override, APIs and UI. No new Dealer price resolver, Dealer order, revenue calculation, Quick Order or Excel Import was added. No automatic commit was made.
