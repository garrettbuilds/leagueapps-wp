<?php
/** WP-CLI commands. There is no web-triggered sync, on purpose. */

defined( 'WP_CLI' ) || exit;

class LAWP_Command {

	/**
	 * Check credentials and connectivity. Reads no personal data.
	 *
	 * ## EXAMPLES
	 *     wp leagueapps health
	 */
	public function health() {
		list( $auth, $api, $site ) = $this->clients();
		WP_CLI::log( 'LeagueApps for WordPress ' . LAWP_VERSION );
		WP_CLI::log( '  Site:        ' . $site );
		WP_CLI::log( '  Certificate: ' . ( is_readable( $this->cert() ) ? 'readable' : 'NOT READABLE' ) );
		$token = $auth->token();
		WP_CLI::log( '  Token:       ' . ( $token ? 'ok, scope ' . ( $auth->scope() ?: '?' ) : 'FAILED: ' . $auth->last_error() ) );
		$meta = $api->site();
		WP_CLI::log( '  Site read:   ' . ( $meta ? $meta['name'] : 'FAILED: ' . $api->last_error() ) );
		WP_CLI::log( '  Writes to LeagueApps: blocked. The token is read-only at their end too.' );
	}

	/**
	 * Report how this Site organises its Divisions. Run once when installing.
	 *
	 * ## EXAMPLES
	 *     wp leagueapps discover
	 */
	public function discover() {
		list( , $api ) = $this->clients();
		$regs = $api->registrations( 0 );
		if ( null === $regs ) { WP_CLI::error( $api->last_error() ); }
		$progs = $api->programs( 0 );
		if ( null === $progs ) { WP_CLI::error( $api->last_error() ); }
		$clean = array_map( array( 'LAWP_Fields', 'reduce' ), $regs );
		WP_CLI::log( LAWP_Discover::explain( LAWP_Discover::inspect( $clean, $progs ) ) );
	}

	/**
	 * List Teams grouped by Division.
	 *
	 * ## OPTIONS
	 * [--program=<text>]
	 * : Only Programs whose name contains this. Use it to select one Season or Tournament.
	 *
	 * [--min-roster=<n>]
	 * : Hide Teams with fewer Registrations than this. Default 1.
	 *
	 * ## EXAMPLES
	 *     wp leagueapps teams --program="2026 Summer Classic"
	 *     wp leagueapps teams --program="Fall 2026" --min-roster=4
	 */
	public function teams( $args, $assoc ) {
		list( , $api ) = $this->clients();
		$regs = $api->registrations( 0 );
		if ( null === $regs ) { WP_CLI::error( $api->last_error() ); }

		$clean = array_map( array( 'LAWP_Fields', 'reduce' ), $regs );

		// Loud failure rather than a quiet leak, if the allowlist is ever widened.
		foreach ( $clean as $row ) {
			if ( LAWP_Fields::contains_denied( $row ) ) {
				WP_CLI::error( 'A denied field survived the allowlist. Refusing to continue.' );
			}
		}

		$by = LAWP_Teams::group( $clean, array(
			'program_filter' => $assoc['program'] ?? '',
			'min_roster'     => (int) ( $assoc['min-roster'] ?? 1 ),
		) );

		$total = 0;
		foreach ( $by as $key => $teams ) {
			WP_CLI::log( "\n" . LAWP_Divisions::label( $key ) . '  (' . count( $teams ) . ')' );
			foreach ( $teams as $t ) {
				WP_CLI::log( sprintf( '   %-30s %s', $t['name'], $t['captain'] ? 'captain: ' . $t['captain'] : '' ) );
				$total++;
			}
		}
		WP_CLI::log( sprintf( "\n%d Teams across %d Divisions", $total, count( $by ) ) );
	}

	private function cert() {
		return defined( 'LAWP_CERT_PATH' ) ? LAWP_CERT_PATH : (string) getenv( 'LAWP_CERT_PATH' );
	}

	private function clients() {
		$client = defined( 'LAWP_CLIENT_ID' ) ? LAWP_CLIENT_ID : getenv( 'LAWP_CLIENT_ID' );
		$site   = defined( 'LAWP_SITE_ID' ) ? LAWP_SITE_ID : getenv( 'LAWP_SITE_ID' );
		if ( ! $client || ! $this->cert() || ! $site ) {
			WP_CLI::error( 'Set LAWP_CLIENT_ID, LAWP_CERT_PATH and LAWP_SITE_ID in wp-config.php. See README.' );
		}
		$auth = new LAWP_Auth( $client, $this->cert() );
		return array( $auth, new LAWP_Reader( $auth, $site ), $site );
	}
}

WP_CLI::add_command( 'leagueapps', 'LAWP_Command' );
