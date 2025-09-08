# Dateibasierte-Link-Liste
Hier haben wir eine komplette, dateibasierte Link-Liste mit Kategorien und Adminbereich als einzelne PHP-Datei.  Du findest den Code als linklist.php.

1. Datei ablegen

Lege linklist.php auf deinem Webserver ab (PHP 8+ empfohlen).

Das Script legt automatisch ein Verzeichnis data_links/ an und setzt 0777 (nur für Demo/Test; in Produktion bitte saubere Owner/Gruppenrechte setzen).

Ändere im Kopf der Datei das ADMIN_PASSWORD.

2. Adminbereich

Öffne https://deine-domain/path/linklist.php?admin=1, logge dich mit dem gesetzten Passwort ein.

Kategorien anlegen/umbenennen/löschen.

Links pro Kategorie hinzufügen/bearbeiten/löschen, optional anheften.

3. In bestehende Seite einbinden (PHP include)

<?php
include __DIR__ . '/linklist.php';

echo render_linklist([
  'category'  => null,   // z.B. 'news' für eine Kategorie, sonst alle
  'show_desc' => true,
  'limit'     => 20,
  'order'     => 'pin',  // 'pin' | 'new' | 'alpha'
]);

4. Alternativ per API/iframe (ohne PHP-Include)
HTML-Fragment: 
/linklist.php?api=1&format=html&category=news&show_desc=1&order=pin&limit=20

JSON-Ausgabe (z.B. für Fetch):
/linklist.php?api=1&format=json

Einbettung per iframe:
<iframe src="/path/linklist.php?api=1&format=html&show_desc=1" width="100%" height="520"></iframe>

Sicherheit & Hinweise:
- chmod 777 ist nur für Tests sinnvoll. In Produktion besser: Verzeichnis dem PHP-User gehören lassen und 750/640 o.ä. setzen.

- Das Admin-Login ist absichtlich simpel (Passwort + CSRF). Wenn du möchtest, rüste HTTP Basic Auth oder IP-Restriktion vor dem Script nach.

- Flatfiles sind nicht transaktional; für sehr große Datenmengen oder parallele Zugriffe wäre eine DB ratsam.

