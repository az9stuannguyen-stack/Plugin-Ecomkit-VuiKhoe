// Synthetic DOM/HTTP: exact string IDs, text-only output and explicit no-retry behavior.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const handlers = {};
const status = {textContent: ''};
const output = {textContent: ''};
const ids = {children: [], replaceChildren() { this.children = []; }, appendChild(v) { this.children.push(v); }};
const form = {action: '/wp-admin/admin-ajax.php', elements: {order_id: {value: ''}}, addEventListener(name, fn) { handlers[name] = fn; }};
const root = {querySelector(s) { return ({'[data-lazada-diagnostic]': form, '[data-lazada-status]': status, '[data-lazada-output]': output})[s]; }};
class FakeFormData { constructor(f) { this.values = {order_id: f.elements.order_id.value}; } set(k,v) { this.values[k] = v; } }
let calls = [];
let reply = {success: true, data: {orders: [{providerOrderId: '987654321098765432109876543210'}]}};
const context = {FormData: FakeFormData, document: {getElementById(id) { return id === 'ecomkit-lazada-returned-ids' ? ids : root; }, createElement() { return {value: ''}; }}, fetch: async function(url,args) { calls.push(args); return {json: async () => reply}; }};
vm.runInNewContext(fs.readFileSync(__dirname + '/../ecomkit-vuikhoe/assets/js/lazada-order-diagnostic.js', 'utf8'), context);
async function submit(op) { await handlers.submit({preventDefault() {}, submitter: {value: op}}); }
(async () => {
    await submit('orders');
    assert.strictEqual(calls.length, 1);
    assert.strictEqual(form.elements.order_id.value, '987654321098765432109876543210');
    assert.strictEqual(ids.children[0].value, form.elements.order_id.value);
    assert.strictEqual(calls[0].credentials, 'same-origin');
    reply = {success: true, data: {order: {providerOrderId: form.elements.order_id.value, rawStatuses: ['<img src=x onerror=alert(1)>']}}};
    await submit('order');
    assert.strictEqual(calls[1].body.values.order_id, '987654321098765432109876543210');
    assert(output.textContent.includes('<img')); // Remains text, not inserted HTML.
    reply = {success: false, data: {classification: 'LAZADA_ORDER_PROVIDER_ERROR'}};
    await submit('items');
    assert.strictEqual(calls.length, 3); // No automatic retry.
    form.elements.order_id.value = '1 OR 1=1';
    await submit('order');
    assert.strictEqual(calls.length, 3);
    assert(status.textContent.includes('chính xác'));
    handlers.change({target: {name: 'connection_id'}});
    assert.strictEqual(form.elements.order_id.value, '');
    assert.strictEqual(ids.children.length, 0);
    console.log('lazada-diagnostic-browser-check: PASS');
})().catch(error => { console.error(error); process.exitCode = 1; });
