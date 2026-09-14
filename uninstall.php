<?php
/**
 * Runs when somebody deletes the plugin, and usually does nothing.
 *
 * Deleting a plugin is not the same as asking for your data to be destroyed. The
 * common reasons are reinstalling, moving hosts, or clearing out a plugin list,
 * and in all three the right behaviour is to leave the tables alone so a
 * reinstall picks up where it left off.
 *
 * So this deletes only if somebody turned on "Delete all data when this plugin is
 * deleted" in the settings BEFORE deleting. Off by default. That is the WordPress
 * convention and it is the right one: a stale table costs a few kilobytes, and a
 * deleted one costs an afternoon of remapping divisions.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Infrastructure/Schema.php';
require_once __DIR__ . '/src/Infrastructure/Settings.php';

use LeagueAppsWP\Infrastructure\Schema;
use LeagueAppsWP\Infrastructure\Settings;

$settings = (array) get_option( Settings::OPTION_SETTINGS, array() );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$owned = Schema::owned();

foreach ( $owned['tables'] as $table ) {
	// Table names cannot be parameterised, and these are built from $wpdb->prefix
	// plus a literal, never from input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

foreach ( $owned['options'] as $option ) {
	delete_option( $option );
}

delete_option( Settings::OPTION_SETTINGS );
