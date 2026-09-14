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

	/*
	 * HOW THE LAST READ ENDED, which is not the same question as whether it
	 * errored. A read that stops at the page cap returns rows and no error, and
	 * treating that as the whole list is how a public page loses half its teams.
	 * 'exhausted' is the only value that means "there is no more".
	 */
	private $terminated_because = 'exhausted';
	private $pages_read = 0;
	private $retry_after = null;
	private $http_status = null;

	public function __construct( LAWP_Auth $auth, $site_id ) {
		$this->auth    = $auth;
		$this->site_id = (int) $site_id;
	}

	public function calls() { return $this->calls; }
	public function last_error() { return $this->last_error; }
	public function terminated_because() { return $this->terminated_because; }
	public function pages_read() { return $this->pages_read; }
	public function retry_after() { return $this->retry_after; }
	public function http_status() { return $this->http_status; }
	public function complete() { return 'exhausted' === $this->terminated_because; }

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
	 * THE CURSOR IS last-updated, NOT last-id. This was wrong for months and the
	 * way it was wrong is worth recording.
	 *
	 * Both parameters are required, and passing one without the other returns a
	 * 400 naming the missing one, which makes it look as though they are a pair
	 * of equals. They are not. `last-updated` is a millisecond-epoch watermark
	 * and it is what advances; `last-id` breaks ties between rows that share a
	 * timestamp. Advancing only `last-id` returns the same first page for ever.
	 *
	 * On a Site with fewer than a thousand rows, everything arrives in one page
	 * and none of this shows. On a Site with ten years of registrations it caps
	 * silently at one thousand, which is what happened: 8,000 rows were reachable
	 * and 1,000 were being read. The stall guard below is the only reason it
	 * failed loudly instead of publishing a tenth of a league.
	 *
	 * Verified by walking a real Site: eight pages, eight thousand unique rows,
	 * no duplicates, the watermark advancing every page.
	 *
	 * ROWS ARE DEDUPLICATED BY ID, because a row sharing the boundary timestamp
	 * can legitimately appear on both sides of it.
	 */
	private function fetch_all( $which, $since_ms, $max_pages ) {
		if ( ! isset( self::$endpoints[ $which ] ) ) {
			$this->last_error         = "no such endpoint: $which";
			$this->terminated_because = 'no_such_endpoint';
			return null;
		}

		$out     = array();
		$seen    = array();
		$updated = max( 0, (int) $since_ms );
		$last_id = 0;

		$this->terminated_because = 'page_cap';
		$this->pages_read         = 0;
		$this->retry_after        = null;
		$this->http_status        = null;

		for ( $page = 0; $page < $max_pages; $page++ ) {
			$batch = $this->fetch_page( $which, $updated, $last_id );

			// fetch_page has already set terminated_because for its own failures.
			if ( null === $batch ) { return $out; }

			$this->pages_read++;

			if ( ! $batch ) { $this->terminated_because = 'exhausted'; break; }

			foreach ( $batch as $row ) {
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';

				if ( '' === $id ) { $out[] = $row; continue; }
				if ( isset( $seen[ $id ] ) ) { continue; }

				$seen[ $id ] = true;
				$out[]       = $row;
			}

			// The highest watermark on this page, and the highest id at it.
			$next_updated = $updated;
			$next_id      = 0;

			foreach ( $batch as $row ) {
				$row_updated = isset( $row['lastUpdated'] ) ? (int) $row['lastUpdated'] : 0;
				$row_id      = isset( $row['id'] ) ? (int) $row['id'] : 0;

				if ( $row_updated > $next_updated ) {
					$next_updated = $row_updated;
					$next_id      = $row_id;
				} elseif ( $row_updated === $next_updated && $row_id > $next_id ) {
					$next_id = $row_id;
				}
			}

			/*
			 * Neither field moved, so another request returns this page again.
			 * Stop rather than loop until the job is killed - and report it as
			 * incomplete, because we cannot prove we have everything.
			 */
			if ( $next_updated <= $updated && $next_id <= $last_id ) {
				$this->terminated_because = 'cursor_stalled';
				$this->last_error         = 'pagination cursor did not advance; stopped to avoid looping';
				break;
			}

			$updated = $next_updated;
			$last_id = $next_id;

			if ( count( $batch ) < 1000 ) { $this->terminated_because = 'exhausted'; break; }
		}

		return $out;
	}

	private function fetch_page( $which, $updated_watermark, $last_id ) {
		$token = $this->auth->token();
		if ( ! $token ) { $this->last_error = $this->auth->last_error(); return null; }

		$url = sprintf(
			'%s/v2/sites/%d/%s?last-updated=%d&last-id=%d',
			self::HOST, $this->site_id, self::$endpoints[ $which ],
			max( 0, (int) $updated_watermark ), max( 0, (int) $last_id )
		);

		$res = wp_remote_get( $url, array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
		) );
		$this->calls++;

		if ( is_wp_error( $res ) ) {
			$this->last_error = $res->get_error_message();
			// A cURL timeout and a DNS failure arrive the same way. Both mean we
			// do not know what we did not read.
			$this->terminated_because = false !== stripos( $this->last_error, 'timed out' ) ? 'timeout' : 'http_error';
			return null;
		}

		$code              = wp_remote_retrieve_response_code( $res );
		$this->http_status = $code;

		if ( 401 === $code ) {
			// Token likely expired mid-run. Drop it; let the caller retry once.
			$this->auth->forget();
			$this->last_error         = 'unauthorised; token discarded';
			$this->terminated_because = 'http_error';
			return null;
		}

		if ( 429 === $code ) {
			$retry                    = (int) wp_remote_retrieve_header( $res, 'retry-after' );
			$this->retry_after        = $retry ?: null;
			$this->last_error         = 'rate limited' . ( $retry ? ", retry after {$retry}s" : '' );
			$this->terminated_because = 'rate_limited';
			return null;
		}

		if ( 200 !== $code ) {
			$body                     = json_decode( wp_remote_retrieve_body( $res ), true );
			$this->last_error         = sprintf( 'HTTP %d%s', $code, isset( $body['message'] ) ? ' ' . $body['message'] : '' );
			$this->terminated_because = 'http_error';
			return null;
		}

		/*
		 * A 200 is not evidence of an endpoint.
		 *
		 * The league subdomain serves an HTML page with a 200 for any path,
		 * including nonsensical ones. So the body has to be JSON, and an array
		 * of rows, before this counts as a read at all.
		 */
		$rows = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $rows ) ) {
			$this->last_error         = 'the response was not JSON';
			$this->terminated_because = 'not_json';
			return null;
		}

		return $rows;
	}
}
