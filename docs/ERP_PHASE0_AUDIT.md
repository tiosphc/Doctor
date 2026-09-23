# 1. Executive Summary

Ngày audit: 2026-09-22  
Phạm vi: toàn bộ repository `D:\TTFIS\Doctor`, gồm Laravel API trong `backend/`, React/TanStack Start trong `frontend/`, migrations, dữ liệu development hiện hữu, cấu hình runtime và bộ test/build. Audit chỉ đọc và kiểm chứng; không triển khai ERP, không sửa business logic, không chạy migration phá hủy dữ liệu.

Project hiện là một hệ thống đặt lịch thẩm mỹ tương đối hoàn chỉnh: catalog dịch vụ/bác sĩ, lịch làm việc, đặt lịch khách đăng nhập và khách vãng lai, workflow tiếp nhận–khám–hoàn tất, review, voucher, loyalty theo số lần khám, notification, quản trị nhân sự và audit log. Backend có ranh giới quyền rõ và bộ test tốt; frontend dùng API thật, có xử lý CSRF/error/cache và build production thành công.

Kết luận ERP Phase 0:

- Kiến trúc Laravel, API client, authentication, transaction/locking, notification và audit logger là nền tảng có thể mở rộng.
- Project **chưa sẵn sàng để bắt đầu các module ERP giao dịch** vì chưa có canonical customer độc lập với account, permission vẫn là bốn role hard-code, voucher/loyalty phụ thuộc appointment, và hoàn toàn chưa có product/order/payment/inventory primitives.
- Dữ liệu customer là blocker lớn nhất: customer đăng nhập nằm trong `users`, guest nằm lặp lại trong từng `appointments`, không có liên kết guest → account và dữ liệu hiện tại đã có dấu hiệu trùng lặp.
- Vận hành email chưa đáng tin cậy: cấu hình runtime dùng SMTP nhưng có 21 failed jobs liên quan notification/invitation; không có worker/scheduler đang chạy tại thời điểm audit.
- Chất lượng hiện tại: 320/320 backend tests pass, 1.792 assertions; TypeScript, ESLint và production build pass. ESLint có 18 warning Fast Refresh, không có error. Composer không có security advisory tại thời điểm kiểm tra.

## Mức sẵn sàng theo khu vực

| Khu vực | Đánh giá | Lý do |
| --- | --- | --- |
| Auth | NEEDS SMALL CHANGE | Sanctum session/CSRF hoạt động và được test; chưa có reset password cho user thông thường. |
| Permission | NEEDS REFACTOR | Backend chặn theo role tốt, nhưng role/middleware/action đang hard-code; chưa có permission/ability model cho ERP. |
| Customer | BLOCKER | Không có canonical customer; guest data nằm trong appointment và không link lịch sử khi đăng ký. |
| Guest booking | NEEDS REFACTOR | Flow OTP/lookup/rate limit tốt, nhưng identity bị lặp và không thể dùng làm customer master. |
| Appointment | READY | State machine, transaction, row lock, availability, voucher snapshot và test cạnh tranh khá đầy đủ. |
| Doctor flow | READY | Chỉ xem/thao tác lịch được phân công; ownership được kiểm tra ở API và service. |
| Receptionist flow | READY | Có API riêng cho confirm/check-in/no-show/cancel/final complete; không gọi được doctor API. |
| Notification | NEEDS SMALL CHANGE | Audience hiện đúng vai trò; cần khắc phục delivery/failed jobs và giám sát queue. |
| Voucher | NEEDS REFACTOR | An toàn cho appointment một lần dùng, nhưng không có usage limit/redemption ledger/order scope. |
| Loyalty | NEEDS REFACTOR | Chỉ là milestone từ số appointment completed; không có điểm, ledger hoặc sales value. |
| Audit log | NEEDS SMALL CHANGE | Schema/logging nền tảng tốt; cần module/action ERP và cơ chế append-only/retention rõ hơn. |
| Email | BLOCKER | SMTP có cấu hình nhưng failed queue jobs tồn tại; general password recovery chưa có. |
| Database | NEEDS REFACTOR | FK/index/triggers tốt cho booking; thiếu customer master và toàn bộ schema ERP. |
| Frontend architecture | NEEDS REFACTOR | API/query/guards dùng được; nhiều file nghiệp vụ 800–2.200 dòng và chưa có automated frontend tests. |
| Backend architecture | READY | Controller/Request/Resource/Service separation tốt, test coverage mạnh; cần tách bounded context khi thêm ERP. |

# 2. Architecture

## Backend

- Runtime: PHP 8.4.12; `composer.json` yêu cầu PHP `^8.3`.
- Framework thực tế: Laravel Framework 13.31.0; Sanctum 4.3.3; PHPUnit 12.5.35; Boost 2.9.0; Pint 1.32.1.
- API có 103 route không tính vendor, chia theo public, authenticated customer, admin, receptionist và doctor.
- Authentication: Laravel Sanctum theo SPA cookie/session. Frontend gọi `/sanctum/csrf-cookie`, gửi credentials và XSRF header. `sessions` và queue dùng database; `personal_access_tokens` tồn tại nhưng UI hiện dùng session, không dùng bearer token làm đường chính.
- Middleware nghiệp vụ: `AuthenticateOptionalSanctum`, `EnsureUserIsAdmin`, `EnsureUserIsCustomer`, `EnsureUserIsReceptionist`, `EnsureUserIsDoctor`. Doctor middleware còn kiểm tra password setup, doctor profile liên kết và trạng thái active.
- Controller được tách public/customer/admin/staff. Input quan trọng dùng Form Request; output chính dùng API Resource.
- Service layer chứa logic giao dịch: `BookingService`, `AppointmentNotificationService`, `VoucherService`, `LoyaltyService`, `ReviewService`, account/lifecycle services và `AuditLogger`.
- Policy: `AppointmentPolicy` bảo vệ view/cancel/reschedule cho lịch thuộc customer. Staff còn được bảo vệ bằng route middleware, controller scope và service transition checks.
- Không có custom Events/Listeners hoặc custom Jobs. Notification implement queue trực tiếp; `AppointmentNotification` dùng `ShouldQueueAfterCommit`.
- Queue driver runtime: database. Scheduler chạy command nhắc lịch mỗi 5 phút. Tại thời điểm audit không có PHP API process, queue worker hoặc scheduler process đang chạy.
- Mail runtime resolve sang SMTP. OTP guest được gửi đồng bộ bằng Mailable; appointment/doctor invitation/reward notifications được queue.
- Error API chuẩn hóa JSON cho 401/403/404/409/419/422/429/500; business conflict trả 409.

## Frontend

- React 19.2, TypeScript 5.8.3, Vite 8.1.5, Tailwind CSS 4.2.1.
- Router: TanStack Router file-based 1.170.18; runtime/build dùng TanStack Start 1.168.32.
- Server state: TanStack React Query 5.101.1. API được chia theo domain trong `src/services/`.
- `api.ts` gửi cookie, tự lấy CSRF cho unsafe methods, retry một lần khi gặp 419, và chuẩn hóa lỗi 401/403/404/409/419/422/429/500.
- Auth state nằm trong `AuthContext`, query `/api/user`; UI guard theo admin/customer/receptionist/doctor. Backend vẫn là nguồn quyền quyết định.
- Mutation chính có pending/disabled state, hiển thị feedback và invalidates query liên quan. Confirm nguy hiểm dùng Radix alert dialog ở các màn hình quản trị.
- Không phát hiện localStorage/sessionStorage mock thay cho API thật.
- Các file nghiệp vụ quá lớn: `AdminPages.tsx` 2.215 dòng, `DoctorManagement.tsx` 1.318, `AccountPages.tsx` 1.258, `BookingPage.tsx` 1.220, `ServiceForm.tsx` 1.179, `ReviewVoucherPages.tsx` 978, `StaffPages.tsx` 869. Đây là rủi ro khi ghép thêm ERP.
- Chưa có script/test framework frontend; chất lượng hiện chỉ được kiểm bằng TypeScript, ESLint và build.

## Database và runtime

- MySQL 8.4.3, database development hiện có 23 bảng nghiệp vụ/hạ tầng và 29 migrations đã chạy.
- Timezone ứng dụng: `Asia/Ho_Chi_Minh`; locale vẫn là `en`; app name runtime vẫn là `Laravel`.
- FK dùng chủ yếu `RESTRICT` cho lịch sử nghiệp vụ; doctor account link dùng `SET NULL`; pivot/config phụ thuộc doctor dùng cascade.
- Không model nào dùng SoftDeletes.
- Appointment ownership được bảo vệ thêm bằng hai MySQL trigger insert/update: đúng một trong hai mode `user_id` hoặc bộ guest identity.
- `php artisan db:show --counts` không chạy trọn vẹn vì PHP CLI thiếu extension `intl`; đây là environment warning, không phải schema failure.

# 3. Existing Modules

| Module | Chức năng thực tế |
| --- | --- |
| Authentication/Profile | Register customer, login/logout/session, current user, update name/phone, doctor invitation/setup password. |
| Public catalog | Service category, service, doctor, availability, blog, published review. |
| Booking | Customer/guest booking, guest OTP, public lookup, cancel/reschedule, availability và chống double-book. |
| Customer account | Lịch hiện tại/lịch sử, profile, notification, voucher wallet, loyalty summary, review. |
| Admin | Dashboard, appointment, customer read view, doctor/schedule/time-off/service assignment, service/category, staff, blog, review moderation, voucher, audit log. |
| Receptionist | Dashboard/today/all appointments, customer list, confirm/check-in/no-show/cancel/final complete. |
| Doctor | Own dashboard/today/appointments/schedule/reviews, start examination, finish treatment step. |
| Notification | Database notification cho account; email cho customer/guest; reminders; read/unread API. |
| Voucher | Appointment percentage voucher từ review reward, loyalty milestone hoặc admin; one-time redemption. |
| Loyalty | Đếm completed appointments của registered customer; cấp voucher milestone. |
| Audit | Snapshot actor/action/module/target/old/new/request context cho các mutation được tích hợp. |

Không tồn tại module hoặc bảng cho product, product category, warehouse, inventory, stock movement/reservation/transfer, price list, sales order/return, payment, refund hoặc ERP reports.

## Bảng dữ liệu hiện tại

Tất cả bảng dưới đây không dùng soft delete.

| Bảng | Mục đích | PK / FK / unique chính | Relationship, status và bảng liên quan |
| --- | --- | --- | --- |
| `users` | Account cho admin, receptionist, doctor, customer | PK `id`; unique `email` | `role`, `must_change_password`; has appointments/reviews/vouchers/notifications, optional doctor profile. Phone không unique. |
| `password_reset_tokens` | Token broker Laravel/doctor invitation | PK `email` | Không FK; hiện chỉ flow setup doctor dùng broker, chưa có general reset UI/API. |
| `sessions` | Sanctum SPA/session | PK `id`; indexed nullable `user_id` | Không FK; session database. |
| `personal_access_tokens` | Hạ tầng Sanctum token | PK `id`; unique `token`; morph FK logic | Có thể dùng token, nhưng SPA hiện dùng cookie session. |
| `doctors` | Hồ sơ bác sĩ | PK `id`; nullable unique FK `user_id` → users, SET NULL | `is_active`; belongsTo user, services, schedules, time-offs, appointments, reviews. |
| `services` | Dịch vụ khám/thẩm mỹ | PK `id`; FK `category_id`; unique `slug` | `is_active`; belongsTo category, doctors; has appointments/reviews. |
| `service_categories` | Nhóm dịch vụ | PK `id`; unique `name`, `slug` | `is_active`; has services; delete bị restrict khi còn service. |
| `doctor_service` | Bác sĩ được phép làm dịch vụ | Composite PK `doctor_id`,`service_id`; hai FK cascade | Pivot, không status. |
| `doctor_schedules` | Ca làm việc theo thứ trong tuần | PK `id`; FK `doctor_id` cascade | `is_active`; overlap được kiểm trong service/controller. |
| `doctor_time_offs` | Nghỉ cả ngày/một phần ngày | PK `id`; FK `doctor_id` cascade | Không status; ảnh hưởng availability. |
| `appointments` | Booking và workflow khám | PK `id`; nullable FK `user_id`; FK doctor/service; nullable FK voucher đều restrict; unique `booking_code` | Guest fields, pricing snapshot, status, reminder/reschedule fields. Hai ownership triggers. |
| `reviews` | Review một lịch completed | PK `id`; unique FK `appointment_id`; FK user/doctor/service restrict | `status` published/hidden; một appointment tối đa một review. |
| `vouchers` | Voucher phần trăm dùng cho appointment | PK `id`; unique `code`; nullable FK `user_id`; unique (`user_id`,`source`,`source_id`) | `type=percentage`; `source`, `status`, expiry/used_at; linked từ appointment qua `voucher_id`. |
| `notifications` | Laravel database notifications | UUID PK `id`; polymorphic notifiable index | Read state qua `read_at`; data JSON chứa event/audience/action URL. |
| `audit_logs` | Nhật ký mutation | PK `id`; nullable FK `actor_id` SET NULL | `action`, `module`, target polymorphic snapshot, old/new/metadata JSON, IP/UA/method/URL. |
| `blogs` | Nội dung blog | PK `id`; FK author/category restrict; unique `slug` | `is_published`, `published_at`; belongsTo user/category. |
| `blog_categories` | Nhóm blog | PK `id`; unique `name`,`slug` | `is_active`; delete restrict khi còn blog. |
| `cache` | Laravel database cache | PK `key` | Hạ tầng. |
| `cache_locks` | Distributed cache lock | PK `key` | Dùng lock cho guest booking/rate-sensitive paths. |
| `jobs` | Queue pending | PK `id`; queue/reserved indexes | Database queue. Trống tại thời điểm audit. |
| `job_batches` | Batch metadata | String PK `id` | Hạ tầng queue. |
| `failed_jobs` | Failed queue history | PK `id`; unique `uuid` | Có 21 bản ghi thất bại tại thời điểm audit. |
| `migrations` | Migration registry | PK `id` | 29 migration entries đã applied. |

Không có `customers`, `employees`, payment, voucher-redemption ledger hoặc loyalty ledger table. “Employee” hiện được biểu diễn bằng `users.role=receptionist`; doctor là `users` liên kết 0/1 với `doctors`.

# 4. Customer Model

## Hiện trạng

- Customer có account chính là một row `users` với `role=customer`. Không có `Customer` model hoặc `customers` table.
- Một customer đăng nhập hiện tương ứng với một user, nhưng `users` đồng thời chứa admin/receptionist/doctor nên không phải customer master thuần nghiệp vụ.
- Appointment đăng nhập lưu `user_id`, còn `guest_name`, `guest_email`, `guest_phone` phải null.
- Appointment vãng lai lưu `user_id=null` và cả ba guest fields. Dữ liệu identity được lặp trên mỗi appointment.
- `booking_code` là unique và bắt buộc cho mọi appointment. Public lookup/cancel/reschedule xác thực bằng booking code + normalized phone; guest booking ban đầu còn yêu cầu OTP/token gắn với email.
- Trigger DB đảm bảo đúng một ownership mode; test cũng kiểm soát protected fields.

## Guest đăng ký account sau này

Không có cơ chế link hoặc claim lịch sử guest vào user mới. Register chỉ tạo `users`; không tìm guest appointment theo email/phone. Vì vậy lịch cũ không xuất hiện trong “my appointments”, không đóng góp loyalty, và vẫn là identity độc lập.

## Dấu hiệu duplicate trên dữ liệu development

- 1 nhóm guest email trùng qua nhiều appointment.
- 1 nhóm guest phone trùng qua nhiều appointment.
- 3 guest appointment rows có email trùng với email của user hiện hữu.
- Customer account không có nhóm phone trùng trong dữ liệu hiện tại, nhưng schema không có unique phone.
- Có 9 kết quả join guest phone ↔ user phone; dữ liệu seed tái sử dụng phone giữa nhiều role nên con số này không đồng nghĩa có 9 customer thực.

Không được merge chỉ dựa trên tên. Email/phone cũng cần verification, normalization và conflict policy trước khi claim lịch sử.

# 5. Appointment Workflow

## State machine thực tế

```text
pending -> confirmed -> checked_in -> in_progress -> treatment_done -> completed
    |          |             
    +----------+------------------------------> cancelled
               +------------------------------> no_show
```

Các transition chính theo code:

- `pending -> confirmed`: admin hoặc receptionist.
- `pending|confirmed -> cancelled`: admin/receptionist; customer hoặc guest chỉ với lịch của mình, còn trong tương lai và trước cutoff 2 giờ.
- `pending|confirmed -> rescheduled`: customer/guest; status giữ nguyên, tối đa 2 lần, backend tính lại end time và kiểm tra slot dưới row lock.
- `confirmed -> checked_in`: admin hoặc receptionist.
- `confirmed -> no_show`: admin hoặc receptionist, theo grace rule.
- `checked_in -> in_progress`: chỉ doctor được gán.
- `in_progress -> treatment_done`: chỉ doctor được gán.
- `treatment_done -> completed`: admin hoặc receptionist.
- Không có transition quay ngược hoặc skip step hợp lệ.

`BookingService` khóa doctor/appointment/voucher khi cần, kiểm tra schedule/time-off/overlap trong transaction và retry transaction tối đa ba lần. `BLOCKING_STATUSES` giữ slot cho các bước vận hành; terminal state không giữ slot. Pricing, voucher và original schedule được snapshot trên appointment.

## So với flow mong muốn

Flow thật khớp flow yêu cầu: customer đặt → operations xác nhận/check-in → doctor khám/finish treatment → operations final complete. Doctor không check-in và không final complete; receptionist không start/finish examination. Backend chặn trực tiếp, không phụ thuộc button frontend.

Bug “bác sĩ không thể bấm lịch sau check-in” không tái hiện qua code/test: route detail tồn tại, API chỉ trả lịch assigned, transition `checked_in -> in_progress` hợp lệ, mutation invalidates cache, và các test doctor flow pass. Nếu tái hiện trên browser thật cần capture request/response và browser log; audit không có bằng chứng để quy nguyên nhân hiện tại.

## Repeat request/double click

- Frontend mutation button chính bị disable khi pending.
- Backend state transition dưới row lock khiến request lặp thứ hai gặp invalid transition thay vì lặp side effect.
- Booking và voucher redemption dùng transaction/lock; test competing booking và voucher reuse pass.

# 6. Role & Permission Matrix

Ký hiệu: `All` = mọi record trong phạm vi module, `Own` = của customer, `Assigned` = của doctor được liên kết, `—` = backend không cho phép.

| Action | Admin | Receptionist | Doctor | Customer |
| --- | --- | --- | --- | --- |
| Xem lịch | All | All | Assigned | Own |
| Tạo lịch customer | — | — | — | Own |
| Xác nhận lịch | All | All | — | — |
| Check-in | All | All | — | — |
| Chuyển `in_progress` | — | — | Assigned | — |
| Chuyển `treatment_done` | — | — | Assigned | — |
| Final complete | All | All | — | — |
| No-show | All | All | — | — |
| Hủy lịch | All | All | — | Own, có cutoff/status rule |
| Reschedule | — | — | — | Own, có cutoff/limit |
| Quản lý bác sĩ/lịch/time-off | All | — | Chỉ xem lịch làm của mình | — |
| Xem/quản lý khách | Xem customer detail/list | Xem danh sách | Chỉ identity cần cho assigned appointment | Profile của mình |
| Quản lý service/blog/staff/review | All | — | Review assigned/published read-only | Own review |
| Quản lý voucher | All | — | — | Xem/redeem voucher hợp lệ của mình hoặc general admin voucher |
| Notification API | Own notifications | Own | Own | Own |
| Audit log | All, read-only API | — | — | — |
| Quản lý user/staff | Receptionist + doctor lifecycle | — | — | Chỉ tự cập nhật name/phone |

Guest không phải role: được tạo lịch sau OTP email, lookup/cancel/reschedule bằng booking code + phone và rate limit; không có notification database hoặc voucher.

Backend enforcement gồm Sanctum, role middleware, policy/ownership query, assigned `doctor_id` check và service-level transition. Test suite có case 401/403/404 cho cross-role và direct API access.

# 7. Notification Flow

| Event | Người nhận | Type/channel | Điều kiện/ghi chú |
| --- | --- | --- | --- |
| Appointment created | Customer account hoặc guest; tất cả admin + receptionist; doctor được gán | Customer: DB + queued mail; guest: queued mail; internal: DB | Không gửi cho doctor không liên quan. Audience được gắn customer/staff/doctor. |
| Appointment confirmed | Customer account hoặc guest | Customer: DB + queued mail; guest: queued mail | Operations đã có record trong portal; code hiện không phát thêm notification confirmed cho staff/doctor. |
| Appointment checked in | Doctor assigned | DB | Không broadcast cho mọi doctor. |
| Doctor starts examination | Không gửi notification mới | — | Audit transition vẫn được ghi. |
| Doctor marks treatment done | Admin + receptionist | DB | Mỗi operations account một notification. |
| Final completed | Customer/guest | DB/mail | Không tạo internal completion notification; có thể kích hoạt review invitation và loyalty evaluation. |
| Appointment cancelled/rescheduled | Customer/guest, operations, doctor assigned | DB/mail tùy recipient | Chỉ các audience liên quan. |
| Reminder | Customer/guest | DB/mail | Scheduler mỗi 5 phút; `reminder_sent_at` claim trong transaction trước khi queue. |
| Review invitation | Registered customer | DB | Chỉ completed eligible appointment. |
| Review reward / loyalty milestone | Registered customer | DB | Sau transaction, idempotency dựa vào unique reward source. |
| Doctor account invitation | Doctor user | Queued mail | Token setup password có expiry và resend throttle. |
| Guest OTP | Guest email | Synchronous mail | Rate-limited; generic failure/verification response. |

Notification listing/read endpoints chỉ thao tác relationship notification của user authenticated. Test xác nhận isolation, recipients và reminder idempotency. Dữ liệu cũ có row audience null; migration gần đây chỉ backfill notification doctor-created cụ thể. Delivery không được xem là healthy chỉ vì code tồn tại: `failed_jobs` đang có 18 `AppointmentNotification` và 3 `DoctorAccountInvitation` failures liên quan SMTP/Gmail connection.

# 8. Voucher & Loyalty

## Voucher hiện tại

- Code unique, normalize uppercase; loại duy nhất là percentage.
- Source: `review_reward`, `loyalty_milestone`, `admin`.
- Reward voucher thuộc một `user_id`; general admin voucher có `user_id=null`.
- Có expiry và status `active|used|expired|revoked`; effective expiry còn được tính runtime khi status vẫn active.
- Không có start date riêng, tổng usage limit, per-user usage counter hoặc bảng redemption ledger.
- Mỗi appointment chỉ gắn tối đa một voucher; không stacking. Giá gốc/discount/final được backend snapshot.
- Voucher được lock và đánh dấu used trong cùng transaction tạo appointment. Concurrent/double redemption bị chặn.
- Cancel appointment hợp lệ restore voucher đúng một lần nếu voucher chưa hết hạn; appointment vẫn giữ `voucher_id` làm lịch sử.
- General voucher không có giới hạn user nhưng vẫn one-time toàn cục vì status chuyển used.

Kết luận: logic đủ an toàn cho appointment promotion một lần dùng. Không nên dùng trực tiếp cho Sales Order vì scope, eligibility, allocation, redemption history, usage limit, line/order discount, return/refund reversal và promotion composition chưa tồn tại.

## Loyalty hiện tại

- Không có điểm hoặc ledger.
- `LoyaltyService` đếm appointment `completed` của registered customer.
- Config hiện có milestone 10 visits → voucher 25%, expiry 30 ngày.
- Unique (`user_id`,`source`,`source_id`) ngăn phát voucher milestone trùng.
- Guest appointment không tính và không tự chuyển thành lịch sử customer khi guest đăng ký.

Appointment loyalty và sales loyalty nên tách rule/ledger. Có thể dùng chung canonical customer và reward issuance abstraction sau này, nhưng không dùng “completed appointment count” như sales points.

# 9. Audit Log

Schema lưu actor id/name/role, action, module, target type/id/name, description, old/new values, metadata, IP, user agent, method, URL và timestamp. Actor snapshot vẫn tồn tại nếu user sau đó bị xóa. API chỉ đọc và chỉ admin truy cập.

Code hỗ trợ module `STAFF`, `DOCTOR`, `APPOINTMENT`, `SERVICE`, `VOUCHER`, `REVIEW`, `BLOG`, cùng action create/update/delete/activate/deactivate và appointment transitions. Dữ liệu development hiện có mới tập trung ở appointment/staff/voucher do audit migration được thêm gần đây; không có backfill lịch sử tổng quát.

`AuditLogger` loại password, confirmation/current/new password, remember/access/refresh/API token, reset/verification token, authorization, OTP, API key và các key chứa secret/password/token. Đây là nền tảng tốt cho ERP.

Gap ERP:

- Chưa có module/action price change, stock adjustment/reservation/transfer, sales order confirm, payment, refund, return hoặc permission changes.
- Append-only chỉ được bảo vệ ở tầng API/convention; database chưa cấm update/delete audit rows.
- Chưa có retention/export/partition/archival policy.
- Cần xác định field nào của ERP là PII/payment-sensitive trước khi đưa vào old/new snapshots.

# 10. Existing Bugs

## BUG-01

ID: ERP-AUDIT-001  
Severity: Medium  
Area: Frontend role handling / booking  
Steps: Đăng nhập bằng receptionist → header vẫn hiện “Đặt lịch” → mở `/booking` → chọn dịch vụ/bác sĩ/slot → submit. Doctor có thể mở trực tiếp URL dù CTA bị ẩn.  
Expected: Chỉ guest hoặc customer được vào customer booking flow; staff được chặn/redirect ngay.  
Actual: `BookingPage` chỉ chặn admin; mọi authenticated role khác được coi là `!isGuest`, không hiện guest identity fields và gọi customer appointment API. Backend trả 403 nên không có privilege escalation, nhưng UX đi hết flow rồi thất bại.  
Probable cause: Frontend dùng `isGuest = !user`, guard chỉ kiểm `user.role === "admin"`; header CTA loại admin/doctor nhưng không loại receptionist.  
Relevant files: `frontend/src/pages/BookingPage.tsx`, `frontend/src/components/layout/SiteLayout.tsx`, `frontend/src/services/appointmentApi.ts`, `backend/app/Http/Requests/StoreAppointmentRequest.php`.  
Recommended fix: Tạo guard explicit `guest|customer`; ẩn CTA cho mọi staff role; thêm frontend role test. Không nới quyền backend.

## BUG-02

ID: ERP-AUDIT-002  
Severity: High  
Area: Email / queue operations  
Steps: Dispatch appointment mail hoặc doctor invitation trong môi trường hiện tại; kiểm tra `failed_jobs`.  
Expected: Queued email được worker gửi thành công hoặc retry/alert có kiểm soát.  
Actual: Có 21 failed jobs: 18 appointment notifications và 3 doctor account invitations; exception history cho thấy SMTP/Gmail connection failure. Không có queue worker/scheduler process đang chạy tại thời điểm audit.  
Probable cause: SMTP/Google connection hoặc credential/network configuration không ổn định và thiếu supervised worker/monitoring; không phải lỗi transaction/domain test.  
Relevant files: `backend/config/mail.php`, `backend/config/queue.php`, `backend/app/Notifications/AppointmentNotification.php`, `backend/app/Notifications/DoctorAccountInvitation.php`, `backend/app/Console/Commands/SendAppointmentReminders.php`.  
Recommended fix: Chuẩn hóa SMTP credential/app-password hoặc mail provider, chạy worker/scheduler dưới process supervisor, cấu hình retry/backoff/alert, rồi thực hiện smoke test non-production trước khi retry jobs. Không expose secret.

## BUG-03

ID: ERP-AUDIT-003  
Severity: Medium  
Area: Guest OTP email branding/localization  
Steps: Request guest OTP với cấu hình runtime hiện tại.  
Expected: Email mang thương hiệu JUNIE và nội dung tiếng Việt đồng nhất UI.  
Actual: Subject/body OTP là tiếng Anh; heading lấy `config('app.name')`, runtime hiện là `Laravel`, nên có thể hiển thị “Laravel booking verification”.  
Probable cause: `APP_NAME` chưa được đặt theo sản phẩm và Mailable/template còn default English.  
Relevant files: `backend/.env`, `backend/.env.example`, `backend/config/app.php`, `backend/app/Mail/GuestBookingOtpMail.php`, `backend/resources/views/mail/guest-booking-otp.blade.php`.  
Recommended fix: Đặt app name đúng theo environment và locale hóa subject/template; thêm mail rendering test kiểm brand/locale.

## BUG-04

ID: ERP-AUDIT-004  
Severity: Medium  
Area: Authentication / account recovery  
Steps: Từ login chọn “Quên mật khẩu?”.  
Expected: Customer/receptionist/admin có thể yêu cầu reset password an toàn.  
Actual: Trang chỉ thông báo backend chưa có API; password broker hiện mới phục vụ doctor invitation/setup password.  
Probable cause: General password recovery chưa được triển khai.  
Relevant files: `frontend/src/pages/AuthPages.tsx`, `frontend/src/routes/forgot-password.tsx`, `backend/routes/api.php`, `backend/app/Services/DoctorAccountService.php`.  
Recommended fix: Thiết kế rate-limited forgot/reset flow cho account thông thường, generic response chống enumeration, queued mail và token invalidation tests.

## BUG-05

ID: ERP-AUDIT-005  
Severity: Low  
Area: Documentation / onboarding  
Steps: Đọc root/frontend/backend README để dựng hệ thống.  
Expected: Tài liệu mô tả stack/API và cách chạy hiện tại.  
Actual: Frontend README vẫn mô tả bản mock/Lovable và không backend/React Router; code thật dùng Laravel API + TanStack Router/Query. Backend README chủ yếu là Laravel template.  
Probable cause: Documentation không được cập nhật cùng các phase gần đây.  
Relevant files: `README.md`, `frontend/README.md`, `backend/README.md`, `.codex/HANDOFF.md`.  
Recommended fix: Sau khi chốt Phase 0, cập nhật README từ nguồn cấu hình/script thật và giữ handoff là log kỹ thuật, không thay README vận hành.

# 11. Technical Debt

- Customer identity bị trộn giữa account và snapshot guest; đây là debt ảnh hưởng trực tiếp mọi ERP transaction/customer report.
- Role là string và route middleware hard-code bốn role. Thêm warehouse/accounting/sales role bằng cách nhân middleware sẽ khó kiểm soát và audit.
- `BookingService` hơn 1.100 dòng, đang gánh booking, schedule safety, state transition, voucher và audit orchestration. ERP không nên nối thêm sales/inventory logic vào service này.
- Frontend gom nhiều page/domain trong file 800–2.200 dòng; query keys và mutation invalidation sẽ khó quản lý khi thêm ERP.
- `src/types/index.ts` gom gần 500 dòng type của nhiều domain; nên tách contract theo bounded context trước ERP UI lớn.
- Logout chỉ remove một số query keys; cache role/customer khác còn trong memory cho tới khi gc/refetch. Backend vẫn an toàn, nhưng cần clear user-scoped query cache có hệ thống khi đổi account.
- Không có automated component/E2E test frontend; các lỗi role/CTA như BUG-01 không được bắt.
- Appointment/voucher/status dùng string constants ở code, không có DB CHECK; migration ERP phải tránh namespace/status collision.
- Không có SoftDeletes. Nhiều FK dùng restrict bảo toàn lịch sử nhưng master-data lifecycle ERP cần deactivate/archive policy rõ.
- Audit log mới được đưa vào gần đây và không backfill lịch sử; dashboard/report không nên suy diễn audit history là đầy đủ.
- Frontend build cảnh báo Vite đã hỗ trợ tsconfig paths native và 18 Fast Refresh warnings; không blocker nhưng tăng nhiễu CI.

# 12. ERP Reuse Map

| Existing component | Reusable for ERP? | Required changes |
| --- | --- | --- |
| Sanctum SPA auth/CSRF/session | Có | Bổ sung reset password, session/queue deployment hardening và permission abilities. |
| Role middleware | Một phần | Thay/bao bằng permission matrix/policies; không nhân thêm role string checks cho từng module ERP. |
| Form Requests + API Resources + JSON errors | Có | Duy trì per-domain request/resource; chuẩn hóa error codes cho ERP workflows. |
| Service transaction/row-lock pattern | Có | Áp dụng cho stock reservation, transfer, order confirm/payment; tách service theo aggregate. |
| Appointment state machine | Chỉ làm mẫu | Không reuse statuses cho Sales Order; xây state machine/order invariants riêng. |
| `users` | Có cho account | Không dùng làm customer master; liên kết nullable từ canonical customer. |
| Guest fields trên appointment | Chỉ làm snapshot | Backfill vào customer master; giữ immutable contact snapshot khi cần lịch sử. |
| Service catalog | Một phần | Service không phải Product/SKU; chỉ có thể làm reference cho service line tương lai. |
| Voucher service/table | Một phần nhỏ | Tách promotion definition, issuance, redemption ledger, scope/limits/order allocation/reversal. |
| Loyalty milestone | Một phần nhỏ | Giữ reward concept; tạo points/events ledger và rule source cho sales/appointment riêng. |
| Notification framework | Có | Thêm ERP events/audiences/templates, outbox/delivery monitoring và queue operations. |
| AuditLogger/schema | Có | Thêm modules/actions ERP, append-only control, retention và sensitive-field policy. |
| Admin API/layout/query patterns | Có | Tách feature modules, route-level authorization, query key factory và lazy chunks. |
| Existing appointment price snapshot | Không làm payment ledger | Có thể tham khảo snapshot semantics; doanh thu/payment/refund phải có entities riêng. |

## Gap analysis cho từng module ERP dự kiến

| ERP module | Reuse hiện có | Cần tạo mới / conflict / risk |
| --- | --- | --- |
| Customer | User auth, appointment contact data | Canonical `customers`, verified contacts, merge/claim rules. Risk duplicate và backfill ownership cao. |
| Product | Service catalog UI/API pattern | Product/SKU/UOM/tax/cost/status mới. Không đổi `services` thành products vì dependency doctor/duration/booking. |
| Product Category | Service category CRUD pattern | Bảng/category riêng; tránh FK/slug semantics của service. Migration risk thấp. |
| Warehouse | Admin CRUD/audit pattern | Warehouse/location/address/status mới; permission theo scope. |
| Inventory | Transaction/locking pattern | Inventory balance/projection mới; không lưu quantity mutable không ledger. Concurrency risk cao. |
| Stock Movement | Audit pattern | Immutable movement header/lines/source/reference mới. Audit log không thay stock ledger. |
| Stock Reservation | Booking slot reservation concept | Reservation entity/expiry/release/commit mới; không reuse appointment statuses. Concurrency/deadlock risk cao. |
| Stock Transfer | State machine pattern | Transfer + lines + source/destination + posting mới; permission separation. |
| Price List | Service price snapshot idea | Price lists/items/effective dates/customer segment/currency mới. Price-change audit bắt buộc. |
| Sales Order | Transaction/state pattern | Order/header/lines/totals/tax/discount/customer snapshots/status mới. Không dùng appointment làm order. |
| Sales Return | Cancel/reversal idea | Return authorization/lines/stock/payment effects mới; reversal phải tham chiếu original order. |
| Payment | Không có domain component | Payment/tender/allocation/status/reference/idempotency mới; high security/audit risk. |
| Refund | Không có domain component | Refund/allocation/provider result/reconciliation mới; không đồng nhất với appointment cancel. |
| Voucher | Code/expiry/percentage/lock pattern | Promotion/voucher/redemption ledger và order scope mới; conflict trực tiếp với one-use appointment voucher. |
| Buy X Get Y | Không có | Promotion conditions/actions, qualifying lines, free/discount allocation mới. |
| Buy A Get B | Không có | Cùng promotion engine, product scopes/quantity conditions mới. |
| ERP Reports | Dashboard aggregation pattern | Reporting/read models cho sales/stock/payment; không báo cáo tài chính từ `appointments.final_price`. |

# 13. ERP Blockers

1. **Chưa có canonical customer master.** Guest identity lặp theo appointment và không link account; mọi Sales Order/Payment/Loyalty ERP sẽ làm duplicate nặng hơn nếu xây tiếp trên `users`/guest fields.
2. **Permission model không đủ chi tiết cho ERP.** Bốn role hard-code chưa biểu diễn các ability như price override, stock adjustment, order confirm, payment/refund hoặc warehouse scope.
3. **Email/queue production path chưa healthy.** 21 failed jobs và không có worker/scheduler active; invitation, reminder và transactional communication không đáng tin cậy.
4. **Voucher/loyalty chưa có ledger và bị khóa vào appointment.** Reuse trực tiếp sẽ gây conflict về usage/reversal/order scope.
5. **Không có sales/inventory/payment aggregates.** `appointments.final_price` không phải invoice/revenue/payment; phải thiết kế domain mới trước báo cáo ERP.
6. **Chưa có migration/backfill/dedup plan.** Tạo FK ERP vào identity hiện tại có thể sinh orphan, duplicate hoặc gắn sai lịch sử guest.

# 14. Recommended Data Model Direction

Khuyến nghị **Phương án B**:

```text
customers
  id
  user_id nullable, unique
  name
  primary_email nullable
  primary_phone nullable
  normalized_email/phone
  verification/status/merge metadata
       |
       +-- appointments.customer_id
       +-- future sales_orders.customer_id
       +-- loyalty_accounts.customer_id
```

Giữ contact/name snapshot trên appointment/order để bảo toàn lịch sử tại thời điểm giao dịch, nhưng ownership/reporting trỏ về `customers.id`.

Ưu điểm dựa trên project thật:

- Guest có customer identity trước khi có login; đăng ký sau chỉ link `user_id`, không cần đổi PK toàn bộ lịch sử.
- Một canonical customer dùng chung appointment và Sales Order, trong khi account/auth vẫn tách khỏi business party.
- Có nơi đặt verified contacts, dedup/merge history, consent và lifecycle mà không làm phình `users` cho admin/doctor/receptionist.
- Loyalty/voucher/sales reports có cùng chủ thể.

Nhược điểm/rủi ro:

- Cần backfill guest và registered appointments, normalization và manual conflict queue.
- Không thể unique thô email/phone trước khi xử lý shared household/reused seed/legacy data.
- Claim guest history phải yêu cầu proof (verified email/phone), có audit và rollback; không tự merge theo tên.
- Phải triển khai theo expand/backfill/dual-read-or-write/constraint/cutover thay vì migration một bước.

Phương án A (`users -> customer_profile`) không phù hợp vì guest chưa có user và `users` chứa mọi role. Phương án C giữ nguyên sẽ tiếp tục nhân identity khi ERP tạo Sales Order cho walk-in customer.

# 15. Recommended Next Step

Thực hiện một design task độc lập: **đặc tả canonical Customer (Phương án B) và kế hoạch migration/backfill không mất dữ liệu**, gồm identity normalization, guest/account matching rules, conflict queue, appointment snapshot strategy, staged constraints, rollback và acceptance tests. Chưa tạo migration hoặc code trước khi đặc tả này được duyệt.

# 16. Files Inspected

Các nhóm/file trọng yếu đã đọc trực tiếp:

- Project instructions/continuity: `AGENTS.md`, `CLAUDE.md`, `.codex/HANDOFF_RULES.md`, `.codex/HANDOFF.md`, root/backend/frontend README, `frontend/src/routes/README.md`.
- Backend dependency/config/routes: `backend/composer.json`, `backend/routes/api.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `config/{app,auth,booking,cors,database,loyalty,mail,queue,review,services,session}.php`, `.env.example`, `phpunit.xml`.
- Tất cả 29 migration files hiện có trong `backend/database/migrations/` và các seeders chính.
- Models: toàn bộ `backend/app/Models/`.
- Services: toàn bộ `backend/app/Services/`, đặc biệt `BookingService`, notification/voucher/loyalty/review/audit/account lifecycle.
- Controllers: auth, appointment/guest/notification/voucher/loyalty/review; toàn bộ admin appointment/customer/voucher/audit; doctor/receptionist portal và các controller management liên quan.
- Requests/middleware/policy/resources liên quan auth, appointment, guest, staff, voucher, notification và audit.
- Notifications/Mail/command: toàn bộ `backend/app/Notifications/`, `GuestBookingOtpMail`, mail view và `SendAppointmentReminders`.
- Tests: inventory toàn bộ 33 test files; đọc/đối chiếu các suite auth, appointment, guest, staff portal, notification, voucher, loyalty, audit, admin access/customer/dashboard/doctor và database foundation.
- Frontend dependency/core: `package.json`, `bun.lock`, router/query bootstrapping, `AuthContext`, guards/layout, `api.ts`, toàn bộ domain service files và `src/types/index.ts`.
- Frontend business/UI trọng yếu: `BookingPage`, `LookupPage`, `AuthPages`, `AdminPages`, `AdminAuditLogPage`, `DoctorManagement`, `AccountPages`, `StaffPages`, `ReviewVoucherPages`, service explorer/detail/form và file-based routes.

# 17. Commands / Tests Executed

| Command/check | Result |
| --- | --- |
| `composer show --direct` / package inspection | Xác nhận Laravel 13.31.0, Sanctum 4.3.3, PHPUnit 12.5.35 và versions trực tiếp. |
| `php artisan about` và `config:show` | Xác nhận PHP/runtime, MySQL, DB queue/session, SMTP, timezone và app name. |
| `php artisan route:list -v` / `--json` | 103 non-vendor routes; middleware/role scope khớp mô tả. |
| `php artisan migrate:status` | Tất cả 29 migrations đã applied; không chạy migration mới. |
| `php artisan schedule:list` | Reminder command mỗi 5 phút. |
| Read-only schema/data queries | Xác nhận 23 tables, constraints/triggers và aggregate không chứa PII nêu trong báo cáo. |
| `php artisan db:show --counts` | ENVIRONMENT WARNING: thiếu PHP `intl`; vẫn xác nhận MySQL 8.4.3 trước khi dừng phần counts. |
| `php artisan test --compact` | PASS: 320 tests, 320 passed, 1.792 assertions, khoảng 46,16 giây. Test dùng `aesthetic_clinic_testing`, mail array, queue sync. |
| `composer validate --no-check-publish` | PASS: `composer.json` valid. |
| `composer audit --locked` | PASS: không có security vulnerability advisory. |
| `bunx tsc --noEmit` | PASS. |
| `bun run lint` | PASS với 0 error, 18 pre-existing Fast Refresh warnings. |
| `bun run build` | PASS: client, SSR và Nitro production build thành công. Có non-blocking warnings về native tsconfig paths và `inlineDynamicImports`. |
| Process/port inspection | MySQL ban đầu không chạy và được start bằng cấu hình Laragon hiện hữu để thực hiện read-only audit/test; không có PHP API/worker/scheduler process tại thời điểm kiểm tra. |
| `git status --short` | Không áp dụng: workspace không phải Git repository (`.git` không tồn tại). |

Không chạy `migrate:fresh`, không chạy destructive command, không gửi email thật, không retry failed jobs và không expose secret.

# 18. Code Changes

No functional code changes were made during this audit.

Các thay đổi duy nhất:

- Tạo tài liệu audit `docs/ERP_PHASE0_AUDIT.md`.
- Cập nhật project handoff để ghi nhận kết quả audit và verification; không thay đổi backend, frontend, schema, dependency hoặc dữ liệu nghiệp vụ.
