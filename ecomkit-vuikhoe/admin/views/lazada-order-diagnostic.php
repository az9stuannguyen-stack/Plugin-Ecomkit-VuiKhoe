<?php
defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
wp_enqueue_script( 'ecomkit-lazada-order-diagnostic', plugins_url( 'assets/js/lazada-order-diagnostic.js', ECOMKIT_VUIKHOE_FILE ), array(), ECOMKIT_VUIKHOE_VERSION, true );
$diagnostic_ready = array_values( array_filter( $lazada_connections, static fn( array $c ): bool => 'ACTIVE' === $c['status'] && in_array( $c['lifecycle'], array( 'READY', 'REFRESH_NEEDED' ), true ) ) );
?>
<details id="ecomkit-lazada-order-diagnostic" class="ecomkit-layout-1b0f4999">
<summary>Kiểm tra Lazada Order API</summary>
<p>Chỉ kiểm tra đọc dữ liệu và mã đơn; không lưu đơn hoặc map tài chính.</p>
<?php if ( empty( $diagnostic_ready ) ) : ?><p>Chưa thể kiểm tra Order API. Vui lòng kết nối Lazada trước.</p><?php endif; ?>
<form data-lazada-diagnostic action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post">
<input type="hidden" name="action" value="ecomkit_lazada_order_diagnostic">
<?php wp_nonce_field( 'ecomkit_lazada_order_diagnostic', 'nonce' ); ?>
<p><label>Shop VN <select name="connection_id" required><?php foreach ( $diagnostic_ready as $c ) : ?><option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( $c['shop'] ); ?></option><?php endforeach; ?></select></label></p>
<p><label>Ngày đơn: <input name="order_date" type="date" value="<?php echo esc_attr( wp_date( 'Y-m-d', time(), new DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) ); ?>"></label></p>
<p><label>Mã đơn Lazada: <input name="order_id" type="text" autocomplete="off" maxlength="40"></label></p>
<p><label><input name="check_list" type="checkbox" value="1"> Kiểm tra thêm danh sách đơn theo ngày (GetOrders)</label></p>
<p>Kiểm tra trực tiếp mã đơn không cần ngày. Danh sách chỉ đọc một trang từ 00:00 theo Asia/Ho_Chi_Minh; có thể chứa đơn của các ngày sau. Không có trong trang này không có nghĩa là đơn không tồn tại.</p>
<details><summary>Cài đặt nâng cao</summary><p><label>Offset <input name="offset" type="number" value="0" min="0" max="5000" step="100"></label> Giới hạn 100 đơn/trang; không tự chuyển trang.</p></details>
<p><button class="button button-primary" type="submit" <?php disabled( empty( $diagnostic_ready ) ); ?>>Kiểm tra đơn Lazada</button></p>
</form>
<p data-lazada-status role="status" aria-live="polite"></p>
<pre data-lazada-summary class="ecomkit-layout-a548ea71"></pre>
<details><summary>Chi tiết kỹ thuật</summary><pre data-lazada-output class="ecomkit-layout-16667867"></pre></details>
<noscript>Cần bật JavaScript để kiểm tra mà không lưu response vào database/transient.</noscript>
</details>
