<?php
/**
 * LeagueApps reader: GET only, three named endpoints.
 *
 * No request( $method, $url ) and no way to reach one. Adding a POST would mean
 * adding it visibly, not passing a different string to a general function.
 *
 * Pagination is also the sync contract: every endpoint needs both last-updated
 * (ms epoch watermark) and last-id (keyset cursor, 0 first). Passing one returns
 * a 400 naming the other. Page size is 1,000.
 */

defined( 'ABSPATH' ) || defined( 'WP_CLI' ) || exit;

final class LAWP_Reader {

	const HOST = 'https://admin.leagueapps.io';

	/** The only paths we fetch. The un-suffixed names now 410. */
	/*
	 * Two endpoints. members-2 is deliberately absent.
	 *
	 * The key can read it, but this plugin displays teams and divisions and has
	 * no use for a member list. Leaving it out of the map means no code path
	 * reaches it, which is a stronger guarantee than a comment asking people
	 * not to.
	 *
	 * There is no schedules or standings endpoint to add. Probed across two
	 * sites with two keys: schedule, schedules, games, standings, results,
	 * scores, teams, divisions, brackets, pools and events all 404. Teams are
	 * derived from registrations; schedules are not available at all.
	 */
	private static $endpoints = array(
		'registrations' => 'export/registrations-2',
		'programs'      => 'export/programs',
	);

	private $auth;
	private $site_id;
	private $calls = 0;
	private $last_error = '';

	public function __construct( LAWP_Auth $auth, $site_id ) {
		$this->auth    = $auth;
		$this->site_id = (int) $site_id;
	}

	public function calls() { return $this->calls; }
	public function last_error() { return $this->last_error; }

	public function registrations( $since_ms = 0, $max_pages = 50 ) {
		return $this->fetch_all( 'registrations', $since_ms, $max_pages );
	}

	public function programs( $since_ms = 0, $max_pages = 50 ) {
		return $this->fetch_all( 'programs', $since_ms, $max_pages );
	}

	/** Site metadata. A health check that touches no personal data. */
	public function site() {
		$token = $this->auth->token();
		if ( ! $token ) { $this->last_error = $this->auth->last_error(); return null; }

		$res = wp_remote_get( 'https://api.leagueapps.io/v2/sites/' . $this->site_id, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
		) );
		$this->calls++;
		if ( is_wp_error( $res ) ) { $this->last_error = $res->get_error_message(); return null; }
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) { $this->last_error = 'HTTP ' . wp_remote_retrieve_response_code( $res ); return null; }
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/**
	 * Page through one endpoint until it stops returning a full page.
	 *
	 * Stops on a short page, an empty page, or a cursor that fails to advance.
	 * That last one matters: without it, an endpoint that ignores last-id would
	 * return the same page forever and this would loop until the job is killed.
	 */
	private function fetch_all( $which, $since_ms, $max_pages ) {
		if ( ! isset( self::$endpoints[ $which ] ) ) {
			$this->last_error = "no such endpoint: $which";
			return null;
		}

		$out     = array();
		$last_id = 0;
		$seen    = array();

		for ( $page = 0; $page < $max_pages; $page++ ) {
			$batch = $this->fetch_page( $which, $since_ms, $last_id );
			if ( null === $batch ) { return null; }
			if ( ! $batch ) { break; }

			$out = array_merge( $out, $batch );

			$ids = array_filter( array_map( function ( $r ) { return isset( $r['id'] ) ? (int) $r['id'] : 0; }, $batch ) );
			if ( ! $ids ) { break; }

			$next = max( $ids );
			if ( isset( $seen[ $next ] ) || $next <= $last_id ) {
				// The cursor is not advancing. Stop rather than loop.
				$this->last_error = 'pagination cursor did not advance; stopped to avoid looping';
				break;
			}
			$seen[ $next ] = true;
			$last_id       = $next;

			if ( count( $batch ) < 1000 ) { break; }
		}

		return $out;
	}

	private function fetch_page( $which, $since_ms, $last_id ) {
		$token = $this->auth->token();
		if ( ! $token ) { $this->last_error = $this->auth->last_error(); return null; }

		$url = sprintf(
			'%s/v2/sites/%d/%s?last-updated=%d&last-id=%d',
			self::HOST, $this->site_id, self::$endpoints[ $which ],
			max( 0, (int) $since_ms ), max( 0, (int) $last_id )
		);

		$res = wp_remote_get( $url, array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
		) );
		$this->calls++;

		if ( is_wp_error( $res ) ) {
			$this->last_error = $res->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $res );

		if ( 401 === $code ) {
			// Token likely expired mid-run. Drop it; let the caller retry once.
			$this->auth->forget();
			$this->last_error = 'unauthorised; token discarded';
			return null;
		}

		if ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $res, 'retry-after' );
			$this->last_error = 'rate limited' . ( $retry ? ", retry after {$retry}s" : '' );
			return null;
		}

		if ( 200 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$this->last_error = sprintf( 'HTTP %d%s', $code, isset( $body['message'] ) ? ' ' . $body['message'] : '' );
			return null;
		}

		$rows = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $rows ) ? $rows : array();
	}
}
