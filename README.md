# Sparkassentracker – PHP-Version für den Raspberry Pi

Reines PHP + SQLite, kein Composer, kein Build-Schritt, keine externen Server.
Läuft als eigener Dienst auf **Port 8081** neben Recordloom, ohne dessen
Konfiguration anzufassen.

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

PHP ≥ 8.1 mit SQLite und mbstring. Auf Raspberry Pi OS (Bookworm, PHP 8.2):

```bash
sudo apt install php-cli php-sqlite3 php-mbstring
```

(Läuft Recordloom schon mit PHP, ist meist nur `php-sqlite3` nachzuinstallieren.)

## Installation

```bash
sudo mkdir -p /opt/sparkassentracker
sudo tar -xzf sparkassentracker.tar.gz -C /opt/sparkassentracker --strip-components=1
sudo chown -R pi:pi /opt/sparkassentracker        # Benutzer anpassen
chmod 770 /opt/sparkassentracker/data
```

### Variante A – eigener Dienst (am einfachsten)

Nutzt den eingebauten PHP-Server, völlig unabhängig von Recordloom.
Für einen Nutzer im Heimnetz völlig ausreichend.

```bash
sudo cp /opt/sparkassentracker/deploy/sparkassentracker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sparkassentracker
```

### Variante B – über den vorhandenen Webserver

Wenn Recordloom ohnehin über nginx/Apache + PHP-FPM läuft: einen zweiten
Server-Block auf Port 8081 anlegen (`deploy/nginx-sparkassentracker.conf` bzw.
`deploy/apache-sparkassentracker.conf`). Dann muss `data/` für den
Webserver-Benutzer schreibbar sein:

```bash
sudo chown -R www-data:www-data /opt/sparkassentracker/data
```

## Erster Start

`http://<pi-ip>:8081` öffnen → Passwort festlegen → CSVs importieren.

- Bestehende Regeln: `categories.json` der Desktop-App unter *Kategorien* hochladen
  (oder nach `data/categories.json` kopieren).
- Bestehende Datenbank: Das Schema entspricht der Desktop-`transactions.db`; eine
  Kopie nach `data/transactions.db` sollte direkt funktionieren (fehlende Spalten
  werden ergänzt). Vorher eine Sicherung anlegen. Alternativ einfach die CSVs neu
  importieren – Duplikate werden übersprungen.

## Passwort vergessen

`data/auth.json` löschen → beim nächsten Aufruf neues Passwort festlegen.

## Backup

Es reicht, den Ordner `data/` zu sichern (`transactions.db`, `categories.json`).

## Sicherheit

Gedacht fürs Heimnetz. Für Zugriff von unterwegs nicht den Port am Router
freigeben, sondern über VPN (WireGuard/Tailscale) gehen oder einen
Reverse-Proxy mit HTTPS davorsetzen.
