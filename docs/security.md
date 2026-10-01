# Bảo mật

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

