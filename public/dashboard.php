<?php /* public/dashboard.php */ ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LSZJ Dashboard</title>
<link rel="stylesheet" href="app.css">
</head>
<body>
<h1>LSZJ Dashboard</h1>
<div class="nav"><a href="#" onclick="nav('dashboard.php');return false;">Dashboard</a><a href="#" onclick="nav('flight_approvals.php');return false;">Flugfreigaben</a><a href="#" onclick="nav('manual_flight.php');return false;">+ Flug manuell erfassen</a></div>
<div class="top"><label>Von <input id="from" type="date"></label><label>Bis <input id="to" type="date"></label><label>Status <select id="status"><option value="pending">pending</option><option value="correction_required">correction_required</option><option value="approved">approved</option><option value="all">all</option></select></label><label>Benutzer <input id="user" value="demo"></label><button onclick="loadData()">Laden</button><button class="secondary" onclick="importKTraxRange()">kTrax-Import</button><button class="quick" onclick="setToday()">Heute</button><button class="quick" onclick="setYesterday()">Gestern</button><button class="quick" onclick="setLast7()">Letzte 7 Tage</button></div>
<div id="rangeLabel" class="card"></div>
<div id="metrics" class="grid"></div>
<div class="card manualbox"><h2>Manuelle Flugerfassung</h2><p>Flüge ohne FLARM/KTrax nacherfassen oder Segel- und Motorflüge manuell verknüpfen.</p><p><a class="button" href="#" onclick="nav('manual_flight.php');return false;">+ Flug manuell erfassen</a></p></div>
<div id="exportBox" class="card"></div>
<div class="card"><h2>Exportvorschau</h2><div id="preview"></div></div>
<script>
function qs(id){return document.getElementById(id)}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
function todayISO(){return new Date().toISOString().slice(0,10)}
function shiftISO(days){const d=new Date();d.setDate(d.getDate()+days);return d.toISOString().slice(0,10)}
function getParam(n,f){const p=new URLSearchParams(location.search);return p.get(n)||f}
function setRange(from,to){qs('from').value=from;qs('to').value=to;loadData()}
function setToday(){setRange(todayISO(),todayISO())}
function setYesterday(){const y=shiftISO(-1);setRange(y,y)}
function setLast7(){setRange(shiftISO(-6),todayISO())}
function rangeQuery(){return 'from='+encodeURIComponent(qs('from').value)+'&to='+encodeURIComponent(qs('to').value)+'&status='+encodeURIComponent(qs('status').value)+'&user='+encodeURIComponent(qs('user').value)}
function exportRangeQuery(){return 'from='+encodeURIComponent(qs('from').value)+'&to='+encodeURIComponent(qs('to').value)}
function singleDateForManual(){return qs('from').value||todayISO()}
function nav(page){if(page==='manual_flight.php'){location.href=page+'?date='+encodeURIComponent(singleDateForManual())+'&status='+encodeURIComponent(qs('status').value)+'&user='+encodeURIComponent(qs('user').value)}else{location.href=page+'?'+rangeQuery()}}
function fmt(v){if(!v)return '';let s=String(v).replace('T',' ');let d=s.slice(0,10).split('-');return d.length===3?`${d[2]}.${d[1]}.${d[0]} ${s.slice(11,16)}`:s}
function fmtDate(v){if(!v)return '';let d=String(v).slice(0,10).split('-');return d.length===3?`${d[2]}.${d[1]}.${d[0]}`:v}
qs('from').value=getParam('from',getParam('date',todayISO()));qs('to').value=getParam('to',qs('from').value);qs('status').value=getParam('status','pending');qs('user').value=getParam('user','demo');
async function loadData(){
  history.replaceState(null,'',location.pathname+'?'+rangeQuery());
  qs('rangeLabel').innerHTML='<b>Zeitraum:</b> '+fmtDate(qs('from').value)+' bis '+fmtDate(qs('to').value);
  const fl=await (await fetch('../src/api_flight_approvals.php?'+rangeQuery()+'&status=all&_='+Date.now())).json();
  const exp=await (await fetch('../src/api_export_status.php?from='+encodeURIComponent(qs('from').value)+'&to='+encodeURIComponent(qs('to').value)+'&_='+Date.now())).json();
  qs('metrics').innerHTML=`<div class="metric"><span>pending</span><b>${fl.counts.pending}</b></div><div class="metric"><span>Korrektur</span><b>${fl.counts.correction_required}</b></div><div class="metric"><span>approved</span><b>${fl.counts.approved}</b></div><div class="metric"><span>Freigegeben, nicht exportiert</span><b>${exp.approved_not_exported}</b></div><div class="metric"><span>Bereits exportiert</span><b>${exp.already_exported}</b></div>`;
  renderExport(exp);renderPreview(exp);if(window.lszjI18n)window.lszjI18n.translateAll();
}
function renderExport(exp){
  let html='<h2>Vereinsflieger-CSV Export</h2>';
  let cls='warnbox';
  if(exp.can_export){
    cls='okbox';
    html+='<p>Alle freigegebenen, noch nicht exportierten Einträge sind exportbereit.</p>';
    html+='<p><a class="button ok" href="../src/export_vereinsflieger.php?'+exportRangeQuery()+'">CSV Export starten</a></p>';
  } else {
    if((exp.approved_not_exported||0)===0){
      html+='<p>Keine freigegebenen, noch nicht exportierten Einträge im Zeitraum.</p>';
    } else {
      html+='<p>Export ist noch nicht freigegeben.</p><ul>';
      if((exp.pending||0)>0) html+='<li>'+esc(exp.pending)+' Einträge sind noch offen.</li>';
      if((exp.correction_required||0)>0) html+='<li>'+esc(exp.correction_required)+' Einträge benötigen Korrektur.</li>';
      if((exp.missing_flight_type||0)>0) html+='<li>'+esc(exp.missing_flight_type)+' freigegebene Einträge haben keine Flugart.</li>';
      if((exp.missing_charge_mode||0)>0) html+='<li>'+esc(exp.missing_charge_mode)+' freigegebene Einträge haben keine Abrechnungsart.</li>';
      html+='</ul>';
    }
  }
  if(exp.export_batches&&exp.export_batches.length){
    html+='<h3>Letzte Exporte im Zeitraum</h3><table><tr><th>Batch</th><th>Anzahl</th><th>Erster Export</th><th>Letzter Export</th></tr>';
    exp.export_batches.forEach(b=>{html+=`<tr><td>${esc(b.export_batch||'')}</td><td>${esc(b.cnt||'')}</td><td>${fmt(b.first_exported_at)}</td><td>${fmt(b.last_exported_at)}</td></tr>`});
    html+='</table>';
  }
  qs('exportBox').className='card '+cls;qs('exportBox').innerHTML=html;
}
function renderPreview(exp){
  if(!exp.export_rows||!exp.export_rows.length){qs('preview').innerHTML='<p>Keine freigegebenen, noch nicht exportierten Einträge im Zeitraum.</p>';return}
  let h='<table><tr><th>ID</th><th>Typ</th><th>Flugzeug</th><th>Start</th><th>Flugart</th><th>Abrechnung</th></tr>';
  exp.export_rows.forEach(r=>{h+=`<tr><td>${esc(r.id)}</td><td>${esc(r.entry_type)}</td><td>${esc(r.callsign)}</td><td>${fmt(r.departure_time)}</td><td>${esc(r.flight_type_code||'')} ${esc(r.flight_type_name||'')}</td><td>${esc(r.charge_mode_name||r.charge_mode||'')}</td></tr>`});
  h+='</table>';qs('preview').innerHTML=h;
}
loadData();
</script><script src="ktrax_import_range.js"></script>
<script src="i18n_hotfix_02.js?v=20260818_1"></script>
</body></html>
