#!/usr/bin/env bash
# Sparkassentracker auf dem Pi aktualisieren (und bei der Erstinstallation einrichten).
#
# Aufruf als normaler Benutzer (nicht mit sudo), das Script fragt selbst nach sudo:
#   ~/sparkassentracker/deploy/deploy.sh
#
# Schritte: git pull → Backup von data/ → nach /var/www kopieren → Rechte setzen
#           → PHP-FPM neu laden. data/ (Datenbank, Passwort) wird nie überschrieben.
#
# Pfade bei Bedarf per Umgebungsvariable anpassen, z. B.:
#   PHP_FPM_SERVICE=php8.2-fpm ~/sparkassentracker/deploy/deploy.sh

set -euo pipefail

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
TARGET_DIR="${TARGET_DIR:-/var/www/sparkassentracker}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/sparkassentracker}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
WEB_USER="${WEB_USER:-www-data}"

# Alles in main(): Bash liest das Script so komplett ein, bevor git pull es ggf. ersetzt.
main() {
    local SUDO=""
    [ "$(id -u)" -ne 0 ] && SUDO="sudo"

    step "1/5 Code holen ($REPO_DIR)"
    local before after
    before=$(git -C "$REPO_DIR" rev-parse HEAD)
    git -C "$REPO_DIR" pull --ff-only
    after=$(git -C "$REPO_DIR" rev-parse HEAD)
    if [ "$before" = "$after" ]; then
        echo "   Keine neuen Commits – kopiere trotzdem, damit der Stand sicher passt."
    else
        git -C "$REPO_DIR" log --oneline "$before..$after" | sed 's/^/   /'
    fi

    step "2/5 Daten sichern ($BACKUP_DIR)"
    $SUDO install -d -m 700 "$BACKUP_DIR"
    if [ -n "$($SUDO find "$TARGET_DIR/data" -mindepth 1 -maxdepth 1 ! -name sessions ! -name .gitkeep 2>/dev/null | head -1)" ]; then
        local backup="$BACKUP_DIR/data-$(date +%F-%H%M%S).tar.gz"
        $SUDO tar -czf "$backup" --exclude=data/sessions -C "$TARGET_DIR" data
        echo "   $backup"
    else
        echo "   Noch keine Daten vorhanden (Erstinstallation) – nichts zu sichern."
    fi

    step "3/5 Nach $TARGET_DIR kopieren (ohne data/ und .git)"
    $SUDO install -d -m 755 "$TARGET_DIR"
    $SUDO rsync -a --delete --exclude=/data/ --exclude=/.git/ "$REPO_DIR/" "$TARGET_DIR/"

    step "4/5 Rechte setzen ($WEB_USER)"
    $SUDO install -d "$TARGET_DIR/data"
    $SUDO chown -R "$WEB_USER:$WEB_USER" "$TARGET_DIR"
    $SUDO chmod 770 "$TARGET_DIR/data"

    step "5/5 $PHP_FPM_SERVICE neu laden"
    $SUDO systemctl reload "$PHP_FPM_SERVICE"

    if [ "$before" != "$after" ] && ! git -C "$REPO_DIR" diff --quiet "$before" "$after" -- deploy/nginx-unterpfad-tracker.conf; then
        echo
        echo "⚠  deploy/nginx-unterpfad-tracker.conf hat sich geändert."
        echo "   Block in der Recordloom-Konfiguration anpassen, dann: sudo nginx -t && sudo systemctl reload nginx"
    fi

    echo
    echo "✔ Fertig."
}

step() { printf '\n\033[1m▸ %s\033[0m\n' "$1"; }

main "$@"
