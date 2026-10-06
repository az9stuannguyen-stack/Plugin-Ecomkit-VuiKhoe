<?php
defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
wp_enqueue_script( 'ecomkit-lazada-finance-diagnostic', plugins_url( 'assets/js/lazada-finance-diagnostic.js', ECOMKIT_VUIKHOE_FILE ), array(), ECOMKIT_VUIKHOE_VERSION, true );
$finance_connections = ( new Ecomkit_Vuikhoe_Lazada_Reconciliation_Service() )->active_connections();
?>
<details style="margin-top:16px"><summary>Kiểm tra nguồn tài chính Lazada</summary>
<p>Chỉ đọc nguồn để audit, không lưu tài chính hoặc ghi 24 cột. Ngày dưới đây là ngày giao dịch, không phải ngày lên đơn. Thiếu dữ liệu không có nghĩa phí bằng 0.</p>
<form data-lazada-finance action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post">
<input type="hidden" name="action" value="ecomkit_lazada_finance_diagnostic">
<?php wp_nonce_field( 'ecomkit_lazada_finance_diagnostic', 'nonce' ); ?>
<p><label>Shop Lazada <select name="connection_id" required><?php foreach ( $finance_connections as $c ) : ?><option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( $c['shop'] . ' (' . $c['lifecycle'] . ')' ); ?></option><?php endforeach; ?></select></label></p>
<p><label>Mã đơn Lazada <input type="text" name="order_id" inputmode="numeric" maxlength="40" required></label></p>
<p><label>Từ ngày giao dịch <input type="date" name="start_date" required></label> <label>Đến ngày giao dịch <input type="date" name="end_date" required></label></p>
<p>Khoảng ngày ngắn hơn 180 ngày. Mỗi lần đọc một trang tối đa 100 giao dịch; không tự gọi trang tiếp theo.</p>
<p><label>API <select name="endpoint"><option value="detail">GetTransactionDetails — đọc kỳ, đối chiếu mã tại Ecomkit</option><option value="details">QueryTransactionDetails — lọc trade_order_id (cần quyền tương ứng)</option></select></label></p>
<p><label>Offset trang <input name="offset" type="number" value="0" min="0" max="1000000" step="100"></label></p>
<p><label><input type="checkbox" name="check_payout" value="1"> Đọc thêm payout của shop từ ngày bắt đầu (cấp statement, không phải từng đơn)</label></p>
<p><label><input type="checkbox" name="check_order" value="1"> Đọc thêm GetOrder/GetOrderItems để so sánh nguồn giá và raw status</label></p>
<button class="button" type="submit" <?php disabled( empty( $finance_connections ) ); ?>>Kiểm tra nguồn tài chính Lazada</button>
</form><div data-lazada-finance-output aria-live="polite"></div>
</details>
