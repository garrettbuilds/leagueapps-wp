<?php
/**
 * One credential per LeagueApps Site.
 *
 * A league commonly has more than one LeagueApps account: a tournament runs on
 * its own Site with its own key, separately from the regular season. Proved by
 * test rather than assumed - a key issued for one Site gets HTTP 403 on another
 * and vice versa, which we confirmed on two live Sites. Each Site needs its own credential, and one global pair
 * of constants cannot express that.
 *
 * WHY THIS IS NOT IN THE DATABASE
 *
 * A key in wp_options is in every database export, every backup, and every
 * migration, and is readable by anyone who reaches wp-admin or a compromised
 * plugin. wp-config.php is none of those things. The admin screen shows whether
 * a credential works and never what it is.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

final class Credentials {

	/**
	 * Credentials for a Site, or null if none is configured.
	 *
	 * Two forms are accepted. The map is what a site with more than one Site id
	 * should use:
	 *
	 *   define( 'LAWP_CREDENTIALS', array(
	 *       1000001 => array( 'client_id' => 'abc…', 'cert_path' => '/var/www/x/private/tournament.p12' ),
	 *       1000002 => array( 'client_id' => 'def…', 'cert_path' => '/var/www/x/private/league.p12' ),
	 *   ) );
	 *
	 * The older single pair still works and is treated as the credential for
	 * LAWP_SITE_ID, or as a last-resort default when no map entry matches. An
	 * install that has been running on one Site keeps running without edits.
	 *
	 * @return array{client_id:string,cert_path:string}|null
	 */
	public static function for_site( int $site_id ): ?array {
		if ( defined( 'LAWP_CREDENTIALS' ) && is_array( LAWP_CREDENTIALS ) ) {
			$map = LAWP_CREDENTIALS;

			if ( isset( $map[ $site_id ] ) && is_array( $map[ $site_id ] ) ) {
				$entry = $map[ $site_id ];

				if ( '' !== (string) ( $entry['client_id'] ?? '' ) && '' !== (string) ( $entry['cert_path'] ?? '' ) ) {
					return array(
						'client_id' => (string) $entry['client_id'],
						'cert_path' => (string) $entry['cert_path'],
					);
				}
			}
		}

		if ( defined( 'LAWP_CLIENT_ID' ) && defined( 'LAWP_CERT_PATH' ) ) {
			/*
			 * The legacy pair is only used for the Site it was configured for.
			 *
			 * Falling back to it for ANY Site would be the bug this class exists
			 * to prevent: a request for the league's Site quietly signed with the
			 * tournament's key, which fails with a 403 that looks like a
			 * permissions problem rather than a configuration one.
			 */
			$legacy_site = defined( 'LAWP_SITE_ID' ) ? (int) LAWP_SITE_ID : 0;

			if ( 0 === $legacy_site || $legacy_site === $site_id ) {
				return array(
					'client_id' => (string) LAWP_CLIENT_ID,
					'cert_path' => (string) LAWP_CERT_PATH,
				);
			}
		}

		return null;
	}

	/** Every Site this install has a credential for. */
	public static function configured_sites(): array {
		$sites = array();

		if ( defined( 'LAWP_CREDENTIALS' ) && is_array( LAWP_CREDENTIALS ) ) {
			foreach ( array_keys( LAWP_CREDENTIALS ) as $site ) {
				$sites[] = (int) $site;
			}
		}

		if ( defined( 'LAWP_CLIENT_ID' ) && defined( 'LAWP_SITE_ID' ) ) {
			$sites[] = (int) LAWP_SITE_ID;
		}

		return array_values( array_unique( array_filter( $sites ) ) );
	}

	/**
	 * A fingerprint an operator can compare against their console, safely.
	 *
	 * Enough to tell two credentials apart in a support conversation, not enough
	 * to be one. The full key never appears in the admin, in a log, or in a
	 * screenshot somebody pastes into a ticket.
	 */
	public static function fingerprint( int $site_id ): string {
		$creds = self::for_site( $site_id );

		if ( null === $creds ) {
			return '';
		}

		$id = $creds['client_id'];

		return strlen( $id ) > 6 ? '…' . strtoupper( substr( $id, -6 ) ) : '…';
	}

	/** Is the certificate on disk and readable by the process? */
	public static function readable( int $site_id ): bool {
		$creds = self::for_site( $site_id );

		return null !== $creds && is_readable( $creds['cert_path'] );
	}
}
