# Paket B v2: Personenimport aus Vereinsflieger

## Freigabestatus
Für kontrollierte Vorschau und anschliessenden manuellen Import vorgesehen. Der API-Benutzer `Schnittstelle` benötigt `Mitgliederdaten bearbeiten`. Der AppKey sollte unter `Nutzbar für` auf diesen Benutzer beschränkt sein.

## Installation
1. Backup erstellen.
2. `src/` und `public/` in die bestehenden Verzeichnisse kopieren.
3. Keine SQL-Migration erforderlich.
4. Optional den Link aus `patches/dashboard-link.txt` in die ADMIN-Navigation einsetzen. Die Seite funktioniert auch direkt.
5. Zugangsdaten ausserhalb des Webroots konfigurieren.

## Konfiguration
Umgebungsvariablen:
- `VF_API_USERNAME=Schnittstelle`
- `VF_API_PASSWORD=...`
- `VF_API_APPKEY=...`
- optional `VF_API_CID=...`
- optional `VF_API_AUTH_SECRET=...` mit dem aktuell gültigen 2FA-Code

Ohne automatisierte TOTP-Erzeugung darf `VF_API_AUTH_SECRET` nicht für einen unbeaufsichtigten Dauerbetrieb verwendet werden, da der Code wechselt. Dieses Paket generiert bewusst keinen TOTP-Code.

Alternativ kann die lokale `config.php` einen Abschnitt `vereinsflieger_api` enthalten. Secrets nicht committen.

## Gegenüber Paket B v1 korrigiert
- `auth/accesstoken`: GET
- `auth/signin`: POST
- `user/list`: POST
- `auth/signout/{token}`: DELETE
- Passwort wird vor MD5 nach ISO-8859-1 umgewandelt
- `dashboard.php` wird nicht ersetzt

## Sicherer Ersttest
1. Nur `Vorschau laden` verwenden.
2. Anzahl und Stichprobe mit VF vergleichen.
3. Besonders `würden deaktiviert` prüfen.
4. Erst danach Vollständigkeit bestätigen und importieren.

## Rollback
Alle mitgelieferten neuen Dateien entfernen. Es gibt keine Schemaänderung. Der bestehende CSV-Import bleibt unverändert.
