/* LSZJ kTrax range import button */
async function importKTraxRange(){
  const fromEl = document.getElementById('from');
  const toEl = document.getElementById('to');
  if(!fromEl || !toEl){ alert('Datumsfilter Von/Bis nicht gefunden.'); return; }
  const from = fromEl.value;
  const to = toEl.value;
  if(!from || !to){ alert('Bitte Von und Bis setzen.'); return; }
  if(to < from){ alert('Bis darf nicht vor Von liegen.'); return; }
  if(!confirm('Fehlende kTrax-Daten im Zeitraum '+from+' bis '+to+' importieren?')) return;
  const btns = Array.from(document.querySelectorAll('button')).filter(b => (b.textContent||'').trim() === 'kTrax-Import');
  btns.forEach(b => { b.disabled = true; b.dataset.oldText = b.textContent; b.textContent = 'kTrax-Import läuft...'; });
  try {
    const url = '../src/api_import_ktrax_range.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    const r = await fetch(url, {method:'POST'});
    const j = await r.json();
    if(!j.ok){ alert(j.error || 'kTrax-Import fehlgeschlagen.'); return; }
    let msg = 'kTrax-Import abgeschlossen.\n\n'
      + 'Tage geprüft: ' + j.days_checked + '\n'
      + 'Bereits vorhanden: ' + j.days_skipped + '\n'
      + 'Neu importiert: ' + j.days_imported + '\n'
      + 'Fehler: ' + j.days_failed;
    if(j.details && j.details.length){
      msg += '\n\nDetails:';
      j.details.forEach(d => { msg += '\n- ' + d.date + ': ' + d.status + (d.message ? ' ('+d.message+')' : ''); });
    }
    alert(msg);
    if(typeof loadData === 'function') loadData();
  } catch(e){
    alert('kTrax-Import fehlgeschlagen: ' + e.message);
  } finally {
    btns.forEach(b => { b.disabled = false; b.textContent = b.dataset.oldText || 'kTrax-Import'; });
  }
}
