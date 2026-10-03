# LSZJ i18n Komplettpaket 009 v4

V4 ersetzt die fehlerhaften reinen PowerShell-Patcher aus V2/V3.
Das PowerShell-Skript ist nur ein stabiler Starter. Die Änderungen werden vom mitgelieferten PHP-Patcher vorgenommen.

```powershell
Set-ExecutionPolicy -Scope Process Bypass
& .\tools\apply_i18n_complete_fix_v4.ps1
```

Danach:

```powershell
node --check .\public\i18n.js
node --check .\public\master_data_autocomplete.js
php -l .\public\qr_login.php
php -l .\public\flight_approvals.php
```
