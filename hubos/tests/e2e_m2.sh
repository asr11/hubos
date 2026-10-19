#!/usr/bin/env bash
# M2 E2E (Client 360) against a COPY of the DB (or synthetic fixture in CI).
# If a source DB exists, its sha256 is verified unchanged before/after.
# Zero real client names live in this script (synthetic ZZ_ fixtures only).
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

PW="zz-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
CHILD_ID=$(q "SELECT id FROM clients WHERE legacy_id='gi_zz_child'")
PARENT_ID=$(q "SELECT parent_client_id FROM clients WHERE legacy_id='gi_zz_child'")
PARENT_NAME=$(q "SELECT name FROM clients WHERE id=$PARENT_ID")
PARENT_DOCS=$(q "SELECT COUNT(*) FROM finance_documents WHERE client_id=$PARENT_ID")
TENANT=$(q "SELECT tenant_id FROM clients WHERE id=$CHILD_ID")

HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_admin_m2@hubos.test", "ZZ_Admin", "admin", $argv[2]);
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_viewer_m2@hubos.test", "ZZ_Viewer", "client_viewer", $argv[2], null, (int)$argv[3]);
$t = new HubOS\Core\Tenant($pdo, (int)$argv[1]);
$t->update("clients", (int)$argv[3], ["name" => "ZZ_Child קבוצת בטא טכנולוגיות"]);
' "$TENANT" "$PW" "$CHILD_ID"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

login() {
  curl -s -c "$2" -b "$2" -o "$TMP/login.html" "$BASE/login"
  local tok; tok=$(csrf_from "$TMP/login.html")
  curl -s -c "$2" -b "$2" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$tok" --data-urlencode "email=$1" --data-urlencode "password=$PW" "$BASE/login"
}
check "admin login" 303 "$(login zz_admin_m2@hubos.test "$JAR")"

# 1. Child card & family roll-up
code=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/child.html" -w '%{http_code}' "$BASE/clients/$CHILD_ID")
check "GET /clients/$CHILD_ID (gi_zz_child)" 200 "$code"
check "Child card shows parent link to $PARENT_NAME" 1 "$(grep -c "class=\"family-link family-parent\" href=\"/clients/$PARENT_ID\"" "$TMP/child.html")"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/child_fin.html" "$BASE/clients/$CHILD_ID?tab=finance"
ROLLED=$(grep -c "class=\"origin origin-family\" href=\"/clients/$PARENT_ID\"" "$TMP/child_fin.html" || true)
check "Child finance tab rolls up $PARENT_NAME docs (labeled)" "$PARENT_DOCS" "$ROLLED"

# 2. Parent card
curl -s -b "$JAR" -c "$JAR" -o "$TMP/parent_fin.html" "$BASE/clients/$PARENT_ID?tab=finance"
PDOCS=$(grep -c 'class="finance-doc"' "$TMP/parent_fin.html" || true)
check "Parent card finance docs >= 4" 1 "$([[ "$PDOCS" -ge 4 ]] && echo 1 || echo 0)"
check "Parent card shows child link" 1 "$(grep -c "class=\"family-link family-child\" href=\"/clients/$CHILD_ID\"" "$TMP/parent_fin.html")"

# 3. Hebrew Search (with prefix stripping)
curl -s -b "$JAR" -c "$JAR" -o "$TMP/search.html" -G --data-urlencode "q=בטא" "$BASE/clients"
check "search 'בטא' finds ZZ_Child (linked)" 1 "$(grep -c "href=\"/clients/$CHILD_ID\">ZZ_Child קבוצת בטא טכנולוגיות<" "$TMP/search.html")"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/search2.html" -G --data-urlencode "q=לבטא" "$BASE/clients"
check "search 'לבטא' (prefix ל) finds ZZ_Child" 1 "$(grep -c "href=\"/clients/$CHILD_ID\"" "$TMP/search2.html")"

# 4. Tabs + access logging
EV0=$(q "SELECT COUNT(*) FROM events WHERE action='vault.metadata_viewed'")
for t in overview contacts interactions work time finance collection sales assets access timeline; do
  code=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/tab_$t.html" -w '%{http_code}' "$BASE/clients/$CHILD_ID?tab=$t")
  [[ "$code" == 200 ]] || echo "  tab $t -> $code"
done
check "all 11 tabs return 200" 11 "$(for t in overview contacts interactions work time finance collection sales assets access timeline; do grep -l "id=\"panel-$t\"" "$TMP/tab_$t.html"; done | wc -l | tr -d ' ')"
check "tab nav shows 11 tabs with counts" 11 "$(sed -n '/id="client-tabs"/,/<\/nav>/p' "$TMP/tab_overview.html" | grep -o '<span class="badge">[0-9]*</span></a>' | wc -l | tr -d ' ')"
check "access tab view logged (vault.metadata_viewed)" $((EV0+1)) "$(q "SELECT COUNT(*) FROM events WHERE action='vault.metadata_viewed'")"
check "collection tab states balance not reliable" 1 "$(grep -c 'id="collection-caveat"' "$TMP/tab_collection.html")"

# 5. Enrichment: network failure is graceful (dead local port)
TOKEN=$(csrf_from "$TMP/child.html")
out=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$TOKEN" "$BASE/clients/$PARENT_ID/enrich")
check "POST enrich (registry down) redirects back" "303 $BASE/clients/$PARENT_ID#registry" "$out"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/p_err.html" "$BASE/clients/$PARENT_ID"
check "card shows friendly registry error" 1 "$(grep -c 'id="registry-error"' "$TMP/p_err.html")"
check "POST enrich without CSRF" 403 "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/clients/$PARENT_ID/enrich")"

# 6. Viewer restriction
VJAR="$TMP/viewer.txt"
check "viewer login" 303 "$(login zz_viewer_m2@hubos.test "$VJAR")"
check "viewer opens own client" 200 "$(curl -s -b "$VJAR" -c "$VJAR" -o "$TMP/viewer_child.html" -w '%{http_code}' "$BASE/clients/$CHILD_ID")"
check "viewer blocked from parent" 403 "$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' "$BASE/clients/$PARENT_ID")"
check "viewer card has no family roll-up links" 0 "$(grep -c 'origin-family\|family-link' "$TMP/viewer_child.html" || true)"
check "viewer has no access tab" 0 "$(grep -c 'id="tab-access"' "$TMP/viewer_child.html" || true)"

if [[ "$HAS_SRC" -eq 1 ]]; then
  SRC_SUM_AFTER=$(srcsum)
  check "source DB unchanged (sha256)" "$SRC_SUM_BEFORE" "$SRC_SUM_AFTER"
fi

echo "E2E M2: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
