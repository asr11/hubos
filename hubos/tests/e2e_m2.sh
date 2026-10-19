#!/usr/bin/env bash
# M2 E2E (Client 360) against a COPY of a real DB. The source DB is never
# modified (sha256 checked before/after). A ZZ_ admin + ZZ_ viewer are created
# in the copy only. Registry URL points at a dead local port, so no network.
#   bash tests/e2e_m2.sh [source.sqlite]     (default: storage/hubos.sqlite)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-$ROOT/storage/hubos.sqlite}"
TMP="$(mktemp -d)"
PORT="${PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
JAR="$TMP/cookies.txt"
PID=""

[[ -f "$SRC" ]] || { echo "source DB not found: $SRC"; exit 2; }
srcsum() { { cat "$SRC"; [[ -f "$SRC-wal" ]] && cat "$SRC-wal"; true; } | sha256sum | cut -d' ' -f1; }
SRC_SUM_BEFORE=$(srcsum)
# Plain file copy (never open the source with SQLite: even a read could checkpoint WAL).
cp "$SRC" "$TMP/copy.sqlite"
[[ -f "$SRC-wal" ]] && cp "$SRC-wal" "$TMP/copy.sqlite-wal"

export HUBOS_DB_PATH="$TMP/copy.sqlite"
export HUBOS_SESSION_PATH="$TMP/sessions"
export HUBOS_LOG_PATH="$TMP/logs"
export HUBOS_COOKIE_SECURE=0
export HUBOS_REGISTRY_URL="http://127.0.0.1:9/registry?resource_id=x"
mkdir -p "$HUBOS_SESSION_PATH"

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
MANO=$(q "SELECT id FROM clients WHERE legacy_id='gi_mano_123'")
AVANT=$(q "SELECT parent_client_id FROM clients WHERE legacy_id='gi_mano_123'")
AVANT_NAME=$(q "SELECT name FROM clients WHERE id=$AVANT")
AVANT_DOCS=$(q "SELECT COUNT(*) FROM finance_documents WHERE client_id=$AVANT")
TENANT=$(q "SELECT tenant_id FROM clients WHERE id=$MANO")
echo "  copy: mano=$MANO parent=$AVANT ($AVANT_NAME) parent_docs=$AVANT_DOCS tenant=$TENANT"

HUBOS_APP_ROOT="$ROOT" php -r '
require getenv("HUBOS_APP_ROOT")."/app/bootstrap.php";
$pdo = HubOS\Core\DB::connect(getenv("HUBOS_DB_PATH"));
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_admin_m2@hubos.test", "ZZ_Admin", "admin", $argv[2]);
HubOS\Core\Provisioning::createUser($pdo, (int)$argv[1], "zz_viewer_m2@hubos.test", "ZZ_Viewer", "client_viewer", $argv[2], null, (int)$argv[3]);
' "$TENANT" "$PW" "$MANO"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

login() { # email jar
  curl -s -c "$2" -b "$2" -o "$TMP/login.html" "$BASE/login"
  local tok; tok=$(csrf_from "$TMP/login.html")
  curl -s -c "$2" -b "$2" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$tok" --data-urlencode "email=$1" --data-urlencode "password=$PW" "$BASE/login"
}
check "admin login" 303 "$(login zz_admin_m2@hubos.test "$JAR")"

# 1. Mano card
code=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/mano.html" -w '%{http_code}' "$BASE/clients/$MANO")
check "GET /clients/$MANO (gi_mano_123)" 200 "$code"
check "Mano card shows parent link to $AVANT_NAME" 1 "$(grep -c "class=\"family-link family-parent\" href=\"/clients/$AVANT\"" "$TMP/mano.html")"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/mano_fin.html" "$BASE/clients/$MANO?tab=finance"
ROLLED=$(grep -c "class=\"origin origin-family\" href=\"/clients/$AVANT\"" "$TMP/mano_fin.html" || true)
check "Mano finance tab rolls up $AVANT_NAME docs (labeled)" "$AVANT_DOCS" "$ROLLED"

# 2. Avantgarde card
curl -s -b "$JAR" -c "$JAR" -o "$TMP/av_fin.html" "$BASE/clients/$AVANT?tab=finance"
AVDOCS=$(grep -c 'class="finance-doc"' "$TMP/av_fin.html" || true)
check "Avantgarde card finance docs >= 13" 1 "$([[ "$AVDOCS" -ge 13 ]] && echo 1 || echo 0)"
echo "  avantgarde finance rows: $AVDOCS"
check "Avantgarde card shows child link to Mano" 1 "$(grep -c "class=\"family-link family-child\" href=\"/clients/$MANO\"" "$TMP/av_fin.html")"

# 3. Search
curl -s -b "$JAR" -c "$JAR" -o "$TMP/search.html" -G --data-urlencode "q=מנו" "$BASE/clients"
check "search 'מנו' finds מנו ספנות (linked)" 1 "$(grep -c "href=\"/clients/$MANO\">קבוצת מנו ספנות<" "$TMP/search.html")"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/search2.html" -G --data-urlencode "q=למנו" "$BASE/clients"
check "search 'למנו' (prefix ל) finds מנו ספנות" 1 "$(grep -c "href=\"/clients/$MANO\"" "$TMP/search2.html")"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/all.html" "$BASE/clients"
check "every client name in /clients is a card link" "$(q 'SELECT COUNT(*) FROM clients WHERE tenant_id='"$TENANT")" "$(grep -c 'class="client-link" href="/clients/' "$TMP/all.html")"

# 4. Tabs + access logging
EV0=$(q "SELECT COUNT(*) FROM events WHERE action='vault.metadata_viewed'")
for t in overview contacts interactions work time finance collection sales assets access timeline; do
  code=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/tab_$t.html" -w '%{http_code}' "$BASE/clients/$MANO?tab=$t")
  [[ "$code" == 200 ]] || echo "  tab $t -> $code"
done
check "all 11 tabs return 200" 11 "$(for t in overview contacts interactions work time finance collection sales assets access timeline; do grep -l "id=\"panel-$t\"" "$TMP/tab_$t.html"; done | wc -l | tr -d ' ')"
check "tab nav shows 11 tabs with counts" 11 "$(sed -n '/id="client-tabs"/,/<\/nav>/p' "$TMP/tab_overview.html" | grep -o '<span class="badge">[0-9]*</span></a>' | wc -l | tr -d ' ')"
check "access tab view logged (vault.metadata_viewed)" $((EV0+1)) "$(q "SELECT COUNT(*) FROM events WHERE action='vault.metadata_viewed'")"
check "collection tab states balance not reliable" 1 "$(grep -c 'id="collection-caveat"' "$TMP/tab_collection.html")"

# 5. No vault secret strings in any HTML (cards of every client with vault items, all tabs)
for cid in $(php -r '$p=new PDO("sqlite:".getenv("HUBOS_DB_PATH")); echo implode(" ", $p->query("SELECT DISTINCT client_id FROM vault_items WHERE client_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));'); do
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/v_${cid}_access.html" "$BASE/clients/$cid?tab=access"
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/v_${cid}_overview.html" "$BASE/clients/$cid"
done
LEAKS=$(php -r '
$p = new PDO("sqlite:".getenv("HUBOS_DB_PATH"));
$needles = []; $public = 0;
$sql = "SELECT v.notes, v.secret_ciphertext, v.data_json, v.title, v.service, v.url, v.username,
               c.name, c.email, c.phone, c.domain, c.city, c.tax_id
          FROM vault_items v LEFT JOIN clients c ON c.tenant_id = v.tenant_id AND c.id = v.client_id";
foreach ($p->query($sql) as $r) {
    $meta = implode("\n", array_map("strval", [$r["title"], $r["service"], $r["url"], $r["username"], $r["name"], $r["email"], $r["phone"], $r["domain"], $r["city"], $r["tax_id"]]));
    foreach ([$r["notes"], $r["secret_ciphertext"], json_decode((string)$r["data_json"], true)["notes"] ?? null] as $v) {
        if (!is_string($v) || strlen(trim($v)) < 6) { continue; }
        $v = trim($v);
        // A note that is literally a public field (e.g. the client domain) is not a secret.
        if (str_contains($meta, $v)) { $public++; continue; }
        $needles[] = $v; $needles[] = htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, "UTF-8");
    }
}
$needles = array_unique($needles);
$hits = 0; $files = glob($argv[1]."/*.html");
foreach ($files as $f) { $h = file_get_contents($f); foreach ($needles as $n) { if (str_contains($h, $n)) { $hits++; } } }
fwrite(STDERR, "  secret needles=".count($needles)." (excluded $public notes equal to public client/item fields) html files=".count($files)."\n");
echo $hits;' "$TMP")
check "no vault secret strings in any HTML" 0 "$LEAKS"

# 6. Enrichment: network failure is graceful (dead local port)
TOKEN=$(csrf_from "$TMP/mano.html")
out=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$TOKEN" "$BASE/clients/$AVANT/enrich")
check "POST enrich (registry down) redirects back" "303 $BASE/clients/$AVANT#registry" "$out"
curl -s -b "$JAR" -c "$JAR" -o "$TMP/av_err.html" "$BASE/clients/$AVANT"
check "card shows friendly registry error" 1 "$(grep -c 'id="registry-error"' "$TMP/av_err.html")"
check "nothing applied (no registry event)" 0 "$(q "SELECT COUNT(*) FROM events WHERE action='client.registry_applied'")"
check "POST enrich without CSRF" 403 "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/clients/$AVANT/enrich")"

# 7. Viewer restriction
VJAR="$TMP/viewer.txt"
check "viewer login" 303 "$(login zz_viewer_m2@hubos.test "$VJAR")"
check "viewer opens own client (Mano)" 200 "$(curl -s -b "$VJAR" -c "$VJAR" -o "$TMP/viewer_mano.html" -w '%{http_code}' "$BASE/clients/$MANO")"
check "viewer blocked from parent" 403 "$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' "$BASE/clients/$AVANT")"
check "viewer card has no family roll-up links" 0 "$(grep -c 'origin-family\|family-link' "$TMP/viewer_mano.html" || true)"
check "viewer has no access tab" 0 "$(grep -c 'id="tab-access"' "$TMP/viewer_mano.html" || true)"
check "viewer cannot enrich" 403 "$(curl -s -b "$VJAR" -c "$VJAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$(csrf_from "$TMP/viewer_mano.html")" "$BASE/clients/$MANO/enrich")"

# 8. Misc
check "unknown client 404" 404 "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/clients/999999")"
check "logged-out card redirects to login" 303 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/clients/$MANO")"
check "server log has no PHP errors" 0 "$(grep -ci 'fatal\|warning\|deprecated\|notice' "$TMP/server.log" || true)"
[[ -f "$HUBOS_LOG_PATH/app.log" ]] && { echo "  app.log:"; cat "$HUBOS_LOG_PATH/app.log"; }

SRC_SUM_AFTER=$(srcsum)
check "source DB unchanged (sha256)" "$SRC_SUM_BEFORE" "$SRC_SUM_AFTER"

echo "E2E M2: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
