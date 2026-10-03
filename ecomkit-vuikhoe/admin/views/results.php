<?php defined( 'ABSPATH' ) || exit; $result = $data['reconciliation'] ?? null; ?>
<div class="wrap"><h1><?php echo esc_html__( 'Đối chiếu Shopee', 'ecomkit-vuikhoe' ); ?></h1>
<?php if ( ! is_array( $result ) ) : ?><p><?php echo esc_html__( 'Batch này chưa có kết quả đối chiếu Shopee.', 'ecomkit-vuikhoe' ); ?></p><?php else : $summary = $result['summary']; ?>
<?php if ( isset( $_GET['reconcile_notice'] ) ) : ?><div class="notice notice-success inline"><p><?php echo esc_html__( 'Đã hoàn tất lượt đối chiếu Shopee.', 'ecomkit-vuikhoe' ); ?></p></div><?php endif; ?>
<table class="widefat striped" style="max-width:900px"><tbody>
<?php foreach ( array( 'excel_shopee_count' => 'Excel Shopee Orders', 'provider_count' => 'Shopee Orders trong khoảng truy vấn', 'matched_count' => 'Matched', 'missing_count' => 'Missing in Shopee', 'extra_count' => 'Extra in Shopee window', 'detail_count' => 'Details loaded', 'detail_missing_count' => 'Detail missing', 'status' => 'Status' ) as $key => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) ( $summary[ $key ] ?? 0 ) ); ?></td></tr><?php endforeach; ?>
</tbody></table>
<h2><?php echo esc_html__( 'Đơn Shopee trong Batch', 'ecomkit-vuikhoe' ); ?></h2>
<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Mã đơn sàn', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Matching status', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Shopee status', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Ngày đặt Excel', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Provider create time', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Provider update time', 'ecomkit-vuikhoe' ); ?></th><th><?php echo esc_html__( 'Nguồn', 'ecomkit-vuikhoe' ); ?></th></tr></thead><tbody>
<?php foreach ( $result['orders'] as $order ) : $source = $order['source_refs']; ?><tr><td><code><?php echo esc_html( $order['order_sn'] ); ?></code></td><td><?php echo esc_html( $order['matching_status'] ); ?></td><td><?php echo esc_html( $order['provider_status'] ); ?></td><td><?php echo esc_html( $order['order_date'] ); ?></td><td><?php echo esc_html( $order['provider_created_at'] ); ?></td><td><?php echo esc_html( $order['provider_updated_at'] ); ?></td><td><?php echo esc_html( (string) ( $source['sheet'] ?? '' ) . ( isset( $source['row'] ) ? ' — dòng ' . (int) $source['row'] : '' ) ); ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if ( ! empty( $summary['extra_order_sns'] ) ) : ?><h2><?php echo esc_html__( 'Extra in Shopee window', 'ecomkit-vuikhoe' ); ?></h2><p><?php echo esc_html( implode( ', ', array_map( 'strval', $summary['extra_order_sns'] ) ) ); ?></p><?php endif; ?>
<p><?php echo esc_html__( 'Kết quả này là đối chiếu danh tính đơn Shopee, chưa phải đối soát tài chính hoàn tất.', 'ecomkit-vuikhoe' ); ?></p>
<?php endif; ?></div>

