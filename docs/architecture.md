# Kiến trúc WordPress mục tiêu

## WP.6 canonical Result

`Ecomkit_Vuikhoe_Canonical_Columns::all()` là nguồn code duy nhất cho 24 key/label/thứ tự. Pure materializer nhận Order, OrderItems, Batch provenance và provider normalized snapshots đã persist; không DB/HTTP. Service ghi JSON `v3` vào `orders.canonical_data`, cùng version, UTC materialized timestamp và SHA-256 source fingerprint bao gồm Payment evidence. Một Order luôn là một row; Result UI và WP.7 dùng cùng definition. WP.6A ưu tiên `orders.eshop_order_code`, sau đó raw Excel cell có `column_map` xác thực; fallback cell D chỉ dành cho Batch cũ có đủ provenance của đúng định dạng production đã xác minh.

WP.6C Batch enrichment đọc Order Shopee MATCHED, gọi single-order Payment tuần tự chỉ cho snapshot thiếu hoặc explicit refresh, persist mỗi thành công riêng, giữ snapshot cũ khi một call lỗi, rồi materialize toàn Batch từ dữ liệu đã lưu. Không giữ DB transaction qua network. Canonical v3 chỉ map bốn giá trị tài chính được duyệt; không tính phần trăm/trạng thái kế toán.

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

## 13. WP.5 reconciliation runtime

- Runtime `0.5.0`, schema `5`. Migration chỉ thêm bốn cột nullable vào `orders`: raw provider detail, normalized provider projection, provider update timestamp và matched timestamp. Không drop/recreate, không sửa raw Excel, và xác minh row counts/indexes trước khi tăng version.
- Phase A đọc Batch/Orders và lập window từ `Ngày đặt`; Phase B thực hiện network reads; Phase C xác nhận list/detail completeness; Phase D dùng transaction DB ngắn. Không giữ transaction trong khi chờ Shopee.
- Chỉ `platform=SHOPEE` được xử lý. Một READY connection được tự chọn; nhiều READY connection bắt buộc admin chọn rõ shop. `connection_id` ràng buộc evidence với shop đã dùng.
- Set comparison là exact, case-sensitive: `MATCHED = EXCEL ∩ PROVIDER`, `MISSING = EXCEL - PROVIDER`, `EXTRA = PROVIDER - EXCEL`. Window chưa hoàn tất không được tạo `NOT_FOUND_IN_SHOPEE`.
- Provider raw detail và normalized projection nằm riêng trong Order. Reconciliation summary không chứa PII được lưu trong `batches.source_metadata`; không tạo `SyncRun` ở WP.5.

## 13.1 WP.5A order-date contract

- Runtime `0.5.1`, schema vẫn `5`; cột `orders.order_date datetime NULL` đã tồn tại nên không có migration.
- Parser map exact header normalized `Ngày đặt`. Excel numeric datetime dùng `PhpSpreadsheet\Shared\Date`; chuỗi chỉ qua các format xác định `d/m/Y H:i[:s]`, date-only và ISO không mơ hồ sau khi gom whitespace/LF/CRLF. Không dùng `strtotime()` để đoán input.
- Nguồn không có timezone được hiểu bằng `wp_timezone()`, rồi lưu UTC `Y-m-d H:i:s`. `raw_source_metadata` giữ nguyên cells, thêm precision và storage marker; dữ liệu từ parser cũ không có marker tiếp tục được đọc theo local-wall-time cũ để tránh làm lệch Batch hiện hữu.
- ORDER_ROW là nơi duy nhất thiết lập ngày. Continuation item không ghi đè ngày cha. Date không rỗng nhưng sai tạo `EXCEL_INVALID_ORDER_DATE`; date trống thuộc semantics cảnh báo `SHOPEE_RECON_ORDER_DATE_MISSING` của planner.
- Batch preview và reconciliation chuyển UTC về timezone WordPress khi hiển thị. Run không có window dùng warning, báo window/API call bằng 0 và không tạo `NOT_FOUND_IN_SHOPEE`.

## 13. Production runtime diagnostics WP.2C

- Runtime `0.2.3`, schema vẫn `2`. Deployment bắt buộc gồm toàn bộ `vendor/`; bootstrap cảnh báo quản trị viên nếu `vendor/autoload.php` không đọc được.
- `Excel Runtime Diagnostics` báo version, autoload/classes, PHP extensions theo Composer metadata của PhpSpreadsheet `5.8.1`, trạng thái temp/upload directory và ba giới hạn PHP liên quan. Không hiển thị absolute path, php.ini đầy đủ, secret hay credential.
- Self-test administrator-only dùng `manage_options` + nonce: tạo workbook tổng hợp trong memory, ghi ra temp XLSX, kiểm tra tồn tại/readable/size, đọc lại bằng `IOFactory`, xác nhận cell cố định và xóa trong `finally`. Không ghi Order/Batch và không gọi provider.
- Workbook upload được preflight ngay trước `IOFactory`: dependency readiness, file tồn tại, readable và size > 0. Known failures dùng classification riêng như `EXCEL_RUNTIME_DEPENDENCY_MISSING`, `EXCEL_RUNTIME_EXTENSION_MISSING`, `EXCEL_RUNTIME_TEMP_UNWRITABLE`, `EXCEL_WORKBOOK_SOURCE_MISSING` và `EXCEL_WORKBOOK_SOURCE_UNREADABLE`.
- Extension `.xlsx` là bắt buộc. MIME WordPress được tham khảo nhưng không còn là điều kiện duy nhất vì hosting có thể nhận diện ZIP-based XLSX khác nhau; cấu trúc thực tế vẫn phải được PhpSpreadsheet Xlsx reader tải thành công, nếu không trả `EXCEL_READ_ERROR`.
- Batch metadata hiện có giữ failure stage/classification, safe exception class/message, worksheet/row nếu biết, PHP và trạng thái PhpSpreadsheet. Batch detail chỉ hiển thị tập dữ liệu an toàn này, không stack trace, raw SQL, source path hoặc Excel PII.
- Memory exhaustion ở mức fatal có thể xảy ra trước khi PHP catch được exception; `memory_limit` được hiển thị để chẩn đoán nhưng không tự kết luận memory root cause khi thiếu bằng chứng.

## 14. Transactional database migration WP.2D

- Runtime `0.2.4`, schema `3`. Cả sáu bảng vận hành Ecomkit phải dùng InnoDB để Batch, Order, OrderItem, Error, Marketplace Connection và Sync Run có thể tham gia workflow atomic; trạng thái engine hỗn hợp không được coi là sẵn sàng.
- Fresh install khai báo rõ `ENGINE=InnoDB` trong từng câu lệnh `CREATE TABLE`; `$wpdb->get_charset_collate()` vẫn là nguồn charset/collation. `dbDelta()` tạo/cập nhật cấu trúc, còn migration engine cho installation hiện hữu dùng `ALTER TABLE <tên-bảng-Ecomkit> ENGINE=InnoDB` riêng biệt.
- Upgrade `2 → 3` kiểm tra `SHOW ENGINES` trước, chỉ dùng sáu tên bảng sinh từ `$wpdb->prefix`, và không nhận table identifier từ request. Nếu InnoDB không được hỗ trợ, migration dừng an toàn và không tăng schema version.
- Trước lần chuyển đầu tiên, migration lưu fingerprint không chứa nội dung hàng: row count, column/index hash và collation. Sau mỗi lần chạy, toàn bộ engine, số dòng, cột, index và collation được kiểm tra lại. Chỉ khi cả sáu bảng đạt yêu cầu mới xóa checkpoint và ghi schema `3`.
- `ALTER TABLE` có thể implicit commit, vì vậy migration được thiết kế resumable: bảng đã là InnoDB được bỏ qua; nếu một bảng sau đó thất bại, schema vẫn ở `2` và request kế tiếp tiếp tục các bảng còn lại. Không drop, truncate, rename hoặc tạo bảng thay thế.
- Runtime guard `ECOMKIT_NON_TRANSACTIONAL_TABLE` được giữ nguyên và nay kiểm tra cả sáu bảng. Import chỉ bắt đầu transaction khi Database Runtime Diagnostics xác nhận mọi bảng đều là InnoDB.

## 14. WP.2E — Orders schema contract repair

- Runtime `0.2.5`, schema `4`; migration `3 → 4` đọc metadata cột thực tế và dùng explicit `ALTER TABLE ... MODIFY` để cho phép `connection_id` và `matching_status` là `NULL`, chỉ khi cột cũ chưa tương thích.
- Migration giữ nguyên kiểu cột đang cài đặt, kiểm tra số dòng `orders`, `order_items`, `batches`, `errors` trước/sau, xác minh contract rồi mới tăng schema version.
- WP.2 yêu cầu identity Order ở tầng ứng dụng; `connection_id` và `matching_status` vẫn `NULL` cho đến reconciliation. Admin chỉ hiển thị tên cột lỗi và trạng thái contract, không hiển thị SQL hoặc giá trị Order.

## 15. WP.3 — Shopee OAuth foundation

- Runtime `0.3.0`, schema vẫn `4`: provider config nằm trong WordPress option; từng shop dùng bảng `marketplace_connections` hiện hữu nên không cần migration.
- Resolver tập trung hai host Sandbox/Production. Signer dùng HMAC-SHA256 trên `partner_id + api_path + Unix timestamp`. HTTP client WP.3 chỉ có token exchange `/api/v2/auth/token/get`.
- OAuth start là admin-post có capability + nonce; flow nonce 32 byte nằm trong transient 10 phút và cookie HttpOnly/Secure/SameSite=Lax. Callback REST public nhưng flow được consume một lần và ràng buộc admin, environment, Partner ID fingerprint và callback URL.
- Provider key và token được mã hóa AES-256-GCM bằng master key ngoài database. OAuth chỉ upsert connection `(SHOPEE, external_shop_id)` sau khi response và encryption hợp lệ; không chạm Batch, Order, OrderItem hay SyncRun.

## 16. WP.3B — Credential key resolution

- Runtime `0.3.2`, schema vẫn `4`. Resolver ưu tiên explicit `ECOMKIT_CREDENTIAL_KEY` hợp lệ (`explicit_v1`), nếu constant không tồn tại thì dẫn xuất `wp_salts_v1` từ tám WordPress keys/salts theo thứ tự cố định.
- KDF là HKDF-SHA256, info `ecomkit-vuikhoe|credential-master|v1`, output 32 byte. Domain, filesystem, database credential và table prefix không tham gia derivation.
- Envelope ghi identifier `key_source` nhưng không chứa key. Legacy envelope không có identifier tiếp tục dùng resolution ưu tiên cũ để giữ explicit-key compatibility.
- Không lưu master key/derived key vào option, table, transient, cookie hay cache. Rotation WordPress salts làm credential cũ fail closed và yêu cầu nhập lại/ủy quyền lại.

# WP.4A credential lifecycle architecture

Shopee credential mutation is serialized with MySQL/MariaDB `GET_LOCK` using `ecomkit_shopee_credential_<connection-id>` and a two-second bounded wait. Refresh and OAuth reauthorization share this lock. Refresh reloads and decrypts the current row only after acquisition, then rechecks access expiry. The access/refresh pair is encrypted as one immutable unit and persisted with one database update. Lock failure fails closed.

`Ecomkit_Vuikhoe_Shopee_Token_Service::ensure_usable_access_token()` is the boundary for WP.4B and later server-side provider clients. The 300-second skew is Ecomkit policy, not a Shopee requirement. No scheduler, Order API, SyncRun, escrow, or Excel reconciliation exists in WP.4A.

# WP.4B provider-read boundary

WP.6B thêm ranh giới Payment riêng: chỉ một Order đã `MATCHED` trong Excel Batch, dùng `marketplace_order_id` chứ không dùng `eshop_order_code`. Client tái sử dụng token lifecycle và shop signer; POST JSON `order_sn` có common auth ở query, không ký body. Snapshot Payment lưu ở bốn cột nullable riêng; Order Detail, Excel, OrderItems và canonical v2 không bị sửa. Admin action là thủ công, một Payment call mỗi click, không retry hoặc GET fallback.

`Ecomkit_Vuikhoe_Shopee_Order_Service` is read-only and depends on `Ecomkit_Vuikhoe_Shopee_Token_Service`; it never decrypts connection credentials. Every list/detail HTTP request obtains an ephemeral usable access token, signs the shop-level base `partner_id + api_path + timestamp + access_token + shop_id`, and performs one `wp_remote_get()` without retries or redirects. Filters and cursors are not part of the signature base.

The low-level methods make exactly one request. Higher-level list pagination preserves opaque cursors, rejects non-progress/cycles, deduplicates exact `order_sn` with latest-page-wins plus conflict counters, and has a 100-page local guard. Higher-level detail reads split exact identities into batches of 50 and report completeness. No provider response is written to application tables.

# WP.6D Income evidence

`Shopee_Income_Service` truy vấn read-only `POST /api/v2/payment/get_income_detail` bằng shop token/signature hiện hữu. Cursor opaque, tối đa 10 trang; chỉ `order_sn` khớp chính xác với `orders.marketplace_order_id` mới lưu `income_raw_data`, `income_normalized_data`, `income_fetched_at` (thời điểm lấy, không phải provider update) và `income_request_id` trong Orders. Migration schema 8 chỉ thêm bốn cột nullable; Order Detail, Payment snapshot, matching, OrderItems và canonical v3 không đổi. Normalizer thuần giữ queried bucket tách biệt provider `status`, giữ amount NULL khác 0 và Unix time chuyển ISO UTC trong normalized snapshot. WP.6E mới đánh giá mapping kế toán.
