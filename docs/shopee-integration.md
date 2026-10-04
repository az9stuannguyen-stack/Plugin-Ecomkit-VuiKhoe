# Tích hợp Shopee

## WP.6 Result materialization

WP.6A không gọi Shopee. Nó chỉ đọc `provider_normalized_data` do WP.5 đã persist. Canonical v2 dùng `providerCreatedAt` chỉ khi Excel `order_date` NULL; recipient dùng `recipientName`, `recipientPhone`, `recipientFullAddress` và `recipientState > recipientCity > recipientRegion` cho Order `MATCHED`. `NOT_FOUND_IN_SHOPEE`, `DETAIL_MISSING` và Lazada giữ các giá trị provider là NULL. `CANCELLED` vẫn là một Order `MATCHED` và không bị loại.

## 1. Trạng thái WP.3

Plugin WordPress đã có provider config, signer, OAuth start/callback, token exchange và encrypted MarketplaceConnection. Live authorization vẫn là `VALIDATION_PENDING`; automated tests không thay thế Shopee Console và shop authorization thật. WP.3 không triển khai Order API, escrow hoặc refresh execution.

Credential encryption ưu tiên explicit `ECOMKIT_CREDENTIAL_KEY` khi được cấu hình hợp lệ; mặc định dùng `wp_salts_v1` dẫn xuất từ WordPress Security Keys. Salt rotation yêu cầu nhập lại Partner Key và reauthorize shop; ciphertext cũ không bị tự động xóa hay chuyển thành plaintext.

Authorization dùng `/api/v2/shop/auth_partner`; token exchange dùng `/api/v2/auth/token/get`. Production host là `https://partner.shopeemobile.com`, Sandbox là `https://partner.test-stable.shopeemobile.com`. Callback động là `rest_url('ecomkit/v1/shopee/callback')` và Production bắt buộc HTTPS.

Authorization code là single-use, short-lived (thường khoảng 10 phút). Access token thường khoảng 4 giờ nhưng runtime luôn dùng `expire_in`; refresh token thường khoảng 30 ngày và chỉ được lưu cho lifecycle tương lai. Khi refresh được triển khai, refresh token mới phải thay thế token cũ.

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

# WP.3C token exchange contract

Each one-time authorization code is exchanged at most once with `POST /api/v2/auth/token/get`. Production uses `https://partner.shopeemobile.com`; Sandbox uses `https://partner.test-stable.shopeemobile.com`. Query parameters are integer `partner_id`, Unix `timestamp`, and lowercase HMAC-SHA256 `sign` over `partner_id + api_path + timestamp`. The initial JSON body contains only string `code` and integer `partner_id`. Callback `shop_id` is retained and compared safely with `shop_id_list` when the response supplies that optional list.

Callback stages run from `CALLBACK_RECEIVED` through `OAUTH_COMPLETE`. Admin diagnostics contain only stage, classification, sanitized provider error/message, request ID, HTTP status, API path, and duration in a one-time user-bound transient. The redirect carries only an opaque reference. It never contains the code, signature, Partner Key, access/refresh token, or raw response. Historical failures that stored only the generic error and request ID cannot be reconstructed into an exact provider cause.

# WP.4A refresh contract

Refresh uses one `POST /api/v2/auth/access_token/get` call. Production host is `https://partner.shopeemobile.com`; Sandbox is `https://partner.test-stable.shopeemobile.com`. Public signing query fields are `partner_id`, `timestamp`, and lowercase HMAC-SHA256 `sign` over `partner_id + api_path + timestamp`. JSON contains string `refresh_token` plus integer `partner_id` and integer `shop_id`; no merchant/supplier/user/principal identity is added.

The current refresh token is single-use. Under the shared connection advisory lock, Ecomkit reloads the credential, skips refresh when the access token is usable beyond its 300-second application-policy skew, or validates and atomically encrypts/persists both returned tokens and provider `expire_in`. The 30-day refresh expiry shown in UI is explicitly an estimate, not provider-returned authority. There are zero automatic retries after an ambiguous send, no cron scheduler, and recovery is manual reauthorization. Future WP.4B code must call `ensure_usable_access_token(connectionId)` rather than decrypt credentials directly.

# WP.4B Order API contract

Order reads use `GET /api/v2/order/get_order_list` and `GET /api/v2/order/get_order_detail` on the configured environment host. Common query parameters are `partner_id`, `timestamp`, `access_token`, `shop_id`, and `sign`; the HMAC-SHA256 shop signature base is `partner_id + api_path + timestamp + access_token + shop_id`. Business filters are excluded from that base, and the signed URL is never diagnostic output.

GetOrderList accepts only `create_time` or `update_time`, positive ordered Unix seconds covering at most 15 days, page size 1-100, an opaque cursor, and an allowlisted optional status. One-page and all-pages clients are separate. The latter rejects cursor stalls/cycles, stops at 100 pages, and applies deterministic exact-identity deduplication with latest occurrence winning and duplicate/conflict counts.

GetOrderDetail accepts exact safe `order_sn` strings and explicit optional-field names. A low-level call is capped at 50; the batched client chunks larger sets and reports requested/returned counts, missing, extra, and duplicate identities without fabricating data. The live admin test converts `datetime-local` values with `wp_timezone()`, makes at most one five-order list call and one detail call, and persists no provider orders or PII.

# WP.4B.1 live response alignment

GetOrderList explicitly sends `response_optional_fields=order_status` as a business query filter; it is excluded from the shop-level signature base. Each list item requires only a non-empty string `order_sn`. `order_status` is preserved when returned and otherwise remains `null`. A final page may use an empty or omitted `next_cursor` when `more=false`.

The default admin test accepts one date and converts its WordPress-timezone boundaries to Unix seconds using `DateTimeImmutable`: local `00:00:00` to local `23:59:59`. No timezone offset is hardcoded. Failure diagnostics contain shape metadata only. WP.4B.1 itself introduced no all-history crawler or reconciliation; WP.5 below implements bounded Excel-derived windows.

## WP.5 — Excel-derived exact reconciliation

## WP.6B — Payment/Escrow one-order POST

Ecomkit dùng `POST /api/v2/payment/get_escrow_detail` tại environment host hiện có, `Content-Type: application/json`, body `{"order_sn":"<marketplace_order_id>"}`. Common shop auth `partner_id`, `timestamp`, `access_token`, `shop_id`, `sign` nằm ở query; signature base giữ nguyên và không chứa body/order_sn. Token lấy qua `ensure_usable_access_token()`. Chỉ admin click cho một Order Shopee MATCHED mới gọi provider; không retry, không GET fallback. Nếu live shop từ chối method/contract, ghi classification an toàn để xác minh lại; nếu thiếu permission, báo external permission. Payment snapshot tách riêng và không map canonical trong WP.6B.

WP.6C sử dụng lại single-order POST đã được live xác nhận để enrich Batch: chỉ `platform=SHOPEE`, `matching_status=MATCHED`, đúng `marketplace_order_id`, tối đa một Payment call cho mỗi Order đủ điều kiện. Mặc định snapshot hợp lệ được tái sử dụng; admin có thể chủ động refresh. Sau mỗi lượt, canonical v3 materialize từ Payment normalized data đã persist, không cần provider call riêng. `commissionFee`, `serviceFee`, `sellerTransactionFee` map trực tiếp; `escrowAmountAfterAdjustment` ưu tiên `escrowAmount`. Không lấy `buyerTotalAmount` làm seller receivable, không map affiliate Shopee thành affiliate Vui Khỏe.

- Input is an existing `SUCCESS`/`WARNING` Excel Batch; only rows with `platform=SHOPEE` participate. LAZADA rows are never changed.
- The query plan comes from the Excel `Ngày đặt` value in WordPress timezone. Consecutive local days are grouped, each window is at most 15 days, and list calls use `time_range_field=create_time`. There is no manual date/order-ID input and no unbounded history scan.
- Every window uses `get_all_orders()`, opaque provider cursors, exact `order_sn` deduplication and the existing 100-page stall/cycle guard. Negative evidence is valid only after a window completes.
- Identity is strictly `Excel marketplace_order_id === Shopee order_sn`, case-sensitive. `MATCHED`, `MISSING_IN_SHOPEE` and `EXTRA_IN_SHOPEE` are exact set operations; no name, phone, address, SKU, amount or time-proximity fallback exists.
- `GetOrderDetail` runs only for matched Excel identities and batches at most 50 IDs per request. Missing detail becomes `DETAIL_MISSING`; extra/duplicate detail identities fail closed.
- The validated per-order detail object is stored in `provider_raw_data`; a pure deterministic neutral projection is stored in `provider_normalized_data`. `provider_updated_at` protects against stale overwrite and `matched_at` records reconciliation evidence time. Excel `raw_source_metadata` remains unchanged.
- One READY connection is selected automatically. Multiple READY shops require an explicit administrator selection; provider evidence is bound through `connection_id` to that shop.
- WP.5 performs zero automatic retries, no Payment/Escrow request, no Lazada API call and no SyncRun creation. A controlled token refresh may still occur inside `ensure_usable_access_token()`.
# WP.6D Income Status validation

Ecomkit dùng `POST /api/v2/payment/get_income_detail` với JSON `cursor`, `date_from`, `date_to`, `income_status`, `page_size`; shop auth vẫn nằm trên query và signer cũ. Local Shop: 1=RELEASED, 2=PENDING; không gửi 0. RELEASED yêu cầu `date_to` sau `date_from`, tối đa 14 ngày payout date. PENDING gửi ngày hợp lệ theo schema nhưng **không** được lọc bởi ngày. `page_size=30` theo ví dụ schema (không giả định 100 hợp lệ). Response `income_detail_list.list` và `next_page.cursor`; empty cursor mới kết thúc. Chỉ so `order_sn` exact với marketplace ID, không dùng eShop ID. Diagnostic duyệt nhiều nhất 10 trang/click; incomplete/network/provider error không được kết luận vắng đơn. Snapshot Income tách biệt; canonical mapping kế toán còn chờ phê duyệt sau live VN shop validation.

WP.6E chấp nhận riêng envelope live hợp lệ `HTTP 200`, `error:""`, `response:null` là `SHOPEE_INCOME_EMPTY`; JSON lỗi, thiếu contract hay `response` sai kiểu vẫn invalid. Danh sách hợp lệ được kiểm tra nghiêm ngặt ở top-level `income_detail_list` hoặc `response.income_detail_list`, không suy từ key khác. Pipeline tự truy vấn PENDING (ngày chỉ là field bắt buộc, không lọc), rồi tối đa sáu cửa sổ RELEASED liên tiếp, mỗi cửa sổ 14 ngày, mới nhất trước. Mỗi page/list quét **một lần cho cả Batch**, index bằng exact `order_sn`, không truy vấn cùng cửa sổ cho từng Order. Tối đa 10 pages/window; query chưa duyệt hết không bị gọi là NOT_FOUND. Income là best-effort; Payment snapshot/canonical v3 vẫn tạo Result nếu Income trống hoặc lỗi.
