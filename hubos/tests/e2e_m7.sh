#!/usr/bin/env bash
# M7 E2E:
# 1. Data Provenance Banner & Verified Source Timestamp + SHA-256 + POST /settings/sync-d1
# 2. Digital-CEO Agent API (/api/agent/status, /api/agent/morning, /api/agent/clients,
#    /api/agent/clients/{id}, POST /api/agent/event) with Bearer token auth & zero vault secret leakage.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
PORT="${PORT:-8098}"
BASE="http://127.0.0.1:${PORT}"
JAR="$TMP/cookies.txt"
PID=""

export HUBOS_DB_PATH="$TMP/m7.sqlite"
export HUBOS_SESSION_PATH="$TMP/sessions"
export HUBOS_LOG_PATH="$TMP/logs"
export HUBOS_COOKIE_SECURE=0
export HUBOS_VAULT_KEY="0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
export HUBOS_AGENT_TOKEN="zz_m7_agent_bearer_token_9876543210abcdef"
mkdir -p "$HUBOS_SESSION_PATH" "$HUBOS_LOG_PATH"

cleanup() {
  if [[ -n "$PID" ]]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
  rm -rf "$TMP"
  echo "cleanup: server stopped, temp dir removed ($( [[ -e "$TMP" ]] && echo FAIL || echo ok ))"
}
trap cleanup EXIT

PASS=0; FAIL=0
check() { if [[ "$2" == "$3" ]]; then PASS=$((PASS+1)); echo "  PASS  $1 -> $3"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 -> expected $2, got $3"; fi; }
csrf_from() { grep -o 'name="_csrf" value="[0-9a-f]*"' "$1" | head -n1 | sed 's/.*value="//; s/"$//'; }
q() { php -r '$p=new PDO("sqlite:".getenv("HUBOS_DB_PATH")); echo $p->query($argv[1])->fetchColumn();' "$1"; }

php "$ROOT/bin/migrate.php" >/dev/null
php "$ROOT/bin/import_d1.php" "$ROOT/tests/fixtures/d1_mini.sql" >/dev/null

PW="zz-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
CHILD_ID=$(q "SELECT id FROM clients WHERE legacy_id='gi_zz_child'")
TENANT=$(q "SELECT tenant_id FROM clients WHERE id=$CHILD_ID")
VAULT_SECRET="ZZ_UltraSecret_M7_NeverLeak_999!"

HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
$uid = HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_owner_m7@hubos.test", "ZZ_Owner", "owner", $argv[2]);
$t = new HubOS\Core\Tenant($pdo, (int)$argv[1]);
$p = new HubOS\Core\Permissions($t);
$v = HubOS\Core\Vault::fromKeyString(getenv("HUBOS_VAULT_KEY"));
$user = ["id" => $uid, "tenant_id" => (int)$argv[1], "role" => "owner", "status" => "active"];
(new HubOS\Modules\Vault\VaultService($t, $p, $v))->create($user, [
    "client_id" => $argv[3],
    "title"     => "ZZ_M7_Vault_Item",
    "username"  => "zz_root",
    "secret"    => $argv[4],
]);
' "$TENANT" "$PW" "$CHILD_ID" "$VAULT_SECRET"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

# ------------------------------------------------------------------ 1. Digital-CEO Agent API
check "GET /api/agent/morning without token -> 401" 401 "$(curl -s -o "$TMP/unauth.json" -w '%{http_code}' "$BASE/api/agent/morning")"
check "401 response is JSON ok=false" 1 "$(grep -c '"ok":false' "$TMP/unauth.json")"

check "GET /api/agent/morning with wrong token -> 401" 401 "$(curl -s -H 'Authorization: Bearer wrong_token_1234567890' -o /dev/null -w '%{http_code}' "$BASE/api/agent/morning")"

check "GET /api/agent/status with valid token -> 200" 200 "$(curl -s -H "Authorization: Bearer $HUBOS_AGENT_TOKEN" -o "$TMP/status.json" -w '%{http_code}' "$BASE/api/agent/status")"
check "status JSON has version m7" 1 "$(grep -c '"version":"m7"' "$TMP/status.json")"

check "GET /api/agent/morning with valid token -> 200" 200 "$(curl -s -H "Authorization: Bearer $HUBOS_AGENT_TOKEN" -o "$TMP/morning.json" -w '%{http_code}' "$BASE/api/agent/morning")"
check "morning JSON has money & provenance" 1 "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["ok"]===true && isset($d["money"]["income_total"]) && isset($d["provenance"]["source_sha256"]));' "$TMP/morning.json")"

check "GET /api/agent/clients?q=Child -> 200" 200 "$(curl -s -H "Authorization: Bearer $HUBOS_AGENT_TOKEN" -o "$TMP/clients.json" -w '%{http_code}' "$BASE/api/agent/clients?q=Child")"
check "clients search finds ZZ_Child Co" 1 "$(grep -c 'ZZ_Child Co' "$TMP/clients.json")"

check "GET /api/agent/clients/$CHILD_ID -> 200" 200 "$(curl -s -H "Authorization: Bearer $HUBOS_AGENT_TOKEN" -o "$TMP/c360.json" -w '%{http_code}' "$BASE/api/agent/clients/$CHILD_ID")"
check "GET /api/agent/client/$CHILD_ID alias -> 200" 200 "$(curl -s -H "Authorization: Bearer $HUBOS_AGENT_TOKEN" -o /dev/null -w '%{http_code}' "$BASE/api/agent/client/$CHILD_ID")"
check "client360 JSON never leaks vault plaintext or ciphertext" 0 "$(grep -c "$VAULT_SECRET\|secret_ciphertext\|data_json" "$TMP/c360.json" || true)"

check "POST /api/agent/event -> 201" 201 "$(curl -s -X POST -H "Authorization: Bearer $HUBOS_AGENT_TOKEN" -H 'Content-Type: application/json' -d "{\"action\":\"agent.telegram_digest\",\"client_id\":$CHILD_ID,\"summary\":\"ZZ_Telegram digest ok\"}" -o "$TMP/ev.json" -w '%{http_code}' "$BASE/api/agent/event")"
check "agent event recorded in events table" 1 "$(q "SELECT COUNT(*) FROM events WHERE action='agent.telegram_digest' AND client_id=$CHILD_ID")"

# ------------------------------------------------------------------ 2. Data Provenance UI Banner & Sync Now
curl -s -c "$JAR" -b "$JAR" -o "$TMP/login.html" "$BASE/login"
TOK=$(csrf_from "$TMP/login.html")
check "owner login -> 303" 303 "$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$TOK" --data-urlencode "email=zz_owner_m7@hubos.test" --data-urlencode "password=$PW" "$BASE/login")"

curl -s -b "$JAR" -c "$JAR" -o "$TMP/dash.html" "$BASE/dashboard"
check "dashboard shows #provenance-banner" 1 "$(grep -c 'id="provenance-banner"' "$TMP/dash.html")"
check "dashboard shows verified source Cloudflare D1 (hub-os-d1)" 1 "$(grep -c 'Cloudflare D1 (hub-os-d1)' "$TMP/dash.html")"
check "dashboard shows exact timestamp YYYY-MM-DD HH:MM:SS" 1 "$(grep -cE 'id="prov-timestamp">[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}<' "$TMP/dash.html")"

curl -s -b "$JAR" -c "$JAR" -o "$TMP/card.html" "$BASE/clients/$CHILD_ID"
check "Client 360 header shows #client-provenance-banner" 1 "$(grep -c 'id="client-provenance-banner"' "$TMP/card.html")"

SYNC_BEFORE=$(q "SELECT COUNT(*) FROM sync_runs")
DTOK=$(csrf_from "$TMP/dash.html")
check "POST /settings/sync-d1 -> 303" 303 "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$DTOK" --data-urlencode "_return=/dashboard" "$BASE/settings/sync-d1")"
SYNC_AFTER=$(q "SELECT COUNT(*) FROM sync_runs")
check "sync_runs incremented after POST /settings/sync-d1" "$((SYNC_BEFORE + 1))" "$SYNC_AFTER"

echo "E2E M7: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
