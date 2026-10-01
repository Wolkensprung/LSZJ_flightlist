/* LSZJ i18n hotfix 02
   Zweck: stabile DE/FR Umschaltung ohne fehleranfällige RegExp-Escapes.
   Lädt Übersetzungen aus api_i18n.php und nutzt Fallback, falls die DB/API nicht verfügbar ist.
*/
(function(){
  const LANG_KEY = 'lszj_lang';
  const fallbackPairs = {
    'pending': {de:'Offen', fr:'Ouvert'},
    'approved': {de:'Freigegeben', fr:'Validé'},
    'correction_required': {de:'Korrektur nötig', fr:'Correction requise'},
    'exported': {de:'Exportiert', fr:'Exporté'},
    'all': {de:'Alle', fr:'Tous'},
    'glider_tow': {de:'Segelflugschlepp', fr:'Remorquage planeur'},
    'self_launch': {de:'Eigenstart', fr:'Décollage autonome'},
    'towplane_only': {de:'Motorflug', fr:'Vol moteur'},
    'Segelflugschlepp': {de:'Segelflugschlepp', fr:'Remorquage planeur'},
    'Eigenstart': {de:'Eigenstart', fr:'Décollage autonome'},
    'Motorflug': {de:'Motorflug', fr:'Vol moteur'},
    'Dashboard': {de:'Dashboard', fr:'Tableau de bord'},
    'Flugfreigaben': {de:'Flugfreigaben', fr:'Validations des vols'},
    '+ Flug manuell erfassen': {de:'+ Flug manuell erfassen', fr:'+ Saisir un vol manuellement'},
    'Flug manuell erfassen': {de:'Flug manuell erfassen', fr:'Saisir un vol manuellement'},
    'Flug korrigieren': {de:'Flug korrigieren', fr:'Corriger le vol'},
    'Zurück zu Flugfreigaben': {de:'Zurück zu Flugfreigaben', fr:'Retour aux validations des vols'},
    'Von': {de:'Von', fr:'Du'},
    'Bis': {de:'Bis', fr:'Au'},
    'Status': {de:'Status', fr:'Statut'},
    'Benutzer': {de:'Benutzer', fr:'Utilisateur'},
    'Laden': {de:'Laden', fr:'Charger'},
    'Heute': {de:'Heute', fr:'Aujourd’hui'},
    'Gestern': {de:'Gestern', fr:'Hier'},
    'Letzte 7 Tage': {de:'Letzte 7 Tage', fr:'7 derniers jours'},
    'kTrax-Import': {de:'kTrax-Import', fr:'Import kTrax'},
    'kTrax-Import läuft...': {de:'kTrax-Import läuft...', fr:'Import kTrax en cours...'},
    'Zeitraum:': {de:'Zeitraum:', fr:'Période :'},
    'Operation': {de:'Operation', fr:'Opération'},
    'Segelflug': {de:'Segelflug', fr:'Planeur'},
    'Motorflug / F-Schlepp': {de:'Motorflug / F-Schlepp', fr:'Vol moteur / remorquage'},
    'Kein Segelflug zugeordnet.': {de:'Kein Segelflug zugeordnet.', fr:'Aucun vol planeur associé.'},
    'Kein Motorflug zugeordnet.': {de:'Kein Motorflug zugeordnet.', fr:'Aucun vol moteur associé.'},
    'Kein Motorflug/Schlepp vorhanden.': {de:'Kein Motorflug/Schlepp vorhanden.', fr:'Aucun vol moteur/remorquage disponible.'},
    'Kein Segelflug zu diesem Motorflug vorhanden.': {de:'Kein Segelflug zu diesem Motorflug vorhanden.', fr:'Aucun vol planeur pour ce vol moteur.'},
    'Schlepp zu Segelflug erfassen': {de:'Schlepp zu Segelflug erfassen', fr:'Saisir un remorquage pour le planeur'},
    'Segelflug zu Schleppflug erfassen': {de:'Segelflug zu Schleppflug erfassen', fr:'Saisir un planeur pour le remorquage'},
    'Start': {de:'Start', fr:'Départ'},
    'Landung': {de:'Landung', fr:'Atterrissage'},
    'Flugzeit': {de:'Flugzeit', fr:'Durée du vol'},
    'Schleppzeit': {de:'Schleppzeit', fr:'Durée du remorquage'},
    'Schlepp': {de:'Schlepp', fr:'Remorquage'},
    'Segelflugpilot': {de:'Segelflugpilot', fr:'Pilote planeur'},
    'Begleiter / FI': {de:'Begleiter / FI', fr:'Accompagnant / FI'},
    'Motorpilot': {de:'Motorpilot', fr:'Pilote moteur'},
    'Flugart': {de:'Flugart', fr:'Type de vol'},
    'Abrechnung': {de:'Abrechnung', fr:'Facturation'},
    'Kommentar': {de:'Kommentar', fr:'Commentaire'},
    'Startzeit': {de:'Startzeit', fr:'Heure de départ'},
    'Landung Segelflugzeug': {de:'Landung Segelflugzeug', fr:'Atterrissage du planeur'},
    'Landung Motorflugzeug': {de:'Landung Motorflugzeug', fr:'Atterrissage de l’avion moteur'},
    'Segelflugzeug': {de:'Segelflugzeug', fr:'Planeur'},
    'Motorflugzeug': {de:'Motorflugzeug', fr:'Avion moteur'},
    'Segelflug löschen': {de:'Segelflug löschen', fr:'Supprimer le vol planeur'},
    'Korrektur': {de:'Korrektur', fr:'Correction'},
    'Motorflug löschen': {de:'Motorflug löschen', fr:'Supprimer le vol moteur'},
    'Speichern & Freigeben': {de:'Speichern & Freigeben', fr:'Enregistrer et valider'},
    'Speichern': {de:'Speichern', fr:'Enregistrer'},
    'Abbrechen': {de:'Abbrechen', fr:'Annuler'},
    'Freigegeben, nicht exportiert': {de:'Freigegeben, nicht exportiert', fr:'Validé, non exporté'},
    'Bereits exportiert': {de:'Bereits exportiert', fr:'Déjà exporté'},
    'Manuelle Flugerfassung': {de:'Manuelle Flugerfassung', fr:'Saisie manuelle des vols'},
    'Exportvorschau': {de:'Exportvorschau', fr:'Aperçu de l’export'},
    'Vereinsflieger-CSV Export': {de:'Vereinsflieger-CSV Export', fr:'Export CSV Vereinsflieger'},
    "Abmelden": {de:"Abmelden", fr:"Se déconnecter"},
    "Anmelden": {de:"Anmelden", fr:"Se connecter"},
    "Mit Passkey anmelden": {de:"Mit Passkey anmelden", fr:"Se connecter avec une clé d’accès"},
    "Melde Dich mit dem persönlichen Passkey auf diesem Smartphone oder Computer an.": {de:"Melde Dich mit dem persönlichen Passkey auf diesem Smartphone oder Computer an.", fr:"Connecte-toi avec ta clé d’accès personnelle sur ce smartphone ou cet ordinateur."},
    "Noch keinen Passkey oder neues Smartphone?": {de:"Noch keinen Passkey oder neues Smartphone?", fr:"Pas encore de clé d’accès ou nouveau smartphone ?"},
    "Fordere einen sicheren Link per E-Mail an und richte den Passkey auf diesem persönlichen Gerät ein.": {de:"Fordere einen sicheren Link per E-Mail an und richte den Passkey auf diesem persönlichen Gerät ein.", fr:"Demande un lien sécurisé par e-mail et configure la clé d’accès sur cet appareil personnel."},
    "Passkey erstmals einrichten": {de:"Passkey erstmals einrichten", fr:"Configurer une première clé d’accès"},
    "Vorläufig als anderer Benutzer anmelden": {de:"Vorläufig als anderer Benutzer anmelden", fr:"Se connecter provisoirement comme autre utilisateur"},
    "C-Büro anmelden": {de:"C-Büro anmelden", fr:"Connecter le PC du bureau C"},
    "QR-Code erzeugen": {de:"QR-Code erzeugen", fr:"Générer le code QR"},
    "QR-Code mit dem persönlichen Smartphone scannen und die Anmeldung dort mit dem eigenen Passkey bestätigen.": {de:"QR-Code mit dem persönlichen Smartphone scannen und die Anmeldung dort mit dem eigenen Passkey bestätigen.", fr:"Scanne le code QR avec ton smartphone personnel et confirme la connexion avec ta propre clé d’accès."},
    "Noch keinen Passkey?": {de:"Noch keinen Passkey?", fr:"Pas encore de clé d’accès ?"},
    "Scanne diesen QR-Code mit dem persönlichen Smartphone. Richte den Passkey dort per E-Mail-Link ein. Danach hier einen neuen Login-QR-Code erzeugen.": {de:"Scanne diesen QR-Code mit dem persönlichen Smartphone. Richte den Passkey dort per E-Mail-Link ein. Danach hier einen neuen Login-QR-Code erzeugen.", fr:"Scanne ce code QR avec ton smartphone personnel. Configure la clé d’accès à l’aide du lien reçu par e-mail. Génère ensuite ici un nouveau code QR de connexion."},
    "Warte auf die Bestätigung am Smartphone ...": {de:"Warte auf die Bestätigung am Smartphone ...", fr:"En attente de la confirmation sur le smartphone…"},
    "Freigabe erhalten. C-Büro wird angemeldet ...": {de:"Freigabe erhalten. C-Büro wird angemeldet ...", fr:"Confirmation reçue. Connexion du PC du bureau C…"},
    "Der QR-Code ist abgelaufen. Falls Du gerade einen Passkey eingerichtet hast, erzeuge jetzt einen neuen QR-Code.": {de:"Der QR-Code ist abgelaufen. Falls Du gerade einen Passkey eingerichtet hast, erzeuge jetzt einen neuen QR-Code.", fr:"Le code QR a expiré. Si tu viens de configurer une clé d’accès, génère maintenant un nouveau code QR."},
    "QR-Anmeldung beendet:": {de:"QR-Anmeldung beendet:", fr:"Connexion par QR terminée :"},
    "QR-Session konnte nicht erzeugt werden.": {de:"QR-Session konnte nicht erzeugt werden.", fr:"La session QR n’a pas pu être créée."},
    "Status konnte nicht gelesen werden.": {de:"Status konnte nicht gelesen werden.", fr:"Le statut n’a pas pu être lu."},
    "QR-Anmeldung konnte nicht übernommen werden.": {de:"QR-Anmeldung konnte nicht übernommen werden.", fr:"La connexion QR n’a pas pu être reprise."},
    "C-Büro-Login": {de:"C-Büro-Login", fr:"Connexion au bureau C"},
    "Anmeldung bestätigen": {de:"Anmeldung bestätigen", fr:"Confirmer la connexion"},
    "Bestätige nur, wenn Du den QR-Code selbst am C-Büro-PC geöffnet hast.": {de:"Bestätige nur, wenn Du den QR-Code selbst am C-Büro-PC geöffnet hast.", fr:"Confirme uniquement si tu as toi-même affiché le code QR sur le PC du bureau C."},
    "C-Büro-Anmeldung wurde bestätigt.": {de:"C-Büro-Anmeldung wurde bestätigt.", fr:"La connexion au bureau C a été confirmée."},
    "QR-Login-Session ist unbekannt.": {de:"QR-Login-Session ist unbekannt.", fr:"La session de connexion QR est inconnue."},
    "QR-Login-Session ist nicht mehr offen.": {de:"QR-Login-Session ist nicht mehr offen.", fr:"La session de connexion QR n’est plus ouverte."},
    "QR-Login-Session ist abgelaufen.": {de:"QR-Login-Session ist abgelaufen.", fr:"La session de connexion QR a expiré."},
    "QR-Login-Session ist nicht zur Übernahme freigegeben.": {de:"QR-Login-Session ist nicht zur Übernahme freigegeben.", fr:"La session de connexion QR n’est pas autorisée à être reprise."},
    "QR-Login-Session wurde bereits verwendet.": {de:"QR-Login-Session wurde bereits verwendet.", fr:"La session de connexion QR a déjà été utilisée."},
    "Passkeys": {de:"Passkeys", fr:"Clés d’accès"},
    "Passkey registrieren": {de:"Passkey registrieren", fr:"Enregistrer une clé d’accès"},
    "Neuen Passkey registrieren": {de:"Neuen Passkey registrieren", fr:"Enregistrer une nouvelle clé d’accès"},
    "Aktive Passkeys": {de:"Aktive Passkeys", fr:"Clés d’accès actives"},
    "Widerrufen": {de:"Widerrufen", fr:"Révoquer"},
    "Gerätename": {de:"Gerätename", fr:"Nom de l’appareil"},
    "Persönliches Gerät": {de:"Persönliches Gerät", fr:"Appareil personnel"},
    "Erstellt:": {de:"Erstellt:", fr:"Créée le :"},
    "Zuletzt verwendet:": {de:"Zuletzt verwendet:", fr:"Dernière utilisation :"},
    "noch nie": {de:"noch nie", fr:"jamais"},
    "Noch keine Passkeys registriert.": {de:"Noch keine Passkeys registriert.", fr:"Aucune clé d’accès enregistrée."},
    "Zurück zum Dashboard": {de:"Zurück zum Dashboard", fr:"Retour au tableau de bord"},
    "Recovery-Modus aktiv": {de:"Recovery-Modus aktiv", fr:"Mode de récupération actif"},
    "Registriere den neuen Passkey auf dem Ersatzgerät. Die bisherigen Passkeys bleiben aus Sicherheitsgründen aktiv, bis der neue Passkey erfolgreich validiert und gespeichert wurde. Danach werden die bisherigen Passkeys automatisch widerrufen.": {de:"Registriere den neuen Passkey auf dem Ersatzgerät. Die bisherigen Passkeys bleiben aus Sicherheitsgründen aktiv, bis der neue Passkey erfolgreich validiert und gespeichert wurde. Danach werden die bisherigen Passkeys automatisch widerrufen.", fr:"Enregistre la nouvelle clé d’accès sur l’appareil de remplacement. Pour des raisons de sécurité, les clés existantes restent actives jusqu’à ce que la nouvelle clé soit validée et enregistrée. Elles seront ensuite révoquées automatiquement."},
    "Registriere den Passkey auf dem zusätzlichen Gerät. Bestehende Passkeys bleiben aktiv.": {de:"Registriere den Passkey auf dem zusätzlichen Gerät. Bestehende Passkeys bleiben aktiv.", fr:"Enregistre la clé d’accès sur l’appareil supplémentaire. Les clés existantes restent actives."},
    "Falls auf diesem Geräteprofil bereits ein Passkey für die LSZJ Startliste existiert, verwende ein anderes Gerät oder lösche den lokalen Geräte-Passkey zuerst in der Passkeyverwaltung des Betriebssystems.": {de:"Falls auf diesem Geräteprofil bereits ein Passkey für die LSZJ Startliste existiert, verwende ein anderes Gerät oder lösche den lokalen Geräte-Passkey zuerst in der Passkeyverwaltung des Betriebssystems.", fr:"Si une clé d’accès existe déjà pour la liste de départ LSZJ dans ce profil d’appareil, utilise un autre appareil ou supprime d’abord la clé locale dans la gestion des clés d’accès du système."},
    "Passkey wiederherstellen": {de:"Passkey wiederherstellen", fr:"Récupérer la clé d’accès"},
    "Passkey-Recovery": {de:"Passkey-Recovery", fr:"Récupération de la clé d’accès"},
    "Recovery-Link gültig": {de:"Recovery-Link gültig", fr:"Lien de récupération valide"},
    "Recovery bestätigt": {de:"Recovery bestätigt", fr:"Récupération confirmée"},
    "Recovery nicht möglich": {de:"Recovery nicht möglich", fr:"Récupération impossible"},
    "Recovery bestätigen": {de:"Recovery bestätigen", fr:"Confirmer la récupération"},
    "Neuen Recovery-Link anfordern": {de:"Neuen Recovery-Link anfordern", fr:"Demander un nouveau lien de récupération"},
    "Recovery-Link anfordern": {de:"Recovery-Link anfordern", fr:"Demander un lien de récupération"},
    "Recovery-Link versendet": {de:"Recovery-Link versendet", fr:"Lien de récupération envoyé"},
    "Mailadresse": {de:"Mailadresse", fr:"Adresse e-mail"},
    "Gerät verloren oder nicht mehr vertrauenswürdig": {de:"Gerät verloren oder nicht mehr vertrauenswürdig", fr:"Appareil perdu ou plus digne de confiance"},
    "Nach erfolgreicher Registrierung des neuen Passkeys werden alle bisherigen aktiven Passkeys widerrufen.": {de:"Nach erfolgreicher Registrierung des neuen Passkeys werden alle bisherigen aktiven Passkeys widerrufen.", fr:"Après l’enregistrement réussi de la nouvelle clé d’accès, toutes les anciennes clés actives seront révoquées."},
    "Nur ein zusätzliches Gerät registrieren": {de:"Nur ein zusätzliches Gerät registrieren", fr:"Enregistrer uniquement un appareil supplémentaire"},
    "Bestehende Passkeys bleiben aktiv.": {de:"Bestehende Passkeys bleiben aktiv.", fr:"Les clés d’accès existantes restent actives."},
    "Zur Anmeldung": {de:"Zur Anmeldung", fr:"Retour à la connexion"},
    "Abbrechen": {de:"Abbrechen", fr:"Annuler"},
    "Passkey wurde registriert.": {de:"Passkey wurde registriert.", fr:"La clé d’accès a été enregistrée."},
    "Passkey wurde widerrufen.": {de:"Passkey wurde widerrufen.", fr:"La clé d’accès a été révoquée."},
    "Dieser Passkey ist bereits registriert.": {de:"Dieser Passkey ist bereits registriert.", fr:"Cette clé d’accès est déjà enregistrée."},
    "Passkey ist unbekannt oder wurde widerrufen.": {de:"Passkey ist unbekannt oder wurde widerrufen.", fr:"La clé d’accès est inconnue ou a été révoquée."},
    "Dieser Browser unterstützt Passkeys nicht.": {de:"Dieser Browser unterstützt Passkeys nicht.", fr:"Ce navigateur ne prend pas en charge les clés d’accès."},
    "Die Anmeldung wurde abgebrochen oder es ist kein passender Passkey verfügbar.": {de:"Die Anmeldung wurde abgebrochen oder es ist kein passender Passkey verfügbar.", fr:"La connexion a été annulée ou aucune clé d’accès appropriée n’est disponible."},
    "Die Passkey-Registrierung wurde abgebrochen oder ist abgelaufen. Bitte starte die Registrierung erneut.": {de:"Die Passkey-Registrierung wurde abgebrochen oder ist abgelaufen. Bitte starte die Registrierung erneut.", fr:"L’enregistrement de la clé d’accès a été annulé ou a expiré. Recommence l’enregistrement."},
    "Auf diesem Gerät existiert bereits ein Passkey für die LSZJ Startliste. Verwende für einen zweiten Passkey ein anderes Gerät oder ein anderes Geräteprofil.": {de:"Auf diesem Gerät existiert bereits ein Passkey für die LSZJ Startliste. Verwende für einen zweiten Passkey ein anderes Gerät oder ein anderes Geräteprofil.", fr:"Une clé d’accès pour la liste de départ LSZJ existe déjà sur cet appareil. Utilise un autre appareil ou un autre profil pour une deuxième clé."},
    "Diesen Passkey wirklich widerrufen? Danach kann er nicht mehr zur Anmeldung verwendet werden.": {de:"Diesen Passkey wirklich widerrufen? Danach kann er nicht mehr zur Anmeldung verwendet werden.", fr:"Révoquer réellement cette clé d’accès ? Elle ne pourra ensuite plus être utilisée pour se connecter."},
    "LSZJ Startliste": {de:"LSZJ Startliste", fr:"Liste de départ LSZJ"},
    "Test": {de:"Test", fr:"Test"},
    "Administration": {de:"Administration", fr:"Administration"},
    "Benutzerverwaltung": {de:"Benutzerverwaltung", fr:"Gestion des utilisateurs"},
    "Speichern": {de:"Speichern", fr:"Enregistrer"},
    "Löschen": {de:"Löschen", fr:"Supprimer"},
    "Schliessen": {de:"Schliessen", fr:"Fermer"},
    "Zurück": {de:"Zurück", fr:"Retour"},
    "Ja": {de:"Ja", fr:"Oui"},
    "Nein": {de:"Nein", fr:"Non"},
    "Fehler": {de:"Fehler", fr:"Erreur"},
    "Erfolgreich": {de:"Erfolgreich", fr:"Réussi"},
    "Methode nicht erlaubt.": {de:"Methode nicht erlaubt.", fr:"Méthode non autorisée."},
    "Ungültige JSON-Daten.": {de:"Ungültige JSON-Daten.", fr:"Données JSON non valides."},
    "Anmeldung erforderlich.": {de:"Anmeldung erforderlich.", fr:"Connexion requise."},
    "Keine Berechtigung.": {de:"Keine Berechtigung.", fr:"Autorisation insuffisante."},
    "Sitzung abgelaufen.": {de:"Sitzung abgelaufen.", fr:"Session expirée."},
    "Ungültiges CSRF-Token.": {de:"Ungültiges CSRF-Token.", fr:"Jeton CSRF non valide."},
    "Flugdienstleiter": {de:"Flugdienstleiter", fr:"Chef de service de vol"},
    "Segelflugpilot": {de:"Segelflugpilot", fr:"Pilote de planeur"},
    "Motorpilot": {de:"Motorpilot", fr:"Pilote avion"},
    "Schlepppilot": {de:"Schlepppilot", fr:"Pilote remorqueur"},
    "Betriebstag": {de:"Betriebstag", fr:"Journée d’exploitation"},
    "Tagesabschluss": {de:"Tagesabschluss", fr:"Clôture journalière"},
    "Tagesabschlussprüfung": {de:"Tagesabschlussprüfung", fr:"Contrôle de clôture journalière"},
    "Kommentar": {de:"Kommentar", fr:"Commentaire"},
    "Flugart": {de:"Flugart", fr:"Type de vol"},
    "Abrechnung": {de:"Abrechnung", fr:"Facturation"},
    "Startzeit": {de:"Startzeit", fr:"Heure de départ"},
    "Landezeit": {de:"Landezeit", fr:"Heure d’atterrissage"},
    "Start": {de:"Start", fr:"Départ"},
    "Landung": {de:"Landung", fr:"Atterrissage"},
    "Segelflug": {de:"Segelflug", fr:"Planeur"},
    "Motorflug": {de:"Motorflug", fr:"Vol moteur"},
    "Schlepp": {de:"Schlepp", fr:"Remorquage"},
    "Speichern & Freigeben": {de:"Speichern & Freigeben", fr:"Enregistrer et valider"},
    "Flug korrigieren": {de:"Flug korrigieren", fr:"Corriger le vol"},
    "Flug manuell erfassen": {de:"Flug manuell erfassen", fr:"Saisir un vol manuellement"},
    "Flugfreigaben": {de:"Flugfreigaben", fr:"Validations des vols"},
    "Alle": {de:"Alle", fr:"Tous"},
    "Offen": {de:"Offen", fr:"Ouvert"},
    "Freigegeben": {de:"Freigegeben", fr:"Validé"},
    "Korrektur nötig": {de:"Korrektur nötig", fr:"Correction requise"},
    "Exportiert": {de:"Exportiert", fr:"Exporté"},
    "Logout": {de:"Logout", fr:"Se déconnecter"},
    "Angemeldet:": {de:"Angemeldet:", fr:"Connecté :"},
    "Pilot": {de:"Pilot", fr:"Pilote"},
    "Administrator": {de:"Administrator", fr:"Administrateur"},
    "LSZJ Dashboard": {de:"LSZJ Dashboard", fr:"Tableau de bord LSZJ"},
    "Aktiver Flugdienstleiter:": {de:"Aktiver Flugdienstleiter:", fr:"Chef de service de vol actif :"},
    "Seit": {de:"Seit", fr:"Depuis"},
    "Uhr": {de:"Uhr", fr:"h"},
    "Kein Flugdienstleiter aktiv": {de:"Kein Flugdienstleiter aktiv", fr:"Aucun chef de service de vol actif"},
    "Der Flugdienstleiterdienst ist derzeit nicht besetzt.": {de:"Der Flugdienstleiterdienst ist derzeit nicht besetzt.", fr:"Le service de chef de vol n’est actuellement pas assuré."},
    "Dienst übernehmen": {de:"Dienst übernehmen", fr:"Prendre le service"},
    "Flugbetrieb": {de:"Flugbetrieb", fr:"Opérations de vol"},
    "Flüge prüfen, freigeben und bei Bedarf manuell erfassen.": {de:"Flüge prüfen, freigeben und bei Bedarf manuell erfassen.", fr:"Contrôler et valider les vols, et les saisir manuellement si nécessaire."},
    "Tagesabschluss Flugdienstleiter": {de:"Tagesabschluss Flugdienstleiter", fr:"Clôture journalière du chef de service de vol"},
    "Der Betriebstag wird erst nach vollständiger Prüfung und Freigabe aller Flüge abgeschlossen. Der Abschluss gibt die Tagesdaten für den Export nach Vereinsflieger frei.": {de:"Der Betriebstag wird erst nach vollständiger Prüfung und Freigabe aller Flüge abgeschlossen. Der Abschluss gibt die Tagesdaten für den Export nach Vereinsflieger frei.", fr:"La journée d’exploitation n’est clôturée qu’après le contrôle complet et la validation de tous les vols. La clôture libère les données du jour pour l’export vers Vereinsflieger."},
    "Flugdaten kontrollieren": {de:"Flugdaten kontrollieren", fr:"Contrôler les données de vol"},
    "Alle Flüge freigeben": {de:"Alle Flüge freigeben", fr:"Valider tous les vols"},
    "Tagesabschlussprüfung auf Grün bringen": {de:"Tagesabschlussprüfung auf Grün bringen", fr:"Faire passer le contrôle de clôture journalière au vert"},
    "Betriebstag abschliessen und für VF freigeben": {de:"Betriebstag abschliessen und für VF freigeben", fr:"Clôturer la journée d’exploitation et la valider pour VF"},
    "Tagesabschluss öffnen": {de:"Tagesabschluss öffnen", fr:"Ouvrir la clôture journalière"},
    "Flugdienstleiterdienst": {de:"Flugdienstleiterdienst", fr:"Service de chef de vol"},
    "C4-Tagesexport nach VF": {de:"C4-Tagesexport nach VF", fr:"Export journalier C4 vers VF"},
    "Im Pilotbetrieb führt ein Administrator den Export nach jedem abgeschlossenen Flugtag manuell aus.": {de:"Im Pilotbetrieb führt ein Administrator den Export nach jedem abgeschlossenen Flugtag manuell aus.", fr:"Pendant la phase pilote, un administrateur effectue manuellement l’export après chaque journée de vol clôturée."},
    "C4-Tagesexport öffnen": {de:"C4-Tagesexport öffnen", fr:"Ouvrir l’export journalier C4"},
    "Exportjournal": {de:"Exportjournal", fr:"Journal des exports"},
    "Korrekturen exportierter Flüge": {de:"Korrekturen exportierter Flüge", fr:"Corrections des vols exportés"},
    "Lokale Korrekturen und die manuelle Abstimmung mit VF werden revisionssicher geführt.": {de:"Lokale Korrekturen und die manuelle Abstimmung mit VF werden revisionssicher geführt.", fr:"Les corrections locales et la synchronisation manuelle avec VF sont consignées de manière traçable."},
    "D1-Änderungsliste": {de:"D1-Änderungsliste", fr:"Liste des modifications D1"},
    "Manuelle VF-Synchronisation": {de:"Manuelle VF-Synchronisation", fr:"Synchronisation manuelle avec VF"},
    "Benutzer, Stammdaten und den Personenabgleich mit Vereinsflieger verwalten.": {de:"Benutzer, Stammdaten und den Personenabgleich mit Vereinsflieger verwalten.", fr:"Gérer les utilisateurs, les données de base et le rapprochement des personnes avec Vereinsflieger."},
    "CSV-Stammdatenimport": {de:"CSV-Stammdatenimport", fr:"Import CSV des données de base"},
    "Personen aus VF synchronisieren": {de:"Personen aus VF synchronisieren", fr:"Synchroniser les personnes depuis VF"},
    "Chef de service de voldienst": {de:"Chef de service de voldienst", fr:"Service de chef de vol"},
    "Clôture journalière Check de service de vol": {de:"Clôture journalière Check de service de vol", fr:"Clôture journalière du chef de service de vol"},
    "Correctionen exportierter Flüge": {de:"Correctionen exportierter Flüge", fr:"Corrections des vols exportés"},
    "Utilisateur, Stammdaten und den Personenabgleich mit Vereinsflieger verwalten.": {de:"Utilisateur, Stammdaten und den Personenabgleich mit Vereinsflieger verwalten.", fr:"Gérer les utilisateurs, les données de base et le rapprochement des personnes avec Vereinsflieger."},
    "Von": {de:"Von", fr:"Du"},
    "Bis": {de:"Bis", fr:"Au"},
    "Status": {de:"Status", fr:"Statut"},
    "Laden": {de:"Laden", fr:"Charger"},
    "kTrax-Import": {de:"kTrax-Import", fr:"Import kTrax"},
    "Heute": {de:"Heute", fr:"Aujourd’hui"},
    "Gestern": {de:"Gestern", fr:"Hier"},
    "Letzte 7 Tage": {de:"Letzte 7 Tage", fr:"7 derniers jours"},
    "Zeitraum:": {de:"Zeitraum:", fr:"Période :"},
    "bis": {de:"bis", fr:"au"},
    "pending": {de:"pending", fr:"Ouvert"},
    "correction_required": {de:"correction_required", fr:"Correction requise"},
    "approved": {de:"approved", fr:"Validé"},
    "exported": {de:"exported", fr:"Exporté"},
    "all": {de:"all", fr:"Tous"},
    "Korrektur": {de:"Korrektur", fr:"Correction"},
    "Alle": {de:"Alle", fr:"Tous"},
    "Keine Einträge im aktuellen Filter.": {de:"Keine Einträge im aktuellen Filter.", fr:"Aucune entrée dans le filtre actuel."},
    "Kein Motorflug/Schlepp vorhanden.": {de:"Kein Motorflug/Schlepp vorhanden.", fr:"Aucun vol moteur/remorquage disponible."},
    "Schlepp zu Segelflug erfassen": {de:"Schlepp zu Segelflug erfassen", fr:"Saisir le remorquage pour le vol en planeur"},
    "Kein Segelflug zu diesem Motorflug vorhanden.": {de:"Kein Segelflug zu diesem Motorflug vorhanden.", fr:"Aucun vol en planeur correspondant à ce vol moteur."},
    "Segelflug zu Schleppflug erfassen": {de:"Segelflug zu Schleppflug erfassen", fr:"Saisir le vol en planeur pour le vol de remorquage"},
    "Operation": {de:"Operation", fr:"Opération"},
    "Segelflug löschen": {de:"Segelflug löschen", fr:"Supprimer le vol en planeur"},
    "Motorflug löschen": {de:"Motorflug löschen", fr:"Supprimer le vol moteur"},
    "Speichern & Freigeben": {de:"Speichern & Freigeben", fr:"Enregistrer et valider"},
    "Bereits nach Vereinsflieger exportiert, schreibgeschützt.": {de:"Bereits nach Vereinsflieger exportiert, schreibgeschützt.", fr:"Déjà exporté vers Vereinsflieger, en lecture seule."},
    "Änderungen, Korrekturen und Löschungen sind in der Flugfreigabemaske nicht mehr möglich.": {de:"Änderungen, Korrekturen und Löschungen sind in der Flugfreigabemaske nicht mehr möglich.", fr:"Les modifications, corrections et suppressions ne sont plus possibles dans l’écran de validation des vols."},
    "D1-Änderungsliste": {de:"D1-Änderungsliste", fr:"Liste des modifications D1"},
    "Flug korrigieren": {de:"Flug korrigieren", fr:"Corriger le vol"},
    "+ Flug manuell erfassen": {de:"+ Flug manuell erfassen", fr:"+ Saisir un vol manuellement"},
    "Flugdienstleiter": {de:"Flugdienstleiter", fr:"Chef de service de vol"},
    "Benutzerverwaltung": {de:"Benutzerverwaltung", fr:"Gestion des utilisateurs"},
    "Dashboard": {de:"Dashboard", fr:"Tableau de bord"},
    "Flugfreigaben": {de:"Flugfreigaben", fr:"Validations des vols"}
  };

  let table = {};
  let ready = false;
  let translating = false;

  function currentLang(){
    const lang = localStorage.getItem(LANG_KEY) || getCookie('lszj_lang') || 'de';
    return ['de','fr'].indexOf(lang) >= 0 ? lang : 'de';
  }

  function getCookie(name){
    const prefix = name + '=';
    const parts = document.cookie.split(';');
    for(const part of parts){
      const trimmed = part.trim();
      if(trimmed.indexOf(prefix) === 0) return decodeURIComponent(trimmed.slice(prefix.length));
    }
    return '';
  }

  function setLang(lang){
    if(['de','fr'].indexOf(lang) < 0) return;
    localStorage.setItem(LANG_KEY, lang);
    document.cookie = 'lszj_lang=' + encodeURIComponent(lang) + '; path=/; max-age=31536000';
    location.reload();
  }

  function fallbackTable(lang){
    const out = {};
    Object.keys(fallbackPairs).forEach(function(key){
      out[key] = fallbackPairs[key][lang] || fallbackPairs[key].de || key;
    });
    return out;
  }

  async function loadTable(){
    const lang = currentLang();
    document.documentElement.lang = lang;
    table = fallbackTable(lang);
    try {
      const response = await fetch('api_i18n.php?lang=' + encodeURIComponent(lang) + '&_=' + Date.now());
      const payload = await response.json();
      if(payload && payload.ok && payload.translations){
        table = Object.assign(table, payload.translations);
      }
    } catch(error) {
      console.warn('LSZJ i18n: DB fallback active', error);
    }
    ready = true;
  }

  function translateText(text){
    if(!text) return text;
    const source = String(text);
    if(Object.prototype.hasOwnProperty.call(table, source)) return table[source];

    // HTML-Textknoten enthalten oft Zeilenumbrüche und Einrückungen.
    // Für die Suche werden nur innere Leerzeichen normalisiert; die äusseren
    // Leerzeichen bleiben erhalten. Keine Teilwort-Ersetzung mehr, damit keine
    // Mischtexte wie "Chef de service de voldienst" entstehen.
    const leading = (source.match(/^\s*/) || [''])[0];
    const trailing = (source.match(/\s*$/) || [''])[0];
    const core = source.trim().replace(/\s+/g, ' ');
    if(Object.prototype.hasOwnProperty.call(table, core)){
      return leading + table[core] + trailing;
    }
    return source;
  }

  function shouldSkipNode(node){
    const parent = node && node.parentElement;
    if(!parent) return false;
    const tag = (parent.tagName || '').toLowerCase();
    return ['script','style','textarea'].indexOf(tag) >= 0;
  }

  function translateNode(node){
    if(!node || !node.nodeValue || shouldSkipNode(node)) return;
    const original = node.nodeValue;
    const translated = translateText(original);
    if(translated !== original) node.nodeValue = translated;
  }

  function translateAttributes(element){
    ['title','placeholder','aria-label','value'].forEach(function(attr){
      if(!element.hasAttribute || !element.hasAttribute(attr)) return;
      if(attr === 'value' && ['button','submit','reset'].indexOf((element.type || '').toLowerCase()) < 0) return;
      const original = element.getAttribute(attr);
      const translated = translateText(original);
      if(translated !== original) element.setAttribute(attr, translated);
    });
  }

  function addLangSwitcher(){
    let host = document.querySelector('.nav');
    if(!host){
      host = document.querySelector('.i18n-switcher-host');
      if(!host){
        host = document.createElement('div');
        host.className = 'i18n-switcher-host';
        host.setAttribute('aria-label', 'Sprachauswahl');
        document.body.appendChild(host);
      }
    }
    if(!document.querySelector('.lang-switch')){
      const style = document.createElement('style');
      style.textContent = '.i18n-switcher-host{position:fixed;right:12px;top:12px;z-index:9999;background:#fff;border:1px solid #d9e0e8;border-radius:999px;padding:3px;box-shadow:0 2px 8px rgba(15,23,42,.14)}.lang-switch{display:inline-flex;gap:2px}.lang-switch button{border:0;background:transparent;border-radius:999px;padding:5px 8px;font:inherit;font-size:.78rem;font-weight:800;cursor:pointer}.lang-switch button.active{background:#1769aa;color:#fff}';
      document.head.appendChild(style);
      const box = document.createElement('span');
      box.className = 'lang-switch';
      box.innerHTML = '<button type="button" data-lang="de" aria-label="Deutsch">DE</button><button type="button" data-lang="fr" aria-label="Français">FR</button>';
      box.querySelector('[data-lang="de"]').onclick = function(){ setLang('de'); };
      box.querySelector('[data-lang="fr"]').onclick = function(){ setLang('fr'); };
      host.appendChild(box);
    }
    updateLangSwitcher();
  }

  function updateLangSwitcher(){
    const lang = currentLang();
    document.querySelectorAll('.lang-switch button').forEach(function(button){
      button.classList.toggle('active', button.dataset.lang === lang);
    });
  }

  function translateAll(){
    if(!ready || translating) return;
    translating = true;
    try {
      addLangSwitcher();
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
      const nodes = [];
      let current;
      while((current = walker.nextNode())) nodes.push(current);
      nodes.forEach(translateNode);
      document.querySelectorAll('input,button,a,option').forEach(translateAttributes);
      updateLangSwitcher();
    } finally {
      translating = false;
    }
  }

  window.lszjI18n = {
    t: translateText,
    translateAll: translateAll,
    setLang: setLang,
    lang: currentLang
  };

  document.addEventListener('DOMContentLoaded', async function(){
    await loadTable();
    translateAll();
  });

  const observer = new MutationObserver(function(){
    translateAll();
  });
  observer.observe(document.documentElement, {childList:true, subtree:true});
})();
