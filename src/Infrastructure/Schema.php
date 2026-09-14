<?php
/**
 * Custom tables: the durable, authoritative local snapshot.
 *
 * Posts were considered and rejected. A team is not editorial content: it has an
 * immutable external id, it is rewritten by a machine several times a day, and a
 * few hundred of them in wp_posts means a few thousand rows in wp_postmeta that
 * every other query has to step over.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

final class Schema {

	public const VERSION = '1.1.0';
	public const OPTION  = 'lawp_schema_version';

	public static function teams_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lawp_teams';
	}

	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lawp_sync_runs';
	}

	public static function locks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lawp_locks';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$teams   = self::teams_table();
		$runs    = self::runs_table();
		$locks   = self::locks_table();

		/*
		 * NO BLANK LINES INSIDE THESE STATEMENTS.
		 *
		 * dbDelta splits the field block on newlines and treats each line as a
		 * column or index definition. A blank line becomes an empty definition
		 * and dbDelta emits `ALTER TABLE ... ADD `` (``)` for it, which is a
		 * syntax error. It does this on every run, for every blank line, while
		 * still reporting the install as done. Group columns with comments
		 * outside the string, never with blank lines inside it.
		 */
		$sql_teams = "CREATE TABLE {$teams} (			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key varchar(100) NOT NULL,
			source_team_id bigint(20) unsigned NOT NULL,
			program_id bigint(20) unsigned NULL,
			program_name varchar(255) NOT NULL DEFAULT '',
			team_name_source varchar(255) NOT NULL,
			display_name_override varchar(255) NOT NULL DEFAULT '',
			division_key varchar(64) NULL,
			division_label varchar(190) NULL,
			division_source varchar(190) NOT NULL DEFAULT '',
			roster_count int(10) unsigned NOT NULL DEFAULT 0,
			captain varchar(190) NOT NULL DEFAULT '',
			location varchar(190) NOT NULL DEFAULT '',
			visible_hash char(64) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_team (event_key,source_team_id),
			KEY division (event_key,division_key,is_active),
			KEY active (event_key,is_active)) {$collate};";

		$sql_runs = "CREATE TABLE {$runs} (			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(64) NOT NULL,
			event_key varchar(100) NOT NULL,
			mode varchar(20) NOT NULL DEFAULT 'dry_run',
			status varchar(20) NOT NULL,
			source_hash varchar(80) NOT NULL DEFAULT '',
			rows_read int(10) unsigned NOT NULL DEFAULT 0,
			pages_read int(10) unsigned NOT NULL DEFAULT 0,
			source_complete tinyint(1) NOT NULL DEFAULT 0,
			creates int(10) unsigned NOT NULL DEFAULT 0,
			updates int(10) unsigned NOT NULL DEFAULT 0,
			renames int(10) unsigned NOT NULL DEFAULT 0,
			moves int(10) unsigned NOT NULL DEFAULT 0,
			deactivations int(10) unsigned NOT NULL DEFAULT 0,
			held int(10) unsigned NOT NULL DEFAULT 0,
			error_codes varchar(255) NOT NULL DEFAULT '',
			warning_codes varchar(255) NOT NULL DEFAULT '',
			plugin_version varchar(20) NOT NULL DEFAULT '',
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY event_started (event_key,started_at),
			KEY status (status)) {$collate};";

		/*
		 * A lock is a durable row, not a transient.
		 *
		 * A persistent object cache evicts under memory pressure, and an evicted
		 * lock is an absent lock. On a multi-server install a transient may not
		 * be shared at all. A row answers "is another run working?" identically
		 * from every process.
		 */
		$sql_locks = "CREATE TABLE {$locks} (			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lock_name varchar(100) NOT NULL,
			owner varchar(64) NOT NULL,
			acquired_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			heartbeat_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lock_name (lock_name)) {$collate};";

		dbDelta( $sql_teams );
		dbDelta( $sql_runs );
		dbDelta( $sql_locks );

		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_upgrade(): void {
		$have = (string) get_option( self::OPTION, '0.0.0' );

		// version_compare, not !==, so a downgrade does not silently re-run
		// install() and a malformed stored value does not loop.
		if ( version_compare( $have, self::VERSION, '>=' ) ) {
			return;
		}

		self::install();
	}

	/** Every table and option this plugin owns. Used by uninstall, and by nothing else. */
	public static function owned(): array {
		return array(
			'tables'  => array( self::teams_table(), self::runs_table(), self::locks_table() ),
			'options' => array( self::OPTION, 'lawp_events', 'lawp_settings', 'lawp_data_generation' ),
		);
	}
}
