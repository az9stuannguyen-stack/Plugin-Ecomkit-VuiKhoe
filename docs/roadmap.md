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

- Upload Excel an toàn và tạo Batch thực.
- Internal order identity/repository trên schema foundation.
- Chưa thay đổi contract 24 cột hoặc rule `Mã đơn sàn`.

Exit gate: upload/Batch/identity tests trên WordPress staging; không tự động bắt đầu stage này từ WP.1.

## WP.3 — File ingestion và parser

- Secure upload limits/type/signature checks.
- Excel contract (`Mã đơn sàn`, trim-only, duplicate/formula semantics).
- PDF text-layer parser và platform detection; OCR vẫn ngoài scope trừ khi phê duyệt.
- Structured errors và parser re-run isolation.

Exit gate: fixture/golden tests; production Excel golden vẫn phải được cung cấp và phê duyệt.

## WP.4 — Matching engine

- Exact key-only matching, duplicate precedence, platform aggregation và provenance.
- Transactional deterministic rebuild, counters và error creation.

Exit gate: đầy đủ matrix `MATCHED`, missing, duplicate, parse error, case/whitespace và rollback tests.

## WP.5 — Result, Error và History

- Read-only admin screens/REST, pagination 20/50/100, URL-persisted filters.
- Full-Batch summaries, safe error details, path/secret redaction.
- History theo Batch, không có destructive action mặc định.

Exit gate: capability tests, XSS/redaction tests, query/index performance ở dữ liệu mục tiêu.

## WP.6 — Export

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

