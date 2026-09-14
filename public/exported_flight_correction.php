<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';

$user = auth_require_login();
require_role('ADMIN');

$entryId = filter_input(INPUT_GET, 'accounting_entry_id', FILTER_VALIDATE_INT);
if ($entryId === false || $entryId === null || $entryId <= 0) {
    $entryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}
$entryId = is_int($entryId) && $entryId > 0 ? $entryId : 0;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exportierten Flug korrigieren</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .d1-hidden { display: none !important; }
        .d1-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; }
        .d1-field { display:flex; flex-direction:column; gap:.35rem; }
        .d1-field input,.d1-field textarea,.d1-field select { width:100%; box-sizing:border-box; }
        .d1-field textarea { min-height:5rem; resize:vertical; }
        .d1-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.75rem; }
        .d1-meta > div { padding:.75rem; border:1px solid #d8d8d8; border-radius:.4rem; }
        .d1-meta small { display:block; color:#555; }
        .d1-warning { border-left:.35rem solid #b45309; background:#fff7ed; padding:1rem; margin:1rem 0; }
        .d1-actions { display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; margin-top:1rem; }
        .d1-difference { border-bottom:1px solid #ddd; padding:.75rem 0; }
        .d1-value { white-space:pre-wrap; overflow-wrap:anywhere; }
        .required-marker { color:#b91c1c; font-weight:700; margin-left:.25rem; }
        .required-note { color:#7f1d1d; font-size:.9rem; }
        .field-changed > span:first-child::after { content:' geändert'; color:#b45309; font-size:.85rem; font-weight:700; }
    </style>
</head>
<body>
<div class="card">
    <div class="row">
        <strong>Angemeldet: <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        <a class="button secondary" href="vf_export_changes.php">Änderungsliste</a>
        <a class="button secondary" href="vf_export_journal.php">Exportjournal</a>
        <a class="button secondary" href="dashboard.php">Dashboard</a>
    </div>
</div>

<h1>Exportierten Flug korrigieren</h1>
<p class="info">Diese Maske ändert ausschliesslich den lokalen LSZJ-Datensatz. Vereinsflieger wird nicht automatisch aktualisiert.</p>

<div class="card">
    <div class="row">
        <label for="entryId">LSZJ-Buchungs-ID <span class="required-marker">* Pflichtfeld</span></label>
        <input id="entryId" type="number" min="1" step="1" value="<?= $entryId > 0 ? $entryId : '' ?>" inputmode="numeric" required aria-required="true">
        <button id="loadButton" type="button">Exportierten Flug laden</button>
    </div>
</div>

<div id="message" class="card d1-hidden" role="status"></div>

<form id="correctionForm" class="d1-hidden" novalidate>
    <div class="card">
        <h2>Exportinformationen</h2>
        <div id="metadata" class="d1-meta"></div>
        <div class="d1-warning"><strong>Achtung:</strong> <span id="warningText"></span> Die ursprüngliche VF-flid und die Exporthistorie bleiben bestehen.</div>
    </div>

    <div class="card">
        <h2>Lokale Flugdaten</h2>
        <p>Es werden nur tatsächlich geänderte Felder an den geschützten D1-Endpunkt gesendet.</p>
        <div id="fields" class="d1-grid"></div>
    </div>

    <div class="card">
        <h2>Begründung und Bestätigung</h2>
        <label class="d1-field" for="changeReason">
            <span>Korrekturgrund <span class="required-marker">* Pflichtfeld</span></span>
            <textarea id="changeReason" minlength="5" maxlength="1000" required aria-required="true" placeholder="Zum Beispiel: Landezeit gemäss Flugbuch korrigiert"></textarea>
            <small id="changeReasonCounter" class="required-note" aria-live="polite">0/1000 Zeichen, mindestens 5 erforderlich</small>
        </label>
        <p>
            <label>
                <input id="confirmWarning" type="checkbox" required aria-required="true">
                Ich habe verstanden, dass diese lokale Änderung einen Synchronisationsbedarf mit Vereinsflieger erzeugen kann.
                <span class="required-marker">* Pflichtfeld</span>
            </label>
        </p>
        <div class="d1-actions">
            <button id="saveButton" class="ok" type="submit" disabled>Lokale Admin-Korrektur speichern</button>
            <button id="resetButton" class="secondary" type="button">Eingaben zurücksetzen</button>
        </div>
    </div>
</form>

<div id="resultPanel" class="card d1-hidden"></div>

<script>
'use strict';
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const initialEntryId = <?= json_encode($entryId) ?>;
const fieldDefinitions = {
    callsign:{label:'Flugzeug / Callsign',type:'text'}, pilot_name:{label:'Pilot',type:'text'},
    attendant_name:{label:'Begleiter',type:'text'}, tow_pilot_name:{label:'Schlepppilot',type:'text'},
    departure_time:{label:'Startzeit',type:'datetime-local'}, departure_location:{label:'Startort',type:'text'},
    arrival_time:{label:'Landezeit',type:'datetime-local'}, arrival_location:{label:'Landeort',type:'text'},
    flight_minutes:{label:'Flugminuten',type:'number',min:'0'}, landing_count:{label:'Landungen',type:'number',min:'1'},
    start_type:{label:'Startart-ID',type:'number',min:'0'}, comment:{label:'Bemerkung',type:'textarea'},
    tow_height_m:{label:'Schlepphöhe in Metern',type:'number',min:'0'}, tow_callsign:{label:'Schleppflugzeug',type:'text'},
    tow_minutes:{label:'Schleppminuten',type:'number',min:'0'}, motor_minutes:{label:'Motorminuten',type:'number',min:'0'},
    block_minutes:{label:'Blockminuten',type:'number',min:'0'}, vf_flight_type_id:{label:'VF-Flugart-ID',type:'number',min:'0'},
    charge_mode:{label:'Abrechnungsart-ID',type:'number',min:'0'}, uid_charge:{label:'VF-Kostenträger-UID',type:'number',min:'0'},
    invoiced:{label:'Abgerechnet',type:'select'}, km:{label:'Kilometer',type:'number',min:'0',step:'0.01'},
    winch_id:{label:'Winden-ID',type:'number',min:'0'}, wid:{label:'VF-Winden-ID',type:'number',min:'0'},
    winch_driver_vf_user_no:{label:'VF-UID Windenfahrer',type:'number',min:'0'}, uid_winch:{label:'VF-UID Windenfahrer',type:'number',min:'0'},
    flight_instructor_vf_user_no:{label:'VF-UID Fluglehrer',type:'number',min:'0'}, uid_fi:{label:'VF-UID Fluglehrer',type:'number',min:'0'}
};
const $ = id => document.getElementById(id);
const entryIdInput=$('entryId'), loadButton=$('loadButton'), form=$('correctionForm'), fieldsBox=$('fields'), metadataBox=$('metadata'), warningText=$('warningText'), messageBox=$('message'), resultPanel=$('resultPanel'), changeReason=$('changeReason'), changeReasonCounter=$('changeReasonCounter'), confirmWarning=$('confirmWarning'), saveButton=$('saveButton'), resetButton=$('resetButton');
let busy=false, loaded=null, originalValues={};
function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function showMessage(text,kind='info'){messageBox.classList.remove('d1-hidden');messageBox.innerHTML=`<div class="${kind==='error'?'warnbox':kind==='success'?'okbox':'info'}">${escapeHtml(text)}</div>`;}
function clearMessage(){messageBox.classList.add('d1-hidden');messageBox.textContent='';}
async function callApi(body){const response=await fetch('api_update_exported_flight.php?v=20260914-d1-ui-v3',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','Cache-Control':'no-cache'},body:JSON.stringify({...body,csrf_token:csrfToken})});const text=(await response.text()).replace(/^\uFEFF/,'').trim();let data;try{data=JSON.parse(text);}catch(e){throw new Error(`API liefert kein JSON (HTTP ${response.status}): ${text.slice(0,300)}`);}if(!response.ok||!data.ok){const error=new Error(data.error||`HTTP ${response.status}`);error.status=response.status;throw error;}return data;}
function toInputDateTime(v){return v?String(v).replace(' ','T').slice(0,16):'';}
function fromInputDateTime(v){return v?String(v).replace('T',' ')+':00':'';}
function normalizedComparable(field,value){const d=fieldDefinitions[field]||{type:'text'};if(d.type==='datetime-local')return fromInputDateTime(value);if(d.type==='number')return value===''?null:String(value);if(d.type==='select')return String(value);const t=String(value??'').trim();return t===''?null:t;}
function originalComparable(field,value){const d=fieldDefinitions[field]||{type:'text'};if(d.type==='datetime-local')return value?String(value).slice(0,19):null;if(d.type==='number')return value===null||value===''?null:String(value);if(d.type==='select')return value===null||value===''?'0':String(value);const t=String(value??'').trim();return t===''?null:t;}
function inputValue(field,value){const d=fieldDefinitions[field]||{type:'text'};if(d.type==='datetime-local')return toInputDateTime(value);if(d.type==='select')return value===1||value==='1'?'1':'0';return value??'';}
function fieldIsChanged(field,value){return normalizedComparable(field,value)!==originalComparable(field,originalValues[field]);}
function createField(field,value){const d=fieldDefinitions[field]||{label:field,type:'text'},wrapper=document.createElement('label');wrapper.className='d1-field';const caption=document.createElement('span');caption.textContent=d.label;wrapper.appendChild(caption);let control;if(d.type==='textarea'){control=document.createElement('textarea');}else if(d.type==='select'){control=document.createElement('select');control.innerHTML='<option value="0">Nein</option><option value="1">Ja</option>';}else{control=document.createElement('input');control.type=d.type;for(const a of ['min','max','step'])if(d[a]!==undefined)control.setAttribute(a,d[a]);}control.name=field;control.value=String(inputValue(field,value));control.autocomplete='off';const update=()=>{wrapper.classList.toggle('field-changed',fieldIsChanged(field,control.value));updateSaveState();};control.addEventListener('input',update);control.addEventListener('change',update);wrapper.appendChild(control);return wrapper;}
function metadataItem(label,value){return `<div><small>${escapeHtml(label)}</small><strong>${escapeHtml(value??'nicht gesetzt')}</strong></div>`;}
function renderLoaded(data){loaded=data;originalValues={};fieldsBox.textContent='';metadataBox.innerHTML=[metadataItem('LSZJ-ID',data.accounting_entry_id),metadataItem('Operation-ID',data.operation_id),metadataItem('VF-flid',data.vf_flid),metadataItem('VF-Exportiert',data.vf_exported_at),metadataItem('VF-Erfolg',data.succeeded_at),metadataItem('Synchronisationsstatus',data.sync_status),metadataItem('Zeilenversion',data.row_version),metadataItem('Letzte lokale Korrektur',data.last_local_change_at),metadataItem('Letzter Korrekturgrund',data.last_change_reason)].join('');warningText.textContent=data.warning+' ';for(const field of data.editable_fields||[]){const value=data.entry[field]??null;originalValues[field]=value;fieldsBox.appendChild(createField(field,value));}changeReason.value='';confirmWarning.checked=false;resultPanel.classList.add('d1-hidden');form.classList.remove('d1-hidden');updateReasonValidation();updateSaveState();}
function collectChanges(){const changes={};for(const control of fieldsBox.querySelectorAll('[name]'))if(fieldIsChanged(control.name,control.value))changes[control.name]=normalizedComparable(control.name,control.value);return changes;}
function updateReasonValidation(){const n=changeReason.value.trim().length;changeReasonCounter.textContent=`${n}/1000 Zeichen, mindestens 5 erforderlich`;changeReason.setCustomValidity(n>=5&&n<=1000?'':'Der Korrekturgrund muss 5 bis 1000 Zeichen enthalten.');}
function updateSaveState(){const n=changeReason.value.trim().length;saveButton.disabled=busy||!loaded||Object.keys(collectChanges()).length===0||n<5||n>1000||!confirmWarning.checked;}
async function loadFlight(){if(busy)return;const id=Number(entryIdInput.value);if(!Number.isInteger(id)||id<=0){entryIdInput.reportValidity();showMessage('Bitte eine gültige LSZJ-Buchungs-ID eingeben.','error');return;}busy=true;loaded=null;form.classList.add('d1-hidden');resultPanel.classList.add('d1-hidden');showMessage('Exportierter Flug wird geladen.');loadButton.disabled=true;try{const data=await callApi({action:'load',accounting_entry_id:id});renderLoaded(data.result);clearMessage();const url=new URL(location.href);url.searchParams.set('accounting_entry_id',String(id));history.replaceState({},'',url);}catch(e){showMessage(e.message,'error');}finally{busy=false;loadButton.disabled=false;updateSaveState();}}
function renderDifferences(list){if(!Array.isArray(list)||!list.length)return '<p>Keine Payload-Unterschiede.</p>';return list.map(i=>`<div class="d1-difference"><strong>${escapeHtml(i.field)}</strong> (${escapeHtml(i.change_type)}, Risiko ${escapeHtml(i.risk)})<div class="d1-value"><small>Alt</small><br>${escapeHtml(JSON.stringify(i.old))}</div><div class="d1-value"><small>Neu</small><br>${escapeHtml(JSON.stringify(i.new))}</div></div>`).join('');}
function renderResult(r){resultPanel.innerHTML=`<div class="${r.sync_status==='in_sync'?'okbox':'warnbox'}"><h2>Korrektur gespeichert</h2><p>${escapeHtml(r.message)}</p><p><strong>VF wurde geändert:</strong> ${r.vf_was_modified?'Ja':'Nein'}</p></div><div class="d1-meta">${metadataItem('Audit-ID',r.audit_id)}${metadataItem('D1-Prüfung',r.check_id)}${metadataItem('Neue Zeilenversion',r.row_version)}${metadataItem('Synchronisationsstatus',r.sync_status)}${metadataItem('Unterschiede',r.difference_count)}${metadataItem('Höchstes Risiko',r.highest_risk)}</div><h3>Payload-Unterschiede</h3>${renderDifferences(r.differences)}<p><button id="reloadAfterSave" type="button">Korrigierten Flug neu laden</button></p>`;resultPanel.classList.remove('d1-hidden');$('reloadAfterSave').addEventListener('click',loadFlight);}
async function saveCorrection(event){event.preventDefault();if(busy||!loaded)return;updateReasonValidation();const changes=collectChanges();if(!Object.keys(changes).length){showMessage('Es wurden keine Felder geändert.','error');return;}if(!form.reportValidity())return;if(!confirm('Lokale Admin-Korrektur speichern? Vereinsflieger wird nicht geändert.'))return;busy=true;saveButton.disabled=true;resultPanel.classList.add('d1-hidden');showMessage('Lokale Korrektur wird gespeichert.');try{const data=await callApi({action:'update',accounting_entry_id:loaded.accounting_entry_id,expected_row_version:loaded.row_version,change_reason:changeReason.value.trim(),confirm_sync_warning:confirmWarning.checked,changes});loaded.row_version=data.result.row_version;renderResult(data.result);showMessage('Korrektur, Audit und D1-Prüfung wurden gespeichert.','success');form.classList.add('d1-hidden');}catch(e){showMessage(e.message,'error');if(e.status===409)form.classList.add('d1-hidden');}finally{busy=false;updateSaveState();}}
loadButton.addEventListener('click',loadFlight);entryIdInput.addEventListener('keydown',e=>{if(e.key==='Enter')loadFlight();});form.addEventListener('submit',saveCorrection);resetButton.addEventListener('click',()=>{if(loaded)renderLoaded(loaded);});changeReason.addEventListener('input',()=>{updateReasonValidation();updateSaveState();});confirmWarning.addEventListener('change',updateSaveState);if(initialEntryId>0)loadFlight();
</script>
</body>
</html>
