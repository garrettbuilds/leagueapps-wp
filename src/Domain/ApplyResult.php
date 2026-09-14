<?php
/**
 * What an apply actually did, including doing nothing on purpose.
 *
 * `content_changed` is separate from `applied` because they answer different
 * questions. A run can apply successfully and change nothing a visitor sees, and
 * in that case the page cache must NOT be purged: purging on every run turns a
 * six-hourly no-op into a six-hourly cold cache for no reason.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class ApplyResult {

	private function __construct(
		public readonly bool $applied,
		public readonly string $reason,
		public readonly int $writes = 0,
		public readonly bool $content_changed = false,
		public readonly string $source_hash = '',
	) {}

	public static function applied( int $writes, bool $content_changed, string $source_hash ): self {
		return new self( true, 'APPLIED', $writes, $content_changed, $source_hash );
	}

	public static function nothing_to_do( string $source_hash ): self {
		return new self( true, 'NO_CHANGE', 0, false, $source_hash );
	}

	public static function refused( string $reason ): self {
		return new self( false, $reason );
	}
}
