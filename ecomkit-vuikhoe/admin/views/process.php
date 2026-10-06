<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap ecomkit-admin ecomkit-page-process">
	<header class="ecomkit-page-header"><h1><?php echo esc_html__( 'Xử lý đơn hàng', 'ecomkit-vuikhoe' ); ?></h1><p class="ecomkit-subtitle">Excel &rarr; Xử lý &rarr; Kết quả &rarr; Sao chép</p></header>
	<?php if ( isset( $_GET['import_failure'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html__( 'Không thể hoàn tất yêu cầu nhập Excel an toàn.', 'ecomkit-vuikhoe' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['reconcile_error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html__( 'Không thể hoàn tất đối chiếu Shopee.', 'ecomkit-vuikhoe' ); ?> <code><?php echo esc_html( strtoupper( sanitize_key( wp_unslash( $_GET['reconcile_error'] ) ) ) ); ?></code></p></div><?php endif; ?>
	<p><?php echo esc_html__( 'Chọn file Excel đơn hàng. Ecomkit sẽ tự động xử lý và tạo kết quả.', 'ecomkit-vuikhoe' ); ?></p>
	<section class="ecomkit-layout-be094664">
	<h2><?php echo esc_html__( 'Chọn file Excel', 'ecomkit-vuikhoe' ); ?></h2>
	<p><?php echo esc_html( sprintf( 'Một file .xlsx mỗi lần, tối đa %s. Sau khi tải lên, bạn chỉ cần đợi kết quả.', size_format( (int) $data['max_upload_size'] ) ) ); ?></p>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ecomkit_vuikhoe_import_excel"><?php wp_nonce_field( 'ecomkit_vuikhoe_import_excel', 'ecomkit_nonce' ); ?>
		<table class="form-table" role="presentation"><tr><th scope="row"><label for="ecomkit-excel-file"><?php echo esc_html__( 'File Excel', 'ecomkit-vuikhoe' ); ?></label></th><td><input class="ecomkit-upload-input" id="ecomkit-excel-file" name="excel_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></td></tr><tr><th scope="row"><?php echo esc_html__( 'Nguồn xử lý', 'ecomkit-vuikhoe' ); ?></th><td><strong><?php echo esc_html__( 'Excel nội bộ', 'ecomkit-vuikhoe' ); ?></strong></td></tr></table>
		<?php submit_button( __( 'Tải lên và xử lý', 'ecomkit-vuikhoe' ) ); ?>
	</form>
	</section>
	<?php if ( is_array( $data['batch'] ) ) : $batch = $data['batch']; $local_time = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $batch['created_at'] . ' UTC' ), wp_timezone() ); ?>
		<hr><h2><?php echo esc_html__( 'Tóm tắt Batch', 'ecomkit-vuikhoe' ); ?></h2>
		<div class="ecomkit-table-scroll"><table class="ecomkit-layout-1d34c094 widefat striped"><tbody>
		<tr><th><?php echo esc_html__( 'Batch ID', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $batch['id'] ); ?></td></tr><tr><th><?php echo esc_html__( 'Tên file', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $batch['source_filename'] ); ?></td></tr><tr><th><?php echo esc_html__( 'Trạng thái', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $batch['status'] ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'Dòng nghiệp vụ đã xử lý', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $batch['metadata']['total_rows'] ?? 0 ) ); ?></td></tr><tr><th><?php echo esc_html__( 'Đơn hợp lệ', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $batch['order_count'] ); ?></td></tr><tr><th><?php echo esc_html__( 'Dòng sản phẩm', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $batch['metadata']['item_rows'] ?? 0 ) ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'Sàn đã phát hiện', 'ecomkit-vuikhoe' ); ?></th><td><?php $counts = array(); foreach ( (array) ( $batch['metadata']['platform_counts'] ?? array() ) as $platform => $count ) { $counts[] = $platform . ': ' . (int) $count; } echo esc_html( implode( ', ', $counts ) ); ?></td></tr><tr><th><?php echo esc_html__( 'Lỗi', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $batch['error_count'] ); ?></td></tr><tr><th><?php echo esc_html__( 'Thời điểm tạo', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $local_time ); ?></td></tr>
		</tbody></table></div>
		<?php if ( Ecomkit_Vuikhoe_Security::can_manage_ecomkit() ) : ?>
		<details><summary><?php echo esc_html__( 'Chẩn đoán nâng cao: Đối chiếu Shopee thủ công', 'ecomkit-vuikhoe' ); ?></summary>
		<?php $shopee_count = (int) ( $batch['metadata']['platform_counts']['SHOPEE'] ?? 0 ); $connections = (array) ( $data['ready_connections'] ?? array() ); ?>
		<?php if ( 0 === $shopee_count && in_array( (string) $batch['status'], array( 'SUCCESS', 'WARNING' ), true ) ) : ?><h2><?php echo esc_html__( 'Đối chiếu Shopee', 'ecomkit-vuikhoe' ); ?></h2><p><?php echo esc_html__( 'Batch này không có đơn Shopee để đối chiếu.', 'ecomkit-vuikhoe' ); ?></p>
		<?php elseif ( $shopee_count > 0 && in_array( (string) $batch['status'], array( 'SUCCESS', 'WARNING' ), true ) && $connections ) : ?>
		<h2><?php echo esc_html__( 'Đối chiếu Shopee', 'ecomkit-vuikhoe' ); ?></h2>
		<p><?php echo esc_html__( 'Ngày truy vấn được tự động lấy từ cột Ngày đặt của các đơn Shopee trong Batch. Không cần nhập ngày hoặc mã đơn.', 'ecomkit-vuikhoe' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ecomkit_shopee_reconcile_batch"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_shopee_reconcile_batch', 'ecomkit_reconcile_nonce' ); ?>
			<?php if ( count( $connections ) > 1 ) : ?><label for="ecomkit-reconcile-connection"><strong><?php echo esc_html__( 'Shop Shopee', 'ecomkit-vuikhoe' ); ?></strong></label> <select id="ecomkit-reconcile-connection" name="connection_id" required><option value=""><?php echo esc_html__( 'Chọn shop', 'ecomkit-vuikhoe' ); ?></option><?php foreach ( $connections as $connection ) : ?><option value="<?php echo esc_attr( (string) $connection['id'] ); ?>"><?php echo esc_html( (string) $connection['external_shop_id'] ); ?></option><?php endforeach; ?></select><?php elseif ( 1 === count( $connections ) ) : ?><input type="hidden" name="connection_id" value="<?php echo esc_attr( (string) $connections[0]['id'] ); ?>"><?php endif; ?>
			<?php submit_button( __( 'Đối chiếu Shopee', 'ecomkit-vuikhoe' ), 'primary', 'submit', false ); ?>
		</form>
		<?php endif; ?></details>
		<details <?php if ( isset( $_GET['lazada_reconcile_notice'] ) || isset( $_GET['lazada_reconcile_error'] ) ) { echo 'open'; } ?>><summary>Công cụ quản trị nâng cao</summary><?php require __DIR__ . '/lazada-batch-reconciliation.php'; ?></details>
		<?php endif; ?>
		<?php $failure = $batch['metadata']['failure_diagnostic'] ?? null; if ( is_array( $failure ) && ! empty( $failure['stage'] ) ) : ?>
		<h2><?php echo esc_html__( 'Chi tiết chẩn đoán', 'ecomkit-vuikhoe' ); ?></h2>
		<div class="ecomkit-table-scroll"><table class="ecomkit-layout-1d34c094 widefat striped"><tbody>
		<tr><th><?php echo esc_html__( 'Giai đoạn', 'ecomkit-vuikhoe' ); ?></th><td><code><?php echo esc_html( (string) $failure['stage'] ); ?></code></td></tr>
		<tr><th><?php echo esc_html__( 'Phân loại', 'ecomkit-vuikhoe' ); ?></th><td><code><?php echo esc_html( (string) ( $failure['classification'] ?? 'EXCEL_IMPORT_FAILED' ) ); ?></code></td></tr>
		<tr><th><?php echo esc_html__( 'Exception class', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $failure['exception_class'] ?? 'N/A' ) ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'Thông báo kỹ thuật an toàn', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $failure['exception_message'] ?? 'N/A' ) ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'Worksheet', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $failure['sheet'] ?? 'N/A' ) ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'Dòng vật lý', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( isset( $failure['row'] ) ? (string) $failure['row'] : 'N/A' ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'PHP', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $failure['php_version'] ?? PHP_VERSION ) ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'PhpSpreadsheet', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) ( $failure['phpspreadsheet'] ?? 'N/A' ) ); ?></td></tr>
		<?php if ( ! empty( $failure['db_column'] ) ) : ?><tr><th><?php echo esc_html__( 'Failing DB column', 'ecomkit-vuikhoe' ); ?></th><td><code><?php echo esc_html( (string) $failure['db_column'] ); ?></code></td></tr><?php endif; ?>
		</tbody></table></div>
		<?php endif; ?>
		<h2><?php echo esc_html__( 'Đơn Excel hợp lệ (tối đa 100 dòng xem trước)', 'ecomkit-vuikhoe' ); ?></h2>
		<?php if ( empty( $batch['orders'] ) ) : ?><p><?php echo esc_html__( 'Không có đơn hợp lệ để xem trước.', 'ecomkit-vuikhoe' ); ?></p><?php else : ?><div class="ecomkit-table-scroll"><table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Sàn', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Mã đơn sàn', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Ngày đặt', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Worksheet', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Dòng', 'ecomkit-vuikhoe' ); ?></th></tr></thead><tbody><?php foreach ( $batch['orders'] as $order ) : $source = json_decode( (string) $order['source_refs'], true ) ?: array(); ?><tr><td><?php echo esc_html( (string) $order['platform'] ); ?></td><td><code><?php echo esc_html( (string) $order['raw_order_code'] ); ?></code></td><td><?php echo esc_html( (string) ( $order['order_date_display'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $source['sheet'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $source['row'] ?? '' ) ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
		<h2><?php echo esc_html__( 'Lỗi import (tối đa 100 dòng xem trước)', 'ecomkit-vuikhoe' ); ?></h2>
		<?php if ( empty( $batch['errors'] ) ) : ?><p><?php echo esc_html__( 'Không phát hiện lỗi Excel.', 'ecomkit-vuikhoe' ); ?></p><?php else : ?><div class="ecomkit-table-scroll"><table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Vị trí', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Mã lỗi', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Thông báo', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Cách xử lý', 'ecomkit-vuikhoe' ); ?></th></tr></thead><tbody><?php foreach ( $batch['errors'] as $error ) : ?><tr><td><?php echo esc_html( trim( (string) $error['sheet_name'] . ( $error['row_number'] ? ' — dòng ' . $error['row_number'] : '' ) ) ); ?></td><td><code><?php echo esc_html( (string) $error['error_code'] ); ?></code></td><td><?php echo esc_html( (string) $error['friendly_message'] ); ?></td><td><?php echo esc_html( (string) $error['suggestion'] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
	<?php endif; ?>
</div>
