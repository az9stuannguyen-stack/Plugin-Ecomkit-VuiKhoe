// Real Chromium DOM; fake fetch only. Reproduce the old named-control URL bug,
// then run the actual diagnostic script against the same form. No provider calls.
const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert');
const {spawnSync} = require('child_process');
const browser = process.env.ECOMKIT_TEST_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ecomkit-lazada-dom-'));
const script = fs.readFileSync(path.join(__dirname, '../ecomkit-vuikhoe/assets/js/lazada-finance-diagnostic.js'), 'utf8');
const endpoint = 'https://wordpress.example/subsite/wp-admin/admin-ajax.php';
const html = `<!doctype html><meta charset="utf-8"><base href="https://wordpress.example/subsite/wp-admin/admin.php?page=ecomkit">
<div id="ecomkit-lazada-order-diagnostic"><form data-lazada-finance action="${endpoint}">
<input name="action" value="ecomkit_lazada_finance_diagnostic"><input name="nonce" value="synthetic-nonce">
<input name="connection_id" value="1"><input name="order_date" type="date" value="2026-10-03">
<input name="order_id" value="532935709720247"><input name="offset" value="0"><input name="check_list" type="checkbox" value="1">
<button type="submit">Audit</button></form><div data-lazada-finance-output></div></div><pre id="proof"></pre>
<script>
const form = document.querySelector('form');
const proof = {old_property_tag: form.action.tagName, old_fetch_argument: String(form.action), old_url: new URL(String(form.action), document.baseURI).href};
window.fetch = async (url, options) => {
 proof.url = url; proof.method = options.method; proof.action = options.body.get('action'); proof.nonce = options.body.get('nonce'); proof.order_id = options.body.get('order_id');
 return {status:200, headers:new Headers({'X-Ecomkit-Lazada-Diagnostic':'handler'}),text:async()=>JSON.stringify({success:true,data:{order_id:'532935709720247',checks:{},transport:{handler_reached:true}}})};
};
</script><script>${script}</script><script>
form.dispatchEvent(new Event('submit',{cancelable:true}));
setTimeout(()=>{document.getElementById('proof').textContent=JSON.stringify(proof)},0);
</script>`;
try {
 const file = path.join(dir, 'fixture.html'); fs.writeFileSync(file, html);
 const run = spawnSync(browser, ['--headless', '--disable-gpu', '--no-first-run', '--dump-dom', '--virtual-time-budget=1000', '--user-data-dir=' + path.join(dir,'profile'), 'file:///' + file.replace(/\\/g,'/')], {encoding:'utf8', timeout:30000});
 if (run.error) throw run.error;
 assert.strictEqual(run.status, 0, run.stderr);
 const match = run.stdout.match(/<pre id="proof">([^<]+)<\/pre>/); assert(match, run.stdout);
 const proof = JSON.parse(match[1].replace(/&amp;/g,'&'));
 assert.strictEqual(proof.old_property_tag,'INPUT');
 assert.strictEqual(proof.old_fetch_argument,'[object HTMLInputElement]');
 assert.strictEqual(proof.old_url,'https://wordpress.example/subsite/wp-admin/[object%20HTMLInputElement]');
 assert.strictEqual(proof.url,endpoint); assert.strictEqual(proof.method,'POST');
 assert.strictEqual(proof.action,'ecomkit_lazada_finance_diagnostic'); assert.strictEqual(proof.nonce,'synthetic-nonce');
 assert.strictEqual(proof.order_id,'532935709720247');
 console.log('lazada-finance-dom-check: PASS (old URL bug reproduced; actual JS sends WordPress URL; zero real calls)');
} finally { fs.rmSync(dir,{recursive:true,force:true,maxRetries:5,retryDelay:100}); }
