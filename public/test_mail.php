<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/test_mail.php';

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim((string)($_POST['email'] ?? ''));

    try {

        send_test_mail($email);

        $message = '✅ Mail erfolgreich versendet.';

    } catch (Throwable $e) {

        $message =
            '❌ Fehler: '
            . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SMTP-Test</title>
<link rel="stylesheet" href="app.css">
</head>
<body>

<div class="card"
     style="max-width:700px;margin:40px auto;">

<h1>SMTP Test</h1>

<form method="post">

<label>
Empfänger-Adresse

<input
    type="email"
    name="email"
    required
    value="klaus.haehlen@..."
>
</label>

<p>
<button type="submit">
Testmail senden
</button>
</p>

</form>

<?php if ($message !== null): ?>
<pre><?=htmlspecialchars(
    $message,
    ENT_QUOTES,
    'UTF-8'
)?></pre>
<?php endif; ?>

</div>

</body>
</html>