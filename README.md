# LoxBerry-Plugin-miniserverbackup-ng

![Miniserver Backup NG](./webfrontend/html/logo.png)

This is a [LoxBerry](https://wiki.loxberry.de/) plugin and only works on the LoxBerry platform. For more details visit the [LoxBerry Wiki](https://wiki.loxberry.de/plugins/miniserverbackup-ng/start).

This plugin was forked from [github.com/Woersty/LoxBerry-Plugin-miniserverbackup](https://github.com/Woersty/LoxBerry-Plugin-miniserverbackup).


## Lokale Anpassung für LoxBerry 4

Version **2026.10.17.2**, basierend auf Upstream `26ccfd0` (2025.03.12).
Zielsystem: LoxBerry 4.0.0.15, Raspberry Pi 4 (aarch64), Debian 13,
Apache/PHP 7.4.33 und 7-Zip 25.01. Die enthaltene Debian-13-Paketliste
verwendet `7zip` und die PHP-Erweiterungen curl und zip. Der LoxBerry-Installer
passt die PHP-Paketnamen an seine aktive PHP-Version an.

- Unvollständige Dateilisten, HTTP-/Verbindungsfehler, ungültige Einträge und
  fehlgeschlagene Downloads brechen den jeweiligen Miniserverlauf ab. Alte
  Archivdateien werden in diesen Fällen weder verändert noch rotiert;
  der letzte erfolgreiche Sicherungszeitpunkt bleibt erhalten.
- Der Vergleichsbestand wird erst nach vollständiger Downloadprüfung geändert.
  Ein neuer Downloadversuch startet immer mit einer leeren Datei.
- Bei Größenänderungen wird die Dateigröße einmal erneut abgefragt und die
  Datei frisch geladen. Ändert sich eine Datei ständig weiter, wird der Lauf
  sicherheitshalber abgebrochen; das kann insbesondere laufende Logs betreffen.
- Neue 7z-Archive werden vor dem Aufräumen älterer Backups auf Integrität geprüft.
- Geprüfte Serverdateien ersetzen den alten Archivinhalt auch dann, wenn ihr
  Dateidatum älter als das der zuvor archivierten Fassung ist.
- Ein fehlgeschlagener Miniserverlauf liefert einen Fehler-Exitcode.
- Moderne Architekturbezeichnungen werden akzeptiert.
- Bibliotheken werden auch bei CLI-Aufrufen ohne `LBHOMEDIR` gefunden.
- Einstellungen, Dateiverwaltung, Status und Backup-Downloads erfordern die
  LoxBerry-Anmeldung. Auch `API.php` benötigt jetzt HTTP-Basic-Authentifizierung.
- Das Installationsskript installiert die `.htaccess` ausdrücklich, weil der
  LoxBerry-Installer versteckte Dateien auf der obersten HTML-Ebene auslässt.
- Der falsche Platzhalter für die Passwortdatei wurde korrigiert.
- Manuelle Backups funktionieren unabhängig von der eingestellten Tageszeit.
- Neue 7z-Vollbackups entfernen inzwischen auf dem Miniserver gelöschte Dateien.
- 7z-Befehle behandeln Archiv- und Quellpfade mit Leerzeichen korrekt.
- Einstellungen werden erst nach Prüfung der Miniserver und nach Erwerb der
  Dateisperre abgeschnitten. Die Anzeige des freien Speichers benötigt keine Shell.
- Die Erstinstallation legt eine deaktivierte Konfiguration an. Speicherziel und
  Zeitplan können anschließend in der Pluginoberfläche eingerichtet werden.
- Automatische Upstream-Updates sind deaktiviert, damit diese lokale Anpassung
  erhalten bleibt.

Installation: ZIP in der LoxBerry-Pluginverwaltung hochladen. Diese Arbeitskopie
ändert die laufende Installation nicht. Ein vollständiges Backup und ein
Wiederherstellungstest am echten Miniserver müssen nach der Einrichtung erfolgen.

Prüfung (auf dem LoxBerry mit installierten Core-Bibliotheken):

```sh
python3 tests/compatibility.py
python3 tests/install_smoke.py
python3 tests/failure_regression.py
python3 tests/auth_smoke.py
```

Diese Tests verwenden künstliche Daten und separate temporäre Verzeichnisse.
Der Apache-Test startet einen eigenen Prozess an einem lokalen Testport.
Es wird kein echter Miniserver kontaktiert und keine Systemkonfiguration geändert.

ZIP erneut bauen: `python3 tools/build_package.py`. Das Paket und seine
SHA256-Prüfsumme liegen anschließend unter `dist/`.

Die Version 2026.10.17.2 ersetzt das erste lokale ZIP 2026.10.17.1. Sie sichert
gezielt die Fehlerbehandlung bei Auflistung und Download ab. Ein Abbruch durch
Stromausfall, ein vollständiger Upgrade-Rollback und die Wiederherstellung am
echten Miniserver sind weiterhin nicht durch diese Tests abgedeckt.
