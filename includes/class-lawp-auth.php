<?php
/**
 * LeagueApps authentication: POST, to one hardcoded URL, and nothing else.
 *
 * Getting a token is the only POST this integration may ever make. Keeping it in
 * its own class with no method parameter means no code path can be talked into
 * POSTing elsewhere. This class never fetches data; the reader never authenticates.
 *
 * Never logs the key, the assertion, the token, or an Authorization header.
 */

defined( 'ABSPATH' ) || defined( 'WP_CLI' ) || exit;

final class LAWP_Auth {

	/** The only URL we may POST to. Not configurable: that would let a signed assertion be redirected. */
	const TOKEN_URL = 'https://auth.leagueapps.io/v2/auth/token';

	/**
	 * LeagueApps' discovery document advertises private_key_jwt; sending the JWT
	 * that way returns 401. This older grant is what actually works. Tested, not read.
	 */
	const GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

	/** Refresh this many seconds before the token actually expires. */
	const SKEW = 60;

	private $client_id;
	private $cert_path;
	private $cert_pass;
	private $last_error = '';

	public function __construct( $client_id, $cert_path, $cert_pass = 'notasecret' ) {
		$this->client_id = (string) $client_id;
		$this->cert_path = (string) $cert_path;
		$this->cert_pass = (string) $cert_pass;
	}

	public function last_error() {
		return $this->last_error;
	}

	/** A valid token, cached. They last 899s; re-signing per request buys nothing. */
	public function token() {
		$cached = get_transient( 'lawp_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$assertion = $this->assertion();
		if ( ! $assertion ) {
			return null;
		}

		$res = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'grant_type' => self::GRANT,
				'assertion'  => $assertion,
			),
		) );

		if ( is_wp_error( $res ) ) {
			// Usually the read-only guard, until the token-URL exception is registered.
			$this->last_error = 'token request blocked or failed: ' . $res->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$this->last_error = sprintf(
				'token exchange returned HTTP %d%s',
				$code,
				isset( $body['error'] ) ? ' (' . $body['error'] . ')' : ''
			);
			return null;
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 900 ) - self::SKEW );
		set_transient( 'lawp_token', $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/** Scope the token carries, for the health check. Never the token itself. */
	public function scope() {
		$t = $this->token();
		if ( ! $t ) { return null; }
		$parts = explode( '.', $t );
		if ( count( $parts ) < 2 ) { return null; }
		$claims = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		$scope  = $claims['scope'] ?? ( $claims['scp'] ?? null );
		// Array in the claims, string in the response. Normalise.
		return is_array( $scope ) ? implode( ' ', $scope ) : $scope;
	}

	public function forget() {
		delete_transient( 'lawp_token' );
	}

	/** Build and sign the RS256 assertion. */
	private function assertion() {
		if ( ! is_readable( $this->cert_path ) ) {
			$this->last_error = 'certificate not readable at the configured path';
			return null;
		}

		$certs = array();
		if ( ! openssl_pkcs12_read( file_get_contents( $this->cert_path ), $certs, $this->cert_pass ) ) {
			// Not echoing openssl's error: it can include path and key detail.
			$this->last_error = 'certificate could not be opened; wrong password or not a PKCS#12 file';
			return null;
		}

		$key = openssl_pkey_get_private( $certs['pkey'] );
		if ( ! $key ) {
			$this->last_error = 'no usable private key in the certificate';
			return null;
		}

		$b64 = function ( $raw ) {
			return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
		};

		$now    = time();
		$header = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = $b64( wp_json_encode( array(
			'iss' => $this->client_id,
			'sub' => $this->client_id,
			'aud' => self::TOKEN_URL,
			'iat' => $now,
			'exp' => $now + 300,
			'jti' => bin2hex( random_bytes( 8 ) ),
		) ) );

		$sig = '';
		if ( ! openssl_sign( "$header.$claims", $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			$this->last_error = 'could not sign the assertion';
			return null;
		}

		return "$header.$claims." . $b64( $sig );
	}
}
