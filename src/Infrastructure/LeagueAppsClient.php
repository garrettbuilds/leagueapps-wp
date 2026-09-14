<?php
/**
 * The production client. The only class that talks to LeagueApps.
 *
 * It wraps the raw reader and does one important thing on top of it: it turns
 * "what came back" plus "how the read ended" into a SourceResult, so nothing
 * downstream can mistake a partial read for a complete one. The reader can
 * return rows and a failure at the same time; this is where that stops being
 * ambiguous.
 *
 * Rows are reduced to the allowlisted fields HERE, at the parse boundary, before
 * anything is stored, logged or passed on.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LAWP_Auth;
use LAWP_Reader;
use LeagueAppsWP\Contracts\ClientInterface;
use LeagueAppsWP\Domain\FieldPolicy;
use LeagueAppsWP\Domain\SourceResult;

final class LeagueAppsClient implements ClientInterface {

	public function __construct(
		private readonly LAWP_Auth $auth,
	) {}

	public function registrations( int $site_id, int $since_ms = 0 ): SourceResult {
		return $this->read( $site_id, 'registrations', $since_ms, true );
	}

	public function programs( int $site_id, int $since_ms = 0 ): SourceResult {
		return $this->read( $site_id, 'programs', $since_ms, false );
	}

	private function read( int $site_id, string $what, int $since_ms, bool $reduce ): SourceResult {
		$reader = new LAWP_Reader( $this->auth, $site_id );
		$rows   = 'registrations' === $what
			? $reader->registrations( $since_ms )
			: $reader->programs( $since_ms );

		$rows = is_array( $rows ) ? $rows : array();

		if ( $reduce ) {
			$rows = array_map( array( FieldPolicy::class, 'reduce' ), $rows );
		}

		if ( $reader->complete() ) {
			return SourceResult::exhausted( $rows, $reader->pages_read() );
		}

		return SourceResult::incomplete(
			$rows,
			$reader->pages_read(),
			$reader->terminated_because(),
			$reader->last_error(),
			$reader->retry_after(),
			$reader->http_status()
		);
	}
}
