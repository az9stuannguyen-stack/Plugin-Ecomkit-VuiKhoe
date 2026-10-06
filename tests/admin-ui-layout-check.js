// Real Chromium layout/screenshots of actual PHP views with synthetic data only.
const fs=require('fs'),os=require('os'),path=require('path'),assert=require('assert'),{spawnSync}=require('child_process');
const dir=process.env.ECOMKIT_UI_DIR||path.join(os.tmpdir(),'ecomkit-ui-6k2');
const php=process.env.ECOMKIT_TEST_PHP||path.join(os.tmpdir(),'ecomkit-php-8.3.35','php.exe');
const chrome=process.env.ECOMKIT_TEST_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const gen=spawnSync(php,['-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=mbstring',path.join(__dirname,'admin-ui-render-check.php')],{env:{...process.env,ECOMKIT_UI_DIR:dir},encoding:'utf8'});assert.strictEqual(gen.status,0,gen.stdout+gen.stderr);
for(const width of [1366,1920])for(const page of ['dashboard','process','results','errors','history','marketplace','settings','progress']){
 const source=fs.readFileSync(path.join(dir,page+'.html'),'utf8');
 const proof=`<pre id="layout-proof"></pre><script>document.getElementById('layout-proof').textContent=JSON.stringify({width:innerWidth,documentWidth:document.documentElement.scrollWidth,columns:document.querySelectorAll('.ecomkit-result-table thead th').length,headers:document.querySelectorAll('.ecomkit-page-header h1').length,scroll:document.querySelector('.ecomkit-result-table')?getComputedStyle(document.querySelector('.ecomkit-result-table').parentElement).overflowX:null});</script>`;
 const file=path.join(dir,page+'-'+width+'.html');fs.writeFileSync(file,source+proof);
 const result=spawnSync(chrome,['--headless','--disable-gpu','--no-first-run','--window-size='+width+',1080','--dump-dom','--screenshot='+path.join(dir,page+'-'+width+'.png'),'file:///'+file.replace(/\\/g,'/')],{encoding:'utf8',timeout:30000});assert.strictEqual(result.status,0,result.stderr);
 const match=result.stdout.match(/<pre id="layout-proof">([^<]+)<\/pre>/);assert(match,result.stdout);const data=JSON.parse(match[1]);assert.strictEqual(data.headers,1,page+' duplicate/missing title');assert(data.documentWidth<=data.width,page+' viewport overflow');if(page==='results'||page==='progress'){assert.strictEqual(data.columns,24);assert.strictEqual(data.scroll,'auto');}
}
console.log('admin-ui-layout-check: PASS (8 rendered views at 1366/1920, viewport containment, 24 columns, screenshots; zero provider calls)');
