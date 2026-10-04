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
| 2 | Mã đơn ESHOP | `eshop_order_code` | text | `EXCEL_AVAILABLE`: eShop nội bộ từ header `Mã đơn hàng eShop`, tách biệt mã sàn | Không có nguồn provider | Trống nếu không có field/header map hoặc legacy provenance được xác thực |
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

## 8. Phân loại nguồn canonical v2

Bảng tại mục 2 cố định nhãn và thứ tự. WP.6A mở nguồn cột 2 từ Excel. Snapshot đổi `v1` sang `v2`; snapshot cũ phải materialize lại. Nguồn thiếu hoặc không xác minh được luôn là NULL.

Shopee Order Detail raw fields như `total_amount`, shipping fee hoặc `escrow_amount` chỉ được giữ đúng nghĩa provider nếu thực sự xuất hiện. WP.5 không diễn giải chúng thành settlement, platform fee, affiliate fee, commission hay final receivable.

WP.5A không materialize export 24 cột. Nó chỉ làm rõ nguồn cột 1: Excel datetime có precision thực được lưu UTC; date-only giữ marker `DATE` để không giả vờ biết giờ. UI chuyển lại qua timezone WordPress. Nếu thiếu/không hợp lệ, giá trị canonical vẫn trống và reconciliation không được suy diễn `NOT_FOUND_IN_SHOPEE` khi chưa chạy provider window.

## 9. Ma trận nguồn WP.6A cho đủ 24 cột

`EXCEL_AVAILABLE` = 1; `SHOPEE_ORDER_DETAIL_AVAILABLE` = 5; đa nguồn Excel rồi Shopee = 3; `FUTURE_PAYMENT_ESCROW` = 9; `UNMAPPED_NO_SOURCE` = 6. Tổng 24. NULL được phép ở mọi cột khi nguồn thực thiếu; riêng mã đơn sàn là bắt buộc đối với Order hợp lệ đầu vào.

| # | Nhãn canonical | Nguồn hiện tại | Key chính xác | Fallback | Hành vi WP.6A / phụ thuộc tương lai |
| ---: | --- | --- | --- | --- | --- |
| 1 | Ngày Lên Đơn | EXCEL_THEN_SHOPEE_FALLBACK | `orders.order_date` UTC | `providerCreatedAt` khi Shopee MATCHED | Ngày địa phương; NULL nếu cả hai thiếu |
| 2 | Mã đơn ESHOP | EXCEL_AVAILABLE | `orders.eshop_order_code`; raw `cells[column_map['Mã đơn hàng eShop']]` | Chỉ raw `cells['4']` của Batch legacy đã xác thực | Text Excel độc lập với mã sàn; NULL nếu provenance không đủ; không có provider fallback |
| 3 | Mã đơn sàn | EXCEL_THEN_SHOPEE_FALLBACK | `orders.raw_order_code` | `rawOrderCode` khi Shopee MATCHED | Giữ leading zero, không dùng eShop |
| 4 | Kênh Bán Hàng | EXCEL_THEN_SHOPEE_FALLBACK | `orders.platform` | `provider.platform` khi Shopee MATCHED | SHOPEE/LAZADA khi có nguồn |
| 5 | Trạng Thái Đơn Hàng | SHOPEE_ORDER_DETAIL_AVAILABLE | `providerStatus` | Không | Chỉ Shopee MATCHED; Lazada NULL |
| 6 | Tên Khách Hàng | SHOPEE_ORDER_DETAIL_AVAILABLE | `recipientName` | Không | Chỉ Shopee MATCHED |
| 7 | SĐT | SHOPEE_ORDER_DETAIL_AVAILABLE | `recipientPhone` | Không | Không dùng buyer username |
| 8 | Địa Chỉ | SHOPEE_ORDER_DETAIL_AVAILABLE | `recipientFullAddress` | Không | Không tự ghép |
| 9 | Tỉnh/TP | SHOPEE_ORDER_DETAIL_AVAILABLE | `recipientState` | `recipientCity` rồi `recipientRegion` | Không suy từ địa chỉ tự do |
| 10 | Ngày Xuất VAT | UNMAPPED_NO_SOURCE | Không có header/field đã xác minh | Không | NULL; không dùng ngày đặt |
| 11 | Ghi Chú | UNMAPPED_NO_SOURCE | Không có header Excel đã xác minh; WP.5 không yêu cầu Shopee `note` | Không | NULL; cần duyệt ngữ nghĩa note trước khi map |
| 12 | Đã Thu Tiền | FUTURE_PAYMENT_ESCROW | Chưa có payment evidence | Không | NULL; COMPLETED không chứng minh đã thu |
| 13 | Trạng Thái Công Nợ | FUTURE_PAYMENT_ESCROW | Chưa có receivable evidence | Không | NULL; không suy từ order/matching status |
| 14 | Chênh lệch | FUTURE_PAYMENT_ESCROW | Chưa có đủ toán hạng/công thức duyệt | Không | NULL |
| 15 | Giá SP (VAT 8%) | UNMAPPED_NO_SOURCE | OrderItem có SKU/name/quantity; `price` không được parser điền và không phải giá VAT 8% | Không | NULL; không tự nhân 1.08 |
| 16 | % Tổng Chi Phí | FUTURE_PAYMENT_ESCROW | Chưa có fee breakdown và công thức | Không | NULL |
| 17 | Tổng Tiền Sẽ Thu | FUTURE_PAYMENT_ESCROW | Seller receivable chưa có | Không; `totalAmount` là gross order amount | NULL đến khi có escrow/payout mapping |
| 18 | Phí Affiliate (Vui Khỏe) | UNMAPPED_NO_SOURCE | Không có internal affiliate source đã xác minh | Không | NULL |
| 19 | Chiết Khấu (Vui Khỏe) | UNMAPPED_NO_SOURCE | Không có internal discount source đã xác minh | Không | NULL; không dùng Shopee promotion |
| 20 | % Chiết Khấu Vui Khỏe | UNMAPPED_NO_SOURCE | Không có internal discount rate đã xác minh | Không | NULL |
| 21 | Phí Cố Định (TMĐT) | FUTURE_PAYMENT_ESCROW | Chưa có fixed fee evidence | Không | NULL |
| 22 | Phí dịch vụ (TMĐT) | FUTURE_PAYMENT_ESCROW | Chưa có service fee evidence | Không | NULL |
| 23 | Phí Giao Dịch (TMĐT) | FUTURE_PAYMENT_ESCROW | Chưa có transaction fee evidence | Không | NULL |
| 24 | % Chi Phí Sàn TMĐT | FUTURE_PAYMENT_ESCROW | Chưa có fee breakdown và công thức | Không | NULL |

Nguồn Excel persist: `orders.order_date`, `platform`, `raw_order_code`, `eshop_order_code` (từ import mới), `raw_source_metadata.cells`, `column_map` (từ import mới), `source_refs` (sheet/row) và OrderItems `sku`, `product_name`, `quantity`. Parser đọc cell theo chỉ số **một-based**: Excel D là `cells['4']`. Import mới tìm header bằng cơ chế hiện hữu (không giả định row 1/3), lưu `header_row` trong Batch metadata và `column_map` gồm ít nhất sáu header production trong Order raw metadata. Continuation item không ghi đè eShop identity của Order.

Workbook production đã được xác minh: sheet `DANH SÁCH ĐƠN HÀNG`, row 1 title, row 2 blank, row 3 header `STT | Sàn & Mã Đơn | Ngày đặt | Mã đơn hàng eShop | Mã hàng hóa | Tên hàng hóa | Số lg`. Mã eShop có dạng `ĐH...`, khác hẳn marketplace order ID. Với Batch cũ không có header map, chỉ dùng `cells['4']` khi **đồng thời** có parser `wp5a-v1`, metadata header row 3/date column 3/8 Orders/18 Items, source Excel, sheet đúng, row Order ≥4, đúng bảy raw cells không rỗng tại các vị trí nghiệp vụ và combined identity khớp cell B. Không dùng filename làm chứng cứ. Thiếu bất cứ điều kiện nào thì NULL; không chuyển sang mã sàn, Shopee `order_sn`, STT hay SKU.

Nguồn Shopee WP.5 persist trong `provider_normalized_data`: `providerStatus`, `providerCreatedAt`, `recipientName`, `recipientPhone`, `recipientFullAddress`, `recipientState`, `recipientCity`, `recipientRegion`, `totalAmount`, `estimatedShippingFee`, `actualShippingFee`, `escrowAmount` và danh sách items. `provider_raw_data` có thể chứa `note` hoặc field khác tùy response nhưng WP.5 không yêu cầu note trong detail, và chưa có quyết định ngữ nghĩa canonical cho nó. Các số tiền Order Detail này không tương đương phí sàn hay seller settlement.

WP.6B lưu riêng `payment_raw_data`/`payment_normalized_data` từ `POST /api/v2/payment/get_escrow_detail`, chỉ cho một Shopee `marketplace_order_id` đã MATCHED. Các nguồn ứng viên cho WP.6C (chưa được duyệt để materialize): `commission_fee` → Phí Cố Định (TMĐT) (độ tin cậy thấp; cần phê duyệt), `service_fee` → Phí dịch vụ (TMĐT) (trung bình), `seller_transaction_fee` → Phí Giao Dịch (TMĐT) (trung bình), `escrow_amount`/`escrow_amount_after_adjustment` → Tổng Tiền Sẽ Thu (cần live state và phê duyệt). `% Chi Phí Sàn TMĐT`, `% Tổng Chi Phí`, Chênh lệch chưa có công thức và toán hạng duyệt. Đã Thu Tiền/Trạng Thái Công Nợ cần bằng chứng thu tiền, giải ngân và quy tắc kế toán riêng; không suy từ COMPLETED. `order_ams_commission_fee` là Shopee Affiliate, không phải Phí Affiliate (Vui Khỏe). Toàn bộ canonical tài chính tiếp tục NULL trong v2.

