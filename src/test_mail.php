<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function send_test_mail(string $targetEmail): void
{
    $subject = 'LSZJ Startliste - SMTP Test';

    $html = <<<HTML
<p>SMTP-Test erfolgreich.</p>

<p>
Diese E-Mail wurde über Hostpoint SMTP versendet.
</p>

<p>
LSZJ Startliste
</p>
HTML;

    $text = <<<TEXT
SMTP-Test erfolgreich.

Diese E-Mail wurde über Hostpoint SMTP versendet.

LSZJ Startliste
TEXT;

    send_mail(
        $targetEmail,
        $subject,
        $html,
        $text
    );
}