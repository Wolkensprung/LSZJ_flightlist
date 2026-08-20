<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LSZJ Freigabe</title>
<style>
body{font-family:Arial,sans-serif;margin:16px;background:#f7f7f7;color:#222}.card{background:white;border-radius:12px;padding:14px;margin:10px 0;box-shadow:0 1px 4px #ddd}.badge{display:inline-block;padding:3px 8px;border-radius:10px;background:#eee}.row{display:flex;gap:8px;flex-wrap:wrap}.row>*{flex:1}input,select,textarea,button{font-size:16px;padding:8px;border:1px solid #ccc;border-radius:8px}button{background:#0b64c0;color:#fff;border:0}.small{font-size:12px;color:#666}.danger{background:#b00020}.muted{color:#666}</style>
</head>
<body>
<h1>LSZJ Freigabe</h1>
<div class="row"><input id="date" type="date"><button onclick="loadEntries()">Laden</button><button onclick="exportCsv()">CSV Export</button></div>
<div id="entries"></div>
<script>
const today = new Date().toISOString().slice(0,10); document.getElementById('date').value = today;
async function loadEntries(){
  const date = document.getElementById('date').value;
  const r = await fetch('../src/api_flights.php?date='+encodeURIComponent(date)+'&status=pending');
  const data = await r.json();
  const root = document.getElementById('entries'); root.innerHTML='';
  data.entries.forEach(e=>{
    const div=document.createElement('div'); div.className='card';
    div.innerHTML=`<div><span class="badge">${e.entry_type}</span> <b>${e.callsign||''}</b> <span class="muted">${e.departure_time||''}</span></div>
    <div class="row"><input placeholder="Pilot" value="${e.pilot_name||''}" id="p${e.id}"><input placeholder="Begleiter/FI" value="${e.attendant_name||''}" id="a${e.id}"></div>
    <div class="row"><input placeholder="Minuten" value="${e.flight_minutes||''}" id="m${e.id}"><input placeholder="Schlepp-Minuten" value="${e.tow_minutes||''}" id="tm${e.id}"></div>
    <textarea placeholder="Kommentar" id="c${e.id}" style="width:100%;box-sizing:border-box;margin-top:8px">${e.comment||''}</textarea>
    <div class="row" style="margin-top:8px"><button onclick="save(${e.id})">Speichern</button><button onclick="approve(${e.id})">Freigeben</button></div>
    <div class="small">Operation ${e.operation_id}, Rolle ${e.approval_role}, Startart ${e.start_type||''}, Tow ${e.tow_callsign||''}</div>`;
    root.appendChild(div);
  });
}
async function save(id){
  await fetch('../src/api_update_entry.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,user:'demo',pilot_name:document.getElementById('p'+id).value,attendant_name:document.getElementById('a'+id).value,flight_minutes:document.getElementById('m'+id).value,tow_minutes:document.getElementById('tm'+id).value,comment:document.getElementById('c'+id).value})});
  loadEntries();
}
async function approve(id){
  await fetch('../src/api_approve_entry.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,user:'demo'})});
  loadEntries();
}
function exportCsv(){ window.location='../src/export_vereinsflieger.php?date='+encodeURIComponent(document.getElementById('date').value); }
loadEntries();
</script>
</body>
</html>
