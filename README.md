# Ecomkit - Vui Khỏe

Plugin WordPress nội bộ dùng để nhập, đồng bộ, đối chiếu và xuất dữ liệu đơn hàng thương mại điện tử của Vui Khỏe.

## Trạng thái dự án

Stage **WP.1 — WordPress Plugin Foundation** đã triển khai plugin có thể đóng gói/cài đặt, Composer autoload, lifecycle kích hoạt, schema version 1 với sáu bảng nền tảng, menu quản trị và chẩn đoán an toàn. Chưa có Excel upload/parser, matching, Result/Error/History nghiệp vụ, export hoặc tích hợp Shopee hoạt động.

Repo cũ tại `C:\Users\nkluck\ecomkit` chỉ là nguồn tham chiếu read-only. Kiến trúc Node/NestJS/PostgreSQL/Redis/BullMQ không được sao chép nguyên trạng sang plugin.

## Tài liệu WP.0

- [Kiến trúc](docs/architecture.md)
- [Hợp đồng và mapping 24 cột](docs/24-column-mapping.md)
- [Tích hợp Shopee](docs/shopee-integration.md)
- [Bảo mật](docs/security.md)
- [Lộ trình](docs/roadmap.md)

## Yêu cầu tương thích

- PHP **8.1** trở lên.
- WordPress **6.6** trở lên.
- MySQL/MariaDB theo yêu cầu của phiên bản WordPress đang dùng, với quyền tạo/cập nhật bảng khi kích hoạt.

Đây là baseline được chốt ở WP.1 vì WP.0 chưa chỉ định phiên bản tối thiểu. Plugin kiểm tra compatibility trước khi tải runtime và hiển thị notice an toàn cho quản trị viên nếu môi trường không đạt.

## Cài đặt trên staging/local

1. Không thử trên production trước. Sao lưu database của staging/local.
2. Vào **Plugins → Add New → Upload Plugin**.
3. Chọn `dist/ecomkit-vuikhoe.zip`, cài đặt và kích hoạt.
4. Mở **WordPress Admin → Ecomkit**.
5. Xác nhận bảy trang quản trị xuất hiện và Dashboard báo schema/bảng dữ liệu sẵn sàng.

Kích hoạt tạo hoặc nâng cấp schema theo version bằng `dbDelta()` và không tạo dữ liệu mẫu. Deactivate hoặc uninstall ở WP.1 không xóa bảng hay dữ liệu.

## Chức năng đã có ở WP.1

- Bootstrap plugin phiên bản `0.1.0`, Composer classmap autoload và text domain.
- Compatibility notices cho PHP/WordPress.
- Sáu bảng custom có prefix động, schema version `1` và nâng cấp không cần deactivate/reactivate.
- Activation idempotent; deactivation và uninstall mặc định không phá hủy dữ liệu.
- Menu Ecomkit với Tổng quan, Xử lý đơn hàng, Kết quả, Lỗi, Lịch sử, Marketplace và Cài đặt.
- Tất cả page callback kiểm tra `manage_options`; output động được escape.
- Dashboard/Settings chỉ hiển thị diagnostic an toàn, không hiển thị secret.

## Chưa được triển khai

- Upload/parse Excel hoặc PDF, chuẩn hóa/matching và sinh 24 cột.
- Result, Error, History và export XLSX/CSV thực tế.
- Shopee OAuth, signing, API, token/credential storage runtime hoặc provider calls.
- Background jobs/Action Scheduler.

WP.1 là foundation để kiểm thử trên staging. Việc plugin cài được không đồng nghĩa pipeline nghiệp vụ hoặc Shopee đã sẵn sàng.

## Nguyên tắc nghiệp vụ bất biến

- Khóa đối chiếu duy nhất là **Mã đơn sàn**.
- Chuẩn hóa V1 chỉ loại bỏ khoảng trắng ở đầu và cuối; phân biệt hoa/thường, giữ dấu gạch nối và khoảng trắng bên trong.
- Không ghép bằng tên khách hàng, số điện thoại, địa chỉ, sản phẩm, SKU, COD, tên file hoặc sàn.
- Một đơn canonical tương ứng một dòng export với đúng 24 cột theo đúng thứ tự.
- Giá trị chưa có nguồn được phê duyệt phải là `NULL` và xuất thành ô trống; tuyệt đối không tự tính trường tài chính.
- Dữ liệu Shopee và credential phải được xử lý phía server; không đưa secret vào trình duyệt, log, cron payload, export hoặc thông báo lỗi.

## Phạm vi WordPress dự kiến

Plugin sẽ dùng API và cơ chế quyền của WordPress, WordPress Cron/Action Scheduler hoặc hàng đợi tương đương cho tác vụ nền, và các bảng riêng có prefix của WordPress cho Batch, Order, lỗi, lịch sử và Shopee. Lựa chọn runtime chi tiết chỉ được chốt ở các stage sau sau khi có migration, retention và concurrency design.

## Nguồn chuẩn của WP.0

Các quyết định trong tài liệu này được phục hồi từ README, handoff, Prisma schema, matching engine, exporter, Result/History/Error services, Shopee normalizer/adapter và marketplace worker của repo tham chiếu tại checkpoint ngày 2026-10-01. Khi tài liệu và code tham chiếu khác nhau, hành vi code và test hiện hành được ưu tiên, đồng thời khoảng trống được ghi rõ thay vì suy đoán.

