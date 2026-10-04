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

Next: **WP.7 — XLSX/CSV Export from Canonical Results**.

