const fs=require('fs'),vm=require('vm'),assert=require('assert');
const handlers={},status={textContent:''},output={textContent:''},summary={textContent:''};
const form={action:'/admin-ajax.php',elements:{order_id:{value:'987654321098765432109876543210'},check_list:{checked:false},order_date:{value:''}},addEventListener(k,v){handlers[k]=v;}};
const root={querySelector(s){return {'[data-lazada-diagnostic]':form,'[data-lazada-status]':status,'[data-lazada-output]':output,'[data-lazada-summary]':summary}[s];}};
class FormData{constructor(f){this.values={order_id:f.elements.order_id.value};}}
let calls=0,body='',http=200,network=false;
vm.runInNewContext(fs.readFileSync(__dirname+'/../ecomkit-vuikhoe/assets/js/lazada-order-diagnostic.js','utf8'),{document:{getElementById(){return root;}},FormData,fetch:async(u,a)=>{calls++;assert.strictEqual(a.body.values.order_id,form.elements.order_id.value);if(network)throw Error('network');return {status:http,text:async()=>body};}});
async function submit(){await handlers.submit({preventDefault(){}});}
(async()=>{
body=JSON.stringify({success:true,data:{order_id:form.elements.order_id.value,checks:{order:{success:true,data:{comparison:'MATCH',raw_statuses:['<img>'],pii:{classification:'MASKED'}}},items:{success:true,data:{item_count:2}}}}});await submit();assert(summary.textContent.includes('<img>'));assert(summary.textContent.includes('2'));assert.strictEqual(calls,1);
body=JSON.stringify({success:false,data:{message:'Safe provider error'}});http=400;await submit();assert.strictEqual(status.textContent,'Safe provider error');
body='-1';http=403;await submit();assert(status.textContent.includes('quyền'));
body='<b>Fatal error</b> SECRET';http=500;await submit();assert(status.textContent.includes('PHP'));assert(!output.textContent.includes('SECRET'));
body='<html>SECRET</html>';await submit();assert(status.textContent.includes('không phải JSON'));
body='null';await submit();assert(status.textContent.includes('cấu trúc'));
network=true;await submit();assert(status.textContent.includes('mạng'));assert.strictEqual(calls,7);
form.elements.check_list.checked=true;await submit();assert.strictEqual(calls,7);
console.log('lazada-diagnostic-browser-check: PASS (JSON/provider/permission/PHP/non-JSON/network; no retries)');
})().catch(e=>{console.error(e);process.exitCode=1;});
