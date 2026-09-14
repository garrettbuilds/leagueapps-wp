<?php
/**
 * Registers the block and the shortcode, both of which render the same way.
 *
 * The shortcode is not a legacy fallback. It is how this works in a page builder
 * that does not use the block editor, which is most of the reason a plugin like
 * this gets published at all. One renderer, two entry points; a second
 * implementation would be a second thing to keep correct.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Presentation;

use LeagueAppsWP\Infrastructure\Settings;
use LeagueAppsWP\Infrastructure\SyncHistory;
use LeagueAppsWP\Infrastructure\SystemClock;
use LeagueAppsWP\Infrastructure\ViewCache;
use LeagueAppsWP\Infrastructure\WpdbTeamRepository;
use LeagueAppsWP\Service\TeamsViewBuilder;

final class TeamsBlock {

	/** Older than this and the front end says the list may be behind. */
	private const STALE_AFTER = 86400;

	public static function register(): void {
		register_block_type(
			LAWP_DIR . 'blocks/teams',
			array( 'render_callback' => array( self::class, 'render' ) )
		);

		add_shortcode( 'leagueapps_teams', array( self::class, 'shortcode' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
	}

	/** @param array $attributes Block attributes, already typed by block.json. */
	public static function render( array $attributes = array() ): string {
		$event_key = (string) ( $attributes['eventKey'] ?? '' );
		$config    = Settings::event( $event_key );

		if ( null === $config ) {
			/*
			 * A visitor is told nothing. An editor is told what to fix.
			 *
			 * A misconfigured block on a live page must not print a diagnostic,
			 * and must not print an empty box either. Nothing is the right answer
			 * for a visitor; the editor already has a notice in the sidebar.
			 */
			return current_user_can( 'edit_posts' )
				? '<p class="lawp-teams-placeholder">' . esc_html__( 'LeagueApps Teams: no event is selected, or the selected event no longer exists.', 'leagueapps-wp' ) . '</p>'
				: '';
		}

		global $wpdb;
		$clock      = new SystemClock();
		$generation = Settings::generation( $event_key );

		$view = ViewCache::remember(
			$event_key,
			'teams:' . ( ! empty( $attributes['showEmpty'] ) ? 'all' : 'filled' ),
			$generation,
			static function () use ( $wpdb, $clock, $config, $event_key, $generation, $attributes ) {
				$repository = new WpdbTeamRepository( $wpdb, $clock );
				$history    = new SyncHistory( $wpdb, $clock );

				return ( new TeamsViewBuilder( ! empty( $attributes['showEmpty'] ) ) )->build(
					$config,
					$repository->active_for_event( $event_key ),
					$history->last_success( $event_key ),
					$generation,
					$history->is_stale( $event_key, self::STALE_AFTER )
				);
			}
		);

		if ( null === $view ) {
			return '';
		}

		$html = ( new TeamsRenderer() )->render( $view, array(
			'heading'           => (string) ( $attributes['heading'] ?? '' ),
			'heading_level'     => (int) ( $attributes['headingLevel'] ?? 2 ),
			'show_jump_links'   => ! empty( $attributes['showJumpLinks'] ),
			'show_counts'       => ! empty( $attributes['showCounts'] ) && $config->show_roster_count,
			// Two gates, and the event's is the one that wins. A page editor must
			// not be able to publish names the event's data policy says no to.
			'show_captain'      => ! empty( $attributes['showCaptain'] ) && $config->show_captain,
			'show_location'     => ! empty( $attributes['showLocation'] ) && $config->show_location,
			'show_last_updated' => ! empty( $attributes['showLastUpdated'] ),
			'table_class'       => (string) ( $attributes['tableClass'] ?? '' ),
			'source_url'        => (string) ( $attributes['sourceUrl'] ?? '' ),
			'source_label'      => '' !== (string) ( $attributes['sourceLabel'] ?? '' )
				? (string) $attributes['sourceLabel']
				: __( 'View on LeagueApps', 'leagueapps-wp' ),
		) );

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return '' !== $wrapper ? sprintf( '<div %s>%s</div>', $wrapper, $html ) : $html;
	}

	/**
	 * [leagueapps_teams event="summer-classic-2026" captain="yes"]
	 *
	 * Attribute names are the shortcode convention (lowercase, underscore-free)
	 * rather than the block's camelCase, because that is what somebody typing one
	 * into a page builder will expect.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array(
			'event'        => '',
			'heading'      => '',
			'level'        => 2,
			'jumplinks'    => 'yes',
			'counts'       => 'no',
			'captain'      => 'no',
			'location'     => 'no',
			'updated'      => 'yes',
			'empty'        => 'no',
			'table_class'  => '',
			'source'       => '',
			'source_label' => '',
		), (array) $atts, 'leagueapps_teams' );

		$yes = static fn( $value ): bool => in_array( strtolower( (string) $value ), array( 'yes', 'true', '1', 'on' ), true );

		return self::render( array(
			'eventKey'        => (string) $atts['event'],
			'heading'         => (string) $atts['heading'],
			'headingLevel'    => (int) $atts['level'],
			'showJumpLinks'   => $yes( $atts['jumplinks'] ),
			'showCounts'      => $yes( $atts['counts'] ),
			'showCaptain'     => $yes( $atts['captain'] ),
			'showLocation'    => $yes( $atts['location'] ),
			'showLastUpdated' => $yes( $atts['updated'] ),
			'showEmpty'       => $yes( $atts['empty'] ),
			'tableClass'      => (string) $atts['table_class'],
			'sourceUrl'       => (string) $atts['source'],
			'sourceLabel'     => (string) $atts['source_label'],
		) );
	}

	/**
	 * Tells the editor which events exist and what each one's data supports.
	 *
	 * Capabilities are measured, not declared. "Show manager name" appears only
	 * if manager names are actually in this event's cache, because a toggle that
	 * renders a blank column makes an editor think they broke something.
	 */
	public static function editor_data(): void {
		global $wpdb;

		$clock   = new SystemClock();
		$history = new SyncHistory( $wpdb, $clock );
		$events  = array();
		$caps    = array();

		foreach ( array_keys( Settings::events() ) as $key ) {
			$config = Settings::event( (string) $key );

			if ( null === $config ) {
				continue;
			}

			$events[] = array(
				'key'   => $key,
				'label' => '' !== $config->display_title ? $config->display_title : (string) $key,
			);

			$teams    = ( new WpdbTeamRepository( $wpdb, $clock ) )->active_for_event( (string) $key );
			$total    = count( $teams );
			$captains = 0;
			$multi    = 0;

			foreach ( $teams as $team ) {
				if ( '' !== $team->captain ) {
					++$captains;
				}
				if ( $team->roster_count > 1 ) {
					++$multi;
				}
			}

			$last = $history->last_success( (string) $key );

			$caps[ $key ] = array(
				'hasSynced'    => null !== $last,
				'isStale'      => $history->is_stale( (string) $key, self::STALE_AFTER ),
				'lastSync'     => null !== $last
					? sprintf(
						/* translators: %s: human-readable time difference. */
						__( 'Last updated %s ago.', 'leagueapps-wp' ),
						human_time_diff( (int) strtotime( $last . ' UTC' ) )
					)
					: '',
				'captain'      => $config->show_captain && $captains > 0,
				'captainCoverage' => $captains > 0 && $captains < $total
					? sprintf(
						/* translators: 1: teams with a manager named, 2: total teams. */
						__( 'Found for %1$d of %2$d teams. The rest show a blank cell.', 'leagueapps-wp' ),
						$captains,
						$total
					)
					: '',
				// Every team having exactly one registration is normal for a
				// tournament where only the manager registers. A column of 1s
				// tells a visitor nothing, so the control is not offered.
				'rosterCounts' => $config->show_roster_count && $multi > 0,
				'rosterHelp'   => '',
			);
		}

		wp_add_inline_script(
			'leagueapps-wp-teams-editor-script',
			'window.lawpBlockData = ' . wp_json_encode( array( 'events' => $events, 'capabilities' => $caps ) ) . ';',
			'before'
		);
	}
}
