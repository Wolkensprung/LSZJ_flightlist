<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
$user = auth_user();

if ($user === null) { header('Location: login.php?reason=expired&return='.rawurlencode('passkeys.php')); exit; }
$stmt = db()->prepare('SELECT id,device_name,created_at,last_used_at FROM user_passkeys WHERE user_id=? AND revoked_at IS NULL ORDER BY created_at DESC');
$stmt->execute([(int)$user['id']]);
$passkeys = $stmt->fetchAll();

?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>LSZJ Passkeys</title><link rel="stylesheet" href="app.css">
<style>.wrap{max-width:760px;margin:auto}.status{padding:10px;border-radius:8px;margin-top:12px}.good{background:#eefaf1}.bad{background:#fff0f0;color:#970018}</style></head><body><div class="wrap">
<h1>Passkeys</h1><div class="card"><strong><?=htmlspecialchars((string)$user['display_name'],ENT_QUOTES,'UTF-8')?></strong>
<p>Registriere dieses persönliche Gerät für die Anmeldung mit Face ID, Fingerabdruck oder Geräte-PIN.</p>
<label>Gerätename <input id="device-name" maxlength="255" value="Persönliches Gerät"></label>
<button id="register" type="button">Passkey registrieren</button><div id="status" hidden></div></div>
<div class="card"><h2>Aktive Passkeys</h2><?php if(!$passkeys):?><p>Noch keine Passkeys registriert.</p><?php else:?><ul><?php foreach($passkeys as $p):?><li><?=htmlspecialchars((string)$p['device_name'],ENT_QUOTES,'UTF-8')?>, erstellt <?=htmlspecialchars((string)$p['created_at'],ENT_QUOTES,'UTF-8')?></li><?php endforeach;?></ul><?php endif;?></div>
<p><a class="button secondary" href="dashboard.php">Zurück zum Dashboard</a></p></div>
<script>
const csrf=<?=json_encode(csrf_token(),JSON_THROW_ON_ERROR)?>;
const statusBox=document.getElementById('status');
function show(message,ok=false){statusBox.hidden=false;statusBox.className='status '+(ok?'good':'bad');statusBox.textContent=message;}
function b64urlToBytes(value){const pad='='.repeat((4-value.length%4)%4);const b64=(value+pad).replace(/-/g,'+').replace(/_/g,'/');const raw=atob(b64);return Uint8Array.from(raw,c=>c.charCodeAt(0));}
function bytesToB64url(value){const bytes=new Uint8Array(value);let raw='';for(const b of bytes)raw+=String.fromCharCode(b);return btoa(raw).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
function prepareCreationOptions(o){o.challenge=b64urlToBytes(o.challenge);o.user.id=b64urlToBytes(o.user.id);if(o.excludeCredentials)o.excludeCredentials=o.excludeCredentials.map(c=>({...c,id:b64urlToBytes(c.id)}));return o;}
function credentialToJSON(c){return {id:c.id,type:c.type,rawId:bytesToB64url(c.rawId),response:{clientDataJSON:bytesToB64url(c.response.clientDataJSON),attestationObject:bytesToB64url(c.response.attestationObject)},clientExtensionResults:c.getClientExtensionResults(),authenticatorAttachment:c.authenticatorAttachment||null};}
document.getElementById('register').addEventListener('click',async()=>{try{
 if(!window.PublicKeyCredential)throw new Error('Dieser Browser unterstützt Passkeys nicht.');
 const r=await fetch('api_passkey_register_options.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf})});
 const payload=await r.json();if(!r.ok||payload.ok===false)throw new Error(payload.error||'Optionen konnten nicht geladen werden.');
 const credential=await navigator.credentials.create({publicKey:prepareCreationOptions(payload)});if(!credential)throw new Error('Registrierung wurde abgebrochen.');
 const finish=await fetch('api_passkey_register_finish.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf,device_name:document.getElementById('device-name').value,credential:credentialToJSON(credential)})});
 const result=await finish.json();if(!finish.ok||!result.ok)throw new Error(result.error||'Passkey konnte nicht gespeichert werden.');
 show(result.message,true);
 
 
 //setTimeout(()=>location.reload(),700);

show(result.message, true);

setTimeout(() => {
    location.reload();
}, 1000);

 }catch(e){show(e.message||String(e));}});
</script></body></html>
