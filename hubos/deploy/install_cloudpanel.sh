#!/usr/bin/env bash
# ==============================================================================
# OS HUB v2 (hubos) — Idempotent CloudPanel Setup & Deployment Hook
# Usage:
#   APP_DIR=/home/hub-os/htdocs/os.hub.co.il bash deploy/install_cloudpanel.sh
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
BACKUP_ROOT="${BACKUP_ROOT:-/home/backups/daily/hubos}"
PHP_BIN="${PHP_BIN:-php}"

echo "[hubos-deploy] Starting idempotent setup in: $APP_DIR"

# 1. Runtime storage directories & strict permissions (700)
mkdir -p "$APP_DIR/storage/sessions" "$APP_DIR/storage/logs"
chmod 700 "$APP_DIR/storage" "$APP_DIR/storage/sessions" "$APP_DIR/storage/logs"

if compgen -G "$APP_DIR/storage/*.sqlite*" >/dev/null; then
    chmod 600 "$APP_DIR/storage/"*.sqlite* || true
fi

# 2. Pre-migration online SQLite backup (if DB already exists)
if [[ -f "$APP_DIR/storage/hubos.sqlite" ]]; then
    if mkdir -p "$BACKUP_ROOT" 2>/dev/null && [[ -w "$BACKUP_ROOT" ]]; then
        STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
        if command -v sqlite3 >/dev/null 2>&1; then
            sqlite3 "$APP_DIR/storage/hubos.sqlite" ".backup '$BACKUP_ROOT/hubos_pre_migrate_${STAMP}.sqlite'"
        else
            cp -p "$APP_DIR/storage/hubos.sqlite" "$BACKUP_ROOT/hubos_pre_migrate_${STAMP}.sqlite"
        fi
        chmod 600 "$BACKUP_ROOT/hubos_pre_migrate_${STAMP}.sqlite"
        echo "[hubos-deploy] Pre-migration backup saved to $BACKUP_ROOT/hubos_pre_migrate_${STAMP}.sqlite"
    fi
fi

# 3. Run idempotent SQLite migrations
echo "[hubos-deploy] Running database migrations..."
"$PHP_BIN" "$APP_DIR/bin/migrate.php"

if [[ -f "$APP_DIR/storage/hubos.sqlite" ]]; then
    chmod 600 "$APP_DIR/storage/hubos.sqlite"* || true
fi

# 4. Generate nightly SQLite backup & monthly recurring tasks helper script
cat > "$APP_DIR/bin/nightly_maintenance.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${BACKUP_ROOT:-/home/backups/daily/hubos}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
STAMP="$(date -u +%Y-%m-%d)"
if [[ -f "$ROOT/storage/hubos.sqlite" ]]; then
    if command -v sqlite3 >/dev/null 2>&1; then
        sqlite3 "$ROOT/storage/hubos.sqlite" ".backup '$BACKUP_DIR/hubos_${STAMP}.sqlite'"
    else
        cp -p "$ROOT/storage/hubos.sqlite" "$BACKUP_DIR/hubos_${STAMP}.sqlite"
    fi
    gzip -f "$BACKUP_DIR/hubos_${STAMP}.sqlite"
    chmod 600 "$BACKUP_DIR/hubos_${STAMP}.sqlite.gz"
    find "$BACKUP_DIR" -name 'hubos_*.sqlite.gz' -mtime +30 -delete || true
fi
php "$ROOT/bin/recurring.php" >/dev/null
EOF
chmod 750 "$APP_DIR/bin/nightly_maintenance.sh"

echo "[hubos-deploy] Setup complete."
