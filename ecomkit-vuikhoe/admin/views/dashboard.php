<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap ecomkit-admin ecomkit-page-dashboard">
	<header class="ecomkit-page-header"><h1><?php echo esc_html__( 'Ecomkit - Vui Khỏe', 'ecomkit-vuikhoe' ); ?></h1><p class="ecomkit-subtitle">Excel &rarr; Xử lý &rarr; Kết quả &rarr; Sao chép</p></header>
	<section class="ecomkit-card"><h2>Bắt đầu xử lý đơn hàng</h2><p>Chọn file Excel để Ecomkit tự động xử lý. Khi có kết quả, bạn có thể xem và sao chép dữ liệu.</p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-process' ), admin_url( 'admin.php' ) ) ); ?>">Chọn file Excel</a> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ecomkit-vuikhoe-results' ), admin_url( 'admin.php' ) ) ); ?>">Xem kết quả</a></section>
	<dl class="ecomkit-stats">
		<div class="ecomkit-stat"><dt>Phiên bản Ecomkit</dt><dd><?php echo esc_html( ECOMKIT_VUIKHOE_VERSION ); ?></dd></div>
		<div class="ecomkit-stat"><dt>Dữ liệu hệ thống</dt><dd><?php echo esc_html( $data['diagnostic']['tables_ok'] ? 'Sẵn sàng' : 'Cần kiểm tra' ); ?></dd></div>
		<div class="ecomkit-stat"><dt>Schema hiện tại</dt><dd><?php echo esc_html( (string) $data['diagnostic']['stored_version'] ); ?></dd></div>
	</dl>
	<details><summary>Thông tin hệ thống</summary>
	<div class="ecomkit-table-scroll"><table class="ecomkit-layout-c06a247c widefat striped">
		<tbody>
			<tr><th scope="row"><?php echo esc_html__( 'Phiên bản plugin', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( ECOMKIT_VUIKHOE_VERSION ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Phiên bản schema', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $data['diagnostic']['stored_version'] . ' / ' . (string) $data['diagnostic']['expected_version'] ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'PHP tương thích', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( version_compare( PHP_VERSION, ECOMKIT_VUIKHOE_MIN_PHP, '>=' ) ? __( 'Đạt', 'ecomkit-vuikhoe' ) : __( 'Không đạt', 'ecomkit-vuikhoe' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'WordPress tương thích', 'ecomkit-vuikhoe' ); ?></th><td><?php global $wp_version; echo esc_html( version_compare( (string) $wp_version, ECOMKIT_VUIKHOE_MIN_WP, '>=' ) ? __( 'Đạt', 'ecomkit-vuikhoe' ) : __( 'Không đạt', 'ecomkit-vuikhoe' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Bảng cơ sở dữ liệu', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $data['diagnostic']['tables_ok'] ? __( 'Sẵn sàng', 'ecomkit-vuikhoe' ) : __( 'Cần kiểm tra', 'ecomkit-vuikhoe' ) ); ?></td></tr>
		</tbody>
	</table></div>
	<h2><?php echo esc_html__( 'Trạng thái từng bảng', 'ecomkit-vuikhoe' ); ?></h2>
	<ul>
		<?php foreach ( $data['diagnostic']['tables'] as $table_key => $table_ready ) : ?>
			<li><code><?php echo esc_html( (string) $table_key ); ?></code>: <?php echo esc_html( $table_ready ? __( 'Sẵn sàng', 'ecomkit-vuikhoe' ) : __( 'Thiếu hoặc cần kiểm tra', 'ecomkit-vuikhoe' ) ); ?></li>
		<?php endforeach; ?>
	</ul></details>
</div>

