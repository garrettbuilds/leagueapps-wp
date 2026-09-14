<?php
/**
 * The admin screen, written for a volunteer rather than a developer.
 *
 * Everything this plugin does was reachable only from WP-CLI, which is fine for
 * the person who installed it and useless to the board member who inherits it.
 *
 * Two principles shape what is here:
 *
 * DISCOVER, DO NOT ASK. Nobody types a program id. The plugin reads the Site,
 * shows what it found, and asks which of those things to publish. An id typed
 * from memory is last season's tournament half the time.
 *
 * SHOW THE CONSEQUENCE. Every screen says what the current configuration
 * actually does right now - how many teams, which divisions are empty, when the
 * last sync ran - rather than leaving somebody to infer it from two text boxes.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Wp;

use LAWP_Auth;
use LeagueAppsWP\Infrastructure\LeagueAppsClient;
use LeagueAppsWP\Infrastructure\Schema;
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

final class Admin {

	public const SLUG = 'leagueapps';
	public const CAP  = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );

		foreach ( array( 'discover', 'save_event', 'dry_run', 'apply', 'delete_event', 'diagnose' ) as $action ) {
			add_action( 'admin_post_lawp_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	public static function menu(): void {
		/*
		 * Its own menu item, not buried under Settings.
		 *
		 * This is an operational screen somebody visits to answer "are the teams
		 * up to date?", not a set-and-forget preference. Settings is where things
		 * go to be found once and never again.
		 */
		add_menu_page(
			__( 'LeagueApps', 'leagueapps-wp' ),
			__( 'LeagueApps', 'leagueapps-wp' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-groups',
			58
		);
	}

	/* ---------------------------------------------------------------- render */

	public static function render(): void {
		self::guard();

		$events = Settings::events();
		$notice = get_transient( 'lawp_admin_notice' );
		delete_transient( 'lawp_admin_notice' );

		echo '<div class="wrap"><h1>' . esc_html__( 'LeagueApps', 'leagueapps-wp' ) . '</h1>';

		if ( is_array( $notice ) ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( (string) $notice['type'] ),
				wp_kses_post( (string) $notice['message'] )
			);
		}

		self::connection_panel();

		$report = get_transient( 'lawp_admin_report' );

		if ( is_string( $report ) && '' !== $report ) {
			delete_transient( 'lawp_admin_report' );
			echo '<h2>' . esc_html__( 'Last run', 'leagueapps-wp' ) . '</h2>';
			echo '<pre class="lawp-report" style="background:#1d2327;color:#f0f0f1;padding:16px;overflow:auto;max-height:520px;border-radius:4px;">'
				. esc_html( $report ) . '</pre>';
		}

		self::confirm_form();

		if ( array() === $events ) {
			self::first_run();
		} else {
			foreach ( $events as $key => $stored ) {
				self::event_panel( (string) $key, (array) $stored );
			}

			self::add_event_form();
		}

		echo '</div>';
	}

	/** Can we talk to LeagueApps at all? Answered before anything else is offered. */
	private static function connection_panel(): void {
		$configured = defined( 'LAWP_CLIENT_ID' ) && defined( 'LAWP_CERT_PATH' );

		echo '<div class="card" style="max-width:none;padding:12px 16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Connection', 'leagueapps-wp' ) . '</h2>';

		if ( ! $configured ) {
			/*
			 * The credential is NOT editable here, deliberately.
			 *
			 * A key in the database is in every export and every backup, and is
			 * readable by anyone who reaches wp-admin. wp-config.php is neither.
			 */
			echo '<p>' . esc_html__( 'Not connected. Add these to wp-config.php, then reload this page:', 'leagueapps-wp' ) . '</p>';
			echo '<pre style="background:#f6f7f7;padding:12px;">'
				. "define( 'LAWP_CLIENT_ID', 'your-private-api-key-name' );\n"
				. "define( 'LAWP_CERT_PATH', '/var/www/your-site/private/leagueapps.p12' );</pre>";
			echo '<p class="description">'
				. esc_html__( 'The certificate belongs above your web root but inside your site folder, mode 0600. Hosts commonly block the site user from /opt, so that is the wrong place for it.', 'leagueapps-wp' )
				. '</p></div>';
			return;
		}

		$readable = is_readable( (string) LAWP_CERT_PATH );
		$auth     = new LAWP_Auth( LAWP_CLIENT_ID, LAWP_CERT_PATH );
		$token    = $readable ? $auth->token() : null;

		echo '<table class="widefat striped" style="margin-bottom:8px;"><tbody>';
		self::status_row( __( 'Certificate', 'leagueapps-wp' ), $readable, $readable ? __( 'Readable', 'leagueapps-wp' ) : __( 'Not readable by the web server', 'leagueapps-wp' ) );
		self::status_row(
			__( 'LeagueApps', 'leagueapps-wp' ),
			(bool) $token,
			$token ? sprintf( /* translators: %s: OAuth scope. */ __( 'Connected, scope %s', 'leagueapps-wp' ), $auth->scope() ?: '?' ) : esc_html( $auth->last_error() )
		);
		self::status_row( __( 'Writes to LeagueApps', 'leagueapps-wp' ), true, __( 'Blocked. This plugin has no method that can write.', 'leagueapps-wp' ) );
		echo '</tbody></table>';

		if ( ! $token && false !== stripos( $auth->last_error(), 'block' ) ) {
			echo '<p class="description">'
				. esc_html__( 'Something on this site is refusing the request. Signing in uses a POST even though it only buys read access, so a read-only guard will stop it. Allow POST to auth.leagueapps.io/v2/auth/token specifically rather than relaxing the guard.', 'leagueapps-wp' )
				. '</p>';
		}

		echo '</div>';
	}

	private static function status_row( string $label, bool $ok, string $detail ): void {
		printf(
			'<tr><td style="width:180px;"><strong>%s</strong></td><td>%s %s</td></tr>',
			esc_html( $label ),
			$ok ? '<span style="color:#00a32a;">&#10003;</span>' : '<span style="color:#d63638;">&#10007;</span>',
			esc_html( $detail )
		);
	}

	/**
	 * Turn what the Site returned into a publishable event.
	 *
	 * Everything here is pre-filled from the Site. An operator confirms or
	 * corrects; they never supply an id or invent a division name, because a
	 * value typed from memory is last season's tournament about half the time.
	 */
	private static function confirm_form(): void {
		$found = get_transient( 'lawp_discovered' );

		if ( ! is_array( $found ) || array() === ( $found['programs'] ?? array() ) ) {
			return;
		}

		$preset   = require LAWP_DIR . 'presets/letter-grades.php';
		$mapper   = new DivisionMapper( new \LeagueAppsWP\Domain\DivisionMap( $preset ) );
		$proposed = array();

		foreach ( $found['programs'] as $program ) {
			$match = $mapper->map( $program['name'] );

			$proposed[] = array(
				'program' => $program,
				'key'     => $match->is_known() ? (string) $match->key : '',
				'label'   => $match->is_known() ? (string) $match->label : '',
			);
		}

		// A filter that covers the newest programs, so the event keeps working
		// when a division is added later.
		$suggested = self::suggest_filter( $found['programs'] );

		echo '<div class="card" style="max-width:none;padding:12px 16px;margin-top:16px;border-left:4px solid #2271b1;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Confirm what to publish', 'leagueapps-wp' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html( sprintf(
				/* translators: 1: number of programs, 2: number of teams. */
				__( 'Found %1$d current programs and %2$d teams on this Site. Nothing is published until you save.', 'leagueapps-wp' ),
				count( $found['programs'] ),
				(int) $found['teams']
			) )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lawp_save_event' );
		echo '<input type="hidden" name="action" value="lawp_save_event">';
		printf( '<input type="hidden" name="site_id" value="%d">', (int) $found['site_id'] );

		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="lawp-title">%s</label></th><td><input type="text" id="lawp-title" name="title" class="regular-text" value="%s" required> <p class="description">%s</p></td></tr>',
			esc_html__( 'What is this?', 'leagueapps-wp' ),
			esc_attr( $suggested ),
			esc_html__( 'How it appears in this admin. For example: Texas Hoedown 2026.', 'leagueapps-wp' )
		);
		printf(
			'<tr><th scope="row"><label for="lawp-filter">%s</label></th><td><input type="text" id="lawp-filter" name="program_filter" class="regular-text" value="%s"> <p class="description">%s</p></td></tr>',
			esc_html__( 'Include programs whose name contains', 'leagueapps-wp' ),
			esc_attr( $suggested ),
			esc_html__( 'A name filter rather than a list of ids, so a division added later is picked up on its own. Leave empty to include every program.', 'leagueapps-wp' )
		);
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Divisions', 'leagueapps-wp' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Each current program, and the division heading its teams will appear under. Anything left unmapped is held back rather than guessed at.', 'leagueapps-wp' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Program in LeagueApps', 'leagueapps-wp' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Teams', 'leagueapps-wp' ) . '</th>';
		echo '<th style="width:240px;">' . esc_html__( 'Heading on your page', 'leagueapps-wp' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Order', 'leagueapps-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		$order = 10;

		foreach ( $proposed as $i => $row ) {
			printf(
				'<tr><td>%s<br><span style="color:#646970;font-size:12px;">%s</span></td><td>%d</td>'
				. '<td><input type="text" name="division_label[%d]" value="%s" class="regular-text" placeholder="%s"></td>'
				. '<td><input type="number" name="division_order[%d]" value="%d" style="width:80px;"></td></tr>',
				esc_html( $row['program']['name'] ),
				esc_html( sprintf( /* translators: %d: program id. */ __( 'id %d', 'leagueapps-wp' ), $row['program']['id'] ) ),
				(int) $row['program']['teams'],
				(int) $i,
				esc_attr( $row['label'] ),
				esc_attr__( 'leave empty to hold back', 'leagueapps-wp' ),
				(int) $i,
				$order
			);

			printf( '<input type="hidden" name="division_source[%d]" value="%s">', (int) $i, esc_attr( $row['program']['name'] ) );
			$order += 10;
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'What visitors see', 'leagueapps-wp' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'require_payment', __( 'Only show teams whose entry fee is paid', 'leagueapps-wp' ), true, __( 'Registration status alone is not proof of payment. A spot can be reserved before a payment clears.', 'leagueapps-wp' ) );
		self::checkbox_row( 'show_location', __( 'Show where each team travels from', 'leagueapps-wp' ), true, __( 'Shown as the metro, not the town. The city on a registration is the person who registered, so a metro is both more useful and less identifying.', 'leagueapps-wp' ) );
		self::checkbox_row( 'show_captain', __( 'Show the manager\'s name', 'leagueapps-wp' ), false, __( 'A team credit is ordinary public information. Showing it beside a location tells the world roughly where that person lives, so prefer one or the other.', 'leagueapps-wp' ) );
		echo '</tbody></table>';

		submit_button( __( 'Save and publish this event', 'leagueapps-wp' ) );
		echo '</form></div>';
	}

	private static function checkbox_row( string $name, string $label, bool $default, string $help ): void {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1"%s> %s</label><p class="description">%s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			checked( $default, true, false ),
			esc_html__( 'Yes', 'leagueapps-wp' ),
			esc_html( $help )
		);
	}

	/**
	 * Suggest a program filter from the newest program's name.
	 *
	 * NOT a common prefix across every current program, which is what this did
	 * first and which produced nothing at all on the Site it was built against:
	 * a 2017 tournament was still flagged live, so the longest shared opening of
	 * "2026 Texas Hoedown (C Division)" and "2017 Open (NAGAAA) Tournament OLD"
	 * was "20". One stale program silently emptied the suggestion.
	 *
	 * The newest program with its trailing parenthetical removed is both more
	 * robust and closer to what somebody would type: "2026 Texas Hoedown (C
	 * Division)" becomes "2026 Texas Hoedown".
	 *
	 * @param array<int,array{id:int,name:string}> $programs Newest first.
	 */
	private static function suggest_filter( array $programs ): string {
		$newest = (string) ( $programs[0]['name'] ?? '' );

		if ( '' === $newest ) {
			return '';
		}

		// Everything from the first bracket onwards is the division, not the event.
		$base = (string) preg_replace( '/\s*[\(\[].*$/u', '', $newest );
		$base = trim( (string) preg_replace( '/\s+(OLD|TEST|COPY|DRAFT)$/i', '', $base ) );

		if ( strlen( $base ) < 4 ) {
			return '';
		}

		/*
		 * Only suggest it if it actually groups something.
		 *
		 * A filter matching one program is not a filter, it is a program id
		 * written out in words, and it will not pick up a division added later.
		 */
		$matches = 0;

		foreach ( $programs as $program ) {
			if ( false !== stripos( (string) $program['name'], $base ) ) {
				++$matches;
			}
		}

		return $matches >= 2 ? $base : '';
	}

	private static function first_run(): void {
		echo '<div class="card" style="max-width:none;padding:12px 16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Start here', 'leagueapps-wp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Nothing is published yet. Enter your LeagueApps Site id and this will read it and show you what is there. You will not have to type a program id.', 'leagueapps-wp' ) . '</p>';
		self::discover_form();
		echo '</div>';
	}

	private static function discover_form(): void {
		$site = defined( 'LAWP_SITE_ID' ) ? (int) LAWP_SITE_ID : 0;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lawp_discover' );
		echo '<input type="hidden" name="action" value="lawp_discover">';
		echo '<p><label>' . esc_html__( 'LeagueApps Site id', 'leagueapps-wp' ) . ' ';
		printf( '<input type="number" name="site_id" value="%s" required style="width:140px;">', esc_attr( (string) ( $site ?: '' ) ) );
		echo '</label> ';
		submit_button( __( 'Read this Site', 'leagueapps-wp' ), 'primary', 'submit', false );
		echo '</p><p class="description">'
			. esc_html__( 'The number in your LeagueApps console URL, for example /console/sites/1234. Reading changes nothing.', 'leagueapps-wp' )
			. '</p></form>';
	}

	/** One configured event: what it publishes, and what to do about it. */
	private static function event_panel( string $key, array $stored ): void {
		global $wpdb;

		$config = Settings::event( $key );

		if ( null === $config ) {
			return;
		}

		$clock      = new SystemClock();
		$repository = new WpdbTeamRepository( $wpdb, $clock );
		$teams      = $repository->active_for_event( $key );

		$by_division = array();
		foreach ( $teams as $team ) {
			$by_division[ (string) $team->division_key ][] = $team;
		}

		$last = $wpdb->get_row( $wpdb->prepare(
			'SELECT status, finished_at, creates, updates, deactivations, held FROM ' . Schema::runs_table()
			. ' WHERE event_key = %s ORDER BY id DESC LIMIT 1',
			$key
		), ARRAY_A );

		echo '<div class="card" style="max-width:none;padding:12px 16px;margin-top:16px;">';
		printf(
			'<h2 style="margin-top:0;">%s <span style="font-weight:400;color:#646970;">%s</span></h2>',
			esc_html( '' !== $config->display_title ? $config->display_title : $key ),
			esc_html( sprintf( /* translators: %d: LeagueApps Site id. */ __( 'Site %d', 'leagueapps-wp' ), $config->site_id ) )
		);

		printf(
			'<p><strong>%s</strong>%s</p>',
			esc_html( sprintf(
				/* translators: 1: team count, 2: division count. */
				_n( '%1$d team across %2$d division.', '%1$d teams across %2$d divisions.', count( $teams ), 'leagueapps-wp' ),
				count( $teams ),
				count( $by_division )
			) ),
			$last
				? ' ' . esc_html( sprintf(
					/* translators: 1: run status, 2: human time difference. */
					__( 'Last run %1$s, %2$s ago.', 'leagueapps-wp' ),
					strtoupper( (string) $last['status'] ),
					human_time_diff( (int) strtotime( (string) $last['finished_at'] . ' UTC' ) )
				) )
				: ' ' . esc_html__( 'Never synced.', 'leagueapps-wp' )
		);

		// What each configured division is doing, including the empty ones.
		echo '<table class="widefat striped" style="margin:8px 0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Division', 'leagueapps-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Teams', 'leagueapps-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'leagueapps-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $config->divisions->keys_in_order() as $division ) {
			$count = count( $by_division[ $division ] ?? array() );
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_html( $config->divisions->label( $division ) ),
				$count,
				$count > 0
					? esc_html__( 'Published', 'leagueapps-wp' )
					: '<em>' . esc_html__( 'Open, nobody entered yet', 'leagueapps-wp' ) . '</em>'
			);
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf(
				/* translators: 1: shortcode. */
				__( 'Put this anywhere, or use the LeagueApps Teams block: %s', 'leagueapps-wp' ),
				sprintf( '[leagueapps_teams event="%s" location="yes"]', $key )
			) )
		);

		self::what_is_published( $config );

		echo '<p>';
		self::action_button( 'lawp_dry_run', $key, __( 'Check for changes', 'leagueapps-wp' ), 'secondary' );
		echo ' ';
		self::action_button( 'lawp_apply', $key, __( 'Update the page now', 'leagueapps-wp' ), 'primary' );
		echo ' ';
		self::action_button( 'lawp_diagnose', $key, __( 'A team is missing', 'leagueapps-wp' ), 'secondary' );
		echo '</p>';
		echo '<p class="description">' . esc_html__( '"Check for changes" writes nothing. It reads LeagueApps and tells you exactly what would change.', 'leagueapps-wp' ) . '</p>';

		self::diagnostic_panel( $key );
		self::history_panel( $key );

		echo '</div>';
	}

	/** Stated plainly, because publishing people's details is the risk here. */
	private static function what_is_published( $config ): void {
		echo '<p><strong>' . esc_html__( 'On the public page', 'leagueapps-wp' ) . '</strong><br>';

		$shown = array( __( 'Team name', 'leagueapps-wp' ), __( 'Division', 'leagueapps-wp' ) );

		if ( $config->show_location ) {
			$shown[] = __( 'Home metro', 'leagueapps-wp' );
		}

		if ( $config->show_captain ) {
			$shown[] = __( 'Manager name', 'leagueapps-wp' );
		}

		echo esc_html( implode( ', ', $shown ) ) . '<br>';
		echo '<span style="color:#646970;">'
			. esc_html__( 'Never: email, phone, address, date of birth, or payment amounts. There is no setting for those.', 'leagueapps-wp' )
			. '</span></p>';

		if ( $config->show_location && $config->show_captain ) {
			echo '<div class="notice notice-warning inline" style="margin:8px 0;"><p>'
				. esc_html__( 'Showing a manager name beside a home metro tells the world roughly where that particular person lives. Either alone does not. Consider turning one off.', 'leagueapps-wp' )
				. '</p></div>';
		}
	}

	/**
	 * Every team LeagueApps offered, and what happened to it.
	 *
	 * The screen an administrator reaches for when somebody says their team is
	 * missing. Before this existed the honest answer involved downloading a
	 * registrations export and reading it by hand.
	 */
	private static function diagnostic_panel( string $event ): void {
		$rows = get_transient( 'lawp_diagnostic_' . $event );

		if ( ! is_array( $rows ) ) {
			return;
		}

		delete_transient( 'lawp_diagnostic_' . $event );

		echo '<h3>' . esc_html__( 'Every team in LeagueApps, and where it went', 'leagueapps-wp' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Team', 'leagueapps-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'On the page?', 'leagueapps-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Why', 'leagueapps-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'What to do', 'leagueapps-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['team'] ),
				$row['published']
					? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Yes', 'leagueapps-wp' ) . '</span>'
					: '<span style="color:#d63638;">&#10007; ' . esc_html__( 'No', 'leagueapps-wp' ) . '</span>',
				esc_html( (string) $row['why'] ),
				esc_html( (string) $row['fix'] )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Read live from LeagueApps just now. Nothing was changed.', 'leagueapps-wp' ) . '</p>';
	}

	/** Recent runs, so "when did this last work" is answerable without SSH. */
	private static function history_panel( string $event ): void {
		global $wpdb;

		$runs = $wpdb->get_results( $wpdb->prepare(
			'SELECT mode, status, creates, updates, deactivations, held, error_codes, warning_codes, finished_at
			FROM ' . Schema::runs_table() . ' WHERE event_key = %s ORDER BY id DESC LIMIT 10',
			$event
		), ARRAY_A );

		if ( ! $runs ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Recent syncs', 'leagueapps-wp' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When', 'leagueapps-wp' ), __( 'Result', 'leagueapps-wp' ), __( 'Added', 'leagueapps-wp' ), __( 'Changed', 'leagueapps-wp' ), __( 'Removed', 'leagueapps-wp' ), __( 'Held', 'leagueapps-wp' ), __( 'Notes', 'leagueapps-wp' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			$codes = trim( (string) $run['error_codes'] . ' ' . (string) $run['warning_codes'] );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td><code>%s</code></td></tr>',
				esc_html( human_time_diff( (int) strtotime( (string) $run['finished_at'] . ' UTC' ) ) . ' ' . __( 'ago', 'leagueapps-wp' ) ),
				esc_html( strtoupper( (string) $run['status'] ) ),
				(int) $run['creates'],
				(int) $run['updates'],
				(int) $run['deactivations'],
				(int) $run['held'],
				esc_html( '' !== $codes ? $codes : '-' )
			);
		}

		echo '</tbody></table>';
	}

	private static function action_button( string $action, string $event, string $label, string $class ): void {
		printf(
			'<form method="post" action="%s" style="display:inline;">%s<input type="hidden" name="action" value="%s"><input type="hidden" name="event" value="%s"><button type="submit" class="button button-%s">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( $action . '_' . $event, '_wpnonce', true, false ),
			esc_attr( $action ),
			esc_attr( $event ),
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	private static function add_event_form(): void {
		echo '<div class="card" style="max-width:none;padding:12px 16px;margin-top:16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Add another event', 'leagueapps-wp' ) . '</h2>';
		self::discover_form();
		echo '</div>';
	}

	/* --------------------------------------------------------------- actions */

	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage LeagueApps.', 'leagueapps-wp' ) );
		}
	}

	private static function notice( string $type, string $message ): void {
		set_transient( 'lawp_admin_notice', array( 'type' => $type, 'message' => $message ), 60 );
	}

	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function handle_discover(): void {
		self::guard();
		check_admin_referer( 'lawp_discover' );

		$site = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;

		if ( $site <= 0 ) {
			self::notice( 'error', esc_html__( 'Enter a Site id.', 'leagueapps-wp' ) );
			self::back();
		}

		$client = new LeagueAppsClient( new LAWP_Auth( LAWP_CLIENT_ID, LAWP_CERT_PATH ) );
		$regs   = $client->registrations( $site );
		$progs  = $client->programs( $site );

		if ( ! $regs->complete ) {
			self::notice( 'error', sprintf(
				/* translators: %s: reason the read stopped. */
				esc_html__( 'Could not read the Site completely (%s). Nothing was changed.', 'leagueapps-wp' ),
				esc_html( $regs->terminated_because )
			) );
			self::back();
		}

		$discovery = new Discovery();
		$report    = $discovery->inspect( $regs->rows, $progs->rows );

		/*
		 * Keep the findings, not just the prose.
		 *
		 * The point of discovery is that nobody types a program id or invents a
		 * division name. The next screen is built from what the Site actually
		 * returned, so it has to survive the redirect.
		 */
		set_transient( 'lawp_discovered', array(
			'site_id'  => $site,
			'model'    => $report['model'],
			'teams'    => $report['teams'],
			'programs' => self::live_programs( $progs->rows, $regs->rows ),
			'field'    => $report['division_field_values'],
		), 1800 );

		set_transient( 'lawp_admin_report', $discovery->explain( $report ), 300 );
		self::notice( 'success', esc_html__( 'Site read. Nothing was changed. Confirm the details below to publish it.', 'leagueapps-wp' ) );
		self::back();
	}

	/**
	 * Programs worth offering, newest first, with how many teams are in each.
	 *
	 * Completed programs are left out. A ten year old Site has hundreds of them
	 * and none is what somebody is setting up today.
	 *
	 * @return array<int,array{id:int,name:string,teams:int}>
	 */
	private static function live_programs( array $programs, array $registrations ): array {
		$teams = array();

		foreach ( $registrations as $row ) {
			$id   = (int) ( $row['programId'] ?? 0 );
			$team = trim( (string) ( $row['team'] ?? '' ) );

			if ( $id > 0 && '' !== $team ) {
				$teams[ $id ][ $team ] = true;
			}
		}

		$out = array();

		foreach ( $programs as $program ) {
			$state = strtoupper( (string) ( $program['programState'] ?? $program['state'] ?? '' ) );

			if ( in_array( $state, array( 'COMPLETED', 'ARCHIVED', 'CANCELLED', 'CANCELED' ), true ) ) {
				continue;
			}

			$id = (int) ( $program['id'] ?? 0 );

			$out[] = array(
				'id'    => $id,
				'name'  => (string) ( $program['name'] ?? '' ),
				'teams' => count( $teams[ $id ] ?? array() ),
			);
		}

		usort( $out, static fn( array $a, array $b ): int => $b['id'] <=> $a['id'] );

		return $out;
	}

	public static function handle_dry_run(): void {
		self::run( false );
	}

	public static function handle_apply(): void {
		self::run( true );
	}

	private static function run( bool $apply ): void {
		self::guard();

		$event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		check_admin_referer( ( $apply ? 'lawp_apply_' : 'lawp_dry_run_' ) . $event );

		$config = Settings::event( $event );

		if ( null === $config ) {
			self::notice( 'error', esc_html__( 'That event is no longer configured.', 'leagueapps-wp' ) );
			self::back();
		}

		global $wpdb;
		$clock      = new SystemClock();
		$repository = new WpdbTeamRepository( $wpdb, $clock );

		$client = new LeagueAppsClient( new LAWP_Auth( LAWP_CLIENT_ID, LAWP_CERT_PATH ) );
		$source = $client->registrations( $config->site_id );

		$planner = new SyncPlanner( new SourceValidator(), new TeamNormalizer( new DivisionMapper( $config->divisions ) ) );
		$plan    = $planner->plan(
			$config,
			$source,
			$repository->active_for_event( $event ),
			'admin-' . bin2hex( random_bytes( 4 ) ),
			$clock->now()->format( \DateTimeInterface::RFC3339 )
		);

		$reporter = new Reporter();
		set_transient( 'lawp_admin_report', $reporter->text( $plan, $apply ? 'apply' : 'dry-run' ), 300 );

		if ( ! $apply ) {
			self::notice(
				$plan->safe_to_apply() ? 'success' : 'warning',
				esc_html__( 'Checked. Nothing was written. The report is below.', 'leagueapps-wp' )
			);
			self::back();
		}

		/*
		 * A person pressing a button may apply a plan that needs review; a cron
		 * job may not. That is the difference the allow_warnings flag encodes,
		 * and this is the one place it is true.
		 */
		$result = ( new SyncApplier( $repository, $clock ) )->apply( $plan, '', true );

		if ( ! $result->applied ) {
			self::notice( 'error', sprintf(
				/* translators: %s: refusal reason. */
				esc_html__( 'Refused: %s. The published page is unchanged.', 'leagueapps-wp' ),
				esc_html( $result->reason )
			) );
			self::back();
		}

		if ( $result->content_changed ) {
			Settings::bump_generation( $event );
			self::purge( $config );
		}

		self::notice( 'success', $result->content_changed
			? sprintf(
				/* translators: %d: number of changes. */
				esc_html__( '%d change(s) applied and the page cache cleared.', 'leagueapps-wp' ),
				$result->writes
			)
			: esc_html__( 'Already up to date. Nothing changed, so no cache was cleared.', 'leagueapps-wp' )
		);
		self::back();
	}

	/**
	 * Build the "where did every team go" table from a live, read-only plan.
	 *
	 * Deliberately a dry run: the answer to "why is my team missing" must never
	 * be arrived at by changing the page.
	 */
	public static function handle_diagnose(): void {
		self::guard();

		$event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		check_admin_referer( 'lawp_diagnose_' . $event );

		$config = Settings::event( $event );

		if ( null === $config ) {
			self::notice( 'error', esc_html__( 'That event is no longer configured.', 'leagueapps-wp' ) );
			self::back();
		}

		global $wpdb;
		$clock  = new SystemClock();
		$client = new LeagueAppsClient( new LAWP_Auth( LAWP_CLIENT_ID, LAWP_CERT_PATH ) );
		$source = $client->registrations( $config->site_id );

		if ( ! $source->complete ) {
			self::notice( 'error', sprintf(
				/* translators: %s: why the read stopped. */
				esc_html__( 'Could not read LeagueApps completely (%s), so this would be misleading. Nothing was changed.', 'leagueapps-wp' ),
				esc_html( $source->terminated_because )
			) );
			self::back();
		}

		$planner = new SyncPlanner( new SourceValidator(), new TeamNormalizer( new DivisionMapper( $config->divisions ) ) );
		$plan    = $planner->plan(
			$config,
			$source,
			( new WpdbTeamRepository( $wpdb, $clock ) )->active_for_event( $event ),
			'diag-' . bin2hex( random_bytes( 4 ) ),
			$clock->now()->format( \DateTimeInterface::RFC3339 )
		);

		$rows = array();

		foreach ( $plan->changes as $change ) {
			if ( in_array( $change->action, array( 'create', 'update', 'rename', 'move_division', 'no_change' ), true ) ) {
				$rows[] = array(
					'team'      => $change->label(),
					'published' => true,
					'why'       => __( 'Registered, paid, in a mapped division', 'leagueapps-wp' ),
					'fix'       => '',
				);
				continue;
			}

			if ( in_array( $change->action, array( 'hold_for_review', 'hide_by_policy' ), true ) ) {
				$rows[] = array( 'team' => $change->label(), 'published' => false )
					+ self::explain( (string) ( $change->reasons[0] ?? '' ), (string) ( $change->reasons[1] ?? '' ) );
			}
		}

		foreach ( $plan->skipped as $skip ) {
			$rows[] = array( 'team' => (string) $skip['team'], 'published' => false )
				+ self::explain( (string) $skip['reason'], (string) $skip['detail'] );
		}

		usort( $rows, static fn( array $a, array $b ): int => ( $a['published'] <=> $b['published'] ) ?: strcasecmp( $a['team'], $b['team'] ) );

		set_transient( 'lawp_diagnostic_' . $event, $rows, 300 );
		self::notice( 'success', esc_html__( 'Read from LeagueApps. Nothing was changed.', 'leagueapps-wp' ) );
		self::back();
	}

	/**
	 * Turn a machine code into something a volunteer can act on.
	 *
	 * Every row says what to DO. A reason code on its own sends somebody back to
	 * whoever installed the plugin, which is the situation this screen exists to
	 * end.
	 *
	 * @return array{why:string,fix:string}
	 */
	private static function explain( string $reason, string $detail ): array {
		switch ( $reason ) {
			case 'FEE_NOT_SETTLED':
				return array(
					'why' => __( 'The entry fee is not marked paid', 'leagueapps-wp' ),
					'fix' => __( 'Take the payment in LeagueApps, or mark the invoice paid. It appears at the next sync.', 'leagueapps-wp' ),
				);

			case 'NOT_HOLDING_A_SPOT':
				return array(
					'why' => sprintf( /* translators: %s: LeagueApps registration status. */ __( 'Registration status is %s, not Spot Reserved', 'leagueapps-wp' ), $detail ),
					'fix' => __( 'The registration is unfinished in LeagueApps. Confirm the spot there.', 'leagueapps-wp' ),
				);

			case 'PROGRAM_NOT_IN_EVENT':
				return array(
					'why' => sprintf( /* translators: %s: LeagueApps program name. */ __( 'Registered in "%s", which this event does not include', 'leagueapps-wp' ), $detail ),
					'fix' => __( 'Either the team entered the wrong program, or this event\'s program filter is too narrow.', 'leagueapps-wp' ),
				);

			case 'UNKNOWN_DIVISION':
				return array(
					'why' => sprintf( /* translators: %s: the unrecognised division value. */ __( 'Division "%s" is not mapped', 'leagueapps-wp' ), $detail ),
					'fix' => __( 'Add it to this event\'s divisions, or hide it. It is held rather than guessed at on purpose.', 'leagueapps-wp' ),
				);

			case 'DIVISION_UNASSIGNED':
				return array(
					'why' => __( 'No division on the registration, and none in the program name', 'leagueapps-wp' ),
					'fix' => __( 'Assign the team to a division in LeagueApps.', 'leagueapps-wp' ),
				);

			case 'DIVISION_HIDDEN':
				return array(
					'why' => __( 'Its division is set to hidden here', 'leagueapps-wp' ),
					'fix' => __( 'Make that division visible in this event\'s settings.', 'leagueapps-wp' ),
				);

			case 'BELOW_MIN_ROSTER':
				return array(
					'why' => sprintf( /* translators: %s: number of registrations. */ __( 'Only %s registration(s), below this event\'s minimum', 'leagueapps-wp' ), $detail ),
					'fix' => __( 'More players need to register, or lower the minimum.', 'leagueapps-wp' ),
				);

			default:
				return array(
					'why' => $reason . ( '' !== $detail ? ': ' . $detail : '' ),
					'fix' => __( 'Run "Check for changes" for the full report.', 'leagueapps-wp' ),
				);
		}
	}

	public static function handle_save_event(): void {
		self::guard();
		check_admin_referer( 'lawp_save_event' );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$site  = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
		$key   = sanitize_title( $title );

		if ( '' === $key || $site <= 0 ) {
			self::notice( 'error', esc_html__( 'Give the event a name. Nothing was saved.', 'leagueapps-wp' ) );
			self::back();
		}

		$divisions = self::divisions_from_input(
			isset( $_POST['division_label'] ) ? (array) wp_unslash( $_POST['division_label'] ) : array(),
			isset( $_POST['division_source'] ) ? (array) wp_unslash( $_POST['division_source'] ) : array(),
			isset( $_POST['division_order'] ) ? (array) wp_unslash( $_POST['division_order'] ) : array()
		);

		if ( array() === $divisions ) {
			self::notice( 'error', esc_html__( 'Give at least one program a heading, or there is nothing to publish. Nothing was saved.', 'leagueapps-wp' ) );
			self::back();
		}

		$show_location = ! empty( $_POST['show_location'] );

		Settings::save_event( $key, array(
			'site_id'                  => $site,
			'divisions'                => array_values( $divisions ),
			'program_ids'              => array(),
			'program_filter'           => isset( $_POST['program_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['program_filter'] ) ) : '',
			'display_title'            => $title,
			'unknown_division_policy'  => 'hold_for_review',
			'require_payment'          => ! empty( $_POST['require_payment'] ),
			'show_location'            => $show_location,
			'show_captain'             => ! empty( $_POST['show_captain'] ),
			'show_roster_count'        => false,
			// Loaded only when locations are shown, so an event that does not
			// publish them does not carry a lookup table it never consults.
			'metros'                   => $show_location ? require LAWP_DIR . 'presets/us-metros.php' : array(),
			'max_deactivation_count'   => 10,
			'max_deactivation_percent' => 25,
			'min_roster'               => 1,
			'timezone'                 => wp_timezone_string(),
		) );

		delete_transient( 'lawp_discovered' );

		self::notice( 'success', sprintf(
			/* translators: 1: event name, 2: number of divisions. */
			esc_html__( 'Saved "%1$s" with %2$d division(s). Nothing is published yet: press "Check for changes" to see what would appear, then "Update the page now".', 'leagueapps-wp' ),
			esc_html( $title ),
			count( $divisions )
		) );
		self::back();
	}

	public static function handle_delete_event(): void {
		self::guard();
		check_admin_referer( 'lawp_delete_event' );
		self::back();
	}

	/**
	 * Turn the confirmation form into a division map.
	 *
	 * Separate from the request handler so it can be tested without an HTTP
	 * round trip, which is the only reason the mapping rules below are checkable
	 * at all.
	 *
	 * ONE ENTRY PER HEADING, NOT PER PROGRAM. Several programs commonly feed one
	 * heading: a Site may run "Open C Division" and "2026 Summer (C Division)" in
	 * the same season. Labels are grouped, and each program name becomes an alias
	 * of the heading it was mapped to. Using the whole program name as the alias
	 * is what makes matching work without anybody inventing a rule.
	 *
	 * AN EMPTY HEADING MEANS HOLD IT BACK. That is a real choice and the right
	 * default for anything the operator did not recognise, so it is silent rather
	 * than an error.
	 *
	 * @return array<string,array{key:string,label:string,order:int,aliases:string[],visible:bool}>
	 */
	public static function divisions_from_input( array $labels, array $sources, array $orders ): array {
		$divisions = array();

		foreach ( $labels as $i => $label ) {
			$label = sanitize_text_field( (string) $label );

			if ( '' === $label ) {
				continue;
			}

			$key    = sanitize_title( $label );
			$source = sanitize_text_field( (string) ( $sources[ $i ] ?? '' ) );

			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $divisions[ $key ] ) ) {
				$divisions[ $key ] = array(
					'key'     => $key,
					'label'   => $label,
					'order'   => (int) ( $orders[ $i ] ?? 0 ),
					'aliases' => array(),
					'visible' => true,
				);
			}

			if ( '' !== $source && ! in_array( $source, $divisions[ $key ]['aliases'], true ) ) {
				$divisions[ $key ]['aliases'][] = $source;
			}
		}

		return $divisions;
	}

	/**
	 * Clear only the pages this event feeds.
	 *
	 * Nginx Helper exposes an object rather than an action; firing a
	 * do_action at a hook nothing listens to purges nothing and reports success,
	 * which is how a stale public copy outlives the change that should have
	 * replaced it.
	 */
	private static function purge( $config ): void {
		global $nginx_purger;

		if ( null !== $config->page_id ) {
			clean_post_cache( (int) $config->page_id );

			if ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_post' ) ) {
				$nginx_purger->purge_post( (int) $config->page_id );
			}
		} elseif ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_all' ) ) {
			// No page recorded, so we cannot be surgical. Better a cold cache
			// than a stale team list.
			$nginx_purger->purge_all();
		}

		do_action( 'lawp_purge_event', $config->event_key );
	}
}
