<?php
declare(strict_types=1);
require_once __DIR__.'/../src/recovery_complete.php';

$error=null;$user=null;$completed=false;
$rawToken=trim((string)($_POST['token']??$_GET['token']??''));
if ($rawToken==='') {
    $error='Recovery-Link ist ungültig oder unvollständig.';
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        csrf_require_valid($_POST['csrf_token']??null);
        $mode=(string)($_POST['recovery_mode']??'replace');
        if (!in_array($mode,['replace','add'],true)) throw new RuntimeException('Ungültige Recovery-Auswahl.');
        $user=recovery_consume_token($rawToken);
        auth_login((int)$user['id'],SESSION_DEVICE_SMARTPHONE);
        recovery_start_passkey_replacement((int)$user['id'],$mode==='replace');
        $completed=true;
    } catch (Throwable $e) {
        error_log('Recovery complete POST: '.$e->getMessage());
        $error=$e->getMessage();
    }
} else {
    try {$user=recovery_validate_token($rawToken);}
    catch (Throwable $e) {error_log('Recovery complete GET: '.$e->getMessage());$error=$e->getMessage();}
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>LSZJ Passkey-Recovery</title><link rel="stylesheet" href="app.css">
<style>
:root{--blue:#1769aa;--blue-dark:#0f568e;--green:#17823b;--red:#b00020;--text:#1f2937;--muted:#667085;--border:#d9e0e8;--bg:#f4f6f8}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text)}.shell{width:min(calc(100% - 24px),700px);margin:24px auto}.card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 24px rgba(15,23,42,.08);padding:clamp(20px,5vw,38px);overflow:hidden}.kicker{margin:0 0 8px;color:var(--blue);font-size:.85rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}h1{margin:0 0 16px}.panel{padding:18px 20px;border-radius:9px;border-left:5px solid;line-height:1.55}.ok{border-left-color:var(--green);background:#eefaf1}.bad{border-left-color:var(--red);background:#fff0f0;color:#970018}.title{display:block;margin-bottom:6px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}.primary,.secondary{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding:10px 18px;border-radius:8px;font:inherit;font-weight:700;text-decoration:none}.primary{border:0;background:var(--blue);color:#fff;cursor:pointer}.secondary{border:1px solid #9aa6b2;background:#fff;color:#344054}
.choice{display:grid;grid-template-columns:20px minmax(0,1fr);gap:6px 10px;width:100%;min-width:0;margin-top:14px;padding:14px;border:1px solid var(--border);border-radius:9px;cursor:pointer}.choice:has(input:checked){border-color:var(--blue);background:#eef6fc}.choice input{grid-column:1;grid-row:1;margin:3px 0 0}.choice strong{grid-column:2;grid-row:1;min-width:0;white-space:normal;overflow-wrap:break-word;word-break:normal}.choice span{grid-column:2;grid-row:2;min-width:0;margin:0;color:var(--muted);line-height:1.45;white-space:normal;overflow-wrap:break-word;word-break:normal}
@media(max-width:520px){.shell{width:calc(100% - 16px);margin:8px auto}.card{padding:20px 16px}.primary,.secondary{width:100%}}
</style>    <script src="i18n.js?v=20261003_3" defer></script>
</head><body><main class="shell"><section class="card"><p class="kicker">LSZJ Startliste</p><h1>Passkey-Recovery</h1>
<?php if($error!==null): ?><div class="panel bad"><strong class="title">Recovery nicht möglich</strong><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><div class="actions"><a class="primary" href="recovery_request.php">Neuen Recovery-Link anfordern</a><a class="secondary" href="passkey_login.php">Zur Anmeldung</a></div>
<?php elseif($completed): ?><div class="panel ok"><strong class="title">Recovery bestätigt</strong>Du bist angemeldet als <?=htmlspecialchars((string)$user['display_name'],ENT_QUOTES,'UTF-8')?>.</div><p>Registriere jetzt den neuen Passkey. Im Verlustmodus werden bisherige Passkeys erst nach erfolgreicher Registrierung widerrufen.</p><div class="actions"><a class="primary" href="passkeys.php">Neuen Passkey registrieren</a></div>
<?php else: ?><div class="panel ok"><strong class="title">Recovery-Link gültig</strong>Bestätige die Wiederherstellung für <?=htmlspecialchars((string)$user['display_name'],ENT_QUOTES,'UTF-8')?>.</div><form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="token" value="<?=htmlspecialchars($rawToken,ENT_QUOTES,'UTF-8')?>">
<label class="choice"><input type="radio" name="recovery_mode" value="replace" checked><strong>Gerät verloren oder nicht mehr vertrauenswürdig</strong><span>Nach erfolgreicher Registrierung des neuen Passkeys werden alle bisherigen aktiven Passkeys widerrufen.</span></label>
<label class="choice"><input type="radio" name="recovery_mode" value="add"><strong>Nur ein zusätzliches Gerät registrieren</strong><span>Bestehende Passkeys bleiben aktiv.</span></label>
<div class="actions"><button class="primary" type="submit">Recovery bestätigen</button><a class="secondary" href="passkey_login.php">Abbrechen</a></div></form><?php endif; ?>
</section></main></body></html>
