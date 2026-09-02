<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/page_security.php';
$currentUser=lszj_require_page_role('ADMIN');
$csrf=csrf_token();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LSZJ Benutzerverwaltung</title>
<link rel="stylesheet" href="app.css">
<style>
.ua-wrap{max-width:980px;margin:auto}.ua-search{position:relative}.ua-search input{width:100%;box-sizing:border-box;padding:10px}.ua-results{position:absolute;z-index:20;left:0;right:0;background:#fff;border:1px solid #bbb;max-height:300px;overflow:auto}.ua-results[hidden]{display:none}.ua-item{padding:10px;border-bottom:1px solid #eee;cursor:pointer}.ua-item:hover{background:#eef5ff}.ua-meta{font-size:12px;color:#667085}.ua-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.ua-role{border:1px solid #ccd3da;border-radius:8px;padding:10px}.ua-role label{display:block;margin-top:7px}.ua-role input[type=datetime-local]{width:100%;box-sizing:border-box}.ua-message{padding:10px;border-radius:7px}.ua-ok{background:#eefaf1}.ua-error{background:#fff0f0;color:#a00018}.muted{color:#667085}
</style>
</head>
<body>
<div class="ua-wrap">
<div class="nav"><a href="dashboard.php">Dashboard</a><a href="flight_approvals.php">Flugfreigaben</a><a href="manual_flight.php">+ Flug manuell erfassen</a><a href="duty_officer.php">Flugdienstleiter</a><a href="user_admin.php">Benutzerverwaltung</a><a href="logout.php">Logout</a></div>
<h1>Benutzerverwaltung</h1>
<p class="muted">Vereinsflieger bleibt führend für Stammdaten. Hier werden nur LSZJ-Aktivstatus und Rollen verwaltet.</p>
<div id="message" hidden></div>
<div class="card ua-search">
<label for="userSearch"><strong>Benutzer suchen</strong></label>
<input id="userSearch" autocomplete="off" placeholder="Name, Mitgliedernummer oder E-Mail eingeben">
<div id="results" class="ua-results" hidden></div>
</div>
<form id="userForm" class="card" hidden>
<input type="hidden" id="userId">
<h2 id="displayName"></h2>
<div class="ua-grid">
<div><strong>Quelle</strong><div id="source"></div></div><div><strong>Kostenstufe</strong><div id="costLevel"></div></div><div><strong>Prioritätsgruppe</strong><div id="priorityGroup"></div></div><div><strong>Vereinsflieger-Nr.</strong><div id="vfNo"></div></div><div><strong>E-Mail</strong><div id="email"></div></div><div><strong>Letzter Login</strong><div id="lastLogin"></div></div>
</div>
<p><label><input type="checkbox" id="active"> Lokaler Benutzer aktiv</label></p>
<h3>Rollen</h3>
<div id="roles" class="ua-grid"></div>
<p class="muted">Rollenänderungen werden für bereits angemeldete Benutzer bei der nächsten Anmeldung wirksam.</p>
<button type="submit">Speichern</button>
</form>
</div>
<script>
(()=>{'use strict';
const csrf=<?= json_encode($csrf,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const q=document.getElementById('userSearch'),results=document.getElementById('results'),form=document.getElementById('userForm'),msg=document.getElementById('message');
let timer=null;
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
function showMessage(text,ok){msg.hidden=false;msg.className='ua-message '+(ok?'ua-ok':'ua-error');msg.textContent=text}
function localDate(v){if(!v)return '';return String(v).replace(' ','T').slice(0,16)}
q.addEventListener('input',()=>{clearTimeout(timer);const value=q.value.trim();if(value.length<2){results.hidden=true;return}timer=setTimeout(()=>search(value),180)});
async function search(value){const r=await fetch('api_user_admin.php?action=search&q='+encodeURIComponent(value),{credentials:'same-origin'});const j=await r.json();if(!j.ok){showMessage(j.error,false);return}results.innerHTML='';for(const u of j.items){const row=document.createElement('div');row.className='ua-item';row.innerHTML='<strong>'+esc(u.display_name)+'</strong><div class="ua-meta">'+esc([u.cost_level,u.priority_group,u.email,u.active==1?'aktiv':'inaktiv'].filter(Boolean).join(' · '))+'</div>';row.addEventListener('mousedown',e=>{e.preventDefault();loadUser(u.id)});results.appendChild(row)}results.hidden=!j.items.length}
async function loadUser(id){results.hidden=true;const r=await fetch('api_user_admin.php?action=get&id='+encodeURIComponent(id),{credentials:'same-origin'});const j=await r.json();if(!j.ok){showMessage(j.error,false);return}render(j.user)}
function render(u){q.value=u.display_name;form.hidden=false;document.getElementById('userId').value=u.id;document.getElementById('displayName').textContent=u.display_name;document.getElementById('source').textContent=u.source||'';document.getElementById('costLevel').textContent=u.cost_level||'';document.getElementById('priorityGroup').textContent=u.priority_group||'';document.getElementById('vfNo').textContent=u.vf_user_no||u.vf_member_no||'';document.getElementById('email').textContent=u.email||'';document.getElementById('lastLogin').textContent=u.last_login||'';document.getElementById('active').checked=Number(u.active)===1;const box=document.getElementById('roles');box.innerHTML='';for(const role of u.roles){const code=role.code;const item=document.createElement('div');item.className='ua-role';item.dataset.code=code;item.innerHTML='<label><input type="checkbox" class="enabled"> <strong>'+esc(role.name)+'</strong> ('+esc(code)+')</label><label>Gültig von<input type="datetime-local" class="valid-from"></label><label>Gültig bis<input type="datetime-local" class="valid-until"></label>';item.querySelector('.enabled').checked=Number(role.assigned)===1;item.querySelector('.valid-from').value=localDate(role.valid_from);item.querySelector('.valid-until').value=localDate(role.valid_until);box.appendChild(item)} }
form.addEventListener('submit',async e=>{e.preventDefault();const roles=[...document.querySelectorAll('.ua-role')].map(x=>({code:x.dataset.code,enabled:x.querySelector('.enabled').checked,valid_from:x.querySelector('.valid-from').value,valid_until:x.querySelector('.valid-until').value}));const body={action:'save',csrf_token:csrf,user_id:Number(document.getElementById('userId').value),active:document.getElementById('active').checked,roles};const r=await fetch('api_user_admin.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(body)});const j=await r.json();if(!j.ok){showMessage(j.error,false);return}showMessage(j.message,true);render(j.user)});
})();
</script>
</body></html>
