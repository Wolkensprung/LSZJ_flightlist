<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function send_recovery_mail(
    string $email,
    string $displayName,
    string $token
): void {

    $config = app_config();

    $baseUrl =
        $config['recovery']['base_url']
        ?? 'http://localhost:8000';

    $link =
        rtrim($baseUrl, '/')
        . '/recovery_complete.php?token='
        . urlencode($token);

    $subject =
        'LSZJ Startliste - Passkey Recovery';

    $html = <<<HTML
<p>Hallo {$displayName}</p>

<p>
Für Dein Benutzerkonto wurde ein Recovery-Link angefordert.
</p>

<p>
{$link}
Recovery-Link öffnen
</a>
</p>

<p>
Der Link ist 24 Stunden gültig.
</p>

<p>
Falls Du diese Anfrage nicht ausgelöst hast,
kannst Du diese E-Mail ignorieren.
</p>

<p>
LSZJ Startliste
</p>
HTML;

    $text = <<<TEXT
Hallo {$displayName}

Für Dein Benutzerkonto wurde ein Recovery-Link angefordert.

{$link}

Der Link ist 24 Stunden gültig.

Falls Du diese Anfrage nicht ausgelöst hast,
kannst Du diese E-Mail ignorieren.

LSZJ Startliste
TEXT;

    send_mail(
        $email,
        $subject,
        $html,
        $text
    );
}