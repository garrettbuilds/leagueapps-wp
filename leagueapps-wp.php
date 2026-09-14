<?php
/**
 * Plugin Name:       LeagueApps for WordPress
 * Plugin URI:        https://github.com/garrettbuilds/leagueapps-wp
 * Description:       Publishes LeagueApps Teams and Divisions on your own site. Read-only, and never touches member contact details.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leagueapps-wp
 *
 * Terminology follows LeagueApps exactly: Site, Program, Division, Team,
 * Registration, Season. Where this plugin adds a concept it says so.
 *
 * Read docs/DIVISIONS.md before changing anything. How a Site organises its
 * Divisions is the one thing that varies most between installs.
 */

defined( 'ABSPATH' ) || exit;

define( 'LAWP_VERSION', '0.1.0' );
define( 'LAWP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Configuration lives in wp-config.php, never in the database.
 *
 *   define( 'LAWP_CLIENT_ID', '...' );   the Private API Key NAME from your LeagueApps console
 *   define( 'LAWP_CERT_PATH', '/opt/.../leagueapps.p12' );
 *   define( 'LAWP_SITE_ID',   1234 );
 *
 * The .p12 belongs outside the web root, mode 0600, owned by the user the
 * WP-CLI job runs as. Never in wp-content, never in a repository.
 */

require_once LAWP_DIR . 'includes/class-lawp-auth.php';
require_once LAWP_DIR . 'includes/class-lawp-reader.php';
require_once LAWP_DIR . 'includes/class-lawp-fields.php';
require_once LAWP_DIR . 'includes/class-lawp-divisions.php';
require_once LAWP_DIR . 'includes/class-lawp-teams.php';
require_once LAWP_DIR . 'includes/class-lawp-discover.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LAWP_DIR . 'cli/commands.php';
}
