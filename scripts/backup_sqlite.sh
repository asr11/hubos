#!/bin/bash
# Automated SQLite backup for OS Hub
DB_PATH="/home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db"
BACKUP_DIR="/home/asr11/02_HUB_Dev/os.hub.co.il/backups/auto"
DATE=$(date +%Y-%m-%d_%H%M%S)
MAX_BACKUPS=14

mkdir -p "$BACKUP_DIR"

# Use SQLite .backup command for consistency
python3 -c "
import sqlite3, shutil
conn = sqlite3.connect('$DB_PATH')
backup = sqlite3.connect('${BACKUP_DIR}/os_hub_${DATE}.db')
conn.backup(backup)
backup.close()
conn.close()
print('Backup created: os_hub_${DATE}.db')
"

# Rotate old backups (keep last MAX_BACKUPS)
ls -t "$BACKUP_DIR"/os_hub_*.db 2>/dev/null | tail -n +$((MAX_BACKUPS + 1)) | xargs rm -f 2>/dev/null

echo "[$(date)] Backup completed. $(ls "$BACKUP_DIR" | wc -l) backups retained."
