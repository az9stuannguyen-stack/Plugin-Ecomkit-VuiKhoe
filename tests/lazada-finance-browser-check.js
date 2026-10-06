'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {readResponse, render} = require('../ecomkit-vuikhoe/assets/js/lazada-finance-diagnostic.js');
class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; }
    append(child) { this.children.push(child); }
    replaceChildren() { this.children = []; }
}
global.document = {createElement: tag => new Element(tag)};
const response = (raw, status = 200) => ({status, ok: status < 400, text: async () => raw});
(async () => {
    const id = '987654321098765432109876543210';
    const data = {order_id: id, checks: {transactions: {success: true, data: {matched_count: 1, page_count: 1, records: [{order_no: id, amount: '-12345678901234567890.12345678901234567890', fee_name: '<img src=x onerror=alert(1)>', canonical_candidate: 'UNKNOWN'}], diagnostic: {api_path: '/finance/transaction/detail/get'}, next_offset: null}}}, distinct_names: []};
    assert.equal((await readResponse(response(JSON.stringify({success: true, data})))).order_id, id);
    await assert.rejects(readResponse(response('<html>private</html>', 404)), /HTTP 404/);
    await assert.rejects(readResponse(response('0', 403)), /không hợp lệ/);
    await assert.rejects(readResponse(response(JSON.stringify({success:false,data:{message:'Safe permission denied'}}),403)), /Safe permission denied/);
    await assert.rejects(readResponse({text: async () => {throw new Error('network');}}), /network/);
    const output = new Element('div'); render(output, data);
    const nodes = node => [node, ...node.children.flatMap(nodes)];
    assert(nodes(output).some(n => n.textContent === id));
    assert(nodes(output).some(n => n.textContent === '-12345678901234567890.12345678901234567890'));
    assert(nodes(output).some(n => n.textContent === '<img src=x onerror=alert(1)>'));
    assert(!nodes(output).some(n => n.tag === 'img'));
    let submitted; let calls = 0; let request;
    const button = {disabled: false};
    const form = {action: 'https://example.test/subsite/wp-admin/admin-ajax.php', querySelector: () => button, parentElement: {querySelector: () => output}, addEventListener: (_, fn) => {submitted = fn;}};
    class FakeFormData extends Array { constructor() {super(['action', 'ecomkit_lazada_finance_diagnostic'], ['nonce', 'synthetic'], ['order_id', id]);} }
    const browser = {document: {querySelectorAll: () => [form], createElement: tag => new Element(tag)}, FormData: FakeFormData, URLSearchParams, fetch: async (url, options) => {calls++; request = {url, options}; return response(JSON.stringify({success:true,data}));}};
    vm.runInNewContext(fs.readFileSync(require.resolve('../ecomkit-vuikhoe/assets/js/lazada-finance-diagnostic.js'), 'utf8'), browser);
    await submitted({preventDefault(){}});
    assert.equal(calls, 1); assert.equal(request.url, form.action); assert.equal(request.options.method, 'POST'); assert.equal(request.options.credentials, 'same-origin'); assert.equal(request.options.body.get('order_id'), id); assert.equal(button.disabled, false);
    browser.fetch = async () => {calls++; throw new Error('network');};
    await submitted({preventDefault(){}}); assert.equal(calls, 2); assert.match(output.textContent, /network/);
    console.log('lazada-finance-browser-check: PASS (JSON/error/non-JSON/network, exact strings, text-only rendering)');
})().catch(e => { console.error(e); process.exitCode = 1; });
