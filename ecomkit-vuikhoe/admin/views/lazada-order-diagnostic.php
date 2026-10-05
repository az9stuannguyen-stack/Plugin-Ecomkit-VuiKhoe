<?php
defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
wp_enqueue_script( 'ecomkit-lazada-order-diagnostic', plugins_url( 'assets/js/lazada-order-diagnostic.js', ECOMKIT_VUIKHOE_FILE ), array(), ECOMKIT_VUIKHOE_VERSION, true );
$diagnostic_ready = array_values( array_filter( $lazada_connections, static fn( array $c ): bool => 'ACTIVE' === $c['status'] && in_array( $c['lifecycle'], array( 'READY', 'REFRESH_NEEDED' ), true ) ) );
?>
<details id="ecomkit-lazada-order-diagnostic" style="margin-top:16px">
<summary>Kiểm tra Lazada Order API</summary>
<p>Chỉ kiểm tra đọc dữ liệu; không lưu đơn, không đối chiếu và không map tài chính. Mỗi lần bấm danh sách chỉ đọc một trang tối đa 100 đơn.</p>
<p><strong>Giới hạn thời gian:</strong> chỉ gửi Từ ngày/giờ (<code>created_after</code>). Đến ngày/giờ là mốc tham chiếu, KHÔNG gửi provider; kết quả có thể chứa đơn sau mốc này. Hai mốc nhập phải cách nhau tối đa 24 giờ. Không tự chạy các trang tiếp theo.</p>
<?php if ( empty( $diagnostic_ready ) ) : ?><p>Chưa thể kiểm tra Order API. Vui lòng kết nối Lazada trước.</p><?php endif; ?>
<form data-lazada-diagnostic action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post">
<input type="hidden" name="action" value="ecomkit_lazada_order_diagnostic">
<?php wp_nonce_field( 'ecomkit_lazada_order_diagnostic', 'nonce' ); ?>
<p><label>Shop VN <select name="connection_id" required><?php foreach ( $diagnostic_ready as $c ) : ?><option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( $c['shop'] ); ?></option><?php endforeach; ?></select></label></p>
<p><label>Từ ngày/giờ <input name="from" type="datetime-local" value="<?php echo esc_attr( wp_date( 'Y-m-d\T00:00', time(), wp_timezone() ) ); ?>" required></label> <label>Đến ngày/giờ (tham chiếu) <input name="to" type="datetime-local" value="<?php echo esc_attr( wp_date( 'Y-m-d\T23:59', time(), wp_timezone() ) ); ?>" required></label></p>
<p>Múi giờ: <?php echo esc_html( wp_timezone()->getName() ); ?>. <label>Offset <input name="offset" type="number" value="0" min="0" max="5000" step="100" style="width:100px"></label></p>
<p><button class="button" type="submit" name="operation" value="orders" <?php disabled( empty( $diagnostic_ready ) ); ?>>Kiểm tra danh sách đơn Lazada</button></p>
<p><label>Order ID chính xác <input name="order_id" type="text" list="ecomkit-lazada-returned-ids" autocomplete="off" maxlength="40"></label><datalist id="ecomkit-lazada-returned-ids"></datalist> Chọn ID trả về từ danh sách; không chuyển ID thành số.</p>
<p><label>Nhập mã đơn Lazada từ Excel (tùy chọn) <input name="excel_order_id" type="text" maxlength="128" autocomplete="off"></label> So sánh exact string; không tự lưu hay thay đổi matching.</p>
<p><button class="button" type="submit" name="operation" value="order" <?php disabled( empty( $diagnostic_ready ) ); ?>>Kiểm tra chi tiết đơn</button> <button class="button" type="submit" name="operation" value="items" <?php disabled( empty( $diagnostic_ready ) ); ?>>Kiểm tra sản phẩm đơn</button></p>
</form>
<p data-lazada-status role="status" aria-live="polite"></p>
<p>PII chỉ hiện field availability; MASKED/MISSING không tự động là lỗi API. Response evidence là đường dẫn cấu trúc quan sát được, không phải raw payload. Token lifecycle có thể tự làm mới khi gần hết hạn.</p>
<pre data-lazada-output style="max-height:520px;overflow:auto;white-space:pre-wrap"></pre>
<noscript>Cần bật JavaScript để kiểm tra mà không lưu response vào database/transient.</noscript>
</details>
