# UI-Sprint: Anmeldung und C-Büro

## Dateien
- public/passkey_login.php ersetzt
- public/qr_login.php ersetzt
- src/api_onboarding_qr_code.php neu
- public/api_onboarding_qr_code.php neu

## Vorläufiger Testzugang
`login.php` bleibt unverändert erhalten. Der Link ist sichtbar, solange in config.php
nichts anderes gesetzt ist. Später kann er ausgeblendet werden:

```php
'auth' => [
    'legacy_login_enabled' => false,
],
```

In der Testumgebung vorerst `true` verwenden.
