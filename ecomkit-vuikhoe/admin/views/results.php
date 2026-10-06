<?php defined( 'ABSPATH' ) || exit; $result = $data['result'] ?? null; $source_gap_labels = array(); $canonical_labels = array(); foreach ( Ecomkit_Vuikhoe_Canonical_Columns::all() as $definition ) { $canonical_labels[ $definition['key'] ] = $definition['label']; } $formula_gap_labels = array(); foreach ( (array) ( $result['rows'] ?? array() ) as $result_row ) { foreach ( array( 'product_price_vat_8', 'affiliate_fee_vuikhoe', 'discount_vuikhoe', 'vat_issued_date', 'note' ) as $source_key ) { if ( null === ( $result_row['columns'][ $source_key ] ?? null ) ) { $source_gap_labels[ $source_key ] = $canonical_labels[ $source_key ]; } } foreach ( (array) ( $result_row['formula_state'] ?? array() ) as $formula_state ) { if ( 'MISSING_OPERANDS' === ( $formula_state['status'] ?? '' ) ) { foreach ( (array) ( $formula_state['fields'] ?? array() ) as $missing_key ) { $formula_gap_labels[ $missing_key ] = $canonical_labels[ $missing_key ] ?? $missing_key; } } } } ?>
<div class="wrap"><h1><?php echo esc_html__( 'Kết quả', 'ecomkit-vuikhoe' ); ?></h1>
<style>.wrap details{margin:16px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:6px}.wrap details summary{cursor:pointer;font-weight:600}.wrap .widefat th{white-space:nowrap}.wrap .widefat td{padding:10px 12px}#ecomkit-copy-values,#ecomkit-copy-with-headers{margin:8px 6px 8px 0}</style>
<form method="get"><input type="hidden" name="page" value="ecomkit-vuikhoe-results"><label for="batch_id"><?php echo esc_html__( 'Batch', 'ecomkit-vuikhoe' ); ?></label> <select id="batch_id" name="batch_id"><option value="">&mdash;</option><?php foreach ( $data['batches'] as $batch ) : ?><option value="<?php echo esc_attr( (string) $batch['id'] ); ?>" <?php selected( $data['batch_id'], (int) $batch['id'] ); ?>>#<?php echo esc_html( (string) $batch['id'] . ' — ' . (string) $batch['source_filename'] ); ?></option><?php endforeach; ?></select> <?php submit_button( __( 'Mở Batch', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?></form>
<?php $pipeline = $data['pipeline'] ?? null; $progress = $data['progress'] ?? null; if ( is_array( $progress ) ) : $pipeline_active = ! $progress['terminal']; $last_epoch = strtotime( $progress['updated_at'] . ' UTC' ); ?>
<style>
#ecomkit-progress{box-sizing:border-box;max-width:100%;padding:20px;margin:16px 0;border:1px solid #c3c4c7;border-left:5px solid #2271b1;border-radius:6px;background:#fff}#ecomkit-progress[data-status="SUCCESS"]{border-left-color:#00a32a;background:#f0f8f1}#ecomkit-progress[data-status="WARNING"]{border-left-color:#dba617;background:#fffaf0}#ecomkit-progress[data-status="ERROR"]{border-left-color:#d63638;background:#fcf0f1}#ecomkit-progress h2{margin:0 0 8px}#ecomkit-progress progress{display:block;width:100%;height:20px;margin:12px 0;accent-color:#2271b1}#ecomkit-progress[data-status="SUCCESS"] progress{accent-color:#00a32a}#ecomkit-progress .ecomkit-progress-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:8px 18px;margin-top:12px}#ecomkit-progress .ecomkit-progress-grid p{margin:2px 0}#ecomkit-progress .ecomkit-progress-alert{margin-top:12px;font-weight:600}
</style>
<section id="ecomkit-progress" data-status="<?php echo esc_attr( $progress['status'] ); ?>" data-batch="<?php echo esc_attr( (string) $data['batch_id'] ); ?>" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ecomkit_pipeline_progress' ) ); ?>" aria-live="polite">
<h2 id="ecomkit-progress-title"><?php echo esc_html( 'ERROR' === $progress['status'] ? 'XỬ LÝ DỪNG' : ( ! $progress['terminal'] ? 'Xử lý dữ liệu Shopee chưa hoàn tất' : ( 'WARNING' === $progress['status'] ? 'HOÀN TẤT VỚI CẢNH BÁO' : 'HOÀN TẤT' ) ) ); ?> — <span id="ecomkit-progress-percent"><?php echo esc_html( (string) $progress['percent'] ); ?></span>%</h2>
<progress id="ecomkit-progress-bar" max="100" value="<?php echo esc_attr( (string) $progress['percent'] ); ?>" aria-label="Tiến độ xử lý dữ liệu"><?php echo esc_html( (string) $progress['percent'] ); ?>%</progress>
<p><strong id="ecomkit-progress-stage"><?php echo esc_html( $progress['stage_label'] ); ?></strong></p>
<div class="ecomkit-progress-grid"><p id="ecomkit-progress-import"><?php echo esc_html( sprintf( 'Đã đọc: %d đơn, %d dòng sản phẩm · Shopee %d · Lazada %d', $progress['orders'], $progress['items'], $progress['shopee'], $progress['lazada'] ) ); ?></p><p id="ecomkit-progress-reconcile"><?php echo esc_html( sprintf( 'Đối chiếu Shopee: %d/%d · Khớp: %d', $progress['reconcile_done'], $progress['shopee'], $progress['matched'] ) ); ?></p><p id="ecomkit-progress-detail"><?php echo esc_html( sprintf( 'Chi tiết đơn Shopee: %d/%d · Thiếu: %d', $progress['detail_done'], $progress['matched'], $progress['detail_missing'] ) ); ?></p><p id="ecomkit-progress-payment"><?php echo esc_html( sprintf( 'Payment: %d/%d · Lấy mới: %d · Dùng lại: %d · Lỗi: %d', $progress['payment_done'], $progress['payment_total'], $progress['payment_fetched'], $progress['payment_reused'], $progress['payment_failed'] ) ); ?></p><p id="ecomkit-progress-income"><?php echo esc_html( sprintf( 'Income: %d/%d truy vấn · %s', $progress['income_done'], $progress['income_total'], $progress['income_label'] ) ); ?></p><p id="ecomkit-progress-canonical"><?php echo esc_html( sprintf( 'Kết quả: %d/%d đơn · 24 cột', $progress['canonical_done'], $progress['orders'] ) ); ?></p></div>
<p id="ecomkit-progress-warnings"><?php echo esc_html( sprintf( 'Cảnh báo: %d · Lỗi: %d', $progress['warning_count'], $progress['error_count'] ) ); ?></p>
<p><?php echo esc_html__( 'Tiến trình nền:', 'ecomkit-vuikhoe' ); ?> <span id="ecomkit-progress-worker"><?php echo esc_html( $pipeline_active ? 'Đang hoạt động' : 'Đã kết thúc' ); ?></span> · <?php echo esc_html__( 'Cập nhật gần nhất:', 'ecomkit-vuikhoe' ); ?> <span id="ecomkit-progress-updated"><?php echo false === $last_epoch ? '&mdash;' : esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_epoch, wp_timezone() ) ); ?></span></p>
<p class="ecomkit-progress-alert" id="ecomkit-progress-alert"><?php echo $progress['stalled'] ? esc_html__( 'Tiến trình chưa cập nhật; có thể đang chờ WordPress Cron. Không tự gọi lại Shopee.', 'ecomkit-vuikhoe' ) : ( 'ERROR' === $progress['status'] ? esc_html__( 'Tiến trình dừng ở bước này; dữ liệu đã lưu vẫn được giữ nguyên.', 'ecomkit-vuikhoe' ) : '' ); ?></p>
<p id="ecomkit-progress-source-gap" <?php echo $pipeline_active || 'ERROR' === $progress['status'] ? 'hidden' : ''; ?>><?php echo esc_html( 'Kết quả xử lý đã hoàn tất. Các ô “—” là dữ liệu chưa có nguồn xác thực cho đơn tương ứng, không phải dữ liệu đang chờ xử lý.' . ( $source_gap_labels ? ' Một số đơn còn thiếu: ' . implode( ', ', $source_gap_labels ) . '.' : ' Các trường nguồn chính đã có giá trị trong Batch.' ) . ( $formula_gap_labels ? ' Một số công thức chưa tính vì thiếu: ' . implode( ', ', $formula_gap_labels ) . '.' : '' ) . ' Giá VAT bằng 0 không được dùng làm mẫu số.' ); ?></p>
<?php if ( ! $progress['terminal'] && in_array( (string) ( $pipeline['status'] ?? '' ), array( 'SUCCESS', 'WARNING' ), true ) ) : ?><p><?php echo esc_html__( 'Kết quả Excel đã có, nhưng đối chiếu Shopee chưa có bằng chứng hoàn tất. Hệ thống không tự gọi lại Shopee nếu lượt gọi trước không rõ kết quả; cần kiểm tra trạng thái kỹ thuật của Batch.', 'ecomkit-vuikhoe' ); ?></p><?php endif; ?>
</section>
<?php if ( $pipeline_active ) : ?><script>
(function () {
    const card = document.getElementById('ecomkit-progress');
    if (!card) return;
    const byId = id => document.getElementById('ecomkit-progress-' + id);
    const endpoint = new URL(card.dataset.url);
    endpoint.searchParams.set('action', 'ecomkit_pipeline_progress');
    endpoint.searchParams.set('batch_id', card.dataset.batch);
    endpoint.searchParams.set('nonce', card.dataset.nonce);
    const poll = () => fetch(endpoint.toString(), {credentials: 'same-origin', cache: 'no-store'})
        .then(response => response.json())
        .then(payload => {
            if (!payload.success || !payload.data || !payload.data.progress) throw new Error('status');
            const p = payload.data.progress;
            card.dataset.status = p.status;
            byId('title').firstChild.textContent = (p.status === 'ERROR' ? 'XỬ LÝ DỪNG' : !p.terminal ? 'Xử lý dữ liệu Shopee chưa hoàn tất' : p.status === 'WARNING' ? 'HOÀN TẤT VỚI CẢNH BÁO' : 'HOÀN TẤT') + ' — ';
            byId('percent').textContent = p.percent;
            byId('bar').value = p.percent;
            byId('stage').textContent = p.stage_label;
            byId('import').textContent = `Đã đọc: ${p.orders} đơn, ${p.items} dòng sản phẩm · Shopee ${p.shopee} · Lazada ${p.lazada}`;
            byId('reconcile').textContent = `Đối chiếu Shopee: ${p.reconcile_done}/${p.shopee} · Khớp: ${p.matched}`;
            byId('detail').textContent = `Chi tiết đơn Shopee: ${p.detail_done}/${p.matched} · Thiếu: ${p.detail_missing}`;
            byId('payment').textContent = `Payment: ${p.payment_done}/${p.payment_total} · Lấy mới: ${p.payment_fetched} · Dùng lại: ${p.payment_reused} · Lỗi: ${p.payment_failed}`;
            byId('income').textContent = `Income: ${p.income_done}/${p.income_total} truy vấn · ${p.income_label}`;
            byId('canonical').textContent = `Kết quả: ${p.canonical_done}/${p.orders} đơn · 24 cột`;
            byId('warnings').textContent = `Cảnh báo: ${p.warning_count} · Lỗi: ${p.error_count}`;
            byId('worker').textContent = p.terminal ? 'Đã kết thúc' : 'Đang hoạt động';
            byId('updated').textContent = p.updated_local || '—';
            byId('alert').textContent = p.stalled ? 'Tiến trình chưa cập nhật; có thể đang chờ WordPress Cron. Không tự gọi lại Shopee.' : p.status === 'ERROR' ? 'Tiến trình dừng ở bước này; dữ liệu đã lưu vẫn được giữ nguyên.' : '';
            byId('source-gap').hidden = !p.terminal || p.status === 'ERROR';
            if (p.terminal) { window.location.reload(); return; }
            window.setTimeout(poll, 2500);
        })
        .catch(() => {
            byId('alert').textContent = 'Chưa thể đọc tiến độ. Trang sẽ thử lại; không có lệnh Shopee nào được gửi từ đây.';
            window.setTimeout(poll, 5000);
        });
    window.setTimeout(poll, 2500);
})();
</script><?php endif; endif; ?>
<?php if ( is_array( $pipeline ) ) : foreach ( array_unique( array_merge( (array) ( $pipeline['warnings'] ?? array() ), (array) ( $pipeline['errors'] ?? array() ) ) ) as $pipeline_code ) : ?><p><?php echo esc_html( Ecomkit_Vuikhoe_Auto_Pipeline::operator_message( (string) $pipeline_code ) ); ?></p><?php endforeach; endif; ?>
<?php if ( isset( $_GET['materialize_notice'] ) ) : ?><div class="notice notice-success inline"><p><?php echo esc_html__( 'Đã tạo/làm mới kết quả 24 cột.', 'ecomkit-vuikhoe' ); ?></p></div><?php endif; ?>
<?php if ( isset( $_GET['materialize_error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html__( 'Không thể tạo kết quả chuẩn hóa. Dữ liệu nguồn không bị thay đổi.', 'ecomkit-vuikhoe' ); ?></p></div><?php endif; ?>
<?php if ( is_array( $result ) ) : $batch = $result['batch']; $counts = $result['counts']; ?>
<?php $lazada_progress = ( new Ecomkit_Vuikhoe_Lazada_Reconciliation_Service() )->batch_summary( (int) $batch['id'] ); if ( $lazada_progress['total_lazada'] > 0 ) : ?><p><?php echo esc_html( sprintf( 'Đối chiếu Lazada: %d/%d · Khớp: %d · Không khớp: %d · Lỗi: %d · Còn chờ: %d', $lazada_progress['processed'], $lazada_progress['eligible_count'], $lazada_progress['matched'], $lazada_progress['unmatched'], $lazada_progress['errors'], $lazada_progress['pending'] ) ); ?></p><?php endif; ?>
<?php $reconciliation = $result['reconciliation']; if ( isset( $_GET['reconcile_notice'] ) && is_array( $reconciliation ) && 'SUCCESS' === (string) ( $reconciliation['status'] ?? '' ) ) : ?><div class="notice notice-success inline"><p><?php echo esc_html__( 'Đã hoàn tất lượt đối chiếu Shopee.', 'ecomkit-vuikhoe' ); ?></p></div><?php elseif ( isset( $_GET['reconcile_notice'] ) ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html__( 'Đối chiếu Shopee chưa hoàn tất đầy đủ.', 'ecomkit-vuikhoe' ); ?></p></div><?php endif; ?>
<h2><?php echo esc_html( sprintf( 'Batch #%d — %s', (int) $batch['id'], (string) $batch['source_filename'] ) ); ?></h2>
<p><?php echo esc_html( sprintf( 'Batch: %s | Đơn: %d | Shopee: %d | Lazada: %d | Matched: %d | Missing: %d | Detail missing: %d | Sẵn sàng: %d | Cảnh báo: %d', (string) $batch['status'], $counts['total'], $counts['SHOPEE'], $counts['LAZADA'], $counts['MATCHED'], $counts['NOT_FOUND_IN_SHOPEE'], $counts['DETAIL_MISSING'], $counts['ready'], $counts['warnings'] ) ); ?></p>
<?php $recon_summary = is_array( $result['reconciliation'] ?? null ) ? $result['reconciliation'] : array(); $recon_class = Ecomkit_Vuikhoe_Shopee_Reconciliation_Service::classify_summary( $recon_summary ); if ( $recon_class && (int) ( $recon_summary['excel_shopee_count'] ?? 0 ) > 0 ) : $recon_windows = (array) ( $recon_summary['windows'] ?? array() ); $recon_dates = array_map( static fn( array $window ): string => (string) ( $window['start_date'] ?? '' ) . ( ( $window['end_date'] ?? '' ) !== ( $window['start_date'] ?? '' ) ? ' – ' . (string) ( $window['end_date'] ?? '' ) : '' ), $recon_windows ); ?>
<div class="notice notice-warning inline" style="padding:12px 16px"><p><strong><?php echo esc_html__( 'Đối chiếu Shopee cần chú ý', 'ecomkit-vuikhoe' ); ?></strong></p>
<p><?php echo esc_html( match ( $recon_class ) { 'SHOPEE_RECON_PROVIDER_WINDOW_EMPTY' => 'Không tìm thấy đơn Shopee nào trong khoảng ngày truy vấn.', 'SHOPEE_RECON_ZERO_INTERSECTION' => 'Đã nhận dữ liệu từ Shopee nhưng không có Mã đơn sàn nào trùng chính xác với Excel.', 'SHOPEE_RECON_PARTIAL_MATCH' => 'Chỉ một phần Mã đơn sàn trong Excel trùng chính xác với Shopee.', default => 'Chưa thể kết luận đơn vắng mặt vì dữ liệu Shopee chưa được duyệt hết.' } ); ?></p>
<p><?php echo esc_html( sprintf( 'Excel Shopee: %d đơn · Shopee trong khoảng ngày: %d đơn · Khớp: %d · Khoảng ngày Excel: %s', (int) ( $recon_summary['excel_shopee_count'] ?? 0 ), (int) ( $recon_summary['provider_count'] ?? 0 ), (int) ( $recon_summary['matched_count'] ?? 0 ), implode( ', ', $recon_dates ) ) ); ?></p>
<?php if ( ! empty( $recon_summary['shop_id'] ) ) : ?><p><?php echo esc_html( 'Shop đang sử dụng: ' . (string) $recon_summary['shop_id'] . '. Hãy xác nhận file thuộc đúng shop này; đây là gợi ý kiểm tra, chưa phải nguyên nhân đã xác định.' ); ?></p><?php endif; ?>
<?php if ( 0 === (int) ( $recon_summary['matched_count'] ?? 0 ) ) : ?><p><?php echo esc_html__( 'Không có đơn Shopee khớp nên hệ thống không gọi Order Detail, Payment/Escrow hoặc Income cho các đơn này. Các giá trị Excel vẫn có trong Result; nguồn Excel nội bộ thiếu giá trị được thông báo riêng.', 'ecomkit-vuikhoe' ); ?></p><?php endif; ?>
</div><?php endif; ?>
<details <?php if ( isset( $_GET['lazada_reconcile_notice'] ) || isset( $_GET['lazada_reconcile_error'] ) ) { echo 'open'; } ?>><summary><?php echo esc_html__( 'Công cụ quản trị nâng cao', 'ecomkit-vuikhoe' ); ?></summary>
<p><?php echo esc_html__( 'Chỉ dùng khi cần kiểm tra kỹ thuật. Quy trình thông thường tự xử lý sau một lần tải Excel.', 'ecomkit-vuikhoe' ); ?></p>
<?php if ( current_user_can( 'manage_options' ) ) : ?>
<?php require __DIR__ . '/lazada-batch-reconciliation.php'; ?>
<h2><?php echo esc_html__( 'Xuất trạng thái xử lý Batch', 'ecomkit-vuikhoe' ); ?></h2>
<p><?php echo esc_html__( 'Chỉ đọc metadata đã lưu của Batch đang chọn và trạng thái kết nối/Cron hiện tại. Không gọi Shopee hoặc thay đổi tiến trình.', 'ecomkit-vuikhoe' ); ?></p>
<form id="ecomkit-batch-state-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ecomkit_batch_state_audit' ) ); ?>">
<input type="hidden" name="action" value="ecomkit_batch_state_export"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_batch_state_export', 'ecomkit_batch_state_nonce' ); ?>
<button type="button" class="button button-secondary" id="ecomkit-batch-state-inspect"><?php echo esc_html__( 'Xem trạng thái Batch', 'ecomkit-vuikhoe' ); ?></button> <?php submit_button( __( 'Xuất JSON trạng thái Batch', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?>
</form><div id="ecomkit-batch-state-output" role="status" aria-live="polite"></div>
<script>
(function () {
    const form = document.getElementById('ecomkit-batch-state-form');
    const button = document.getElementById('ecomkit-batch-state-inspect');
    const output = document.getElementById('ecomkit-batch-state-output');
    if (!form || !button || !output) return;
    const section = (title, data) => {
        const heading = document.createElement('h3'); heading.textContent = title; output.appendChild(heading);
        const table = document.createElement('table'); table.className = 'widefat striped';
        const body = document.createElement('tbody');
        Object.entries(data).forEach(([key, value]) => {
            const row = document.createElement('tr'); const label = document.createElement('th'); const cell = document.createElement('td');
            label.textContent = key; cell.textContent = value === null ? 'null' : typeof value === 'object' ? JSON.stringify(value) : String(value);
            row.append(label, cell); body.appendChild(row);
        });
        table.appendChild(body); output.appendChild(table);
    };
    button.addEventListener('click', async () => {
        button.disabled = true; output.replaceChildren();
        const body = new URLSearchParams({action: 'ecomkit_batch_state_audit', nonce: form.dataset.nonce, batch_id: form.elements.batch_id.value});
        try {
            const response = await fetch(form.dataset.ajax, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body});
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data) throw new Error('Không thể đọc trạng thái Batch.');
            section('Pipeline', payload.data.auto_pipeline);
            section('Shopee reconciliation', payload.data.shopee_reconciliation);
            section('CURRENT_CONNECTION_STATE', payload.data.connection_current);
            section('Provider request evidence', payload.data.diagnostic_interpretation_inputs);
        } catch (error) { const notice = document.createElement('p'); notice.textContent = error.message; output.appendChild(notice); }
        finally { button.disabled = false; }
    });
})();
</script>
<?php endif; ?>
<?php if ( is_array( $result['reconciliation'] ) ) : ?><p><?php echo esc_html( sprintf( 'Đối chiếu Shopee: %s | provider_windows_executed: %d | shopee_api_calls: %d', (string) ( $result['reconciliation']['status'] ?? '' ), (int) ( $result['reconciliation']['provider_windows_executed'] ?? 0 ), (int) ( $result['reconciliation']['shopee_api_calls'] ?? 0 ) ) ); ?></p><?php endif; ?>
<h2><?php echo esc_html__( 'Kiểm tra nguồn phí Shopee', 'ecomkit-vuikhoe' ); ?></h2>
<p><?php echo esc_html__( 'Chỉ đọc Payment snapshot đã lưu cho mã đơn sàn chính xác trong Batch này. Không gọi Shopee, không cập nhật dữ liệu. Nhập tối đa 10 mã, mỗi dòng một mã.', 'ecomkit-vuikhoe' ); ?></p>
<form id="ecomkit-fee-audit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( 'ecomkit_shopee_fee_audit' ) ); ?>">
<input type="hidden" name="action" value="ecomkit_shopee_fee_audit_export"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_shopee_fee_audit_export', 'ecomkit_fee_audit_nonce' ); ?>
<label for="ecomkit-fee-audit-ids"><?php echo esc_html__( 'Mã đơn sàn Shopee', 'ecomkit-vuikhoe' ); ?></label><br><textarea id="ecomkit-fee-audit-ids" name="order_sns" rows="3" cols="35" required placeholder="Mỗi dòng một order_sn"></textarea><br>
<button type="button" class="button button-secondary" id="ecomkit-fee-audit-inspect"><?php echo esc_html__( 'Kiểm tra nguồn phí Shopee', 'ecomkit-vuikhoe' ); ?></button> <?php submit_button( __( 'Xuất JSON chẩn đoán phí', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?>
</form>
<div id="ecomkit-fee-audit-output" role="status" aria-live="polite"></div>
<script>
(function () {
    const form = document.getElementById('ecomkit-fee-audit-form');
    const output = document.getElementById('ecomkit-fee-audit-output');
    const button = document.getElementById('ecomkit-fee-audit-inspect');
    if (!form || !output || !button) return;
    const line = (parent, text, tag = 'p') => { const node = document.createElement(tag); node.textContent = text; parent.appendChild(node); return node; };
    const table = (parent, rows, exactPaths) => {
        const node = document.createElement('table'); node.className = 'widefat striped';
        const head = document.createElement('thead'); const tr = document.createElement('tr');
        ['Field path', 'Value', 'Type'].forEach(label => line(tr, label, 'th')); head.appendChild(tr); node.appendChild(head);
        const body = document.createElement('tbody');
        rows.forEach(item => { const row = document.createElement('tr');
            line(row, item.path, 'td');
            const value = line(row, item.value === null ? '—' : String(item.value), 'td');
            if (exactPaths.has(item.path)) { value.textContent += ' — trùng chính xác 5700'; value.style.fontWeight = 'bold'; }
            line(row, item.type, 'td'); body.appendChild(row);
        }); node.appendChild(body); parent.appendChild(node);
    };
    button.addEventListener('click', async () => {
        output.replaceChildren(); line(output, 'Đang đọc snapshot Payment đã lưu…'); button.disabled = true;
        const body = new URLSearchParams(); body.set('action', 'ecomkit_shopee_fee_audit');
        body.set('nonce', form.dataset.ajaxNonce); body.set('batch_id', form.elements.batch_id.value);
        body.set('order_sns', form.elements.order_sns.value);
        try {
            const response = await fetch(form.dataset.ajax, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body });
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data || !Array.isArray(payload.data.orders)) throw new Error('Không thể đọc snapshot Payment trong Batch này.');
            output.replaceChildren();
            payload.data.orders.forEach(order => {
                line(output, 'Order SN: ' + order.marketplace_order_id, 'h3');
                line(output, 'Payment fetched at: ' + (order.payment_fetched_at || '—') + ' · request_id: ' + (order.payment_request_id || '—'));
                const origin = order.financial_paths.find(item => item.path === 'payment_raw_data.order_income.service_fee');
                line(output, 'Phí Shopee gốc ← serviceFee ← payment_raw_data.order_income.service_fee: ' + (origin ? String(origin.value) : '—') + ' (normalized: ' + (order.normalized_financial.serviceFee === null ? '—' : String(order.normalized_financial.serviceFee)) + '). Phí dịch vụ legacy canonical được tính riêng khi đủ PiShip và chiết khấu nội bộ.');
                line(output, 'Exact 5700 matches: ' + (order.exact_5700_matches.length ? order.exact_5700_matches.map(item => item.path).join(', ') : 'NONE'));
                const normalized = Object.entries(order.normalized_financial).map(([key, value]) => ({path: 'payment_normalized_data.' + key, value, type: value === null ? 'null' : typeof value}));
                table(output, normalized.concat(order.financial_paths), new Set(order.exact_5700_matches.map(item => item.path)));
            });
        } catch (error) { output.replaceChildren(); line(output, error.message || 'Không thể đọc snapshot Payment.'); }
        finally { button.disabled = false; }
    });
})();
</script>
<?php if ( is_array( $progress ) && in_array( $progress['status'], array( 'WARNING', 'ERROR' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_pipeline_resume"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_pipeline_resume', 'ecomkit_pipeline_nonce' ); ?><?php submit_button( __( 'Tiếp tục xử lý sau khi kiểm tra cảnh báo', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?></form><?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_vuikhoe_materialize_results"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_vuikhoe_materialize_results', 'ecomkit_result_nonce' ); ?><?php submit_button( __( 'Tạo / Làm mới kết quả 24 cột', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?></form>
<h2><?php echo esc_html__( 'Tài chính Shopee', 'ecomkit-vuikhoe' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_shopee_financial_enrich"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_shopee_financial_enrich', 'ecomkit_financial_nonce' ); ?><label><input type="checkbox" name="refresh_existing" value="1"> <?php echo esc_html__( 'Làm mới cả snapshot Payment đã có (tối đa một call cho mỗi đơn MATCHED)', 'ecomkit-vuikhoe' ); ?></label> <?php submit_button( __( 'Cập nhật tài chính Shopee', 'ecomkit-vuikhoe' ), 'primary', '', false ); ?></form>
<?php if ( is_array( $data['financial_run'] ?? null ) ) : $run = $data['financial_run']; ?><div class="notice <?php echo 'SUCCESS' === $run['status'] ? 'notice-success' : 'notice-warning'; ?> inline"><p><?php echo esc_html( sprintf( 'Tài chính Shopee: %s | Đủ điều kiện: %d | Đã lấy: %d | Tái sử dụng: %d | Lỗi: %d | Provider calls: %d | Canonical rematerialized: %d', $run['status'], $run['eligible'], $run['fetched'], $run['reused'], $run['failed'], $run['provider_calls'], $run['canonical_rematerialized'] ) ); ?></p><?php foreach ( $run['failures'] as $failure ) : ?><p><?php echo esc_html( 'Order ID ' . (string) ( $failure['order_id'] ?? '—' ) . ': ' . (string) $failure['classification'] ); ?></p><?php endforeach; ?></div><?php endif; ?>
<h2><?php echo esc_html__( 'Kiểm tra Payment/Escrow', 'ecomkit-vuikhoe' ); ?></h2>
<p><?php echo esc_html__( 'Mỗi lần bấm chỉ gửi một POST cho một đơn Shopee đã MATCHED. Để cập nhật Result sau khi kiểm tra, materialize lại từ snapshot đã lưu.', 'ecomkit-vuikhoe' ); ?></p>
<?php if ( ! empty( $data['payment_orders'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_shopee_payment_test"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_shopee_payment_test', 'ecomkit_payment_nonce' ); ?><label for="payment-order-id"><?php echo esc_html__( 'Đơn Shopee đã khớp', 'ecomkit-vuikhoe' ); ?></label> <select id="payment-order-id" name="order_id" required><?php foreach ( $data['payment_orders'] as $payment_order ) : ?><option value="<?php echo esc_attr( (string) $payment_order['id'] ); ?>"><?php echo esc_html( (string) $payment_order['marketplace_order_id'] ); ?></option><?php endforeach; ?></select> <?php submit_button( __( 'Kiểm tra Payment/Escrow', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?></form><?php else : ?><p><?php echo esc_html__( 'Batch này chưa có đơn Shopee MATCHED để kiểm tra.', 'ecomkit-vuikhoe' ); ?></p><?php endif; ?>
<?php if ( is_array( $data['payment_test'] ?? null ) ) : $payment_test = $data['payment_test']; ?><div class="notice <?php echo $payment_test['ok'] ? 'notice-success' : 'notice-warning'; ?> inline"><p><?php echo esc_html( $payment_test['ok'] ? 'Payment/Escrow POST thành công; chỉ lưu snapshot Payment.' : (string) ( $payment_test['classification'] ?? 'SHOPEE_PAYMENT_ERROR' ) ); ?></p><?php if ( $payment_test['ok'] ) : $payment_data = $payment_test['data']; $financial = $payment_data['normalized']; ?><p><?php echo esc_html( 'Order SN: ' . (string) $payment_data['order_sn'] . ' | request_id: ' . (string) $payment_data['request_id'] ); ?></p><table class="widefat striped"><tbody><?php foreach ( array( 'escrowAmount', 'escrowAmountAfterAdjustment', 'buyerTotalAmount', 'commissionFee', 'serviceFee', 'sellerTransactionFee', 'affiliateCommissionFee', 'totalAdjustmentAmount', 'estimatedShippingFee', 'actualShippingFee', 'shopeeShippingRebate', 'buyerPaymentMethod', 'currency' ) as $field ) : ?><tr><th><?php echo esc_html( $field ); ?></th><td><?php echo null === ( $financial[ $field ] ?? null ) ? '&mdash;' : esc_html( (string) $financial[ $field ] ); ?></td></tr><?php endforeach; ?></tbody></table><?php else : $diagnostic = $payment_test['diagnostic'] ?? array(); ?><table class="widefat striped"><tbody><?php foreach ( $diagnostic as $key => $value ) : ?><tr><th><?php echo esc_html( $key ); ?></th><td><?php echo esc_html( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div><?php endif; ?>
<h2><?php echo esc_html__( 'Kiểm tra Income Status', 'ecomkit-vuikhoe' ); ?></h2>
<p><?php echo esc_html__( 'Chỉ kiểm tra một đơn Shopee MATCHED; tối đa 10 trang, 30 bản ghi/trang. PENDING là danh sách hiện tại: ngày gửi bắt buộc nhưng KHÔNG lọc kết quả. RELEASED lọc theo ngày giải ngân (tối đa 14 ngày). Không thay đổi canonical.', 'ecomkit-vuikhoe' ); ?></p>
<?php if ( ! empty( $data['payment_orders'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ecomkit_shopee_income_test"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><?php wp_nonce_field( 'ecomkit_shopee_income_test', 'ecomkit_income_nonce' ); ?><label for="income-order-id"><?php echo esc_html__( 'Đơn Shopee đã khớp', 'ecomkit-vuikhoe' ); ?></label> <select id="income-order-id" name="order_id" required><?php foreach ( $data['payment_orders'] as $income_order ) : ?><option value="<?php echo esc_attr( (string) $income_order['id'] ); ?>"><?php echo esc_html( (string) $income_order['marketplace_order_id'] ); ?></option><?php endforeach; ?></select> <label for="income-bucket"><?php echo esc_html__( 'Trạng thái truy vấn', 'ecomkit-vuikhoe' ); ?></label> <select id="income-bucket" name="income_bucket"><option value="PENDING">PENDING</option><option value="RELEASED">RELEASED</option></select> <label><?php echo esc_html__( 'Từ ngày RELEASED', 'ecomkit-vuikhoe' ); ?> <input type="date" name="date_from"></label> <label><?php echo esc_html__( 'Đến ngày RELEASED', 'ecomkit-vuikhoe' ); ?> <input type="date" name="date_to"></label> <?php submit_button( __( 'Kiểm tra Income Status', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?></form><?php endif; ?>
<?php if ( is_array( $data['income_test'] ?? null ) ) : $income_test = $data['income_test']; ?><div class="notice <?php echo $income_test['ok'] && 'FOUND' === ( $income_test['data']['classification'] ?? '' ) ? 'notice-success' : 'notice-warning'; ?> inline"><p><?php echo esc_html( $income_test['ok'] ? (string) $income_test['data']['classification'] : (string) $income_test['classification'] ); ?></p><?php if ( $income_test['ok'] ) : $income_data = $income_test['data']; ?><p><?php echo esc_html( 'Order SN: ' . (string) $income_data['order_sn'] . ' | request_id: ' . (string) $income_data['request_id'] . ' | pages: ' . (string) $income_data['pages'] ); ?></p><?php if ( 'FOUND' === $income_data['classification'] ) : ?><table class="widefat striped"><tbody><?php foreach ( array( 'incomeBucket', 'providerStatus', 'currency', 'paymentMethod', 'estimatedEscrowAmount', 'estimatedPayoutTime', 'releasedAmount', 'actualPayoutTime', 'description' ) as $field ) : $value = $income_data['normalized'][ $field ] ?? null; if ( in_array( $field, array( 'estimatedEscrowAmount', 'releasedAmount' ), true ) && ( is_int( $value ) || is_string( $value ) ) ) { $value = Ecomkit_Vuikhoe_Money_Formatter::format_exact( $value ); } elseif ( in_array( $field, array( 'estimatedPayoutTime', 'actualPayoutTime' ), true ) && is_string( $value ) ) { $timestamp = strtotime( $value ); $value = false !== $timestamp ? wp_date( 'Y-m-d H:i:s T', $timestamp ) : $value; } ?><tr><th><?php echo esc_html( $field ); ?></th><td><?php echo null === $value ? '&mdash;' : esc_html( (string) $value ); ?></td></tr><?php endforeach; ?></tbody></table><?php elseif ( 'SHOPEE_INCOME_EMPTY' === $income_data['classification'] ) : ?><p><?php echo esc_html__( 'Shopee trả về thành công nhưng chưa có Income record cho truy vấn này.', 'ecomkit-vuikhoe' ); ?></p><?php else : ?><p><?php echo esc_html__( 'Không thấy đơn trong truy vấn trạng thái/ngày này sau khi duyệt hết trang; không có kết luận về sự tồn tại của đơn ở Shopee.', 'ecomkit-vuikhoe' ); ?></p><?php endif; ?><?php else : ?><table class="widefat striped"><tbody><?php foreach ( $income_test['diagnostic'] as $key => $value ) : ?><tr><th><?php echo esc_html( $key ); ?></th><td><?php echo esc_html( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div><?php endif; ?>
</details>
<form method="get" style="margin:16px 0"><input type="hidden" name="page" value="ecomkit-vuikhoe-results"><input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch['id'] ); ?>"><label><?php echo esc_html__( 'Nền tảng', 'ecomkit-vuikhoe' ); ?> <select name="platform"><option value="">ALL</option><?php foreach ( array( 'SHOPEE', 'LAZADA' ) as $value ) : ?><option <?php selected( $data['platform_filter'], $value ); ?>><?php echo esc_html( $value ); ?></option><?php endforeach; ?></select></label> <label><?php echo esc_html__( 'Matching', 'ecomkit-vuikhoe' ); ?> <select name="matching"><option value="">ALL</option><?php foreach ( array( 'MATCHED', 'NOT_FOUND_IN_SHOPEE', 'DETAIL_MISSING', '__BLANK__' ) as $value ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $data['matching_filter'], $value ); ?>><?php echo esc_html( '__BLANK__' === $value ? '(blank)' : $value ); ?></option><?php endforeach; ?></select></label> <?php submit_button( __( 'Lọc', 'ecomkit-vuikhoe' ), 'secondary', '', false ); ?></form>
<div class="notice notice-info inline"><p><?php echo esc_html__( 'Kết quả v9 ưu tiên Giá SP, Affiliate và Chiết Khấu Vui Khỏe từ Excel; Shopee Payment đúng đơn cung cấp fallback khi nguồn tương ứng thiếu. Phí dịch vụ dùng Phí Hạ Tầng suy ra từ serviceFee cộng PiShip. Ba tỷ lệ legacy hiển thị với 2 chữ số thập phân HALF-UP; ratio canonical không đổi. Công nợ/Chênh lệch chưa có quy tắc duyệt.', 'ecomkit-vuikhoe' ); ?></p></div>
<?php
$clipboard_values = Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( $result );
$clipboard_headers = Ecomkit_Vuikhoe_Canonical_Result_Service::clipboard_tsv( array( 'rows' => array() ), true );
$clipboard_count = count( $result['rows'] );
$clipboard_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$unmapped_status_count = 0;
foreach ( $result['rows'] as $clipboard_row ) {
	$status = Ecomkit_Vuikhoe_Shopee_Business_Status::resolve( (string) $clipboard_row['platform'], (string) ( $clipboard_row['columns']['order_status'] ?? '' ) );
	if ( null !== $status['diagnostic'] ) { $unmapped_status_count++; }
}
?>
<section style="margin:12px 0;padding:12px;border:1px solid #c3c4c7;background:#fff" aria-label="Bổ sung tên và địa chỉ từ PDF nhãn Shopee">
<h3 style="margin-top:0"><?php echo esc_html__( 'Bổ sung thông tin khách hàng từ PDF nhãn Shopee (tùy chọn)', 'ecomkit-vuikhoe' ); ?></h3>
<label for="ecomkit-spx-pdf"><?php echo esc_html__( 'Chọn PDF nhãn Shopee', 'ecomkit-vuikhoe' ); ?></label>
<input type="file" id="ecomkit-spx-pdf" accept=".pdf,application/pdf" aria-label="Chọn PDF nhãn Shopee">
<button type="button" class="button" id="ecomkit-clear-spx-pdf" disabled><?php echo esc_html__( 'Xóa dữ liệu PDF tạm', 'ecomkit-vuikhoe' ); ?></button>
<p style="margin-bottom:0"><?php echo esc_html__( 'PDF chỉ được dùng tạm để bổ sung dữ liệu khi sao chép và không được lưu trên hệ thống. Dữ liệu PDF tự mất khi tải lại trang.', 'ecomkit-vuikhoe' ); ?></p>
<p id="ecomkit-spx-summary" role="status" aria-live="polite"></p>
</section>
<?php if ( $unmapped_status_count > 0 ) : ?><p class="description"><?php echo esc_html( sprintf( 'UNMAPPED_SHOPEE_BUSINESS_STATUS: %d trạng thái Shopee chưa đủ bằng chứng để đổi sang nhãn nghiệp vụ; giữ nguyên trạng thái gốc khi sao chép.', $unmapped_status_count ) ); ?></p><?php endif; ?>
<div style="margin:12px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
<button type="button" class="button button-primary" id="ecomkit-copy-values" <?php disabled( 0 === $clipboard_count ); ?>><?php echo esc_html__( 'Sao chép dữ liệu', 'ecomkit-vuikhoe' ); ?></button>
<button type="button" class="button" id="ecomkit-copy-with-headers" <?php disabled( 0 === $clipboard_count ); ?>><?php echo esc_html__( 'Sao chép kèm tiêu đề', 'ecomkit-vuikhoe' ); ?></button>
<span id="ecomkit-copy-feedback" role="status" aria-live="polite"></span>
</div>
<script>
(function () {
    const values = <?php echo wp_json_encode( $clipboard_values, $clipboard_json_flags ); ?>;
    const headers = <?php echo wp_json_encode( $clipboard_headers, $clipboard_json_flags ); ?>;
    const count = <?php echo (int) $clipboard_count; ?>;
    const maxPdfBytes = <?php echo (int) min( Ecomkit_Vuikhoe_Shopee_SPX_Labels::MAX_BYTES, wp_max_upload_size() ); ?>;
    const feedback = document.getElementById('ecomkit-copy-feedback');
    const summary = document.getElementById('ecomkit-spx-summary');
    const input = document.getElementById('ecomkit-spx-pdf');
    const clear = document.getElementById('ecomkit-clear-spx-pdf');
    const enrichment = new Map(); // Page memory only; never browser storage or HTML.
    function currentValues() {
        if (!enrichment.size) return values;
        return values.split('\n').map(line => {
            const cells = line.split('\t');
            const recipient = cells[3] === 'SHOPEE' ? enrichment.get(cells[2]) : null;
            if (recipient) { cells[5] = recipient.name; cells[7] = recipient.address; }
            return cells.join('\t');
        }).join('\n');
    }
    input.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) return;
        enrichment.clear(); clear.disabled = true;
        if (file.size > maxPdfBytes) { summary.textContent = 'PDF vượt quá giới hạn cho phép.'; this.value = ''; return; }
        summary.textContent = 'Đang đọc PDF nhãn Shopee…';
        try {
            const form = new FormData();
            form.append('action', 'ecomkit_shopee_spx_pdf');
            form.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'ecomkit_shopee_spx_pdf' ), $clipboard_json_flags ); ?>);
            form.append('batch_id', <?php echo (int) $batch['id']; ?>);
            form.append('platform', <?php echo wp_json_encode( $data['platform_filter'], $clipboard_json_flags ); ?>);
            form.append('matching', <?php echo wp_json_encode( $data['matching_filter'], $clipboard_json_flags ); ?>);
            form.append('pdf', file);
            const response = await fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ), $clipboard_json_flags ); ?>, {method: 'POST', body: form, credentials: 'same-origin', cache: 'no-store'});
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data) throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không thể đọc PDF.');
            for (const [id, recipient] of Object.entries(payload.data.enrichment || {})) {
                if (typeof recipient.name === 'string' && typeof recipient.address === 'string') enrichment.set(id, {name: recipient.name, address: recipient.address});
            }
            clear.disabled = enrichment.size === 0;
            const data = payload.data;
            summary.textContent = 'Đã đọc: ' + data.pages + ' trang, ' + data.parsed + ' nhãn · Khớp Result: ' + data.matched + ' · Tên được bổ sung: ' + data.names_enriched + ' · Địa chỉ được bổ sung: ' + data.addresses_enriched + ' · Không khớp: ' + data.unmatched + (data.duplicate_conflicts ? ' · Trùng mã mâu thuẫn: ' + data.duplicate_conflicts : '') + (data.matched ? '' : ' Đã đọc PDF nhưng không có mã đơn nào khớp Batch hiện tại.');
        } catch (error) { summary.textContent = error.message || 'PDF không hợp lệ.'; }
        finally { this.value = ''; }
    });
    clear.addEventListener('click', function () {
        enrichment.clear(); input.value = ''; clear.disabled = true;
        summary.textContent = 'Đã xóa dữ liệu PDF tạm. Lần sao chép tiếp theo dùng giá trị Ecomkit.';
    });
    async function copyPlainText(value) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            try { await navigator.clipboard.writeText(value); return; } catch (error) { /* Try the text-only fallback. */ }
        }
        const area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        try {
            area.focus(); area.select();
            if (!document.execCommand('copy')) throw new Error('COPY_FAILED');
        } finally { area.remove(); }
    }
    for (const [id, withHeaders] of [['ecomkit-copy-values', false], ['ecomkit-copy-with-headers', true]]) {
        document.getElementById(id).addEventListener('click', async function () {
            this.disabled = true;
            try {
                const data = currentValues();
                await copyPlainText(withHeaders ? headers + '\n' + data : data);
                feedback.textContent = withHeaders ? 'Đã sao chép ' + count + ' đơn + tiêu đề.' : 'Đã sao chép ' + count + ' đơn × 24 cột.';
            } catch (error) { feedback.textContent = 'Không thể sao chép. Vui lòng kiểm tra quyền clipboard của trình duyệt.'; }
            finally { this.disabled = false; }
        });
    }
})();
</script>
<div style="overflow-x:auto;margin-top:12px"><table class="widefat striped" style="width:max-content;min-width:100%"><thead><tr><?php foreach ( $result['columns'] as $column ) : ?><th><?php echo esc_html( $column['label'] ); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ( $result['rows'] as $row ) : ?><tr title="<?php echo esc_attr( (string) $row['platform'] . ' / ' . (string) $row['matching_status'] . ( $row['stale'] ? ' / Kết quả cũ' : ( ! $row['ready'] ? ' / Chưa materialize' : '' ) ) ); ?>"><?php foreach ( $result['columns'] as $column ) : $value = $row['columns'][ $column['key'] ] ?? null; $rational = $row['rational'][ $column['key'] ] ?? null; $display = is_array( $rational ) ? Ecomkit_Vuikhoe_Exact_Financial_Math::format_percent_two_decimals( $rational ) : ( Ecomkit_Vuikhoe_Canonical_Columns::is_money( $column['key'] ) && ( is_int( $value ) || is_string( $value ) ) ? Ecomkit_Vuikhoe_Money_Formatter::format_exact( $value ) : $value ); $formula_state = $row['formula_state'][ $column['key'] ] ?? null; $cell_title = is_array( $formula_state ) && 'MISSING_OPERANDS' === ( $formula_state['status'] ?? '' ) ? 'Chưa tính: thiếu ' . implode( ', ', array_map( static fn( string $key ): string => $canonical_labels[ $key ] ?? $key, (array) ( $formula_state['fields'] ?? array() ) ) ) : ( is_array( $formula_state ) && 'ZERO_DIVISOR' === ( $formula_state['status'] ?? '' ) ? 'Chưa tính: Giá SP bằng 0' : '' ); ?><td title="<?php echo esc_attr( $cell_title ); ?>"><?php echo null === $display || '' === $display ? '&mdash;' : esc_html( (string) $display ); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php elseif ( $data['batch_id'] ) : ?><p><?php echo esc_html__( 'Không tìm thấy Batch Excel.', 'ecomkit-vuikhoe' ); ?></p><?php else : ?><p><?php echo esc_html__( 'Chọn một Batch để xem hoặc tạo kết quả 24 cột.', 'ecomkit-vuikhoe' ); ?></p><?php endif; ?></div>
