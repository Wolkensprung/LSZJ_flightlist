/* Development helper: reports likely untranslated German UI texts in FR mode. */
(function(){
'use strict';
const markers=/\b(Alle|Bitte|Start|Landung|Flug|Schlepp|Motor|Pilot|Fehler|Speichern|Abbrechen|Löschen|Prüfen|Keine|Kein|Tage|Bereits|Neu|Details|Von|Bis|Heute|Gestern|Zeitraum|Benutzer|Rolle|Dienst|Import|Export)\b|[ÄÖÜäöüß]/;
function scan(){
 if((window.lszjI18n?.lang?.()||'de')!=='fr')return;
 const findings=[]; const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
 let n; while((n=walker.nextNode())){const p=n.parentElement;if(!p||['SCRIPT','STYLE','TEXTAREA','OPTION'].includes(p.tagName))continue;const t=n.nodeValue.trim();if(t&&markers.test(t))findings.push({text:t,element:p.tagName.toLowerCase(),class:p.className||''});}
 document.querySelectorAll('option').forEach(o=>{const t=o.textContent.trim();if(t&&markers.test(t))findings.push({text:t,element:'option',class:''});});
 window.lszjI18nAudit=findings; if(findings.length)console.groupCollapsed(`LSZJ i18n audit: ${findings.length} mögliche Resttexte`),console.table(findings),console.groupEnd();
}
window.addEventListener('load',()=>setTimeout(scan,800));
})();
