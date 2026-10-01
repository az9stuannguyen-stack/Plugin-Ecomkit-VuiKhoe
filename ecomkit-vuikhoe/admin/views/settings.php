<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Cài đặt', 'ecomkit-vuikhoe' ); ?></h1>
	<p><?php echo esc_html__( 'Thông tin chẩn đoán nền tảng; trang này không hiển thị hoặc chỉnh sửa secret.', 'ecomkit-vuikhoe' ); ?></p>
	<ul>
		<li><?php echo esc_html( sprintf( /* translators: %s: PHP version. */ __( 'PHP hiện tại: %s', 'ecomkit-vuikhoe' ), PHP_VERSION ) ); ?></li>
		<li><?php global $wp_version; echo esc_html( sprintf( /* translators: %s: WordPress version. */ __( 'WordPress hiện tại: %s', 'ecomkit-vuikhoe' ), (string) $wp_version ) ); ?></li>
		<li><?php echo esc_html( sprintf( /* translators: %s: schema status. */ __( 'Schema: %s', 'ecomkit-vuikhoe' ), $data['diagnostic']['tables_ok'] ? __( 'Sẵn sàng', 'ecomkit-vuikhoe' ) : __( 'Cần kiểm tra', 'ecomkit-vuikhoe' ) ) ); ?></li>
	</ul>
</div>

