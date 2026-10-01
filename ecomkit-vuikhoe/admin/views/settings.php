<?php defined( 'ABSPATH' ) || exit; $runtime = $data['excel_runtime']; ?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Cài đặt', 'ecomkit-vuikhoe' ); ?></h1>
	<p><?php echo esc_html__( 'Thông tin chẩn đoán an toàn; trang này không hiển thị secret hoặc đường dẫn máy chủ.', 'ecomkit-vuikhoe' ); ?></p>
	<?php $database = $data['database_runtime']; $labels = array( 'batches' => 'Batches', 'orders' => 'Orders', 'order_items' => 'Order Items', 'errors' => 'Errors', 'marketplace_connections' => 'Marketplace Connections', 'sync_runs' => 'Sync Runs' ); ?>
	<h2><?php echo esc_html__( 'Database Runtime Diagnostics', 'ecomkit-vuikhoe' ); ?></h2>
	<?php if ( ! $database['innodb_supported'] ) : ?><div class="notice notice-error inline"><p><?php echo esc_html__( 'Máy chủ cơ sở dữ liệu hiện không hỗ trợ InnoDB, Ecomkit chưa thể xử lý dữ liệu an toàn.', 'ecomkit-vuikhoe' ); ?></p></div><?php endif; ?>
	<table class="widefat striped" style="max-width:900px"><tbody>
	<tr><th><?php echo esc_html__( 'Database server', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $database['db_type'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'InnoDB support', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $database['innodb_supported'] ? 'YES' : 'NO' ); ?></td></tr>
	<?php foreach ( $labels as $key => $label ) : $table = $database['tables'][ $key ]; ?><tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $table['engine'] . ' — ' . ( $table['transactional'] ? 'OK' : 'FAIL' ) ); ?></td></tr><?php endforeach; ?>
	<tr><th><?php echo esc_html__( 'Transactional database readiness', 'ecomkit-vuikhoe' ); ?></th><td><strong><?php echo esc_html( $database['ready'] ? 'PASS' : 'FAIL' ); ?></strong></td></tr>
	</tbody></table>
	<h2><?php echo esc_html__( 'Excel Runtime Diagnostics', 'ecomkit-vuikhoe' ); ?></h2>
	<?php if ( is_array( $data['runtime_test'] ) ) : ?>
		<div class="notice <?php echo $data['runtime_test']['ok'] ? 'notice-success' : 'notice-error'; ?> inline"><p>
			<?php echo esc_html( $data['runtime_test']['ok'] ? __( 'PASS — Ghi và đọc workbook XLSX tổng hợp thành công.', 'ecomkit-vuikhoe' ) : __( 'FAIL — Môi trường Excel chưa sẵn sàng.', 'ecomkit-vuikhoe' ) ); ?>
			<?php echo esc_html( sprintf( ' Giai đoạn: %1$s. Phân loại: %2$s.', $data['runtime_test']['stage'], $data['runtime_test']['classification'] ) ); ?>
		</p></div>
	<?php endif; ?>
	<table class="widefat striped" style="max-width:900px"><tbody>
	<tr><th><?php echo esc_html__( 'Plugin version', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['plugin_version'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'DB schema version', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $runtime['db_schema_version'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'PHP version', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['php_version'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'WordPress version', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['wordpress_version'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'Composer autoload', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['composer_autoload'] ? 'OK' : 'FAIL' ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'PhpSpreadsheet 5.8.1', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['phpspreadsheet'] ? 'OK' : 'FAIL' ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'Temporary directory', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['temp_available'] ? 'available' : 'unavailable' ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'Temporary directory writable', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['temp_writable'] ? 'YES' : 'NO' ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'WordPress upload directory', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['upload_available'] ? 'available' : 'unavailable' ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'WordPress upload directory writable', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['upload_writable'] ? 'YES' : 'NO' ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'Required PHP extensions', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( implode( ', ', $runtime['required_extensions'] ) ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'Missing PHP extensions', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( empty( $runtime['missing_extensions'] ) ? 'none' : 'MISSING: ' . implode( ', ', $runtime['missing_extensions'] ) ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'memory_limit', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['memory_limit'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'upload_max_filesize', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['upload_max_filesize'] ); ?></td></tr>
	<tr><th><?php echo esc_html__( 'post_max_size', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $runtime['post_max_size'] ); ?></td></tr>
	</tbody></table>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ecomkit_vuikhoe_test_excel_runtime">
		<?php wp_nonce_field( 'ecomkit_vuikhoe_test_excel_runtime', 'ecomkit_runtime_nonce' ); ?>
		<?php submit_button( __( 'Kiểm tra môi trường Excel', 'ecomkit-vuikhoe' ), 'secondary' ); ?>
	</form>
</div>
