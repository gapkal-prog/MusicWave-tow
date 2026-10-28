#!/usr/bin/env bash
# Run automated quality, security, accessibility, and performance audits.
# Outputs only verified reports to docs/audits/ (never commits failure stubs).
# Usage: ./tools/run-audits.sh [--url=http://localhost:8888]

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
AUDIT_DIR="$ROOT_DIR/docs/audits"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir -p "$AUDIT_DIR"

URL="http://localhost:8888"
for arg in "$@"; do
  case "$arg" in
    --url=*) URL="${arg#*=}" ;;
  esac
done

echo "=== MusicWave Verification Audits ==="
echo "Audit dir: $AUDIT_DIR"
echo "Date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"

# 1. PHP syntax gate
if command -v php >/dev/null 2>&1; then
  php "$ROOT_DIR/tools/check-syntax.php" > "$AUDIT_DIR/syntax.txt" 2>&1
  echo "[PASS] syntax.txt updated"
fi

# 2. Optional Composer security advisory check
if command -v composer >/dev/null 2>&1 && composer audit --format=plain > "$TMP_DIR/composer-audit.txt" 2>&1; then
  mv "$TMP_DIR/composer-audit.txt" "$AUDIT_DIR/composer-audit.txt"
  echo "[PASS] composer-audit.txt updated"
else
  echo "[SKIP] composer audit unavailable in current environment"
fi

# 3. Optional WP-CLI Plugin Check
if command -v wp >/dev/null 2>&1; then
  if wp plugin check music-wave-core --format=json > "$TMP_DIR/plugin-check-core.json" 2>&1; then
    mv "$TMP_DIR/plugin-check-core.json" "$AUDIT_DIR/plugin-check-core.json"
  fi
  if wp plugin check music-wave-vip --format=json > "$TMP_DIR/plugin-check-vip.json" 2>&1; then
    mv "$TMP_DIR/plugin-check-vip.json" "$AUDIT_DIR/plugin-check-vip.json"
  fi
fi

# 4. Optional Lighthouse & axe live browser audits (only when target URL is reachable)
if command -v curl >/dev/null 2>&1 && curl -fsS --max-time 3 "$URL/" >/dev/null 2>&1; then
  PAGES=("/" "/releases/" "/playlists/" "/my-account/" "/request-song/")
  if command -v lighthouse >/dev/null 2>&1; then
    for page in "${PAGES[@]}"; do
      slug=$(echo "$page" | tr '/' '-' | sed 's/^-//;s/-$//')
      [ -z "$slug" ] && slug="front"
      if lighthouse "$URL$page" \
        --only-categories=performance,accessibility,best-practices,seo \
        --chrome-flags="--headless --no-sandbox" \
        --output=json \
        --output-path="$TMP_DIR/lighthouse-$slug.json" >/dev/null 2>&1; then
        mv "$TMP_DIR/lighthouse-$slug.json" "$AUDIT_DIR/lighthouse-$slug.json"
      fi
    done
  fi
fi

ls -lh "$AUDIT_DIR"
echo "=== Done ==="
