#!/bin/bash
# HubOS gated deploy — run LOCALLY from the repo:  bash hubos/bin/deploy.sh
#
# Order (production is touched only after a full PASS on the new code):
#   1. Only committed code (git archive HEAD) — no stray local files.
#   2. Upload to a STAGING tree on the VPS.
#   3. bin/verify.sh runs on STAGING (46 unit tests + 7-persona simulation on a copy of the live DB + health).
#   4. FAIL  -> stop. Production untouched.
#      PASS  -> snapshot current production code -> sync staging -> production (no --delete, storage excluded).
#   5. Post-deploy smoke test. Failure -> automatic rollback from the snapshot.
set -euo pipefail

REMOTE=root@128.140.100.120
PROD=/home/hub-os/htdocs/os.hub.co.il
STAGE=/home/hub-os/staging/os.hub.co.il
RELEASES=/root/hubos_releases
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$REPO"

SHA=$(git rev-parse --short HEAD)
if [ -n "$(git status --porcelain --untracked-files=no -- hubos)" ]; then
    echo "ABORT: uncommitted changes under hubos/ — commit first (only committed code is deployed)."
    exit 1
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
git archive HEAD hubos | tar -x -C "$TMP"
TOP=$(cd "$TMP/hubos" && ls -A | tr '\n' ' ')

echo "== 1/4 lint ($SHA) =="
LINT=$(find "$TMP/hubos" -name '*.php' -print0 | xargs -0 -n1 -P4 php -l 2>&1 | grep -v '^No syntax errors' || true)
[ -z "$LINT" ] || { echo "$LINT"; echo "ABORT: lint errors"; exit 1; }
bash -n "$TMP/hubos/bin/verify.sh"

echo "== 2/4 upload to staging =="
ssh "$REMOTE" "mkdir -p '$STAGE' && chown -R hub-os:hub-os /home/hub-os/staging"
rsync -az --delete --exclude storage/ "$TMP/hubos/" "$REMOTE:$STAGE/"
ssh "$REMOTE" "chown -R hub-os:hub-os '$STAGE'"

echo "== 3/4 verify STAGING code (production untouched) =="
if ! ssh "$REMOTE" "set -o pipefail; HUBOS_SOURCE_DB='$PROD/storage/hubos.sqlite' HUBOS_BACKUP_DIR='$PROD/storage/backups' bash '$STAGE/bin/verify.sh' 2>&1 | grep -vE '^  ✔'"; then
    echo "DEPLOY BLOCKED: verify FAILED on staging. Production was NOT changed."
    exit 1
fi

echo "== 4/4 promote to production =="
ssh "$REMOTE" bash -s -- "$PROD" "$STAGE" "$RELEASES" "$SHA" "$TOP" <<'REMOTE_EOF'
set -euo pipefail
PROD="$1"; STAGE="$2"; RELEASES="$3"; SHA="$4"; TOP="$5"
exec 9>/tmp/hubos_deploy.lock
flock -n 9 || { echo "ABORT: another deploy is running"; exit 1; }

mkdir -p -m 700 "$RELEASES"
TS=$(date +%Y%m%d_%H%M%S)
SNAP="$RELEASES/hubos_code_${TS}_before_${SHA}.tgz"
EXISTING=""
for p in $TOP; do [ -e "$PROD/$p" ] && EXISTING="$EXISTING $p"; done
tar -czf "$SNAP" -C "$PROD" $EXISTING
echo "snapshot=$SNAP ($(du -h "$SNAP" | cut -f1))"
ls -1t "$RELEASES"/hubos_code_*.tgz | tail -n +6 | xargs -r rm -f   # keep last 5

rsync -a --exclude storage/ "$STAGE/" "$PROD/"
chown -R hub-os:hub-os $(for p in $TOP; do echo "$PROD/$p"; done)
su -s /bin/bash hub-os -c "sqlite3 '$PROD/storage/hubos.sqlite' \".backup '$PROD/storage/backups/pre_deploy_${TS}.sqlite'\""
su -s /bin/bash hub-os -c "source /home/hub-os/.hubos_env 2>/dev/null || true; /usr/bin/php '$PROD/bin/migrate.php'"
echo "$SHA $(date -Iseconds)" > "$PROD/REVISION" && chown hub-os:hub-os "$PROD/REVISION"

smoke() {
    local login api integ
    login=$(curl -s -o /dev/null -w '%{http_code}' https://os.hub.co.il/login)
    api=$(curl -s -o /dev/null -w '%{http_code}' https://os.hub.co.il/api/agent/status)
    integ=$(su -s /bin/bash hub-os -c "sqlite3 -readonly '$PROD/storage/hubos.sqlite' 'PRAGMA quick_check;'")
    echo "smoke: login=$login api_unauth=$api db=$integ"
    [ "$login" = "200" ] && [ "$api" = "401" ] && [ "$integ" = "ok" ]
}
sleep 1
if smoke; then
    echo "DEPLOYED $SHA"
else
    echo "SMOKE FAILED — rolling back from $SNAP"
    tar -xzf "$SNAP" -C "$PROD"
    chown -R hub-os:hub-os $(for p in $TOP; do echo "$PROD/$p"; done)
    echo "rolled-back $(date -Iseconds) from $SNAP" > "$PROD/REVISION"
    smoke && echo "ROLLBACK OK" || echo "ROLLBACK SMOKE STILL FAILING — manual check required"
    exit 1
fi
REMOTE_EOF
