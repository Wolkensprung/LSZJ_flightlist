/* D1 UX-Hardening fuer bereits exportierte Fluege.
 * Backend-Schutz bleibt der MariaDB-Trigger. Dieses Skript macht die Sperre
 * dem Benutzer bereits vor dem Speichern sichtbar.
 */
(() => {
    'use strict';

    const text = node => (node?.textContent || '').replace(/\s+/g, ' ').trim();
    const isExportBadge = node => /(^|\s)Exportiert(\s|$)/i.test(text(node));
    const candidates = [...document.querySelectorAll('span, strong, div, label')]
        .filter(isExportBadge);

    function operationCard(badge) {
        return badge.closest(
            '[data-operation-id], .operation-card, .flight-card, article, section, .card'
        );
    }

    function findEntryId(card) {
        const direct = card.dataset.accountingEntryId || card.dataset.entryId;
        if (/^\d+$/.test(direct || '')) return direct;

        const hidden = card.querySelector(
            'input[name="accounting_entry_id"], input[name$="[id]"], '
            + 'input[data-accounting-entry-id]'
        );
        const value = hidden?.dataset.accountingEntryId || hidden?.value || '';
        return /^\d+$/.test(value) ? value : '';
    }

    function isNavigation(control) {
        return control.matches('a, [type="hidden"], [data-d1-keep-enabled]');
    }

    function isAction(control) {
        return /löschen|loeschen|korrektur|speichern|freigeben/i.test(text(control));
    }

    for (const badge of candidates) {
        const card = operationCard(badge);
        if (!card || card.dataset.d1Locked === '1') continue;
        card.dataset.d1Locked = '1';
        card.classList.add('d1-exported-readonly');

        for (const control of card.querySelectorAll('input, select, textarea, button')) {
            if (isNavigation(control)) continue;
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        }

        for (const action of card.querySelectorAll('button, a')) {
            if (isAction(action)) action.classList.add('d1-hidden-action');
        }

        const notice = document.createElement('div');
        notice.className = 'd1-export-lock-notice';
        notice.setAttribute('role', 'note');
        notice.innerHTML = '<strong>Exportiert, schreibgeschützt.</strong> '
            + 'Änderungen sind in dieser Maske nicht mehr möglich.';

        const entryId = findEntryId(card);
        if (entryId) {
            const link = document.createElement('a');
            link.className = 'button secondary';
            link.href = 'exported_flight_correction.php?accounting_entry_id='
                + encodeURIComponent(entryId);
            link.textContent = 'D1-Admin-Korrektur';
            notice.append(' ', link);
        } else {
            notice.append(' Die D1-Änderungsliste führt zur Admin-Korrektur.');
        }

        card.prepend(notice);
    }
})();
