<?php
/**
 * WP-CLI commands. There is no web-triggered sync, on purpose.
 *
 * These are adapters and nothing else: they read arguments, call a service,
 * render a report and set an exit code. No business logic lives here, which is
 * why the business logic can be tested without WP-CLI bootstrapped.
 */

defined( 'WP_CLI' ) || exit;

use LeagueAppsWP\Domain\SyncPlan;
use LeagueAppsWP\Infrastructure\DbLockStore;
use LeagueAppsWP\Infrastructure\LeagueAppsClient;
use LeagueAppsWP\Infrastructure\ReadOnlyTeamRepository;
use LeagueAppsWP\Infrastructure\Settings;
use LeagueAppsWP\Infrastructure\SystemClock;
use LeagueAppsWP\Infrastructure\WpdbTeamRepository;
use LeagueAppsWP\Presentation\Reporter;
use LeagueAppsWP\Service\Discovery;
use LeagueAppsWP\Service\DivisionMapper;
use LeagueAppsWP\Service\SourceValidator;
use LeagueAppsWP\Service\SyncApplier;
use LeagueAppsWP\Service\SyncPlanner;
use LeagueAppsWP\Service\TeamNormalizer;

class LAWP_Command {

	/**
	 * Check credentials and connectivity. Reads no personal data.
	 *
	 * ## EXAMPLES
	 *     wp leagueapps health
	 */
	public function health( $args, $assoc ) {
		$auth = $this->auth();

		WP_CLI::log( 'LeagueApps for WordPress ' . LAWP_VERSION );
		WP_CLI::log( '  Certificate: ' . ( is_readable( $this->cert() ) ? 'readable' : 'NOT READABLE by ' . get_current_user() ) );

		$token = $auth->token();
		WP_CLI::log( '  Token:       ' . ( $token ? 'ok, scope ' . ( $auth->scope() ?: '?' ) : 'FAILED: ' . $auth->last_error() ) );
		WP_CLI::log( '  Writes to LeagueApps: blocked. There is no write method in the client.' );

		foreach ( Settings::events() as $key => $event ) {
			WP_CLI::log( sprintf( '  Event %-24s site %s', $key, $event['site_id'] ?? '?' ) );
		}

		if ( ! $token ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Report how a Site organises its Divisions. Run this before configuring one.
	 *
	 * ## OPTIONS
	 *
	 * --site=<id>
	 * : LeagueApps Site id.
	 *
	 * [--event=<key>]
	 * : Compare against a configured event's division map, to list what it misses.
	 *
	 * ## EXAMPLES
	 *     wp leagueapps discover --site=1234
	 */
	public function discover( $args, $assoc ) {
		$site = (int) ( $assoc['site'] ?? 0 );

		if ( $site <= 0 ) {
			WP_CLI::error( 'Pass --site=<id>.' );
		}

		$client = new LeagueAppsClient( $this->auth() );
		$regs   = $client->registrations( $site );
		$progs  = $client->programs( $site );

		if ( ! $regs->complete ) {
			WP_CLI::warning( 'The registration read did not finish (' . $regs->terminated_because . '). This report is partial.' );
		}

		$event = isset( $assoc['event'] ) ? Settings::event( (string) $assoc['event'] ) : null;

		WP_CLI::log( ( new Discovery() )->explain(
			( new Discovery() )->inspect( $regs->rows, $progs->rows, $event?->divisions )
		) );
	}

	/**
	 * Plan a team sync, and apply it only if asked explicitly.
	 *
	 * Dry run is the default. Applying needs both --mode=apply and a --confirm
	 * string that names the event, so a half-typed command cannot write.
	 *
	 * ## OPTIONS
	 *
	 * --event=<key>
	 * : The configured event to sync.
	 *
	 * [--mode=<mode>]
	 * : dry-run or apply.
	 * ---
	 * default: dry-run
	 * options:
	 *   - dry-run
	 *   - apply
	 * ---
	 *
	 * [--confirm=<string>]
	 * : Required for apply. Must be apply-teams-<event key>.
	 *
	 * [--allow-warnings]
	 * : Apply a plan that needs review. A person's decision, never a cron job's.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp leagueapps sync --event=summer-classic-2026
	 *     wp leagueapps sync --event=summer-classic-2026 --mode=apply --confirm=apply-teams-summer-classic-2026
	 */
	public function sync( $args, $assoc ) {
		$event_key = (string) ( $assoc['event'] ?? '' );
		$mode      = (string) ( $assoc['mode'] ?? 'dry-run' );
		$format    = (string) ( $assoc['format'] ?? 'table' );

		$config = Settings::event( $event_key );

		if ( null === $config ) {
			WP_CLI::error( "No event configured with the key '{$event_key}'. Run: wp leagueapps events" );
		}

		if ( 'apply' === $mode && ( $assoc['confirm'] ?? '' ) !== 'apply-teams-' . $event_key ) {
			WP_CLI::error( "Applying needs --confirm=apply-teams-{$event_key}." );
		}

		global $wpdb;
		$clock = new SystemClock();
		$real  = new WpdbTeamRepository( $wpdb, $clock );

		// In a dry run the planner is handed a repository that throws on every
		// write. It has no write path anyway; this is here so that if one is ever
		// added by accident, it fails loudly here rather than quietly in production.
		$repository = 'apply' === $mode ? $real : new ReadOnlyTeamRepository( $real );

		$locks  = new DbLockStore( $wpdb, $clock );
		$owner  = bin2hex( random_bytes( 8 ) );
		$locked = false;

		if ( 'apply' === $mode ) {
			$locked = $locks->acquire( 'sync:' . $event_key, $owner, (int) Settings::get( 'lock_ttl' ) );

			if ( ! $locked ) {
				WP_CLI::warning( 'Another sync holds the lock for this event. Exiting without doing anything.' );
				WP_CLI::halt( 3 );
			}
		}

		try {
			$client = new LeagueAppsClient( $this->auth() );
			$source = $client->registrations( $config->site_id );

			$planner = new SyncPlanner(
				new SourceValidator(),
				new TeamNormalizer( new DivisionMapper( $config->divisions ) )
			);

			$plan = $planner->plan(
				$config,
				$source,
				$repository->active_for_event( $event_key ),
				$owner,
				$clock->now()->format( DateTimeInterface::RFC3339 )
			);

			$reporter = new Reporter();

			if ( 'json' === $format ) {
				WP_CLI::line( (string) wp_json_encode( $reporter->json( $plan, $mode ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				WP_CLI::log( $reporter->text( $plan, $mode ) );
			}

			if ( 'apply' !== $mode ) {
				WP_CLI::halt( $plan->exit_code() );
			}

			$result = ( new SyncApplier( $real, $clock ) )->apply(
				$plan,
				'',
				isset( $assoc['allow-warnings'] )
			);

			if ( ! $result->applied ) {
				WP_CLI::warning( 'Refused: ' . $result->reason );
				WP_CLI::halt( $plan->exit_code() ?: 3 );
			}

			if ( $result->content_changed ) {
				// Only now, and only because something a visitor can see changed.
				$generation = Settings::bump_generation( $event_key );
				$this->purge( $config->page_id );
				WP_CLI::success( sprintf( '%d change(s) applied. Cache generation %d.', $result->writes, $generation ) );
			} else {
				WP_CLI::success( 'Nothing to change. No cache was purged.' );
			}
		} finally {
			if ( $locked ) {
				$locks->release( 'sync:' . $event_key, $owner );
			}
		}
	}

	/** List configured events and the state of each. */
	public function events( $args, $assoc ) {
		$events = Settings::events();

		if ( array() === $events ) {
			WP_CLI::log( 'No events configured yet. Start with: wp leagueapps discover --site=<id>' );
			return;
		}

		foreach ( $events as $key => $event ) {
			WP_CLI::log( sprintf(
				"%s\n  site %s, %d division(s), unknown-division policy: %s",
				$key,
				$event['site_id'] ?? '?',
				count( (array) ( $event['divisions'] ?? array() ) ),
				$event['unknown_division_policy'] ?? 'hold_for_review'
			) );
		}
	}

	/**
	 * Purge only the page this event feeds.
	 *
	 * Never a site-wide flush. Purging everything after each sync turns a
	 * six-hourly no-op into a six-hourly cold cache for every page on the site,
	 * and hides whether the targeted purge works at all.
	 */
	private function purge( ?int $page_id ): void {
		if ( null === $page_id ) {
			return;
		}

		$url = get_permalink( $page_id );

		if ( ! $url ) {
			return;
		}

		clean_post_cache( $page_id );

		// Nginx Helper, if the host uses it. Documented hook, not a guessed
		// PURGE request to a URL somebody typed into a settings field.
		if ( has_action( 'rt_nginx_helper_purge_url' ) ) {
			do_action( 'rt_nginx_helper_purge_url', $url );
		}

		do_action( 'lawp_purge_url', $url );
	}

	private function cert() {
		return defined( 'LAWP_CERT_PATH' ) ? LAWP_CERT_PATH : '';
	}

	private function auth() {
		if ( ! defined( 'LAWP_CLIENT_ID' ) || ! defined( 'LAWP_CERT_PATH' ) ) {
			WP_CLI::error( 'Set LAWP_CLIENT_ID and LAWP_CERT_PATH in wp-config.php.' );
		}

		return new LAWP_Auth( LAWP_CLIENT_ID, LAWP_CERT_PATH );
	}
}

WP_CLI::add_command( 'leagueapps', 'LAWP_Command' );
