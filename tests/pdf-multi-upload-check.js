// Run the real Result script with synthetic DOM/transport; no customer files or network.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const view = fs.readFileSync(__dirname + '/../ecomkit-vuikhoe/admin/views/results.php', 'utf8');
assert(/id="ecomkit-spx-pdf"[^>]*\bmultiple\b/.test(view), 'File chooser does not support multiple PDFs');
const rows = ['ORDER-A', 'ORDER-B', 'ORDER-C', 'ORDER-D'].map(id => {
    const cells = Array(24).fill('');
    cells[2] = id; cells[3] = 'SHOPEE'; cells[5] = 'Existing ' + id; cells[6] = '09***'; cells[7] = 'Existing address'; cells[14] = '312000';
    return cells.join('\t');
});
const base = rows.join('\n');
let script = [...view.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).find(text => text.includes('function currentValues()'));
assert(script, 'Result clipboard script missing');
script = script.replace(/<\?php[\s\S]*?\?>/g, '"synthetic"')
    .replace(/const values = .*?;/, 'const values = ' + JSON.stringify(base) + ';')
    .replace(/const headers = .*?;/, 'const headers = "Headers";')
    .replace(/const count = .*?;/, 'const count = 4;')
    .replace(/const maxPdfBytes = .*?;/, 'const maxPdfBytes = 104857600;')
    .replace(/const maxPdfFiles = .*?;/, 'const maxPdfFiles = 50;');
const elements = new Map();
function element() {
    return {disabled: false, textContent: '', value: '', files: [], children: [], listeners: {},
        addEventListener(type, handler) { this.listeners[type] = handler; },
        appendChild(child) { this.children.push(child); }, replaceChildren() { this.children = []; }};
}
const document = {getElementById(id) { if (!elements.has(id)) elements.set(id, element()); return elements.get(id); }, createElement: element};
class FormData { constructor() { this.data = new Map(); } append(key, value) { this.data.set(key, value); } }
const responses = []; const requests = []; let active = 0; let maxActive = 0; let clipboard = null;
const context = {document, FormData, AbortController, navigator: {clipboard: {async writeText(text) { clipboard = text; }}},
    async fetch(url, options) {
        requests.push(options.body.data.get('pdf').name); active++; maxActive = Math.max(maxActive, active);
        try { const next = responses.shift(); assert(next, 'Unexpected provider request'); return typeof next === 'function' ? await next(options) : next; }
        finally { active--; }
    }};
vm.runInNewContext(script, context);
const input = elements.get('ecomkit-spx-pdf'); const clear = elements.get('ecomkit-clear-spx-pdf'); const copy = elements.get('ecomkit-copy-values');
function file(name, size = 1024) { return {name, size}; }
function response(enrichment, extra = {}) { return {ok: true, status: 200, async json() { return {success: true, data: {pages: 1, parsed: 1, unmatched: 0, enrichment, ...extra}}; }}; }
function recipient(name) { return {name, address: 'Address ' + name}; }
async function select(files) { input.files = files; return input.listeners.change.call(input); }
async function copied() { await copy.listeners.click.call(copy); return clipboard.split('\n').map(row => row.split('\t')); }
async function main() {
    responses.push(response({'ORDER-A': recipient('Alpha')}, {pages: 78, parsed: 78}), response({'ORDER-B': recipient('Beta')}));
    await select([file('morning.pdf', 6 * 1024 * 1024), file('afternoon.pdf')]);
    assert.deepStrictEqual(requests, ['morning.pdf', 'afternoon.pdf']); assert.strictEqual(maxActive, 1, 'Files uploaded concurrently');
    let copiedRows = await copied(); assert.strictEqual(copiedRows[0][5], 'Alpha'); assert.strictEqual(copiedRows[1][5], 'Beta');
    assert(elements.get('ecomkit-spx-summary').textContent.includes('79 trang')); assert(!input.disabled && !copy.disabled);
    responses.push(response({'ORDER-A': recipient('Alpha')})); await select([file('same-labels.pdf')]);
    copiedRows = await copied(); assert.strictEqual(copiedRows[1][5], 'Beta', 'Selecting additional files discarded prior enrichment');
    responses.push(response({'ORDER-A': recipient('Different')}), response({'ORDER-A': recipient('Alpha')}));
    await select([file('conflict.pdf'), file('repeat-original.pdf')]);
    copiedRows = await copied(); assert.strictEqual(copiedRows[0][5], 'Existing ORDER-A', 'Cross-file conflict was overwritten/reintroduced');
    responses.push(response({}, {conflicted_order_ids: ['ORDER-B']})); await select([file('internal-conflict.pdf')]);
    copiedRows = await copied(); assert.strictEqual(copiedRows[1][5], 'Existing ORDER-B', 'Conflict within one PDF was overwritten by another file');
    responses.push({ok: false, status: 400, async json() { return {success: false, data: {message: 'Unsupported label template'}}; }}, response({'ORDER-C': recipient('Gamma')}));
    await select([file('too-large.pdf', 104857601), file('lazada.pdf'), file('good-after-failure.pdf')]);
    copiedRows = await copied(); assert.strictEqual(copiedRows[2][5], 'Gamma', 'A failed file stopped the remaining queue');
    assert.strictEqual(elements.get('ecomkit-pdf-errors').children.length, 2); assert(!requests.includes('too-large.pdf'));
    for (const row of copiedRows) { assert.strictEqual(row.length, 24); assert.strictEqual(row[6], '09***'); assert.strictEqual(row[14], '312000'); }
    const previousRequests = requests.length; await select(Array(51).fill(file('many.pdf'))); assert.strictEqual(requests.length, previousRequests);
    assert.strictEqual((await copied())[2][5], 'Gamma', 'Rejected selection lost valid enrichment');
    responses.push({ok: false, status: 413}, {ok: true, status: 200, async json() { throw Error('HTML instead of JSON'); }}, response({'ORDER-D': recipient('Delta')}));
    await select([file('host-cap.pdf'), file('timeout.pdf'), file('last-good.pdf')]);
    assert.strictEqual((await copied())[3][5], 'Delta', 'Hosting failure stopped the queue');

    // Clear while a request is in flight; even a late successful response must not restore PII.
    let resolveLate; responses.push(() => new Promise(resolve => { resolveLate = resolve; }));
    const pending = select([file('late.pdf')]); assert(copy.disabled && input.disabled);
    clear.listeners.click.call(clear); assert(!copy.disabled && !input.disabled);
    resolveLate(response({'ORDER-A': recipient('Late')})); await pending;
    await copied(); assert.strictEqual(clipboard, base, 'Clear allowed a late response to repopulate PDF data');
    assert.strictEqual(elements.get('ecomkit-pdf-errors').children.length, 0);
    responses.push(response({'ORDER-A': recipient('Fresh')})); await select([file('fresh.pdf')]);
    assert.strictEqual((await copied())[0][5], 'Fresh', 'Clear did not reset conflicts for a new selection');
    assert(!responses.length && active === 0);
    console.log('Multi-PDF queue, append, conflict, limits, partial failures and cancellation: PASS');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
