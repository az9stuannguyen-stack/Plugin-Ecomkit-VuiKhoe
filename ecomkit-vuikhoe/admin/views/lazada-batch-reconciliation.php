<?php
defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
$lazada_connections = (array) ( $data['lazada_connections'] ?? array() );
$lazada_summary = (array) ( $batch['metadata']['lazada_reconciliation'] ?? array() );
$lazada_resume = 'PROCESSING' === ( $lazada_summary['status'] ?? '' );
?>
<details><summary>Chẩn đoán nâng cao: Đối chiếu Lazada cho Batch</summary>
<p>Đối chiếu mã đơn sàn chính xác trong shop đã chọn. Không cần ngày, không quét danh sách; chưa map trạng thái hoặc tài chính Lazada.</p>
<?php if ( isset( $_GET['lazada_reconcile_error'] ) ) : ?><p role="alert">Không thể hoàn tất đối chiếu Lazada. <code><?php echo esc_html( strtoupper( sanitize_key( wp_unslash( $_GET['lazada_reconcile_error'] ) ) ) ); ?></code></p><?php endif; ?>
<?php if ( $lazada_summary ) : ?>
<p role="status"><?php echo esc_html( sprintf( 'Shop %s · %s · Đã xử lý %d/%d · Khớp %d · Không tìm thấy %d · Lỗi %d · GetOrder thành công %d · GetOrderItems thành công %d', $lazada_summary['shop_id'] ?? '', $lazada_summary['status'] ?? '', $lazada_summary['processed'] ?? 0, $lazada_summary['eligible_count'] ?? 0, $lazada_summary['matched'] ?? 0, $lazada_summary['unmatched'] ?? 0, $lazada_summary['errors'] ?? 0, $lazada_summary['get_order_success'] ?? 0, $lazada_summary['get_items_success'] ?? 0 ) ); ?></p>
<?php endif; ?>
<p>Mỗi lần bấm xử lý tối đa một đơn. Nếu còn đơn, bấm tiếp tục; không tự chạy hoặc tự thử lại. Xem lỗi từng đơn tại trang Lỗi. PII bị ẩn vẫn được chấp nhận.</p>
<?php if ( in_array( (string) $batch['status'], array( 'SUCCESS', 'WARNING' ), true ) && ( $lazada_connections || $lazada_resume ) ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="ecomkit_lazada_reconcile_batch">
<input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>">
<?php wp_nonce_field( 'ecomkit_lazada_reconcile_batch', 'ecomkit_lazada_reconcile_nonce' ); ?>
<?php if ( $lazada_resume ) : ?>
<input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) $lazada_summary['connection_id'] ); ?>">
<?php else : ?>
<label>Shop Lazada <select name="connection_id" required>
<?php if ( count( $lazada_connections ) > 1 ) : ?><option value="">Chọn shop</option><?php endif; ?>
<?php foreach ( $lazada_connections as $c ) : ?><option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( $c['shop'] . ' (' . $c['lifecycle'] . ')' ); ?></option><?php endforeach; ?>
</select></label>
<?php endif; ?>
<button class="button" type="submit"><?php echo esc_html( $lazada_resume ? 'Tiếp tục đối chiếu Lazada cho Batch' : 'Đối chiếu Lazada cho Batch' ); ?></button>
</form>
<?php else : ?><p>Cần Batch Excel hợp lệ và kết nối Lazada ACTIVE.</p><?php endif; ?>
<?php if ( ! empty( $data['lazada_evidence'] ) ) : ?>
<p>Bằng chứng từng đơn (tối đa 100 dòng xem trước):</p>
<table class="widefat striped"><thead><tr><th>Mã Excel</th><th>Kết quả</th><th>Mã provider</th><th>Exact</th><th>Sản phẩm</th><th>Trạng thái API</th><th>PII</th><th>Lỗi</th></tr></thead><tbody>
<?php foreach ( $data['lazada_evidence'] as $e ) : ?><tr>
<?php foreach ( array( $e['excel_id'], $e['state'], $e['provider_id'], $e['exact_match'] ? 'MATCH' : 'Chưa xác nhận', $e['item_count'], wp_json_encode( $e['raw_statuses'] ), $e['pii'], $e['error_code'] ) as $value ) : ?><td><?php echo esc_html( null === $value ? '—' : (string) $value ); ?></td><?php endforeach; ?>
</tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
</details>
