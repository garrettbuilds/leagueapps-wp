<?php
/**
 * Plugin Name:       LeagueApps for WordPress
 * Plugin URI:        https://github.com/garrettbuilds/leagueapps-wp
 * Description:       Publish LeagueApps Teams and Divisions on your own site. Read-only, one-way, and it never touches member contact details.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leagueapps-wp
 *
 * Terminology follows LeagueApps exactly: Site, Program, Division, Team,
 * Registration, Season. Where this plugin adds a concept - Event - it says so
 * and explains why.
 *
 * Read docs/DIVISIONS.md before changing anything about divisions. How a Site
 * organises them is the single thing that varies most between installs, and
 * assuming one model is the mistake this plugin was rewritten to stop making.
 */

defined( 'ABSPATH' ) || exit;

define( 'LAWP_VERSION', '0.2.0' );
define( 'LAWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAWP_FILE', __FILE__ );

/**
 * Configuration lives in wp-config.php, never in the database.
 *
 *   define( 'LAWP_CLIENT_ID', '...' );   the Private API Key NAME from your console
 *   define( 'LAWP_CERT_PATH', '/opt/.../leagueapps.p12' );
 *
 * The .p12 belongs outside the web root, mode 0600, owned by the user the
 * WP-CLI job runs as - which is usually NOT the user PHP-FPM runs as. Never in
 * wp-content, never in a repository.
 */

/*
 * A four-line autoloader instead of Composer's.
 *
 * Composer is a development dependency here: it runs the tests. Shipping
 * vendor/ so the plugin can boot would mean committing a few hundred files to
 * load six, and a plugin that fails fatally because somebody's deploy skipped
 * vendor/ is a bad trade for an autoloader this small. The PSR-4 layout is the
 * same either way, so tests and production resolve identical files.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'LeagueAppsWP\\' ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class, strlen( 'LeagueAppsWP\\' ) ) );
		$path     = LAWP_DIR . 'src/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// The raw HTTP layer predates the namespaced code and stays procedural on
// purpose: it is the part most likely to be read by somebody debugging a 401.
require_once LAWP_DIR . 'includes/class-lawp-auth.php';
require_once LAWP_DIR . 'includes/class-lawp-reader.php';

register_activation_hook( __FILE__, array( 'LeagueAppsWP\\Infrastructure\\Schema', 'install' ) );

/*
 * There is deliberately no deactivation hook.
 *
 * Deactivating a plugin is how people test a theory about an unrelated bug. It
 * is not consent to delete anything, so nothing here treats it as such. Data
 * removal happens only on delete, and only if it was switched on first - see
 * uninstall.php.
 */

add_action(
	'admin_init',
	static function (): void {
		/*
		 * admin_init rather than plugins_loaded. dbDelta runs SHOW TABLES and
		 * SHOW COLUMNS against every table in the statement; doing that on every
		 * front-end request, for every visitor, to discover that nothing has
		 * changed is a cost with no benefit. Schema changes arrive with a deploy,
		 * and a deploy is followed by somebody loading wp-admin.
		 */
		\LeagueAppsWP\Infrastructure\Schema::maybe_upgrade();
	}
);

add_action( 'init', array( 'LeagueAppsWP\\Presentation\\TeamsBlock', 'register' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LAWP_DIR . 'cli/commands.php';
}
