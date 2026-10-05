(function () {
    'use strict';
    const root = document.getElementById('ecomkit-lazada-order-diagnostic');
    if (!root) return;
    const form = root.querySelector('[data-lazada-diagnostic]');
    const status = root.querySelector('[data-lazada-status]');
    const output = root.querySelector('[data-lazada-output]');
    const ids = document.getElementById('ecomkit-lazada-returned-ids');
    let busy = false;
    form.addEventListener('change', function (event) {
        if (event.target.name === 'connection_id') {
            ids.replaceChildren();
            form.elements.order_id.value = '';
            output.textContent = '';
            status.textContent = 'Đã đổi shop. Đọc lại danh sách để chọn đúng ID trong shop này.';
        }
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) return;
        const operation = event.submitter ? event.submitter.value : 'orders';
        if (operation !== 'orders' && !/^[0-9]{1,40}$/.test(form.elements.order_id.value)) {
            status.textContent = 'Chọn/copy Order ID chính xác từ danh sách trước.';
            return;
        }
        const data = new FormData(form);
        data.set('operation', operation);
        busy = true;
        status.textContent = 'Đang kiểm tra Lazada… Không tự động thử lại.';
        output.textContent = '';
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store'});
            const result = await response.json();
            // textContent only: provider text is never interpreted as HTML.
            output.textContent = JSON.stringify(result.data, null, 2);
            status.textContent = result.success ? 'Đã nhận bằng chứng. Chưa xác nhận FULL PASS live cho đến khi kiểm tra đủ 3 API.' : 'Kiểm tra thất bại. Xem mã lỗi/bằng chứng; không suy đoán nguyên nhân.';
            if (result.success && operation === 'orders') {
                ids.replaceChildren();
                (result.data.orders || []).forEach(function (order) {
                    const option = document.createElement('option');
                    option.value = order.providerOrderId; // Exact string, no numeric coercion.
                    ids.appendChild(option);
                });
                if (result.data.orders && result.data.orders.length) form.elements.order_id.value = result.data.orders[0].providerOrderId;
            }
        } catch (_) {
            status.textContent = 'Không đọc được phản hồi kiểm tra. Không tự động thử lại.';
        } finally {
            busy = false;
        }
    });
}());
