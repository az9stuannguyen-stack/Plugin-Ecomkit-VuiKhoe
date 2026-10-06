<?php
defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
$lazada_connections = array_values( array_filter( (array) ( $data['lazada_connections'] ?? array() ), static fn( array $c ): bool => 'ACTIVE' === ( $c['status'] ?? '' ) ) );
$lazada_metadata = $batch['metadata'] ?? json_decode( (string) ( $batch['source_metadata'] ?? '' ), true );
$lazada_summary = array_merge( (array) ( $lazada_metadata['lazada_reconciliation'] ?? array() ), ( new Ecomkit_Vuikhoe_Lazada_Reconciliation_Service() )->batch_summary( (int) $batch['id'] ) );
$lazada_total = (int) $lazada_summary['total_lazada'];
if ( $lazada_total < 1 ) { return; }
$lazada_pending = max( 0, (int) ( $lazada_summary['eligible_count'] ?? $lazada_total ) - (int) ( $lazada_summary['processed'] ?? 0 ) );
$lazada_resume = $lazada_pending > 0 && ! empty( $lazada_summary['connection_id'] );
?>
<section aria-label="Đối chiếu Lazada cho Batch">
<h2>Đối chiếu Lazada cho Batch</h2>
<p>Kiểm tra chính xác mã đơn Lazada trong Batch với Lazada API. Chỉ dùng cho kiểm tra kỹ thuật ở giai đoạn hiện tại.</p>
<?php if ( isset( $_GET['lazada_reconcile_error'] ) ) : ?><p role="alert">Không thể hoàn tất đối chiếu Lazada. <code><?php echo esc_html( strtoupper( sanitize_key( wp_unslash( $_GET['lazada_reconcile_error'] ) ) ) ); ?></code></p><?php endif; ?>
<p role="status"><?php echo esc_html( sprintf( 'Tổng đơn Lazada: %d · Đã xử lý: %d · Đã khớp: %d · Không khớp: %d · Lỗi: %d · Còn chờ: %d', $lazada_total, $lazada_summary['processed'] ?? 0, $lazada_summary['matched'] ?? 0, $lazada_summary['unmatched'] ?? 0, $lazada_summary['errors'] ?? 0, $lazada_pending ) ); ?></p>
<?php if ( $lazada_resume ) : ?><p><?php echo esc_html( 'Shop Lazada: ' . ( $lazada_summary['shop_id'] ?? '' ) ); ?></p><?php endif; ?>
<p>Mỗi lần bấm xử lý tối đa một đơn. Nếu còn đơn, bấm tiếp tục; không tự chạy hoặc tự thử lại. Xem lỗi từng đơn tại trang Lỗi. PII bị ẩn vẫn được chấp nhận.</p>
<?php if ( in_array( (string) $batch['status'], array( 'SUCCESS', 'WARNING' ), true ) && ( $lazada_connections || $lazada_resume ) ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="ecomkit_lazada_reconcile_batch">
<input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>">
<input type="hidden" name="return_page" value="<?php echo esc_attr( $data['lazada_return_page'] ?? 'ecomkit-vuikhoe-process' ); ?>">
<?php wp_nonce_field( 'ecomkit_lazada_reconcile_batch', 'ecomkit_lazada_reconcile_nonce' ); ?>
<?php if ( $lazada_resume ) : ?>
<input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) $lazada_summary['connection_id'] ); ?>">
<?php else : ?>
<label>Shop Lazada <select name="connection_id" required>
<?php if ( count( $lazada_connections ) > 1 ) : ?><option value="">Chọn shop</option><?php endif; ?>
<?php foreach ( $lazada_connections as $c ) : ?><option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( $c['shop'] . ' (' . $c['lifecycle'] . ')' ); ?></option><?php endforeach; ?>
</select></label>
<?php endif; ?>
<button class="button" type="submit"><?php echo esc_html( $lazada_resume && $lazada_pending > 0 ? 'Tiếp tục đối chiếu Lazada' : 'Đối chiếu Lazada' ); ?></button>
</form>
<?php else : ?><p>Cần Batch Excel hợp lệ và kết nối Lazada ACTIVE.</p><?php endif; ?>
<?php if ( ! empty( $data['lazada_evidence'] ) ) : ?>
<p>Bằng chứng từng đơn (tối đa 100 dòng xem trước):</p>
<table class="widefat striped"><thead><tr><th>Mã đơn sàn</th><th>Kết quả</th><th>GetOrder</th><th>GetOrderItems</th><th>Provider ID</th><th>Exact</th><th>Item count</th><th>Raw statuses</th><th>PII</th><th>Lỗi</th></tr></thead><tbody>
<?php foreach ( $data['lazada_evidence'] as $e ) : ?><tr>
<?php $lazada_state = 'NOT_FOUND_IN_LAZADA' === $e['state'] ? 'UNMATCHED' : $e['state']; $lazada_api_label = static fn( mixed $v ): string => null === $v ? '—' : ( $v ? 'PASS' : 'FAIL' ); ?>
<?php foreach ( array( $e['excel_id'], $lazada_state, $lazada_api_label( $e['get_order_success'] ?? null ), $lazada_api_label( $e['get_items_success'] ?? null ), $e['provider_id'], $e['exact_match'] ? 'MATCH' : 'Chưa xác nhận', $e['item_count'], wp_json_encode( $e['raw_statuses'] ), $e['pii'], $e['error_code'] ) as $value ) : ?><td><?php echo esc_html( null === $value ? '—' : (string) $value ); ?></td><?php endforeach; ?>
</tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
</section>
