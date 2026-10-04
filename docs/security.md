# Bảo mật

## WP.6 canonical Result

Materialization là POST admin-only (`manage_options`) với nonce và PRG, chỉ đọc dữ liệu đã persist và không gọi network/token/Payment/Escrow. Tên, SĐT và địa chỉ source-backed được phép trong bảng Result admin và luôn HTML-escape; không được ghi log, diagnostic, error, transient, public REST hay frontend. Raw Excel/provider JSON không hiển thị.

## 1. Mô hình quyền WordPress

- Mọi admin page và REST mutation phải kiểm tra capability phía server; ẩn nút UI không phải authorization.
- Dùng capability riêng tối thiểu cho vận hành, export và quản lý Marketplace; chỉ quyền quản trị được cấu hình credential/user-facing settings.
- REST dùng WordPress authentication và nonce phù hợp; request server-to-server cần cơ chế riêng, scope hẹp và constant-time verification.
- Không có public registration hoặc public upload/sync endpoint.

## 2. Credential và secret

- Không commit Partner Key, Access Token, Refresh Token, encryption key, mật khẩu hoặc secret test.
- Credential lưu at-rest bằng authenticated encryption, versioned envelope; key mã hóa ở server config/environment, không lưu cùng ciphertext trong DB/options.
- API GET không bao giờ trả secret sau khi lưu; browser không prefill secret.
- Không đặt secret trong cron/action arguments, queue payload, lock key, URL, log, export, raw snapshot hoặc error response.
- API runtime và background runner cần cùng quyền giải mã tối thiểu. Key rotation/envelope version phải được thiết kế trước runtime.
- External read-only credential không có refresh token và không được plugin tự refresh/revoke.

## 3. Upload và parse

- Allowlist `.xlsx` và PDF; kiểm tra extension, MIME và file signature, không tin tên file do client gửi.
- Sanitize basename, tạo tên lưu trữ riêng, chống path traversal và không trả storage path qua API.
- Giới hạn tham chiếu: 25 MB/file, tối đa 100 file/Batch, đúng một Excel/Batch; phải xác nhận lại theo hosting WordPress.
- Không hỗ trợ `.xls`, `.xlsm`, `.csv`, macro hay external link ở contract parser hiện tại.
- Không thực thi formula. Chỉ dùng cached result khi an toàn; formula không có cached result là lỗi đọc.
- PDF không có text layer phải báo rõ; không giả OCR nếu chưa triển khai.

## 4. Output và injection

- Escape mọi source value theo đúng context HTML/attribute/JSON; không render raw HTML.
- Error raw value/context là text; redact path, database URL, password, token và secret-like values.
- CSV prefix apostrophe cho cell bắt đầu `=`, `+`, `-`, `@`; XLSX code phải là text.
- Không xuất raw provider payload, PII ngoài 24-column contract, internal IDs/path hoặc error payload.

## 5. PII và dữ liệu Marketplace

- Thu thập tối thiểu; masked PII phải giữ masked, không reconstruct/unmask.
- Không yêu cầu PII-specific optional Shopee fields mặc định nếu nghiệp vụ chưa duyệt.
- Raw và normalized snapshots cần access control, retention và audit; không coi chúng là dữ liệu hiển thị mặc định.
- Log chỉ giữ context đủ chẩn đoán, không log full request/response của provider.

## 6. Tính toàn vẹn và concurrency

- Matching rebuild trong transaction; rollback phải giữ kết quả trước đó.
- Unique connection + marketplace order ID; lock theo connection khi sync.
- Lock release phải xác minh ownership; không xóa lock của worker khác.
- Retry chỉ cho lỗi retryable; lỗi auth/validation/normalization không retry mù.
- Checkpoint chỉ commit cuối transaction/workflow thành công; job phải idempotent.

## 7. OAuth và provider calls

- OAuth state ngẫu nhiên, một lần, TTL, ràng buộc platform và người khởi tạo; callback validate redirect/shop/code.
- Redirect sau callback là allowlist nội bộ và không chứa secret.
- Live test phải rate-limit, ADMIN-only, read-only và có audit.
- Không tin provider payload: validate identity, timestamp, JSON-safety và cấm credential-like fields trong snapshots.

## 8. Error semantics an toàn

Lỗi trả `error_code` ổn định, message người dùng an toàn, severity/retryability và suggested action khi phù hợp. Không trả stack trace, SQL, filesystem path, token, provider secret hoặc raw credential. Unknown login và sai mật khẩu (nếu plugin có auth riêng, hiện không khuyến nghị) phải dùng cùng một response để tránh enumeration.

## 9. Vận hành WordPress

- Chỉ tải code Marketplace/admin khi cần; không expose global debug data.
- Tôn trọng HTTPS, secure cookies và WordPress salts; production không cho callback HTTP.
- Backup/restore phải bao gồm custom tables và encryption-key runbook; mất key đồng nghĩa credential không giải mã được.
- Uninstall không tự xóa dữ liệu/credential nếu chưa có xác nhận và retention policy rõ ràng.

## 10. Upload Excel WP.2

- Endpoint dùng `admin-post.php`, yêu cầu session WordPress, `manage_options` và nonce `ecomkit_vuikhoe_import_excel`.
- Chỉ một `.xlsx`; kiểm tra upload error, extension, WordPress filetype/content và giới hạn thấp hơn giữa 10 MB với giới hạn WordPress/PHP.

## 10. Excel runtime diagnostics WP.2C

- Self-test runtime là `admin-post.php` riêng, bắt buộc `manage_options` và nonce `ecomkit_vuikhoe_test_excel_runtime`; không nhận workbook công ty và không ghi dữ liệu nghiệp vụ.
- Trang chẩn đoán không hiển thị absolute temp/upload path, stack trace, raw SQL, php.ini đầy đủ, database credential, salt hoặc provider secret.
- Failure metadata chỉ giữ stage, classification, safe exception class/message, worksheet/physical row nếu có, entity/operation, PHP version và trạng thái load PhpSpreadsheet. Message được giới hạn độ dài và redact path/giá trị DB đã quote.
- Workbook upload chỉ tồn tại trong temp lifecycle và bị xóa trong `finally`; không giữ source workbook để debug.
- Plugin không tự sửa permission, không `chmod 777`, không cài extension/polyfill và không nới nhận arbitrary binary thành XLSX.
- Chỉ xử lý tối đa 2.000 dòng đồng bộ. Workbook khác đúng một worksheet hoặc thiếu header exact bị từ chối có cấu trúc.
- Tên gốc chỉ là metadata đã sanitize. Nội dung được chuyển sang file tạm tên ngẫu nhiên, không lưu path/URL và luôn xóa sau parse.
- Order identity là text trim-only. Numeric cell không nguyên, dạng scientific, quá 15 chữ số có thể tin cậy hoặc format không chỉ gồm chữ số bị từ chối bằng `EXCEL_UNSAFE_NUMERIC_ORDER_CODE`.
- Formula không được tính; chỉ cached scalar result mới được đọc. Raw metadata không lưu object thực thi hoặc công thức.
- Output admin được escape; SQL value dùng `$wpdb->insert`, `$wpdb->update` hoặc prepared query. Không có provider/network call.

## 11. InnoDB migration WP.2D

- Migration chỉ thao tác sáu bảng Ecomkit có tên do plugin tạo từ `$wpdb->prefix`; không có endpoint SQL công khai, table name do người dùng chọn hoặc câu lệnh tùy ý.
- Preflight xác minh máy chủ thật sự hỗ trợ InnoDB. Plugin không âm thầm hạ cấp sang MyISAM và vẫn giữ guard `ECOMKIT_NON_TRANSACTIONAL_TABLE` nếu môi trường chưa an toàn.
- Việc chuyển engine chỉ dùng `ALTER TABLE ... ENGINE=InnoDB`; không dùng `DROP`, `TRUNCATE`, bảng thay thế hay thay đổi bảng core/third-party. Schema version chỉ tăng sau khi cả sáu bảng và fingerprint bảo toàn dữ liệu đều được xác minh.
- Checkpoint migration chỉ lưu số dòng cùng hash cấu trúc/index và collation, không lưu nội dung hàng hoặc PII. Database Runtime Diagnostics chỉ hiển thị loại DB, hỗ trợ InnoDB, logical table name, engine và transactional status; không hiển thị host, username, password hoặc SQL.
- Migration có thể tiếp tục sau partial implicit commit: bảng đã đạt InnoDB không bị ALTER lại; lỗi còn lại giữ schema cũ và chặn import cho đến khi tất cả bảng đạt yêu cầu.

## 12. Shopee credential security WP.3

- Advanced mode dùng `ECOMKIT_CREDENTIAL_KEY` là Base64 của đúng 32 byte. Default mode dùng HKDF-SHA256 trên tám WordPress Security Keys theo thứ tự cố định; không cần sửa `wp-config.php` và không lưu derived master key trong database.
- Partner Key và per-shop access/refresh token dùng envelope version 1, AES-256-GCM, IV ngẫu nhiên 12 byte, tag 16 byte và AAD theo loại credential. Envelope sai, key sai hoặc bị sửa đều fail closed.
- OAuth state là opaque nonce 32 byte, transient 10 phút, one-time consume và cookie HttpOnly/Secure/SameSite=Lax. Callback không log hoặc lưu authorization code.
- UI, redirect và diagnostics chỉ chứa readiness, shop ID, expiry, safe error code/request ID; không chứa Partner Key, token, signature hoặc raw provider response.
- Envelope chỉ ghi safe source identifier `explicit_v1` hoặc `wp_salts_v1`. Placeholder WordPress salts bị từ chối. Rotation salts không xóa ciphertext hay fallback plaintext; admin phải nhập lại Partner Key và ủy quyền lại.

# WP.3C OAuth diagnostics

OAuth failure details are stored for at most ten minutes in a one-time transient bound to the initiating admin. The browser URL receives only an opaque reference. Allowed fields are stage, classification, sanitized provider error/message, request ID, HTTP status, API path, and duration. Authorization codes, signatures, Partner Keys, access/refresh tokens, and raw provider responses are neither logged nor persisted in diagnostics.

# WP.4A refresh safety

WP.6B Payment test chỉ dành cho admin có capability và nonce, nhận Order ID đã MATCHED từ Batch thay vì order_sn tùy ý. Signed URL, token, Partner Key và Payment raw JSON không xuất hiện trong diagnostics/transient/HTML; chỉ summary tài chính và key/type shape được hiển thị. Persisted raw Payment chỉ giữ `order_sn` và `order_income`, tách khỏi Order Detail và canonical. Một click có tối đa một Payment POST, không tự thử GET khi method bị từ chối.

WP.6C Batch action cũng chỉ dành cho admin và có nonce. Nó chỉ chọn các Order Excel Shopee MATCHED có marketplace ID/connection; tối đa một Payment POST mỗi Order trong một lượt, không retry, không gọi Lazada/extra provider Orders. Summary chỉ giữ count, Order ID nội bộ và classification lỗi; không có raw Payment, PII hay credential. Lỗi một Order không làm mất Payment snapshot cũ hoặc thay đổi Excel/Order Detail.

Shopee refresh tokens are treated as rotating single-use state. The refresh endpoint is never retried automatically. Network/timeout ambiguity, or encryption/persistence failure after provider success, moves the safe lifecycle toward `REFRESH_UNCERTAIN` and requires reauthorization while preserving old ciphertext. Definitive expired/invalid refresh authorization becomes `REAUTH_REQUIRED`. Tokens appear only in encrypted envelopes and ephemeral server-side memory, never in URLs, HTML, diagnostics, logs, or options plaintext.

# WP.4B Order API security

Order API signed URLs contain the access token because Shopee requires it, so full URLs are never logged or included in diagnostics. Diagnostics retain only stage, classification, API path, HTTP status, duration, safe provider error/message, request ID, shop ID, and counts. Detail customer/name/phone/address/buyer/item payloads are never logged or stored in options/transients; the admin PRG result contains at most five order numbers, statuses, raw total amounts, and item counts.

Inputs enforce a 15-day list window, bounded page and detail sizes, exact non-control-character `order_sn`, and an explicit optional-field allowlist. There are zero automated retries and no public REST business endpoint.

# WP.5 provider evidence and PII

Shopee reconciliation is an administrator-only POST action protected by `manage_options` and a nonce. It never accepts manual dates or pasted order IDs; both identity and query dates come from the persisted Excel Batch. Provider calls continue through the token lifecycle service and never expose access/refresh tokens, signatures or signed URLs.

The validated individual Order Detail object may contain recipient PII and is stored only in `orders.provider_raw_data`; its provider-neutral projection is stored separately in `provider_normalized_data`. Neither payload is logged, placed in transients/URLs, exposed through public REST, or rendered in the default summary. Diagnostics/errors retain only safe source location, order identity, stage, classification and suggestion. `raw_source_metadata` remains Excel-owned and unchanged.

No network transaction spans a DB transaction. Failed/incomplete pagination cannot produce negative match evidence. Older provider snapshots cannot overwrite newer `provider_updated_at`; absent provider timestamps use the conservative rule that an existing snapshot is retained. Payment/Escrow is not called and financial settlement is not inferred.

