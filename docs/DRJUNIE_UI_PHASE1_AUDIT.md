# DrJunie — Phase 1: Audit UI & Design System

Ngày thực hiện: 29/10/2026. Trạng thái: hoàn tất Phase 1, chờ yêu cầu bắt đầu Phase 2.

## Kết quả và ranh giới

Đã đọc route source, generated route tree, các layout, shared UI, các trang đang
được route sử dụng và stylesheet thực tế. Đã chuẩn bị bộ tokens có scope riêng và
quy chuẩn presentation để triển khai lần lượt. Chưa thay giao diện của route nào.

Landing Page `/` được giữ nguyên. Không chỉnh Laravel, API client, business logic,
database, dữ liệu, guards, quyền, route, payload hoặc event handlers. Không commit,
push hay deploy. Không đọc nội dung `backend/.env` để thực hiện audit giao diện.

Các tài liệu và artifact:

- [Kiểm kê route và components](DRJUNIE_UI_INVENTORY.md).
- [Design system và component contracts](../frontend/src/design-system/README.md).
- [Tokens có scope `.junie-theme`](../frontend/src/design-system/tokens.css), chưa import vào app.
- Baseline hero tại [1440 × 900](ui-phase-1/landing-1440.png) và
  [390 × 844](ui-phase-1/landing-390.png), đã mở ảnh kiểm tra trực quan.
- [Kết quả trình duyệt và contrast](ui-phase-1/baseline.json).

## 1. Kiểm kê kiến trúc thực tế

Frontend: React 19, TypeScript, TanStack Start/Router và Query, Tailwind CSS 4,
Radix UI/shadcn, Lucide, Sonner. Không có Storybook hay script test UI trong
`package.json`; có các file test riêng cho logic nghiệp vụ, không thuộc thay đổi này.

135 route definitions ngoài `__root.tsx`: 107 page definitions và 28 layout
definitions, tương ứng 107 URL patterns khi chuẩn hóa trailing slash. Có 54 TSX
modules trong `src/components`; feature UI được liệt kê riêng trong inventory.
Các số này đếm source, không phải số màn hình đã render/kiểm thử.

Root: `routes/__root.tsx` cung cấp QueryClient/Auth, `SiteLayout` và Outlet.
`SiteLayout.tsx` chọn shell theo pathname, đồng thời chứa cả `AdminShell`,
`AccountShell`, public header/footer và account footer. Đây là điểm phụ thuộc
chung cần xử lý cẩn thận để không thay nhiều portal cùng lúc.

| Khu vực                      | Layout hiện tại                                                                         | Route definitions | Nhận xét                                                                          |
| ---------------------------- | --------------------------------------------------------------------------------------- | ----------------: | --------------------------------------------------------------------------------- |
| Landing `/`                  | LovableHomePage tự render Nav/Hero/Sections/BookingJourney/Footer                       |                 1 | Chuẩn thiết kế, không chỉnh sửa                                                   |
| Public & Booking             | SiteLayout; `/doi-ngu-bac-si` tự render Nav/Footer; booking/auth dùng LovableFlowLayout |                26 | `/setup-password` chưa dùng FlowLayout; `/cart`, `/checkout/*` vẫn có RetailGuard |
| Customer                     | AccountShell trong SiteLayout, gồm `/account/*`, `/my-orders/*`                         |                13 | Sidebar 180px, nhiều shared components với admin/staff                            |
| Admin                        | AdminShell trong SiteLayout                                                             |                62 | Max content 1600px, nested routes, forms/drawers/tables dày đặc                   |
| Doctor, Receptionist, Dealer | StaffShell hoặc DealerLayout; `/dealer/apply` dùng public shell                         |                33 | Staff sidebar 220px/navy; dealer có nhiều màu hex riêng                           |

Quyền hiện tại được ghi nhận để bảo toàn: `/account` yêu cầu customer; `/admin`
yêu cầu admin; doctor/receptionist có role guard tương ứng. `/dealer` yêu cầu
customer và DealerRouteGuard; apply bỏ kiểm tra account đại lý nhưng vẫn giữ role
guard. RetailGuard trong cart/checkout/my-orders kiểm tra đăng nhập, không tự ý
thêm hạn chế role. Các guard cục bộ trong page vẫn được giữ nguyên.

`HomePage.tsx` và `BookingPage.tsx` là source cũ không được route hiện tại import;
route `/` dùng `LovableHomePage`, `/booking` dùng `TwoStepBookingPage`. Không xóa
source cũ trong audit. `routes/README.md` chứa hướng dẫn không tạo `pages/` trong
khi source hiện có dùng nhiều module `pages/`; giữ cấu trúc thực tế, không đổi kiến
trúc chỉ để khớp tài liệu cũ.

## 2. Landing Page làm chuẩn

Nguồn chính: `pages/LovableHomePage.tsx`, `components/landing/LandingTheme.css`,
`Hero.css`, `Nav.css`, `LuxButton.tsx`, `Sections.tsx`, `BookingJourney.css` và
`Responsive.css`. Có cả giá trị riêng cho hero/section; không suy diễn rằng mọi
giá trị trong Landing đều đã được token hóa.

| Thuộc tính           | Giá trị thực tế / cách dùng                                                                   |
| -------------------- | --------------------------------------------------------------------------------------------- |
| Màu nền              | Ivory `oklch(0.984 0.006 75)`, cream `oklch(0.963 0.013 75)`                                  |
| Bề mặt / điểm nhấn   | Sand `oklch(0.913 0.024 70)`, champagne `oklch(0.7 0.043 66)`                                 |
| Nội dung / hành động | Espresso `oklch(0.36 0.025 58)`, mocha `oklch(0.49 0.038 58)`, primary `oklch(0.47 0.039 58)` |
| Typography           | Manrope cho body/control; Cormorant Garamond 300/400 cho editorial heading                    |
| Nhịp bố cục          | Khoảng trống rộng, phân cấp bằng typography và đường viền mảnh; ảnh lớn với texture nhẹ       |
| Button               | CTA góc vuông/nhỏ, mocha hoặc line; hero cao tối thiểu 50px; login Nav radius 2px             |
| Card                 | Flow card radius 4px, border champagne alpha 30%, shadow mocha nhẹ                            |
| Hình ảnh             | Arch dành cho ảnh/editorial, không phải mẫu container của mọi form/table                      |
| Motion               | `cubic-bezier(0.22, 1, 0.36, 1)`, reveal/magnetic; có reduced-motion rules                    |

Browser xác nhận Manrope và Cormorant đã load ở desktop/mobile, hero không tràn
ngang tại 1440/390px, CTA vẫn trỏ `/booking` và `#dich-vu`. Đây là baseline hero,
không phải chứng nhận responsive cho toàn bộ Landing hoặc mọi route.

## 3. Sai lệch và rủi ro cần giải quyết

| Mức           | Vấn đề có bằng chứng trong source                                                                                                   | Hướng giải quyết / Phase                                                                                                    |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Cao           | `styles.css` root còn navy, Inter/Playfair, card trắng/radius 8px; Landing có scope palette/fonts riêng                             | Dùng scoped tokens, kích hoạt theo shell từng Phase 2–5; không đổi `:root` đồng loạt                                        |
| Cao           | `common/Button` dạng pill cao 44px; `ui/button` radius md cao 32–40px; ProductAdminShared có chuỗi class riêng; nhiều native button | Chuẩn hóa presentation trong shared components, giữ API và hành vi; Phase 2 đặt nền, Phase 4 xử lý consumers admin          |
| Cao           | `common/Fields` input cao 48px, `ui/input` 36px, ProductAdminShared 40px; Field mới hiển thị text error, chưa tự nối ARIA tới input | Thống nhất height/radius/focus/disabled; thêm liên kết error tại consumer, không đổi validation; Phase 2–5                  |
| Cao           | Radix content render ra body; Toaster nằm ngoài SiteLayout nên không tự kế thừa scoped theme                                        | Themed portal content/container và overlay; kiểm tra toast theo từng shell, không dùng body-wide override                   |
| Vừa           | `@theme inline` chưa map destructive/foreground dù `ui/button`, badge, alert có class destructive                                   | Bổ sung mapping trong Phase có consumer; chỉ khai báo CSS variable chưa đủ để tạo utility                                   |
| Vừa           | AdminShell/AccountShell/public header/footer nằm chung SiteLayout; styles có `body:has(.admin-portal)`                              | Giới hạn edit trong shell hiện hành, kiểm tra Landing sau mỗi thay đổi shared; không tách toàn bộ kiến trúc trong một Phase |
| Vừa           | DealerLayout hardcode `#092b5c`, `#f7f9fc`, `#eaf0fa` và các tone slate/blue khác                                                   | Chuyển sang tokens ở Phase 5, bảo toàn tier/wallet/approval labels và trạng thái                                            |
| Vừa           | 45 native table declarations, chưa có shared table primitive; density/toolbar/cell actions phân tán                                 | Shared presentation theo consumer thật ở Phase 4; không chuyển query/sort/filter/pagination vào abstraction mới             |
| Vừa           | Heading overrides riêng của admin/dealer và nhiều `font-semibold` tại consumer                                                      | Portal dùng Manrope rõ ràng cho dữ liệu, serif có chọn lọc; không ép toàn bộ heading theo editorial 300                     |
| Vừa           | `text-secondary` hiện dùng như màu chữ; secondary mới là sand rất nhạt                                                              | Review từng consumer và đổi sang muted/foreground phù hợp trước khi kích hoạt theme                                         |
| Vừa           | StatusBadge, SalesOrderStatusBadge và ui/badge có hình thức khác nhau                                                               | Dùng tone semantic, giữ nguyên status mapping/label; không chuyển tất cả trạng thái thành màu nâu                           |
| Sau migration | Loading/empty/error, disabled/pending, mobile menu, long data và keyboard chưa được review toàn bộ                                  | Kiểm tra ngay từng Phase và review chéo Phase 6; chưa tuyên bố đạt từ audit source                                          |

## 4. Nền tảng design system đã chuẩn bị

`tokens.css` chứa palette lấy nguyên từ Landing, alias tương thích tên semantic
hiện tại, font, spacing, radius, control/table height, container/sidebar width,
shadow, hover/pressed/focus và functional tones. Chỉ khai báo variables trên
`.junie-theme`; không có global selector và chưa được import. Giá trị mới cho UI
vận hành được đánh dấu là đề xuất, phân biệt với giá trị sao chép từ Landing.

`design-system/README.md` quy định cụ thể typography, density, component reuse,
portal inheritance, contrast, keyboard, responsive và trạng thái tương tác. Chưa
tạo thêm bộ Button/Input/Card cạnh tranh với shared UI đang tồn tại. Các Phase sau
sẽ sửa shared presentation khi có consumer thực tế, theo contract đã ghi.

Đã thử stylesheet trực tiếp trong Chrome với probe `.junie-theme`: CSS parse được,
alias resolve; các computed style được theo dõi trên Landing không đổi khi thêm
stylesheet. Đã kiểm tra contrast bằng sRGB canvas và relative luminance:

| Cặp màu tokens                 |   Tỷ lệ | Ngưỡng kiểm tra |
| ------------------------------ | ------: | --------------: |
| Foreground / background        | 10.44:1 |           4.5:1 |
| Muted text / background        |  6.02:1 |           4.5:1 |
| Primary text / primary surface |  6.55:1 |           4.5:1 |
| Input boundary / background    |  3.37:1 |             3:1 |
| Focus ring / background        |  6.55:1 |             3:1 |
| Success / success surface      |  8.17:1 |           4.5:1 |
| Warning / warning surface      |  7.59:1 |           4.5:1 |
| Danger / danger surface        |  7.09:1 |           4.5:1 |
| Info / info surface            |  7.46:1 |           4.5:1 |

Đây là kiểm tra các cặp token đặc, không thay thế kiểm tra alpha/opacity, nền ảnh,
overrides hoặc trạng thái trên components sau khi áp dụng vào màn hình.

## 5. Kế hoạch 6 Phase và điểm dừng

| Phase                                           | Phạm vi và sản phẩm bàn giao                                                                                                                                                                                                                                      | Kiểm tra và điều kiện hoàn tất                                                                                                                                                            |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **1. Audit UI & Design System**                 | Inventory routes/layouts/components, baseline Landing, gap analysis, scoped tokens, reuse contracts, kế hoạch                                                                                                                                                     | Đọc source thực; type/lint/build baseline; kiểm tra token/contrast và ảnh Landing; dừng sau báo cáo này                                                                                   |
| **2. Public Pages & Booking**                   | Public shell, services/category/detail, doctor/team/detail, blogs/detail, products/detail, promotions, lookup; login/register/forgot/setup-password; cart/checkout/success; booking/success. Chuẩn hóa shared Button/Fields/card presentation trong scope đã chọn | Type/lint/build; public desktop/mobile và keyboard; loading/empty/error; giữ OTP, slot conflict, voucher, validation, booking result và checkout; Landing không đổi                       |
| **3. Customer Portal**                          | AccountShell/footer, dashboard, appointments/detail, history, profile, notifications, vouchers, loyalty, my-orders/detail; tái sử dụng tokens/shared UI Phase 2                                                                                                   | Type/lint/build; customer navigation/drawer; lỗi form, thông báo, cancel/review/order-return UI với quyền và handler cũ; kiểm tra portal overlays                                         |
| **4. Admin Portal**                             | AdminShell/sidebar/header, dashboard, customer/staff/doctor/service/blog, ERP catalog/pricing/inventory/warehouse/supplier/procurement/order, dealer management/wallet/tier, promotion/voucher/review/report/audit/notification; forms/wizard/drawers/tables      | Triển khai theo nhóm màn trong cùng Phase; type/lint/build; kiểm tra representative list/detail/form và các trạng thái; giữ query/mutation/status/permission/financial behavior           |
| **5. Doctor, Receptionist & Dealer Portal**     | StaffShell, appointment/today/schedule/review/customer/notification screens; DealerLayout, apply/dashboard/products/orders/quick-order/import/profile/promotions/wallet/top-up/result                                                                             | Type/lint/build; kiểm tra role shells, tables, step/form, import feedback, long data và overlays; giữ account/tier/wallet/approval và khả năng thao tác cũ                                |
| **6. Responsive, UI Review & Final Validation** | Review chéo route inventory sau migration, xử lý sai lệch còn lại, responsive/a11y/consistency và ghi nhận vấn đề chưa giải quyết                                                                                                                                 | 360/390/768/1024/1440px, zoom 200%, keyboard/focus/reduced motion, long/empty/error/loading/success data; type/lint/build và smoke flows theo role; so Landing với baseline; không deploy |

Sau mỗi Phase: báo cáo phần đã xong, files, checks và lỗi còn tồn tại. Không tự
chuyển Phase. Riêng Phase 1 dừng tại đây; các mục 2–6 là kế hoạch, chưa thực hiện.

## 6. Validation và tồn tại

| Kiểm tra                   | Kết quả                                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------- |
| `tsc --noEmit`             | PASS                                                                                        |
| `eslint .`                 | PASS: 0 errors, 15 warnings Fast Refresh có sẵn                                             |
| `vite build`               | PASS; còn cảnh báo chunk >500kB và `inlineDynamicImports`/`codeSplitting` từ build hiện tại |
| Prettier các file text mới | PASS                                                                                        |
| Inventory                  | Mỗi route source có `fullPath` tương ứng trong generated tree; 135/135                      |
| Chrome baseline            | Hero desktop/mobile đã render; fonts load; không overflow ngang tại hai viewport; CTA đúng  |
| Scoped tokens              | CSS parse/alias/isolation PASS; 9/9 cặp contrast đạt ngưỡng đã nêu                          |
| Kiểm tra thay đổi          | Chỉ thêm tài liệu, baseline artifacts và design-system files; không sửa file source có sẵn  |

15 lint warnings nằm tại Cards (5), Status (1), ServiceExplorer (3), ServiceForm
(1), ui/badge (1), ui/button (1), AuthContext (1), InventoryOperationDrawer (1) và
ServiceDetailView (1), đều thuộc `react-refresh/only-export-components`. Giữ ngoài
phạm vi Phase 1 vì chưa thay logic/module structure.

Chưa kiểm thử dữ liệu và tương tác của authenticated portals; chưa chạy end-to-end
booking/checkout hoặc các mutation nghiệp vụ. Những kiểm tra đó thuộc Phase tương
ứng khi có thay đổi UI và tài khoản/dữ liệu phù hợp. Chưa áp dụng tokens vào giao
diện nên các sai lệch đã audit vẫn tồn tại cho tới khi triển khai Phase 2–5.
