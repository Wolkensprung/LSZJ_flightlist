(() => {
  'use strict';

  const pageBase = new URL('.', window.location.href);
  const apiUrl = name => new URL('../src/' + name, pageBase).toString();
  let showAllPilots = false;

  function fieldType(input) {
    if (!(input instanceof HTMLInputElement)) return null;
    const id = input.id || '';

    if (id === 'pilot' || id === 'att' || id === 'towpilot') return 'pilot';
    if (/^g\d+_(pilot|att)$/.test(id) || /^m\d+_towpilot$/.test(id)) return 'pilot';

    const key = [id, input.name || '', input.getAttribute('data-field') || '']
      .join(' ').toLowerCase();
    if (key.includes('callsign') || key.includes('aircraft') ||
        key.includes('glider') || key.includes('towplane') ||
        key.includes('flugzeug')) return 'aircraft';

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
        if (input.value.trim() && input.dataset.externalMode !== '1') {
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    });

    const anchor = firstPilot.closest('.card,.panel,.form-section,fieldset,form')
      || firstPilot.parentElement;
    anchor.insertBefore(label, anchor.firstChild);
  }

  function createExternalPanel(input, wrap, closeList) {
    const panel = document.createElement('div');
    panel.className = 'lszj-external-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <div class="lszj-external-title">Externer Pilot / FI</div>
      <div class="lszj-external-note">
        Diese Angaben werden für Vereinsflieger und die Rechnungsstellung benötigt.
        Im Export wird automatisch „Nachname, Vorname“ gebildet.
      </div>
      <div class="lszj-external-row">
        <label>Nachname *<input type="text" data-external="last_name" maxlength="100" autocomplete="family-name"></label>
        <label>Vorname *<input type="text" data-external="first_name" maxlength="100" autocomplete="given-name"></label>
      </div>
      <div class="lszj-external-row">
        <label>Mailadresse *<input type="email" data-external="email" maxlength="255" autocomplete="email"></label>
        <label>Telefon *<input type="tel" data-external="phone" maxlength="50" autocomplete="tel"></label>
      </div>
      <div class="lszj-external-actions">
        <button type="button" class="lszj-external-save">Kontakt übernehmen</button>
        <button type="button" class="lszj-external-cancel">Abbrechen</button>
      </div>
      <div class="lszj-external-error" hidden></div>`;
    wrap.appendChild(panel);

    const field = name => panel.querySelector(`[data-external="${name}"]`);
    const error = panel.querySelector('.lszj-external-error');

    function hide() {
      panel.hidden = true;
      error.hidden = true;
      error.textContent = '';
    }

    function show(prefill = '') {
      closeList();
      panel.hidden = false;
      input.dataset.externalMode = '1';
      input.dataset.masterId = '';

      if (prefill && !field('last_name').value && !field('first_name').value) {
        const parts = prefill.split(',').map(v => v.trim());
        if (parts.length >= 2) {
          field('last_name').value = parts.shift();
          field('first_name').value = parts.join(', ');
        }
      }
      field('last_name').focus();
    }

    panel.querySelector('.lszj-external-cancel').addEventListener('click', () => {
      input.dataset.externalMode = '0';
      hide();
      input.focus();
    });

    panel.querySelector('.lszj-external-save').addEventListener('click', async () => {
      const data = {
        action: 'create',
        last_name: field('last_name').value.trim(),
        first_name: field('first_name').value.trim(),
        email: field('email').value.trim(),
        phone: field('phone').value.trim()
      };

      if (!data.last_name || !data.first_name || !data.email || !data.phone) {
        error.textContent = 'Nachname, Vorname, Mailadresse und Telefon sind Pflichtfelder.';
        error.hidden = false;
        return;
      }

      if (!field('email').checkValidity()) {
        error.textContent = 'Bitte eine gültige Mailadresse eingeben.';
        error.hidden = false;
        field('email').focus();
        return;
      }

      const displayName = `${data.last_name}, ${data.first_name}`;

      try {
        const response = await fetch(apiUrl('api_external_contacts.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        const json = await response.json();
        if (!response.ok || !json.ok) {
          throw new Error(json.error || `HTTP ${response.status}`);
        }

        input.value = displayName;
        input.dataset.externalMode = '1';
        input.dataset.externalContactId = String(json.id);
        input.dataset.masterId = '';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        hide();
      } catch (e) {
        error.textContent = `Externer Kontakt konnte nicht gespeichert werden: ${e.message}`;
        error.hidden = false;
      }
    });

    return { show, hide };
  }

  function enhance(input, type) {
    if (input.dataset.lszjAutocomplete) return;
    input.dataset.lszjAutocomplete = type;
    input.dataset.externalMode = '0';
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

    const externalPanel = type === 'pilot'
      ? createExternalPanel(input, wrap, close)
      : null;

    const select = item => {
      input.value = type === 'pilot' ? item.display_name : item.callsign;
      input.dataset.masterId = type === 'pilot' ? item.vf_user_no : item.callsign;
      input.dataset.externalMode = '0';
      delete input.dataset.externalContactId;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      if (externalPanel) externalPanel.hide();
      close();
    };

    const render = data => {
      items = data;
      active = -1;
      list.innerHTML = '';

      if (!data.length) {
        const empty = document.createElement('div');
        empty.className = 'lszj-ac-empty';
        empty.textContent = 'Keine Treffer';
        list.appendChild(empty);

        if (type === 'pilot') {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'lszj-ac-external-button';
          button.textContent = 'Externen Pilot / FI erfassen';
          button.addEventListener('mousedown', event => {
            event.preventDefault();
            externalPanel.show(input.value.trim());
          });
          list.appendChild(button);
        }

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
              .filter(Boolean).join(' · ');

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
      if (!q || input.dataset.externalMode === '1') {
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
      if (type === 'pilot' && showAllPilots) url.searchParams.set('all', '1');

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

    input.addEventListener('input', () => {
      input.dataset.externalMode = '0';
      delete input.dataset.externalContactId;
      if (externalPanel) externalPanel.hide();
      search();
    });
    input.addEventListener('focus', () => {
      if (input.value.trim() && input.dataset.externalMode !== '1') search();
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

      rows.forEach((row, index) => row.classList.toggle('active', index === active));
      rows[active].scrollIntoView({ block: 'nearest' });
    });

    document.addEventListener('mousedown', event => {
      if (!wrap.contains(event.target)) close();
    });
  }

  function scan(root = document) {
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
