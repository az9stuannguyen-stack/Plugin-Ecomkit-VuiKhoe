# Hợp đồng canonical 24 cột

> WP.6H.2 / canonical v6: Shopee **Phí dịch vụ (TMĐT)** = `payment_normalized_data.serviceFee` + `payment_normalized_data.shippingSellerProtectionFeeAmount` − `orders.discount_vuikhoe`, dùng exact decimal math. Với Payment snapshot cũ thiếu normalized PiShip, chỉ đọc `payment_raw_data.order_income.shipping_seller_protection_fee_amount` khi raw `order_sn` khớp chính xác; không refetch. Mọi toán hạng bắt buộc, NULL không thành 0. Kết quả âm là `SERVICE_FEE_NEGATIVE_REVIEW` và canonical NULL. Provider `serviceFee` giữ nguyên; metadata phép tính nằm trong canonical JSON, không thêm cột. Công thức tỷ lệ và legacy fallback dùng phí đã phân loại lại, Escrow vẫn thắng. Snapshot v1–v5 stale. Bản Excel thiếu chiết khấu nội bộ vẫn nhập và hoàn tất với source gap; công cụ kỹ thuật không thuộc luồng thường. Các dòng v5 bên dưới là lịch sử.

> WP.6H.3 audit: fixture đơn `260924TSBR7FC0` xác nhận phép tính từ giá/chiết khấu nội bộ và các phí Payment giả lập; chưa có Payment/Order Detail snapshot thực của chính đơn này trong workspace để xác minh trường API Voucher Xtra. `voucher_from_shopee`, `voucher_from_seller` và discount theo item không được tự động đồng nhất với Voucher Xtra. Trạng thái: `PROVIDER_VOUCHER_XTRA_SOURCE_NOT_PROVEN`. `order_selling_price` là giá bán theo provider, chưa chứng minh tương đương chính xác Giá SP (VAT 8%) nội bộ. Vì vậy không bật fallback provider cho hai cột này, canonical giữ v6.

## WP.6H — Exact Financial Formula Engine (hiện hành)

Canonical `v5` giữ đúng 24 scalar columns. Công thức legacy đã duyệt hoạt động tự động khi tất cả toán hạng của **từng công thức** có nguồn: `% Tổng Chi Phí=(L+M+N+I+J)/F`, `% Chiết Khấu Vui Khỏe=(I+J)/F`, `% Chi Phí Sàn TMĐT=(L+M+N)/F`; tỷ lệ lưu dưới dạng ratio, không nhân 100. F là Giá SP VAT 8%, I/J là hai field nội bộ Excel, L/M/N là ba phí Payment Shopee đã duyệt. NULL không phải 0; riêng F=0 làm ba tỷ lệ NULL với trạng thái `ZERO_DIVISOR`. Thiếu I/J không cản tỷ lệ phí sàn nếu F/L/M/N đầy đủ.

`Tổng Tiền Sẽ Thu` ưu tiên `escrowAmountAfterAdjustment`, sau đó `escrowAmount`, rồi mới fallback chính xác `F-(L+M+N+I+J)` khi đủ mọi toán hạng. `Chênh lệch` giữ NULL vì workbook legacy có `#REF!`. Không lấy Shopee Affiliate thay cho I; không chế phí Lazada.

Engine `Ecomkit_Vuikhoe_Exact_Financial_Math` tính cộng/trừ/so sánh/chia bằng chuỗi số nguyên, không PHP float, BCMath hay GMP. Snapshot v5 lưu trong `canonical_data` ba nhánh: `columns` (24 scalar values), `rational` (tử số/mẫu số chính xác, decimal prefix tối đa 18 chữ số và cờ `decimal_exact`), `formula_state` (READY/MISSING_OPERANDS/ZERO_DIVISOR + field dependency). Với tỷ lệ vô hạn, decimal prefix là **truncated, không phải giá trị phân số chính xác**; tử số/mẫu số là nguồn chân lý. UI tính phần trăm trực tiếp từ phân số ×100 bằng cùng engine, cắt tối đa 12 chữ số sau dấu thập phân và thêm `…%` nếu còn dư; không round hoặc thay đổi snapshot. Money formatter chỉ dùng cho cột tiền. Snapshot v1–v4 stale, cần rematerialize; không migration DB. Các mục stage cũ bên dưới là lịch sử.

> WP.6G xác lập năm nguồn Excel nội bộ trong canonical v4; các ánh xạ trực tiếp đó tiếp tục có hiệu lực trong v5. Phần ghi chú v4 bên dưới là lịch sử; phần WP.6H phía trên là quy tắc công thức hiện hành.

## WP.6G — nguồn nội bộ Excel tùy chọn

File upload hiện tại có thể giữ nguyên bảy header production; thiếu năm header sau **không phải lỗi**. Parser dùng đúng hàng tiêu đề đã khám phá, không dùng vị trí cột vật lý. Giá trị chỉ lấy ở hàng Order cha; hàng item tiếp nối không ghi đè.

| Canonical # | Nhãn/header Excel chính xác | Structured Order field | Thiếu/không hợp lệ | Provider fallback |
| ---: | --- | --- | --- | --- |
| 10 | `Ngày Xuất VAT` | `vat_issued_date` | `NULL`; ngày không hợp lệ tạo warning | Không |
| 11 | `Ghi Chú` | `note` | `NULL` | Không |
| 15 | `Giá SP (VAT 8%)` | `product_price_vat_8` | `NULL`; tiền không hợp lệ tạo warning | Không |
| 18 | `Phí Affiliate (Vui Khỏe)` | `affiliate_fee_vuikhoe` | `NULL`; tiền không hợp lệ tạo warning | Không; đặc biệt không dùng `affiliateCommissionFee` |
| 19 | `Chiết Khấu (Vui Khỏe)` | `discount_vuikhoe` | `NULL`; tiền không hợp lệ tạo warning | Không; không dùng voucher Shopee |

Tiền lưu dạng decimal không có dấu phân tách hàng nghìn; ô trống là `NULL`, số 0 nguồn là `0`. Numeric Excel kiểu double chỉ được nhận nếu là số nguyên chính xác trong ngưỡng an toàn; số lẻ cần nhập bằng text decimal tối đa bốn chữ số phần thập phân để tránh làm tròn nhị phân. Ngày Xuất VAT là **ngày lịch** (`YYYY-MM-DD 00:00:00` trong DB), không chuyển timezone từ ngày đặt. Ghi Chú là text và được escape khi hiển thị. Raw cells, header map, sheet/row provenance giữ nguyên. Các structured field này có thể được nguồn nội bộ khác điền sau này mà không cần đổi physical Excel column.

Snapshot `v1`/`v2`/`v3` trở thành stale, materialize lại từ Orders đã lưu để tạo `v4`. Batch cũ thiếu structured internal values vẫn `NULL`; không suy diễn từ raw Shopee/Payment. Tỷ lệ `% Tổng Chi Phí`, `% Chiết Khấu Vui Khỏe`, `% Chi Phí Sàn TMĐT` và fallback legacy của `Tổng Tiền Sẽ Thu` vẫn **gated** vì chưa có engine decimal chính xác/chính sách precision được duyệt. Escrow Shopee vẫn ưu tiên; `Chênh lệch` vẫn NULL do `#REF!` ở workbook cũ. Vẫn đúng 24 cột, một Order một Result row; WP.7 chưa bắt đầu.

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
| 10 | Ngày Xuất VAT | `vat_issued_date` | date | `INTERNAL_EXCEL`: header tùy chọn `Ngày Xuất VAT`, lưu `orders.vat_issued_date` | Không fallback provider | Trống nếu thiếu/không hợp lệ |
| 11 | Ghi Chú | `note` | text | `INTERNAL_EXCEL`: header tùy chọn `Ghi Chú`, lưu `orders.note` | Không fallback provider | Trống nếu thiếu |
| 12 | Đã Thu Tiền | `amount_collected` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không suy từ COD/total | Trống |
| 13 | Trạng Thái Công Nợ | `receivable_status` | text | `FUTURE_PAYMENT_ESCROW` | Chưa có payment evidence | Trống |
| 14 | Chênh lệch | `difference_amount` | decimal(20,4) | `FUTURE_PAYMENT_ESCROW` | Không tính | Trống |
| 15 | Giá SP (VAT 8%) | `product_price_vat_8` | decimal(20,4) | `INTERNAL_EXCEL`: header tùy chọn cùng tên, lưu `orders.product_price_vat_8` | Không tính từ giá Shopee/Payment | Trống nếu thiếu/không hợp lệ |
| 16 | % Tổng Chi Phí | `total_cost_percent` | ratio string + exact rational metadata | `(L+M+N+I+J)/F` khi đủ nguồn | Không dùng float | Trống nếu thiếu toán hạng hoặc F=0 |
| 17 | Tổng Tiền Sẽ Thu | `total_amount_to_collect` | decimal string/source value | `escrowAmountAfterAdjustment` > `escrowAmount` > `F-(L+M+N+I+J)` khi đủ nguồn | Không suy từ buyerTotalAmount | NULL nếu cả hai Escrow và toàn bộ công thức thiếu |
| 18 | Phí Affiliate (Vui Khỏe) | `affiliate_fee_vuikhoe` | decimal(20,4) | `INTERNAL_EXCEL`: header tùy chọn cùng tên, lưu `orders.affiliate_fee_vuikhoe` | Không dùng Shopee Affiliate | Trống nếu thiếu/không hợp lệ |
| 19 | Chiết Khấu (Vui Khỏe) | `discount_vuikhoe` | decimal(20,4) | `INTERNAL_EXCEL`: header tùy chọn cùng tên, lưu `orders.discount_vuikhoe` | Không dùng Shopee voucher/discount | Trống nếu thiếu/không hợp lệ |
| 20 | % Chiết Khấu Vui Khỏe | `discount_percent_vuikhoe` | ratio string + exact rational metadata | `(I+J)/F` khi đủ nguồn | Không dùng Shopee Affiliate | Trống nếu thiếu toán hạng hoặc F=0 |
| 21 | Phí Cố Định (TMĐT) | `fixed_platform_fee` | decimal(20,4) | `SHOPEE_PAYMENT_ESCROW`: `commissionFee` | Trực tiếp, không tính | NULL nếu thiếu |
| 22 | Phí dịch vụ (TMĐT) | `service_platform_fee` | decimal(20,4) | Shopee Payment + Excel: `serviceFee + shippingSellerProtectionFeeAmount − discount_vuikhoe` | Exact v6 reclassification | NULL nếu thiếu nguồn/âm cần review |
| 23 | Phí Giao Dịch (TMĐT) | `transaction_platform_fee` | decimal(20,4) | `SHOPEE_PAYMENT_ESCROW`: `sellerTransactionFee` | Trực tiếp, không tính | NULL nếu thiếu |
| 24 | % Chi Phí Sàn TMĐT | `platform_cost_percent` | ratio string + exact rational metadata | `(L+M+N)/F` khi đủ nguồn | Không chế phí Lazada | Trống nếu thiếu toán hạng hoặc F=0 |

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

## 8. Phân loại nguồn canonical v3

Bảng tại mục 2 cố định nhãn và thứ tự. WP.6A mở nguồn cột 2 từ Excel; WP.6C mở bốn cột tài chính từ Payment đã lưu. Snapshot `v1`/`v2` phải materialize lại thành `v3`. Nguồn thiếu hoặc không xác minh được luôn là NULL.

Shopee Order Detail raw fields như `total_amount`, shipping fee hoặc `escrow_amount` chỉ được giữ đúng nghĩa provider nếu thực sự xuất hiện. WP.5 không diễn giải chúng thành settlement, platform fee, affiliate fee, commission hay final receivable.

WP.5A không materialize export 24 cột. Nó chỉ làm rõ nguồn cột 1: Excel datetime có precision thực được lưu UTC; date-only giữ marker `DATE` để không giả vờ biết giờ. UI chuyển lại qua timezone WordPress. Nếu thiếu/không hợp lệ, giá trị canonical vẫn trống và reconciliation không được suy diễn `NOT_FOUND_IN_SHOPEE` khi chưa chạy provider window.

## 9. Ma trận nguồn WP.6A cho đủ 24 cột

Sau audit WP.6F: `EXCEL_AVAILABLE` = 1; `SHOPEE_ORDER_DETAIL_AVAILABLE` = 5; đa nguồn Excel rồi Shopee = 3; `SHOPEE_PAYMENT_ESCROW` = 4; `FUTURE_PAYMENT_ESCROW` = 2; `UNMAPPED_NO_SOURCE` = 5; `DERIVED_LEGACY_FORMULA_GATED` = 3; `UNRESOLVED_BROKEN_LEGACY_REFERENCE` = 1. Tổng 24. Source class `DERIVED_LEGACY_FORMULA_GATED` là hợp đồng, **chưa** là giá trị materialized. NULL được phép ở mọi cột khi nguồn thực thiếu; riêng mã đơn sàn là bắt buộc đối với Order hợp lệ đầu vào.

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
| 14 | Chênh lệch | UNRESOLVED_BROKEN_LEGACY_REFERENCE | Workbook cũ có `#REF!`, không có công thức tin cậy | Không | NULL; chờ định nghĩa kế toán độc lập |
| 15 | Giá SP (VAT 8%) | UNMAPPED_NO_SOURCE | OrderItem có SKU/name/quantity; `price` không được parser điền và không phải giá VAT 8% | Không | NULL; không tự nhân 1.08 |
| 16 | % Tổng Chi Phí | DERIVED_LEGACY_FORMULA_GATED | `(L+M+N+I+J)/F` đã xác minh | Không kích hoạt khi thiếu F/I/J | NULL hiện tại; lưu ratio khi có đủ nguồn và decimal engine |
| 17 | Tổng Tiền Sẽ Thu | SHOPEE_PAYMENT_ESCROW | `payment_normalized_data.escrowAmountAfterAdjustment` | `escrowAmount`; legacy `F-(L+M+N+I+J)` chỉ khi đủ nguồn | Escrow giữ ưu tiên; fallback legacy chưa kích hoạt vì thiếu F/I/J |
| 18 | Phí Affiliate (Vui Khỏe) | UNMAPPED_NO_SOURCE | Không có internal affiliate source đã xác minh | Không | NULL |
| 19 | Chiết Khấu (Vui Khỏe) | UNMAPPED_NO_SOURCE | Không có internal discount source đã xác minh | Không | NULL; không dùng Shopee promotion |
| 20 | % Chiết Khấu Vui Khỏe | DERIVED_LEGACY_FORMULA_GATED | `(I+J)/F` đã xác minh | Không kích hoạt khi thiếu F/I/J | NULL hiện tại; không rút gọn thành `J/F` |
| 21 | Phí Cố Định (TMĐT) | SHOPEE_PAYMENT_ESCROW | `payment_normalized_data.commissionFee` | Không | Trực tiếp; thiếu = NULL, 0 = 0 |
| 22 | Phí dịch vụ (TMĐT) | SHOPEE_PAYMENT_PLUS_INTERNAL_RECLASSIFICATION | `serviceFee`, `shippingSellerProtectionFeeAmount`, `orders.discount_vuikhoe` | Raw PiShip đã lưu nếu normalized key thiếu và order_sn khớp | Exact v6; thiếu = NULL, 0 = 0, âm = review |
| 23 | Phí Giao Dịch (TMĐT) | SHOPEE_PAYMENT_ESCROW | `payment_normalized_data.sellerTransactionFee` | Không | Trực tiếp; thiếu = NULL, 0 = 0 |
| 24 | % Chi Phí Sàn TMĐT | DERIVED_LEGACY_FORMULA_GATED | `(L+M+N)/F` đã xác minh | Không kích hoạt khi thiếu F hoặc bất kỳ phí nào | NULL hiện tại; không thay phí thiếu bằng 0 |

Nguồn Excel persist: `orders.order_date`, `platform`, `raw_order_code`, `eshop_order_code` (từ import mới), `raw_source_metadata.cells`, `column_map` (từ import mới), `source_refs` (sheet/row) và OrderItems `sku`, `product_name`, `quantity`. Parser đọc cell theo chỉ số **một-based**: Excel D là `cells['4']`. Import mới tìm header bằng cơ chế hiện hữu (không giả định row 1/3), lưu `header_row` trong Batch metadata và `column_map` gồm ít nhất sáu header production trong Order raw metadata. Continuation item không ghi đè eShop identity của Order.

Workbook production đã được xác minh: sheet `DANH SÁCH ĐƠN HÀNG`, row 1 title, row 2 blank, row 3 header `STT | Sàn & Mã Đơn | Ngày đặt | Mã đơn hàng eShop | Mã hàng hóa | Tên hàng hóa | Số lg`. Mã eShop có dạng `ĐH...`, khác hẳn marketplace order ID. Với Batch cũ không có header map, chỉ dùng `cells['4']` khi **đồng thời** có parser `wp5a-v1`, metadata header row 3/date column 3/8 Orders/18 Items, source Excel, sheet đúng, row Order ≥4, đúng bảy raw cells không rỗng tại các vị trí nghiệp vụ và combined identity khớp cell B. Không dùng filename làm chứng cứ. Thiếu bất cứ điều kiện nào thì NULL; không chuyển sang mã sàn, Shopee `order_sn`, STT hay SKU.

Nguồn Shopee WP.5 persist trong `provider_normalized_data`: `providerStatus`, `providerCreatedAt`, `recipientName`, `recipientPhone`, `recipientFullAddress`, `recipientState`, `recipientCity`, `recipientRegion`, `totalAmount`, `estimatedShippingFee`, `actualShippingFee`, `escrowAmount` và danh sách items. `provider_raw_data` có thể chứa `note` hoặc field khác tùy response nhưng WP.5 không yêu cầu note trong detail, và chưa có quyết định ngữ nghĩa canonical cho nó. Các số tiền Order Detail này không tương đương phí sàn hay seller settlement.

WP.6B lưu riêng `payment_raw_data`/`payment_normalized_data` từ `POST /api/v2/payment/get_escrow_detail` cho Shopee `marketplace_order_id` đã MATCHED. WP.6C phê duyệt bốn ánh xạ trực tiếp trong bảng trên. WP.6F xác minh công thức legacy của các tỷ lệ nhưng chưa có đủ nguồn để kích hoạt; Chênh lệch vẫn chưa có công thức tin cậy. Đã Thu Tiền/Trạng Thái Công Nợ cần bằng chứng giải ngân và quy tắc kế toán riêng; không suy từ COMPLETED. `order_ams_commission_fee` là Shopee Affiliate, không phải Phí Affiliate (Vui Khỏe).

WP.6C đã phê duyệt bốn ánh xạ trực tiếp ghi trong bảng. Canonical v3 chỉ đọc `payment_normalized_data` khi `marketplaceOrderId` khớp `orders.marketplace_order_id` của Shopee MATCHED; fingerprint bao gồm Payment normalized evidence. Field thiếu là NULL, số 0 từ provider được giữ nguyên. Các phần trăm, Đã Thu Tiền, Trạng Thái Công Nợ, Chênh lệch, Phí Affiliate và chiết khấu nội bộ vẫn NULL; shipping và buyerTotalAmount chỉ giữ ở Payment snapshot.

## WP.6F — Legacy Financial Formula Contract và audit Giá SP

Workbook legacy là chứng cứ công thức, **không** chứng minh rằng workbook upload production hiện tại có đủ các toán hạng. Các cột legacy: A `Ngày Lên Đơn`, B `Mã đơn ESHOP`, C `Mã đơn sàn`, D `Kênh Bán Hàng`, E `Chênh lệch`, F `Giá SP (VAT 8%)`, G `% Tổng Chi Phí`, H `Tổng Tiền Sẽ Thu`, I `Phí Affiliate (Vui Khỏe)`, J `Chiết Khấu (Vui Khỏe)`, K `% Chiết Khấu Vui Khỏe`, L `Phí Cố Định (TMĐT)`, M `Phí dịch vụ (TMĐT)`, N `Phí Giao Dịch (TMĐT)`, O `% Chi Phí Sàn TMĐT`. F là **ô đầu vào độc lập**, không suy ngược từ H và phí.

| Canonical | Công thức workbook đã xác minh | Quy tắc kích hoạt |
| --- | --- | --- |
| `% Tổng Chi Phí` | `=((L3+M3+N3+I3+J3)/F3*100%)` = `(L+M+N+I+J)/F` | F khác 0; cả năm phí/chiết khấu có nguồn và khác NULL |
| `Tổng Tiền Sẽ Thu` | `=F3-(L3+M3+N3+I3+J3)` | Chỉ fallback sau `escrowAmountAfterAdjustment`, `escrowAmount`; F và cả năm toán hạng khác NULL |
| `% Chiết Khấu Vui Khỏe` | K3 `=((I3+J3)/F3)*100%` = `(I+J)/F` | F khác 0; I và J khác NULL. K4–K5 là giá trị lưu `0.0393`, nhất quán với công thức vì I=0; không suy thành `J/F` toàn cục |
| `% Chi Phí Sàn TMĐT` | `=((L3+M3+N3)/F3)*100%` = `(L+M+N)/F` | F khác 0; L, M, N khác NULL |
| `Chênh lệch` | `#REF!` | **Không có công thức được duyệt**; luôn NULL |

Quy ước percentage dự kiến là **ratio**: `0.0393` biểu thị `3.93%`; Excel `*100%` không phải nhân thêm 100 vào giá trị lưu. Không làm tròn canonical hoặc dùng binary float để tính tiền/tỷ lệ. Hợp đồng toán hạng trong `Ecomkit_Vuikhoe_Canonical_Columns::legacy_formula_contract()` chỉ là metadata; `legacy_operands_ready()` từ chối NULL, float và mẫu thập phân không chính xác, từ chối mẫu số 0. Chưa có decimal arithmetic engine/nguồn đầu vào hoàn chỉnh nên **không có công thức nào được thực thi trong canonical v3**. Khi kích hoạt sau này phải dùng decimal string-safe arithmetic, quyết định biểu diễn tỷ lệ không hữu hạn, bump canonical version, rồi rematerialize; không làm tròn âm thầm.

### Source audit — `Giá SP (VAT 8%)`

| Ứng viên; key/header | Ngữ nghĩa; cấp | Gross/net, VAT, discount | Phạm vi | Độ tin cậy / map F? |
| --- | --- | --- | --- | --- |
| Excel production `Sàn & Mã Đơn`, `Ngày đặt`, `Mã đơn hàng eShop`, `Mã hàng hóa`, `Tên hàng hóa`, `Số lg` | Danh tính/ngày/sản phẩm/số lượng; không có giá | Không xác định | Shopee + Lazada | Cao về sự **vắng mặt**; **NO** |
| `orders.product_price_vat_8` | Cột DB nullable nhưng importer không ghi; không phải bằng chứng nguồn | Không xác định | Cả hai | Cao về trạng thái NULL; **NO** |
| `order_items.price` | Cột item nullable, parser `build_item()` và import không ghi | Không chứng minh VAT 8%, chưa có tổng Order | Cả hai | Cao về trạng thái chưa có dữ liệu; **NO** |
| `raw_source_metadata.cells` + `column_map`; `source_refs`; `raw_product_metadata.cells` | Raw Excel/header map/provenance, production hiện có 7 header, không có F | Không xác định | Cả hai | Cao về cấu trúc; **NO**. Không đoán cột vật lý |
| Shopee Order Detail `items[].originalPrice`/`discountedPrice`, `totalAmount`, raw `model_original_price`/`model_discounted_price`/`total_amount` | Giá item hoặc gross order provider | VAT 8% nội bộ chưa xác minh; discount khác nhau | Chỉ Shopee | Cao về key, thấp về tương đương F; **NO** |
| Payment `orderOriginalPrice`, `orderSellingPrice`, `orderDiscountedPrice`, `buyerTotalAmount` (raw `order_income.*`) | Giá và buyer total theo provider, có thể chịu phí ship/voucher/discount | Không chứng minh VAT 8% nội bộ | Chỉ Shopee | Cao về key, thấp về tương đương F; **NO** |
| Internal metadata khác (`orders.*`, Batch) | Không có bảng giá Vui Khỏe đã xác minh | Không xác định | Cả hai | Cao về thiếu nguồn; **NO** |
| Legacy workbook F `Giá SP (VAT 8%)` | Đầu vào order-level của workbook *khác* | Nhãn nói VAT 8%; gross/net và discount chưa được định nghĩa thêm | Chỉ các dòng workbook legacy | Cao cho công thức, **không** chứng minh giá trị trong upload hiện tại; **NO** |

Giá SP hiện `UNRESOLVED`: cần nguồn giá Vui Khỏe order-level VAT 8% đã duyệt (ví dụ header chính xác trong export mở rộng hoặc nguồn nội bộ), quy tắc multi-item/discount, và provenance trước khi mapping. `Phí Affiliate (Vui Khỏe)` và `Chiết Khấu (Vui Khỏe)` cũng không có header production/nguồn nội bộ; Shopee `affiliateCommissionFee`/`order_ams_commission_fee` **không** phải affiliate Vui Khỏe, Shopee promotions **không** phải chiết khấu Vui Khỏe. NULL khác 0. `Ngày Xuất VAT` và `Ghi Chú` thiếu nguồn Excel được xác minh; provider note chưa được duyệt ngữ nghĩa. `Chênh lệch` cần công thức kế toán mới ngoài `#REF!`. Shopee Escrow vẫn được materialize như trước; Lazada không được chế phí. Không đổi one-upload pipeline, schema 8 hoặc canonical v3.

WP.6D lưu riêng Income evidence từ `POST /api/v2/payment/get_income_detail` khi admin tìm thấy đúng `order_sn`. `released_amount` và provider `status` là **ứng viên nguồn** cho Đã Thu Tiền / Trạng Thái Công Nợ, chưa được phê duyệt mapping; hai cột này tiếp tục NULL trong canonical v3. `estimated_escrow_amount` của PENDING không phải tiền đã thu. Không đổi 24 nhãn/cột hoặc phiên bản canonical ở giai đoạn này.

