# Hợp đồng canonical 24 cột

## 1. Quy tắc chung

- Tên, thứ tự và số lượng cột dưới đây là contract bắt buộc.
- Một `Order` trên một dòng; OrderItem không nhân số dòng export.
- `NULL` xuất thành ô trống, không đổi thành `0`, `false` hoặc chuỗi `NULL`.
- Date đã có nguồn xuất dạng `YYYY-MM-DD` như exporter tham chiếu.
- Mã đơn phải được ghi dạng text để không mất số 0 đầu hoặc bị scientific notation.
- Không xuất raw source, UUID nội bộ, storage path, credential hay error payload.
- Không tính trường tài chính khi chưa có nguồn và công thức được duyệt.

## 2. Định nghĩa chính xác

| # | Header export | Canonical key | Kiểu logic | Nguồn file matching | Shopee hiện hành | Quy tắc NULL |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | Ngày Lên Đơn | `order_date` | date/datetime | `EXCEL > SHOPEE_ORDER_DETAIL`: Excel `Ngày đặt` luôn thắng; chỉ khi Excel NULL, Order SHOPEE `MATCHED` mới fallback `providerCreatedAt`; sau cùng NULL | `create_time` đã normalize thành `providerCreatedAt` | Trống nếu cả hai thiếu/không hợp lệ |
| 2 | Mã đơn ESHOP | `eshop_order_code` | text | `UNMAPPED` | `UNMAPPED` | Trống |
| 3 | Mã đơn sàn | `raw_order_code` | text | `EXCEL > SHOPEE_ORDER_DETAIL`: mã nguồn primary luôn thắng | `order_sn` exact chỉ là fallback | Bắt buộc với record hợp lệ |
| 4 | Kênh Bán Hàng | `sales_channel` | text | `EXCEL > SHOPEE_ORDER_DETAIL`: platform Excel luôn thắng | Provider hằng `SHOPEE` chỉ là fallback | Trống nếu nguồn chưa xác định |
| 5 | Trạng Thái Đơn Hàng | `order_status` | text | `SHOPEE_ORDER_DETAIL` | `providerStatus` nguyên bản khi `MATCHED` | Trống nếu thiếu |
| 6 | Tên Khách Hàng | `customer_name` | text | Excel chưa map | `SHOPEE_ORDER_DETAIL`: `recipientName` khi `MATCHED` | Trống nếu thiếu detail/giá trị |
| 7 | SĐT | `phone` | text | Excel chưa map | `SHOPEE_ORDER_DETAIL`: `recipientPhone` khi `MATCHED` | Trống; không fallback buyer username |
| 8 | Địa Chỉ | `address` | text | Excel chưa map | `SHOPEE_ORDER_DETAIL`: `recipientFullAddress` khi `MATCHED` | Trống; không tự ghép component |
| 9 | Tỉnh/TP | `province_city` | text | Excel chưa map | `SHOPEE_ORDER_DETAIL`: `recipientState` > `recipientCity` > `recipientRegion` khi `MATCHED` | Trống; không suy từ full address |
| 10 | Ngày Xuất VAT | `vat_issued_date` | date/datetime | `UNMAPPED` | Không có nguồn duyệt | Trống |
| 11 | Ghi Chú | `note` | text | `UNMAPPED` | Không có nguồn duyệt | Trống |
| 12 | Đã Thu Tiền | `amount_collected` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không suy từ COD/total | Trống |
| 13 | Trạng Thái Công Nợ | `receivable_status` | text | `FUTURE_PAYMENT_ESCROW` | Chưa có payment evidence | Trống |
| 14 | Chênh lệch | `difference_amount` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |
| 15 | Giá SP (VAT 8%) | `product_price_vat_8` | decimal(20,4) | `UNMAPPED` | Không tính từ item price | Trống |
| 16 | % Tổng Chi Phí | `total_cost_percent` | decimal(9,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |
| 17 | Tổng Tiền Sẽ Thu | `total_amount_to_collect` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không suy từ `total_amount` | Trống |
| 18 | Phí Affiliate (Vui Khỏe) | `affiliate_fee_vuikhoe` | decimal(20,4) | `UNMAPPED` | Không tính | Trống |
| 19 | Chiết Khấu (Vui Khỏe) | `discount_vuikhoe` | decimal(20,4) | `UNMAPPED` | Không tính | Trống |
| 20 | % Chiết Khấu Vui Khỏe | `discount_percent_vuikhoe` | decimal(9,4) | `UNMAPPED` | Không tính | Trống |
| 21 | Phí Cố Định (TMĐT) | `fixed_platform_fee` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |
| 22 | Phí dịch vụ (TMĐT) | `service_platform_fee` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |
| 23 | Phí Giao Dịch (TMĐT) | `transaction_platform_fee` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |
| 24 | % Chi Phí Sàn TMĐT | `platform_cost_percent` | decimal(9,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |

### Lưu ý Shopee quan trọng

WP.6 dùng đúng các key phẳng do WP.5 normalizer đã persist: `recipientName`, `recipientPhone`, `recipientFullAddress`, `recipientState`, `recipientCity`, `recipientRegion`. PII này chỉ được hiển thị trong trang Result admin `manage_options`; không vào log, diagnostic, error payload, public REST hay frontend. Không hiển thị raw provider JSON.

OrderItem không bao giờ nhân số dòng canonical. Nếu một phiên bản contract sau map nhiều cột product textual, các giá trị source-backed được nối theo thứ tự OrderItem bằng ` | ` và giữ alignment; không cộng giá nếu contract không yêu cầu. V1 chưa map cột product nào nên các cột đó vẫn NULL.

## 3. Khóa đối chiếu và normalization V1

`normalized_order_code = trim(raw_order_code)`.

- Giữ nguyên hoa/thường: `order001` khác `ORDER001`.
- Giữ dấu gạch nối và khoảng trắng bên trong: `AB C` khác `ABC`.
- `ORDER001` và ` ORDER001 ` là cùng khóa.
- Không sửa typo, không fuzzy match, không dùng PII hay dữ liệu sản phẩm làm fallback.

Với mỗi normalized code:

1. Nếu Excel có hơn một occurrence hoặc PDF có hơn một occurrence → `DUPLICATE`.
2. Nếu mỗi phía có đúng một occurrence → `MATCHED`.
3. Chỉ có Excel → `PDF_NOT_FOUND`.
4. Chỉ có PDF → `EXCEL_NOT_FOUND`.
5. `PARSE_ERROR` dành cho record không thể tạo kết quả hợp lệ do lỗi parse.

Nếu các PDF của cùng code chỉ ra đúng một platform thì giữ platform đó; không có hoặc nhiều platform khác nhau thì `UNKNOWN`.

## 4. Provenance

Mỗi Master Order giữ tham chiếu gọn: file ID, loại nguồn Excel/PDF/Marketplace API, row/page, raw order code và với Marketplace là connection/shop/sync-run identity. Không chép toàn raw payload vào `source_refs`.

## 5. Export contract

- Filter: `ALL` hoặc một Matching Status.
- Sort: `created_at ASC`, sau đó ID `ASC`.
- Batch không có Master Order: conflict `BATCH_NOT_MATCHED`; không tạo file rỗng giả thành công.
- XLSX: sheet `Ket qua doi chieu`, header bold, freeze hàng đầu, autofilter, code dạng text.
- CSV: UTF-8 BOM, CRLF, quote/escape theo CSV. Giá trị bắt đầu bằng `=`, `+`, `-`, `@` phải thêm apostrophe để giảm formula injection.
- Convention filename tham chiếu: `ecomkit-vuikhoe-{8 ký tự cuối batch ID}-{YYYYMMDD}.{xlsx|csv}`. WordPress có thể thay loại ID, nhưng prefix, ngày và tính duy nhất phải được giữ hoặc version hóa rõ.

## 6. Test contract tối thiểu cho stage runtime

- Header chính xác 24 cột, đúng thứ tự.
- Một Order đúng một dòng; `NULL` thành blank.
- Unicode tiếng Việt và mã có số 0 đầu không biến dạng.
- CSV có BOM và chống formula injection.
- Duplicate precedence, case sensitivity và trim-only normalization.
- Export filter không chỉ xuất trang Result đang xem.
- Trường tài chính không có nguồn vẫn blank.

## 7. Hiệu chỉnh nguồn Excel production đã xác minh

Hợp đồng canonical vẫn có đúng 24 cột với thứ tự không đổi. Cột canonical số 3 vẫn là **Mã Đơn sàn** (`raw_order_code`) và là khóa đối chiếu marketplace.

- Nguồn Excel production là cột `Sàn & Mã Đơn`, không phải một cột literal tên `Mã đơn sàn`.
- Ô nguồn chứa nhãn sàn ở dòng có nghĩa đầu tiên và mã đơn marketplace ở dòng có nghĩa thứ hai. Ví dụ `Shopee` + `TEST-SHP-001` tạo `platform=SHOPEE` và `raw_order_code=TEST-SHP-001`; `Lazada` + `000123456789` giữ nguyên leading zero.
- `Mã đơn hàng eShop` **không bằng** `Mã đơn sàn`, không phải marketplace identity và không được dùng làm fallback.
- Với Shopee ở stage sau, invariant vẫn là `Mã đơn sàn = order_sn`.
- Dòng sản phẩm continuation không tạo thêm Order và không làm thay đổi sequence 24 cột; chúng được lưu dưới dạng OrderItem.

## 8. Phân loại nguồn canonical v1

Bảng duy nhất tại mục 2 là hợp đồng có thẩm quyền. Cột 1, 3 và 4 có nguồn `EXCEL` với provider fallback nêu rõ trong từng dòng; cột 5–9 là `SHOPEE_ORDER_DETAIL`; cột 2, 10, 11, 15, 18–20 là `UNMAPPED`; cột 12–14, 16, 17, 21–24 là `FUTURE_PAYMENT_ESCROW`. Mọi nguồn không khả dụng materialize thành NULL.

Shopee Order Detail raw fields như `total_amount`, shipping fee hoặc `escrow_amount` chỉ được giữ đúng nghĩa provider nếu thực sự xuất hiện. WP.5 không diễn giải chúng thành settlement, platform fee, affiliate fee, commission hay final receivable.

WP.5A không materialize export 24 cột. Nó chỉ làm rõ nguồn cột 1: Excel datetime có precision thực được lưu UTC; date-only giữ marker `DATE` để không giả vờ biết giờ. UI chuyển lại qua timezone WordPress. Nếu thiếu/không hợp lệ, giá trị canonical vẫn trống và reconciliation không được suy diễn `NOT_FOUND_IN_SHOPEE` khi chưa chạy provider window.

