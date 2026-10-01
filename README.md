# Ecomkit - Vui Khỏe

Plugin WordPress nội bộ dùng để nhập, đồng bộ, đối chiếu và xuất dữ liệu đơn hàng thương mại điện tử của Vui Khỏe.

## Trạng thái dự án

Stage **WP.2D — Database Transaction Compatibility / InnoDB Migration** đã triển khai plugin phiên bản `0.2.4`, schema `3`: giữ nguyên parser production đã kiểm chứng, yêu cầu sáu bảng Ecomkit dùng InnoDB và cung cấp migration `2 → 3` có thể tiếp tục an toàn sau lỗi. Chưa có Shopee/Lazada API, matching, kết quả 24 cột cuối, export hoặc PDF.

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
- MySQL/MariaDB theo yêu cầu của phiên bản WordPress đang dùng, có hỗ trợ InnoDB và quyền tạo/cập nhật/đổi storage engine cho riêng sáu bảng Ecomkit.
- Các PHP extension runtime được PhpSpreadsheet `5.8.1` khai báo: `ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip` và `zlib`.

Đây là baseline được chốt ở WP.1 vì WP.0 chưa chỉ định phiên bản tối thiểu. Plugin kiểm tra compatibility trước khi tải runtime và hiển thị notice an toàn cho quản trị viên nếu môi trường không đạt.

## Cập nhật trên staging/local

1. Không thử trên production trước; sao lưu database và plugin hiện tại của staging/local.
2. Cập nhật toàn bộ thư mục plugin `ecomkit-vuikhoe`, bao gồm `vendor/`, bằng quy trình triển khai do người vận hành quản lý.
3. Không cần deactivate, xóa plugin hoặc cài lại. Lần tải runtime kế tiếp sẽ phát hiện schema cũ và chạy migration tăng dần.
4. Mở **WordPress Admin → Ecomkit → Cài đặt → Database Runtime Diagnostics**, xác nhận schema `3`, InnoDB được hỗ trợ và cả sáu bảng báo `InnoDB — OK`.
5. Chỉ sau khi Transactional database readiness báo `PASS`, mở **Xử lý đơn hàng** và thử bằng bản sao file Excel, không dùng trực tiếp file nghiệp vụ gốc.

Activation/update chỉ tạo hoặc nâng cấp schema bằng `dbDelta()` và không tạo dữ liệu mẫu. Deactivate hoặc uninstall không xóa bảng hay dữ liệu.

## Chức năng đã có

- Bootstrap plugin phiên bản `0.2.4`, Composer classmap autoload và text domain.
- Compatibility notices cho PHP/WordPress.
- Sáu bảng custom có prefix động, schema version `3`, tạo mới rõ ràng với `ENGINE=InnoDB` và nâng cấp tại chỗ không cần deactivate/reactivate.
- Migration `2 → 3` chỉ chuyển các bảng Ecomkit chưa phải InnoDB, không drop/truncate; kiểm tra lại số dòng, cột, index và collation trước khi ghi schema version mới. Nếu dừng giữa chừng, lần chạy sau bỏ qua bảng đã đúng và tiếp tục phần còn lại.
- Activation idempotent; deactivation và uninstall mặc định không phá hủy dữ liệu.
- Menu Ecomkit với Tổng quan, Xử lý đơn hàng, Kết quả, Lỗi, Lịch sử, Marketplace và Cài đặt.
- Tất cả page callback kiểm tra `manage_options`; output động được escape.
- Dashboard/Settings chỉ hiển thị diagnostic an toàn, không hiển thị secret.
- Database Runtime Diagnostics hiển thị loại máy chủ, hỗ trợ InnoDB, engine/transactional status của sáu bảng Ecomkit và trạng thái sẵn sàng tổng thể; không hiển thị DB host, tài khoản, mật khẩu hay SQL.
- Upload một file `.xlsx` qua form `manage_options` + nonce; file tạm có tên ngẫu nhiên và được xóa sau parse.
- PhpSpreadsheet `5.8.1`; đúng một worksheet; header được tìm trong 20 hàng vật lý không trống đầu tiên và cột production là `Sàn & Mã Đơn`.
- Tạo Batch Excel, lưu các order identity hợp lệ, raw row metadata an toàn, preview, lịch sử và lỗi thao tác bằng tiếng Việt.

## Yêu cầu file Excel WP.2

- Chỉ `.xlsx`; không nhận `.xls`, CSV, PDF, ZIP, HTML hoặc XML.
- Tối đa **10 MB** hoặc giới hạn WordPress/PHP nếu thấp hơn.
- Tối đa **2.000 dòng dữ liệu** do WP.2 xử lý đồng bộ.
- Workbook phải có đúng một worksheet; các hàng tiêu đề/trang trí phía trên bảng được bỏ qua.
- Cột production bắt buộc là `Sàn & Mã Đơn`. Dòng đầu trong ô là `Shopee` hoặc `Lazada`; dòng tiếp theo là mã đơn sàn được giữ nguyên như text.
- `Mã đơn hàng eShop` không phải mã đơn marketplace và không bao giờ được dùng làm `Mã đơn sàn`.
- Dòng sản phẩm có `Sàn & Mã Đơn` trống được nối với Order hợp lệ gần nhất phía trước; dòng sản phẩm mồ côi tạo lỗi rõ ràng.
- Hàng nghiệp vụ phải có cấu trúc item gồm `Mã hàng hóa` và `Tên hàng hóa`. Hàng chữ ký/footer không có identity, mã hàng và số lượng được bỏ qua; không nhận diện chỉ dựa vào chuỗi `Thủ Kho`.
- “Dòng nghiệp vụ đã xử lý” chỉ đếm Order row và continuation item row; không đếm title, header, blank hoặc footer.
- Ba fixture production-shape khóa các kết quả 3/3, 16/23 và 8/18 cho Order/OrderItem. Công cụ development `tests/diagnose-xlsx.php` chỉ xuất sheet, header row, số đếm, error code và failing stage, không xuất PII.
- **Triển khai thủ công phải sao chép toàn bộ thư mục `ecomkit-vuikhoe/`, bao gồm `vendor/` và `vendor/autoload.php`.** Chỉ cập nhật các file PHP của plugin là không đủ.
- Trước khi upload file công ty, vào **Ecomkit → Cài đặt → Excel Runtime Diagnostics → Kiểm tra môi trường Excel**. Self-test tạo workbook tổng hợp không PII, ghi/đọc qua PhpSpreadsheet rồi xóa file tạm trong `finally`.
- Upload lifecycle: xác thực extension/kích thước, di chuyển vào file tạm ngẫu nhiên, xác nhận file tồn tại/readable/có kích thước, đọc cấu trúc XLSX, persist dữ liệu nội bộ và xóa workbook tạm. Workbook nguồn không được lưu lâu dài.
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

