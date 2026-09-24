# LSZJ-Datenbankmigrationen

Die Migrationen werden in numerischer Reihenfolge ausgeführt. Sie sind mit
`CREATE TABLE IF NOT EXISTS` idempotent und können daher auf einer Umgebung,
in der die Tabellen bereits manuell erstellt wurden, erneut ausgeführt werden.

## Voraussetzungen

Die Tabelle `users` muss bereits vorhanden sein. Beide Migrationen erstellen
einen Foreign Key auf `users(id)`.

## Enthaltene Migrationen

1. `001_create_user_passkeys.sql`
2. `002_create_user_recovery_tokens.sql`

## Lokal ausführen

PowerShell, aus dem Projektstamm:

```powershell
mariadb -h localhost -u lszj -p lszj_flightlist < database/migrations/001_create_user_passkeys.sql
mariadb -h localhost -u lszj -p lszj_flightlist < database/migrations/002_create_user_recovery_tokens.sql
```

Falls `mariadb` nicht verfügbar ist, kann stattdessen `mysql` verwendet werden.

## Auf Hostpoint ausführen

Bash, aus dem Projektstamm:

```bash
mariadb -h sgbielc.mysql.db.internal -u sgbielc_SLTest -p sgbielc_StartlisteTEST < database/migrations/001_create_user_passkeys.sql
mariadb -h sgbielc.mysql.db.internal -u sgbielc_SLTest -p sgbielc_StartlisteTEST < database/migrations/002_create_user_recovery_tokens.sql
```

Das Passwort wird interaktiv abgefragt und erscheint nicht in der Shell-History.

## Kontrolle

```sql
SHOW TABLES LIKE 'user_passkeys';
SHOW TABLES LIKE 'user_recovery_tokens';
SHOW COLUMNS FROM user_passkeys;
SHOW COLUMNS FROM user_recovery_tokens;
```

## Deployment-Checkliste

```text
1. git pull --ff-only origin main
2. composer install --no-dev --optimize-autoloader
3. ausstehende Migrationen in numerischer Reihenfolge ausführen
4. PHP-Syntax der geänderten Dateien prüfen
5. Passkey-Registrierung, Login und Recovery kurz testen
```

## Hinweis zur Versionsverwaltung

Nach dem Einfügen ins Repository:

```powershell
git add database/migrations/001_create_user_passkeys.sql
git add database/migrations/002_create_user_recovery_tokens.sql
git add database/migrations/README.md
git commit -m "Add passkey and recovery database migrations"
git push origin main
```
