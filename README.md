# ERP Sales & Dealer Management

Hệ thống ERP bán hàng và quản lý đại lý, tập trung vào các nghiệp vụ sản phẩm, bảng giá, đơn hàng, kho, tồn kho, khách hàng, đại lý và chương trình khuyến mãi.

Dự án hỗ trợ hai kênh bán hàng chính:

- **Retail**: khách hàng mua lẻ theo giá Retail.
- **Dealer**: đại lý đặt số lượng lớn theo Tier, số dư ví và khu vực phục vụ của kho.

## 1. Công nghệ sử dụng

| Thành phần | Công nghệ |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Database | MySQL |
| Authentication | Laravel Sanctum |
| Frontend | React 19, TypeScript, TanStack Start/Router |
| Data fetching | TanStack Query |
| UI | Tailwind CSS 4, shadcn/Radix UI |
| Backend testing | PHPUnit 12 |

Cấu trúc chính:

```text
backend/     Laravel REST API và business logic
frontend/    React application cho Retail, Dealer và Admin
```

## 2. Chức năng chính

### 2.1. Quản lý sản phẩm

- Quản lý sản phẩm, danh mục và thương hiệu.
- Hỗ trợ SKU và nhiều đơn vị bán như `piece`, `box`.
- Quản lý giá Retail và giá theo Dealer Tier.
- Quản lý tồn kho theo kho.
- Hiển thị trạng thái tồn kho trên giao diện quản trị.

### 2.2. Bảng giá

Hệ thống có hai nhóm giá chính:

- **Retail Price**: giá bán cho khách hàng thông thường.
- **Dealer Price**: giá riêng cho từng Tier đại lý.

Các Tier hiện tại:

```text
Silver → Gold → Diamond
```

Giá Dealer có thể được điều chỉnh theo kho/khu vực phục vụ trước khi áp dụng promotion.

Luồng tính giá tổng quát:

```text
Retail / Silver / Gold / Diamond
        ↓
Warehouse Adjustment
        ↓
Product Override
        ↓
Promotion
        ↓
Final Price
```

## 3. Khách hàng và đại lý

### Retail

Khách hàng có thể:

- Xem sản phẩm.
- Thêm sản phẩm vào giỏ hàng.
- Đặt đơn.
- Chọn COD hoặc chuyển khoản.
- Theo dõi trạng thái đơn hàng.
- Gửi yêu cầu trả hàng trong thời gian cho phép.

### Dealer

Đại lý có:

- Tier riêng: Silver, Gold hoặc Diamond.
- Giá sản phẩm theo Tier.
- Ví tiền dùng để thanh toán đơn Dealer.
- Quick Order cho một khách hàng.
- Excel Import để tạo đơn cho nhiều khách hàng.
- Theo dõi đơn hàng và gửi yêu cầu hủy/hoàn trả khi phù hợp.

Tài khoản Dealer phải được Admin duyệt trước khi sử dụng đầy đủ chức năng đặt hàng.

## 4. Quick Order và Excel Import

### Quick Order

Quick Order phục vụ trường hợp đại lý đặt hàng cho **một khách hàng**.

Luồng chính:

```text
Chọn sản phẩm
  → Nhập số lượng
  → Xem lại đơn
  → Xác nhận
  → Nhập địa chỉ khách hàng
  → Backend chọn kho phù hợp
  → Tạo đơn hàng
```

Người dùng có thể tùy chọn lưu địa chỉ để sử dụng lại sau.

### Excel Import

Excel Import dùng khi đại lý cần tạo đơn cho **nhiều khách hàng**.

Các cột chính:

```text
customer_name
phone
province
district
address_line
sku
quantity
```

Backend tự gộp các dòng thành cùng một đơn khi chúng có cùng:

```text
Tên khách hàng + Số điện thoại + Địa chỉ
```

Dealer không cần nhập mã đơn hàng trong file Excel.

## 5. Quản lý đơn hàng

Workflow chính:

```text
Draft
  → Pending
  → Confirmed
  → Picking
  → Shipped
  → Completed
```

Các trạng thái bổ sung:

```text
Cancelled
Returned / Refunded
```

Một số nguyên tắc:

- Retail cần Admin xác nhận đơn.
- Đơn chưa thanh toán không được chuyển thẳng sang `Completed`.
- Dealer có thể hủy trước khi Admin xác nhận nếu điều kiện cho phép.
- Đơn đã thanh toán phải đi qua quy trình Refund thay vì hủy trực tiếp.
- Yêu cầu trả hàng được gửi cho Admin xét duyệt.

## 6. Kho và tồn kho

Hệ thống phân biệt rõ các nghiệp vụ:

### Tồn đầu kỳ

Dùng để thiết lập số lượng ban đầu khi bắt đầu quản lý tồn kho cho sản phẩm.

### Điều chỉnh tồn

Dùng khi cần tăng hoặc giảm tồn do kiểm kê hoặc chênh lệch thực tế.

### Nhập kho

Dùng để ghi nhận hàng thực tế được nhập vào kho.

### Đơn mua / nhận hàng

Dùng để quản lý quá trình mua hàng từ nhà cung cấp và ghi nhận số lượng, giá nhập.

Dealer không tự chọn kho. Backend xác định kho dựa trên tỉnh/thành của địa chỉ giao hàng và cấu hình khu vực phục vụ.

## 7. Promotion và Voucher

### Product Promotion

Hệ thống hỗ trợ:

- Giảm phần trăm trực tiếp trên sản phẩm.
- Mua X tặng Y.
- Mua sản phẩm A tặng sản phẩm B.
- Điều kiện theo sản phẩm.
- Điều kiện theo Dealer Tier.
- Phạm vi áp dụng: Retail, Dealer hoặc Both.

Một sản phẩm tại cùng một thời điểm chỉ được nằm trong tối đa **một promotion giảm giá trực tiếp đang có hiệu lực**.

Promotion hết thời gian hiệu lực sẽ không tiếp tục khóa sản phẩm.

### Voucher

Voucher được sử dụng cho Retail và áp dụng ở cấp đơn hàng.

Dealer không sử dụng Voucher nhưng vẫn có thể nhận Product Promotion nếu promotion được cấu hình cho Dealer hoặc Both.

## 8. Ví đại lý

Dealer Wallet dùng để quản lý số dư của đại lý.

Các nghiệp vụ chính:

- Khởi tạo ví.
- Admin cộng tiền vào ví.
- Ghi nhận lịch sử giao dịch.
- Trừ tiền khi thanh toán đơn Dealer.
- Hoàn tiền về ví khi Refund được duyệt.

Mọi thay đổi số dư cần có transaction/ledger tương ứng để có thể đối soát.

## 9. Vai trò hệ thống

| Vai trò | Chức năng chính |
| --- | --- |
| Customer | Xem sản phẩm, đặt đơn Retail, thanh toán, theo dõi và yêu cầu trả hàng |
| Dealer | Xem giá Dealer, Quick Order, Excel Import, quản lý đơn và ví |
| Admin | Quản lý sản phẩm, giá, kho, khách hàng, Dealer, đơn hàng, promotion và voucher |

## 10. Cấu trúc dự án

```text
Doctor/
├── backend/                 Laravel REST API
│   ├── app/
│   │   ├── Http/
│   │   ├── Models/
│   │   ├── Services/
│   │   └── Support/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── routes/
│   └── tests/
│
├── frontend/                React application
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── routes/
│   │   ├── services/
│   │   └── types/
│   └── package.json
│
└── README.md
```

## 11. Chạy dự án local

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=localhost --port=8000
```

Nếu dự án sử dụng queue:

```bash
php artisan queue:work
```

### Frontend

```bash
cd frontend
bun install
bun run dev
```

Frontend kết nối backend thông qua biến môi trường như `VITE_API_URL`.

## 12. Kiểm tra trước khi deploy

### Backend

```bash
cd backend
php artisan test
```

### Frontend

```bash
cd frontend
bunx tsc --noEmit
bun run lint
bun run build
```

Không commit file `.env` hoặc các thông tin bí mật lên Git repository.

## 13. Deployment

Frontend có thể deploy lên Vercel.

Backend Laravel cần môi trường hỗ trợ PHP, database và các tiến trình cần thiết như queue worker. Với production, backend thường phù hợp hơn với VPS hoặc nền tảng hỗ trợ Laravel/PHP đầy đủ.

Các biến môi trường production cần được cấu hình trực tiếp trên nền tảng deploy thay vì commit vào repository.

## 14. Tóm tắt

Dự án tập trung vào ba nhóm nghiệp vụ chính:

```text
Sales
  + Dealer Management
  + Inventory / Warehouse
```

Hệ thống cho phép vận hành đồng thời kênh Retail và Dealer, quản lý bảng giá theo Tier, tồn kho theo kho, đơn hàng, ví đại lý, chương trình khuyến mãi và quy trình trả hàng/hoàn tiền trong cùng một hệ thống ERP.
