/* LSZJ i18n hotfix 02
   Zweck: stabile DE/FR Umschaltung ohne fehleranfällige RegExp-Escapes.
   Lädt Übersetzungen aus api_i18n.php und nutzt Fallback, falls die DB/API nicht verfügbar ist.
*/
(function(){
  const LANG_KEY = 'lszj_lang';
  const fallbackPairs = {
    'pending': {de:'Offen', fr:'Ouvert'},
    'approved': {de:'Freigegeben', fr:'Validé'},
    'correction_required': {de:'Korrektur nötig', fr:'Correction requise'},
    'exported': {de:'Exportiert', fr:'Exporté'},
    'all': {de:'Alle', fr:'Tous'},
    'glider_tow': {de:'Segelflugschlepp', fr:'Remorquage planeur'},
    'self_launch': {de:'Eigenstart', fr:'Décollage autonome'},
    'towplane_only': {de:'Motorflug', fr:'Vol moteur'},
    'Segelflugschlepp': {de:'Segelflugschlepp', fr:'Remorquage planeur'},
    'Eigenstart': {de:'Eigenstart', fr:'Décollage autonome'},
    'Motorflug': {de:'Motorflug', fr:'Vol moteur'},
    'Dashboard': {de:'Dashboard', fr:'Tableau de bord'},
    'Flugfreigaben': {de:'Flugfreigaben', fr:'Validations des vols'},
    '+ Flug manuell erfassen': {de:'+ Flug manuell erfassen', fr:'+ Saisir un vol manuellement'},
    'Flug manuell erfassen': {de:'Flug manuell erfassen', fr:'Saisir un vol manuellement'},
    'Flug korrigieren': {de:'Flug korrigieren', fr:'Corriger le vol'},
    'Zurück zu Flugfreigaben': {de:'Zurück zu Flugfreigaben', fr:'Retour aux validations des vols'},
    'Von': {de:'Von', fr:'Du'},
    'Bis': {de:'Bis', fr:'Au'},
    'Status': {de:'Status', fr:'Statut'},
    'Benutzer': {de:'Benutzer', fr:'Utilisateur'},
    'Laden': {de:'Laden', fr:'Charger'},
    'Heute': {de:'Heute', fr:'Aujourd’hui'},
    'Gestern': {de:'Gestern', fr:'Hier'},
    'Letzte 7 Tage': {de:'Letzte 7 Tage', fr:'7 derniers jours'},
    'kTrax-Import': {de:'kTrax-Import', fr:'Import kTrax'},
    'kTrax-Import läuft...': {de:'kTrax-Import läuft...', fr:'Import kTrax en cours...'},
    'Zeitraum:': {de:'Zeitraum:', fr:'Période :'},
    'Operation': {de:'Operation', fr:'Opération'},
    'Segelflug': {de:'Segelflug', fr:'Planeur'},
    'Motorflug / F-Schlepp': {de:'Motorflug / F-Schlepp', fr:'Vol moteur / remorquage'},
    'Kein Segelflug zugeordnet.': {de:'Kein Segelflug zugeordnet.', fr:'Aucun vol planeur associé.'},
    'Kein Motorflug zugeordnet.': {de:'Kein Motorflug zugeordnet.', fr:'Aucun vol moteur associé.'},
    'Kein Motorflug/Schlepp vorhanden.': {de:'Kein Motorflug/Schlepp vorhanden.', fr:'Aucun vol moteur/remorquage disponible.'},
    'Kein Segelflug zu diesem Motorflug vorhanden.': {de:'Kein Segelflug zu diesem Motorflug vorhanden.', fr:'Aucun vol planeur pour ce vol moteur.'},
    'Schlepp zu Segelflug erfassen': {de:'Schlepp zu Segelflug erfassen', fr:'Saisir un remorquage pour le planeur'},
    'Segelflug zu Schleppflug erfassen': {de:'Segelflug zu Schleppflug erfassen', fr:'Saisir un planeur pour le remorquage'},
    'Start': {de:'Start', fr:'Départ'},
    'Landung': {de:'Landung', fr:'Atterrissage'},
    'Flugzeit': {de:'Flugzeit', fr:'Durée du vol'},
    'Schleppzeit': {de:'Schleppzeit', fr:'Durée du remorquage'},
    'Schlepp': {de:'Schlepp', fr:'Remorquage'},
    'Segelflugpilot': {de:'Segelflugpilot', fr:'Pilote planeur'},
    'Begleiter / FI': {de:'Begleiter / FI', fr:'Accompagnant / FI'},
    'Motorpilot': {de:'Motorpilot', fr:'Pilote moteur'},
    'Flugart': {de:'Flugart', fr:'Type de vol'},
    'Abrechnung': {de:'Abrechnung', fr:'Facturation'},
    'Kommentar': {de:'Kommentar', fr:'Commentaire'},
    'Startzeit': {de:'Startzeit', fr:'Heure de départ'},
    'Landung Segelflugzeug': {de:'Landung Segelflugzeug', fr:'Atterrissage du planeur'},
    'Landung Motorflugzeug': {de:'Landung Motorflugzeug', fr:'Atterrissage de l’avion moteur'},
    'Segelflugzeug': {de:'Segelflugzeug', fr:'Planeur'},
    'Motorflugzeug': {de:'Motorflugzeug', fr:'Avion moteur'},
    'Segelflug löschen': {de:'Segelflug löschen', fr:'Supprimer le vol planeur'},
    'Korrektur': {de:'Korrektur', fr:'Correction'},
    'Motorflug löschen': {de:'Motorflug löschen', fr:'Supprimer le vol moteur'},
    'Speichern & Freigeben': {de:'Speichern & Freigeben', fr:'Enregistrer et valider'},
    'Speichern': {de:'Speichern', fr:'Enregistrer'},
    'Abbrechen': {de:'Abbrechen', fr:'Annuler'},
    'Freigegeben, nicht exportiert': {de:'Freigegeben, nicht exportiert', fr:'Validé, non exporté'},
    'Bereits exportiert': {de:'Bereits exportiert', fr:'Déjà exporté'},
    'Manuelle Flugerfassung': {de:'Manuelle Flugerfassung', fr:'Saisie manuelle des vols'},
    'Exportvorschau': {de:'Exportvorschau', fr:'Aperçu de l’export'},
    'Vereinsflieger-CSV Export': {de:'Vereinsflieger-CSV Export', fr:'Export CSV Vereinsflieger'}
  };

  let table = {};
  let ready = false;
  let translating = false;

  function currentLang(){
    return localStorage.getItem(LANG_KEY) || getCookie('lszj_lang') || 'de';
  }

  function getCookie(name){
    const prefix = name + '=';
    const parts = document.cookie.split(';');
    for(const part of parts){
      const trimmed = part.trim();
      if(trimmed.indexOf(prefix) === 0) return decodeURIComponent(trimmed.slice(prefix.length));
    }
    return '';
  }

  function setLang(lang){
    localStorage.setItem(LANG_KEY, lang);
    document.cookie = 'lszj_lang=' + encodeURIComponent(lang) + '; path=/; max-age=31536000';
    location.reload();
  }

  function fallbackTable(lang){
    const out = {};
    Object.keys(fallbackPairs).forEach(function(key){
      out[key] = fallbackPairs[key][lang] || fallbackPairs[key].de || key;
    });
    return out;
  }

  async function loadTable(){
    const lang = currentLang();
    table = fallbackTable(lang);
    try {
      const response = await fetch('api_i18n.php?lang=' + encodeURIComponent(lang) + '&_=' + Date.now());
      const payload = await response.json();
      if(payload && payload.ok && payload.translations){
        table = Object.assign(table, payload.translations);
      }
    } catch(error) {
      console.warn('LSZJ i18n: DB fallback active', error);
    }
    ready = true;
  }

  function replaceAllPlain(text, search, replacement){
    if(!search || text.indexOf(search) === -1) return text;
    return text.split(search).join(replacement);
  }

  function translateText(text){
    if(!text) return text;
    if(Object.prototype.hasOwnProperty.call(table, text)) return table[text];
    let translated = String(text);
    const keys = Object.keys(table).sort(function(a,b){ return b.length - a.length; });
    keys.forEach(function(key){
      translated = replaceAllPlain(translated, key, table[key]);
    });
    return translated;
  }

  function shouldSkipNode(node){
    const parent = node && node.parentElement;
    if(!parent) return false;
    const tag = (parent.tagName || '').toLowerCase();
    return ['script','style','textarea'].indexOf(tag) >= 0;
  }

  function translateNode(node){
    if(!node || !node.nodeValue || shouldSkipNode(node)) return;
    const original = node.nodeValue;
    const translated = translateText(original);
    if(translated !== original) node.nodeValue = translated;
  }

  function translateAttributes(element){
    ['title','placeholder','aria-label','value'].forEach(function(attr){
      if(!element.hasAttribute || !element.hasAttribute(attr)) return;
      if(attr === 'value' && ['button','submit','reset'].indexOf((element.type || '').toLowerCase()) < 0) return;
      const original = element.getAttribute(attr);
      const translated = translateText(original);
      if(translated !== original) element.setAttribute(attr, translated);
    });
  }

  function addLangSwitcher(){
    const nav = document.querySelector('.nav');
    if(!nav) return;
    if(!document.querySelector('.lang-switch')){
      const box = document.createElement('span');
      box.className = 'lang-switch';
      box.innerHTML = ' <button type="button" data-lang="de">DE</button><button type="button" data-lang="fr">FR</button>';
      box.querySelector('[data-lang="de"]').onclick = function(){ setLang('de'); };
      box.querySelector('[data-lang="fr"]').onclick = function(){ setLang('fr'); };
      nav.appendChild(box);
    }
    updateLangSwitcher();
  }

  function updateLangSwitcher(){
    const lang = currentLang();
    document.querySelectorAll('.lang-switch button').forEach(function(button){
      button.classList.toggle('active', button.dataset.lang === lang);
    });
  }

  function translateAll(){
    if(!ready || translating) return;
    translating = true;
    try {
      addLangSwitcher();
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
      const nodes = [];
      let current;
      while((current = walker.nextNode())) nodes.push(current);
      nodes.forEach(translateNode);
      document.querySelectorAll('input,button,a,option').forEach(translateAttributes);
      updateLangSwitcher();
    } finally {
      translating = false;
    }
  }

  window.lszjI18n = {
    t: translateText,
    translateAll: translateAll,
    setLang: setLang,
    lang: currentLang
  };

  document.addEventListener('DOMContentLoaded', async function(){
    await loadTable();
    translateAll();
  });

  const observer = new MutationObserver(function(){
    translateAll();
  });
  observer.observe(document.documentElement, {childList:true, subtree:true});
})();
