#!/usr/bin/env bash
# M3/M4 E2E (timer, hours bank, tasks, needs-linking, recurring) against a COPY
# of the DB (or synthetic fixture in CI). Zero real client names in this script.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-$ROOT/storage/hubos.sqlite}"
TMP="$(mktemp -d)"
PORT="${PORT:-8098}"
BASE="http://127.0.0.1:${PORT}"
JAR="$TMP/cookies.txt"
PID=""

HAS_SRC=0
SRC_SUM_BEFORE=""
if [[ -f "$SRC" ]]; then
  HAS_SRC=1
  srcsum() { { cat "$SRC"; [[ -f "$SRC-wal" ]] && cat "$SRC-wal"; true; } | sha256sum | cut -d' ' -f1; }
  SRC_SUM_BEFORE=$(srcsum)
  cp "$SRC" "$TMP/copy.sqlite"
  [[ -f "$SRC-wal" ]] && cp "$SRC-wal" "$TMP/copy.sqlite-wal"
fi

export HUBOS_DB_PATH="$TMP/copy.sqlite"
export HUBOS_SESSION_PATH="$TMP/sessions"
export HUBOS_LOG_PATH="$TMP/logs"
export HUBOS_COOKIE_SECURE=0
export HUBOS_REGISTRY_URL="http://127.0.0.1:9/registry?resource_id=x"
mkdir -p "$HUBOS_SESSION_PATH" "$HUBOS_LOG_PATH"

php "$ROOT/bin/migrate.php" >/dev/null
php "$ROOT/bin/import_d1.php" "$ROOT/tests/fixtures/d1_mini.sql" >/dev/null

cleanup() {
  if [[ -n "$PID" ]]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
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

PW="zz-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
CHILD_ID=$(q "SELECT id FROM clients WHERE legacy_id='gi_zz_child'")
PARENT_ID=$(q "SELECT parent_client_id FROM clients WHERE id=$CHILD_ID")
TENANT=$(q "SELECT tenant_id FROM clients WHERE id=$CHILD_ID")
BANK=$(q "SELECT id FROM hours_banks WHERE client_id=$CHILD_ID AND status='active' ORDER BY id LIMIT 1")

UNLINKED_TASK=$(HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_admin_m3@hubos.test", "ZZ_Admin", "admin", $argv[2]);
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_viewer_m3@hubos.test", "ZZ_Viewer", "client_viewer", $argv[2], null, (int)$argv[3]);
$t = new HubOS\Core\Tenant($pdo, (int)$argv[1]);
echo $t->insert("tasks", ["title" => "ZZ_Task for zz-parent.test", "client_id" => null]);
' "$TENANT" "$PW" "$CHILD_ID")

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

login() {
  curl -s -c "$2" -b "$2" -o "$TMP/login.html" "$BASE/login"
  local tok; tok=$(csrf_from "$TMP/login.html")
  curl -s -c "$2" -b "$2" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$tok" --data-urlencode "email=$1" --data-urlencode "password=$PW" "$BASE/login"
}
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/$2" -w '%{http_code}' "$BASE$1"; }
post() { local url="$1"; shift; curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w '%{http_code}' --data-urlencode "_csrf=$TOKEN" "$@" "$BASE$url"; }

check "admin login" 303 "$(login zz_admin_m3@hubos.test "$JAR")"
check "GET /clients/$CHILD_ID" 200 "$(get "/clients/$CHILD_ID" child.html)"
TOKEN=$(csrf_from "$TMP/child.html")
check "card shows start-timer form" 1 "$(grep -c 'id="client-timer-form"' "$TMP/child.html")"
R0=$(bank_remaining "$TENANT" "$BANK")

# 1. Start timer
check "POST /timer/start client=$CHILD_ID" 303 "$(post /timer/start --data-urlencode "client_id=$CHILD_ID" --data-urlencode "_return=/clients/$CHILD_ID")"
check "active_timers row for admin" 1 "$(q "SELECT COUNT(*) FROM active_timers a JOIN users u ON u.id=a.user_id WHERE u.email='zz_admin_m3@hubos.test' AND a.client_id=$CHILD_ID")"
sleep 2

# 2. Reload -> still running
check "reload /dashboard -> 200" 200 "$(get /dashboard dash.html)"
check "header widget shows running timer" 1 "$(grep -c 'id="timer-elapsed"[^>]*data-running="1"' "$TMP/dash.html")"
get /timer timer.json >/dev/null
check "GET /timer JSON active+running" 1 "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["active"] && $d["running"] && $d["elapsed"]>=1 && $d["client_id"]==(int)$argv[2]);' "$TMP/timer.json" "$CHILD_ID")"

# 3. Stop with description
check "POST /timer/stop with description" 303 "$(post /timer/stop --data-urlencode "description=ZZ_E2E תיקון באתר" --data-urlencode "_return=/clients/$CHILD_ID?tab=time")"
check "entry created, linked to bank, source timer" "1" "$(q "SELECT COUNT(*) FROM time_entries WHERE description='ZZ_E2E תיקון באתר' AND client_id=$CHILD_ID AND hours_bank_id=$BANK AND source='timer'")"
R1=$(bank_remaining "$TENANT" "$BANK")
check "bank remaining decreased (timer)" 1 "$(php -r 'echo (int)((float)$argv[2] < (float)$argv[1]);' "$R0" "$R1")"

# 4. Manual entry (0:30)
check "manual entry from card" 303 "$(post /time/entries --data-urlencode "client_id=$CHILD_ID" --data-urlencode "duration=0:30" --data-urlencode "description=ZZ_E2E ידני" --data-urlencode "_return=/clients/$CHILD_ID?tab=time")"
R2=$(bank_remaining "$TENANT" "$BANK")
check "bank remaining decreased by 0.5h" 1 "$(php -r 'echo (int)(abs(((float)$argv[1]-(float)$argv[2]) - 0.5) < 0.0001);' "$R1" "$R2")"

# 5. Task requires client
T0=$(q "SELECT COUNT(*) FROM tasks")
check "POST /tasks without client -> 422" 422 "$(post /tasks --data-urlencode "title=ZZ_E2E task no client")"
check "no task created" "$T0" "$(q "SELECT COUNT(*) FROM tasks")"
check "POST /tasks with client -> 303" 303 "$(post /tasks --data-urlencode "title=ZZ_E2E task" --data-urlencode "client_id=$CHILD_ID" --data-urlencode "priority=high")"

# 6. Needs-linking queue
check "GET /work/linking" 200 "$(get /work/linking linking.html)"
check "POST link task $UNLINKED_TASK -> $PARENT_ID" 303 "$(post "/work/linking/task/$UNLINKED_TASK" --data-urlencode "client_id=$PARENT_ID")"
check "task linked" "$PARENT_ID" "$(q "SELECT client_id FROM tasks WHERE id=$UNLINKED_TASK")"
check "relinking an already linked task -> 409" 409 "$(post "/work/linking/task/$UNLINKED_TASK" --data-urlencode "client_id=$CHILD_ID")"

# 7. Recurring templates + CLI idempotency
check "POST /work/recurring" 303 "$(post /work/recurring --data-urlencode "client_id=$CHILD_ID" --data-urlencode "title=ZZ_E2E גיבוי חודשי" --data-urlencode "day_of_month=3")"
R_A=$(php "$ROOT/bin/recurring.php" --month=2026-11 | head -n1)
R_B=$(php "$ROOT/bin/recurring.php" --month=2026-11 | head -n1)
check "recurring CLI idempotent" "created=1 created=0" "$(grep -o 'created=[0-9]*' <<<"$R_A") $(grep -o 'created=[0-9]*' <<<"$R_B")"

# 8. Viewer isolation
VJAR="$TMP/viewer.txt"
check "viewer login" 303 "$(login zz_viewer_m3@hubos.test "$VJAR")"
check "viewer GET /tasks" 200 "$(curl -s -b "$VJAR" -c "$VJAR" -o "$TMP/v_tasks.html" -w '%{http_code}' "$BASE/tasks")"
check "viewer does not see internal ZZ_ task" 0 "$(grep -c 'ZZ_E2E task' "$TMP/v_tasks.html" || true)"
VT=$(csrf_from "$TMP/v_tasks.html")
check "viewer cannot start timer" 403 "$(curl -s -b "$VJAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$VT" --data-urlencode "client_id=$CHILD_ID" "$BASE/timer/start")"

if [[ "$HAS_SRC" -eq 1 ]]; then
  SRC_SUM_AFTER=$(srcsum)
  check "source DB unchanged (sha256)" "$SRC_SUM_BEFORE" "$SRC_SUM_AFTER"
fi

echo "E2E M3/M4: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
