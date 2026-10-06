(function () {
    'use strict';
    const root = document.getElementById('ecomkit-lazada-order-diagnostic');
    if (!root) return;
    const form = root.querySelector('[data-lazada-diagnostic]');
    const status = root.querySelector('[data-lazada-status]');
    const output = root.querySelector('[data-lazada-output]');
    const summary = root.querySelector('[data-lazada-summary]');
    let busy = false;
    function render(data) {
        const checks = data.checks || {};
        const order = checks.order;
        const items = checks.items;
        const list = checks.orders;
        const lines = ['Kết nối Lazada: Sẵn sàng', 'Mã đơn: ' + (data.order_id || 'Chưa nhập')];
        if (order) {
            if (order.success) {
                lines.push('Đơn Lazada: Tìm thấy', 'Đối chiếu mã: ' + (order.data.comparison === 'MATCH' ? 'KHỚP' : 'Mã đơn Lazada không khớp.'), 'Chi tiết đơn: Thành công', 'Trạng thái API: ' + JSON.stringify(order.data.raw_statuses || []), 'PII: ' + ({AVAILABLE: 'Có', MASKED: 'Bị ẩn', MISSING: 'Không có'}[order.data.pii.classification] || 'Không có'));
            } else lines.push(order.message);
        }
        if (items) lines.push(items.success ? 'Sản phẩm: ' + items.data.item_count : items.message);
        if (list) lines.push(list.success ? 'Danh sách provider (một trang): ' + ({MATCH: 'Có mã đơn chính xác', 'NO MATCH': 'Không có mã đơn trong trang trả về', UNVERIFIED: 'Đã nhận ' + list.data.order_count + ' đơn; chưa đối chiếu mã'}[list.data.comparison]) : list.message);
        if (Object.values(checks).some(check => check.classification === 'LAZADA_ORDER_AUTH_REQUIRED')) lines[0] = 'Kết nối Lazada: Token cần được ủy quyền lại';
        summary.textContent = lines.join('\n');
    }
    form.addEventListener('change', function (event) {
        if (event.target.name === 'connection_id') {
            output.textContent = ''; summary.textContent = '';
            status.textContent = 'Đã đổi shop. Bấm kiểm tra để xác nhận mã đơn trong shop này.';
        }
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) return;
        const id = form.elements.order_id.value;
        if ((id && !/^[0-9]{1,40}$/.test(id)) || (!id && !form.elements.check_list.checked)) {
            status.textContent = 'Nhập mã đơn Lazada chính xác hoặc chọn kiểm tra danh sách theo ngày.'; return;
        }
        if (form.elements.check_list.checked && !form.elements.order_date.value) {
            status.textContent = 'Chọn ngày đơn để kiểm tra danh sách.'; return;
        }
        const data = new FormData(form);
        busy = true;
        status.textContent = 'Đang kiểm tra Lazada…';
        output.textContent = ''; summary.textContent = '';
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store'});
            const text = await response.text();
            let result;
            try { result = JSON.parse(text); } catch (_) {
                // Never display arbitrary PHP/HTML bodies: they may contain secrets or buyer data.
                if (response.status === 401 || response.status === 403 || text.trim() === '-1') status.textContent = 'Không có quyền kiểm tra hoặc phiên đã hết hạn. Tải lại trang và đăng nhập lại.';
                else if (/Fatal error|Parse error|Uncaught|critical error/i.test(text)) status.textContent = 'Máy chủ gặp lỗi PHP khi kiểm tra (HTTP ' + response.status + '). Xem nhật ký máy chủ đã che thông tin nhạy cảm.';
                else status.textContent = 'Máy chủ trả phản hồi không phải JSON (HTTP ' + response.status + '). Không tự động thử lại.';
                return;
            }
            if (!result || typeof result.success !== 'boolean' || !result.data || typeof result.data !== 'object') {
                if (response.status === 401 || response.status === 403 || text.trim() === '-1') {
                    status.textContent = 'Không có quyền kiểm tra hoặc phiên đã hết hạn. Tải lại trang và đăng nhập lại.'; return;
                }
                status.textContent = 'Phản hồi JSON không đúng cấu trúc kiểm tra (HTTP ' + response.status + ').'; return;
            }
            // Backend returns only allowlisted, redacted evidence. All values remain text.
            output.textContent = JSON.stringify(result.data, null, 2);
            if (result.success) {
                render(result.data);
                status.textContent = 'Đã kiểm tra Lazada. Xem kết quả từng API bên dưới.';
            } else status.textContent = result.data.message || (response.status === 403 ? 'Không có quyền kiểm tra hoặc phiên đã hết hạn.' : 'Không thể kiểm tra đơn Lazada. Xem Chi tiết kỹ thuật.');
        } catch (_) {
            status.textContent = 'Không kết nối được máy chủ kiểm tra. Kiểm tra mạng; không tự động thử lại.';
        } finally { busy = false; }
    });
}());
