<?php defined( 'ABSPATH' ) || exit;
Ecomkit_Vuikhoe_Security::require_management_capability();
$tiktok_config = new Ecomkit_Vuikhoe_Tiktok_Config();
$tiktok = $tiktok_config->safe_state(); ?>
<section class="ecomkit-card">
<h2>TikTok Shop Việt Nam</h2>
<p>Ủy quyền tài khoản người bán và xác minh shop Việt Nam. Chưa triển khai lấy đơn hoặc tài chính TikTok.</p>
<?php
$tiktok_tokens = new Ecomkit_Vuikhoe_Tiktok_Token_Service();
try { $tiktok_connections = $tiktok_tokens->safe_connections(); } catch ( Throwable ) { $tiktok_connections = array(); }
$tiktok_notices = array( 'saved' => 'Đã lưu cấu hình TikTok Shop an toàn.', 'connected' => 'Đã kết nối shop TikTok.', 'refreshed' => 'Đã làm mới token an toàn.', 'choose_shop' => 'Vui lòng chọn shop Việt Nam để hoàn tất kết nối.', 'error' => 'Không thể hoàn tất. Kiểm tra cấu hình và thử ủy quyền lại.' );
if ( is_string( $_GET['tiktok_notice'] ?? null ) && isset( $tiktok_notices[$_GET['tiktok_notice']] ) ) { ?><p role="status"><?php echo esc_html( $tiktok_notices[$_GET['tiktok_notice']] ); ?></p><?php }
$tiktok_diagnostic = $_GET['tiktok_diagnostic'] ?? null;
if ( is_string( $tiktok_diagnostic ) && preg_match( '/^[a-f0-9]{32}$/D', $tiktok_diagnostic ) ) { $tiktok_error = get_transient( 'ecomkit_tiktok_notice_' . $tiktok_diagnostic ); if ( is_array( $tiktok_error ) && get_current_user_id() === ( $tiktok_error['user'] ?? null ) ) { ?><p><?php echo esc_html( $tiktok_error['code'] ); ?></p><details><summary>Chi tiết kỹ thuật TikTok</summary><?php foreach ( $tiktok_error['provider'] ?? array() as $tiktok_field => $tiktok_value ) { ?><p><?php echo esc_html( $tiktok_field . ': ' . $tiktok_value ); ?></p><?php } ?></details><?php } }
if ( isset( $tiktok_error ) && is_array( $tiktok_error ) && get_current_user_id() === ( $tiktok_error['user'] ?? null ) ) { $tiktok_friendly = array( 'TIKTOK_AUTH_DENIED' => 'Bạn đã hủy ủy quyền TikTok. Kết nối hiện có được giữ nguyên.', 'TIKTOK_STATE_EXPIRED' => 'Phiên kết nối đã hết hạn. Vui lòng bắt đầu kết nối lại.', 'TIKTOK_SCOPE_REQUIRED' => 'Ứng dụng chưa được cấp quyền seller.authorization.info. Kiểm tra quyền và ủy quyền lại.', 'TIKTOK_SHOP_NOT_FOUND' => 'TikTok chưa trả về shop được ủy quyền.', 'TIKTOK_SELLER_REQUIRED' => 'Cần ủy quyền tài khoản người bán TikTok Shop.', 'TIKTOK_REGION_MISMATCH' => 'Shop được chọn không thuộc Việt Nam.' ); if ( isset( $tiktok_friendly[$tiktok_error['code']] ) ) { ?><p><?php echo esc_html( $tiktok_friendly[$tiktok_error['code']] ); ?></p><?php } }
?>
<?php try { $tiktok_callback = $tiktok_config->callback_url(); ?><p><label for="ecomkit-tiktok-callback">Redirect URL</label><br><input id="ecomkit-tiktok-callback" class="large-text" readonly type="text" value="<?php echo esc_attr( $tiktok_callback ); ?>"><br>Đăng ký URL HTTPS này trong TikTok Shop Partner Center.</p><?php } catch ( Throwable ) { ?><p>Cần cấu hình HTTPS cho WordPress trước khi ủy quyền.</p><?php } ?>
<?php if ( ! $tiktok_connections ) : ?><p><strong><?php echo $tiktok['configured'] ? 'Chưa kết nối' : 'Chưa cấu hình'; ?></strong></p><?php endif; ?>
<?php $tiktok_waiting = function_exists( 'get_transient' ) && function_exists( 'get_current_user_id' ) ? get_transient( 'ecomkit_tiktok_waiting_' . get_current_user_id() ) : false;
if ( is_array( $tiktok_waiting ) && ( $tiktok_waiting['expires_at'] ?? 0 ) > time() && hash_equals( hash( 'sha256', wp_get_session_token() ), (string) ( $tiktok_waiting['session_hash'] ?? '' ) ) && hash_equals( $tiktok_config->fingerprint(), (string) ( $tiktok_waiting['fingerprint'] ?? '' ) ) ) : ?><p>Chờ xác nhận từ TikTok Shop.</p><?php endif; ?>
<?php foreach ( $tiktok_connections as $tiktok_connection ) : ?>
<p><strong>Shop: <?php echo esc_html( $tiktok_connection['shop_id'] ); ?></strong> · <?php echo esc_html( $tiktok_connection['name'] ); ?> · <?php echo esc_html( $tiktok_connection['region'] ?? '—' ); ?> · <?php echo esc_html( $tiktok_connection['lifecycle'] ); ?></p>
<p><?php echo esc_html( array( 'READY' => 'Đã kết nối — Sẵn sàng', 'REFRESH_SOON' => 'Đã kết nối — Cần làm mới', 'EXPIRED' => 'Access token hết hạn — Cần làm mới', 'REAUTH_REQUIRED' => 'Cần kết nối lại TikTok Shop', 'INVALID' => 'Cần kết nối lại TikTok Shop' )[$tiktok_connection['lifecycle']] ?? 'Chưa kết nối' ); ?></p>
<p>Scope: <?php echo esc_html( implode( ', ', $tiktok_connection['granted_scopes'] ?? array() ) ); ?>. Access hết hạn: <?php echo esc_html( isset( $tiktok_connection['access_expires_at'] ) ? gmdate( 'Y-m-d H:i:s', $tiktok_connection['access_expires_at'] ) . ' UTC' : '—' ); ?>. Refresh hết hạn: <?php echo esc_html( isset( $tiktok_connection['refresh_expires_at'] ) ? gmdate( 'Y-m-d H:i:s', $tiktok_connection['refresh_expires_at'] ) . ' UTC' : '—' ); ?></p>
<?php if ( in_array( $tiktok_connection['lifecycle'], array( 'READY', 'REFRESH_SOON', 'EXPIRED' ), true ) ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_tiktok_oauth_refresh"><input type="hidden" name="connection_id" value="<?php echo esc_attr( $tiktok_connection['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_tiktok_oauth_refresh', 'ecomkit_tiktok_nonce' ); submit_button( 'Làm mới token TikTok', 'secondary' ); ?></form>
<?php endif; endforeach; ?>
<?php if ( $tiktok['configured'] ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_tiktok_oauth_start"><?php wp_nonce_field( 'ecomkit_tiktok_oauth_start', 'ecomkit_tiktok_nonce' ); submit_button( $tiktok_connections ? 'Kết nối lại TikTok Shop' : 'Kết nối TikTok Shop', 'primary' ); ?></form>
<?php endif; ?>
<?php $tiktok_selection = $_GET['tiktok_selection'] ?? null;
if ( is_string( $tiktok_selection ) ) { try { $tiktok_shops = $tiktok_tokens->safe_selection( $tiktok_selection ); } catch ( Throwable ) { $tiktok_shops = array(); }
if ( $tiktok_shops ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_tiktok_oauth_select"><input type="hidden" name="selection" value="<?php echo esc_attr( $tiktok_selection ); ?>"><?php wp_nonce_field( 'ecomkit_tiktok_oauth_select', 'ecomkit_tiktok_nonce' ); ?>
<label for="ecomkit-tiktok-shop">Shop TikTok Việt Nam</label><select id="ecomkit-tiktok-shop" name="shop_id" required><option value="">Chọn shop</option><?php foreach ( $tiktok_shops as $tiktok_shop ) : ?><option value="<?php echo esc_attr( $tiktok_shop['id'] ); ?>" <?php echo 'VN' !== $tiktok_shop['region'] ? 'disabled' : ''; ?>><?php echo esc_html( $tiktok_shop['id'] . ' · ' . $tiktok_shop['name'] . ' · ' . $tiktok_shop['region'] ); ?></option><?php endforeach; ?></select><?php submit_button( 'Xác nhận shop TikTok', 'primary' ); ?></form>
<?php endif; } ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="ecomkit_tiktok_save_config">
<?php wp_nonce_field( 'ecomkit_tiktok_save_config', 'ecomkit_tiktok_nonce' ); ?>
<p><label for="ecomkit-tiktok-key">App Key</label><br><input id="ecomkit-tiktok-key" name="app_key" type="text" required value="<?php echo esc_attr( $tiktok['app_key'] ); ?>" maxlength="128"></p>
<p><label for="ecomkit-tiktok-service">Service ID</label><br><input id="ecomkit-tiktok-service" name="service_id" type="text" required value="<?php echo esc_attr( $tiktok['service_id'] ); ?>" maxlength="128"><br>Lấy từ App &amp; Service trong TikTok Shop Partner Center, dùng cho liên kết ủy quyền người bán.</p>
<p><label for="ecomkit-tiktok-secret">App Secret</label><br><input id="ecomkit-tiktok-secret" name="app_secret" type="password" value="" autocomplete="new-password" maxlength="4096"><br><?php echo esc_html( $tiktok['secret_status'] ); ?>. Để trống để giữ nguyên; khi đổi App Key hoặc Service ID cần nhập secret mới.</p>
<?php submit_button( 'Lưu cấu hình TikTok Shop', 'secondary' ); ?>
</form>
</section>
