# Sparkassentracker – PHP-Version für den Raspberry Pi

Reines PHP + SQLite, kein Composer, kein Build-Schritt, keine externen Server.
Läuft als Unterpfad **`/tracker/`** im bestehenden nginx-Server von Recordloom,
z. B. `http://recordloom.local/tracker/`, und nutzt dessen PHP-FPM mit.

## Funktionsumfang (aus der Desktop-App übernommen)

- CSV-Import Giro/Tagesgeld (CSV-CAMT) und Kreditkarte, automatische Format-Erkennung,
  Windows-1252, Duplikaterkennung per SHA-256 (Konto im Hash)
- Auto-Kategorisierung über `categories.json` (Dual-Format mit Unterkategorien,
  Leerzeichen-Joker bis 40 Zeichen, längstes Stichwort gewinnt). Gesucht wird in Empfänger,
  Verwendungszweck und Buchungstext; Kombi-Regeln wie `paypal + steam` greifen nur, wenn alle
  Teile vorkommen, und haben Vorrang (z. B. für PayPal-Zahlungen an verschiedene Händler)
- Übertragserkennung zwischen eigenen Konten (Fenster 14 Tage, `config.php`)
- Kontotyp *Alltag* / *Sparkonto* (unter *Import → Konten*): Überträge zwischen
  Alltagskonten (Giro → Kreditkarte) heben sich auf; Überträge auf Sparkonten (Tagesgeld,
  Depot) zählen in „Alle Konten“ als Ausgabe in „Sparen & Anlage › Kontoname“, Entnahmen
  als Einnahme – neben Sparplänen und Bausparen (Kategorie in `config.php`)
- Konto- und Zeitraumfilter, Kategorie-Auswertung mit Unterkategorien, Monatsverlauf
- Wiederkehrende Zahlungen (auto + manuell, ausblenden, Notizen)
- Monatsprognose, Insights (6 Heuristiken), Vorjahresvergleich
- Umsatz bearbeiten: Kategorie, Notiz, Übertrag, direkt als Regel speichern
- CSV-Export, `categories.json` hoch-/herunterladen
- Passwortschutz (Hash in `data/auth.json`), CSRF-Schutz, Dark Mode, mobil nutzbar

Alle Daten bleiben auf dem Pi in `data/` (liegt außerhalb des Web-Roots).

## Voraussetzungen

Recordloom läuft bereits mit nginx + PHP-FPM (auf dem Pi: **PHP 8.4**). Der Tracker
braucht PHP ≥ 8.1 mit den Erweiterungen SQLite und mbstring – meist fehlt nur SQLite:

```bash
sudo apt install php8.4-sqlite3 php8.4-mbstring
```

```bash
sudo systemctl restart php8.4-fpm
```

Prüfen, ob beides geladen ist:

```bash
php8.4 -m | grep -iE 'pdo_sqlite|mbstring'
```

## Installation

Das Repository liegt in deinem normalen Benutzerverzeichnis und wird von dort nach
`/var/www/sparkassentracker` kopiert. Git und der SSH-Schlüssel bleiben bei deinem
Benutzer; `www-data` braucht keinen eigenen Zugang zu GitHub.

Einmalig klonen (mit deinem normalen GitHub-Zugang, das Repository ist privat):

```bash
git clone git@github.com:leshaze/sparkassentracker.git ~/sparkassentracker
```

Ziel- und Backup-Ordner anlegen:

```bash
sudo install -d -o www-data -g www-data -m 770 /var/www/sparkassentracker/data
```

```bash
sudo install -d -m 700 /var/backups/sparkassentracker
```

Dann die Schritte 3–5 aus [Deploy](#deploy) ausführen (kopieren, Rechte setzen,
PHP-FPM neu laden) und nginx wie folgt einrichten.

### nginx-Konfiguration

Den Block aus `deploy/nginx-unterpfad-tracker.conf` **innerhalb** des bestehenden
`server { … }` von Recordloom einfügen, am besten oberhalb von `location /` und
`location ~ \.php$`. Den `fastcgi_pass`-Socket an den von Recordloom angleichen
(z. B. `unix:/run/php/php8.4-fpm.sock`).

```nginx
location = /tracker { return 301 /tracker/; }

location ^~ /tracker/ {
    alias /var/www/sparkassentracker/public/;
    index index.php;
    client_max_body_size 20M;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

Wichtig: `alias` zeigt auf `public/`, nicht auf den Projektordner – so sind
`data/`, `src/` und `config.php` von außen nicht erreichbar. Durch `^~` greifen die
übrigen Regex-Locations von Recordloom nicht für `/tracker/`.

Konfiguration prüfen und nginx neu laden:

```bash
sudo nginx -t
```

```bash
sudo systemctl reload nginx
```

Der Basis-Pfad wird automatisch erkannt; das Login-Cookie gilt nur für `/tracker/`
und kollidiert nicht mit Recordloom.

Größere CSV-Dateien: PHP-FPM begrenzt Uploads standardmäßig auf 2 MB
(`upload_max_filesize`, `post_max_size` in `/etc/php/8.4/fpm/php.ini`).

### Alternativen (nicht mehr Standard)

`deploy/` enthält außerdem Vorlagen für einen eigenen Port 8081
(`nginx-sparkassentracker.conf`, `apache-sparkassentracker.conf`) bzw. den eingebauten
PHP-Server als systemd-Dienst (`sparkassentracker.service`). Diese Varianten nutzen
`/opt/sparkassentracker` als Pfad und werden für das Setup unter `/tracker/` nicht gebraucht.

## Erster Start

`http://recordloom.local/tracker/` öffnen (bzw. `http://<pi-ip>/tracker/`) →
Passwort festlegen → CSVs importieren.

- Bestehende Regeln: `categories.json` der Desktop-App unter *Kategorien* hochladen.
- Bestehende Datenbank: Das Schema entspricht der Desktop-`transactions.db`; eine
  Kopie sollte direkt funktionieren (fehlende Spalten werden ergänzt). Vorher eine
  Sicherung anlegen. Die Datei muss `www-data` gehören, sonst kann PHP nicht schreiben:

  ```bash
  sudo install -o www-data -g www-data -m 660 transactions.db /var/www/sparkassentracker/data/
  ```

  Alternativ einfach die CSVs neu importieren – Duplikate werden übersprungen.

## Deploy

Bei jeder neuen Version:

1. Code holen:

   ```bash
   git -C ~/sparkassentracker pull
   ```

2. Daten sichern (ohne Sessions):

   ```bash
   sudo tar -czf /var/backups/sparkassentracker/data-$(date +%F-%H%M).tar.gz --exclude=data/sessions -C /var/www/sparkassentracker data
   ```

3. Nach `/var/www` kopieren. `data/` (Datenbank, Passwort) und `.git` werden dabei nicht
   angefasst, im Repository gelöschte Dateien werden auch im Ziel entfernt:

   ```bash
   sudo rsync -a --delete --exclude=/data/ --exclude=/.git/ ~/sparkassentracker/ /var/www/sparkassentracker/
   ```

4. Berechtigungen setzen – alles gehört `www-data`, `data/` ist nur für `www-data` zugänglich:

   ```bash
   sudo chown -R www-data:www-data /var/www/sparkassentracker
   ```

   ```bash
   sudo chmod 770 /var/www/sparkassentracker/data
   ```

5. PHP-FPM neu laden, damit sicher der neue Code verwendet wird (Recordloom läuft ungestört weiter):

   ```bash
   sudo systemctl reload php8.4-fpm
   ```

Kein Build-Schritt, keine Migrationen: neue Datenbank-Spalten legt die App beim nächsten
Aufruf selbst an. Nur wenn sich `deploy/nginx-unterpfad-tracker.conf` geändert hat, den
Block in der Recordloom-Konfiguration anpassen und nginx neu laden (`sudo nginx -t`,
dann `sudo systemctl reload nginx`).

Falls `rsync` fehlt: `sudo apt install rsync`.

## Passwort vergessen

Die Datei mit dem Passwort-Hash löschen, beim nächsten Aufruf ein neues Passwort festlegen:

```bash
sudo rm /var/www/sparkassentracker/data/auth.json
```

## Backup

Es reicht, den Ordner `/var/www/sparkassentracker/data/` zu sichern (`transactions.db`,
`categories.json`, `auth.json`). `data/sessions/` enthält nur Login-Sitzungen und muss
nicht gesichert werden. Schritt 2 aus [Deploy](#deploy)
erledigt genau das und legt die Sicherung unter `/var/backups/sparkassentracker/` ab.

Zurückspielen (vorher die aktuelle Version sichern, Dateiname anpassen). Die alten
Datenbankdateien zuerst entfernen, damit keine übrig gebliebene `-wal`-Datei der
aktuellen Datenbank auf die zurückgespielte angewendet wird:

```bash
sudo rm -f /var/www/sparkassentracker/data/transactions.db /var/www/sparkassentracker/data/transactions.db-wal /var/www/sparkassentracker/data/transactions.db-shm
```

```bash
sudo tar -xzf /var/backups/sparkassentracker/data-JJJJ-MM-TT-HHMM.tar.gz -C /var/www/sparkassentracker
```

```bash
sudo chown -R www-data:www-data /var/www/sparkassentracker/data
```

## Sicherheit

Gedacht fürs Heimnetz. Das Passwort direkt nach der Installation festlegen – bis dahin
kann es jeder im Netz setzen, der die Seite zuerst aufruft. Für Zugriff von unterwegs
nicht den Port am Router freigeben, sondern über VPN (WireGuard/Tailscale) gehen oder einen
Reverse-Proxy mit HTTPS davorsetzen.
