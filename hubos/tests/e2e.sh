#!/usr/bin/env bash
# End-to-end smoke test against the PHP built-in server, using a throwaway
# DB + session dir (ZZ_ data). Everything is deleted on exit.
#   bash tests/e2e.sh            (PORT=8099 by default)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
PORT="${PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
JAR="$TMP/cookies.txt"
PID=""

export HUBOS_DB_PATH="$TMP/e2e.sqlite"
export HUBOS_SESSION_PATH="$TMP/sessions"
export HUBOS_LOG_PATH="$TMP/logs"
export HUBOS_COOKIE_SECURE=0

cleanup() {
  if [[ -n "$PID" ]]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
  rm -rf "$TMP"
  echo "cleanup: server stopped, temp dir removed ($( [[ -e "$TMP" ]] && echo FAIL || echo ok ))"
}
trap cleanup EXIT

PASS=0; FAIL=0
check() { # name expected actual
  if [[ "$2" == "$3" ]]; then PASS=$((PASS+1)); echo "  PASS  $1 -> $3"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 -> expected $2, got $3"; fi
}
csrf_from() { grep -o 'name="_csrf" value="[0-9a-f]*"' "$1" | head -n1 | sed 's/.*value="//; s/"$//'; }

PW="ZZ_$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
php "$ROOT/bin/migrate.php"
printf '%s\n' "$PW" | php "$ROOT/bin/create_user.php" --email=zz_owner@hubos.test --name="ZZ_Owner" --tenant=zz-e2e --tenant-name="ZZ_Tenant"

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/login" && break; sleep 0.1; done

# 1. GET /login
code=$(curl -s -c "$JAR" -b "$JAR" -D "$TMP/h1" -o "$TMP/login.html" -w '%{http_code}' "$BASE/login")
check "GET /login" 200 "$code"
TOKEN=$(csrf_from "$TMP/login.html")
check "login page has CSRF token (64 hex)" 64 "${#TOKEN}"
check "CSP header" 1 "$(grep -ci '^content-security-policy:' "$TMP/h1")"
check "X-Frame-Options DENY" 1 "$(grep -ci '^x-frame-options: DENY' "$TMP/h1")"
check "nosniff header" 1 "$(grep -ci '^x-content-type-options: nosniff' "$TMP/h1")"
check "cookie HttpOnly+SameSite=Lax" 1 "$(grep -i '^set-cookie: hubos_sid=' "$TMP/h1" | grep -i 'httponly' | grep -ci 'samesite=lax')"

# 2. POST without CSRF
code=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "email=zz_owner@hubos.test" --data-urlencode "password=$PW" "$BASE/login")
check "POST /login without CSRF" 403 "$code"
code=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=deadbeef" --data-urlencode "email=zz_owner@hubos.test" --data-urlencode "password=$PW" "$BASE/login")
check "POST /login with wrong CSRF" 403 "$code"

# 3. Wrong password
code=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$TOKEN" --data-urlencode "email=zz_owner@hubos.test" --data-urlencode "password=wrong-password-x" "$BASE/login")
check "POST /login wrong password" 401 "$code"

# 4. Correct login
OLD_SID=$(awk '$6=="hubos_sid"{print $7}' "$JAR")
out=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$TOKEN" --data-urlencode "email=zz_owner@hubos.test" --data-urlencode "password=$PW" "$BASE/login")
check "POST /login valid" "303 $BASE/dashboard" "$out"
NEW_SID=$(awk '$6=="hubos_sid"{print $7}' "$JAR")
check "session id regenerated on login" 1 "$([[ -n "$NEW_SID" && "$NEW_SID" != "$OLD_SID" ]] && echo 1 || echo 0)"

# 5. Dashboard
code=$(curl -s -c "$JAR" -b "$JAR" -o "$TMP/dash.html" -w '%{http_code}' "$BASE/dashboard")
check "GET /dashboard (logged in)" 200 "$code"
check "dashboard shows user name" 1 "$(grep -c 'שלום, ZZ_Owner' "$TMP/dash.html")"
TOKEN=$(csrf_from "$TMP/dash.html")

# 6. Clients
code=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/clients")
check "GET /clients" 200 "$code"
out=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$TOKEN" --data-urlencode "name=ZZ_Client <b>x</b>" "$BASE/clients")
check "POST /clients create" "303 $BASE/clients" "$out"
curl -s -c "$JAR" -b "$JAR" -o "$TMP/clients.html" "$BASE/clients"
# scoped to the clients table (the M3 header timer also lists client names, escaped)
check "client listed + escaped" 1 "$(sed -n '/id="clients-table"/,/<\/table>/p' "$TMP/clients.html" | grep -c 'ZZ_Client &lt;b&gt;x&lt;/b&gt;')"
check "client name never rendered raw" 0 "$(grep -c 'ZZ_Client <b>' "$TMP/clients.html" || true)"
code=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "name=ZZ_NoToken" "$BASE/clients")
check "POST /clients without CSRF" 403 "$code"

# 7. Misc
check "GET /assets/app.css" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/app.css")"
check "GET /nope" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/nope")"
check "GET /logout (POST only)" 405 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/logout")"
check "storage not web-reachable" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/../storage/.gitkeep" --path-as-is)"

# 8. Logout
out=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$TOKEN" "$BASE/logout")
check "POST /logout" "303 $BASE/login" "$out"
out=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/dashboard")
check "GET /dashboard after logout" "303 $BASE/login" "$out"

# 9. Events written
EVENTS=$(php -r '$p=new PDO("sqlite:".getenv("HUBOS_DB_PATH")); echo implode(",", $p->query("SELECT action FROM events ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));')
echo "  events: $EVENTS"
check "events log has login, client.created, logout" 1 "$([[ "$EVENTS" == *auth.login*client.created*auth.logout* ]] && echo 1 || echo 0)"

echo "E2E: ${PASS} passed, ${FAIL} failed"
[[ "$FAIL" -eq 0 ]]
