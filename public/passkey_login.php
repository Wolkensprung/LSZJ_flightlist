<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/session.php';
require_once __DIR__ . '/../src/db.php';

$config = app_config();
$legacyLoginEnabled = (bool)($config['auth']['legacy_login_enabled'] ?? true);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ Anmeldung</title>
    <link rel="stylesheet" href="app.css">
    <style>
        :root{--blue:#1769aa;--blue-dark:#0f568e;--text:#1f2937;--muted:#667085;--border:#d9e0e8;--bg:#f4f6f8;--red:#b00020}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text)}
        .shell{width:min(calc(100% - 24px),680px);margin:32px auto}.login-card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.08);padding:clamp(22px,5vw,38px)}
        .kicker{margin:0 0 8px;color:var(--blue);font-size:.85rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}h1{margin:0 0 12px}.intro{color:var(--muted);line-height:1.55}
        .primary{width:100%;min-height:48px;border:0;border-radius:8px;background:var(--blue);color:#fff;font:inherit;font-weight:800;cursor:pointer}.primary:hover{background:var(--blue-dark)}.primary:disabled{opacity:.6;cursor:wait}
        .status{margin-top:16px;padding:14px;border-left:5px solid var(--blue);background:#eef6fc;border-radius:8px}.bad{border-color:var(--red);background:#fff0f0;color:#970018}
        .divider{display:flex;align-items:center;gap:12px;margin:26px 0;color:var(--muted);font-size:.9rem}.divider::before,.divider::after{content:"";height:1px;flex:1;background:var(--border)}
        .setup{padding:17px;border:1px solid var(--border);border-radius:10px;background:#fafbfc}.setup h2{margin:0 0 8px;font-size:1.12rem}.setup p{margin:6px 0;color:var(--muted);line-height:1.5}.setup-link{display:inline-flex;margin-top:10px;font-weight:700;color:var(--blue-dark)}
        .test-login{margin-top:28px;padding-top:18px;border-top:1px solid var(--border);font-size:.9rem;color:var(--muted)}.test-login a{color:#475467}.test-badge{display:inline-block;margin-right:7px;padding:2px 7px;border-radius:999px;background:#fff3cd;color:#73510d;font-size:.75rem;font-weight:800;text-transform:uppercase}
    </style>
    <script src="i18n.js?v=20261003_3" defer></script>
</head>
<body>
<main class="shell"><section class="login-card">
    <p class="kicker">LSZJ Startliste</p>
    <h1>Anmelden</h1>
    <p class="intro">Melde Dich mit dem persönlichen Passkey auf diesem Smartphone oder Computer an.</p>
    <button id="login" class="primary" type="button">Mit Passkey anmelden</button>
    <div id="status" class="status" role="status" aria-live="polite" hidden></div>

    <div class="divider">oder</div>

    <section class="setup">
        <h2>Noch keinen Passkey oder neues Smartphone?</h2>
        <p>Fordere einen sicheren Link per E-Mail an und richte den Passkey auf diesem persönlichen Gerät ein.</p>
        <a class="setup-link" href="recovery_request.php">Passkey erstmals einrichten</a>
    </section>

    <?php if ($legacyLoginEnabled): ?>
        <p class="test-login"><span class="test-badge">Test</span><a href="login.php">Vorläufig als anderer Benutzer anmelden</a></p>
    <?php endif; ?>
</section></main>
<script>
const csrf = <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>;
const button = document.getElementById('login');
const statusBox = document.getElementById('status');

function show(message, bad = false){statusBox.hidden=false;statusBox.className='status'+(bad?' bad':'');statusBox.textContent=message;}
function b64urlToBytes(value){const pad='='.repeat((4-value.length%4)%4);const raw=atob((value+pad).replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(raw,c=>c.charCodeAt(0));}
function bytesToB64url(value){const bytes=new Uint8Array(value);let raw='';for(const b of bytes)raw+=String.fromCharCode(b);return btoa(raw).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
function prepare(options){options.challenge=b64urlToBytes(options.challenge);if(options.allowCredentials)options.allowCredentials=options.allowCredentials.map(c=>({...c,id:b64urlToBytes(c.id)}));return options;}
function assertionToJSON(c){return{id:c.id,type:c.type,rawId:bytesToB64url(c.rawId),response:{clientDataJSON:bytesToB64url(c.response.clientDataJSON),authenticatorData:bytesToB64url(c.response.authenticatorData),signature:bytesToB64url(c.response.signature),userHandle:c.response.userHandle?bytesToB64url(c.response.userHandle):null},clientExtensionResults:c.getClientExtensionResults(),authenticatorAttachment:c.authenticatorAttachment||null};}

button.addEventListener('click',async()=>{
    button.disabled=true;statusBox.hidden=true;
    try{
        if(!window.PublicKeyCredential)throw new Error('Dieser Browser unterstützt Passkeys nicht.');
        const request=await fetch('api_passkey_login_options.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf})});
        const options=await request.json();if(!request.ok||options.ok===false)throw new Error(options.error||'Anmeldeoptionen konnten nicht geladen werden.');
        const credential=await navigator.credentials.get({publicKey:prepare(options)});if(!credential)throw new Error('Anmeldung wurde abgebrochen.');
        const finish=await fetch('api_passkey_login_finish.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf,credential:assertionToJSON(credential)})});
        const result=await finish.json();if(!finish.ok||!result.ok)throw new Error(result.error||'Anmeldung fehlgeschlagen.');
        window.location.assign(result.redirect||'dashboard.php');
    }catch(error){show(error?.name==='NotAllowedError'?'Die Anmeldung wurde abgebrochen oder es ist kein passender Passkey verfügbar.':(error.message||String(error)),true);button.disabled=false;}
});
</script></body></html>
