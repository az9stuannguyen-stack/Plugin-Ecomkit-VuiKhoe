# Lộ trình triển khai

## WP.0 — Architecture + Canonical Contract Recovery

Trạng thái: **PASS** (`f617278`).

- Khóa đúng 24 header, thứ tự, key, kiểu và NULL/export rules.
- Phục hồi matching, Result/Error/History behavior, Shopee mapping và security decisions.
- Tạo kiến trúc WordPress mục tiêu, không tạo runtime plugin.

Exit gate: sáu tài liệu tồn tại; repo Git riêng; repo cũ không thay đổi; không có PHP runtime/plugin bootstrap.

## WP.1 — Plugin scaffold và quality baseline

Trạng thái: **PASS**.

- Plugin bootstrap, Composer classmap autoload, compatibility guard và packaging.
- Sáu custom table foundation, schema version `1`, `dbDelta()` và incremental upgrade hook.
- Activation idempotent; deactivation/uninstall mặc định không xóa dữ liệu.
- Bảy trang quản trị placeholder, `manage_options`, diagnostic và admin notice an toàn.
- Không có Excel, matching, Shopee, provider call hoặc background job.

Exit gate: static/package/schema checks pass; ZIP cài đặt có đúng plugin root; không secret/provider traffic. Manual activation vẫn cần thực hiện trên WordPress staging/local vì workspace không có instance WordPress.

## WP.2 — Excel Upload + Batch + Internal Order Identity

Trạng thái: **AUTOMATED PASS / MANUAL_REQUIRED**.

- Upload `.xlsx` an toàn, parser PhpSpreadsheet, Batch thực và order identity trim-only.
- Lỗi có vị trí/suggestion, import summary, preview, History và Error pages.
- Schema `2` chỉ cho phép trạng thái matching nullable trước reconciliation; giữ contract 24 cột và rule `Mã đơn sàn`.

Exit gate tự động: PASS bằng synthetic fixtures/static tests. Manual WordPress staging với bản sao Excel thực tế vẫn bắt buộc; production Golden chưa được xác nhận.

### WP.2A — Production Excel Structure Support

Trạng thái: **AUTOMATED PASS / MANUAL_REQUIRED**.

- Hỗ trợ title rows và phát hiện header bounded; nguồn chuẩn `Sàn & Mã Đơn` tách platform + marketplace order ID.
- Giữ `Mã đơn hàng eShop` tách biệt khỏi marketplace identity; duplicate theo platform + mã đơn.
- Materialize nhiều dòng sản phẩm thành nhiều `order_items` của đúng một Order; xử lý continuation và orphan rõ ràng.
- Runtime `0.2.1`, schema giữ `2`; không có provider/OAuth/API call.

Exit gate cuối: người dùng phải kiểm thử lại trên WordPress staging/local bằng chính file công ty trước đây lỗi `EXCEL_REQUIRED_COLUMN_MISSING`. Không bắt đầu WP.3 trước khi kiểm thử này đạt.

### WP.2B — Production Excel Regression + Import Root Cause

Trạng thái: **AUTOMATED PASS / MANUAL_REQUIRED**.

- Ba fixture tổng hợp tái hiện chính xác cấu trúc 3/3, 16/23 và 8/18 Order/OrderItem, gồm title merge, header row 3, continuation chains, blank row và footer.
- Classifier cấu trúc loại footer khỏi item, giữ lỗi orphan/invalid, và định nghĩa rõ số dòng nghiệp vụ.
- Persistence kiểm tra mọi write, xác nhận transactional engine, rollback business writes và lưu failure stage chẩn đoán an toàn.
- Runtime `0.2.2`, schema giữ `2`; provider calls vẫn bằng 0.

Exit gate cuối: chạy lại ba workbook trên WordPress thật, ưu tiên file shape 8/18. Không bắt đầu WP.3 trước khi File C import thành công.

### WP.2C — Production Hosting Diagnostics + Excel Load Root Cause

Trạng thái: **AUTOMATED PASS / MANUAL_REQUIRED**.

- Thêm Excel Runtime Diagnostics và administrator-only synthetic XLSX roundtrip self-test.
- Phân loại chính xác missing vendor/class/extension/temp/source và persist stage/classification an toàn vào metadata Batch.
- Xác thực source file tồn tại, readable và size > 0 ngay trước workbook load; cleanup vẫn ở `finally`.
- Document toàn bộ `vendor/` là runtime bắt buộc khi cập nhật thủ công. Runtime `0.2.3`, schema giữ `2`, provider calls bằng 0.

Batch #6 đã được tạo bởi build trước khi diagnostics đầy đủ được triển khai; nếu metadata hiện hữu không có stage/classification thì exact historical cause không thể phục hồi. Exit gate cuối là self-test PASS rồi import File C đạt 8 Order/18 Item trên WordPress thật.

### WP.2D — Database Transaction Compatibility / InnoDB Migration

Trạng thái: **AUTOMATED PASS / MANUAL_REQUIRED**.

- Batch #7 xác nhận blocker tại `BATCH_PERSIST` với `ECOMKIT_NON_TRANSACTIONAL_TABLE`; Excel runtime và parser không phải nguyên nhân hiện tại.
- Runtime `0.2.4`, schema `3`; migration `2 → 3` kiểm tra hỗ trợ InnoDB rồi chỉ chuyển các bảng Ecomkit chưa transactional.
- WP.2E dùng runtime `0.2.5`, schema `4`; migration `3 → 4` sửa explicit nullability của Order trước reconciliation và không dựa riêng vào dbDelta.
- WP.3 dùng runtime `0.3.0`, schema `4`: cấu hình Shopee, OAuth callback, token exchange và encrypted MarketplaceConnection. Order API, refresh execution và reconciliation vẫn bị hoãn sang stage sau.
- WP.3B dùng runtime `0.3.2`, schema `4`: credential key resolver hỗ trợ explicit key và zero-config HKDF từ WordPress Security Keys; không có DB-stored master key.
- Migration không drop/truncate, bảo toàn và xác minh row count, cột, index, charset/collation; partial conversion không tăng schema version và có thể tiếp tục ở lần chạy sau.
- Fresh install tạo rõ cả sáu bảng với `ENGINE=InnoDB`; Database Runtime Diagnostics hiển thị engine/transactional readiness an toàn, không lộ credential hoặc SQL.
- Guard `ECOMKIT_NON_TRANSACTIONAL_TABLE` vẫn hoạt động; provider calls vẫn bằng 0 và parser production-shape không thay đổi.

Exit gate cuối: sau khi cập nhật plugin trên WordPress thật, xác nhận cả sáu bảng là `InnoDB — OK`, rồi import File C đạt 8 Order/18 Item/4 SHOPEE/4 LAZADA/0 lỗi. Không bắt đầu WP.3 trước khi kiểm thử này đạt.

## WP.3 — Shopee Provider Configuration + OAuth + Encrypted Credential Storage

- Cấu hình provider server-side, OAuth state/callback và credential envelope được mã hóa.
- Không hiển thị master key/secret; không tuyên bố live validation nếu chưa có bằng chứng.

Không tự động bắt đầu WP.3 sau WP.2.

## WP.4 — Matching engine

- Exact key-only matching, duplicate precedence, platform aggregation và provenance.
- Transactional deterministic rebuild, counters và error creation.

Exit gate: đầy đủ matrix `MATCHED`, missing, duplicate, parse error, case/whitespace và rollback tests.

## Kế hoạch cũ đã được thay thế — Result, Error và History

- Read-only admin screens/REST, pagination 20/50/100, URL-persisted filters.
- Full-Batch summaries, safe error details, path/secret redaction.
- History theo Batch, không có destructive action mặc định.

Exit gate: capability tests, XSS/redaction tests, query/index performance ở dữ liệu mục tiêu.

## Kế hoạch cũ đã được thay thế — Export

- XLSX và BOM CSV đúng contract 24 cột.
- Filter toàn Batch, stable ordering, date/text/null conventions và formula-injection defense.

Exit gate: byte/content tests, Unicode/leading-zero tests và không rò raw/secret/path.

## WP.7 — Shopee foundation

- Encrypted provider config/connection credentials, signer, official HTTP client và safe errors.
- OAuth-managed và external read-only modes tách biệt.
- Token refresh lock/rotation và readiness metadata.

Exit gate: synthetic contract tests; chưa tuyên bố live validation.

## WP.8 — Shopee orders và background sync

- List/detail/window/cursor behavior, pure normalizer, snapshots, stale policy.
- INITIAL/INCREMENTAL checkpoint, overlap, idempotent background jobs và Batch bridge.
- Exact 24-column mapping chỉ theo bảng WP.0; bổ sung PII/financial mapping cần change control.

Exit gate: registered synthetic E2E qua Result/History/XLSX/CSV, retry/restart và zero-partial-write tests.

## WP.9 — Live read-only validation

- Xác nhận Developer Console permissions, callback, rate limit và environment.
- Một test hẹp/read-only, audit đầy đủ.
- So sánh exact `order_sn` set với legacy cùng shop/window/timezone/status.

Exit gate: chỉ PASS khi missing=0 và extra=0; nếu thiếu approval/artifact thì giữ `VALIDATION_PENDING`.

## WP.10 — Hardening và release

- Threat model, dependency/license review, load/concurrency, backup/restore, key rotation, retention.
- WordPress compatibility matrix, observability, operator runbook và release package reproducible.

Exit gate: acceptance nghiệp vụ, security review và rollback rehearsal.

## Ngoài phạm vi cho đến khi được phê duyệt

- Lazada, TikTok Shop, Google Sheets và OCR.
- Tự động tính các trường tài chính.
- Fuzzy matching hoặc ghép theo PII/sản phẩm.
- Xóa dữ liệu tự động khi uninstall.
- Đánh dấu Shopee production-ready dựa trên synthetic tests.

# WP.3C gate

WP.3C runtime `0.3.3`, database schema `4`: minimal Shopee token request body, strict integer Partner ID, callback/shop identity validation, and safe stage-specific live diagnostics. Its live OAuth gate passed before WP.4A began.

# WP.4A completion gate

Runtime `0.4.0`, schema `4`: locked single-use Shopee refresh rotation, `ensure_usable_access_token()`, safe lifecycle diagnostics, and an admin-only manual refresh action. There is deliberately no WP-Cron refresh. WP.4B Order API work must not start automatically and must consume access tokens only through the token service.

# WP.4B completion gate

Runtime `0.4.1`, schema `4`: read-only GetOrderList/GetOrderDetail foundation, shop signing, token lifecycle integration, bounded pagination/detail batching, strict identity completeness, safe diagnostics, and an admin live test capped at two provider calls. Excel reconciliation and provider normalization remain blocked until live Order API validation passes; Payment/Escrow remains out of scope.

# WP.4B.1 gate

Runtime `0.4.2`, schema `4`: live GetOrderList alignment makes `order_status` optional, requests it explicitly, adds safe response-shape diagnostics, and replaces manual time entry with a WordPress-timezone full-day selector. WP.5 remains unimplemented; its future bounded windows should be derived from Excel business dates rather than manual dates or unbounded history scans.

## WP.5 — Excel ↔ Shopee exact reconciliation

Runtime `0.5.0`, schema `5`: a successful/warning Excel Batch can be reconciled through an explicit admin action. Windows are derived automatically from Shopee rows' `Ngày đặt`, split to at most 15 calendar days, use `create_time`, and retrieve all pages with the existing cursor/cycle/page-limit guards. Missing dates remain unresolved with an actionable error.

Matching is exact and case-sensitive on `marketplace_order_id === order_sn`. Only complete windows may produce `NOT_FOUND_IN_SHOPEE`; extra provider orders remain summary metadata and never become Excel Orders. Detail is requested only for matched Excel IDs in batches of 50. Provider raw/normalized evidence is separated from Excel raw, reruns are idempotent, and stale provider snapshots cannot move backwards.

WP.5 does not call Payment/Escrow, Lazada, PDF, final export or create SyncRun. WP.6 remains gated on a successful live reconciliation and will own result/canonical 24-column materialization.

## WP.5A — production Excel order dates

Runtime `0.5.1`, schema `5`: repair production `Ngày đặt` extraction for Excel serials and strict `d/m/Y H:i[:s]` strings containing ordinary whitespace, LF or CRLF. Parsed instants use the WordPress timezone and persist as UTC without changing the original raw cell. Batch preview exposes the local date/time before reconciliation.

Invalid non-empty dates produce `EXCEL_INVALID_ORDER_DATE`. Missing dates remain unresolved under `SHOPEE_RECON_ORDER_DATE_MISSING`; when no window can be planned, provider calls remain zero, the UI reports `WARNING`, and no green completed notice or false `NOT_FOUND_IN_SHOPEE` is allowed. WP.6 remains blocked until the production re-import and live reconciliation pass.

## WP.6 — Reconciliation Result UI + canonical 24 cột

Trạng thái: **AUTOMATED PASS / MANUAL_REQUIRED**.

Runtime `0.6.0`, schema `6`: mỗi Excel Order có một canonical snapshot `v1` đúng 24 cột trong `orders.canonical_data`. Migration chỉ thêm nullable version/timestamp/fingerprint. Result admin materialize lại local, phát hiện stale, lọc platform/matching và giữ cả Shopee, CANCELLED, missing/detail-missing và Lazada. Payment/Escrow, Lazada API và export chưa triển khai.

WP.7 chỉ được xem xét sau khi WP.6A được kiểm tra thủ công và nguồn của các cột chưa map được quyết định.

## WP.6A — canonical source coverage audit

Runtime `0.6.1`, schema giữ `6`, canonical snapshot `v2`. Kiểm tra đủ 24 cột cho thấy `Mã đơn ESHOP` có nguồn Excel production nhưng WP.6 chưa materialize; parser nay persist field và header provenance cho import mới. Snapshot v1 hiện là stale và operator có thể materialize lại local. Batch cũ thiếu header map chỉ được khôi phục cell D khi đồng thời đáp ứng provenance nghiêm ngặt của workbook production 8 Orders/18 Items đã xác minh; Batch khác để NULL. Các cột tài chính cần Payment/Escrow và các cột chưa có nguồn xác thực vẫn NULL. WP.7 chưa bắt đầu; cần manual review nguồn trước khi xuất.

## WP.6B — Shopee Payment/Escrow foundation

Runtime `0.6.2`, schema `7`, canonical giữ `v2`. Admin chọn một Order Shopee MATCHED từ Batch và bấm kiểm tra; mỗi lần chỉ gửi một `POST /api/v2/payment/get_escrow_detail` với JSON `order_sn` từ `marketplace_order_id`. Không có GET fallback, retry, batch endpoint hay tự động đồng bộ. Validated accounting evidence được lưu riêng trong nullable `payment_raw_data`, `payment_normalized_data`, `payment_fetched_at` (thời điểm lấy, không phải thời điểm provider cập nhật) và `payment_request_id`; migration chỉ thêm cột. Live shop vẫn phải xác nhận POST contract/quyền Payment. Chưa có mapping canonical tài chính, chưa có WP.7. WP.6C cần live evidence và phê duyệt ngữ nghĩa kế toán.

## WP.6C — Shopee financial canonical mapping

Runtime `0.6.3`, schema giữ `7`, canonical `v3`. Live Payment POST đã được xác nhận ngoài giai đoạn code. Action Batch "Cập nhật tài chính Shopee" gọi tối đa một lần cho mỗi Order Shopee MATCHED thiếu snapshot (hoặc mỗi Order khi admin chọn refresh), không gọi cho Lazada; lỗi từng Order độc lập, snapshot cũ được giữ. Sau đó materialize local toàn Batch. `commissionFee`, `serviceFee`, `sellerTransactionFee` map trực tiếp vào ba phí sàn; seller receivable dùng `escrowAmountAfterAdjustment` rồi `escrowAmount`. V1/v2 stale. Các phần trăm, công nợ, đã thu, chênh lệch, affiliate/discount nội bộ vẫn NULL. WP.7 chưa bắt đầu.

## WP.6D — Shopee Income Status foundation

Runtime `0.6.5`, schema `8`, canonical giữ `v3`. Admin chọn một Order Shopee MATCHED, truy vấn PENDING hoặc RELEASED bằng `POST /api/v2/payment/get_income_detail`, so khớp `order_sn` chính xác trên tối đa 10 trang cursor (30 bản ghi/trang theo ví dụ schema). RELEASED dùng cửa sổ payout date tối đa 14 ngày; PENDING bắt buộc gửi hai ngày hợp lệ nhưng hai ngày này **không lọc** record. Chỉ record tìm đúng được lưu ở Income snapshot riêng. Không map Đã Thu Tiền/Trạng Thái Công Nợ, không tự đồng bộ Batch. Cần live shop review trước WP.6E; WP.7 chưa bắt đầu.

## WP.6E — One Upload → Automatic 24-column Result

Runtime `0.6.6`, schema giữ `8`, canonical giữ `v3`. Một upload tự lập lịch Batch pipeline: WP.5 reconciliation/detail, Payment từng Order (snapshot hợp lệ được tái dùng), Income shared-list best-effort, cuối cùng canonical materialization. WP-Cron chia Payment theo từng Order và Income theo từng page/cửa sổ; Result hiển thị tiến độ rồi tự cập nhật. Income HTTP 200 `response:null` là `SHOPEE_INCOME_EMPTY`, không là dữ liệu lỗi hay số 0. Không có cột kế toán mới được map. Cần kiểm tra một file production trên WordPress thật trước khi tuyên bố live PASS; WP.7 chưa bắt đầu.

## WP.6F — Legacy Financial Formula Contract + Giá SP source audit

Runtime `0.6.7`, schema giữ `8`, canonical giữ `v3`. Workbook legacy phê duyệt `% Tổng Chi Phí=(L+M+N+I+J)/F`, `Tổng Tiền Sẽ Thu=F-(L+M+N+I+J)` (chỉ fallback sau Shopee escrow), `% Chiết Khấu Vui Khỏe=(I+J)/F`, `% Chi Phí Sàn TMĐT=(L+M+N)/F`. `Chênh lệch` có `#REF!`, chưa có công thức tin cậy. Production Excel không có `Giá SP (VAT 8%)`, affiliate hoặc discount nội bộ; Order Detail/Payment không chứng minh tương đương. Vì vậy chưa kích hoạt bất kỳ phép tính canonical mới; giữ NULL và one-upload pipeline. Quy ước tương lai: ratio `0.0393=3.93%`, không float/zero-fill/round âm thầm. Cần quyết định nguồn nội bộ, quy tắc multi-item và biểu diễn thập phân trước khi triển khai; WP.7 chưa bắt đầu.

## WP.6E.1 — Automatic Pipeline Progress UX

Runtime `0.6.8`, schema `8`, canonical `v3`. Result có progress card responsive, phần trăm/counters từ persisted Batch state, nhãn tiếng Việt, AJAX admin read-only poll 2,5 giây và cảnh báo Cron sau 90 giây thiếu heartbeat. Trạng thái SUCCESS/WARNING đạt 100% khi Result rows sẵn sàng; Income EMPTY và ô thiếu nguồn không ngăn hoàn tất; hard ERROR không giả 100%. Lịch sử có trạng thái pipeline và tỷ lệ. Không thêm provider API, thay mapping, schema hoặc nút nghiệp vụ. Cần kiểm tra upload thật trên WordPress; WP.7 chưa bắt đầu.

# WP.6H — Exact financial formulas

Plugin 0.7.0 / DB 8 / canonical v5: ba tỷ lệ legacy và fallback Tổng Tiền Sẽ Thu tính tự động từ nguồn Excel nội bộ + Payment đã duyệt, bằng exact decimal-string/rational engine. Snapshot v1–v4 stale; tử số/mẫu số nằm trong canonical JSON, không thêm cột DB/Result. NULL khác 0; zero divisor an toàn; Escrow vẫn ưu tiên; Chênh lệch chưa có công thức. UI phần trăm hiển thị tỷ lệ ×100 với 12 chữ số thập phân tối đa, cắt và ghi `…` khi vô hạn, không sửa canonical. Cần manual one-upload validation; WP.7 chưa bắt đầu.

# WP.6G — Internal Excel sources

Plugin 0.6.9 / DB 8 / canonical v4: năm trường nội bộ tùy chọn được lấy từ cùng workbook Excel, persist Order, materialize trực tiếp; bảy header cũ tiếp tục hợp lệ. Không thêm upload hay thao tác nghiệp vụ. Snapshot v1–v3 cần rematerialize. Ba tỷ lệ và fallback receivable legacy tiếp tục gated cho đến khi có exact decimal arithmetic và chính sách precision; Shopee Escrow không thay đổi. Cần manual WordPress test với workbook mở rộng. WP.7 chưa bắt đầu; Google Sheets/eShop adapter chỉ là lựa chọn tương lai.
# WP.6H.1A — Safe Payment snapshot audit

Plugin 0.7.1 / DB 8 / canonical v5: thêm công cụ chẩn đoán phí Shopee admin-only trong Kết quả, đọc Payment snapshot đã lưu theo Batch + marketplace order ID chính xác; AJAX POST hiển thị và POST tải JSON projection tài chính an toàn. Không provider call, data mutation hay mapping change. Cần chạy với hai đơn production và gửi lại JSON an toàn để kết thúc audit WP.6H.1; chỉ sau đó cân nhắc WP.6H.2. WP.7 chưa bắt đầu.
# WP.6H.2 — completed in code; manual WordPress review pending

Plugin 0.7.2 / DB 8 / canonical v6: Shopee Phí dịch vụ legacy được phân loại lại từ `serviceFee + shippingSellerProtectionFeeAmount − discount_vuikhoe` bằng exact math. Thiếu toán hạng = NULL, âm cần review, Escrow ưu tiên, raw/normalized Payment không bị thay đổi. Snapshot v1–v5 stale và rematerialize từ nguồn lưu sẵn, không tự refetch Payment. Một upload tự xử lý; diagnostics dành cho admin và thu gọn. WP.7 chưa bắt đầu.
