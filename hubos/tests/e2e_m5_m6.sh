#!/usr/bin/env bash
# M5 & M6 E2E (Assets, Inspector, Encrypted Vault, Accounting 300 Refinement,
# CEO Morning Dashboard, Users/Permissions, and Client Portal) against a COPY
# of storage/hubos.sqlite. Source DB sha256 is verified before/after.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-$ROOT/storage/hubos.sqlite}"
TMP="$(mktemp -d)"
PORT="${PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
JAR_OWNER="$TMP/cookies_owner.txt"
JAR_VIEWER="$TMP/cookies_viewer.txt"
PID=""

[[ -f "$SRC" ]] || { echo "source DB not found: $SRC"; exit 2; }
srcsum() { { cat "$SRC"; [[ -f "$SRC-wal" ]] && cat "$SRC-wal"; true; } | sha256sum | cut -d' ' -f1; }
SRC_SUM_BEFORE=$(srcsum)
cp "$SRC" "$TMP/copy.sqlite"
[[ -f "$SRC-wal" ]] && cp "$SRC-wal" "$TMP/copy.sqlite-wal"

export HUBOS_DB_PATH="$TMP/copy.sqlite"
export HUBOS_SESSION_PATH="$TMP/sessions"
export HUBOS_LOG_PATH="$TMP/logs"
export HUBOS_COOKIE_SECURE=0
export HUBOS_VAULT_KEY="0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
export HUBOS_MOCK_INSPECTOR=1
mkdir -p "$HUBOS_SESSION_PATH" "$HUBOS_LOG_PATH"

php "$ROOT/bin/migrate.php" >/dev/null

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

PW="zz-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
MANO=$(q "SELECT id FROM clients WHERE legacy_id='gi_mano_123'")
OTHER_CLIENT=$(q "SELECT id FROM clients WHERE id != $MANO LIMIT 1")
TENANT=$(q "SELECT tenant_id FROM clients WHERE id=$MANO")

# 1. Verify Accounting Rule Refinement on migrated copy
check "zero 300 docs counted as realized income" 0 "$(q "SELECT COUNT(*) FROM finance_documents WHERE doc_type='300' AND counts_as_income=1")"
OPEN_300=$(q "SELECT COUNT(*) FROM finance_documents WHERE doc_type='300' AND income_reason='proforma_open'")
MATCHED_300=$(q "SELECT COUNT(*) FROM finance_documents WHERE doc_type='300' AND income_reason='proforma_matched'")
TOTAL_300=$(q "SELECT COUNT(*) FROM finance_documents WHERE doc_type='300'")
check "all 300 docs classified as proforma_open or proforma_matched" "$TOTAL_300" "$((OPEN_300 + MATCHED_300))"
echo "  300 docs: total=$TOTAL_300 open=$OPEN_300 matched=$MATCHED_300"

HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_owner_m5@hubos.test", "ZZ_Owner", "owner", $argv[2]);
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_viewer_m5@hubos.test", "ZZ_Viewer", "client_viewer", $argv[2], null, (int)$argv[3]);
' "$TENANT" "$PW" "$MANO"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

login() {
  curl -s -c "$2" -b "$2" -o "$TMP/login.html" "$BASE/login"
  local tok; tok=$(csrf_from "$TMP/login.html")
  curl -s -c "$2" -b "$2" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$tok" --data-urlencode "email=$1" --data-urlencode "password=$PW" "$BASE/login"
}

check "owner login" 303 "$(login zz_owner_m5@hubos.test "$JAR_OWNER")"

# 2. CEO Morning Dashboard (/dashboard)
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/dash.html" -w '%{http_code}' "$BASE/dashboard")
check "GET /dashboard (CEO)" 200 "$code"
check "CEO dashboard has greeting" 1 "$(grep -c 'שלום, ZZ_Owner' "$TMP/dash.html")"
for sec in sec-urgent sec-breaking sec-money sec-team sec-events; do
  check "CEO dashboard has #$sec" 1 "$(grep -c "id=\"$sec\"" "$TMP/dash.html")"
done
check "CEO dashboard shows proforma_open separation" 1 "$(grep -c 'proforma_open' "$TMP/dash.html")"
TOKEN=$(csrf_from "$TMP/dash.html")

# 3. Assets & DNS/SSL/WHOIS Inspection (/assets)
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/assets.html" -w '%{http_code}' "$BASE/assets")
check "GET /assets" 200 "$code"
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$TOKEN" --data-urlencode "client_id=$MANO" --data-urlencode "kind=domain" --data-urlencode "domain=zz-m5-test.co.il" --data-urlencode "expiry_date=2026-11-01" "$BASE/assets")
check "POST /assets create" 303 "$code"
ASSET_ID=$(q "SELECT id FROM assets WHERE domain='zz-m5-test.co.il' ORDER BY id DESC LIMIT 1")
check "asset created in DB" 1 "$([[ -n "$ASSET_ID" && "$ASSET_ID" -gt 0 ]] && echo 1 || echo 0)"

code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$TOKEN" "$BASE/assets/$ASSET_ID/inspect")
check "POST /assets/$ASSET_ID/inspect" 303 "$code"
check "asset.inspected event recorded" 1 "$(q "SELECT COUNT(*) FROM events WHERE action='asset.inspected' AND entity_id=$ASSET_ID")"
check "asset SSL status updated by inspector" "valid" "$(q "SELECT ssl_status FROM assets WHERE id=$ASSET_ID")"

# 4. Encrypted Vault (/vault)
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/vault.html" -w '%{http_code}' "$BASE/vault")
check "GET /vault" 200 "$code"
SECRET_PLAIN="ZZ_TopSecret_Vault_E2E_98765!"
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' \
  --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode "client_id=$MANO" \
  --data-urlencode "asset_id=$ASSET_ID" \
  --data-urlencode "title=ZZ_SSH_Access" \
  --data-urlencode "service=SSH" \
  --data-urlencode "url=ssh://zz-m5-test.co.il" \
  --data-urlencode "username=zz_root" \
  --data-urlencode "secret=$SECRET_PLAIN" \
  "$BASE/vault")
check "POST /vault create encrypted item" 303 "$code"
VAULT_ID=$(q "SELECT id FROM vault_items WHERE title='ZZ_SSH_Access' ORDER BY id DESC LIMIT 1")
check "vault item encryption is aes-256-gcm" "aes-256-gcm" "$(q "SELECT encryption FROM vault_items WHERE id=$VAULT_ID")"

curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/vault_after.html" "$BASE/vault"
curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/vault_show.html" "$BASE/vault/$VAULT_ID"
check "plaintext secret never in GET /vault" 0 "$(grep -c "$SECRET_PLAIN" "$TMP/vault_after.html" || true)"
check "plaintext secret never in GET /vault/$VAULT_ID" 0 "$(grep -c "$SECRET_PLAIN" "$TMP/vault_show.html" || true)"

code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/reveal.json" -w '%{http_code}' \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "_format=json" "$BASE/vault/$VAULT_ID/reveal")
check "POST /vault/$VAULT_ID/reveal" 200 "$code"
check "revealed JSON contains decrypted secret" 1 "$(grep -c "$SECRET_PLAIN" "$TMP/reveal.json")"
check "vault.secret_revealed event recorded" 1 "$(q "SELECT COUNT(*) FROM events WHERE action='vault.secret_revealed' AND entity_id=$VAULT_ID")"
check "events table never contains plaintext secret" 0 "$(q "SELECT COUNT(*) FROM events WHERE after_json LIKE '%$SECRET_PLAIN%'")"

# 5. Users & Permissions (/settings/users)
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o "$TMP/users.html" -w '%{http_code}' "$BASE/settings/users")
check "GET /settings/users" 200 "$code"
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' \
  --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode "name=ZZ_NewStaff" \
  --data-urlencode "email=zz_newstaff@hubos.test" \
  --data-urlencode "role=staff" \
  --data-urlencode "password=zz-staff-password-1234" \
  "$BASE/settings/users")
check "POST /settings/users create user" 303 "$code"
NEW_UID=$(q "SELECT id FROM users WHERE email='zz_newstaff@hubos.test'")
code=$(curl -s -b "$JAR_OWNER" -c "$JAR_OWNER" -o /dev/null -w '%{http_code}' \
  --data-urlencode "_csrf=$TOKEN" \
  --data-urlencode "permission=work.link" \
  --data-urlencode "state=grant" \
  "$BASE/settings/users/$NEW_UID/permissions")
check "POST /settings/users/$NEW_UID/permissions" 303 "$code"
check "permission.granted event recorded" 1 "$(q "SELECT COUNT(*) FROM events WHERE action='permission.granted' AND entity_id=$NEW_UID")"

# 6. Client Portal (client_viewer role)
check "viewer login" 303 "$(login zz_viewer_m5@hubos.test "$JAR_VIEWER")"
code=$(curl -s -b "$JAR_VIEWER" -c "$JAR_VIEWER" -o "$TMP/portal.html" -w '%{http_code}' "$BASE/dashboard")
check "GET /dashboard as client_viewer renders portal" 200 "$code"
check "portal hero rendered" 1 "$(grep -c 'id="client-portal-hero"' "$TMP/portal.html")"
check "portal shows linked client name" 1 "$(grep -c 'id="portal-client-name".*קבוצת מנו ספנות' "$TMP/portal.html")"
check "portal never leaks vault title or secret" 0 "$(grep -c 'ZZ_SSH_Access\|'"$SECRET_PLAIN" "$TMP/portal.html" || true)"
VTOKEN=$(csrf_from "$TMP/portal.html")

check "viewer blocked from GET /vault (403)" 403 "$(curl -s -b "$JAR_VIEWER" -o /dev/null -w '%{http_code}' "$BASE/vault")"
check "viewer blocked from POST /vault/$VAULT_ID/reveal (403)" 403 "$(curl -s -b "$JAR_VIEWER" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$VTOKEN" "$BASE/vault/$VAULT_ID/reveal")"
check "viewer blocked from GET /settings/users (403)" 403 "$(curl -s -b "$JAR_VIEWER" -o /dev/null -w '%{http_code}' "$BASE/settings/users")"
check "viewer blocked from foreign client card (403)" 403 "$(curl -s -b "$JAR_VIEWER" -o /dev/null -w '%{http_code}' "$BASE/clients/$OTHER_CLIENT")"

SRC_SUM_AFTER=$(srcsum)
check "source DB untouched (sha256)" "$SRC_SUM_BEFORE" "$SRC_SUM_AFTER"

echo "E2E M5/M6: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
