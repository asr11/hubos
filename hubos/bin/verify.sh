#!/bin/bash
# HubOS one-command verification: unit tests + 7-persona concurrent simulation + live health.
# Run on the VPS as root:  bash /home/hub-os/htdocs/os.hub.co.il/bin/verify.sh
# The simulation runs on a private throwaway DB copy on 127.0.0.1 only. The production DB is never written.
set -u
APP="$(cd "$(dirname "$0")/.." && pwd)"
APP_USER=hub-os
PHP=/usr/bin/php
SITE=https://os.hub.co.il
D=/tmp/hubos_sim_$$
PORT=$(shuf -i 20000-40000 -n1)
TOKEN=$(openssl rand -hex 24)
RESULTS=()
FAIL=0

cleanup() {
    pkill -u "$APP_USER" -f "php -S 127.0.0.1:${PORT}" 2>/dev/null
    rm -rf "$D"
}
trap cleanup EXIT

# step <name> <function> — measures wall seconds + peak RSS (MB) of the step
step() {
    local name="$1" fn="$2"
    local tf; tf=$(mktemp)
    /usr/bin/time -f "%e %M" -o "$tf" bash -c "$fn"
    local rc=$?
    local secs kb
    read -r secs kb < <(tail -1 "$tf"); rm -f "$tf"
    local mb=$(( ${kb:-0} / 1024 ))
    if [ $rc -eq 0 ]; then RESULTS+=("PASS  ${name}  ${secs}s  ${mb}MB"); else RESULTS+=("FAIL  ${name}  ${secs}s  ${mb}MB  (rc=$rc)"); FAIL=1; fi
}

unit_tests() {
    su -s /bin/bash "$APP_USER" -c "cd '$APP' && $PHP tests/run.php" | tail -3
    return "${PIPESTATUS[0]}"
}

simulation() {
    mkdir -m 700 "$D" "$D/sessions" "$D/logs" && chown -R "$APP_USER:$APP_USER" "$D"
    su -s /bin/bash "$APP_USER" -c "sqlite3 '$APP/storage/hubos.sqlite' \".backup '$D/hubos.sqlite'\"" || return 2
    local envs="HUBOS_DB_PATH=$D/hubos.sqlite HUBOS_SESSION_PATH=$D/sessions HUBOS_LOG_PATH=$D/logs HUBOS_COOKIE_SECURE=0 HUBOS_AGENT_TOKEN=$TOKEN"
    su -s /bin/bash "$APP_USER" -c "env $envs $PHP '$APP/tests/sim/setup.php'" > "$D/setup.json" || return 3
    su -s /bin/bash "$APP_USER" -c "cd '$APP' && env $envs PHP_CLI_SERVER_WORKERS=4 setsid nohup $PHP -S 127.0.0.1:$PORT -t public public/index.php > '$D/server.log' 2>&1 < /dev/null &"
    for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login" && break; sleep 0.2; done
    $PHP "$APP/tests/sim/sim.php" "$D/setup.json" "http://127.0.0.1:$PORT" "$TOKEN" > "$D/sim.json"
    local rc=$?
    $PHP -r '$s=json_decode(file_get_contents($argv[1]),true); printf("requests=%d failed=%d 5xx=%d leaks=%d db_locked=%d p50=%sms p95=%sms time_entries_ok=%d/%d\n",$s["total_requests"],$s["total_failed"],$s["http_5xx_or_0"],$s["leaks"],$s["db_locked"],$s["latency_ms"]["p50"],$s["latency_ms"]["p95"],$s["write_burst"]["time_entries_ok"],$s["write_burst"]["time_entries_sent"]); foreach($s["failures"] as $k=>$n) echo "  ! $k x$n\n";' "$D/sim.json"
    local integ
    integ=$(su -s /bin/bash "$APP_USER" -c "sqlite3 '$D/hubos.sqlite' 'PRAGMA integrity_check;'")
    echo "copy_integrity=$integ"
    [ "$integ" = "ok" ] || return 4
    if [ -s "$D/logs/app.log" ]; then echo "app.log:"; tail -3 "$D/logs/app.log"; return 5; fi
    return $rc
}

health() {
    local bad=0
    local code; code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/login")
    echo "login_http=$code"; [ "$code" = "200" ] || bad=1
    local end; end=$(echo | openssl s_client -connect os.hub.co.il:443 -servername os.hub.co.il 2>/dev/null | openssl x509 -noout -enddate | cut -d= -f2)
    local days=$(( ( $(date -d "$end" +%s) - $(date +%s) ) / 86400 ))
    echo "ssl_days_left=$days"; [ "$days" -ge 14 ] || bad=1
    local disk; disk=$(df --output=pcent / | tail -1 | tr -dc '0-9')
    echo "disk_used=${disk}%"; [ "$disk" -lt 85 ] || bad=1
    local newest; newest=$(ls -t "$APP/storage/backups"/*.sqlite 2>/dev/null | head -1)
    local age=999
    [ -n "$newest" ] && age=$(( ( $(date +%s) - $(stat -c %Y "$newest") ) / 3600 ))
    echo "newest_backup_age_h=$age"; [ "$age" -le 30 ] || bad=1
    local prod_integ; prod_integ=$(su -s /bin/bash "$APP_USER" -c "sqlite3 -readonly '$APP/storage/hubos.sqlite' 'PRAGMA quick_check;'")
    echo "prod_quick_check=$prod_integ"; [ "$prod_integ" = "ok" ] || bad=1
    return $bad
}

export -f unit_tests simulation health
export APP APP_USER PHP SITE D PORT TOKEN
T0=$(date +%s)
echo "== 1/3 unit tests ==";   step "unit_tests"  unit_tests
echo "== 2/3 simulation ==";   step "simulation"  simulation
echo "== 3/3 health ==";       step "health"      health
echo
echo "== SUMMARY =="
printf '%s\n' "${RESULTS[@]}"
if [ $FAIL -eq 0 ]; then echo "VERIFY: PASS ($(( $(date +%s) - T0 ))s)"; else echo "VERIFY: FAIL ($(( $(date +%s) - T0 ))s)"; fi
exit $FAIL
