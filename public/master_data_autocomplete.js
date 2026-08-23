(() => {
  'use strict';

  const pageBase = new URL('.', window.location.href);
  const apiUrl = name => new URL('../src/' + name, pageBase).toString();

  let showAllPilots = false;

  // Ausschliesslich bestaetigte LSZJ-Feld-IDs und deren dynamische Varianten.
  function fieldType(input) {
    if (!(input instanceof HTMLInputElement)) return null;

    const id = input.id || '';

    // Manuelle Erfassung und Korrekturmaske.
    if (id === 'pilot' || id === 'att' || id === 'towpilot') {
      return 'pilot';
    }

    // Flugfreigaben: IDs enthalten die jeweilige Datensatznummer.
    if (/^g\d+_(pilot|att)$/.test(id) || /^m\d+_towpilot$/.test(id)) {
      return 'pilot';
    }

    // Flugzeugfelder werden weiterhin nur anhand eindeutiger Bezeichnungen erkannt.
    const key = [id, input.name || '', input.getAttribute('data-field') || '']
      .join(' ')
      .toLowerCase();

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

  function debounce(fn, wait = 180) {
    let timer = null;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), wait);
    };
  }

  function ensureToggle() {
    if (document.querySelector('.lszj-ac-toggle')) return;

    const firstPilot = [...document.querySelectorAll('input')]
      .find(input => fieldType(input) === 'pilot');

    if (!firstPilot) return;

    const label = document.createElement('label');
    label.className = 'lszj-ac-toggle';
    label.innerHTML = '<input type="checkbox"> Alle bekannten Piloten anzeigen';

    label.querySelector('input').addEventListener('change', event => {
      showAllPilots = event.target.checked;

      document.querySelectorAll('[data-lszj-autocomplete="pilot"]').forEach(input => {
        if (input.value.trim()) {
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    });

    const anchor = firstPilot.closest('.card,.panel,.form-section,fieldset,form')
      || firstPilot.parentElement;

    anchor.insertBefore(label, anchor.firstChild);
  }

  function enhance(input, type) {
    // Wichtig: Marker wird VOR der DOM-Aenderung gesetzt.
    // Dadurch kann der MutationObserver dasselbe Feld nie nochmals einpacken.
    if (input.dataset.lszjAutocomplete) return;
    input.dataset.lszjAutocomplete = type;
    input.setAttribute('autocomplete', 'off');

    const parent = input.parentElement;
    if (!parent) return;

    const wrap = document.createElement('div');
    wrap.className = 'lszj-ac-wrap';
    parent.insertBefore(wrap, input);
    wrap.appendChild(input);

    const list = document.createElement('div');
    list.className = 'lszj-ac-list';
    list.hidden = true;
    wrap.appendChild(list);

    let controller = null;
    let items = [];
    let active = -1;

    const close = () => {
      list.hidden = true;
      active = -1;
    };

    const select = item => {
      input.value = type === 'pilot' ? item.display_name : item.callsign;
      input.dataset.masterId = type === 'pilot' ? item.vf_user_no : item.callsign;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      close();
    };

    const render = data => {
      items = data;
      active = -1;
      list.innerHTML = '';

      if (!data.length) {
        list.innerHTML = '<div class="lszj-ac-empty">Keine Treffer</div>';
        list.hidden = false;
        return;
      }

      data.forEach(item => {
        const row = document.createElement('div');
        row.className = 'lszj-ac-item';
        row.tabIndex = -1;

        const main = type === 'pilot' ? item.display_name : item.callsign;
        const meta = type === 'pilot'
          ? [item.priority_group, item.vf_user_no, item.email].filter(Boolean).join(' · ')
          : [item.competition_code, item.model_designation, item.aircraft_type]
              .filter(Boolean)
              .join(' · ');

        row.innerHTML = '<div class="lszj-ac-main"></div><div class="lszj-ac-meta"></div>';
        row.children[0].textContent = main;
        row.children[1].textContent = meta;

        row.addEventListener('mousedown', event => {
          event.preventDefault();
          select(item);
        });

        list.appendChild(row);
      });

      list.hidden = false;
    };

    const search = debounce(async () => {
      const q = input.value.trim();

      if (!q) {
        close();
        return;
      }

      if (controller) controller.abort();
      controller = new AbortController();

      const endpoint = type === 'pilot'
        ? 'api_search_pilots.php'
        : 'api_search_aircraft.php';

      const url = new URL(apiUrl(endpoint));
      url.searchParams.set('q', q);

      if (type === 'pilot' && showAllPilots) {
        url.searchParams.set('all', '1');
      }

      try {
        const response = await fetch(url, { signal: controller.signal });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const json = await response.json();
        render(Array.isArray(json) ? json : (json.items || []));
      } catch (error) {
        if (error.name !== 'AbortError') {
          list.innerHTML = '<div class="lszj-ac-empty">Suche fehlgeschlagen</div>';
          list.hidden = false;
        }
      }
    });

    input.addEventListener('input', search);
    input.addEventListener('focus', () => {
      if (input.value.trim()) search();
    });

    input.addEventListener('keydown', event => {
      const rows = [...list.querySelectorAll('.lszj-ac-item')];
      if (list.hidden || !rows.length) return;

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        active = (active + 1) % rows.length;
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active = (active - 1 + rows.length) % rows.length;
      } else if (event.key === 'Enter' && active >= 0) {
        event.preventDefault();
        select(items[active]);
        return;
      } else if (event.key === 'Escape') {
        close();
        return;
      } else {
        return;
      }

      rows.forEach((row, index) => {
        row.classList.toggle('active', index === active);
      });
      rows[active].scrollIntoView({ block: 'nearest' });
    });

    document.addEventListener('mousedown', event => {
      if (!wrap.contains(event.target)) close();
    });
  }

  function scan(root = document) {
    // Falls der MutationObserver direkt ein Input liefert, muss auch root selbst
    // geprueft werden. querySelectorAll() wuerde root sonst auslassen.
    if (root instanceof HTMLInputElement) {
      const type = fieldType(root);
      if (type) enhance(root, type);
    }

    if (root.querySelectorAll) {
      root.querySelectorAll('input').forEach(input => {
        const type = fieldType(input);
        if (type) enhance(input, type);
      });
    }

    ensureToggle();
  }

  function start() {
    scan(document);

    // Der Observer ist fuer dynamisch geladene Freigabekarten notwendig.
    // Er beobachtet nur hinzugefuegte Elemente. Bereits markierte Inputs werden
    // nie erneut veraendert, daher entsteht keine Wrapper-/Checkbox-Schleife.
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType === Node.ELEMENT_NODE) scan(node);
        });
      });
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
