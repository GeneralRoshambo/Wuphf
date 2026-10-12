#!/usr/bin/env bash
# Deploy Wuphf to a cPanel account over the UAPI (Fileman::upload_files).
# Needs only an API token. Never uploads data/, avatars, or config.local.php,
# so a deploy cannot touch your database, photos, or server-specific settings.
#
#   CPANEL_HOST    server hostname, e.g. server123.example.com (or wuphf.lol if it serves cPanel)
#   CPANEL_USER    cPanel username
#   CPANEL_TOKEN   API token (cPanel > Manage API Tokens)
#   DEPLOY_DIR     web root relative to the home directory (default: public_html)
#   SITE_URL       optional, e.g. https://wuphf.lol, checked after the upload
#   DRY_RUN=1      list what would be uploaded
#   CPANEL_INSECURE=1   accept a self-signed cPanel certificate
#   CPANEL_SCHEME / CPANEL_PORT   default https / 2083
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -z "${CPANEL_TOKEN:-}" ] && [ "${ALLOW_SKIP:-}" = 1 ]; then echo "No CPANEL_TOKEN set; skipping deploy."; exit 0; fi
: "${CPANEL_HOST:?set CPANEL_HOST}" "${CPANEL_USER:?set CPANEL_USER}" "${CPANEL_TOKEN:?set CPANEL_TOKEN}"
DEPLOY_DIR="${DEPLOY_DIR:-public_html}"; DEPLOY_DIR="${DEPLOY_DIR%/}"
BASE="${CPANEL_SCHEME:-https}://${CPANEL_HOST}:${CPANEL_PORT:-2083}"
CURL=(curl -sS --max-time 90 -H "Authorization: cpanel ${CPANEL_USER}:${CPANEL_TOKEN}")
[ "${CPANEL_INSECURE:-}" = 1 ] && CURL+=(-k)

# Stage the files to ship (tracked files only, minus dev-only and per-server ones).
# Written for the bash 3.2 and BSD tools that ship with macOS as well as GNU/Linux.
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
REV="$(git rev-parse --short HEAD 2>/dev/null || date +%s)"
git ls-files | while IFS= read -r f; do
  case "$f" in
    README.md|.gitignore|.cpanel.yml|tools/*|.github/*|data/*.sqlite|avatars/*.jpg|avatars/*.gif|config.local.php) continue ;;
  esac
  mkdir -p "$STAGE/$(dirname "$f")"; cp "$f" "$STAGE/$f"
done
# Give the service worker a per-deploy cache name so installed copies refresh.
sed "s/^const V = '.*';/const V = 'wuphf-$REV';/" "$STAGE/sw.js" > "$STAGE/sw.js.tmp" && mv "$STAGE/sw.js.tmp" "$STAGE/sw.js"

cd "$STAGE"
fail=0
for d in $(find . -type f | sed 's|/[^/]*$||' | sort -u); do
  rel="${d#./}"; [ "$d" = . ] && rel=""
  target="$DEPLOY_DIR${rel:+/$rel}"
  args=(-F "dir=$target" -F "overwrite=1"); n=0; names=""
  for f in $(find "$d" -maxdepth 1 -type f | sort); do
    n=$((n+1)); args+=(-F "file-$n=@$f"); names="$names ${f##*/}"
  done
  if [ "${DRY_RUN:-}" = 1 ]; then echo "[dry run] $target:$names"; continue; fi
  out="$("${CURL[@]}" "${args[@]}" "$BASE/execute/Fileman/upload_files")" || { echo "request failed for $target"; fail=1; continue; }
  if echo "$out" | grep -q '"status":1'; then echo "uploaded $n file(s) to $target"
  else echo "cPanel rejected $target: $(echo "$out" | head -c 400)"; fail=1; fi
done
[ "$fail" = 0 ] || { echo "Deploy finished with errors."; exit 1; }
[ "${DRY_RUN:-}" = 1 ] && exit 0

if [ -n "${SITE_URL:-}" ]; then
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "$SITE_URL/" || true)"
  [ "$code" = 200 ] && echo "Live check OK ($SITE_URL, revision $REV)" || { echo "Live check failed: HTTP $code from $SITE_URL"; exit 1; }
fi
echo "Deployed revision $REV."
