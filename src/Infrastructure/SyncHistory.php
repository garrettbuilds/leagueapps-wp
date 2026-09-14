<?php
/**
 * Reads the run log. Answers "when did this last work?" and "is that too long ago?".
 *
 * Separate from the team repository because it answers a different question, and
 * because the front end needs this without needing anything that can write teams.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LeagueAppsWP\Contracts\ClockInterface;

final class SyncHistory {

	public function __construct(
		private readonly \wpdb $db,
		private readonly ClockInterface $clock,
	) {}

	/** UTC timestamp of the last run that actually applied, or null. */
	public function last_success( string $event_key ): ?string {
		$when = $this->db->get_var(
			$this->db->prepare(
				'SELECT finished_at FROM ' . Schema::runs_table() . "
				WHERE event_key = %s AND mode = 'apply' AND status IN ( 'pass', 'no_change' )
				ORDER BY finished_at DESC LIMIT 1",
				$event_key
			)
		);

		return null !== $when ? (string) $when : null;
	}

	/**
	 * Is the cache older than this event expects?
	 *
	 * A threshold rather than a fixed number because expectations change across a
	 * season: six hours is fine during open registration and far too slow on the
	 * morning of the event.
	 */
	public function is_stale( string $event_key, int $threshold_seconds ): bool {
		$last = $this->last_success( $event_key );

		// Never synced is not stale. It is a different state with a different
		// message, and conflating them tells a visitor a list is out of date when
		// there has never been one.
		if ( null === $last ) {
			return false;
		}

		$age = $this->clock->now()->getTimestamp() - (int) strtotime( $last . ' UTC' );

		return $age > $threshold_seconds;
	}

	/** Recent runs for the admin screen. Counts and codes, no names. */
	public function recent( string $event_key, int $limit = 20 ): array {
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT run_id, mode, status, creates, updates, renames, moves, deactivations, held,
					error_codes, warning_codes, started_at, finished_at
				FROM ' . Schema::runs_table() . '
				WHERE event_key = %s ORDER BY id DESC LIMIT %d',
				$event_key,
				max( 1, $limit )
			),
			ARRAY_A
		);
	}
}
