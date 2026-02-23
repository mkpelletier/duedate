#!/bin/bash
# Deploy quizaccess_duedate plugin to local MAMP Moodle test environment.
#
# Usage:
#   ./deploy.sh          Deploy, auto-detect version change
#   ./deploy.sh --upgrade Force upgrade even if version unchanged
#   ./deploy.sh --purge   Force purge caches only (no upgrade)

set -euo pipefail

PLUGIN_SRC="$(cd "$(dirname "$0")" && pwd)"
MOODLE_ROOT="/Applications/MAMP/htdocs/moodle"
PLUGIN_DEST="$MOODLE_ROOT/mod/quiz/accessrule/duedate"
PHP="/Applications/MAMP/bin/php/php/bin/php"

# Fall back to system PHP if MAMP PHP not found.
if [ ! -x "$PHP" ]; then
    PHP=$(which php)
fi

# Parse arguments.
FORCE_UPGRADE=false
FORCE_PURGE=false
for arg in "$@"; do
    case "$arg" in
        --upgrade) FORCE_UPGRADE=true ;;
        --purge)   FORCE_PURGE=true ;;
        *)         echo "Unknown option: $arg"; exit 1 ;;
    esac
done

# Check Moodle root exists.
if [ ! -f "$MOODLE_ROOT/config.php" ]; then
    echo "Error: Moodle not found at $MOODLE_ROOT"
    exit 1
fi

# Extract version number from version.php using grep (avoids needing Moodle constants).
extract_version() {
    sed -n 's/.*\$plugin->version[[:space:]]*=[[:space:]]*\([0-9]*\).*/\1/p' "$1" 2>/dev/null || echo ""
}

OLD_VERSION=""
if [ -f "$PLUGIN_DEST/version.php" ]; then
    OLD_VERSION=$(extract_version "$PLUGIN_DEST/version.php")
fi

NEW_VERSION=$(extract_version "$PLUGIN_SRC/version.php")

echo "Deploying quizaccess_duedate to $PLUGIN_DEST"
echo "  Source version:    ${NEW_VERSION:-unknown}"
echo "  Installed version: ${OLD_VERSION:-not installed}"

# Sync files (delete anything in dest that's not in source).
rsync -a --delete \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='deploy.sh' \
    "$PLUGIN_SRC/" "$PLUGIN_DEST/"

echo "  Files synced."

# Determine whether to upgrade or purge.
if [ "$FORCE_UPGRADE" = true ]; then
    echo "  Running upgrade (--upgrade flag)..."
    "$PHP" "$MOODLE_ROOT/admin/cli/upgrade.php" --non-interactive
elif [ "$FORCE_PURGE" = true ]; then
    echo "  Purging caches (--purge flag)..."
    "$PHP" "$MOODLE_ROOT/admin/cli/purge_caches.php"
elif [ "$OLD_VERSION" != "$NEW_VERSION" ]; then
    echo "  Version changed ($OLD_VERSION -> $NEW_VERSION). Running upgrade..."
    "$PHP" "$MOODLE_ROOT/admin/cli/upgrade.php" --non-interactive
else
    echo "  Version unchanged. Purging caches..."
    "$PHP" "$MOODLE_ROOT/admin/cli/purge_caches.php"
fi

echo "Done."
