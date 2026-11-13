#!/usr/bin/env bash
#
# Stand up a throwaway WordPress for the integration suite.
#
# Runs on SQLite, so it needs no database server. WP-CLI drives WordPress
# directly, so it needs no web server either.
#
#   ./bin/setup-test-wp.sh [target-dir]
#   cd <target-dir> && wp eval-file /path/to/tests/integration/run.php
#
set -euo pipefail

TARGET="${1:-${TMPDIR:-/tmp}/rycm-consent-test-wp}"
WP_VERSION="${WP_VERSION:-7.1}"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "Target:  $TARGET"
echo "Plugin:  $PLUGIN_DIR"

mkdir -p "$TARGET"
cd "$TARGET"

# WordPress core from the GitHub mirror rather than wordpress.org, which is not
# reachable from every build environment.
if [ ! -d core ]; then
	git clone --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git core
fi

# The SQLite integration lives in a monorepo, and its wp-includes/database is a
# symlink into a sibling package. Copy with -L or the drop-in dies on a missing
# version.php with a fatal that says nothing useful.
if [ ! -d sqlite-src ]; then
	git clone --depth 1 https://github.com/WordPress/sqlite-database-integration.git sqlite-src
fi
rm -rf core/wp-content/plugins/sqlite-database-integration
cp -rL sqlite-src/packages/plugin-sqlite-database-integration \
	core/wp-content/plugins/sqlite-database-integration

mkdir -p core/wp-content/database
sed -e "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$TARGET/core/wp-content/plugins/sqlite-database-integration|g" \
	-e "s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|g" \
	core/wp-content/plugins/sqlite-database-integration/db.copy > core/wp-content/db.php

if [ ! -f wp-cli.phar ]; then
	curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x wp-cli.phar
fi

# DB_ENGINE is what tells the drop-in to engage. Without it the drop-in bails
# early and WordPress tries to reach a MySQL server that is not there.
cat > core/wp-config.php <<'CFG'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'rycm-test-auth-key' );
define( 'SECURE_AUTH_KEY', 'rycm-test-secure-auth' );
define( 'LOGGED_IN_KEY', 'rycm-test-logged-in' );
define( 'NONCE_KEY', 'rycm-test-nonce' );
define( 'AUTH_SALT', 'rycm-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'rycm-test-secure-salt' );
define( 'LOGGED_IN_SALT', 'rycm-test-logged-in-salt' );
define( 'NONCE_SALT', 'rycm-test-nonce-salt' );
define( 'DB_ENGINE', 'sqlite' );
$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
// Lets tests/integration/run.php create and delete content. Throwaway installs only.
define( 'RYCM_CONSENT_TEST_SITE', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG

cd core
WP="php -d error_reporting=24575 $TARGET/wp-cli.phar --allow-root"

if ! $WP core is-installed >/dev/null 2>&1; then
	$WP core install --url=http://localhost:8899 --title="RYCM Consent Test" \
		--admin_user=admin --admin_password=testpass123 \
		--admin_email=test@example.com --skip-email
fi

# Pretty permalinks, as on real sites.
$WP rewrite structure '/%postname%/' >/dev/null
$WP option update timezone_string 'Europe/London' >/dev/null
# wp-cli guesses a /core subfolder from the install path; the test server serves core/ as the web root.
$WP option update siteurl http://localhost:8899 >/dev/null
$WP option update home http://localhost:8899 >/dev/null

ln -sfn "$PLUGIN_DIR" wp-content/plugins/rycm-consent
$WP plugin activate rycm-consent
$WP rewrite flush >/dev/null

echo
echo "Ready. Run the integration suite with:"
echo "  cd $TARGET/core && php $TARGET/wp-cli.phar --allow-root eval-file $PLUGIN_DIR/tests/integration/run.php"

# Front-end requests: php's built-in server needs a router so pretty permalinks reach index.php.
cat > "$TARGET/core/router.php" <<'ROUTER'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( __DIR__ . $path ) && ! str_ends_with( $path, '.php' ) ) {
	return false;
}
if ( is_file( __DIR__ . $path ) && str_ends_with( $path, '.php' ) ) {
	require __DIR__ . $path;
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
ROUTER
echo "Serve it with:  cd $TARGET/core && php -S localhost:8899 router.php"
