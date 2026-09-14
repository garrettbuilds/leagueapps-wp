<?php
/**
 * What came back from LeagueApps, and whether we got all of it.
 *
 * `complete` is the single most important field in this plugin. An incomplete
 * read looks exactly like teams withdrawing: rows that were there last time are
 * absent now. Every deactivation decision is gated on it.
 *
 * WHY THERE IS NO pages_expected
 *
 * The published dry-run design assumes a paginated endpoint that reports a total
 * page count, so a run can assert "4 of 4 retrieved". The export endpoints are
 * keyset-paginated: you pass the highest id you have seen and get the next
 * thousand rows, with no total anywhere in the response. There is nothing to
 * compare against.
 *
 * So completeness is proved by HOW the read ended rather than by a count.
 * A read is complete only if it stopped because the source returned a short or
 * empty page, which is the one termination that means "there is no more". Every
 * other ending (HTTP error, timeout, rate limit, a cursor that failed to
 * advance, hitting the page cap) leaves `complete` false and no deactivation can
 * be planned from it.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class SourceResult {

	public function __construct(
		/** Rows already reduced to the allowlisted fields. */
		public readonly array $rows,
		public readonly bool $complete,
		public readonly int $pages_read = 0,
		/** exhausted | http_error | timeout | rate_limited | cursor_stalled | page_cap | not_json */
		public readonly string $terminated_because = 'exhausted',
		public readonly string $error = '',
		public readonly ?int $retry_after_seconds = null,
		public readonly ?int $http_status = null,
	) {}

	public static function exhausted( array $rows, int $pages_read ): self {
		return new self( $rows, true, $pages_read, 'exhausted' );
	}

	public static function incomplete( array $rows, int $pages_read, string $because, string $error, ?int $retry_after = null, ?int $status = null ): self {
		return new self( $rows, false, $pages_read, $because, $error, $retry_after, $status );
	}

	/**
	 * Whether it is worth trying again soon.
	 *
	 * Schema drift, a 401 and a 404 are not transient and must not be retried in
	 * a loop: retrying a credential problem every five minutes produces a
	 * thousand failed auth attempts a week and no fix.
	 */
	public function is_retryable(): bool {
		if ( $this->complete ) {
			return false;
		}
		if ( in_array( $this->terminated_because, array( 'timeout', 'rate_limited' ), true ) ) {
			return true;
		}
		if ( 'http_error' === $this->terminated_because ) {
			return null !== $this->http_status && $this->http_status >= 500;
		}
		return false;
	}
}
