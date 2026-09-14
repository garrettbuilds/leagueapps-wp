<?php
/**
 * Is this response trustworthy enough to plan changes from?
 *
 * Deliberately not a test that the API returned HTTP 200. The league subdomain
 * serves an HTML page with a 200 for any path including nonsensical ones, so a
 * 200 proves a web server answered and nothing else.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\FieldPolicy;
use LeagueAppsWP\Domain\SourceResult;
use LeagueAppsWP\Domain\ValidationResult;

final class SourceValidator {

	public function validate_config( EventConfig $config ): ValidationResult {
		$result = new ValidationResult();

		if ( $config->site_id <= 0 ) {
			$result->error( 'SITE_NOT_CONFIGURED', 'No LeagueApps Site id is configured for this event.', array( 'class' => 'blocked' ) );
		}

		if ( '' === trim( $config->event_key ) ) {
			$result->error( 'EVENT_KEY_MISSING', 'The event has no key.', array( 'class' => 'blocked' ) );
		}

		if ( $config->divisions->is_empty() ) {
			$result->error(
				'DIVISION_MAP_EMPTY',
				'No divisions are configured. Run discovery, then confirm a public label and order for each division the Site returns.',
				array( 'class' => 'blocked' )
			);
		}

		if ( ! in_array( $config->unknown_division_policy, EventConfig::policies(), true ) ) {
			$result->error( 'UNKNOWN_DIVISION_POLICY_INVALID', 'The unknown-division policy is not one of the four supported values.', array( 'class' => 'blocked' ) );
		}

		if ( $config->max_deactivation_percent < 0 || $config->max_deactivation_percent > 100 ) {
			$result->error( 'THRESHOLD_INVALID', 'The maximum deactivation percentage must be between 0 and 100.', array( 'class' => 'blocked' ) );
		}

		return $result;
	}

	public function validate_source( SourceResult $source, EventConfig $config ): ValidationResult {
		$result = new ValidationResult();

		$result->note( 'rows_read', count( $source->rows ) );
		$result->note( 'pages_read', $source->pages_read );
		$result->note( 'source_complete', $source->complete );
		$result->note( 'terminated_because', $source->terminated_because );

		if ( ! $source->complete ) {
			// One error, one consequence: nothing may be deactivated from this.
			$result->error(
				'INCOMPLETE_SOURCE',
				sprintf(
					'The read did not finish (%s%s). Rows that exist in LeagueApps may be missing from this response, so absent teams cannot be treated as withdrawn.',
					$source->terminated_because,
					'' !== $source->error ? ': ' . $source->error : ''
				),
				array( 'class' => 'failed', 'retryable' => $source->is_retryable() )
			);

			if ( 'rate_limited' === $source->terminated_because ) {
				$result->note( 'retry_after_seconds', $source->retry_after_seconds );
			}

			return $result;
		}

		// A privacy regression must fail loudly rather than reach the database.
		// The client reduces rows at the parse boundary; this asserts it did.
		foreach ( $source->rows as $row ) {
			if ( is_array( $row ) && FieldPolicy::contains_denied( $row ) ) {
				$result->error(
					'PRIVATE_FIELD_PRESENT',
					'A row reached the planner still carrying a denylisted field. The field allowlist is not being applied.',
					array( 'class' => 'blocked' )
				);
				break;
			}
		}

		if ( array() === $source->rows ) {
			$result->warn( 'SOURCE_EMPTY', 'The read finished successfully and returned no rows.' );
			return $result;
		}

		$this->check_program_scope( $source, $config, $result );

		return $result;
	}

	/**
	 * Did we read the programs we meant to read?
	 *
	 * The published design checks a program id in a response envelope. These
	 * endpoints have no envelope: they return rows, each carrying its own
	 * programId. So the check is per-row, and a configured program that returns
	 * nothing is a warning rather than an error, because a tournament with open
	 * registration and no teams yet is a real and correct state.
	 */
	private function check_program_scope( SourceResult $source, EventConfig $config, ValidationResult $result ): void {
		$observed = array();

		foreach ( $source->rows as $row ) {
			if ( isset( $row['programId'] ) ) {
				$observed[ (int) $row['programId'] ] = true;
			}
		}

		$result->note( 'programs_observed', count( $observed ) );

		if ( array() === $config->program_ids ) {
			return;
		}

		$missing = array_values( array_diff( $config->program_ids, array_keys( $observed ) ) );

		if ( count( $missing ) === count( $config->program_ids ) ) {
			// None of them. Almost always the wrong Site, or last season's ids.
			$result->error(
				'SOURCE_IDENTITY_MISMATCH',
				sprintf(
					'None of the %d configured programs appear in this response. Check the Site id and the selected programs.',
					count( $config->program_ids )
				),
				array( 'class' => 'blocked', 'configured' => $config->program_ids )
			);
			return;
		}

		if ( array() !== $missing ) {
			$result->warn(
				'PROGRAM_NOT_IN_SOURCE',
				sprintf( '%d configured program(s) returned no registrations.', count( $missing ) ),
				array( 'count' => count( $missing ) )
			);
		}
	}
}
