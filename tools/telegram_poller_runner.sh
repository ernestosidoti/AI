#!/bin/bash
# Wrapper che riavvia il poller Telegram se crasha.
# Stop con: pkill -f telegram_poller_runner.sh
# Log principale:  storage/logs/poller.log
# Eventi strutturati: storage/logs/poller-events.jsonl

AI_DIR="/Applications/MAMP/htdocs/ai"
PHP="/Applications/MAMP/bin/php/php8.3.14/bin/php"
LOG_DIR="$AI_DIR/storage/logs"
STDOUT_LOG="$LOG_DIR/poller.log"
MAX_RESTARTS=50
RESTART_WINDOW=300  # sec: se più di N restart in questa finestra → stop

mkdir -p "$LOG_DIR"

restart_count=0
window_start=$(date +%s)

while true; do
    now=$(date +%s)
    if [ $((now - window_start)) -gt $RESTART_WINDOW ]; then
        restart_count=0
        window_start=$now
    fi

    if [ $restart_count -ge $MAX_RESTARTS ]; then
        echo "[$(date '+%F %T')] ❌ Troppi restart ($restart_count in ${RESTART_WINDOW}s), esco." >> "$STDOUT_LOG"
        exit 1
    fi

    echo "[$(date '+%F %T')] 🚀 Avvio poller (restart #$restart_count)" >> "$STDOUT_LOG"
    cd "$AI_DIR"
    "$PHP" tools/telegram_poller.php >> "$STDOUT_LOG" 2>&1
    exit_code=$?
    echo "[$(date '+%F %T')] ⚠️  Poller terminato con exit=$exit_code, riavvio tra 3s..." >> "$STDOUT_LOG"
    restart_count=$((restart_count + 1))
    sleep 3
done
