<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function send_mail(
    string $to,
    string $subject,
    string $html,
    string $text
): void {
    $config = app_config();
    $smtp = $config['smtp'] ?? null;

    if (!is_array($smtp)) {
        throw new RuntimeException('SMTP-Konfiguration fehlt.');
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();

        $mail->Host = (string)$smtp['host'];
        $mail->Port = (int)$smtp['port'];

        $mail->SMTPAuth = true;
        $mail->Username = (string)$smtp['username'];
        $mail->Password = (string)$smtp['password'];

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            (string)$smtp['from_address'],
            (string)$smtp['from_name']
        );

        $mail->addAddress($to);

        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;

        $mail->send();

    } catch (Exception $e) {
        throw new RuntimeException(
            'SMTP-Versand fehlgeschlagen: '
            . $mail->ErrorInfo,
            0,
            $e
        );
    }
}