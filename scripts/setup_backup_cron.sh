#!/bin/bash
# setup_backup_cron.sh
# Adds a cron job to run backup_engine.py automatically every hour.

SCRIPT_PATH="/home/asr11/02_HUB_Dev/os.hub.co.il/scripts/backup_engine.py"
CRON_JOB="0 * * * * /usr/bin/python3 $SCRIPT_PATH >> /home/asr11/02_HUB_Dev/os.hub.co.il/backups/backup_cron.log 2>&1"

# Check if cron job already exists
if crontab -l | grep -q "$SCRIPT_PATH"; then
    echo "Cron job already exists."
else
    # Add to crontab
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    echo "Cron job added: $CRON_JOB"
fi
