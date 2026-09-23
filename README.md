# Junie Aesthetic & Dermatology

Hệ thống quản lý và đặt lịch trực tuyến cho phòng khám da liễu – thẩm mỹ Junie. Dự án hỗ trợ khách vãng lai, khách hàng có tài khoản, lễ tân, bác sĩ và quản trị viên; quản lý xuyên suốt từ lúc khách chọn dịch vụ đến khi hoàn thành khám, đánh giá và nhận quyền lợi thành viên.

## 1. Tổng quan công nghệ

| Thành phần | Công nghệ |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Cơ sở dữ liệu | MySQL |
| Xác thực | Laravel Sanctum, session cookie và CSRF |
| Frontend | React 19, TypeScript, TanStack Start/Router |
| Quản lý dữ liệu frontend | TanStack Query |
| Giao diện | Tailwind CSS 4, shadcn/Radix UI |
| Kiểm thử backend | PHPUnit 12 |

Dự án được tách thành hai ứng dụng:

- `backend/`: Laravel REST API, nghiệp vụ và truy cập cơ sở dữ liệu.
- `frontend/`: giao diện React dành cho khách hàng và các vai trò vận hành.

## 2. Business chính

Nghiệp vụ trung tâm của hệ thống là quản lý toàn bộ vòng đời lịch khám:

```text
Dịch vụ
  → Chọn bác sĩ phù hợp
  → Chọn ngày
  → Tính khung giờ còn trống
  → Đặt lịch
  → Lễ tân xác nhận và check-in
  → Bác sĩ bắt đầu và hoàn thành khám
  → Lễ tân hoàn tất lịch hẹn
  → Khách hàng đánh giá
  → Nhận voucher và quyền lợi thành viên
```

### 2.1. Đặt lịch

Khách hàng đặt lịch theo bốn bước:

1. Chọn dịch vụ.
2. Chọn bác sĩ đang phụ trách dịch vụ và đã có lịch làm việc.
3. Chọn ngày, giờ và nhập thông tin liên hệ.
4. Xác nhận lịch hẹn và chọn voucher nếu có.

Khung giờ không được hardcode. Backend tính giờ trống từ:

- Lịch làm việc trong `doctor_schedules`.
- Thời lượng của dịch vụ.
- Lịch nghỉ trong `doctor_time_offs`.
- Các lịch hẹn đang giữ chỗ trong `appointments`.
- Thời điểm hiện tại nếu khách đặt trong ngày hôm nay.

Logic chính nằm trong [`backend/app/Services/BookingService.php`](backend/app/Services/BookingService.php). Khi tạo hoặc đổi lịch, backend khóa các bản ghi liên quan trong transaction và kiểm tra lại giờ trống trước khi ghi dữ liệu nhằm hạn chế đặt trùng.

### 2.2. Vòng đời lịch hẹn

```text
pending
  → confirmed
  → checked_in
  → in_progress
  → treatment_done
  → completed
```

Các nhánh phụ:

- `pending` hoặc `confirmed` có thể chuyển sang `cancelled`.
- `confirmed` có thể chuyển sang `no_show` sau thời gian chờ cấu hình.

Quy tắc chuyển trạng thái được định nghĩa trong [`backend/app/Models/Appointment.php`](backend/app/Models/Appointment.php) và thực thi tập trung trong `BookingService`.

### 2.3. Đặt lịch không cần tài khoản

Guest vẫn có thể đặt lịch qua quy trình:

1. Nhập email và yêu cầu OTP.
2. Nhận OTP qua email.
3. Xác minh OTP để nhận verification token dùng một lần.
4. Gửi thông tin đặt lịch.
5. Nhận mã đặt lịch để tra cứu hoặc hủy sau này.

OTP được lưu dưới dạng hash trong cache, có thời hạn và giới hạn số lần nhập sai. Nghiệp vụ này nằm trong [`backend/app/Services/GuestBookingVerificationService.php`](backend/app/Services/GuestBookingVerificationService.php).

### 2.4. Đánh giá, voucher và loyalty

- Chỉ lịch đã `completed` mới được đánh giá.
- Mỗi lịch hẹn chỉ có một đánh giá.
- Đánh giá hợp lệ có thể phát hành voucher giảm giá.
- Khách hàng đạt mốc lượt khám được nhận voucher loyalty.
- Mỗi lịch chỉ áp dụng tối đa một voucher.
- Voucher đã sử dụng được khôi phục khi lịch bị hủy nếu voucher chưa hết hạn.

Các nghiệp vụ tương ứng nằm trong:

- [`ReviewService.php`](backend/app/Services/ReviewService.php)
- [`VoucherService.php`](backend/app/Services/VoucherService.php)
- [`LoyaltyService.php`](backend/app/Services/LoyaltyService.php)

## 3. Vai trò người dùng

| Vai trò | Chức năng chính |
| --- | --- |
| Guest | Xem dịch vụ, bác sĩ và bài viết; xác minh OTP; đặt lịch; tra cứu và hủy lịch bằng mã đặt lịch |
| Customer | Quản lý lịch cá nhân, đổi lịch, hủy lịch, đánh giá, sử dụng voucher và theo dõi quyền lợi thành viên |
| Receptionist | Xác nhận lịch, check-in, đánh dấu vắng, hủy và hoàn tất lịch sau khi bác sĩ khám xong |
| Doctor | Xem lịch của chính mình, bắt đầu khám, hoàn thành điều trị, xem lịch làm việc và đánh giá |
| Admin | Quản lý bác sĩ, nhân viên, dịch vụ, lịch làm việc, lịch nghỉ, khách hàng, lịch hẹn, blog, đánh giá và voucher |

Phân quyền backend được áp dụng bằng middleware trong [`backend/bootstrap/app.php`](backend/bootstrap/app.php).

## 4. Cấu trúc thư mục

```text
Doctor/
├── backend/                         Laravel REST API
│   ├── app/
│   │   ├── Console/Commands/        Lệnh chạy nền và nhắc lịch
│   │   ├── Http/
│   │   │   ├── Controllers/         Nhận request và trả response
│   │   │   │   └── Api/
│   │   │   │       ├── Admin/       API quản trị viên
│   │   │   │       └── Staff/       API bác sĩ và lễ tân
│   │   │   ├── Middleware/          Xác thực và phân quyền
│   │   │   ├── Requests/            Validation đầu vào
│   │   │   └── Resources/           Chuẩn hóa JSON trả về
│   │   ├── Mail/                     Email OTP
│   │   ├── Models/                   Eloquent model và quan hệ
│   │   ├── Notifications/            Thông báo database/email
│   │   ├── Policies/                 Quyền truy cập tài nguyên
│   │   ├── Services/                 Business logic chính
│   │   └── Support/                  Tiện ích dùng chung
│   ├── config/                       Cấu hình booking, loyalty, reward
│   ├── database/
│   │   ├── factories/                Dữ liệu phục vụ test
│   │   ├── migrations/               Cấu trúc cơ sở dữ liệu
│   │   └── seeders/                  Dữ liệu khởi tạo
│   ├── routes/                       Route API và scheduler
│   └── tests/                        PHPUnit feature test
│
├── frontend/                         React/TanStack application
│   ├── src/
│   │   ├── assets/                   Ảnh tĩnh
│   │   ├── components/               Component tái sử dụng
│   │   │   ├── admin/                Component quản trị
│   │   │   ├── common/               Button, field, loading, error
│   │   │   ├── layout/               Layout công khai và nhân viên
│   │   │   ├── loyalty/              Quyền lợi thành viên
│   │   │   ├── notifications/        Chuông và danh sách thông báo
│   │   │   ├── reviews/              Giao diện đánh giá
│   │   │   ├── services/             Giao diện dịch vụ
│   │   │   └── ui/                   shadcn/Radix UI primitives
│   │   ├── contexts/                 Trạng thái xác thực
│   │   ├── data/                     Nội dung giao diện tĩnh
│   │   ├── hooks/                    React hooks dùng chung
│   │   ├── lib/                      Hàm tiện ích
│   │   ├── pages/                    Màn hình nghiệp vụ
│   │   ├── routes/                   File-based routing
│   │   ├── services/                 Lớp gọi Laravel API
│   │   ├── types/                    TypeScript types
│   │   └── styles.css                CSS và cấu hình giao diện
│   └── package.json
│
├── .codex/                            Thông tin bàn giao dự án
├── .agents/                           Skill và hướng dẫn phát triển
├── AGENTS.md                          Quy tắc làm việc của dự án
└── README.md                          Tài liệu tổng quan này
```

## 5. Luồng xử lý backend

```text
HTTP Request
  → routes/api.php
  → Middleware xác thực và phân quyền
  → Form Request kiểm tra dữ liệu
  → Controller điều phối
  → Service xử lý nghiệp vụ
  → Model/Eloquent đọc hoặc ghi MySQL
  → API Resource chuẩn hóa JSON
  → HTTP Response
```

### Controllers

- `app/Http/Controllers/Api/`: API công khai, khách hàng và guest.
- `app/Http/Controllers/Api/Admin/`: chức năng quản trị.
- `app/Http/Controllers/Api/Staff/`: cổng bác sĩ và lễ tân.

Toàn bộ endpoint chính được khai báo trong [`backend/routes/api.php`](backend/routes/api.php).

### Requests

`app/Http/Requests/` chứa validation cho từng nghiệp vụ, ví dụ:

- `StoreAppointmentRequest`: dữ liệu đặt lịch.
- `AvailableSlotsRequest`: bác sĩ, dịch vụ và ngày lấy giờ trống.
- `StoreDoctorRequest`: thông tin tạo bác sĩ.
- `ReplaceDoctorSchedulesRequest`: lịch làm việc theo tuần.
- `StoreReviewRequest`: dữ liệu đánh giá.

### Services

`app/Services/` chứa nghiệp vụ có thể dùng lại:

| Service | Trách nhiệm |
| --- | --- |
| `BookingService` | Giờ trống, đặt lịch, đổi lịch, hủy lịch, trạng thái lịch |
| `DoctorAccountService` | Tạo hồ sơ và tài khoản bác sĩ, gửi thư mời |
| `DoctorLifecycleService` | Kích hoạt, ngừng hoạt động và xóa bác sĩ an toàn |
| `AppointmentNotificationService` | Chọn người nhận thông báo lịch hẹn |
| `ReviewService` | Tạo và cập nhật đánh giá |
| `VoucherService` | Phát hành, sử dụng và hoàn lại voucher |
| `LoyaltyService` | Tính lượt khám và phát thưởng thành viên |
| `AdminDashboardService` | Tổng hợp dữ liệu dashboard quản trị |

Dự án không có Repository layer riêng; các service truy vấn Eloquent Model trực tiếp.

### Models và quan hệ dữ liệu

Các model nghiệp vụ chính:

- `User`
- `Doctor`
- `Service`, `ServiceCategory`
- `DoctorSchedule`, `DoctorTimeOff`
- `Appointment`
- `Review`
- `Voucher`
- `Blog`, `BlogCategory`

```text
User ──< Appointment >── Doctor
                    ├── Service
                    └── Voucher

Doctor >──< Service       qua doctor_service
Doctor ──< DoctorSchedule
Doctor ──< DoctorTimeOff
Appointment ──1 Review
```

## 6. Luồng xử lý frontend

```text
TanStack Route
  → Page nghiệp vụ
  → React Query
  → API service
  → Laravel REST API
  → Cập nhật cache
  → Render component
```

### `src/routes`

Dự án sử dụng file-based routing. Ví dụ:

- `booking.tsx` tương ứng `/booking`.
- `account.appointments.$id.tsx` tương ứng `/account/appointments/{id}`.
- `admin.doctors.$id.tsx` tương ứng `/admin/doctors/{id}`.
- `doctor.appointments.$id.tsx` tương ứng `/doctor/appointments/{id}`.

File route chỉ nên đọc tham số URL, khai báo metadata và gọi Page/Component. `routeTree.gen.ts` được TanStack tự sinh, không sửa thủ công.

### `src/pages`

- [`BookingPage.tsx`](frontend/src/pages/BookingPage.tsx): luồng đặt lịch bốn bước.
- `account/AccountPages.tsx`: tài khoản, lịch cá nhân, đổi lịch và loyalty.
- `admin/AdminPages.tsx`: quản lý bác sĩ, lịch hẹn và khách hàng.
- `admin/AdminDashboardPage.tsx`: dashboard quản trị.
- `staff/StaffPages.tsx`: giao diện bác sĩ và lễ tân.
- `ServicesPage.tsx`, `ServiceExplorerDetailPage.tsx`: dịch vụ công khai.
- `ReviewVoucherPages.tsx`: đánh giá và voucher.

### `src/services`

Đây là lớp giao tiếp với backend:

- `api.ts`: fetch dùng chung, CSRF, cookie và xử lý lỗi HTTP.
- `authApi.ts`: đăng nhập, đăng ký, đăng xuất.
- `appointmentApi.ts`: đặt, đổi, hủy và tra cứu lịch.
- `doctorApi.ts`: bác sĩ và giờ trống.
- `adminApi.ts`: API quản trị.
- `staffApi.ts`: API bác sĩ và lễ tân.
- `notificationApi.ts`, `reviewVoucherApi.ts`, `loyaltyApi.ts`: các nghiệp vụ bổ sung.

## 7. Bản đồ chức năng và file xử lý

| Chức năng | Backend chính | Frontend chính |
| --- | --- | --- |
| Đăng nhập, đăng ký | `AuthController`, `User`, Sanctum middleware | `AuthContext`, `authApi`, `AuthPages` |
| Đặt lịch | `AppointmentController`, `AvailableSlotController`, `BookingService` | `BookingPage`, `appointmentApi`, `doctorApi` |
| Guest OTP | `GuestBookingVerificationController`, `GuestBookingVerificationService` | `BookingPage`, `appointmentApi` |
| Quản lý bác sĩ | `Api/Admin/Doctor*`, `DoctorAccountService`, `DoctorLifecycleService` | `DoctorManagement`, `AdminPages`, `adminApi` |
| Lịch làm việc/ngày nghỉ | `DoctorScheduleController`, `DoctorTimeOffController`, `BookingService` | `DoctorManagement`, `adminApi` |
| Vận hành lễ tân | `ReceptionistAppointmentController` | `StaffPages`, `staffApi` |
| Cổng bác sĩ | `DoctorPortalController` | `StaffPages`, `staffApi` |
| Dịch vụ và danh mục | `ServiceController`, `ServiceCategoryController` | `ServicePages`, `ServiceForm`, `ServiceExplorer` |
| Đánh giá | `ReviewController`, `ReviewService` | `ReviewVoucherPages`, `reviewVoucherApi` |
| Voucher và loyalty | `VoucherService`, `LoyaltyService` | `ReviewVoucherPages`, `LoyaltyCard`, `loyaltyApi` |
| Thông báo | `AppointmentNotificationService`, `Notifications/` | `useNotifications`, `NotificationBell`, `NotificationItem` |
| Nhắc lịch tự động | `SendAppointmentReminders`, `routes/console.php` | Hiển thị qua hệ thống notification |
| Dashboard admin | `AdminDashboardService`, `DashboardController` | `AdminDashboardPage`, `components/admin/dashboard` |
| Blog | `BlogController`, `BlogCategoryController` | `BlogPages`, `BlogAdminPage` |

## 8. Cơ sở dữ liệu

Các bảng nghiệp vụ chính:

- `users`
- `doctors`
- `services`, `service_categories`
- `doctor_service`
- `doctor_schedules`
- `doctor_time_offs`
- `appointments`
- `reviews`
- `vouchers`
- `notifications`
- `blogs`, `blog_categories`

Migration nằm trong `backend/database/migrations/`. Dữ liệu khởi tạo nằm trong `backend/database/seeders/`; factory trong `backend/database/factories/` chỉ phục vụ test và tạo dữ liệu phát triển.

## 9. Thông báo và tác vụ nền

- Thông báo lịch hẹn sử dụng database notification và email.
- Thư mời bác sĩ thiết lập mật khẩu được đưa vào queue.
- Lệnh `appointments:send-reminders` gửi nhắc lịch sắp tới.
- Scheduler chạy lệnh nhắc lịch mỗi năm phút và chống chạy chồng lặp.

Khi chạy môi trường phát triển cần có queue worker và scheduler nếu muốn kiểm tra đầy đủ thông báo.

## 10. Chạy dự án ở local

### Backend

```bash
cd backend
composer install
php artisan migrate --no-interaction
php artisan serve --host=localhost --port=8000
```

Chạy queue và scheduler ở hai terminal khác:

```bash
cd backend
php artisan queue:work
```

```bash
cd backend
php artisan schedule:work
```

### Frontend

```bash
cd frontend
bun install
bun run dev
```

Frontend mặc định kết nối API qua `VITE_API_URL`. Khi dùng Sanctum cookie, frontend và backend nên sử dụng cùng một hostname, ví dụ đều dùng `localhost`.

## 11. Kiểm thử và kiểm tra chất lượng

Backend:

```bash
cd backend
php artisan test --compact
vendor/bin/pint --format agent
```

Frontend:

```bash
cd frontend
bunx tsc --noEmit
bun run lint
bun run build
```

## 12. Lưu ý vận hành

- Production phải cấu hình SMTP thật; mailer `log` chỉ phù hợp môi trường phát triển.
- Queue worker phải chạy để gửi email và database notification kịp thời.
- Scheduler phải chạy để gửi nhắc lịch tự động.
- Ảnh tải lên sử dụng Laravel public disk và cần `backend/public/storage` trỏ đến `backend/storage/app/public`.
- Không chạy `migrate:fresh` trên cơ sở dữ liệu có dữ liệu thật nếu chưa có xác nhận rõ ràng.
- Không sửa trực tiếp `frontend/src/routeTree.gen.ts` vì đây là file được sinh tự động.

## 13. Tóm tắt

Junie là hệ thống full-stack quản lý phòng khám thẩm mỹ, trong đó `Appointment` là nghiệp vụ trung tâm kết nối khách hàng, bác sĩ và dịch vụ. Laravel chịu trách nhiệm xác thực, phân quyền, validation, tính giờ trống, chống đặt trùng và xử lý vòng đời lịch hẹn. React/TanStack cung cấp giao diện riêng cho từng vai trò và đồng bộ dữ liệu với backend qua REST API. Ngoài đặt lịch, hệ thống còn hỗ trợ quản lý bác sĩ, lịch làm việc, ngày nghỉ, thông báo, đánh giá, voucher, loyalty, dịch vụ và nội dung blog.
