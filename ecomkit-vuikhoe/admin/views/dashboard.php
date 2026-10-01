<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Ecomkit - Vui Khỏe', 'ecomkit-vuikhoe' ); ?></h1>
	<p><?php echo esc_html__( 'Plugin nền tảng đã sẵn sàng.', 'ecomkit-vuikhoe' ); ?></p>
	<table class="widefat striped" style="max-width: 760px">
		<tbody>
			<tr><th scope="row"><?php echo esc_html__( 'Phiên bản plugin', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( ECOMKIT_VUIKHOE_VERSION ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Phiên bản schema', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( (string) $data['diagnostic']['stored_version'] . ' / ' . (string) $data['diagnostic']['expected_version'] ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'PHP tương thích', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( version_compare( PHP_VERSION, ECOMKIT_VUIKHOE_MIN_PHP, '>=' ) ? __( 'Đạt', 'ecomkit-vuikhoe' ) : __( 'Không đạt', 'ecomkit-vuikhoe' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'WordPress tương thích', 'ecomkit-vuikhoe' ); ?></th><td><?php global $wp_version; echo esc_html( version_compare( (string) $wp_version, ECOMKIT_VUIKHOE_MIN_WP, '>=' ) ? __( 'Đạt', 'ecomkit-vuikhoe' ) : __( 'Không đạt', 'ecomkit-vuikhoe' ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html__( 'Bảng cơ sở dữ liệu', 'ecomkit-vuikhoe' ); ?></th><td><?php echo esc_html( $data['diagnostic']['tables_ok'] ? __( 'Sẵn sàng', 'ecomkit-vuikhoe' ) : __( 'Cần kiểm tra', 'ecomkit-vuikhoe' ) ); ?></td></tr>
		</tbody>
	</table>
	<h2><?php echo esc_html__( 'Trạng thái từng bảng', 'ecomkit-vuikhoe' ); ?></h2>
	<ul>
		<?php foreach ( $data['diagnostic']['tables'] as $table_key => $table_ready ) : ?>
			<li><code><?php echo esc_html( (string) $table_key ); ?></code>: <?php echo esc_html( $table_ready ? __( 'Sẵn sàng', 'ecomkit-vuikhoe' ) : __( 'Thiếu hoặc cần kiểm tra', 'ecomkit-vuikhoe' ) ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>

