#!/usr/bin/env bash
# Final check for presentation (no PHP required for basic, with PHP for full)
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "=== MusicWave Final Check ==="
echo "Date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "Root: $ROOT"

# 1. Screenshot check
echo "--- FIX-02 screenshot.png ---"
if [ -f "$ROOT/musicwave/screenshot.png" ]; then
  ls -lh "$ROOT/musicwave/screenshot.png"
  # Check dimensions if identify available
  if command -v identify >/dev/null 2>&1; then
    identify "$ROOT/musicwave/screenshot.png"
  fi
else
  echo "MISSING screenshot.png"
fi

# 2. Fonts self-host
echo "--- FIX-04 fonts self-host ---"
ls -lh "$ROOT/musicwave/assets/fonts/" | head -n 20
google_hits="$(grep -r "googleapis\|gstatic" "$ROOT/musicwave" --include="*.php" --include="*.css" --include="*.js" | grep -v "Previously preconnected" | head -n 5 || true)"
if [ -n "$google_hits" ]; then
  echo "$google_hits"
  echo "FAIL: Google fonts found"
else
  echo "PASS: No Google requests"
fi

# 3. ABSPATH guards
echo "--- FIX-01 ABSPATH ---"
if command -v php >/dev/null 2>&1; then
  php "$ROOT/tools/check-abspath.php" || echo "ABSPATH check failed"
else
  echo "PHP not available, checking via grep"
  missing=$(grep -L "ABSPATH" "$ROOT/musicwave/functions.php" || true)
  if [ -n "$missing" ]; then echo "Missing ABSPATH in functions.php"; else echo "functions.php has ABSPATH"; fi
fi

# 4. declare(strict_types) must be first
echo "--- FIX strict_types order (fatal fix) ---"
bad=0
for f in $(find "$ROOT/music-wave-core/src" "$ROOT/music-wave-vip/src" "$ROOT/musicwave/src" -name "*.php" | head -n 300); do
  # Check if file has declare and ABSPATH before it
  if grep -q "declare(strict_types=1);" "$f"; then
    # Get line numbers
    decl_line=$(grep -n "declare(strict_types=1);" "$f" | head -1 | cut -d: -f1)
    abspath_line=$(grep -n "defined.*ABSPATH" "$f" | head -1 | cut -d: -f1)
    if [ -n "$abspath_line" ] && [ "$abspath_line" -lt "$decl_line" ]; then
      echo "FAIL: $f ABSPATH line $abspath_line before declare line $decl_line"
      bad=$((bad+1))
    fi
  fi
done
if [ "$bad" -eq 0 ]; then
  echo "PASS: declare(strict_types) is first in all checked files"
else
  echo "FAIL: $bad files have wrong order"
fi

# 5. error_log without WP_DEBUG
echo "--- FIX-39 error_log ---"
debug_hits="$(grep -rn "error_log\|var_dump\|print_r" "$ROOT/music-wave-core/src" "$ROOT/musicwave" "$ROOT/music-wave-vip/src" --include="*.php" | grep -v "WP_DEBUG\|phpcs:ignore" | head -n 20 || true)"
if [ -n "$debug_hits" ]; then
  echo "$debug_hits"
  echo "Check above - should be only WP_DEBUG guarded"
else
  echo "PASS: No unguarded error_log/var_dump"
fi

# 6. Conditional assets
echo "--- FIX-35 conditional CSS/JS ---"
grep -rn "musicwave_enqueue_conditional_styles\|wp_register_style\|musicwave_defer_scripts" "$ROOT/musicwave/functions.php" "$ROOT/musicwave/src" | head -n 20

# 7. Skip link, focus-visible, shelf nav, combobox, bidi, form aria
echo "--- FIX-28..33 a11y ---"
grep -rn "skip-link\|main-content" "$ROOT/musicwave/functions.php" "$ROOT/musicwave/src" | head -n 10
grep -n "focus-visible" "$ROOT/musicwave/assets/css/base.css" | head -n 5
grep -rn "aria-controls.*viewport\|shelf.*nav" "$ROOT/musicwave/functions.php" "$ROOT/musicwave/src" | head -n 10
grep -n "aria-haspopup" "$ROOT/music-wave-core/assets/catalog-suggest.js" | head -n 5
grep -n "dir=.auto" "$ROOT/music-wave-core/src/Blocks/ReleaseCard.php" | head -n 5
grep -n "unicode-bidi" "$ROOT/musicwave/assets/css/components/shelf.css" | head -n 5
grep -n "role=.alert\|aria-required" "$ROOT/music-wave-core/src/Blocks/RequestFormBlock.php" | head -n 10

# 8. Docs
echo "--- FIX-37..43 docs ---"
ls -lh "$ROOT/docs/user-guide.md" "$ROOT/docs/compatibility-matrix.md" "$ROOT/docs/extension-contracts.md" "$ROOT/docs/final-verification.md" 2>&1 | head -n 20
ls -lh "$ROOT/docs/audits/" 2>&1 | head -n 20

# 9. Package
echo "--- FIX-38 package ---"
grep -n "MANIFEST\|screenshot\|OFL" "$ROOT/tools/package-release.php" | head -n 20

# 10. Uninstall
echo "--- FIX-05 uninstall ---"
grep -n "delete_data_on_uninstall\|mw_library_index\|mw_release_views" "$ROOT/music-wave-core/uninstall.php" | head -n 20

# 11. Tests
echo "--- FIX-42,60 tests ---"
ls -lh "$ROOT/tests/download-e2e.php" "$ROOT/tests/dashboard-vip-test.php" "$ROOT/tools/run-audits.sh" 2>&1

# 12. Run audits if possible
echo "--- FIX-36 run-audits.sh (if tools available) ---"
if [ -x "$ROOT/tools/run-audits.sh" ]; then
  echo "Script exists and executable"
  # Don't run full audits without wp-env, just show help
  head -n 20 "$ROOT/tools/run-audits.sh"
fi

echo "=== Final Check Done ==="
echo "If any FAIL above, fix before presentation."
echo "For full gates with PHP, run:"
echo "  php tools/check-syntax.php"
echo "  php tools/check-abspath.php"
echo "  composer check:phpcs"
echo "  composer check:phpstan"
echo "  ./tools/run-audits.sh --url=http://localhost:8888"
