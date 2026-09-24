<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/session.php';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>LSZJ Passkey-Recovery</title>
    <link rel="stylesheet" href="app.css">
    <style>
        :root {
            --recovery-blue: #1769aa;
            --recovery-blue-dark: #0f568e;
            --recovery-green: #17823b;
            --recovery-red: #b00020;
            --recovery-text: #1f2937;
            --recovery-muted: #667085;
            --recovery-border: #d9e0e8;
            --recovery-panel: #ffffff;
            --recovery-bg: #f4f6f8;
        }

        body {
            margin: 0;
            background: var(--recovery-bg);
            color: var(--recovery-text);
        }

        .recovery-shell {
            width: min(100% - 32px, 680px);
            margin: 48px auto;
        }

        .recovery-card {
            background: var(--recovery-panel);
            border: 1px solid var(--recovery-border);
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            padding: clamp(24px, 5vw, 38px);
        }

        .recovery-kicker {
            margin: 0 0 8px;
            color: var(--recovery-blue);
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 12px;
            line-height: 1.2;
        }

        .intro {
            margin: 0 0 26px;
            color: var(--recovery-muted);
            line-height: 1.55;
        }

        .form-row {
            display: grid;
            gap: 8px;
        }

        label {
            font-weight: 700;
        }

        input[type="email"] {
            width: 100%;
            box-sizing: border-box;
            min-height: 46px;
            padding: 10px 12px;
            border: 1px solid #aeb8c4;
            border-radius: 8px;
            font: inherit;
        }

        input[type="email"]:focus {
            border-color: var(--recovery-blue);
            outline: 3px solid rgba(23, 105, 170, 0.16);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .primary-button {
            min-height: 44px;
            padding: 10px 18px;
            border: 0;
            border-radius: 8px;
            background: var(--recovery-blue);
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .primary-button:hover {
            background: var(--recovery-blue-dark);
        }

        .primary-button:disabled {
            cursor: wait;
            opacity: 0.65;
        }

        .secondary-link {
            color: var(--recovery-blue-dark);
            font-weight: 600;
        }

        .status {
            margin-top: 24px;
            padding: 16px 18px;
            border-radius: 9px;
            border-left: 5px solid;
            line-height: 1.5;
        }

        .status[hidden] {
            display: none;
        }

        .status-success {
            border-left-color: var(--recovery-green);
            background: #eefaf1;
        }

        .status-error {
            border-left-color: var(--recovery-red);
            background: #fff0f0;
            color: #970018;
        }

        .status-title {
            display: block;
            margin-bottom: 4px;
            font-weight: 800;
        }

        .privacy-note {
            margin: 22px 0 0;
            padding-top: 18px;
            border-top: 1px solid var(--recovery-border);
            color: var(--recovery-muted);
            font-size: 0.92rem;
            line-height: 1.45;
        }

        @media (max-width: 520px) {
            .recovery-shell {
                width: min(100% - 20px, 680px);
                margin: 16px auto;
            }

            .recovery-card {
                padding: 22px 18px;
            }

            .primary-button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<main class="recovery-shell">
    <section class="recovery-card" aria-labelledby="recovery-title">
        <p class="recovery-kicker">LSZJ Startliste</p>
        <h1 id="recovery-title">Passkey wiederherstellen</h1>
        <p class="intro">
            Gib die Mailadresse Deines aktiven Mitgliederkontos ein. Wenn die Adresse bekannt ist,
            erhältst Du einen einmaligen Link zur Registrierung eines neuen Passkeys.
        </p>

        <form id="recovery-form" novalidate>
            <div class="form-row">
                <label for="email">Mailadresse</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    maxlength="255"
                    placeholder="name@beispiel.ch"
                    required
                >
            </div>

            <div class="form-actions">
                <button id="submit-button" class="primary-button" type="submit">
                    Recovery-Link anfordern
                </button>
                <a class="secondary-link" href="passkey_login.php">Zurück zur Anmeldung</a>
            </div>
        </form>

        <div id="status" class="status" role="status" aria-live="polite" hidden>
            <strong id="status-title" class="status-title"></strong>
            <span id="status-message"></span>
        </div>

        <p class="privacy-note">
            Aus Sicherheitsgründen zeigt die Seite nicht an, ob eine bestimmte Mailadresse im System vorhanden ist.
            Der Recovery-Link ist zeitlich begrenzt und kann nur einmal verwendet werden.
        </p>
    </section>
</main>

<script>
(() => {
    'use strict';

    const form = document.getElementById('recovery-form');
    const email = document.getElementById('email');
    const button = document.getElementById('submit-button');
    const status = document.getElementById('status');
    const statusTitle = document.getElementById('status-title');
    const statusMessage = document.getElementById('status-message');

    function showStatus(ok, title, message) {
        status.hidden = false;
        status.className = 'status ' + (ok ? 'status-success' : 'status-error');
        statusTitle.textContent = title;
        statusMessage.textContent = message;
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        status.hidden = true;

        if (!email.checkValidity()) {
            email.reportValidity();
            return;
        }

        button.disabled = true;
        button.textContent = 'Wird gesendet ...';

        try {
            const response = await fetch('api_recovery_request.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({email: email.value.trim()}),
            });

            const result = await response.json();

            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Die Anfrage konnte nicht verarbeitet werden.');
            }

            showStatus(
                true,
                'Anfrage entgegengenommen',
                'Falls die Mailadresse bekannt ist, wurde ein Recovery-Link versendet. Bitte prüfe auch den Spam-Ordner.'
            );
            form.reset();
        } catch (error) {
            showStatus(
                false,
                'Recovery-Link konnte nicht angefordert werden',
                error.message || 'Bitte versuche es später erneut.'
            );
        } finally {
            button.disabled = false;
            button.textContent = 'Recovery-Link anfordern';
        }
    });
})();
</script>
</body>
</html>
