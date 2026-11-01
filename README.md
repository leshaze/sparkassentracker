# Sparkassentracker – PHP-Version für den Raspberry Pi

Reines PHP + SQLite, kein Composer, kein Build-Schritt, keine externen Server.
Läuft als Unterpfad **`/tracker/`** im bestehenden nginx-Server von Recordloom,
z. B. `http://recordloom.local/tracker/`, und nutzt dessen PHP-FPM mit.

## Funktionsumfang (aus der Desktop-App übernommen)

- CSV-Import Giro/Tagesgeld (CSV-CAMT) und Kreditkarte, automatische Format-Erkennung,
  Windows-1252, Duplikaterkennung per SHA-256 (Konto im Hash)
- Auto-Kategorisierung über `categories.json` (Dual-Format mit Unterkategorien,
  Leerzeichen-Joker bis 40 Zeichen, längstes Stichwort gewinnt)
- Übertragserkennung zwischen eigenen Konten (Fenster 14 Tage, `config.php`)
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

Code per Git nach `/var/www/sparkassentracker` holen (dieser Pfad steht in der
nginx-Konfiguration). Der Code gehört dem normalen Benutzer (hier `pi`), damit
Updates ohne `sudo` per `git pull` gehen. Ist das Repository privat, auf dem Pi einen
SSH-Schlüssel anlegen und bei GitHub als *Deploy Key* (nur lesen) hinterlegen.

```bash
sudo mkdir -p /var/www/sparkassentracker
```

```bash
sudo chown pi:pi /var/www/sparkassentracker
```

```bash
git clone git@github.com:leshaze/sparkassentracker.git /var/www/sparkassentracker
```

Danach die Dateirechte setzen wie im Abschnitt [Dateirechte](#dateirechte) beschrieben.

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

### Dateirechte

| Pfad | Besitzer | Rechte | Warum |
|---|---|---|---|
| `/var/www/sparkassentracker` (Code) | `pi:pi` | `755` / `644` | `git pull` ohne sudo; `www-data` darf nur lesen |
| `/var/www/sparkassentracker/data` | `www-data:www-data` | `2770` | PHP schreibt hier DB, Regeln, Passwort-Hash, Sessions; andere haben keinen Zugriff |

Das `2` (setgid) sorgt dafür, dass neue Dateien in `data/` der Gruppe `www-data` gehören.
`pi` kommt in die Gruppe `www-data`, damit Backups und `git status` ohne sudo gehen.

Einmalig nach dem `git clone` ausführen:

```bash
sudo chown -R www-data:www-data /var/www/sparkassentracker/data
```

```bash
sudo chmod 2770 /var/www/sparkassentracker/data
```

```bash
sudo usermod -aG www-data pi
```

Die Gruppenmitgliedschaft gilt erst nach erneutem Anmelden (SSH-Sitzung neu öffnen).

Prüfen:

```bash
ls -ld /var/www/sparkassentracker /var/www/sparkassentracker/data
```

Erwartet: `drwxr-xr-x pi pi` für den Code und `drwxrws--- www-data www-data` für `data/`.

### Update auf dem Pi

Neue Versionen vom Branch `main` holen.

1. Daten sichern (ohne Sessions):

   ```bash
   tar -czf ~/sparkassentracker-data-$(date +%F).tar.gz --exclude=data/sessions -C /var/www/sparkassentracker data
   ```

2. Code aktualisieren:

   ```bash
   git -C /var/www/sparkassentracker pull --ff-only
   ```

3. PHP-FPM neu laden, damit der OPcache sicher den neuen Code verwendet
   (laufende Anfragen werden sauber beendet, Recordloom merkt davon nichts):

   ```bash
   sudo systemctl reload php8.4-fpm
   ```

4. Nur wenn sich `deploy/nginx-unterpfad-tracker.conf` geändert hat: den Block in der
   Recordloom-Konfiguration anpassen, dann `sudo nginx -t` und `sudo systemctl reload nginx`.

Datenbank-Änderungen (neue Spalten) übernimmt die App beim nächsten Aufruf selbst.
Die Rechte bleiben bei `git pull` erhalten; `data/` wird von Git nicht angefasst
(außer `data/.gitkeep`).

Falls `git pull` mit *dubious ownership* abbricht, gehört der Ordner nicht dem
aufrufenden Benutzer – dann den `chown pi:pi`-Schritt aus der Installation wiederholen
(nur für den Code-Ordner, nicht für `data/`).

## Passwort vergessen

Die Datei mit dem Passwort-Hash löschen, beim nächsten Aufruf ein neues Passwort festlegen:

```bash
sudo rm /var/www/sparkassentracker/data/auth.json
```

## Backup

Es reicht, den Ordner `/var/www/sparkassentracker/data/` zu sichern (`transactions.db`,
`categories.json`, `auth.json`). `data/sessions/` enthält nur Login-Sitzungen und muss
nicht gesichert werden. Der `tar`-Befehl aus [Update auf dem Pi](#update-auf-dem-pi)
erledigt genau das.

## Sicherheit

Gedacht fürs Heimnetz. Das Passwort direkt nach der Installation festlegen – bis dahin
kann es jeder im Netz setzen, der die Seite zuerst aufruft. Für Zugriff von unterwegs
nicht den Port am Router freigeben, sondern über VPN (WireGuard/Tailscale) gehen oder einen
Reverse-Proxy mit HTTPS davorsetzen.
