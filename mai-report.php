<?php
// ================================================================
// Mai's report form — PUBLIC page (no login). Access = the secret token in the link Mai sends on WhatsApp.
//   GET  mai-report.php?t=TOKEN               the step-by-step page
//   GET  mai-report.php?api=load&t=TOKEN      steps + saved answers
//   POST mai-report.php?api=save              {t, key, ans}  save one step
//   POST mai-report.php?api=submit            {t}            finish: score, apply, WhatsApp confirmation
// ================================================================
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mai-form-lib.php';

function mrJson($data, int $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

$api = $_GET['api'] ?? '';
if ($api === '') { header('Content-Type: text/html; charset=utf-8'); echo mrPage(); exit; }

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
$token = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string)($in['t'] ?? $_GET['t'] ?? '')));
if (strlen($token) !== 32) mrJson(['ok' => false, 'error' => 'This link is not valid.'], 404);

try { $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
catch (Throwable $e) { mrJson(['ok' => false, 'error' => 'Please try again in a moment.'], 503); }
$st = $pdo->prepare("SELECT * FROM mai_report_sessions WHERE token = :t AND mode = 'form' LIMIT 1");
$st->execute([':t' => $token]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) mrJson(['ok' => false, 'error' => 'This link is not valid.'], 404);

$data = json_decode((string)$session['answers'], true) ?: [];
$steps = $data['steps'] ?? []; $answers = $data['a'] ?? [];
if (!is_array($answers)) $answers = [];
$done = $session['status'] === 'completed';
$expired = !$done && $session['report_date'] < date('Y-m-d');

if ($api === 'load') {
    $out = ['ok' => true, 'name' => explode(' ', trim((string)$session['account_manager_name']))[0], 'type' => $session['report_type'], 'date' => $session['report_date'],
        'status' => $session['status'], 'expired' => $expired, 'steps' => $steps, 'a' => (object)$answers];
    if ($done) { $out['score'] = (int)$session['score']; $out['max'] = (int)$session['max_score']; $out['lines'] = maiFormSummaryLines($steps, $answers); }
    mrJson($out);
}
if ($done) mrJson(['ok' => false, 'error' => 'This report was already submitted. Thank you!'], 409);
if ($expired) mrJson(['ok' => false, 'error' => 'This link has expired. Mai will send a new one at the next check-in.'], 410);

if ($api === 'save') {
    $key = (string)($in['key'] ?? ''); $step = null;
    foreach ($steps as $s) if ($s['key'] === $key) $step = $s;
    if (!$step) mrJson(['ok' => false, 'error' => 'Unknown step.'], 400);
    $ans = is_array($in['ans'] ?? null) ? $in['ans'] : [];
    $clean = [];
    $okVals = array_column($step['choices'] ?? [], 0);
    if ($step['type'] === 'text') { $clean['text'] = mb_substr(trim((string)($ans['text'] ?? '')), 0, 1500); }
    elseif ($step['type'] === 'list') {
        $clean['items'] = []; $clean['notes'] = [];
        foreach ($step['items'] as $it) {
            $v = $ans['items'][$it['id']] ?? null;
            if (is_string($v) && in_array($v, $okVals, true)) $clean['items'][$it['id']] = $v;
            $n = trim((string)($ans['notes'][$it['id']] ?? '')); if ($n !== '') $clean['notes'][$it['id']] = mb_substr($n, 0, 300);
        }
        $clean['note'] = mb_substr(trim((string)($ans['note'] ?? '')), 0, 600);
    } else {
        $c = $ans['choice'] ?? null; if (is_string($c) && in_array($c, $okVals, true)) $clean['choice'] = $c;
        $clean['note'] = mb_substr(trim((string)($ans['note'] ?? '')), 0, 600);
    }
    $answers[$key] = $clean;
    $pdo->prepare("UPDATE mai_report_sessions SET answers = :a WHERE id = :id")->execute([':a' => json_encode(['steps' => $steps, 'a' => $answers], JSON_UNESCAPED_UNICODE), ':id' => $session['id']]);
    mrJson(['ok' => true]);
}
if ($api === 'submit') {
    foreach ($steps as $s) if (!maiFormStepDone($s, $answers[$s['key']] ?? [])) mrJson(['ok' => false, 'error' => 'Please finish every step first.', 'step' => $s['key']], 422);
    $session['answers'] = json_encode(['steps' => $steps, 'a' => $answers], JSON_UNESCAPED_UNICODE);
    [$score, $max, , $lines] = maiFormFinalize($pdo, $session);
    mrJson(['ok' => true, 'score' => $score, 'max' => $max, 'lines' => $lines]);
}
mrJson(['ok' => false, 'error' => 'Unknown request.'], 400);

function mrPage(): string {
    return <<<'HTML'
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#d90b2c">
<title>Your report · Mai</title>
<style>
:root{--bg:#f6f5f3;--card:#fff;--text:#1c1b1a;--muted:#78746e;--line:#e7e4df;--accent:#d90b2c;--ok:#12a150;--okbg:#e6f6ec;--no:#d97706;--nobg:#fdf1de}
@media (prefers-color-scheme:dark){:root{--bg:#0f1117;--card:#181b23;--text:#f1efec;--muted:#9a968f;--line:#2a2e38;--okbg:#10301f;--nobg:#3a2a0e}}
*{box-sizing:border-box}html,body{margin:0}body{background:var(--bg);color:var(--text);font:16px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;min-height:100vh}
.wrap{max-width:560px;margin:0 auto;padding:16px 16px 40px}
.top{display:flex;align-items:center;gap:10px;padding:6px 0 14px}.logo{width:34px;height:34px;border-radius:10px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800}
.top b{font-size:15px}.top small{display:block;color:var(--muted);font-size:12px}
.bar{height:6px;background:var(--line);border-radius:99px;overflow:hidden;margin:2px 0 6px}.bar i{display:block;height:100%;background:var(--accent);border-radius:99px;transition:width .3s}
.count{font-size:12px;color:var(--muted);margin-bottom:12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px}
h1{font-size:13px;letter-spacing:.07em;text-transform:uppercase;color:var(--accent);margin:0 0 6px}h2{font-size:21px;line-height:1.3;margin:0 0 16px}
.btns{display:grid;grid-template-columns:1fr 1fr;gap:10px}
button{font:inherit;cursor:pointer}
.big{padding:18px 10px;border-radius:14px;border:2px solid var(--line);background:var(--card);color:var(--text);font-weight:700;font-size:17px}
.big.on.yes{border-color:var(--ok);background:var(--okbg);color:var(--ok)}.big.on.no{border-color:var(--no);background:var(--nobg);color:var(--no)}
.item{border:1px solid var(--line);border-radius:14px;padding:12px;margin-bottom:10px}.item .n{font-weight:700}.item .s{font-size:12px;color:var(--muted);margin:2px 0 8px}
.chips{display:flex;gap:8px;flex-wrap:wrap}.chip{padding:9px 14px;border-radius:99px;border:1.5px solid var(--line);background:var(--card);color:var(--text);font-weight:600;font-size:14px}
.chip.on.ok{border-color:var(--ok);background:var(--okbg);color:var(--ok)}.chip.on.bad{border-color:var(--no);background:var(--nobg);color:var(--no)}
textarea,input[type=text]{width:100%;font:inherit;padding:12px;border-radius:12px;border:1.5px solid var(--line);background:var(--bg);color:var(--text);margin-top:10px;resize:vertical}
textarea{min-height:110px}.item input{margin-top:8px;padding:9px 12px;font-size:14px}
.nav{display:flex;gap:10px;margin-top:18px}.primary{flex:1;padding:15px;border:0;border-radius:14px;background:var(--accent);color:#fff;font-weight:800;font-size:16px}.primary:disabled{opacity:.4;cursor:default}
.ghost{padding:15px 18px;border:1.5px solid var(--line);border-radius:14px;background:transparent;color:var(--text);font-weight:700}
.hint{font-size:12px;color:var(--muted);margin-top:8px}.sum{margin:0;padding:0;list-style:none}.sum li{padding:9px 0;border-bottom:1px solid var(--line);font-size:14px}.sum li:last-child{border:0}
.center{text-align:center;padding:30px 10px}.tick{width:64px;height:64px;border-radius:50%;background:var(--okbg);color:var(--ok);font-size:34px;display:grid;place-items:center;margin:0 auto 14px}
.score{font-size:34px;font-weight:800;color:var(--accent)}.err{color:var(--no);font-weight:600;margin-top:10px}
</style></head><body><div class="wrap" id="app"><div class="card center">Loading your report…</div></div>
<script>
(function(){
var app=document.getElementById('app'),T=new URLSearchParams(location.search).get('t')||'',S=null,A={},idx=0,busy=false;
function h(tag,props,kids){var e=document.createElement(tag);for(var k in (props||{})){var v=props[k];if(k==='class')e.className=v;else if(k==='text')e.textContent=v;else if(k.slice(0,2)==='on')e.addEventListener(k.slice(2),v);else if(v!==false&&v!=null)e.setAttribute(k,v===true?'':v);}(kids||[]).forEach(function(c){if(c==null)return;e.appendChild(typeof c==='string'?document.createTextNode(c):c);});return e;}
function api(name,body){var o=body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({t:T},body))}:{};return fetch('mai-report.php?api='+name+'&t='+encodeURIComponent(T),o).then(function(r){return r.json().catch(function(){return{ok:false,error:'Something went wrong.'}})});}
function done(s,a){a=a||{};if(s.type==='text')return !!(a.text&&a.text.trim());if(s.type==='list')return s.items.every(function(i){return a.items&&a.items[i.id]});return !!a.choice;}
function okv(s){return s.choices.filter(function(c){return c[2]}).map(function(c){return c[0]});}
function stepOk(s,a){if(!done(s,a))return false;if(s.type==='text')return true;var ok=okv(s);if(s.type==='list')return s.items.every(function(i){return ok.indexOf(a.items[i.id])>-1});return ok.indexOf(a.choice)>-1;}
function save(s){return api('save',{key:s.key,ans:A[s.key]||{}});}
function head(){var n=S.steps.length,pct=Math.round(Math.min(idx,n)/n*100);return h('div',{},[h('div',{class:'top'},[h('div',{class:'logo',text:'M'}),h('div',{},[h('b',{text:'Mai · '+(S.type==='morning'?'Morning':'End-of-day')+' report'}),h('small',{text:'Hi '+S.name+', one step at a time'})])]),h('div',{class:'bar'},[h('i',{style:'width:'+pct+'%'})]),h('div',{class:'count',text:idx<n?('Step '+(idx+1)+' of '+n):'Last step: check and send'})]);}
function go(i){idx=Math.max(0,Math.min(S.steps.length,i));draw();window.scrollTo({top:0,behavior:'smooth'});}
function next(s){if(busy)return;busy=true;save(s).then(function(){busy=false;go(idx+1);});}
function draw(){app.innerHTML='';app.appendChild(head());var n=S.steps.length;if(idx>=n)return review();var s=S.steps[idx],a=A[s.key]=A[s.key]||{};var card=h('div',{class:'card'},[h('h1',{text:s.title}),h('h2',{text:s.prompt})]);
 if(s.type==='yesno'){var row=h('div',{class:'btns'});s.choices.forEach(function(c){row.appendChild(h('button',{class:'big '+c[0]+(a.choice===c[0]?' on':''),text:c[1],onclick:function(){a.choice=c[0];save(s);if(c[2]){draw();setTimeout(function(){go(idx+1)},280);}else draw();}}));});card.appendChild(row);
  if(a.choice&&okv(s).indexOf(a.choice)<0){var ta=h('textarea',{placeholder:"What's blocking it? (optional)",oninput:function(){a.note=ta.value}});ta.value=a.note||'';ta.addEventListener('blur',function(){save(s)});card.appendChild(ta);}}
 else if(s.type==='list'){if(Array.isArray(a.items))a.items={};if(Array.isArray(a.notes))a.notes={};a.items=a.items||{};a.notes=a.notes||{};s.items.forEach(function(it){var box=h('div',{class:'item'},[h('div',{class:'n',text:it.label})]);if(it.sub)box.appendChild(h('div',{class:'s',text:it.sub}));var chips=h('div',{class:'chips'});s.choices.forEach(function(c){chips.appendChild(h('button',{class:'chip '+(a.items[it.id]===c[0]?'on '+(c[2]?'ok':'bad'):''),text:c[1],onclick:function(){a.items[it.id]=c[0];save(s);var all=s.items.every(function(x){return a.items[x.id]}),allok=stepOk(s,a);draw();if(all&&allok)setTimeout(function(){go(idx+1)},350);}}));});box.appendChild(chips);
   if(a.items[it.id]&&okv(s).indexOf(a.items[it.id])<0){var ni=h('input',{type:'text',placeholder:'Short note (optional)',oninput:function(){a.notes[it.id]=ni.value}});ni.value=a.notes[it.id]||'';ni.addEventListener('blur',function(){save(s)});box.appendChild(ni);}card.appendChild(box);});}
 else{var ta2=h('textarea',{placeholder:'Type here…',oninput:function(){a.text=ta2.value;nb.disabled=!done(s,a)}});ta2.value=a.text||'';ta2.addEventListener('blur',function(){save(s)});card.appendChild(ta2);card.appendChild(h('div',{class:'chips',style:'margin-top:10px'},[h('button',{class:'chip',text:'Nothing to report',onclick:function(){a.text='None';save(s);draw();}})]));}
 var nav=h('div',{class:'nav'});if(idx>0)nav.appendChild(h('button',{class:'ghost',text:'Back',onclick:function(){go(idx-1)}}));var nb=h('button',{class:'primary',text:idx===n-1?'Review':'Next',disabled:!done(s,a),onclick:function(){next(s)}});nav.appendChild(nb);card.appendChild(nav);app.appendChild(card);}
function lines(){return S.steps.map(function(s){var a=A[s.key]||{},ok=stepOk(s,a),bad='';if(s.type==='text')return s.title+': '+(a.text||'—');if(s.type==='list'){var o=okv(s);var b=s.items.filter(function(i){return o.indexOf(a.items[i.id])<0}).map(function(i){return i.label});return (b.length?'✗ ':'✓ ')+s.title+(b.length?': '+b.join(', '):': all '+s.items.length);}return (ok?'✓ ':'✗ ')+s.title+(ok?'':(a.note?': '+a.note:': no'));});}
function review(){var card=h('div',{class:'card'},[h('h1',{text:'Check and send'}),h('h2',{text:'This is what Mai will record'})]);var ul=h('ul',{class:'sum'});lines().forEach(function(l){ul.appendChild(h('li',{text:l}))});card.appendChild(ul);var err=h('div',{class:'err'});var nav=h('div',{class:'nav'},[h('button',{class:'ghost',text:'Back',onclick:function(){go(S.steps.length-1)}}),h('button',{class:'primary',text:'Send report',onclick:function(e){var b=e.target;b.disabled=true;b.textContent='Sending…';api('submit',{}).then(function(r){if(r.ok){S.status='completed';S.score=r.score;S.max=r.max;S.lines=r.lines;finished();}else{b.disabled=false;b.textContent='Send report';err.textContent=r.error||'Could not send.';}});}})]);card.appendChild(err);card.appendChild(nav);app.appendChild(card);}
function finished(){app.innerHTML='';var c=h('div',{class:'card center'},[h('div',{class:'tick',text:'✓'}),h('h2',{text:'Report sent. Thank you!'}),h('div',{class:'score',text:S.score+'/'+S.max}),h('p',{class:'hint',text:'Mai also sent you a WhatsApp message with what you reported. You can close this page.'})]);var ul=h('ul',{class:'sum',style:'text-align:left;margin-top:14px'});(S.lines||[]).forEach(function(l){ul.appendChild(h('li',{text:l}))});c.appendChild(ul);app.appendChild(c);}
function fail(msg){app.innerHTML='';app.appendChild(h('div',{class:'card center'},[h('h2',{text:msg})]));}
api('load').then(function(r){if(!r.ok)return fail(r.error||'This link is not valid.');S=r;A=(r.a&&!Array.isArray(r.a))?r.a:{};if(S.status==='completed')return finished();if(S.expired)return fail('This link has expired. Mai will send a new one at the next check-in.');
 var first=0;while(first<S.steps.length&&done(S.steps[first],A[S.steps[first].key]))first++;idx=first;draw();}).catch(function(){fail('Could not load. Please check your connection and reload.');});
})();
</script></body></html>
HTML;
}
