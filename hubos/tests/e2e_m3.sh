#!/usr/bin/env bash
# M3/M4 E2E (timer, hours bank, tasks, needs-linking) against a COPY of a real DB.
# The source DB is never opened/modified (sha256 checked before/after). The copy is
# migrated (003) and gets ZZ_ users only. No network.
#   bash tests/e2e_m3.sh [source.sqlite]       (default: storage/hubos.sqlite)
#   SHOTS=/abs/dir bash tests/e2e_m3.sh       also take headless-Chrome screenshots
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-$ROOT/storage/hubos.sqlite}"
TMP="$(mktemp -d)"
PORT="${PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
JAR="$TMP/cookies.txt"
PID=""; SPID=""

[[ -f "$SRC" ]] || { echo "source DB not found: $SRC"; exit 2; }
srcsum() { { cat "$SRC"; [[ -f "$SRC-wal" ]] && cat "$SRC-wal"; true; } | sha256sum | cut -d' ' -f1; }
SRC_SUM_BEFORE=$(srcsum)
cp "$SRC" "$TMP/copy.sqlite"
[[ -f "$SRC-wal" ]] && cp "$SRC-wal" "$TMP/copy.sqlite-wal"

export HUBOS_DB_PATH="$TMP/copy.sqlite"
export HUBOS_SESSION_PATH="$TMP/sessions"
export HUBOS_LOG_PATH="$TMP/logs"
export HUBOS_COOKIE_SECURE=0
export HUBOS_REGISTRY_URL="http://127.0.0.1:9/registry?resource_id=x"
mkdir -p "$HUBOS_SESSION_PATH"

cleanup() {
  for p in "$PID" "$SPID"; do [[ -n "$p" ]] && { kill "$p" 2>/dev/null || true; wait "$p" 2>/dev/null || true; }; done
  if [[ "${KEEP:-0}" == 1 ]]; then echo "kept: $TMP"; return; fi
  rm -rf "$TMP"
  echo "cleanup: server stopped, temp dir removed ($( [[ -e "$TMP" ]] && echo FAIL || echo ok ))"
}
trap cleanup EXIT

PASS=0; FAIL=0
check() { if [[ "$2" == "$3" ]]; then PASS=$((PASS+1)); echo "  PASS  $1 -> $3"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 -> expected $2, got $3"; fi; }
csrf_from() { grep -o 'name="_csrf" value="[0-9a-f]*"' "$1" | head -n1 | sed 's/.*value="//; s/"$//'; }
q() { php -r '$p=new PDO("sqlite:".getenv("HUBOS_DB_PATH")); echo $p->query($argv[1])->fetchColumn();' "$1"; }
bank_remaining() { HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
$t = new HubOS\Core\Tenant($pdo, (int)$argv[1]);
printf("%.6f", (new HubOS\Modules\Time\HoursBankService($t))->summarize($t->find("hours_banks", (int)$argv[2]))["remaining_hours"]);' "$1" "$2"; }

echo "  migrate copy: $(php "$ROOT/bin/migrate.php" | tail -n1)"
PW="zz-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
MANO=$(q "SELECT id FROM clients WHERE legacy_id='gi_mano_123'")
AVANT=$(q "SELECT parent_client_id FROM clients WHERE id=$MANO")
TENANT=$(q "SELECT tenant_id FROM clients WHERE id=$MANO")
BANK=$(q "SELECT id FROM hours_banks WHERE client_id=$MANO AND status='active' ORDER BY id LIMIT 1")
echo "  copy: mano=$MANO avantgarde=$AVANT bank=$BANK tenant=$TENANT"
check "Mano is client 286" 286 "$MANO"

HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_admin_m3@hubos.test", "ZZ_Admin", "admin", $argv[2]);
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_viewer_m3@hubos.test", "ZZ_Viewer", "client_viewer", $argv[2], null, (int)$argv[3]);
' "$TENANT" "$PW" "$MANO"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

login() { # email jar
  curl -s -c "$2" -b "$2" -o "$TMP/login.html" "$BASE/login"
  local tok; tok=$(csrf_from "$TMP/login.html")
  curl -s -c "$2" -b "$2" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$tok" --data-urlencode "email=$1" --data-urlencode "password=$PW" "$BASE/login"
}
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/$2" -w '%{http_code}' "$BASE$1"; }
post() { local url="$1"; shift; curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w '%{http_code}' --data-urlencode "_csrf=$TOKEN" "$@" "$BASE$url"; }

check "admin login" 303 "$(login zz_admin_m3@hubos.test "$JAR")"
check "GET /clients/$MANO" 200 "$(get "/clients/$MANO" mano.html)"
TOKEN=$(csrf_from "$TMP/mano.html")
check "card shows start-timer form" 1 "$(grep -c 'id="client-timer-form"' "$TMP/mano.html")"
R0=$(bank_remaining "$TENANT" "$BANK")
echo "  Mano bank remaining before: $R0"

# 1. start timer for Mano
check "POST /timer/start client=$MANO" 303 "$(post /timer/start --data-urlencode "client_id=$MANO" --data-urlencode "_return=/clients/$MANO")"
check "active_timers row for admin" 1 "$(q "SELECT COUNT(*) FROM active_timers a JOIN users u ON u.id=a.user_id WHERE u.email='zz_admin_m3@hubos.test' AND a.client_id=$MANO")"
sleep 2
# 2. reload / navigate -> still running (server-side state)
check "reload /dashboard -> 200" 200 "$(get /dashboard dash.html)"
check "header widget shows running timer" 1 "$(grep -c 'id="timer-elapsed"[^>]*data-running="1"' "$TMP/dash.html")"
check "header widget links the timer client" 1 "$(grep -c "id=\"timer-client\"" "$TMP/dash.html")"
grep -q "href=\"/clients/$MANO\" id=\"timer-client\"" "$TMP/dash.html" && check "timer client is Mano" 1 1 || check "timer client is Mano" 1 0
get /timer timer.json >/dev/null
check "GET /timer JSON active+running" 1 "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["active"] && $d["running"] && $d["elapsed"]>=1 && $d["client_id"]==(int)$argv[2]);' "$TMP/timer.json" "$MANO")"
echo "  /timer: $(cat "$TMP/timer.json")"
get "/clients/$MANO" mano2.html >/dev/null
check "card says timer running on this client" 1 "$(grep -c 'id="client-timer-running"' "$TMP/mano2.html")"
# one timer per user
post /timer/start --data-urlencode "client_id=$AVANT" >/dev/null
check "second start rejected (still 1 timer)" 1 "$(q "SELECT COUNT(*) FROM active_timers")"
# stop without description -> rejected
E0=$(q "SELECT COUNT(*) FROM time_entries")
post /timer/stop --data-urlencode "description=" >/dev/null
check "stop without description keeps timer, no entry" "1 $E0" "$(q "SELECT COUNT(*) FROM active_timers") $(q "SELECT COUNT(*) FROM time_entries")"
sleep 1
# 3. stop with description
check "POST /timer/stop with description" 303 "$(post /timer/stop --data-urlencode "description=ZZ_E2E תיקון באתר" --data-urlencode "_return=/clients/$MANO?tab=time")"
check "timer removed" 0 "$(q "SELECT COUNT(*) FROM active_timers")"
check "entry created for Mano, linked to bank, source timer" "1" "$(q "SELECT COUNT(*) FROM time_entries WHERE description='ZZ_E2E תיקון באתר' AND client_id=$MANO AND hours_bank_id=$BANK AND source='timer' AND duration_seconds>=3")"
echo "  entry: $(q "SELECT id||' secs='||duration_seconds||' date='||entry_date FROM time_entries WHERE description='ZZ_E2E תיקון באתר'")"
R1=$(bank_remaining "$TENANT" "$BANK")
echo "  Mano bank remaining after timer: $R1"
check "Mano bank remaining decreased (timer)" 1 "$(php -r 'echo (int)((float)$argv[2] < (float)$argv[1]);' "$R0" "$R1")"
# manual entry from the card (0:30) -> visible decrease on the card
check "manual entry from card" 303 "$(post /time/entries --data-urlencode "client_id=$MANO" --data-urlencode "duration=0:30" --data-urlencode "description=ZZ_E2E ידני" --data-urlencode "_return=/clients/$MANO?tab=time")"
R2=$(bank_remaining "$TENANT" "$BANK")
check "bank remaining decreased by 0.5h" 1 "$(php -r 'echo (int)(abs(((float)$argv[1]-(float)$argv[2]) - 0.5) < 0.0001);' "$R1" "$R2")"
get "/clients/$MANO?tab=time" mano_time.html >/dev/null
check "card time tab lists the timer entry" 1 "$(grep -c 'ZZ_E2E תיקון באתר' "$TMP/mano_time.html")"
check "card header shows bank remaining $(printf '%.2f' "$R2")" 1 "$(grep -c "id=\"bank-remaining\">$(printf '%.2f' "$R2")<" "$TMP/mano_time.html")"
check "events: timer.started/stopped + time_entry.created" "1 1 2" "$(q "SELECT COUNT(*) FROM events WHERE action='timer.started'") $(q "SELECT COUNT(*) FROM events WHERE action='timer.stopped'") $(q "SELECT COUNT(*) FROM events WHERE action='time_entry.created'")"
check "GET /time" 200 "$(get "/time?client_id=$MANO" time.html)"
check "/time shows the entry + total" 1 "$(grep -c 'ZZ_E2E תיקון באתר' "$TMP/time.html")"

# 4. task requires client
T0=$(q "SELECT COUNT(*) FROM tasks")
check "POST /tasks without client -> 422" 422 "$(post /tasks --data-urlencode "title=ZZ_E2E task no client")"
check "no task created" "$T0" "$(q "SELECT COUNT(*) FROM tasks")"
check "422 page shows the client error" 1 "$(grep -c 'id="err-client_id"' "$TMP/post.html")"
check "POST /tasks with client -> 303" 303 "$(post /tasks --data-urlencode "title=ZZ_E2E task" --data-urlencode "client_id=$MANO" --data-urlencode "priority=high")"
ZZT=$(q "SELECT id FROM tasks WHERE title='ZZ_E2E task' AND client_id=$MANO")
check "ZZ_ task stored with client" 1 "$([[ -n "$ZZT" ]] && echo 1 || echo 0)"
check "GET /tasks" 200 "$(get /tasks tasks.html)"
check "GET /projects" 200 "$(get /projects projects.html)"
check "GET /tasks/$ZZT" 200 "$(get "/tasks/$ZZT" task.html)"

# 5. needs-linking queue
check "GET /work/linking" 200 "$(get /work/linking linking.html)"
check "queue counts: 28 tasks / 22 projects" "28 22" "$(grep -o 'id="link-tasks-count">[0-9]*' "$TMP/linking.html" | grep -o '[0-9]*$') $(grep -o 'id="link-projects-count">[0-9]*' "$TMP/linking.html" | grep -o '[0-9]*$')"
ROW55=$(sed -n '/id="task-link-55"/,/<\/tr>/p' "$TMP/linking.html")
check "task 55 suggests Avantgarde ($AVANT)" 1 "$(grep -c "suggestion-btn\" data-client=\"$AVANT\"" <<<"$ROW55")"
check "task 55 suggests Mano ($MANO)" 1 "$(grep -c "suggestion-btn\" data-client=\"$MANO\"" <<<"$ROW55")"
check "suggestion NOT applied by viewing the queue" "" "$(q "SELECT client_id FROM tasks WHERE id=55")"
check "POST link task 55 -> $AVANT" 303 "$(post /work/linking/task/55 --data-urlencode "client_id=$AVANT")"
check "task 55 linked" "$AVANT" "$(q "SELECT client_id FROM tasks WHERE id=55")"
check "task.linked event (used_suggestion)" 1 "$(q "SELECT COUNT(*) FROM events WHERE action='task.linked' AND entity_id=55 AND json_extract(after_json,'$.used_suggestion')=1")"
check "relinking an already linked task -> 409" 409 "$(post /work/linking/task/55 --data-urlencode "client_id=$MANO")"
get "/clients/$AVANT?tab=work" av_work.html >/dev/null
check "task 55 in Avantgarde work tab" 1 "$(grep -c 'id="work-task-55"' "$TMP/av_work.html")"
get "/clients/$MANO?tab=work" mano_work.html >/dev/null
check "task 55 in Mano work tab (family roll-up)" 1 "$(grep -c 'id="work-task-55"' "$TMP/mano_work.html")"
get /work/linking linking2.html >/dev/null
check "queue now 27 tasks" 27 "$(grep -o 'id="link-tasks-count">[0-9]*' "$TMP/linking2.html" | grep -o '[0-9]*$')"

# 6. recurring templates + CLI idempotency (copy only)
check "POST /work/recurring" 303 "$(post /work/recurring --data-urlencode "client_id=$MANO" --data-urlencode "title=ZZ_E2E גיבוי חודשי" --data-urlencode "day_of_month=3")"
R_A=$(php "$ROOT/bin/recurring.php" --month=2026-11 | head -n1)
R_B=$(php "$ROOT/bin/recurring.php" --month=2026-11 | head -n1)
echo "  recurring 1st: $R_A"; echo "  recurring 2nd: $R_B"
check "recurring CLI idempotent" "created=1 created=0" "$(grep -o 'created=[0-9]*' <<<"$R_A") $(grep -o 'created=[0-9]*' <<<"$R_B")"

# 7. viewer
VJAR="$TMP/viewer.txt"
check "viewer login" 303 "$(login zz_viewer_m3@hubos.test "$VJAR")"
check "viewer GET /tasks" 200 "$(curl -s -b "$VJAR" -c "$VJAR" -o "$TMP/v_tasks.html" -w '%{http_code}' "$BASE/tasks")"
check "viewer does not see internal ZZ_ task" 0 "$(grep -c 'ZZ_E2E task' "$TMP/v_tasks.html" || true)"
check "viewer has no timer widget" 0 "$(grep -c 'id="timer-box"' "$TMP/v_tasks.html" || true)"
VT=$(csrf_from "$TMP/v_tasks.html")
check "viewer cannot start timer" 403 "$(curl -s -b "$VJAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$VT" --data-urlencode "client_id=$MANO" "$BASE/timer/start")"
check "viewer cannot create task" 403 "$(curl -s -b "$VJAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$VT" --data-urlencode "client_id=$MANO" --data-urlencode "title=ZZ_v" "$BASE/tasks")"
check "viewer cannot open linking queue" 403 "$(curl -s -b "$VJAR" -o /dev/null -w '%{http_code}' "$BASE/work/linking")"
check "viewer GET /time (totals only)" 200 "$(curl -s -b "$VJAR" -o "$TMP/v_time.html" -w '%{http_code}' "$BASE/time")"
check "viewer /time has no entry descriptions" 0 "$(grep -c 'ZZ_E2E' "$TMP/v_time.html" || true)"
check "viewer /time shows totals note" 1 "$(grep -c 'id="viewer-time-note"' "$TMP/v_time.html")"
curl -s -b "$VJAR" -o "$TMP/v_card_time.html" "$BASE/clients/$MANO?tab=time"
check "viewer card time tab: no descriptions" 0 "$(grep -c 'ZZ_E2E' "$TMP/v_card_time.html" || true)"
check "timer endpoints require CSRF" 403 "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/timer/start")"

# 8. screenshots (optional): a second server whose router injects the admin session cookie
if [[ -n "${SHOTS:-}" ]] && command -v google-chrome >/dev/null; then
  mkdir -p "$SHOTS"
  post /timer/start --data-urlencode "client_id=$MANO" >/dev/null   # running timer for the header screenshot
  SID=$(awk '$6=="hubos_sid"{print $7}' "$JAR" | tail -n1)
  cat >"$TMP/shot_router.php" <<'PHP'
<?php
$f = getenv('SHOT_PUBLIC') . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($f) && !str_ends_with($f, '.php')) { return false; }
$_COOKIE['hubos_sid'] = getenv('SHOT_SID');
require getenv('SHOT_PUBLIC') . '/index.php';
PHP
  SPORT=$((PORT+1))
  SHOT_SID="$SID" SHOT_PUBLIC="$ROOT/public" php -S "127.0.0.1:$SPORT" -t "$ROOT/public" "$TMP/shot_router.php" >"$TMP/shot.log" 2>&1 &
  SPID=$!
  sleep 0.5
  for page in "clients/$MANO?tab=time:m3_card_time" "clients/$AVANT?tab=work:m4_card_work" "time:m3_time" "tasks:m4_tasks" "work/linking:m4_linking"; do
    url="${page%%:*}"; name="${page##*:}"
    google-chrome --headless=new --no-sandbox --disable-gpu --hide-scrollbars --window-size=1440,1800 \
      --screenshot="$SHOTS/$name.png" "http://127.0.0.1:$SPORT/$url" >/dev/null 2>&1 || true
    [[ -s "$SHOTS/$name.png" ]] && echo "  shot: $SHOTS/$name.png" || echo "  shot FAILED: $name"
  done
  post /timer/discard >/dev/null
fi

check "server log has no PHP errors" 0 "$(grep -ci 'fatal\|warning\|deprecated\|notice' "$TMP/server.log" || true)"
[[ -f "$HUBOS_LOG_PATH/app.log" ]] && { echo "  app.log:"; cat "$HUBOS_LOG_PATH/app.log"; }

SRC_SUM_AFTER=$(srcsum)
check "source DB unchanged (sha256)" "$SRC_SUM_BEFORE" "$SRC_SUM_AFTER"
echo "  source sha256: $SRC_SUM_AFTER"

echo "E2E M3/M4: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
