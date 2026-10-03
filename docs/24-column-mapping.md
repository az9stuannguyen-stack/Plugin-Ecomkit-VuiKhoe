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
| 1 | Ngày Lên Đơn | `order_date` | date/datetime | Chưa map | `create_time` → ISO → date | Trống nếu thiếu |
| 2 | Mã đơn ESHOP | `eshop_order_code` | text | Chưa map | Chưa map | Trống |
| 3 | Mã đơn sàn | `raw_order_code` | text | Mã nguồn primary | `order_sn` chính xác | Bắt buộc với record hợp lệ |
| 4 | Kênh Bán Hàng | `sales_channel` | text | Chưa map | Hằng `SHOPEE` | Trống nếu nguồn chưa xác định |
| 5 | Trạng Thái Đơn Hàng | `order_status` | text | Chưa map | `order_status` nguyên bản, nếu không rỗng | Trống nếu thiếu |
| 6 | Tên Khách Hàng | `customer_name` | text | Chưa map | Chưa materialize | Trống |
| 7 | SĐT | `phone` | text | Chưa map | Chưa materialize | Trống |
| 8 | Địa Chỉ | `address` | text | Chưa map | Chưa materialize | Trống |
| 9 | Tỉnh/TP | `province_city` | text | Chưa map | Chưa materialize | Trống |
| 10 | Ngày Xuất VAT | `vat_issued_date` | date/datetime | Chưa map | Chưa map | Trống |
| 11 | Ghi Chú | `note` | text | Chưa map | Chưa materialize | Trống |
| 12 | Đã Thu Tiền | `amount_collected` | decimal(20,4) | Chưa map | Không suy từ COD/total | Trống |
| 13 | Trạng Thái Công Nợ | `receivable_status` | text | Chưa map | Chưa map | Trống |
| 14 | Chênh lệch | `difference_amount` | decimal(20,4) | Chưa map | Không tính | Trống |
| 15 | Giá SP (VAT 8%) | `product_price_vat_8` | decimal(20,4) | Chưa map | Không tính từ item price | Trống |
| 16 | % Tổng Chi Phí | `total_cost_percent` | decimal(9,4) | Chưa map | Không tính | Trống |
| 17 | Tổng Tiền Sẽ Thu | `total_amount_to_collect` | decimal(20,4) | Chưa map | Không suy từ `total_amount` | Trống |
| 18 | Phí Affiliate (Vui Khỏe) | `affiliate_fee_vuikhoe` | decimal(20,4) | Chưa map | Không tính | Trống |
| 19 | Chiết Khấu (Vui Khỏe) | `discount_vuikhoe` | decimal(20,4) | Chưa map | Không tính | Trống |
| 20 | % Chiết Khấu Vui Khỏe | `discount_percent_vuikhoe` | decimal(9,4) | Chưa map | Không tính | Trống |
| 21 | Phí Cố Định (TMĐT) | `fixed_platform_fee` | decimal(20,4) | Chưa map | Không tính | Trống |
| 22 | Phí dịch vụ (TMĐT) | `service_platform_fee` | decimal(20,4) | Chưa map | Không tính | Trống |
| 23 | Phí Giao Dịch (TMĐT) | `transaction_platform_fee` | decimal(20,4) | Chưa map | Không tính | Trống |
| 24 | % Chi Phí Sàn TMĐT | `platform_cost_percent` | decimal(9,4) | Chưa map | Không tính | Trống |

### Lưu ý Shopee quan trọng

Shopee normalizer tham chiếu giữ `recipient_address`, `message_to_seller`, `total_amount`, `cod`, payment/shipping và buyer username trong metadata. Tuy nhiên materializer hiện hành tìm các key generic khác (`shippingAddress`, `buyerNote`). Vì vậy không được tuyên bố các trường SĐT, Tỉnh/TP hoặc Ghi Chú đã map cho Shopee. WP runtime chỉ được bổ sung mapping sau khi có quyết định field-source, PII policy và fixture/test xác nhận.

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

## 8. Ma trận nguồn khả dụng tại WP.5

Các nhãn dưới đây mô tả **nguồn đã có**, không đồng nghĩa WP.5 đã materialize kết quả export. Hợp đồng vẫn đúng 24 cột và giữ nguyên thứ tự.

| # | Header export | Trạng thái nguồn WP.5 | Ghi chú |
| ---: | --- | --- | --- |
| 1 | Ngày Lên Đơn | `EXCEL` / `SHOPEE_ORDER_DETAIL` | Excel `Ngày đặt`; provider `create_time` được giữ riêng |
| 2 | Mã đơn ESHOP | `UNMAPPED` | Có thể tồn tại trong raw Excel nhưng chưa materialize |
| 3 | Mã đơn sàn | `EXCEL` / `SHOPEE_ORDER_DETAIL` | Exact `marketplace_order_id === order_sn` |
| 4 | Kênh Bán Hàng | `EXCEL` / `SHOPEE_ORDER_DETAIL` | Platform source-backed là SHOPEE; chưa export |
| 5 | Trạng Thái Đơn Hàng | `SHOPEE_ORDER_DETAIL` | Giữ raw `order_status` khi có |
| 6 | Tên Khách Hàng | `SHOPEE_ORDER_DETAIL` | Recipient name trong normalized provider data; PII |
| 7 | SĐT | `SHOPEE_ORDER_DETAIL` | Recipient phone trong normalized provider data; PII |
| 8 | Địa Chỉ | `SHOPEE_ORDER_DETAIL` | Chỉ full address/component provider cung cấp; không tự ghép |
| 9 | Tỉnh/TP | `SHOPEE_ORDER_DETAIL` | Component provider-backed; không suy đoán |
| 10 | Ngày Xuất VAT | `UNMAPPED` | Chưa có nguồn được duyệt |
| 11 | Ghi Chú | `UNMAPPED` | WP.5 chưa materialize note |
| 12 | Đã Thu Tiền | `FUTURE_PAYMENT_ESCROW` | Không suy từ COD hoặc total amount |
| 13 | Trạng Thái Công Nợ | `FUTURE_PAYMENT_ESCROW` | Chưa có payment evidence |
| 14 | Chênh lệch | `FUTURE_PAYMENT_ESCROW` | Không tính tại WP.5 |
| 15 | Giá SP (VAT 8%) | `UNMAPPED` | Item price không được đổi nghĩa thành VAT price |
| 16 | % Tổng Chi Phí | `FUTURE_PAYMENT_ESCROW` | Không tính tại WP.5 |
| 17 | Tổng Tiền Sẽ Thu | `FUTURE_PAYMENT_ESCROW` | `total_amount` không phải seller settlement |
| 18 | Phí Affiliate (Vui Khỏe) | `UNMAPPED` | Không có nguồn provider được duyệt |
| 19 | Chiết Khấu (Vui Khỏe) | `UNMAPPED` | Không có công thức được duyệt |
| 20 | % Chiết Khấu Vui Khỏe | `UNMAPPED` | Không có công thức được duyệt |
| 21 | Phí Cố Định (TMĐT) | `FUTURE_PAYMENT_ESCROW` | Không tính từ Order Detail |
| 22 | Phí dịch vụ (TMĐT) | `FUTURE_PAYMENT_ESCROW` | Không tính từ Order Detail |
| 23 | Phí Giao Dịch (TMĐT) | `FUTURE_PAYMENT_ESCROW` | Không tính từ Order Detail |
| 24 | % Chi Phí Sàn TMĐT | `FUTURE_PAYMENT_ESCROW` | Không tính tại WP.5 |

Shopee Order Detail raw fields như `total_amount`, shipping fee hoặc `escrow_amount` chỉ được giữ đúng nghĩa provider nếu thực sự xuất hiện. WP.5 không diễn giải chúng thành settlement, platform fee, affiliate fee, commission hay final receivable.

