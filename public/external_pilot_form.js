// external_pilot_form.js
// LSZJ - GUI-Komponente für externe Piloten / FI

window.LSZJExternalPilotForm = (function () {
    'use strict';

    function create(container, fieldPrefix = 'external') {
        const wrapper = document.createElement('div');
        wrapper.className = 'external-pilot-panel';
        wrapper.style.display = 'none';

        wrapper.innerHTML = `
            <div class="card external-pilot-card">
                <h4>Externer Pilot / FI</h4>

                <p class="small">
                    Diese Angaben werden für Vereinsflieger und die
                    Rechnungsstellung benötigt.
                </p>

                <div class="row">
                    <label>
                        Nachname *
                        <input type="text"
                               id="${fieldPrefix}_last_name"
                               maxlength="100"
                               autocomplete="family-name">
                    </label>

                    <label>
                        Vorname *
                        <input type="text"
                               id="${fieldPrefix}_first_name"
                               maxlength="100"
                               autocomplete="given-name">
                    </label>
                </div>

                <div class="row">
                    <label>
                        Mailadresse *
                        <input type="email"
                               id="${fieldPrefix}_email"
                               maxlength="255"
                               autocomplete="email">
                    </label>
                </div>

                <div class="row">
                    <label>
                        Telefon *
                        <input type="tel"
                               id="${fieldPrefix}_phone"
                               maxlength="50"
                               autocomplete="tel">
                    </label>
                </div>

                <div class="hint">
                    Hinweis: Für Vereinsflieger werden Vorname und Nachname
                    getrennt gespeichert. Im Export wird automatisch
                    „Nachname, Vorname“ erzeugt.
                </div>
            </div>
        `;

        container.appendChild(wrapper);

        return {
            show() {
                wrapper.style.display = '';
            },
            hide() {
                wrapper.style.display = 'none';
            },
            getData() {
                return {
                    last_name: document.getElementById(`${fieldPrefix}_last_name`)?.value.trim() || '',
                    first_name: document.getElementById(`${fieldPrefix}_first_name`)?.value.trim() || '',
                    email: document.getElementById(`${fieldPrefix}_email`)?.value.trim() || '',
                    phone: document.getElementById(`${fieldPrefix}_phone`)?.value.trim() || ''
                };
            },
            validate() {
                const d = this.getData();

                return d.last_name !== '' &&
                       d.first_name !== '' &&
                       d.email !== '' &&
                       d.phone !== '';
            }
        };
    }

    return { create };
})();
