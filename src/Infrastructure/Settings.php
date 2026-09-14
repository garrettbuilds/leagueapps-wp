<?php
/**
 * Plugin settings and configured events.
 *
 * Credentials are NOT here. They live in wp-config.php constants, outside the
 * database and outside the admin UI, so an exported database does not carry them
 * and a compromised admin account cannot read them back out.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LeagueAppsWP\Domain\DivisionMap;
use LeagueAppsWP\Domain\EventConfig;

final class Settings {

	public const OPTION_SETTINGS = 'lawp_settings';
	public const OPTION_EVENTS   = 'lawp_events';
	public const OPTION_GEN      = 'lawp_data_generation';

	public static function defaults(): array {
		return array(
			/*
			 * WHAT HAPPENS TO YOUR DATA, and the two rules behind it.
			 *
			 * 1. Deactivating this plugin never deletes anything. People
			 *    deactivate plugins to test a theory about an unrelated bug, and
			 *    a plugin that treats that as consent to delete a season of
			 *    configuration is a plugin nobody can safely troubleshoot.
			 *
			 * 2. Deleting the plugin removes nothing either, unless this is
			 *    switched on first. Off is the default because the common reason
			 *    to delete is to reinstall or move hosts, and a stale table costs
			 *    a few kilobytes while a deleted one costs an afternoon of
			 *    remapping divisions.
			 */
			'delete_data_on_uninstall' => false,
			'manual_sync_cooldown'     => 60,
			'lock_ttl'                 => 900,
		);
	}

	public static function all(): array {
		return array_merge( self::defaults(), (array) get_option( self::OPTION_SETTINGS, array() ) );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	public static function sanitize( array $input ): array {
		$clean = self::defaults();

		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		// Floors, not suggestions. A one-second cooldown is a way for an
		// impatient click to become a rate-limit ban for the whole site.
		$clean['manual_sync_cooldown'] = max( 60, (int) ( $input['manual_sync_cooldown'] ?? 60 ) );
		$clean['lock_ttl']             = max( 300, (int) ( $input['lock_ttl'] ?? 900 ) );

		return $clean;
	}

	/** @return array<string,array> Raw stored event configuration. */
	public static function events(): array {
		return (array) get_option( self::OPTION_EVENTS, array() );
	}

	public static function event( string $event_key ): ?EventConfig {
		$stored = self::events()[ $event_key ] ?? null;

		if ( null === $stored ) {
			return null;
		}

		return new EventConfig(
			event_key: $event_key,
			site_id: (int) ( $stored['site_id'] ?? 0 ),
			divisions: new DivisionMap( (array) ( $stored['divisions'] ?? array() ) ),
			program_ids: array_map( 'intval', (array) ( $stored['program_ids'] ?? array() ) ),
			program_filter: (string) ( $stored['program_filter'] ?? '' ),
			display_title: (string) ( $stored['display_title'] ?? '' ),
			page_id: isset( $stored['page_id'] ) ? (int) $stored['page_id'] : null,
			unknown_division_policy: (string) ( $stored['unknown_division_policy'] ?? EventConfig::HOLD_FOR_REVIEW ),
			max_deactivation_count: (int) ( $stored['max_deactivation_count'] ?? 10 ),
			max_deactivation_percent: (int) ( $stored['max_deactivation_percent'] ?? 20 ),
			min_roster: (int) ( $stored['min_roster'] ?? 1 ),
			show_roster_count: (bool) ( $stored['show_roster_count'] ?? false ),
			show_captain: (bool) ( $stored['show_captain'] ?? false ),
			timezone: (string) ( $stored['timezone'] ?? wp_timezone_string() ),
		);
	}

	public static function save_event( string $event_key, array $config ): void {
		$events               = self::events();
		$events[ $event_key ] = $config;
		update_option( self::OPTION_EVENTS, $events, false );
	}

	/**
	 * The cache generation for one event.
	 *
	 * Bumped only when a sync changes something a visitor can see. Every view
	 * cache key carries it, so an old key becomes unreachable rather than
	 * needing to be found and deleted. Deletes can fail silently on one node of
	 * a multi-server install; a generation cannot.
	 */
	public static function generation( string $event_key ): int {
		$all = (array) get_option( self::OPTION_GEN, array() );
		return (int) ( $all[ $event_key ] ?? 1 );
	}

	public static function bump_generation( string $event_key ): int {
		$all               = (array) get_option( self::OPTION_GEN, array() );
		$next              = self::generation( $event_key ) + 1;
		$all[ $event_key ] = $next;
		update_option( self::OPTION_GEN, $all, false );

		return $next;
	}
}
