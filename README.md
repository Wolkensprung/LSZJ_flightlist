# LSZJ externe Piloten

Enthalten:
- `public/master_data_autocomplete.js` ersetzt die bestehende Datei.
- `public/external_pilot_form.css` ergänzt die Darstellung.
- `src/api_external_contacts.php` ersetzt die bisherige API und unterstützt `last_name`/`first_name`.

CSS in allen drei Seiten nach `master_data_autocomplete.css` einbinden:

```html
<link rel="stylesheet" href="external_pilot_form.css">
```

Danach PHP neu starten und Browser mit Strg+F5 laden.
