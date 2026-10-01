# Tích hợp Shopee

## 1. Trạng thái phục hồi

Hệ thống tham chiếu đã có signer chính thức, OAuth foundation, refresh lifecycle, Order APIs, normalizer, adapter, synthetic E2E và chế độ external read-only. Tuy nhiên trạng thái live vẫn là `VALIDATION_PENDING`; `SHOPEE_LEGACY_DATA_MATCH = PENDING`. Plugin WordPress chưa triển khai bất kỳ phần runtime nào và không được coi synthetic evidence của repo cũ là nghiệm thu live cho plugin mới.

## 2. Identity và mapping

Chuỗi identity bắt buộc:

```text
Shopee order_sn
  = marketplace_order_id
  = raw_order_code
  = Mã đơn sàn
```

Giữ nguyên chuỗi, phân biệt hoa/thường, không trim để thay đổi identity provider. `order_sn` là khóa so sánh duy nhất; không dùng buyer, phone, address, item hoặc SKU.

Mapping normalized phục hồi:

- `order_sn` → identity và `Mã đơn sàn`.
- `order_status` → raw provider status → `Trạng Thái Đơn Hàng`.
- `create_time` (Unix seconds) → provider created time → `Ngày Lên Đơn`.
- `update_time` bắt buộc, hợp lệ → stale/update policy.
- `currency` giữ trực tiếp trong normalized snapshot, chưa thuộc 24 cột.
- `item_list`: item ID ưu tiên `order_item_id`, rồi `line_item_id`, rồi `item_id`; SKU ưu tiên seller model SKU khi materialize; giữ product, variation, quantity và discounted unit price.
- `total_amount`, `cod`, payment method, shipping carrier, seller message, buyer username và recipient address chỉ là provider metadata; không tự suy luận thành trường tài chính/PII canonical.

## 3. Order API behavior

- List theo `create_time` cho INITIAL và `update_time` cho INCREMENTAL.
- Một provider window tối đa 15 ngày; khoảng dài phải chia window.
- Cursor là opaque; thiếu hoặc lặp continuation cursor là lỗi.
- Deduplicate danh sách bằng exact `order_sn` trước khi lấy detail.
- Detail nhận tối đa 50 order IDs/request, chia batch tuần tự.
- Kết quả detail có thể đảo thứ tự; phải key lại bằng `order_sn`.
- Missing, duplicate hoặc unexpected detail làm fail coherent operation, không ghi partial result.

Các giới hạn/rate limit hiện thời phải lấy từ tài liệu/Developer Console tại lúc triển khai; không hardcode giả định chưa xác minh.

## 4. Sync và checkpoint

Checkpoint Shopee V1 là opaque JSON có version:

```json
{"v":1,"updatedThrough":0}
```

- INITIAL dùng explicit window và `create_time`; candidate checkpoint là window end.
- INCREMENTAL dùng `update_time`, checkpoint đã commit và một window end cố định trên Sync Run.
- Overlap tham chiếu mặc định 300 giây, cho phép 0–3600; đây là reliability policy của Ecomkit, không phải yêu cầu Shopee.
- Retry cùng Sync Run phải dùng cùng upper bound.
- Commit checkpoint chỉ sau khi snapshot, canonical bridge và Sync Run đều thành công.
- Unique source theo connection + `order_sn`; update cũ/bằng timestamp không overwrite snapshot mới.

## 5. Credential modes

### OAuth managed

ADMIN cấu hình app, bắt đầu authorization, state một lần có TTL, callback kiểm tra state/code/shop ID, đổi token và lưu encrypted envelope. Reauthorization cập nhật đúng connection `(SHOPEE, shop_id)`. Chỉ chuyển ownership sau khi acquisition thành công.

### External import read-only

Chỉ nhận Shop ID, Access Token và expiry; không nhận Refresh Token. Ownership là external nên plugin không refresh, revoke, reconnect OAuth, poll hoặc chạy load test. Test live chỉ là một thao tác read-only, window hẹp, do ADMIN chủ động chạy.

UI/API an toàn chỉ trả shop ID, expiry, presence/readiness và trạng thái; không trả token hoặc Partner Key.

## 6. Signing và server boundary

Signing, Partner Key, token lifecycle và provider calls chỉ chạy server-side. Clock dùng Unix seconds và cần kiểm soát clock skew. Endpoint, base string và tham số phải theo official Shopee Open Platform contract được xác minh ở stage triển khai; WP.0 không đóng băng chi tiết có thể thay đổi.

## 7. Live acceptance gate

Chỉ đặt `SHOPEE_LEGACY_DATA_MATCH = PASS` khi cùng shop, cùng time window, timezone và status filter cho kết quả exact case-sensitive set equality:

```text
missing order_sn = 0
extra order_sn   = 0
```

Tổng số bằng nhau nhưng tập ID khác nhau vẫn là FAIL. Mismatch phải phân loại bằng bằng chứng: `WINDOW_MISMATCH`, `TIMEZONE_MISMATCH`, `STATUS_FILTER_MISMATCH`, `PAGINATION_MISS`, `DETAIL_MISS`, `NORMALIZATION_DROP`, `STALE_POLICY_EFFECT`, `LEGACY_FILTER_DIFFERENCE` hoặc `UNKNOWN`.

Không đánh dấu PASS từ mock, fixture, synthetic E2E hoặc khi thiếu legacy artifact chính xác.

