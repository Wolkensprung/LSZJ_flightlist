<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function recovery_mail_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_recovery_mail(
    string $email,
    string $displayName,
    string $token
): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Ungültige Empfängeradresse.');
    }

    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        throw new InvalidArgumentException('Ungültiger Recovery-Token.');
    }

    $config = app_config();
    $baseUrl = rtrim((string)($config['recovery']['base_url'] ?? ''), '/');

    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Recovery base_url fehlt oder ist ungültig.');
    }

    $link = $baseUrl
        . '/recovery_complete.php?token='
        . rawurlencode(strtolower($token));

    $safeName = recovery_mail_escape($displayName);
    $safeLink = recovery_mail_escape($link);
    $subject = 'LSZJ Startliste - Passkey wiederherstellen';

    $html = <<<HTML
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;color:#1f2937;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8;">
<tr>
<td align="center" style="padding:28px 12px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#ffffff;border:1px solid #d9e0e8;border-radius:12px;">
<tr>
<td style="padding:32px;">
    <p style="margin:0 0 8px;color:#1769aa;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">LSZJ Startliste</p>
    <h1 style="margin:0 0 20px;color:#1f2937;font-size:26px;line-height:1.25;">Passkey wiederherstellen</h1>
    <p style="margin:0 0 16px;line-height:1.6;">Hallo {$safeName}</p>
    <p style="margin:0 0 16px;line-height:1.6;">Für Dein Benutzerkonto wurde ein Recovery-Link angefordert.</p>
    <p style="margin:0 0 26px;line-height:1.6;">Der Link ist 24 Stunden gültig und kann nur einmal verwendet werden.</p>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 26px;">
    <tr>
    <td bgcolor="#1769aa" style="border-radius:8px;">
        <a href="{$safeLink}" style="display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;">Passkey wiederherstellen</a>
    </td>
    </tr>
    </table>

    <p style="margin:0 0 8px;color:#667085;font-size:13px;line-height:1.5;">Falls der Button nicht funktioniert, kopiere diesen Link in den Browser:</p>
    <p style="margin:0 0 24px;font-size:13px;line-height:1.5;word-break:break-all;">
        <a href="{$safeLink}" style="color:#0f568e;">{$safeLink}</a>
    </p>

    <p style="margin:0;padding-top:20px;border-top:1px solid #d9e0e8;color:#667085;font-size:13px;line-height:1.5;">Falls Du diese Anfrage nicht ausgelöst hast, kannst Du diese E-Mail ignorieren.</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;

    $text = <<<TEXT
LSZJ Startliste

Hallo {$displayName}

Für Dein Benutzerkonto wurde ein Recovery-Link angefordert.

{$link}

Der Link ist 24 Stunden gültig und kann nur einmal verwendet werden.

Falls Du diese Anfrage nicht ausgelöst hast, kannst Du diese E-Mail ignorieren.
TEXT;

    send_mail($email, $subject, $html, $text);
}
