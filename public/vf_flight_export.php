<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';
$user = auth_require_login();
require_role('ADMIN');
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VF-Flugexport C3</title>
<link rel="stylesheet" href="app.css">
</head>
<body>
<div class="card"><div class="row">
<strong>Angemeldet: <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
<a class="button secondary" href="dashboard.php">Dashboard</a>
<a class="button secondary" href="logout.php">Logout</a>
</div></div>
<h1>Vereinsflieger REST-Flugexport</h1>
<p class="info">Paket C3: Kontrollierter Mehrfachexport mit zusätzlichen serverseitigen Sperren für Flugzeugschlepp, Windenstart, Motorlaufzeit, eigenständigen Schleppflug und GVVC. Maximal zehn Flüge pro Durchlauf.</p>
<div class="card"><div class="row">
<label>Von <input id="from" type="date" value="<?= $today ?>"></label>
<label>Bis <input id="to" type="date" value="<?= $today ?>"></label>
<button id="previewButton" type="button">Vorschau laden</button>
</div></div>
<div id="batchPanel" class="card" hidden>
<div class="row"><strong>Ausgewählt: <span id="selectedCount">0</span> von maximal <span id="batchMaximum">10</span></strong><button id="clearSelectionButton" class="secondary" type="button">Auswahl leeren</button></div>
<p><label><input id="batchConfirmation" type="checkbox"> Ich habe jeden ausgewählten Flug, Personen, Sonderfallprüfung und REST-Payload geprüft.</label></p>
<button id="sendBatchButton" class="ok" type="button" disabled>Ausgewählte Flüge kontrolliert senden</button>
</div>
<div id="result" class="card">Noch keine Vorschau geladen.</div>
<script>
'use strict';
const VERSION='2026-09-11-c3-special-cases-v1';
const csrfToken=<?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const previewButton=document.getElementById('previewButton');
const clearSelectionButton=document.getElementById('clearSelectionButton');
const sendBatchButton=document.getElementById('sendBatchButton');
const batchConfirmation=document.getElementById('batchConfirmation');
const batchPanel=document.getElementById('batchPanel');
const selectedCount=document.getElementById('selectedCount');
const batchMaximum=document.getElementById('batchMaximum');
const resultBox=document.getElementById('result');
let running=false,previewLoaded=false,maximumFlights=10;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function api(body){const r=await fetch(`api_vf_flight_export.php?v=${VERSION}`,{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','Cache-Control':'no-cache'},body:JSON.stringify({...body,csrf_token:csrfToken,page_version:VERSION})});const t=(await r.text()).replace(/^\uFEFF/,'').trim();let d;try{d=JSON.parse(t)}catch(e){throw new Error(`API liefert kein JSON (HTTP ${r.status}): ${t.slice(0,180)}`)}if(!r.ok||!d.ok)throw new Error(d.error||`HTTP ${r.status}`);return d}
function personLine(label,x){if(!x||x.status==='empty')return `<div><strong>${esc(label)}:</strong> nicht belegt</div>`;if(x.status==='resolved')return `<div class="okbox"><strong>${esc(label)}:</strong> ${esc(x.input_name)} → ${esc(x.display_name)} (VF-UID ${esc(x.vf_user_no)})</div>`;const c=(x.candidates||[]).map(i=>`${esc(i.display_name)} (${esc(i.vf_user_no)})`).join('<br>');return `<div class="warnbox"><strong>${esc(label)}:</strong> ${esc(x.issue)}${c?'<br>'+c:''}</div>`}
function persons(x){return '<details><summary>Personenzuordnung</summary>'+personLine('Pilot',x?.pilot)+personLine('Begleiter',x?.attendant)+personLine('Schlepppilot',x?.tow_pilot)+'</details>'}
function special(x){if(!x||x.type==='normal')return '<div><strong>Sonderfall:</strong> keiner</div>';const checks=(x.checks||[]).map(esc).join('<br>');const state=x.enabled?'<div class="okbox">Für kontrollierten Test freigeschaltet</div>':'<div class="warnbox">Serverseitig gesperrt</div>';return `<details open><summary>Sonderfall: ${esc(x.label)}</summary>${state}${checks?'<div>'+checks+'</div>':''}</details>`}
function payload(p){return '<details><summary>REST-Payload</summary><pre>'+esc(JSON.stringify(p,null,2))+'</pre></details>'}
function ids(){return Array.from(document.querySelectorAll('.flight-selection:checked')).map(i=>Number(i.value))}
function update(){const a=ids();selectedCount.textContent=String(a.length);batchMaximum.textContent=String(maximumFlights);sendBatchButton.disabled=running||!previewLoaded||a.length===0||a.length>maximumFlights||!batchConfirmation.checked}
function clear(){document.querySelectorAll('.flight-selection').forEach(i=>i.checked=false);batchConfirmation.checked=false;update()}
function table(items){if(!items.length)return '<p>Keine Kandidaten.</p>';return `<table><thead><tr><th>Auswahl</th><th>ID</th><th>Flug</th><th>Zeit</th><th>Prüfung</th></tr></thead><tbody>${items.map(i=>{const problems=i.issues.length?'<div class="warnbox">'+i.issues.map(esc).join('<br>')+'</div>':'<div class="okbox">Sendbar</div>';const pick=i.send_allowed?`<input class="flight-selection" type="checkbox" value="${i.entry_id}">`:'Gesperrt';return `<tr><td>${pick}</td><td>${i.entry_id}<br><small>Op ${i.operation_id}</small></td><td>${esc(i.callsign)}<br>${esc(i.pilot)}<br><small>${esc(i.entry_type)}</small></td><td>${esc(i.departuretime)}<br>${esc(i.arrivaltime)}</td><td>${problems}${persons(i.person_resolution)}${special(i.special_case)}${payload(i.payload)}</td></tr>`}).join('')}</tbody></table>`}
async function preview(){if(running)return;running=true;previewLoaded=false;batchConfirmation.checked=false;batchPanel.hidden=true;previewButton.disabled=true;resultBox.textContent='Vorschau wird geladen …';try{const d=await api({action:'preview',from:document.getElementById('from').value,to:document.getElementById('to').value});maximumFlights=Number(d.batch_max_flights||10);previewLoaded=true;resultBox.innerHTML=`<h2>Vorschau</h2><div class="grid"><div class="metric"><b>${d.candidate_count}</b><span>Kandidaten</span></div><div class="metric"><b>${d.sendable_count}</b><span>sendbar</span></div><div class="metric"><b>${d.blocked_count}</b><span>blockiert</span></div></div>${table(d.items)}`;batchPanel.hidden=d.sendable_count===0;document.querySelectorAll('.flight-selection').forEach(i=>i.addEventListener('change',update))}catch(e){resultBox.innerHTML='<div class="warnbox">'+esc(e.message)+'</div>'}finally{running=false;previewButton.disabled=false;update()}}
function batchResult(r){resultBox.innerHTML=`<h2>Mehrfachexport abgeschlossen</h2><div class="grid"><div class="metric"><b>${r.requested}</b><span>angefordert</span></div><div class="metric"><b>${r.successful}</b><span>erfolgreich</span></div><div class="metric"><b>${r.failed}</b><span>fehlgeschlagen</span></div></div><table><tr><th>LSZJ-ID</th><th>Status</th><th>VF-flid</th><th>Fehler</th></tr>${r.items.map(i=>`<tr><td>${i.entry_id}</td><td>${i.ok?'Erfolg':'Fehler'}</td><td>${esc(i.flid)}</td><td>${esc(i.error)}</td></tr>`).join('')}</table><p>Vor weiterem Versand neue Vorschau laden.</p>`}
async function send(){if(running)return;const a=ids();if(!previewLoaded||!a.length||a.length>maximumFlights||!batchConfirmation.checked)return;if(!confirm(`${a.length} ausgewählte Flüge kontrolliert senden?`))return;running=true;sendBatchButton.disabled=true;previewButton.disabled=true;resultBox.textContent='Flüge werden einzeln übertragen …';try{const d=await api({action:'send_batch',entry_ids:a,confirm_controlled_batch:true});previewLoaded=false;batchPanel.hidden=true;batchResult(d.result)}catch(e){resultBox.innerHTML='<div class="warnbox">'+esc(e.message)+'</div>'}finally{running=false;previewButton.disabled=false;update()}}
previewButton.addEventListener('click',preview);clearSelectionButton.addEventListener('click',clear);batchConfirmation.addEventListener('change',update);sendBatchButton.addEventListener('click',send);update();
</script></body></html>
