// Real Chromium DOM; fake fetch, no WordPress/provider network calls.
const fs = require('fs'); const os = require('os'); const path = require('path'); const assert = require('assert'); const {spawnSync} = require('child_process');
const browser = process.env.ECOMKIT_TEST_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ecomkit-finance-scan-'));
const script = fs.readFileSync(path.join(__dirname, '../ecomkit-vuikhoe/assets/js/lazada-finance-diagnostic.js'), 'utf8');
const endpoint = 'https://wordpress.example/subsite/wp-admin/admin-ajax.php';
const html = `<!doctype html><meta charset="utf-8"><div><form data-lazada-finance action="${endpoint}">
<input name="action" value="ecomkit_lazada_finance_diagnostic"><input name="nonce" value="synthetic"><input name="connection_id" value="1">
<select name="audit_mode"><option value="order">Order</option><option value="scan">Scan</option></select>
<input name="order_id" value=""><input name="start_date" value="2026-09-01"><input name="end_date" value="2026-10-06">
<select name="endpoint"><option value="detail">Detail</option><option value="details">Details</option></select><label><input name="offset" value="0"></label><label><input name="page_num" value="1"></label><input name="check_order" type="checkbox" value="1">
<button type="submit">Run</button><button type="button" data-lazada-finance-next hidden>Next</button></form><div data-lazada-finance-output></div></div><pre id="proof"></pre>
<script>
const proof={calls:[]};window.fetch=async(url,options)=>{
 proof.calls.push({url,method:options.method,payload:Object.fromEntries(options.body)});
 return {status:200,ok:true,text:async()=>JSON.stringify({success:true,data:{audit_mode:'scan',order_id:'',checks:{transactions:{success:true,data:{records:[{pmt_reference:'987654321098765432109876543210',amount:'-12345678901234567890.12345678901234567890',ecomkit_presence:'Có trong Ecomkit',ecomkit_references:['Batch #31 / Order #1']}],matched_count:1,page_count:proof.calls.length===1?100:1,page_num:options.body.get('page_num'),next_page:proof.calls.length===1?2:null,diagnostic:{}}}},distinct_names:[]}})};
};</script><script>${script}</script><script>
const form=document.querySelector('form');const mode=form.querySelector('[name=audit_mode]');const next=form.querySelector('[data-lazada-finance-next]');
proof.orderRequiredInitially=form.querySelector('[name=order_id]').required;
mode.value='scan';mode.dispatchEvent(new Event('change',{bubbles:true}));
proof.scanRequired=form.querySelector('[name=order_id]').required;proof.endpoint=form.querySelector('[name=endpoint]').value;
form.dispatchEvent(new Event('submit',{cancelable:true}));
setTimeout(()=>{proof.beforeNext=proof.calls.length;proof.nextVisible=!next.hidden;next.click();setTimeout(()=>{proof.nextHidden=next.hidden;proof.text=document.querySelector('[data-lazada-finance-output]').textContent;document.getElementById('proof').textContent=JSON.stringify(proof)},0)},0);
</script>`;
try {
 const file=path.join(dir,'fixture.html');fs.writeFileSync(file,html);
 const run=spawnSync(browser,['--headless','--disable-gpu','--no-first-run','--dump-dom','--virtual-time-budget=1000','--user-data-dir='+path.join(dir,'profile'),'file:///'+file.replace(/\\/g,'/')],{encoding:'utf8',timeout:30000});
 if(run.error)throw run.error;assert.strictEqual(run.status,0,run.stderr);
 const match=run.stdout.match(/<pre id="proof">([^<]+)<\/pre>/);assert(match,run.stdout);const proof=JSON.parse(match[1].replace(/&amp;/g,'&'));
 assert(proof.orderRequiredInitially && !proof.scanRequired && proof.endpoint==='details');
 assert.strictEqual(proof.beforeNext,1);assert.strictEqual(proof.calls.length,2);assert(proof.nextVisible && proof.nextHidden);
 assert.strictEqual(proof.calls[0].url,endpoint);assert.strictEqual(proof.calls[0].payload.audit_mode,'scan');assert.strictEqual(proof.calls[0].payload.order_id,'');assert.strictEqual(proof.calls[0].payload.offset,'0');assert.strictEqual(proof.calls[1].payload.page_num,'2');
 assert(proof.text.includes('987654321098765432109876543210') && proof.text.includes('-12345678901234567890.12345678901234567890'));
 console.log('lazada-finance-discovery-dom-check: PASS (real mode controls, blank ID, explicit next page, exact display; zero real calls)');
} finally {fs.rmSync(dir,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
