/* LSZJ kTrax range import, bilingual and without native alert/confirm dialogs */
(function(){
'use strict';
const tr = key => window.lszjI18n ? window.lszjI18n.t(key) : key;
const lang = () => window.lszjI18n ? window.lszjI18n.lang() : 'de';
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const localDate = value => { const p=String(value||'').slice(0,10).split('-'); return p.length===3 ? `${p[2]}.${p[1]}.${p[0]}` : String(value||''); };
function ensureDialog(){
  let dialog=document.getElementById('lszj-ktrax-dialog');
  if(dialog) return dialog;
  dialog=document.createElement('dialog'); dialog.id='lszj-ktrax-dialog'; dialog.className='lszj-dialog';
  dialog.innerHTML='<form method="dialog"><h2 data-title></h2><div data-body></div><div class="lszj-dialog-actions"><button value="cancel" class="secondary" data-cancel></button><button value="ok" data-ok></button></div></form>';
  const style=document.createElement('style');
  style.textContent='.lszj-dialog{width:min(720px,calc(100vw - 32px));max-height:85vh;border:0;border-radius:14px;padding:0;box-shadow:0 18px 55px #0005}.lszj-dialog::backdrop{background:#0008}.lszj-dialog form{padding:22px}.lszj-dialog h2{margin:0 0 14px}.lszj-dialog [data-body]{line-height:1.5}.lszj-dialog-details{max-height:48vh;overflow:auto;border:1px solid #d9e0e8;border-radius:8px;margin-top:14px}.lszj-dialog-details table{margin:0}.lszj-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}.lszj-dialog-summary{display:grid;grid-template-columns:1fr auto;gap:6px 20px}';
  document.head.appendChild(style); document.body.appendChild(dialog); return dialog;
}
function openDialog({title,body,ok,cancel=true}){
  const d=ensureDialog(); d.querySelector('[data-title]').textContent=title; d.querySelector('[data-body]').innerHTML=body;
  const okBtn=d.querySelector('[data-ok]'), cancelBtn=d.querySelector('[data-cancel]'); okBtn.textContent=ok; cancelBtn.textContent=tr('Abbrechen'); cancelBtn.hidden=!cancel;
  return new Promise(resolve=>{ const done=()=>{d.removeEventListener('close',done);resolve(d.returnValue==='ok');};d.addEventListener('close',done);d.showModal();});
}
function detailLabel(detail){
  const status=tr(detail.status||''); const message=detail.message ? tr(detail.message) : '';
  return message && message.toLocaleLowerCase()!==status.toLocaleLowerCase() ? `${status} (${message})` : status;
}
window.importKTraxRange=async function(){
  const fromEl=document.getElementById('from'), toEl=document.getElementById('to');
  if(!fromEl||!toEl){await openDialog({title:tr('Fehler'),body:esc(tr('Datumsfilter Von/Bis nicht gefunden.')),ok:tr('Schliessen'),cancel:false});return;}
  const from=fromEl.value,to=toEl.value;
  if(!from||!to){await openDialog({title:tr('Fehler'),body:esc(tr('Bitte Von und Bis setzen.')),ok:tr('Schliessen'),cancel:false});return;}
  if(to<from){await openDialog({title:tr('Fehler'),body:esc(tr('Bis darf nicht vor Von liegen.')),ok:tr('Schliessen'),cancel:false});return;}
  const question=lang()==='fr' ? `Importer les données kTrax manquantes pour la période du ${localDate(from)} au ${localDate(to)} ?` : `Fehlende kTrax-Daten im Zeitraum ${localDate(from)} bis ${localDate(to)} importieren?`;
  if(!await openDialog({title:tr('Fehlende kTrax-Daten importieren?'),body:esc(question),ok:tr('Importieren')}))return;
  const btns=[...document.querySelectorAll('button')].filter(b=>(b.textContent||'').trim()===tr('kTrax-Import')||(b.textContent||'').trim()==='kTrax-Import');
  btns.forEach(b=>{b.disabled=true;b.dataset.oldText=b.textContent;b.textContent=tr('kTrax-Import läuft...');});
  try{
    const r=await fetch(`api_import_ktrax_range.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,{method:'POST'}); const j=await r.json();
    if(!j.ok)throw new Error(j.error||tr('kTrax-Import fehlgeschlagen.'));
    const summary=`<div class="lszj-dialog-summary"><span>${esc(tr('Tage geprüft:'))}</span><b>${esc(j.days_checked)}</b><span>${esc(tr('Bereits vorhanden:'))}</span><b>${esc(j.days_skipped)}</b><span>${esc(tr('Neu importiert:'))}</span><b>${esc(j.days_imported)}</b><span>${esc(tr('Fehler:'))}</span><b>${esc(j.days_failed)}</b></div>`;
    const rows=(j.details||[]).map(d=>`<tr><td>${esc(localDate(d.date))}</td><td>${esc(detailLabel(d))}</td></tr>`).join('');
    const details=rows?`<h3>${esc(tr('Details'))}</h3><div class="lszj-dialog-details"><table><tbody>${rows}</tbody></table></div>`:'';
    await openDialog({title:tr('kTrax-Import abgeschlossen.'),body:summary+details,ok:tr('Schliessen'),cancel:false});
    if(typeof window.loadData==='function')window.loadData();
  }catch(e){await openDialog({title:tr('Fehler'),body:esc(`${tr('kTrax-Import fehlgeschlagen.')} ${e.message}`),ok:tr('Schliessen'),cancel:false});}
  finally{btns.forEach(b=>{b.disabled=false;b.textContent=b.dataset.oldText||tr('kTrax-Import');});}
};
})();
