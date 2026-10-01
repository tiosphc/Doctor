# ERP Phase 6 — Dealer Application and Account Foundation

## Scope and existing foundation

Phase 6 adds an application, manual Admin review, a B2B Dealer Account, owner membership and a reusable active Dealer context resolver. The existing `users` table and Sanctum session remain the only login identity. `customers` remains a separate canonical clinic/customer profile; Retail Cart, Checkout and My Orders keep using the User and Retail price. The order recipient remains an immutable Sales Order shipping snapshot.

Before Phase 6, the Product wizard already had a `dealer_tiers` master and Dealer Tier/SKU price configuration. Phase 6 preserves those files and data but does not assign a Tier to an Account, calculate revenue Tier, expose Dealer prices to an approved User or enable Dealer ordering. `SalesOrderService` continues to reject Dealer creation as `UNSUPPORTED_CHANNEL`.

## Schema and constraints

Migration `2026_09_24_115341_create_dealer_foundation_tables.php` adds:

- `dealer_applications`: applicant User FK, business/contact/address snapshot, optional tax code/type/purchase estimate/note, pending/approved/rejected/cancelled status, reviewer/time and safe rejection reason. A generated `pending_user_id` has a unique index so only one pending application can exist for a User, including concurrent requests. Historical rejected/approved rows remain.
- `dealer_accounts`: unique stable code, approved business/contact/address master, active/suspended/inactive state and transition timestamps. `source_application_id` is unique and restricted on deletion. Creator/updater User FKs preserve ownership evidence without using `dealer_accounts.user_id`. The MySQL code trigger permits one transition from a temporary insert code to the PK-derived `DLR########` code and rejects later changes.
- `dealer_account_users`: restricted Dealer/User FKs, unique `(dealer_account_id,user_id)`, indexed `(user_id,status)`, membership role and lifecycle timestamps. V1 creates one active `owner`; schema allows future multi-account and multi-user memberships.

No tax-code uniqueness or automatic business matching was introduced. A missing tax code is allowed and multiple nulls are supported. No Dealer data was seeded.
Rollback refuses to drop these tables once application, Account or membership history exists.

## Application and Admin review

Only an authenticated customer-role User can submit an application. The backend derives `user_id` from auth, prohibits a client-provided ID, normalizes names/whitespace, lowercases valid email and normalizes supported phone forms. Required: company name, contact name, email, phone, address line 1, city, province and country. Trading name, tax code, address line 2, postal code, business type, estimated monthly purchase (nonnegative VND DECIMAL) and note are optional. The estimate is informational only.

Submission locks the User and checks no pending application or active membership. A rejected applicant can submit again. Pending rows have no Dealer Account or membership. Admin review is manual: explicit `approve` and `reject` commands, no generic status PATCH or automatic scoring. Reject requires a reason and does not affect Retail/Clinic access. Applicant cancellation is deferred in V1.

Approval locks the application and applicant User. In one transaction it verifies eligibility, inserts the Account, assigns the PK-derived immutable code, creates the active owner membership, marks the application approved and writes audit records. A retry of an approved application returns its existing Account; other non-pending transitions conflict. The unique source application, membership key and row locks guard double approval and approve/reject races. No Tier, price list, credit limit or Dealer order is created.

Admin can edit business fields while code and membership stay unchanged. Explicit activate, suspend and inactivate commands lock the Account and enforce allowed transitions. Dealer context requires both an active Account and an active membership; suspension immediately removes active Dealer context but leaves login, Customer and Retail access alone. Audit Log records application submission/review, Account creation/edit/status and owner membership creation without storing the full submitted private profile in audit metadata. Notifications were deferred because no Dealer-specific notification contract exists yet.

## API and authorization

All user routes use `auth:sanctum`; only the applicant may see their application. Admin routes additionally use the existing `admin` middleware. Receptionist, Doctor and customer-role Users cannot perform Admin review or Account management.

| Route | Purpose |
| --- | --- |
| `POST /api/dealer-applications` | Submit current User's application |
| `GET /api/dealer-applications/my` | Latest own application; 404 when none |
| `GET /api/dealer-applications/{application}` | Own application only |
| `GET /api/dealer/accounts` | Current User's active memberships and active Accounts |
| `GET /api/dealer/accounts/{dealer}` | Resolve selected Account with server-side membership check |
| `GET /api/admin/dealer-applications` | Paginated Admin list; status/search/applicant/date filters |
| `GET /api/admin/dealer-applications/{application}` | Admin review detail |
| `POST /api/admin/dealer-applications/{application}/approve` | Atomic, idempotent approval |
| `POST /api/admin/dealer-applications/{application}/reject` | Reject pending application with reason |
| `GET /api/admin/dealers` | Paginated Dealer list; status/search filters |
| `GET /api/admin/dealers/{dealer}` | Business data and memberships |
| `PATCH /api/admin/dealers/{dealer}` | Business data only |
| `POST /api/admin/dealers/{dealer}/activate`, `/suspend`, `/inactivate` | Controlled status transitions |

The `DealerContextService` resolves Account scope from the authenticated User plus a selected Account ID on every request. The frontend route and Account code are never authorization evidence. Inactive memberships and suspended/inactive Accounts are excluded. There is no Dealer auth guard, password or alternate login.

## Frontend

- `/dealer/apply`: authenticated application form and pending/approved/rejected status, safe rejection reason and reapplication after rejection.
- `/dealer`: active Dealer profile/context summary only: code, business/contact/address, Account status and membership role. No price, Tier or ordering UI.
- `/admin/dealer-applications` and detail: paginated search/status/date filter, review data, approval confirmation popup and rejection reason dialog.
- `/admin/dealers` and detail: paginated list, owner membership, business edit and confirmed status commands.
- Customer account navigation adapts its Dealer link to apply/status/approved state. Dealer queries are cleared on logout. Forms and tables use existing responsive Tailwind patterns and real Laravel APIs.

## Verification and limits

- Focused Dealer feature tests cover auth, normalization, validation, ownership, duplicate pending, manual approval/rejection, idempotency, code immutability, Admin-only routes, membership/context, status/edit, Customer and Retail preservation.
- Guarded real-MySQL subprocess tests cover simultaneous submission, simultaneous approval, approve/reject race and simultaneous Account suspension. They reset only the dedicated `aesthetic_clinic_testing` database.
- The migration ran in development batch 29. Focused Dealer feature and concurrency tests passed 12 tests/125 assertions. The full backend suite passed 437 tests/2,746 assertions. Frontend TypeScript, ESLint (0 errors, 18 established Fast Refresh warnings), production build and current Bun tests (8/30) passed. Pint and Git whitespace check passed. There is no authenticated visual browser test framework in this project.
- Applicant cancellation, Dealer notifications, invitations/manager roles, active-context selection across multiple memberships, direct Dealer ordering/import, Tier assignment/history, Dealer pricing integration, Payment, Return/Refund and Promotion remain outside Phase 6. The existing Tier and pricing configuration predates this phase and was not connected to approval.
