<?php
/**
 * Renders a plan for the three audiences that read one.
 *
 * An operator needs to see which teams move. A cron log needs counts and codes.
 * A test needs a stable structure. The same plan, three renderings, and none of
 * them invents a number the plan does not carry.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Presentation;

use LeagueAppsWP\Domain\PlannedChange;
use LeagueAppsWP\Domain\SyncPlan;

final class Reporter {

	/** How many individual changes to name before summarising the rest. */
	private const DETAIL_LIMIT = 25;

	public function text( SyncPlan $plan, string $mode ): string {
		$out = array();

		$out[] = sprintf( 'LeagueApps team sync - %s', 'apply' === $mode ? 'APPLY' : 'DRY RUN' );
		$out[] = sprintf( '  Event:   %s', $plan->event_key );
		$out[] = sprintf( '  Run:     %s', $plan->run_id );
		$out[] = sprintf( '  Source:  %d rows over %d page(s), %s',
			(int) ( $plan->source_meta['rows_read'] ?? 0 ),
			(int) ( $plan->source_meta['pages_read'] ?? 0 ),
			( $plan->source_meta['source_complete'] ?? false ) ? 'complete' : 'INCOMPLETE'
		);
		$out[] = sprintf( '  Hash:    %s', $plan->source_hash ?: '(none)' );
		$out[] = '';

		if ( array() !== $plan->division_report ) {
			$out[] = 'Divisions seen in the source';
			foreach ( $plan->division_report as $value => $info ) {
				$out[] = sprintf(
					'  %-28s %3d team(s)  %s',
					$this->truncate( (string) $value, 28 ),
					(int) $info['teams'],
					'known' === $info['outcome'] ? '-> ' . $info['key'] : strtoupper( (string) $info['outcome'] )
				);
			}
			$out[] = '';
		}

		$out[] = 'Planned changes';
		foreach ( $plan->summary as $action => $count ) {
			if ( $count > 0 ) {
				$out[] = sprintf( '  %-18s %d', $action, $count );
			}
		}
		if ( array() === array_filter( $plan->summary ) ) {
			$out[] = '  (none)';
		}
		$out[] = '';

		$detail = array_filter(
			$plan->changes,
			static fn( PlannedChange $c ): bool => ! in_array( $c->action, array( PlannedChange::NO_CHANGE, PlannedChange::EXCLUDE_INVALID ), true )
		);

		if ( array() !== $detail ) {
			$out[] = 'Detail';
			foreach ( array_slice( $detail, 0, self::DETAIL_LIMIT ) as $change ) {
				$out[] = sprintf(
					'  %-16s %-10s %-30s %s',
					$change->action,
					(string) $change->source_team_id(),
					$this->truncate( $change->label(), 30 ),
					implode( '; ', $change->reasons )
				);
			}
			if ( count( $detail ) > self::DETAIL_LIMIT ) {
				$out[] = sprintf( '  ... and %d more', count( $detail ) - self::DETAIL_LIMIT );
			}
			$out[] = '';
		}

		foreach ( $plan->errors as $error ) {
			$out[] = sprintf( 'ERROR   [%s] %s', $error['code'], $error['message'] );
		}
		foreach ( $plan->warnings as $warning ) {
			$out[] = sprintf( 'WARNING [%s] %s', $warning['code'], $warning['message'] );
		}
		if ( array() !== $plan->errors || array() !== $plan->warnings ) {
			$out[] = '';
		}

		$out[] = sprintf( 'RESULT: %s', strtoupper( $plan->status ) );
		$out[] = '  ' . $this->verdict( $plan, $mode );

		return implode( "\n", $out );
	}

	/** Machine-readable, and deliberately carrying no team names. */
	public function json( SyncPlan $plan, string $mode ): array {
		return array(
			'run_id'        => $plan->run_id,
			'event_key'     => $plan->event_key,
			'mode'          => $mode,
			'status'        => $plan->status,
			'safe_to_apply' => $plan->safe_to_apply(),
			'exit_code'     => $plan->exit_code(),
			'planned_at'    => $plan->planned_at,
			'source'        => array(
				'rows_read'  => $plan->source_meta['rows_read'] ?? 0,
				'pages_read' => $plan->source_meta['pages_read'] ?? 0,
				'complete'   => $plan->source_meta['source_complete'] ?? false,
				'hash'       => $plan->source_hash,
			),
			'changes'       => $plan->summary,
			'errors'        => array_map(
				static fn( array $e ): array => array( 'code' => $e['code'], 'message' => $e['message'] ),
				$plan->errors
			),
			'warnings'      => array_map(
				static fn( array $w ): array => array( 'code' => $w['code'], 'message' => $w['message'] ),
				$plan->warnings
			),
		);
	}

	private function verdict( SyncPlan $plan, string $mode ): string {
		if ( 'apply' === $mode ) {
			return $plan->safe_to_apply() ? 'Applied.' : 'Refused. Nothing was written and the published list is unchanged.';
		}

		return match ( $plan->status ) {
			SyncPlan::PASS      => 'Safe to apply. Re-run with --mode=apply.',
			SyncPlan::NO_CHANGE => 'Nothing to do. The cache already matches the source.',
			SyncPlan::WARNING   => 'Needs a person. Scheduled runs will not apply this; --allow-warnings applies it deliberately.',
			SyncPlan::BLOCKED   => 'Blocked by a safety rule. The published list stays as it is until somebody confirms.',
			default             => 'The source could not be read completely. Nothing will be changed.',
		};
	}

	private function truncate( string $text, int $length ): string {
		return strlen( $text ) > $length ? substr( $text, 0, $length - 1 ) . "\u{2026}" : $text;
	}
}
