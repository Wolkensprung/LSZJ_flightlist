# LSZJ Passkey-Registrierung

## Enthalten
- gehärtete `src/session.php`
- WebAuthn-Konfiguration und Registrierungslogik
- zwei API-Endpunkte mit dünnen Public-Wrappern
- `public/passkeys.php` für die Registrierung eines bereits angemeldeten Benutzers

## Installation
1. Vorhandene Dateien sichern.
2. Verzeichnisstruktur aus diesem Paket in das Projekt kopieren.
3. Sicherstellen, dass `vendor/autoload.php` vorhanden ist.
4. `config.php` um den bereits besprochenen `webauthn`-Block ergänzen.
5. Lokal über exakt die in `allowed_origins` konfigurierte Origin aufrufen.
6. Über den vorübergehenden DEV-Namenslogin anmelden.
7. `http://localhost[:PORT]/passkeys.php` öffnen und Passkey registrieren.

## Syntaxprüfung
PowerShell:

```powershell
Get-ChildItem src,public -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }
```

## Wichtige Hinweise
- WebAuthn funktioniert bei normalem HTTP nur auf `localhost`; Produktion benötigt HTTPS.
- Dieses Paket implementiert zunächst nur die Registrierung. Der Passkey-Login folgt als separater Schritt.
- Der aktuelle C-Büro-Timeout bleibt absichtlich unverändert.
- Die Datenbankspalte `public_key` enthält den serialisierten `PublicKeyCredentialSource` der Bibliothek.
- Falls die installierte Version 5.3.8 eine API-Abweichung gegenüber 5.2 meldet, ist die konkrete Fehlermeldung relevant; nicht die kryptografische Prüfung umgehen.
