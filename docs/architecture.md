# Kiến trúc WordPress mục tiêu

## 1. Mục tiêu

Ecomkit - Vui Khỏe là plugin WordPress quản lý luồng nhập dữ liệu, đồng bộ Marketplace, chuẩn hóa, đối chiếu, xem kết quả/lỗi/lịch sử và xuất file. WordPress chịu trách nhiệm xác thực, phân quyền, giao diện quản trị, REST endpoints, lịch tác vụ và persistence. Plugin không sao chép mô hình nhiều service của hệ thống Node cũ.

```text
WordPress Admin / REST
        |
  Application services
   /       |        \
Ingestion  Matching  Shopee adapter
   \       |        /
 Canonical repositories (custom tables)
        |
 Background jobs + audit/error records
        |
 XLSX / CSV export (24 cột)
```

## 2. Ranh giới module đề xuất

| Module | Trách nhiệm | Không được làm |
| --- | --- | --- |
| Admin UI | Batch, kết quả, lỗi, lịch sử, cấu hình | Truy cập token/Partner Key thô |
| REST/API | Validate request, capability, nonce; trả DTO an toàn | Chứa business rule rải rác trong controller |
| Ingestion | Upload `.xlsx`/PDF, kiểm tra file, parse và lưu provenance | Tự chạy matching khi chỉ xem dữ liệu |
| Canonical | Hợp đồng Order/OrderItem và 24 cột | Suy luận trường thiếu |
| Matching | Đối chiếu duy nhất bằng mã đơn đã trim | Fuzzy matching hoặc ghép bằng PII |
| Marketplace | OAuth/token lifecycle, Shopee client, normalizer | Đưa credential vào frontend/job payload |
| Jobs | Sync/parse/match/export không đồng bộ, retry có kiểm soát | Giữ request web mở cho tác vụ dài |
| Result/Error/History | Truy vấn read-only, filter, pagination, summary | Tự parse, retry, sửa hoặc xóa dữ liệu |
| Export | XLSX/CSV đúng 24 cột | Xuất raw payload, path, secret hoặc error context |

## 3. Mô hình dữ liệu logic

- `batches`: một lần xử lý hoặc một bridge từ Marketplace; giữ trạng thái và số đếm tổng hợp.
- `uploaded_files`: metadata file, trạng thái parse và raw parse payload có kiểm soát.
- `orders`: Master Order canonical, gồm identity, matching status, provenance và đúng 24 trường output.
- `order_items`: dòng sản phẩm chuẩn hóa, không tự gộp SKU.
- `processing_errors`: lỗi có mã, severity, vị trí nguồn và suggested action.
- `processing_logs`: audit vận hành không chứa secret/PII thừa.
- `marketplace_connections`: định danh shop, trạng thái, checkpoint và credential envelope.
- `marketplace_sync_runs`: cửa sổ sync, trigger, checkpoint đầu/cuối, số đếm và Batch liên kết.
- `marketplace_external_orders`: snapshot raw và normalized tách biệt, unique theo connection + marketplace order ID.
- `marketplace_sync_errors`: lỗi tích hợp an toàn, retryability và operation.

Tên bảng vật lý phải dùng `$wpdb->prefix` và migration có version. Không dùng post/postmeta cho payload đơn hàng số lượng lớn. Foreign-key logic, unique constraint, index và cleanup policy phải được đặc tả trước khi tạo bảng.

## 4. Luồng file canonical

```text
Tạo Batch -> upload -> validate -> parse Excel/PDF
-> normalize Mã đơn sàn -> match -> persist Order/Error
-> Result / Error / History -> export XLSX/CSV
```

- Một Batch file cần đúng một Excel đã parse và ít nhất một PDF đã parse trước khi match.
- Excel yêu cầu header chính xác `Mã đơn sàn` sau khi trim khoảng trắng ngoài; không alias mơ hồ.
- Matching chạy lại phải deterministic và thay thế chỉ kết quả/lỗi matching trong một transaction; không sửa raw parser data/lỗi parser.
- Parser re-run chỉ thay lỗi do chính parser đó sinh ra cho đúng file.

## 5. Luồng Marketplace canonical

```text
Connection -> Sync Run -> Shopee list/detail
-> raw snapshot + normalized snapshot
-> stale/idempotency policy -> Batch bridge
-> Order/OrderItem -> Result / History / Export
```

- Source identity unique trong phạm vi một connection.
- Raw và normalized snapshots lưu riêng.
- Chỉ commit checkpoint sau khi toàn bộ persistence thành công.
- Replay cùng hoặc cũ hơn `providerUpdatedAt` chỉ cập nhật `lastSeen`, không tạo Batch thừa.
- Job payload chỉ mang ID nội bộ tối thiểu, ví dụ connection ID và sync-run ID.

## 6. Result, Error và History

- Result là read-only; summary tính từ toàn Batch, không từ trang hiện tại. Page size hợp đồng: 20, 50 hoặc 100.
- Nhóm trạng thái: thành công=`MATCHED`; cảnh báo=`PDF_NOT_FOUND`,`EXCEL_NOT_FOUND`; lỗi=`DUPLICATE`,`PARSE_ERROR`.
- Error list/detail không cho sửa, bỏ qua hoặc retry ngầm; raw content render dưới dạng text đã escape và redact.
- History lấy Batch làm record lịch sử, sort `created_at DESC`, rồi ID `DESC`; không mặc định hỗ trợ delete/archive/rerun.

## 7. Trạng thái

Processing status: `PENDING`, `PROCESSING`, `SUCCESS`, `WARNING`, `ERROR`.

Matching status: `MATCHED`, `PDF_NOT_FOUND`, `EXCEL_NOT_FOUND`, `DUPLICATE`, `PARSE_ERROR`.

Quy tắc duplicate ưu tiên cao hơn matched/missing. Một normalized code tạo đúng một Master Order dù có nhiều occurrence; provenance giữ danh sách nguồn.

## 8. Các quyết định chưa khóa ở WP.0

- Thư viện PHP đọc/ghi XLSX/PDF và việc hỗ trợ OCR.
- Action Scheduler hay WP-Cron/custom queue; locking và retry cụ thể.
- Schema SQL vật lý, retention, multisite và chiến lược uninstall.
- Cách map role cũ `ADMIN`/`USER` sang WordPress roles/capabilities.
- Cấu trúc UI (wp-admin thuần hay frontend bundle).

Các quyết định này thuộc stage sau; WP.0 chỉ khóa contract nghiệp vụ và ranh giới bảo mật.

## 9. Quyết định triển khai WP.1

- Baseline runtime: PHP 8.1+, WordPress 6.6+; bootstrap kiểm tra trước khi tải autoload/runtime.
- Composer dùng classmap cho các class theo naming convention WordPress, không có package runtime bên ngoài ở WP.1.
- Schema version `1` dùng sáu bảng foundation: `ecomkit_batches`, `ecomkit_orders`, `ecomkit_order_items`, `ecomkit_errors`, `ecomkit_marketplace_connections`, `ecomkit_sync_runs`, luôn ghép với `$wpdb->prefix`.
- `dbDelta()` chạy khi activation và khi stored schema version thấp hơn expected version; upgrade không phụ thuộc deactivate/reactivate.
- WP.1 chưa tạo các bảng snapshot/log riêng được đề xuất ở WP.0. Raw/canonical metadata foundation dùng JSON-encoded `longtext`; việc tách bảng ở migration tương lai cần giữ compatibility và không thay contract 24 cột.
- Không khai báo foreign key vật lý ở WP.1 để tương thích vận hành/migration WordPress; quan hệ dùng ID và index có chủ đích. Multi-shop uniqueness là `(connection_id, marketplace_order_id)`, không phải global order ID.
- Deactivation và uninstall mặc định giữ nguyên toàn bộ dữ liệu. Destructive uninstall chỉ có thể được bổ sung bằng opt-in rõ ràng ở stage sau.
- Admin dùng `manage_options`, page callback kiểm tra lại capability; không có public route hoặc mutating form ở WP.1.

## 10. Quyết định triển khai WP.2

- Runtime `0.2.0`, schema `2`; migration duy nhất đổi `orders.matching_status` thành nullable để Order vừa nhập chưa bị gắn trạng thái đối chiếu giả. Không drop/recreate bảng và không xóa dữ liệu WP.1.
- PhpSpreadsheet `5.8.1`; chỉ `.xlsx`, đúng một worksheet, header dòng 1, `Mã đơn sàn` exact sau trim khoảng trắng ngoài.
- Import đồng bộ giới hạn 10 MB (hoặc WordPress/PHP thấp hơn) và 2.000 dòng. File được chuyển sang tên tạm ngẫu nhiên, parse trong request rồi xóa trong `finally`; không lưu URL/path công khai.
- Parser thuần không ghi DB. Import service tạo Batch, sau đó ghi Order/Error và cập nhật số đếm trong transaction.
- Batch lifecycle: `PROCESSING → SUCCESS` nếu sạch; `PROCESSING → WARNING` nếu có ít nhất một Order hợp lệ và lỗi dòng; `PROCESSING → ERROR` nếu lỗi cấu trúc/upload hoặc không có Order hợp lệ.
- Duplicate trim-only, phân biệt hoa/thường: occurrence đầu tiên hợp lệ được giữ; occurrence sau tạo `EXCEL_DUPLICATE_ORDER_CODE` với dòng đầu và dòng trùng.
- Order Excel có `platform=UNKNOWN`, `connection_id=NULL`, `matching_status=NULL`; không tạo connection giả và không coi dữ liệu Excel là kết quả reconciliation.
- `raw_source_metadata` giữ các cell scalar an toàn; `source_refs` giữ worksheet/dòng. Formula không được thực thi và chỉ cached scalar value mới có thể được đọc.

## 11. Điều chỉnh production WP.2A

- Runtime `0.2.1`, schema vẫn là `2`: bảng `order_items` hiện hữu đã đủ để lưu mã hàng hóa, tên hàng hóa, số lượng và provenance dòng nên không cần migration.
- Parser tìm hàng tiêu đề trong tối đa 20 hàng vật lý không trống đầu tiên. Hàng tiêu đề/trang trí phía trên không phải dữ liệu và số hàng tiêu đề phát hiện được lưu trong metadata Batch.
- Nguồn production là `Sàn & Mã Đơn`; parser tách đúng hai dòng có nghĩa thành nhãn sàn và mã đơn. `Shopee` → `SHOPEE`, `Lazada` → `LAZADA`; nhãn khác tạo `EXCEL_UNKNOWN_PLATFORM`.
- `Mã đơn hàng eShop` chỉ được giữ trong raw metadata, không tham gia identity. Legacy `Mã đơn sàn` vẫn được nhận khi đứng một mình; nếu cả hai cột identity cùng tồn tại thì import dừng bằng lỗi ambiguity.
- Dòng có sản phẩm nhưng trống `Sàn & Mã Đơn` kế thừa Order hợp lệ gần nhất. Dòng sản phẩm không có context tạo `EXCEL_ORPHAN_ITEM_ROW`; hàng trống hoàn toàn bị bỏ qua; hàng không phải sản phẩm làm ngắt context.
- Duplicate chỉ xét dòng Order tường minh và dùng cặp `(platform, marketplace_order_id)`, phân biệt hoa/thường và trim ngoài. Một Order có thể có nhiều OrderItem nhưng chỉ tạo một record `orders`.
- Timestamp vẫn lưu UTC; wp-admin hiển thị qua `wp_date()` và timezone được cấu hình trong WordPress.

## 12. Hardening production WP.2B

- Root cause parser WP.2A đã được tái hiện trên cả ba workbook thật: footer chỉ có nội dung ở cột `Tên hàng hóa` bị predicate “bất kỳ cột sản phẩm có dữ liệu” nhận nhầm thành OrderItem. Kết quả cũ lần lượt dư một item: 4, 24 và 19 thay vì 3, 23 và 18.
- Mỗi hàng sau header được phân loại đúng một trong năm loại: `ORDER_ROW`, `CONTINUATION_ITEM_ROW`, `BLANK_ROW`, `FOOTER_OR_NONDATA_ROW`, `INVALID_DATA_ROW`. Item hợp lệ cần cả mã hàng hóa và tên hàng hóa; footer không có identity/mã hàng/số lượng bị loại theo cấu trúc, không theo literal `Thủ Kho`.
- Blank row đóng context kế thừa hiện tại. Một item hợp lệ sau đó mà không có Order context tạo `EXCEL_ORPHAN_ITEM_ROW`; dữ liệu nghiệp vụ thiếu cấu trúc không bị nuốt mà tạo `EXCEL_INVALID_DATA_ROW`.
- `total_rows`/“Dòng nghiệp vụ đã xử lý” chỉ đếm Order rows và continuation item rows. Title, header, blank và footer không được tính.
- Import lưu stage an toàn từ upload/workbook/header/row/order/item tới Batch/Order/OrderItem/Error/finalize. Lỗi persistence giữ exception class, message đã sanitize, sheet, row, entity, operation và rollback flag trong metadata Batch; UI chỉ hiển thị thông báo an toàn kèm Batch ID.
- Trước khi ghi business data, runtime xác nhận bốn bảng import dùng engine transactional. Mọi `$wpdb->insert()`/`update()` bắt buộc kiểm tra `false`; Order/OrderItem/Error/Batch finalize failure gây `ROLLBACK`, sau đó một transaction mới đánh dấu Batch `ERROR` và ghi lỗi an toàn.
- Schema vẫn là version `2`: mọi cột được ghi đã tồn tại và nullable đúng yêu cầu; không có migration phá hủy.

