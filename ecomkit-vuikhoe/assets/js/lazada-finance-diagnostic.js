(function () {
    'use strict';
    const scalar = value => value === null || value === undefined ? '—' : (Array.isArray(value) ? value.join(', ') : String(value));
    async function readResponse(response) {
        const raw = await response.text();
        let body;
        try { body = JSON.parse(raw); } catch (_) { throw new Error(`Máy chủ trả phản hồi không phải JSON (HTTP ${response.status}). Không tự động thử lại.`); }
        if (!body || typeof body.success !== 'boolean' || !body.data || typeof body.data !== 'object') throw new Error(`Phản hồi kiểm tra không hợp lệ (HTTP ${response.status}). Không tự động thử lại.`);
        if (!response.ok || !body.success) throw new Error(body.data.message || body.data.classification || `HTTP ${response.status}`);
        return body.data;
    }
    function render(output, result) {
        output.replaceChildren();
        function text(tag, value) { const el = document.createElement(tag); el.textContent = value; output.append(el); }
        function table(rows, fields) {
            if (!rows || !rows.length) { text('p', 'Không có bản ghi trong phản hồi này; chưa kết luận phí bằng 0.'); return; }
            const t = document.createElement('table'); t.className = 'widefat striped';
            const head = document.createElement('tr'); fields.forEach(key => { const th = document.createElement('th'); th.textContent = key; head.append(th); }); t.append(head);
            rows.forEach(row => { const tr = document.createElement('tr'); fields.forEach(key => { const td = document.createElement('td'); td.textContent = scalar(row[key]); tr.append(td); }); t.append(tr); }); output.append(t);
        }
        const diagFields = ['api_path', 'http_method', 'http_status', 'provider_code', 'safe_provider_message', 'request_id'];
        text('p', `Order ID: ${result.order_id} · Candidate canonical: UNKNOWN · Không ghi tài chính/24 cột.`);
        if (result.transport) table([result.transport], ['layer', 'handler_reached', 'permission_passed', 'nonce_passed', 'finance_client_invoked', 'plugin_version']);
        for (const [stage, check] of Object.entries(result.checks || {})) {
            text('h3', `${stage}: ${check.success ? 'PASS' : 'FAIL'}`);
            if (!check.success) { text('p', check.classification || 'UNKNOWN'); table([check.evidence || {}], diagFields); continue; }
            const data = check.data;
            if (stage === 'transactions') {
                text('p', `Giao dịch khớp mã trong trang: ${data.matched_count} · Toàn trang: ${data.page_count} · Coverage: ${data.coverage} · Offset tiếp theo: ${scalar(data.next_offset)}`);
                table(data.records, ['transaction_type', 'fee_type', 'fee_name', 'amount', 'currency', 'order_no', 'orderItem_no', 'reference', 'linkage_scope', 'transaction_date', 'statement', 'paid_status', 'transaction_number', 'VAT_in_amount', 'WHT_amount', 'WHT_included_in_amount', 'orderItem_status', 'canonical_candidate']);
                table([data.diagnostic], diagFields);
            } else if (stage === 'payout') {
                text('p', `Statement của shop: ${data.provider_count} · Preview truncated: ${data.preview_truncated} · Liên kết payout→đơn: ${data.order_payout_link}`);
                table(data.statements, ['statement_number', 'paid', 'payout_amount', 'payout_currency', 'closing_balance', 'opening_balance', 'item_revenue', 'fees_total', 'refunds', 'fees_on_refunds_total', 'created_at', 'updated_at', 'scope']);
                table([data.diagnostic], diagFields);
            } else if (stage === 'order_candidates') {
                table([data], ['order_id', 'raw_statuses', 'price', 'voucher', 'shipping_fee', 'confidence']);
            } else if (stage === 'item_candidates') {
                table(data, ['order_id', 'order_item_id', 'raw_status', 'item_price', 'paid_price', 'confidence']);
            }
        }
        text('h3', 'Tên phí/type quan sát trong trang — chưa xác minh mapping');
        table(result.distinct_names, ['fee_type', 'fee_name', 'transaction_type', 'frequency', 'positive', 'negative', 'zero', 'missing_amount', 'scopes', 'meaning', 'confidence']);
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {readResponse, render};
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-lazada-finance]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault(); const button = form.querySelector('button[type="submit"]'); const output = form.parentElement.querySelector('[data-lazada-finance-output]');
            if (button.disabled) return; button.disabled = true; output.textContent = 'Đang kiểm tra nguồn tài chính Lazada…';
            try {
                // input[name=action] shadows form.action in the real browser DOM.
                const response = await fetch(form.getAttribute('action'), {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(new FormData(form)), headers: {'Accept': 'application/json'}});
                render(output, await readResponse(response));
            } catch (error) { output.textContent = `Không thể hoàn tất kiểm tra: ${error.message || 'Lỗi mạng'}. Không tự động thử lại.`; }
            finally { button.disabled = false; }
        });
    });
}());
