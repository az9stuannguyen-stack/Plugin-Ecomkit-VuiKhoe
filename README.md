# Ecomkit - Vui Khỏe

Plugin WordPress nội bộ dùng để nhập, đồng bộ, đối chiếu và xuất dữ liệu đơn hàng thương mại điện tử của Vui Khỏe.

## Trạng thái dự án

Hiện tại: **WP.6H.1A — Safe Payment Snapshot Audit Export**, plugin `0.7.1`, database schema giữ `8`, canonical giữ `v5`. Trong **Ecomkit → Kết quả → Chẩn đoán nâng cao**, admin có thể nhập mã đơn sàn Shopee chính xác để xem hoặc tải JSON cây số tài chính được lọc từ Payment snapshot đã lưu. Công cụ chỉ đọc, chặn PII/secret, không gọi provider, không refresh Payment, không sửa canonical. Mapping `serviceFee → Phí dịch vụ (TMĐT)` **chưa được sửa**; hai đơn legacy cho thấy parity cần kiểm tra bằng raw Payment. Quy trình một upload và công thức WP.6H giữ nguyên; WP.7 chưa bắt đầu.

Stage **WP.3B — Zero-Config Credential Master Key** triển khai plugin phiên bản `0.3.2`, schema `4`: mặc định dẫn xuất khóa mã hóa bằng HKDF-SHA256 từ WordPress Security Keys; advanced installation vẫn có thể ưu tiên `ECOMKIT_CREDENTIAL_KEY`. Không lưu master key trong database và không cần sửa `wp-config.php` ở hosting WordPress thông thường.

Kiến trúc Node/NestJS/PostgreSQL/Redis/BullMQ cũ không được sao chép nguyên trạng sang plugin.

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
4. Mở **WordPress Admin → Ecomkit → Cài đặt → Database Runtime Diagnostics**, xác nhận schema `5`, InnoDB được hỗ trợ và cả sáu bảng báo `InnoDB — OK`.
5. Chỉ sau khi Transactional database readiness báo `PASS`, mở **Xử lý đơn hàng** và thử bằng bản sao file Excel, không dùng trực tiếp file nghiệp vụ gốc.

Activation/update chỉ tạo hoặc nâng cấp schema bằng `dbDelta()` và không tạo dữ liệu mẫu. Deactivate hoặc uninstall không xóa bảng hay dữ liệu.

## Chức năng đã có

- Bootstrap plugin phiên bản `0.7.1`, Composer classmap autoload và text domain.
- Compatibility notices cho PHP/WordPress.
- Sáu bảng custom có prefix động, schema version `8`, tạo mới rõ ràng với `ENGINE=InnoDB` và nâng cấp tại chỗ không cần deactivate/reactivate.
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
- Cột production `Ngày đặt` được nhận bằng exact normalized header. Giá trị Excel datetime serial dùng utility của PhpSpreadsheet; chuỗi chỉ nhận `d/m/Y H:i[:s]` (hoặc ISO không mơ hồ), sau khi chuẩn hóa whitespace/LF/CRLF. `strtotime()` không được dùng để đoán locale.
- Datetime nguồn được hiểu trong `wp_timezone()` nếu không mang timezone riêng, sau đó lưu vào `orders.order_date` dưới dạng UTC `Y-m-d H:i:s`; marker `order_date_storage=UTC` và precision `DATE`/`DATETIME` nằm trong raw metadata mà không ghi đè raw cell. Date-only biểu thị chỉ biết ngày, không phải thời gian chính xác.
- Giá trị ngày không rỗng nhưng bất hợp lệ tạo `EXCEL_INVALID_ORDER_DATE`; ô trống vẫn persist Order với `order_date=NULL` và WP.5 giữ trạng thái chưa kết luận thay vì gán `NOT_FOUND_IN_SHOPEE`.

## Chưa được triển khai

- Parse PDF và sinh kết quả 24 cột cuối.
- Export XLSX/CSV thực tế (WP.7 sẽ dùng trực tiếp canonical snapshot).
- Payment/Escrow, Lazada API và materialization/export tài chính 24 cột.

## Cấu hình khóa credential WP.3B

Mặc định plugin tự dẫn xuất khóa 32 byte từ tám WordPress authentication/security keys và salts bằng HKDF-SHA256. Không cần operator chỉnh `wp-config.php`, và key material không được lưu vào Ecomkit database.

Advanced installation có thể chủ động kiểm soát key bằng cách tạo khóa 32 byte Base64:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

```php
define('ECOMKIT_CREDENTIAL_KEY', '<generated-base64-value>');
```

Nếu constant hợp lệ tồn tại, explicit mode được ưu tiên; nếu không tồn tại, plugin dùng WordPress Security Keys. Không commit khóa thật. Việc rotate WordPress keys/salts sẽ làm credential mã hóa bằng `wp_salts_v1` không còn giải mã được; khi đó cần nhập lại Partner Key và ủy quyền lại Marketplace.

Vào **Ecomkit → Cài đặt** xác nhận Marketplace Security Diagnostics, rồi vào **Ecomkit → Marketplace** chọn Sandbox/Production, nhập đúng Test/Live Partner ID và Partner Key. Callback được tạo động bằng `rest_url('ecomkit/v1/shopee/callback')`; đăng ký URL/domain này trong Shopee Open Platform trước khi kết nối.
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

# WP.3C runtime note

WP.3C runtime was `0.3.3` with database schema `4`. The initial Shopee token exchange sends only JSON string `code` and JSON integer `partner_id`; callback `shop_id` is retained for identity validation and is not unnecessarily sent in the request body. OAuth failures expose only stage, classification, sanitized provider error/message, request ID, HTTP status, API path, and duration through a one-time admin-bound diagnostic reference. Authorization codes, signatures, tokens, and raw provider responses are never displayed or logged.

# WP.4A token lifecycle

`ensure_usable_access_token(connectionId)` is the only foundation future provider callers should use. It returns a token only inside server-side PHP memory, reuses a token outside the 300-second Ecomkit refresh skew, and otherwise performs one locked refresh call. No cron scheduler or Order API is included. Admins can perform one manual refresh with `manage_options` and a nonce; ambiguous network/persistence outcomes require reauthorization and are never retried automatically.

# WP.4B read-only Order API

The plugin supports read-only `GET /api/v2/order/get_order_list` and `GET /api/v2/order/get_order_detail`. List windows are limited to 15 days, page size to 1-100, cursors remain opaque, pagination stalls/cycles are rejected, and synchronous all-page reads stop at 100 pages as an Ecomkit safety guard. Detail calls accept at most 50 exact `order_sn` values; the higher-level client batches larger sets and reports missing, extra, and duplicate identities.

Every provider request calls `ensure_usable_access_token(connectionId)` first and uses shop-level signing. The admin live test reads at most five orders with one list call and, when non-empty, one detail call. It stores only a short-lived PII-free preview. WP.4B performs no Batch/Order/SyncRun persistence, Excel reconciliation, Payment/Escrow request, PDF work, retry loop, cron, or background sync.

# WP.4B.1 live alignment

The admin Order API test now accepts one WordPress-local calendar date. The server maps that date to local `00:00:00` through `23:59:59` with `wp_timezone()` and `DateTimeImmutable`; operators do not enter hours manually. GetOrderList requests explicitly include `response_optional_fields=order_status`, without changing the shop signature base. A missing list `order_status` is represented as `null`, while a missing/empty `order_sn` remains invalid.

On structural failures, diagnostics record only top-level/response/first-item key names, JSON/PHP types, and list count—never raw JSON, order values, PII, signed URLs, or tokens.

# WP.5 exact Shopee reconciliation

Trang Batch có action quản trị `Đối chiếu Shopee` dùng `manage_options`, nonce, POST và PRG. Operator không nhập ngày hoặc `order_sn`: plugin đọc `Ngày đặt` đã persist (với fallback có kiểm soát cho metadata parser `wp2b-v1`), dùng timezone WordPress, gom các ngày liên tiếp thành window tối đa 15 ngày và dùng `create_time`. Không quét lịch sử không giới hạn.

Mỗi window phải hoàn tất toàn bộ pagination trước khi đơn vắng mặt được gắn `NOT_FOUND_IN_SHOPEE`. So sánh phân biệt hoa/thường và chỉ dùng `marketplace_order_id === order_sn`; không fuzzy match hoặc fallback PII. Detail chỉ được gọi cho Excel order đã có trong list, theo batch tối đa 50.

Schema `5` thêm bốn cột nullable vào `orders`: `provider_raw_data`, `provider_normalized_data`, `provider_updated_at`, `matched_at`. `raw_source_metadata` của Excel không bị ghi đè. Snapshot provider cũ hơn không thay thế snapshot mới hơn; rerun không tạo Order/OrderItem mới. Payload detail có thể chứa PII nên chỉ nằm trong DB nghiệp vụ/admin, không vào log, transient diagnostics hoặc màn hình summary mặc định. WP.5 chưa gọi Payment/Escrow, chưa dùng Lazada API, chưa tạo SyncRun và chưa export 24 cột.

# WP.5A production order dates

Batch preview hiển thị `Ngày đặt` trong timezone WordPress trước khi đối chiếu. Reconciliation đổi UTC về ngày local để tạo full-day window; không yêu cầu nhập ngày thủ công. Nếu mọi đơn Shopee đều thiếu ngày, provider windows và Shopee API calls đều bằng `0`, status là `WARNING`, không có đơn nào bị gán `NOT_FOUND_IN_SHOPEE`, và UI không hiển thị banner hoàn tất màu xanh. Schema vẫn là `5`; không có migration trong WP.5A.

