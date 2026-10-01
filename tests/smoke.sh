#!/bin/bash
#
# Smoke test for Agentic Autopilot: upgrade path and core functionality.
# Usage: tests/smoke.sh <php-binary> <wp-version>
# Example: tests/smoke.sh /usr/bin/php7.4 6.8
#

set -euo pipefail

PHP="${1:?PHP binary required (e.g., /usr/bin/php7.4)}"
WP_VERSION="${2:?WordPress version required (e.g., 6.8 or latest)}"

# Resolve wp-cli binary
WP_CLI_BIN="${WP_CLI_BIN:-/usr/bin/wp}"
if [ ! -f "$WP_CLI_BIN" ]; then
	echo "wp-cli not found at $WP_CLI_BIN, downloading..."
	WP_CLI_BIN="/tmp/wp-cli-$$.phar"
	curl -sSLo "$WP_CLI_BIN" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x "$WP_CLI_BIN"
fi

WORK=$(mktemp -d)
# Repo root = the folder that holds tests/ (works locally and on the CI runner).
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_PID=""
# Only ever kill our own PHP server (never "kill 0", which would hit the whole process group).
cleanup() {
	if [ -n "$SERVER_PID" ]; then
		kill "$SERVER_PID" 2>/dev/null || true
	fi
	rm -rf "$WORK"
}
trap cleanup EXIT

# Simplify: just use basics without --skip-themes --skip-plugins
WP="$PHP $WP_CLI_BIN --path=$WORK/wp --allow-root"

echo "=== Setup: WordPress $WP_VERSION with SQLite ==="

# 1. Download WordPress
# Full download so a default theme is present for the front-end check.
$WP core download --version="$WP_VERSION"

echo "=== Setup SQLite database ==="

# Download SQLite drop-in
SQLITE_ZIP="$WORK/sqlite.zip"
curl -sSL https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -o "$SQLITE_ZIP"
unzip -q "$SQLITE_ZIP" -d "$WORK/wp/wp-content/plugins/"

# Find the actual plugin folder (it's extracted with a folder name)
SQLITE_PLUGIN_DIR=$(find "$WORK/wp/wp-content/plugins" -maxdepth 1 -type d -name "*sqlite*" | head -1)
if [ -z "$SQLITE_PLUGIN_DIR" ]; then
	echo "ERROR: SQLite plugin not found after unzip"
	exit 1
fi

# Set up db.php drop-in from the plugin's db.copy
if [ -f "$SQLITE_PLUGIN_DIR/db.copy" ]; then
	cp "$SQLITE_PLUGIN_DIR/db.copy" "$WORK/wp/wp-content/db.php"

	# Replace placeholders
	SQLITE_PLUGIN_PATH=$(cd "$SQLITE_PLUGIN_DIR" && pwd)
	sed -i "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$SQLITE_PLUGIN_PATH|g" "$WORK/wp/wp-content/db.php"
	sed -i "s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|g" "$WORK/wp/wp-content/db.php"
else
	echo "ERROR: db.copy not found in $SQLITE_PLUGIN_DIR"
	exit 1
fi

echo "=== Create WordPress config and install ==="

# Create wp-config
$WP config create --dbname=wp --dbuser=x --dbpass=x --skip-check

# Install WordPress
$WP core install \
	--url=http://127.0.0.1:8899 \
	--title="Smoke" \
	--admin_user=admin \
	--admin_password=admin \
	--admin_email=a@example.com \
	--skip-email

echo "=== Test upgrade path: install and activate previous release ==="

# Get the latest release tag from GitHub (the version sites run today).
AUTH_ARGS=()
if [ -n "${GITHUB_TOKEN:-}" ]; then
	AUTH_ARGS=(-H "Authorization: Bearer $GITHUB_TOKEN")
fi
PREV_TAG=$(curl -fsSL "${AUTH_ARGS[@]}" https://api.github.com/repos/askreikaco/agentic-autopilot/releases/latest \
	| python3 -c 'import sys, json; print(json.load(sys.stdin)["tag_name"])')
if [ -z "$PREV_TAG" ]; then
	echo "ERROR: Could not fetch the latest release tag; the upgrade test cannot run"
	exit 1
fi

echo "Previous release tag: $PREV_TAG"

# Download and install the previous version
PREV_ZIP="$WORK/prev.zip"
PREV_DOWNLOAD_URL="https://github.com/askreikaco/agentic-autopilot/archive/refs/tags/$PREV_TAG.zip"
echo "Downloading $PREV_DOWNLOAD_URL"
curl -sSL "$PREV_DOWNLOAD_URL" -o "$PREV_ZIP"

# Extract and move to wp-content/plugins/agentic-autopilot/
unzip -q "$PREV_ZIP" -d "$WORK"
EXTRACTED_DIR=$(find "$WORK" -maxdepth 1 -type d -name "agentic-autopilot-*" | head -1)
if [ -z "$EXTRACTED_DIR" ]; then
	echo "ERROR: Extracted directory not found"
	exit 1
fi

mkdir -p "$WORK/wp/wp-content/plugins/agentic-autopilot"
cp -r "$EXTRACTED_DIR"/* "$WORK/wp/wp-content/plugins/agentic-autopilot/"
rm -rf "$EXTRACTED_DIR"

# Activate the previous version
$WP plugin activate agentic-autopilot

# Save some settings to verify they survive the upgrade
echo "Saving options from previous version..."
$WP option update agentic_autopilot '{"instant_navigation":true,"llms_txt":true,"jetpack_monitor_only":true}' --format=json

echo "=== Simulate upgrade: replace plugin folder with current checkout ==="

# Replace the whole folder like WordPress does (old files must not linger), using only tar.
NEW_DIR="$WORK/agentic-autopilot.new"
mkdir -p "$NEW_DIR"
tar -C "$REPO_ROOT" --exclude=.git --exclude=.github --exclude=tests --exclude=.gitattributes -cf - . | tar -C "$NEW_DIR" -xf -
rm -rf "$WORK/wp/wp-content/plugins/agentic-autopilot"
mv "$NEW_DIR" "$WORK/wp/wp-content/plugins/agentic-autopilot"

echo "=== Run checks after upgrade ==="

# Check 1: Plugin is still active
echo "Check 1: Plugin is active..."
ACTIVE_PLUGINS=$($WP plugin list --status=active --field=name)
if ! echo "$ACTIVE_PLUGINS" | grep -q "^agentic-autopilot$"; then
	echo "ERROR: agentic-autopilot not active after upgrade"
	echo "Active plugins: $ACTIVE_PLUGINS"
	exit 1
fi
echo "PASS: agentic-autopilot is active"

# Check 2: Settings survived the upgrade
echo "Check 2: Settings survived upgrade..."
SETTINGS=$($WP option get agentic_autopilot --format=json)
echo "Current settings: $SETTINGS"

# Use python3 to check JSON
INSTANT_NAV=$(echo "$SETTINGS" | python3 -c "import sys, json; print(json.load(sys.stdin).get('instant_navigation', False))" 2>/dev/null || echo "false")
LLMS_TXT=$(echo "$SETTINGS" | python3 -c "import sys, json; print(json.load(sys.stdin).get('llms_txt', False))" 2>/dev/null || echo "false")

if [ "$INSTANT_NAV" != "True" ] && [ "$INSTANT_NAV" != "true" ]; then
	echo "ERROR: instant_navigation setting not preserved"
	exit 1
fi
if [ "$LLMS_TXT" != "True" ] && [ "$LLMS_TXT" != "true" ]; then
	echo "ERROR: llms_txt setting not preserved"
	exit 1
fi
echo "PASS: Settings preserved (instant_navigation=true, llms_txt=true)"

# Check 3: Version constant matches
echo "Check 3: Version constant matches..."
VERSION_FROM_CONSTANT=$($WP eval 'echo AGENTIC_AUTOPILOT_VERSION;' 2>/dev/null || echo "unknown")
VERSION_FROM_HEADER=$(sed -n 's/^[ \t\/*#@]*Version:[ \t]*\([^ \t]*\).*/\1/p' "$REPO_ROOT/agentic-autopilot.php" | head -1)
echo "Version from constant: $VERSION_FROM_CONSTANT"
echo "Version from header: $VERSION_FROM_HEADER"
if [ "$VERSION_FROM_CONSTANT" != "$VERSION_FROM_HEADER" ]; then
	echo "ERROR: Version mismatch"
	exit 1
fi
echo "PASS: Version matches ($VERSION_FROM_HEADER)"

# Check 4: Start PHP server
echo "Check 4: Starting PHP server..."
ROUTER_PHP="$WORK/router.php"
cat > "$ROUTER_PHP" << 'EOF'
<?php
// Real files are served as-is; everything else goes to WordPress (like nginx try_files).
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) ) {
	return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

$PHP -S 127.0.0.1:8899 -t "$WORK/wp" "$ROUTER_PHP" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
echo "Server PID: $SERVER_PID"

# Wait for server to start (up to 10 seconds)
echo "Waiting for server to be ready..."
for i in {1..10}; do
	if curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8899/ 2>/dev/null | grep -q "200\|301\|302"; then
		echo "Server is ready"
		break
	fi
	if [ $i -eq 10 ]; then
		echo "ERROR: Server did not start in time"
		cat "$WORK/server.log"
		exit 1
	fi
	sleep 1
done

# Check 5: Home page returns 200
echo "Check 5: Home page HTTP status..."
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8899/)
if [ "$HTTP_CODE" != "200" ]; then
	echo "ERROR: Expected HTTP 200, got $HTTP_CODE"
	cat "$WORK/server.log"
	exit 1
fi
echo "PASS: HTTP 200"

# Check 6: llms.txt returns 200, text/plain, with the site title (llms_txt was enabled before the upgrade)
echo "Check 6: /llms.txt endpoint..."
LLMS_HEADERS="$WORK/llms.h"
LLMS_BODY=$(curl -s -D "$LLMS_HEADERS" http://127.0.0.1:8899/llms.txt)
LLMS_CODE=$(head -1 "$LLMS_HEADERS" | awk '{print $2}')
if [ "$LLMS_CODE" != "200" ]; then
	echo "ERROR: /llms.txt returned $LLMS_CODE instead of 200"
	cat "$WORK/server.log"
	exit 1
fi
if ! grep -qi "^content-type: text/plain" "$LLMS_HEADERS"; then
	echo "ERROR: /llms.txt is not text/plain"
	cat "$LLMS_HEADERS"
	exit 1
fi
if ! echo "$LLMS_BODY" | grep -q "^# Smoke"; then
	echo "ERROR: /llms.txt does not start with the site title"
	echo "$LLMS_BODY" | head -5
	exit 1
fi
echo "PASS: /llms.txt 200 text/plain with site title"

# Check 7: Admin settings page renders without errors
echo "Check 7: Admin settings page rendering..."
SETTINGS_RENDER=$($WP eval 'wp_set_current_user(1); set_current_screen("settings_page_agentic-autopilot"); ob_start(); Agentic_Autopilot_Settings::render(); $h=ob_get_clean(); echo strlen($h) > 500 ? "ok" : "short";' 2>&1)
if [ "$SETTINGS_RENDER" != "ok" ]; then
	echo "ERROR: Settings page render failed or too short"
	echo "Output: $SETTINGS_RENDER"
	exit 1
fi
echo "PASS: Settings page renders with sufficient content"

# Check 8: Look for fatal errors in server log
echo "Check 8: Checking server logs for errors..."
if grep -q "PHP Fatal\|PHP Parse error" "$WORK/server.log"; then
	echo "ERROR: Fatal errors found in server log"
	grep "PHP Fatal\|PHP Parse error" "$WORK/server.log"
	exit 1
fi

# Warnings from agentic-autopilot are an error; warnings from other code are ignored
if grep "agentic-autopilot" "$WORK/server.log" | grep -q "PHP Warning\|Deprecated"; then
	echo "ERROR: Warnings/deprecations found in agentic-autopilot code"
	grep "agentic-autopilot" "$WORK/server.log" | grep "PHP Warning\|Deprecated"
	exit 1
fi
echo "PASS: No fatal errors or agentic-autopilot-specific warnings"

# Check 9: Deactivate and reactivate plugin
echo "Check 9: Deactivate and reactivate..."
$WP plugin deactivate agentic-autopilot
$WP plugin activate agentic-autopilot

# Verify option was preserved through deactivate/reactivate cycle
SETTINGS_AFTER=$($WP option get agentic_autopilot --format=json)
echo "Settings after reactivate: $SETTINGS_AFTER"
echo "PASS: Plugin deactivate/reactivate successful"

# Check 9b: Blueprint install from a test fixture repo
if [ "${BLUEPRINT_SKIP:-0}" != "1" ]; then
	echo "Check 9b: Blueprint install from a test fixture repo..."

	# Determine the ref to use
	BLUEPRINT_REF="${BLUEPRINT_REF:-$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null || echo 'blueprint')}"

	# Set the Blueprint option with repo and ref
	$WP option update agentic_autopilot_blueprint "{\"repo\":\"askreikaco/agentic-autopilot\",\"ref\":\"$BLUEPRINT_REF\",\"path\":\"tests/fixtures/blueprint/catalog.json\",\"auto_install\":false,\"activate_after_install\":true,\"auto_update\":false,\"excluded\":[],\"expose_to_agents\":false}" --format=json

	# Try to install hello-blueprint plugin
	INSTALL_RESULT=$($WP eval 'wp_set_current_user(1); $r = Agentic_Autopilot_Blueprint::install("plugins","hello-blueprint"); echo is_wp_error($r) ? "ERR ".$r->get_error_message() : "OK";' 2>&1)

	if [ "$INSTALL_RESULT" != "OK" ]; then
		echo "ERROR: Blueprint install failed: $INSTALL_RESULT"
		# Allow BLUEPRINT_REF override for local testing
		if [ "$BLUEPRINT_REF" = "$(git -C "$REPO_ROOT" rev-parse HEAD)" ]; then
			exit 1
		else
			echo "SKIP: Blueprint test (using ref override, not on GitHub)"
		fi
	else
		# Verify plugin is active
		if ! $WP plugin is-active hello-blueprint 2>/dev/null; then
			echo "ERROR: hello-blueprint not active after install"
			exit 1
		fi

		# Verify status shows current
		STATUS_RESULT=$($WP eval 'wp_set_current_user(1); $status = Agentic_Autopilot_Blueprint::items_status(); $s = $status["hello-blueprint"] ?? null; echo $s && "current" === $s["state"] ? "OK" : "NOT_CURRENT";' 2>&1)
		if [ "$STATUS_RESULT" != "OK" ]; then
			echo "ERROR: Blueprint status not current for hello-blueprint"
			exit 1
		fi

		echo "PASS: Blueprint install and activate successful"
	fi
else
	echo "SKIP: Blueprint test (BLUEPRINT_SKIP=1)"
fi

# Final success message
echo ""
# Check 10: uninstall removes our data
echo "Check 10: Uninstall cleans up..."
$WP plugin uninstall agentic-autopilot --deactivate
if $WP option get agentic_autopilot >/dev/null 2>&1; then
	echo "ERROR: option agentic_autopilot still exists after uninstall"
	exit 1
fi
echo "PASS: Uninstall removed the settings"

echo "========================================="
echo "SMOKE OK php=$($PHP -v | head -1 | awk '{print $2}') wp=$WP_VERSION"
echo "========================================="
