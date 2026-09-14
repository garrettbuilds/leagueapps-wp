<?php
/**
 * Turns cached teams into the shape a page renders.
 *
 * Grouping and ordering happen here rather than in SQL because division order is
 * configuration, not a column: an operator can reorder divisions without a
 * migration, and two events on one site can order the same divisions differently.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\Team;
use LeagueAppsWP\Domain\TeamsView;

final class TeamsViewBuilder {

	public function __construct(
		private readonly bool $show_empty_divisions = false,
	) {}

	/**
	 * @param array<int,Team> $teams Active teams, keyed by source_team_id.
	 */
	public function build( EventConfig $config, array $teams, ?string $last_sync_at, int $generation, bool $is_stale = false ): TeamsView {
		$grouped = array();

		foreach ( $teams as $team ) {
			$key = $team->division_key;

			// A team with no division never reaches a page. The planner held it
			// long before this, and this is the second place that is true.
			if ( null === $key || '' === $key ) {
				continue;
			}

			$grouped[ $key ][] = array(
				'id'           => $team->source_team_id,
				'name'         => $team->display_name(),
				'captain'      => $team->captain,
				'location'     => $team->location,
				'roster_count' => $team->roster_count,
			);
		}

		foreach ( $grouped as $key => $list ) {
			// Sorted by name, case-insensitively, so "the" and "The" do not end up
			// in different halves of the table.
			usort( $list, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
			$grouped[ $key ] = $list;
		}

		$divisions = array();
		$order     = $config->divisions->keys_in_order();

		// The pending pseudo-division is not in the configured map, so it is
		// appended last rather than dropped.
		if ( isset( $grouped[ SyncPlanner::PENDING_KEY ] ) ) {
			$order[] = SyncPlanner::PENDING_KEY;
		}

		foreach ( $order as $key ) {
			$list = $grouped[ $key ] ?? array();

			if ( array() === $list && ! $this->show_empty_divisions ) {
				continue;
			}

			$label = SyncPlanner::PENDING_KEY === $key
				? SyncPlanner::PENDING_LABEL
				: $config->divisions->label( $key );

			$divisions[] = array(
				'key'    => $key,
				'label'  => $label,
				'anchor' => $this->anchor( $config->event_key, $key ),
				'count'  => count( $list ),
				'teams'  => $list,
			);
		}

		return new TeamsView(
			event_key: $config->event_key,
			divisions: $divisions,
			total_teams: count( $teams ),
			last_sync_at: $last_sync_at,
			generation: $generation,
			is_stale: $is_stale,
		);
	}

	/**
	 * A stable anchor id, scoped to the event.
	 *
	 * Scoped because two events can appear on one page, and two `#division-a`
	 * anchors means the jump link silently goes to whichever came first.
	 */
	private function anchor( string $event_key, string $division_key ): string {
		$slug = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', $event_key . '-' . $division_key ) ?? '' );
		return trim( $slug, '-' );
	}
}
