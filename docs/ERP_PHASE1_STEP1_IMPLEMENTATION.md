# 1. Summary

Phase 1 Step 1 đã triển khai đúng Stage 1 — Expand của Customer Foundation. Backend hiện có canonical Customer schema, conflict/migration metadata schema, Customer model/factory/relationships và các test nền tảng. Booking hiện tại vẫn dùng ownership legacy; chưa có backfill, dual-write, dual-read hoặc cutover.

# 2. Open Decisions Applied

- Legacy guest backfill sau này sẽ tạo một Customer riêng cho mỗi appointment; Step 1 chưa chạy backfill hoặc clustering.
- Claim lịch sử sau này là theo từng appointment với booking code, phone và OTP; chưa có claim workflow.
- Customer code dùng format `CUS00000001`, sinh thuần từ immutable primary key và không dùng làm FK/security token.
- Receptionist chưa có Customer mutation; task không tạo CRUD/API/UI.
- Customer status chỉ gồm `active`, `inactive`, `merged`; không thêm `blocked`.

# 3. Migrations Added

- `2026_09_23_091747_create_customers_table.php`
- `2026_09_23_091749_create_customer_identity_conflicts_table.php`
- `2026_09_23_091750_create_customer_identity_conflict_candidates_table.php`
- `2026_09_23_091751_create_customer_migration_map_table.php`
- `2026_09_23_091753_add_customer_foundation_to_appointments_table.php`

Năm migration được áp dụng vào development database trong batch 21. Migrations chỉ mở rộng schema; không có câu lệnh backfill hoặc thay đổi dữ liệu business.

# 4. Schema Added

## Customers

`customers` gồm nullable unique `customer_code`, nullable unique `user_id`, current contact raw/normalized, verification timestamps, required `name`, `status`, `source`, nullable self-reference `merged_into_customer_id` và timestamps.

Normalized email/phone có non-unique lookup index. Customer list có composite index `(status, id)`. Email và phone cố ý không unique.

## Identity conflicts

`customer_identity_conflicts` lưu deterministic `conflict_key` dạng `char(64)` unique, source reference, reason/status, optional resolution target/actor/time/note. Index phục vụ pending queue và source lookup đã được tạo.

`customer_identity_conflict_candidates` lưu candidate Customer, match basis, confidence và `created_at`; tuple `(conflict_id, customer_id, match_basis)` là unique. Candidate rows cascade khi conflict bị xóa; Customer deletion vẫn bị restrict.

## Migration map

`customer_migration_map` lưu batch, normalization version, source, Customer, decision, optional conflict và `char(64)` input fingerprint. `(source_type, source_id)` là unique xuyên batch; Customer/conflict history không cascade delete.

## Appointment expand

Đã thêm nullable `customer_id`, `customer_name_snapshot`, `customer_email_snapshot`, `customer_phone_snapshot` và composite index `(customer_id, appointment_date, id)`. FK Customer dùng `RESTRICT` delete. Trigger ownership hiện có không bị sửa.

# 5. Models Added

- `App\Models\Customer` với status/source constants, timestamp casts và typed relationships.
- `App\Support\CustomerCode` với pure method `fromId()`; `1` thành `CUS00000001`, ID trên tám chữ số không bị truncate.

Không có matching, normalization, backfill, claim hoặc merge behavior trong model.

# 6. Relationships Added

- `Customer::user()` — belongsTo User.
- `User::customer()` — hasOne Customer.
- `Customer::appointments()` — hasMany Appointment qua `customer_id`.
- `Appointment::customer()` — belongsTo Customer.
- `Customer::mergedInto()` và `Customer::mergedSources()` — self relationships phục vụ lifecycle foundation.

Các relationship ownership hiện có của User/Appointment không thay đổi.

# 7. Factories Added

`CustomerFactory` có defaults cho name, raw/normalized email và phone, active status, guest-booking source và không tự tạo User. Factory hỗ trợ các state `withUser()`, `guest()`, `inactive()` và `merged()`.

Factory tạo contact tiện dụng cho test nhưng không giả định email/phone unique ở database.

# 8. Tests Added

- Schema assertions cho bốn bảng mới và bốn cột Appointment.
- Customer không User; database unique constraint cho optional User link.
- Duplicate normalized email/phone được phép.
- Active/inactive/merged factory states và merge relationships.
- User ↔ Customer và Customer ↔ Appointment relationships.
- Unique conflict key, conflict candidate tuple và migration source.
- Trigger ownership tiếp tục accept registered/guest legacy modes và reject mixed ownership.
- Customer code format, large ID và invalid ID.
- Existing registered/guest booking tests được tăng assertion để chứng minh Customer fields vẫn null.

# 9. Regression Results

Backend targeted regression:

```text
47 tests passed
280 assertions
```

Backend full regression:

```text
334 tests passed
1,821 assertions
```

Frontend regression:

```text
bunx tsc --noEmit: passed
bun run lint: passed with 0 errors and 18 pre-existing Fast Refresh warnings
bun run build: passed
```

Pint passed trên toàn bộ PHP files changed. `--dirty` không dùng được vì workspace không có Git metadata, nên formatter được chạy bằng explicit file list.

# 10. Database Changes

Development database đã chạy năm migration trong batch 21.

Sau migration:

```text
customers: 0
appointments: 22
appointments with customer_id = null: 22
customer_identity_conflicts: 0
customer_migration_map: 0
```

No customer backfill was executed. No existing appointment ownership was changed.

Index/FK inspection trên MySQL xác nhận unique Customer code/User link, non-unique normalized contact indexes, Customer history composite index, conflict/map indexes và delete rules theo design. Hai trigger `appointments_owner_before_insert` và `appointments_owner_before_update` vẫn tồn tại.

# 11. Existing Behavior Verified

- Registered booking tiếp tục ghi `user_id`, để `guest_*` null và để toàn bộ Customer fields null.
- Guest booking tiếp tục ghi `user_id=null`, đủ `guest_*` và để toàn bộ Customer fields null.
- Mixed registered/guest ownership vẫn bị database trigger reject.
- BookingService, request payloads, API resources, policies, scopes, routes và frontend không thay đổi.

# 12. Known Limitations

- Customer schema exists, but no existing customer data has been backfilled.
- Booking does not yet dual-write `customer_id`.
- Customer is not yet authoritative for appointment ownership.
- No Customer admin API/UI exists.
- No claim or merge workflow exists.
- Contact normalization và conflict generation chưa có production flow.
- Customer lifecycle invariants nâng cao như merged survivor/cycle checks chưa được DB-enforce ở Expand.

# 13. Deferred Work

- Registered Customer backfill.
- Guest Customer backfill theo policy một legacy appointment/một Customer.
- Versioned email/phone normalization.
- Restartable/dry-run backfill command và conflict creation.
- Dual-write, verification gates, dual-read và ownership cutover.
- Claim, merge và conflict-resolution workflows.
- Permission foundation và toàn bộ ERP transactional modules.

# 14. Files Changed

## Backend source

- `backend/app/Models/Customer.php`
- `backend/app/Models/User.php`
- `backend/app/Models/Appointment.php`
- `backend/app/Support/CustomerCode.php`
- `backend/database/factories/CustomerFactory.php`
- Năm migrations liệt kê tại mục 3.

## Tests

- `backend/tests/Feature/CustomerFoundationTest.php`
- `backend/tests/Unit/Support/CustomerCodeTest.php`
- `backend/tests/Feature/Api/AppointmentControllerTest.php`
- `backend/tests/Feature/Api/GuestAppointmentControllerTest.php`

## Documentation

- `docs/ERP_PHASE1_STEP1_IMPLEMENTATION.md`
- `.codex/HANDOFF.md`

Không có functional frontend source change.

# 15. Commands Executed

Các lệnh chính:

```text
composer show --direct
php artisan make:model Customer --factory --no-interaction
php artisan make:class Support/CustomerCode --no-interaction
php artisan make:migration ... --no-interaction
php artisan make:test --phpunit ... --no-interaction
vendor/bin/pint --dirty --format agent
vendor/bin/pint --format agent <changed PHP files>
php artisan migrate --pretend --no-interaction
php artisan migrate --no-interaction
php artisan migrate:status --no-interaction
php artisan test --compact <targeted files>
php artisan test --compact
bunx tsc --noEmit
bun run lint
bun run build
```

MySQL metadata queries chỉ đọc được dùng để kiểm tra counts, indexes, foreign keys và appointment triggers. Không chạy `migrate:fresh`, `db:wipe`, truncate hoặc delete.

# 16. Next Recommended Step

Phase 1 Step 2 — Customer Normalization + Restartable Registered Customer Backfill.

Production recovery sau khi Customer có dữ liệu sẽ dùng forward-fix/compatibility release; không dựa vào destructive rollback để drop populated Customer data.
