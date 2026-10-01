<?php
declare(strict_types=1);
require_once __DIR__.'/../src/qr_login_approval.php';
session_start_if_needed();
$token=trim((string)($_GET['token']??''));$error=null;$user=null;
try{
 qr_login_get_pending_session($token);
 $user=auth_user();
 if($user===null){
   $_SESSION['post_login_redirect']='qr_approve.php?token='.rawurlencode($token);
   header('Location: passkey_login.php');exit;
 }
}catch(Throwable $e){$error=$e->getMessage();}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR-Login bestätigen</title><link rel="stylesheet" href="app.css"><style>.wrap{max-width:640px;margin:28px auto;padding:0 12px}.notice{padding:16px;border-left:5px solid #1769aa;background:#eef6fc;border-radius:8px}.bad{border-color:#b00020;background:#fff0f0;color:#970018}.actions{margin-top:18px;display:flex;gap:10px}.status{margin-top:14px}</style>    <script src="i18n.js" defer></script>
</head><body><main class="wrap"><h1>C-Büro-Login</h1><section class="card">
<?php if($error!==null):?><div class="notice bad"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div>
<?php else:?><div class="notice"><strong>Anmeldung bestätigen</strong><p>Angemeldet als <?=htmlspecialchars((string)$user['display_name'],ENT_QUOTES,'UTF-8')?>.</p><p>Bestätige nur, wenn Du den QR-Code selbst am C-Büro-PC geöffnet hast.</p></div><div class="actions"><button id="approve" type="button">C-Büro anmelden</button><a class="button secondary" href="dashboard.php">Abbrechen</a></div><div id="status" class="status" hidden></div>
<script>const csrf=<?=json_encode(csrf_token(),JSON_THROW_ON_ERROR)?>;const token=<?=json_encode($token,JSON_THROW_ON_ERROR)?>;document.getElementById('approve').addEventListener('click',async()=>{const b=document.getElementById('approve'),s=document.getElementById('status');b.disabled=true;try{const r=await fetch('api_qr_login_approve.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:csrf,token})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'Freigabe fehlgeschlagen.');s.hidden=false;s.className='notice status';s.textContent=j.message;b.remove();}catch(e){s.hidden=false;s.className='notice bad status';s.textContent=e.message||String(e);b.disabled=false;}});</script>
<?php endif;?></section></main></body></html>
