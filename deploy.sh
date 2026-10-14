#!/bin/bash
# Deploy quizaccess_duedate to the local ServBay Moodle sites.
#
# Usage:
#   ./deploy.sh [507|53|all] [--upgrade|--purge]
#
#   507        Moodle 5.0.7 (matches production), https://moodle50.local
#   53         Moodle 5.3, https://moodle53.local
#   all        Both (default)
#   --upgrade  Force upgrade even if version unchanged
#   --purge    Force purge caches only (no upgrade)

set -euo pipefail

PLUGIN_SRC="$(cd "$(dirname "$0")" && pwd)"
SITES_ROOT="/Users/mathieu/Sites/moodle"
PHP="$(command -v php)"

# Parse arguments.
TARGETS="507 53"
FORCE_UPGRADE=false
FORCE_PURGE=false
for arg in "$@"; do
    case "$arg" in
        507|53)    TARGETS="$arg" ;;
        all)       TARGETS="507 53" ;;
        --upgrade) FORCE_UPGRADE=true ;;
        --purge)   FORCE_PURGE=true ;;
        *)         echo "Unknown option: $arg"; exit 1 ;;
    esac
done

# Extract version number from version.php using sed (avoids needing Moodle constants).
extract_version() {
    sed -n 's/.*\$plugin->version[[:space:]]*=[[:space:]]*\([0-9]*\).*/\1/p' "$1" 2>/dev/null || echo ""
}

NEW_VERSION=$(extract_version "$PLUGIN_SRC/version.php")

deploy() {
    local moodle_root plugin_dest
    case "$1" in
        507) moodle_root="$SITES_ROOT/prod507"
             plugin_dest="$moodle_root/mod/quiz/accessrule/duedate" ;;
        # Moodle 5.1+ serves plugins from public/; admin/cli stays at the root.
        53)  moodle_root="$SITES_ROOT/dev53"
             plugin_dest="$moodle_root/public/mod/quiz/accessrule/duedate" ;;
    esac

    if [ ! -f "$moodle_root/config.php" ]; then
        echo "Error: Moodle not found at $moodle_root"
        exit 1
    fi

    local old_version=""
    if [ -f "$plugin_dest/version.php" ]; then
        old_version=$(extract_version "$plugin_dest/version.php")
    fi

    echo "Deploying quizaccess_duedate to $plugin_dest"
    echo "  Source version:    ${NEW_VERSION:-unknown}"
    echo "  Installed version: ${old_version:-not installed}"

    # Sync files (delete anything in dest that's not in source).
    mkdir -p "$plugin_dest"
    rsync -a --delete \
        --exclude='.git' \
        --exclude='.gitignore' \
        --exclude='.gitnexus' \
        --exclude='.claude' \
        --exclude='.DS_Store' \
        --exclude='deploy.sh' \
        "$PLUGIN_SRC/" "$plugin_dest/"

    echo "  Files synced."

    # Determine whether to upgrade or purge.
    if [ "$FORCE_UPGRADE" = true ]; then
        echo "  Running upgrade (--upgrade flag)..."
        "$PHP" "$moodle_root/admin/cli/upgrade.php" --non-interactive
    elif [ "$FORCE_PURGE" = true ]; then
        echo "  Purging caches (--purge flag)..."
        "$PHP" "$moodle_root/admin/cli/purge_caches.php"
    elif [ "$old_version" != "$NEW_VERSION" ]; then
        echo "  Version changed (${old_version:-none} -> $NEW_VERSION). Running upgrade..."
        "$PHP" "$moodle_root/admin/cli/upgrade.php" --non-interactive
    else
        echo "  Version unchanged. Purging caches..."
        "$PHP" "$moodle_root/admin/cli/purge_caches.php"
    fi
}

for target in $TARGETS; do
    deploy "$target"
done

echo "Done."
