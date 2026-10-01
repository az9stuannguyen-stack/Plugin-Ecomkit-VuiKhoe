# Ecomkit - Vui Khỏe

Plugin WordPress nội bộ dùng để nhập, đồng bộ, đối chiếu và xuất dữ liệu đơn hàng thương mại điện tử của Vui Khỏe.

## Trạng thái dự án

Stage **WP.2 — Excel Upload + Batch + Internal Order Identity** đã triển khai plugin phiên bản `0.2.0`: upload `.xlsx` được bảo vệ, parse đồng bộ, tạo Batch, lưu chính xác `Mã đơn sàn`, lỗi có vị trí, lịch sử Batch và preview import. Chưa có Shopee, matching Excel ↔ Marketplace, kết quả 24 cột cuối, export hoặc PDF.

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
- Các PHP extension do PhpSpreadsheet yêu cầu, gồm `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter` và `zip`.

Đây là baseline được chốt ở WP.1 vì WP.0 chưa chỉ định phiên bản tối thiểu. Plugin kiểm tra compatibility trước khi tải runtime và hiển thị notice an toàn cho quản trị viên nếu môi trường không đạt.

## Cập nhật trên staging/local

1. Không thử trên production trước; sao lưu database và plugin hiện tại của staging/local.
2. Cập nhật toàn bộ thư mục plugin `ecomkit-vuikhoe`, bao gồm `vendor/`, bằng quy trình triển khai do người vận hành quản lý.
3. Không cần deactivate, xóa plugin hoặc cài lại. Lần tải runtime kế tiếp sẽ phát hiện schema cũ và chạy migration tăng dần.
4. Mở **WordPress Admin → Ecomkit**, xác nhận Dashboard báo schema `2` và các bảng sẵn sàng.
5. Mở **Xử lý đơn hàng** và thử bằng bản sao file Excel, không dùng trực tiếp file nghiệp vụ gốc.

Activation/update chỉ tạo hoặc nâng cấp schema bằng `dbDelta()` và không tạo dữ liệu mẫu. Deactivate hoặc uninstall không xóa bảng hay dữ liệu.

## Chức năng đã có

- Bootstrap plugin phiên bản `0.2.0`, Composer classmap autoload và text domain.
- Compatibility notices cho PHP/WordPress.
- Sáu bảng custom có prefix động, schema version `2` và nâng cấp tại chỗ không cần deactivate/reactivate.
- Activation idempotent; deactivation và uninstall mặc định không phá hủy dữ liệu.
- Menu Ecomkit với Tổng quan, Xử lý đơn hàng, Kết quả, Lỗi, Lịch sử, Marketplace và Cài đặt.
- Tất cả page callback kiểm tra `manage_options`; output động được escape.
- Dashboard/Settings chỉ hiển thị diagnostic an toàn, không hiển thị secret.
- Upload một file `.xlsx` qua form `manage_options` + nonce; file tạm có tên ngẫu nhiên và được xóa sau parse.
- PhpSpreadsheet `5.8.1`; đúng một worksheet, header dòng 1 và header `Mã đơn sàn` exact sau khi trim khoảng trắng ngoài.
- Tạo Batch Excel, lưu các order identity hợp lệ, raw row metadata an toàn, preview, lịch sử và lỗi thao tác bằng tiếng Việt.

## Yêu cầu file Excel WP.2

- Chỉ `.xlsx`; không nhận `.xls`, CSV, PDF, ZIP, HTML hoặc XML.
- Tối đa **10 MB** hoặc giới hạn WordPress/PHP nếu thấp hơn.
- Tối đa **2.000 dòng dữ liệu** do WP.2 xử lý đồng bộ.
- Workbook phải có đúng một worksheet; dòng 1 là header.
- Cột bắt buộc là `Mã đơn sàn`; chỉ trim khoảng trắng ngoài, không dùng alias.
- Mã nên được định dạng Text để giữ leading zero và ID dài. Numeric cell không thể phục hồi chính xác sẽ bị từ chối, không đoán chữ số.
- Dòng trống hoàn toàn bị bỏ qua. Dòng thiếu mã và occurrence duplicate sau lần đầu tạo lỗi; lần xuất hiện hợp lệ đầu tiên được giữ.

## Chưa được triển khai

- Parse PDF, matching và sinh kết quả 24 cột cuối.
- Result reconciliation và export XLSX/CSV thực tế.
- Shopee OAuth, signing, API, token/credential storage runtime hoặc provider calls.
- Background jobs/Action Scheduler.

WP.2 cần được kiểm thử thủ công trên staging bằng **bản sao** file Excel thực tế. Synthetic tests không thay thế production Golden validation và việc import Excel thành công không đồng nghĩa Shopee đã sẵn sàng.

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

