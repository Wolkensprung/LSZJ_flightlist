(() => {
  'use strict';
  const pageBase = new URL('.', window.location.href);
  const apiUrl = name => new URL('../src/' + name, pageBase).toString();
  const pilotPatterns = /(pilot_name|pilotname|tow_pilot|towpilot|attendant_name|attendantname|instructor)/i;
  const aircraftPatterns = /(callsign|aircraft|glider|towplane)/i;
  let showAllPilots = false;

  function fieldKey(el){ return [el.id, el.name, el.getAttribute('data-field')].filter(Boolean).join(' '); }
  function fieldType(el){
    const key = (
        [
            el.id || '',
            el.name || '',
            el.getAttribute('data-field') || '',
            el.placeholder || ''
        ].join(' ')
    ).toLowerCase();

    // Alle Pilotenfelder erkennen
    if (
        key.includes('pilot') ||
        key.includes('towpilot') ||
        key.includes('attendant') ||
        key.includes('begleiter')
    ) {
        return 'pilot';
    }

    // Alle Flugzeugfelder erkennen
    if (
        key.includes('callsign') ||
        key.includes('aircraft') ||
        key.includes('glider') ||
        key.includes('towplane') ||
        key.includes('flugzeug')
    ) {
        return 'aircraft';
    }

    return null;
  }
  function debounce(fn, wait=180){ let t; return (...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),wait)}; }
  function ensureToggle(){
    if (document.querySelector('.lszj-ac-toggle')) return;
    const first = [...document.querySelectorAll('input[type="text"],input:not([type])')].find(e=>fieldType(e)==='pilot');
    if (!first) return;
    const label=document.createElement('label'); label.className='lszj-ac-toggle';
    label.innerHTML='<input type="checkbox"> Alle bekannten Piloten anzeigen';
    label.querySelector('input').addEventListener('change', e=>{showAllPilots=e.target.checked;});
    const anchor=first.closest('.card,.panel,.form-section,fieldset,form') || first.parentElement;
    anchor.insertBefore(label, anchor.firstChild);
  }
  function enhance(input, type){
    if (input.dataset.lszjAutocomplete) return;
    input.dataset.lszjAutocomplete=type;
    input.setAttribute('autocomplete','off');
    const parent=input.parentElement;
    const wrap=document.createElement('div'); wrap.className='lszj-ac-wrap';
    parent.insertBefore(wrap,input); wrap.appendChild(input);
    const list=document.createElement('div'); list.className='lszj-ac-list'; list.hidden=true; wrap.appendChild(list);
    let controller=null, items=[], active=-1;
    const close=()=>{list.hidden=true;active=-1;};
    const render=data=>{
      items=data; active=-1; list.innerHTML='';
      if(!data.length){list.innerHTML='<div class="lszj-ac-empty">Keine Treffer</div>';list.hidden=false;return;}
      data.forEach((item,i)=>{
        const row=document.createElement('div'); row.className='lszj-ac-item'; row.tabIndex=-1;
        const main=type==='pilot'?item.display_name:item.callsign;
        const meta=type==='pilot'
          ? [item.priority_group,item.vf_user_no,item.email].filter(Boolean).join(' · ')
          : [item.competition_code,item.model_designation,item.aircraft_type].filter(Boolean).join(' · ');
        row.innerHTML='<div class="lszj-ac-main"></div><div class="lszj-ac-meta"></div>';
        row.children[0].textContent=main; row.children[1].textContent=meta;
        row.addEventListener('mousedown',e=>{e.preventDefault();select(item);});
        list.appendChild(row);
      }); list.hidden=false;
    };
    const select=item=>{
      input.value=type==='pilot'?item.display_name:item.callsign;
      input.dataset.masterId=type==='pilot'?item.vf_user_no:item.callsign;
      input.dispatchEvent(new Event('change',{bubbles:true})); close();
    };
    const search=debounce(async()=>{
      const q=input.value.trim(); if(!q){close();return;}
      if(controller) controller.abort(); controller=new AbortController();
      const endpoint=type==='pilot'?'api_search_pilots.php':'api_search_aircraft.php';
      const url=new URL(apiUrl(endpoint)); url.searchParams.set('q',q);
      if(type==='pilot'&&showAllPilots) url.searchParams.set('all','1');
      try{const r=await fetch(url,{signal:controller.signal});const j=await r.json();render(Array.isArray(j)?j:(j.items||[]));}
      catch(e){if(e.name!=='AbortError'){list.innerHTML='<div class="lszj-ac-empty">Suche fehlgeschlagen</div>';list.hidden=false;}}
    });
    input.addEventListener('input',search);
    input.addEventListener('focus',()=>{if(input.value.trim())search();});
    input.addEventListener('keydown',e=>{
      const rows=[...list.querySelectorAll('.lszj-ac-item')]; if(list.hidden||!rows.length)return;
      if(e.key==='ArrowDown'){e.preventDefault();active=(active+1)%rows.length;}
      else if(e.key==='ArrowUp'){e.preventDefault();active=(active-1+rows.length)%rows.length;}
      else if(e.key==='Enter'&&active>=0){e.preventDefault();select(items[active]);return;}
      else if(e.key==='Escape'){close();return;} else return;
      rows.forEach((r,i)=>r.classList.toggle('active',i===active)); rows[active].scrollIntoView({block:'nearest'});
    });
    document.addEventListener('mousedown',e=>{if(!wrap.contains(e.target))close();});
  }
  function scan(root=document){
    root.querySelectorAll('input[type="text"],input:not([type])').forEach(input=>{const type=fieldType(input);if(type)enhance(input,type);});
    ensureToggle();
  }
  document.addEventListener('DOMContentLoaded',()=>{
    scan();
    new MutationObserver(m=>m.forEach(x=>x.addedNodes.forEach(n=>{if(n.nodeType===1)scan(n)}))).observe(document.body,{childList:true,subtree:true});
  });
})();
