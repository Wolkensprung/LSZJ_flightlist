<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/session.php';
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recovery</title><link rel="stylesheet" href="app.css"></head><body>
<div class="card" style="max-width:700px;margin:40px auto;">
<h1>Passkey Recovery (Iteration 1)</h1>
<p>Nur für aktive Mitglieder aus <code>pilots_master</code>. Externe Kontakte sind ausgeschlossen.</p>
<label>Mailadresse <input id="email" type="email"></label>
<button id="btn">Recovery-Token erzeugen</button>
<pre id="out"></pre>
</div>
<script>
document.getElementById('btn').addEventListener('click',async()=>{
 const r=await fetch('api_recovery_request.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:document.getElementById('email').value})});
 const j=await r.json();
 document.getElementById('out').textContent=JSON.stringify(j,null,2);
});
</script>
</body></html>
