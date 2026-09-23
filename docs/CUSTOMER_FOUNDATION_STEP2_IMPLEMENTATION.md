# Customer Foundation Step 2 Implementation

## Status and scope

Customer Foundation Step 2 is implemented for **registered customers only**. It adds deterministic normalization, restartable backfill, canonical read paths, and registered runtime dual-write while preserving existing Booking behavior.

The task did not implement Dealer, Retail, Product/SKU, Sales Order, Warehouse, Inventory, Payment, Excel import, guest-customer backfill, claim/merge workflows, or stronger Customer constraints.

## Current implementation

- `CustomerIdentityNormalizer` owns normalization version `customer-v1`.
- `RegisteredCustomerService` creates/reuses one canonical Customer per eligible User under a row lock and the existing unique `customers.user_id` constraint.
- `RegisteredCustomerBackfillService` scans eligible Users with `chunkById`, processes one User per transaction, links only registered appointments, fills only null snapshots, records migration maps, and performs reconciliation.
- `BackfillRegisteredCustomers` exposes dry-run, canary limit, automatic resume, batch labeling, and reconcile-only modes.
- Registration creates User and Customer atomically.
- Registered booking resolves/creates Customer before creating the Appointment and writes `customer_id` plus current profile snapshots in the booking transaction.
- Customer profile update synchronizes name/phone through the same normalizer.
- Guest booking remains unchanged and does not create/link a Customer in Step 2.

## Normalization contract

### Email

- Unicode-aware edge trim.
- Empty value becomes null.
- Lowercase comparison form.
- Invalid email becomes null and is reported; raw/display input remains available.
- No Gmail dot removal, plus-tag removal, provider aliasing, IDNA guessing, or identity inference.

### Phone

- Unicode-aware edge trim and safe removal of display separators.
- `0912345678`, `+84 912 345 678`, `84912345678`, and `0084912345678` normalize to `+84912345678`.
- Valid explicit international E.164-style input is retained.
- Empty becomes null; invalid/ambiguous input becomes null without guessing another number.
- Existing `App\Support\PhoneNumber` remains unchanged because guest lookup/rate-limit compatibility still uses its legacy local representation.

### Name

- Edge trim and repeated-whitespace collapse.
- Name is never used as an identity key.
- A blank registered name is held as an actionable conflict instead of inventing a Customer identity.

## Command usage

Read-only preflight:

```powershell
php artisan customers:backfill-registered --dry-run --no-interaction
```

Canary execution:

```powershell
php artisan customers:backfill-registered --limit=100 --batch=registered-canary-001 --no-interaction
```

Full execution or automatic resume:

```powershell
php artisan customers:backfill-registered --batch=registered-v1 --no-interaction
```

Read-only reconciliation:

```powershell
php artisan customers:backfill-registered --reconcile-only --no-interaction
```

`--chunk` defaults to 100 and accepts 1–5000. `--batch` is restricted to a non-PII operational key. Logs and reports expose IDs/counts only, not full contact data.

## Checkpoint, restart, and idempotency

- Each eligible User is the atomic unit: lock User → validate mapping → create/reuse Customer → detect conflicts → link eligible appointments → fill null snapshots → insert migration map → commit.
- `customer_migration_map` is the persisted checkpoint and is unique by `(source_type, source_id)` across batch keys.
- Customer code is derived from the inserted Customer primary key through `CustomerCode::fromId()`; it never uses `MAX(code) + 1`.
- `customers.user_id` and `customer_code` unique constraints are the final concurrent/retry guards.
- Re-running scans safely, reuses the existing Customer/code/map, and only links any still-eligible null appointment references.
- `--limit` supports a committed canary. A later full command resumes automatically; no reset flag is required.

## Conflict behavior

- Contact equality never causes an automatic merge.
- A registered User with email/phone matching another Customer receives a separate User-linked Customer plus a deterministic `REGISTERED_CONTACT_COLLISION` conflict and candidate rows.
- Invalid contact is preserved as display data, normalized to null, and recorded as `INVALID_REGISTERED_CONTACT`.
- A pre-linked Appointment whose Customer disagrees with `appointments.user_id` is recorded as `APPOINTMENT_CUSTOMER_MISMATCH` and is not overwritten.
- A migration map disagreeing with the live User↔Customer relation is recorded as `MIGRATION_MAP_CUSTOMER_MISMATCH` and the User unit is skipped.
- Conflict keys hash normalization version, source, reason, and sorted candidate Customer IDs. Re-running the same state does not duplicate the conflict or candidate tuple.

Generic `AuditLogger` is intentionally not called per backfill row. The migration map and conflict tables are the operational trace and support an explicit system source without inventing a fake Admin actor or flooding request-oriented audit history.

## Appointment linkage and compatibility

- Only `appointments.user_id = eligible user` rows are processed.
- Null `customer_id` is linked to that User's unique canonical Customer.
- Existing correct links are retained.
- Existing conflicting non-null links are reported and retained for review.
- Generic Customer snapshots are filled only when null, using current registered profile data; they are not claimed as historic-at-booking truth.
- Guest rows are not searched, merged, claimed, linked, or snapshot-rewritten.
- `appointments.user_id`, `guest_*`, ownership triggers, policies, voucher/loyalty relations, and existing public guest lookup remain intact.

## Customer read cutover

- Admin Customer List and Detail now query `customers`, include linked User compatibility data, and count canonical appointment links.
- Receptionist Customer Search now queries `customers` and safely serializes a Customer without a User.
- Existing response fields `id`, `name`, `email`, `phone`, and `role` remain; additive fields include `customer_code`, `user_id`, and `status`.
- Frontend types accept nullable email/user linkage for canonical Customers; existing screens and query keys remain unchanged.
- Appointment resources intentionally retain their User-shaped registered-customer field during the legacy compatibility window.

## Development execution result

Preflight before mutation:

| Metric | Result |
|---|---:|
| Users scanned | 8 |
| Eligible registered Users | 4 |
| Existing canonical Customers | 0 |
| Customers to create | 4 |
| Registered Appointments linkable | 19 |
| Guest Appointments | 3 |
| Conflicts / invalid rows | 0 / 0 |

After execution and idempotent rerun:

| Expected | Actual | Difference |
|---:|---:|---:|
| 4 eligible Users | 4 canonical registered Customers | 0 |
| 19 registered Appointments | 19 correctly linked Appointments | 0 |
| 4 User migration mappings | 4 consistent mappings | 0 |

Additional checks: 0 missing Customers, duplicate User links, Customer orphans, owner mismatches, linked guest appointments, missing/duplicate Customer codes, normalized collision groups, inconsistent maps, and conflicts. The second run created 0 Customers, linked 0 Appointments, and preserved all four codes/maps.

## Failure, stop, resume, and rollback

- Stop the command normally if a failure is observed. The current User transaction rolls back; previously committed User units remain valid.
- Run `--dry-run` and `--reconcile-only` to inspect state, then fix the reported source/conflict forward.
- Resume by running the same command again. Do not delete migration maps or reset Customer codes.
- Do not use a destructive “rollback all Customers” operation. Operational rollback is: stop new canonical writes if required, restore a compatibility release that still reads legacy `user_id/guest_*`, retain Customers/maps/conflicts for investigation, and forward-repair.
- No schema migration or constraint tightening was added in Step 2. `appointments.customer_id` remains nullable for guest compatibility and later migration stages.

## Known limitations

- Guest Customer backfill and verified claim/merge remain separate future work.
- Loyalty, vouchers, reviews, Appointment policies, and registered Appointment response identity still use User compatibility paths.
- Canonical contact does not automatically rewrite login credentials, and contact collision resolution has no UI yet.
- There is no destructive reset command by design.
- PHP CLI lacks `intl`, so Laravel's formatted `db:table` display is unavailable; migrations, information-schema queries, MySQL-backed tests, and reconciliation provide schema/data verification.

## Explicit next step

After Step 2 verification is accepted, the next task is an architecture update for **Retail + Dealer Unified Commerce**. Product Master/SKU/Retail Pricing begins only after that architecture update is approved. Neither task is started here.

## Final Verification

- Verified: 2026-09-23 14:13 +07:00 (Asia/Saigon).
- Scope: source, applied migrations, live development database, backfill commands, targeted and full backend tests, frontend validation, API contracts, ownership triggers, and read-path/performance/security review. No application code, migration, dependency, or ERP commerce feature was changed during this verification.
- Git audit limitation: `git status`, `git diff --stat`, and `git diff` could not run because this workspace has no `.git` metadata. Source filename inventory found no files named for Product/SKU/Retail/Dealer/Sales Order/Warehouse/Inventory/Excel Import/Payment modules, but exact modified/untracked files and the Step 2 diff cannot be certified without the repository history.

### Live schema and dry run

- All 34 migrations are applied, including the five Customer Foundation schema migrations. `customers.user_id` and `customers.customer_code` have unique indexes; normalized contacts have non-unique indexes. The Customer conflict, candidate, and migration-map tables and nullable `appointments.customer_id` exist. Both Appointment ownership triggers remain installed and do not reference `customer_id`.
- `php artisan customers:backfill-registered --dry-run --no-interaction` exited 0: 8 Users scanned, 4 eligible and processed, 0 Customers to create, 0 Customers created, 4 existing linked Customers, 0 Appointments linkable or linked, 0 conflicts, 0 skipped records, and 0 invalid records. Full-row hashes of Customers, migration maps, registered Appointments, and guest Appointments were unchanged.

### Reconciliation and idempotency

- `php artisan customers:backfill-registered --reconcile-only --no-interaction` exited 0 both before and after the rerun: 4 eligible registered Users, 4 canonical registered Customers, difference 0; 19 registered Appointments, 19 correctly linked, difference 0; 3 guest Appointments, 0 incorrectly linked; 4 migration maps, 0 inconsistent. Missing Customers, duplicate User links, missing/duplicate Customer codes, owner mismatches, orphan registered Customers, normalized email/phone collision groups, and identity conflicts were all 0.
- `php artisan customers:backfill-registered --batch=registered-v1 --no-interaction` exited 0: 0 Customers created, 0 Appointments linked, 0 conflicts/skipped/invalid records. Reconciliation remained clean. Full-row hashes before and after matched for all four checked datasets, confirming stable Customer codes/maps, unchanged registered links, and untouched guest rows.
- Normalizer tests and source checks cover trimmed/lowercased valid email, blank/invalid email to null, no alias guessing, all four documented phone forms, whitespace-normalized names, and no name-based identity matching. Backfill tests cover restart/resume, contact collisions without automatic merge, persisted conflicts, guest non-claim, and mismatched Appointment preservation. Registration and registered booking tests cover canonical dual-write and legacy ownership/snapshot compatibility; guest booking and lookup tests passed. Admin and receptionist tests cover canonical reads including Customers without Users.

### Test and frontend results

| Check | Result |
|---|---|
| Targeted backend | PASS: 143 tests, 808 assertions, 0 failures |
| Full backend (`php artisan test --compact`) | PASS: 360 tests, 1,935 assertions, 0 failures, 0 errors, 0 skipped |
| Frontend typecheck (`bunx tsc --noEmit`) | PASS |
| Frontend lint (`bun run lint`) | PASS: 0 errors, 18 existing Fast Refresh warnings |
| Frontend build (`bun run build`) | PASS: client, SSR, and Nitro outputs |

Known warnings: ESLint retains 18 existing `react-refresh/only-export-components` warnings; the build reports an informational `vite-tsconfig-paths` redundancy warning under Vite 8. PHP CLI still lacks `intl`, so formatted `db:table` output was unavailable; schema was verified through migrations and MySQL metadata. The earlier architecture design document describes the pre-Step 2 state and must not be read as the current database state.

**Final status: functional verification PASS; formal acceptance gate NOT COMPLETE.** The remaining gate is the requested Git diff/scope audit, which requires restoring repository metadata or supplying an authoritative baseline. No Step 2 bug was found or fixed. Do not start the next ERP phase based on this verification alone.
