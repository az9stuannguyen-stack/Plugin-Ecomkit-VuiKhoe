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
        const diagFields = ['api_path', 'http_method', 'http_status', 'provider_code', 'safe_provider_message', 'request_id', 'response_container'];
        text('p', `Chế độ: ${result.audit_mode === 'scan' ? 'Quét giao dịch theo ngày' : 'Theo mã đơn'} · Order ID: ${result.order_id || '—'} · Candidate canonical: UNKNOWN · Không ghi tài chính/24 cột.`);
        if (result.transport) table([result.transport], ['layer', 'handler_reached', 'permission_passed', 'nonce_passed', 'finance_client_invoked', 'plugin_version']);
        for (const [stage, check] of Object.entries(result.checks || {})) {
            text('h3', `${stage}: ${check.success ? 'PASS' : 'FAIL'}`);
            if (!check.success) { text('p', check.classification || 'UNKNOWN'); table([check.evidence || {}], diagFields); continue; }
            const data = check.data;
            if (stage === 'transactions') {
                if (result.audit_mode === 'scan') {
                    text('p', `Provider: PASS · Provider records returned: ${data.page_count} · Normalized: ${data.normalized_count ?? data.matched_count} · Normalization errors: ${data.normalization_error_count ?? 0}`);
                    text('p', 'ORDER LINKAGE NOT AVAILABLE FROM ACCOUNT TRANSACTION API');
                    if (data.normalization_error_count) text('p', 'Lazada đã trả dữ liệu thành công nhưng có bản ghi Ecomkit chưa đọc được.');
                    if (data.normalization_errors?.length) table(data.normalization_errors, ['transaction_index', 'field', 'observed_type', 'expected_type', 'code', 'classification', 'observed_fields']);
                    if (data.normalization_warnings?.length) table(data.normalization_warnings, ['transaction_index', 'field', 'observed_type', 'expected_type', 'code']);
                    if (data.field_inventory?.length) {
                        const details = document.createElement('details'); const title = document.createElement('summary'); title.textContent = 'Cấu trúc trường quan sát (không có giá trị thô)'; details.append(title);
                        data.field_inventory.forEach(record => { const p = document.createElement('p'); p.textContent = `Transaction index ${record.transaction_index}: ` + record.fields.map(field => `${field.field}: ${field.observed_type} (${field.classification})`).join(', '); details.append(p); }); output.append(details);
                    }
                }
                text('p', `Giao dịch hiển thị: ${data.matched_count} · Returned count: ${data.page_count} · ${result.audit_mode === 'scan' ? 'Page: ' + data.page_num : 'Offset: ' + data.offset} · Coverage: ${data.coverage} · Offset tiếp theo: ${scalar(data.next_page ?? data.next_offset)}`);
                table(data.records, result.audit_mode === 'scan' ? ['type', 'sub_type', 'amount', 'currency', 'pmt_reference', 'transaction_number', 'transaction_time', 'linkage_scope'].filter(key => data.field_inventory ? key === 'linkage_scope' || data.field_inventory.some(record => record.fields.some(field => field.field === key)) : true) : ['transaction_type', 'fee_type', 'fee_name', 'amount', 'currency', 'order_no', 'orderItem_no', 'reference', 'linkage_scope', 'ecomkit_presence', 'ecomkit_references', 'transaction_date', 'statement', 'paid_status', 'transaction_number', 'VAT_in_amount', 'WHT_amount', 'WHT_included_in_amount', 'orderItem_status', 'canonical_candidate']);
                if (data.page_info) table([data.page_info], ['page_num', 'page_size', 'total_page', 'total_count']);
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
        table(result.distinct_names, ['fee_type', 'fee_name', 'transaction_type', 'sub_type', 'frequency', 'positive', 'negative', 'zero', 'missing_amount', 'scopes', 'meaning', 'confidence']);
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {readResponse, render};
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-lazada-finance]').forEach(form => {
        const next = form.querySelector('[data-lazada-finance-next]');
        let nextOffset = null; let lastPayload = null;
        const clearNext = () => { nextOffset = null; lastPayload = null; if (next) next.hidden = true; };
        const mode = form.querySelector('[name="audit_mode"]');
        if (mode && mode.tagName === 'SELECT') {
            const updateMode = () => {
                const scan = mode.value === 'scan';
                form.querySelector('[name="order_id"]').required = !scan;
                const endpoint = form.querySelector('[name="endpoint"]'); if (scan) endpoint.value = 'details'; endpoint.disabled = scan;
                const page = form.querySelector('[name="page_num"]'); if (page) page.parentElement.hidden = !scan;
                const offset = form.querySelector('[name="offset"]'); if (offset) offset.parentElement.hidden = scan;
                const orderCheck = form.querySelector('[name="check_order"]'); orderCheck.disabled = scan; if (scan) orderCheck.checked = false;
                clearNext();
            };
            mode.addEventListener('change', updateMode); updateMode();
            form.addEventListener('change', clearNext);
        }
        async function run(payload) {
            const button = form.querySelector('button[type="submit"]'); const output = form.parentElement.querySelector('[data-lazada-finance-output]');
            if (button.disabled) return; button.disabled = true; output.textContent = 'Đang kiểm tra nguồn tài chính Lazada…';
            if (next) next.hidden = true;
            try {
                // input[name=action] shadows form.action in the real browser DOM.
                const response = await fetch(form.getAttribute('action'), {method: 'POST', credentials: 'same-origin', body: payload, headers: {'Accept': 'application/json'}});
                const result = await readResponse(response); render(output, result);
                const offset = result.checks?.transactions?.data?.next_page ?? result.checks?.transactions?.data?.next_offset;
                nextOffset = Number.isInteger(offset) && offset > 0 && offset <= 1000000 ? offset : null;
                lastPayload = new URLSearchParams(payload);
                if (next) next.hidden = nextOffset === null;
            } catch (error) { clearNext(); output.textContent = `Không thể hoàn tất kiểm tra: ${error.message || 'Lỗi mạng'}. Không tự động thử lại.`; }
            finally { button.disabled = false; }
        }
        form.addEventListener('submit', async event => {
            event.preventDefault(); await run(new URLSearchParams(new FormData(form)));
        });
        if (next && typeof next.addEventListener === 'function') next.addEventListener('click', async () => {
            if (nextOffset === null || !lastPayload) return;
            const payload = new URLSearchParams(lastPayload); payload.set(lastPayload.get('audit_mode') === 'scan' ? 'page_num' : 'offset', String(nextOffset));
            payload.delete('check_payout'); payload.delete('check_order'); // Next page reads Finance transactions only.
            const offsetInput = form.querySelector(lastPayload.get('audit_mode') === 'scan' ? '[name="page_num"]' : '[name="offset"]'); if (offsetInput) offsetInput.value = String(nextOffset);
            await run(payload);
        });
    });
}());
