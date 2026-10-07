<?php defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
$tiktok_config = new Ecomkit_Vuikhoe_Tiktok_Config();
$tiktok = $tiktok_config->safe_state(); ?>
<section class="ecomkit-card">
<h2>TikTok Shop Việt Nam</h2>
<p><strong>Chưa kết nối</strong> — Chỉ cấu hình ứng dụng ở giai đoạn hiện tại. Chưa triển khai ủy quyền người bán, lấy đơn hoặc tài chính.</p>
<?php if ( isset( $_GET['tiktok_notice'] ) ) : ?><p role="status"><?php echo esc_html( 'saved' === $_GET['tiktok_notice'] ? 'Đã lưu cấu hình TikTok Shop an toàn.' : 'Không thể lưu cấu hình TikTok Shop. Kiểm tra thông tin ứng dụng và cấu hình mã hóa.' ); ?></p><?php endif; ?>
<?php try { $tiktok_callback = $tiktok_config->callback_url(); ?><p><label for="ecomkit-tiktok-callback">Redirect URL dự kiến</label><br><input id="ecomkit-tiktok-callback" class="large-text" readonly type="text" value="<?php echo esc_attr( $tiktok_callback ); ?>"><br>URL HTTPS dành cho bước ủy quyền sau này; hiện chưa nhận mã hoặc đổi token.</p><?php } catch ( Throwable ) { ?><p>Cần cấu hình HTTPS cho WordPress trước khi chuẩn bị Redirect URL.</p><?php } ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="ecomkit_tiktok_save_config">
<?php wp_nonce_field( 'ecomkit_tiktok_save_config', 'ecomkit_tiktok_nonce' ); ?>
<p><label for="ecomkit-tiktok-key">App Key</label><br><input id="ecomkit-tiktok-key" name="app_key" type="text" required value="<?php echo esc_attr( $tiktok['app_key'] ); ?>" maxlength="128"></p>
<p><label for="ecomkit-tiktok-service">Service ID</label><br><input id="ecomkit-tiktok-service" name="service_id" type="text" required value="<?php echo esc_attr( $tiktok['service_id'] ); ?>" maxlength="128"><br>Lấy từ App &amp; Service trong TikTok Shop Partner Center, dùng cho liên kết ủy quyền người bán.</p>
<p><label for="ecomkit-tiktok-secret">App Secret</label><br><input id="ecomkit-tiktok-secret" name="app_secret" type="password" value="" autocomplete="new-password" maxlength="4096"><br><?php echo esc_html( $tiktok['secret_status'] ); ?>. Để trống để giữ nguyên; khi đổi App Key hoặc Service ID cần nhập secret mới.</p>
<?php submit_button( 'Lưu cấu hình TikTok Shop', 'secondary' ); ?>
</form>
</section>
