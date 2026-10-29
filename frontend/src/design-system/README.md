# DrJunie — UI foundation (Phase 1)

Trạng thái: đã audit và chuẩn bị tokens; **chưa kích hoạt trên bất kỳ route nào**.
Nguồn chuẩn là `/` → `LovableHomePage`, không phải `HomePage.tsx`.
Xem [audit và kế hoạch 6 Phase](../../../docs/DRJUNIE_UI_PHASE1_AUDIT.md) và
[kiểm kê source](../../../docs/DRJUNIE_UI_INVENTORY.md).

## Tokens và cách áp dụng từ Phase 2

`tokens.css` chỉ khai báo custom properties trên `.junie-theme`, không sửa `:root`,
`body`, `.lovable-home` hoặc selector toàn cục. Chưa import stylesheet này vào app.
Phase 2 sẽ import nó tại layout được chuyển đổi và thêm scope cho đúng vùng đó:

```tsx
import "@/design-system/tokens.css";

<section className="junie-theme bg-background font-[family-name:var(--font-sans)] text-foreground">
    {/* Existing UI, migrated within the current phase. */}
</section>;
```

Đây là ví dụ áp dụng sau này, không phải chỉ cần thêm class là hoàn tất migration.
Global heading rules, `@utility card-surface`, utilities `font-sans`/`font-display`, button pill,
màu hardcode và các overrides của admin/dealer vẫn phải được thay có chủ đích.
Giữ nguyên selectors/style của Landing Page. Không import `LandingTheme.css` để
biến mọi portal thành `.lovable-home`: file đó chứa cả animation và quy tắc editorial.

Các alias `--background`, `--primary`, `--font-sans`… tương thích tên hiện tại trong
`styles.css`. Font và shadow trong `@theme inline` hiện dùng literal, nên utility
`font-sans`, `font-display`, `shadow-card` không tự đổi khi chỉ override variables
trên scope. Phải dùng `var(...)` như ví dụ hoặc sửa mapping trong Phase áp dụng.
Các token `--junie-*` chưa có utility Tailwind riêng; dùng
`var(--junie-...)` hoặc thêm mapping `@theme inline` trong Phase cần dùng. Riêng
`--destructive`/`--destructive-foreground` cũng chưa được map vào `@theme` hiện tại;
phải bổ sung trước khi dùng các utility tương ứng của Radix Button/Alert.

CSS custom properties chỉ truyền qua cây DOM. `Dialog`, `Sheet`, `Popover`,
`DropdownMenu`, `AlertDialog` render qua portal ra `body`, nên content và overlay
cần nhận scope riêng hoặc portal container nằm trong scope. `Toaster` đang ở ngoài
`SiteLayout`, cần xử lý theme theo khu vực khi đến Phase liên quan. Tránh gán theme
cho `body` vì sẽ ảnh hưởng Landing Page và những Phase chưa triển khai.

## Phân biệt giá trị đã có và đề xuất mở rộng

| Nhóm                                                                                   | Nguồn / quyết định                                                                    |
| -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Ivory, cream, sand, champagne, mocha, espresso, primary, muted text, decorative border | Sao chép chính xác từ `components/landing/LandingTheme.css`                           |
| Manrope / Cormorant Garamond, easing                                                   | Đã được dùng ở Landing Page; fonts đã khai báo trong `routes/__root.tsx`              |
| Card radius 4px và shadow mocha                                                        | Có ở `.lovable-flow .card-surface`; Landing có thêm CTA radius 0–2px và ảnh arch      |
| Semantic background/card/accent                                                        | Theo Landing; popover dùng cùng ivory để phủ vùng portal chưa được Landing định nghĩa |
| Spacing, cỡ chữ vận hành, control heights, table, sidebar, focus và status tones       | Đề xuất cho các portal, cần review tại từng Phase; không mô tả là giá trị gốc Landing |
| Input border và ring                                                                   | Đề xuất đậm hơn decorative border/champagne để đọc được trạng thái control            |
| `--navy-deep` → espresso                                                               | Alias chuyển tiếp cho source cũ; dần chuyển consumer sang tên semantic                |

## Typography và bố cục

- Public/editorial: Cormorant Garamond 300/400 cho tiêu đề lớn; Manrope cho nội dung.
- Portal: Manrope 500/600 cho heading vận hành, label, table và số liệu. Chỉ dùng
  display serif cho lời chào/điểm nhấn phù hợp, không áp serif đồng loạt vào bảng.
- Body 16px/1.65, label/control/table 14px, metadata 13px. Input trên mobile 16px.
- Page title portal 28–36px; section title 20px. Editorial title 40–72px.
- Nhịp spacing 4/8/12/16/20/24/32/40/48/64px; card padding 20–24px;
  gap giữa sections 24–32px; form field gap 8px, group gap 24px.
- Public container tối đa 1400px; data screen tối đa 1600px; form đơn giản 768px.
  Giữ `adminFormLayout` presets cho form phức tạp, không ép wizard về 768px.
- Gutter 16–24px mobile, 32–40px desktop. Sidebar đề xuất 256px, collapse bằng
  drawer trên màn nhỏ; tái sử dụng Radix focus management đã có.

## Component contracts cho các Phase sau

| Thành phần               | Hướng tái sử dụng và tiêu chí                                                                                                                                                                                                                                                                 |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Button / ButtonLink      | Chuẩn hóa `common/Button.tsx`, giữ API hiện có; dùng `ui/button.tsx` cho Radix `asChild`. Chia sẻ style/variants giữa hai nơi; không tạo hệ button thứ ba. Primary mocha → espresso khi hover, outline rõ viền, ghost có nền hover; cao 44px, radius 4px. Không thay `LuxButton` của Landing. |
| Card                     | Nâng cấp `card-surface` trong scope được migrate; ivory/cream, viền mảnh, radius 4px, shadow nhẹ. Shared Card chỉ thêm khi có consumer thực tế; không dùng arch cho form/bảng.                                                                                                                |
| Form                     | Chuẩn hóa `common/Fields.tsx`, `ui/input.tsx`, `ProductAdminShared.tsx` theo một style contract. Label rõ, hint/error có ID, `aria-describedby`, `aria-invalid`; disabled/read-only khác nhau. Giữ validation và payload hiện có.                                                             |
| Sidebar                  | Dùng cùng khoảng cách, cỡ icon, active/hover/focus; active có nền sand và `aria-current`. Giữ nguyên menu và điều kiện quyền; không gộp navigation data của các role.                                                                                                                         |
| Table                    | Source đang có 45 `<table>` declarations; chưa có shared table primitive. Tách phần trình bày chung khi migrate Admin: semantic caption/headers, header cream, hàng ≥48px, số liệu `tabular-nums`, scroll ngang cục bộ. Giữ sort/filter/pagination/selection ở consumer.                      |
| Status                   | Dùng `StatusBadge`, `SalesOrderStatusBadge` hiện có. Giữ label và mapping nghiệp vụ; success/warning/danger/info vẫn phân biệt bằng chữ/icon và màu.                                                                                                                                          |
| Feedback                 | Dùng `AsyncState`, `OperationNotice`, `SuccessDialog`, Sonner; đồng bộ loading/empty/error/success, có retry nếu consumer đã hỗ trợ.                                                                                                                                                          |
| Dialog / Sheet / Popover | Tái sử dụng Radix, preserve focus trap/Escape/return focus. Truyền theme sang portal content/overlay; title, description và action order rõ.                                                                                                                                                  |

## Tương tác và responsive

- Kiểm tra default, hover, pressed, `focus-visible`, disabled, pending, invalid,
  selected và read-only ở đúng component có các trạng thái đó.
- Focus 2px, offset 3px; không bỏ outline nếu chưa có indicator thay thế.
- Pending giữ nguyên nhãn dễ hiểu, không thay đổi điều kiện cho phép thao tác.
- UI vận hành chuyển động 160–240ms; không mang magnetic/reveal vào form/table.
  Mọi motion mới phải hỗ trợ `prefers-reduced-motion`.
- Tiêu chí review: text thường ≥4.5:1, text lớn/control boundary/focus ≥3:1 trên
  nền thực tế. Không dùng champagne hoặc sand làm chữ nhỏ trên ivory.
- Review 360/390, 768, 1024, 1440px và zoom 200%; breakpoint dựa vào độ rộng nội
  dung thực tế. Không đổi breakpoint của Landing trong các Phase này.
- Mobile: action wrap/stack, form một cột, không tràn trang, table scroll trong
  container; sidebar/dialog dùng được bằng bàn phím. Kiểm tra dữ liệu dài, giá
  tiền lớn, nhiều badge, loading/empty/error và ảnh lỗi.

## Quy tắc thay đổi

Không đổi service API, query keys, mutation, schema, route guards, status mapping,
payload, database hay Laravel. Chỉ tách markup/class khi giữ nguyên props và event
handlers. Không xoá file legacy chỉ vì chưa thấy import. Mỗi Phase báo cáo files,
checks, lỗi tồn tại và dừng ở ranh giới Phase do người dùng yêu cầu.
