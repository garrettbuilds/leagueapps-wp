<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Service\DivisionMapper;
use LeagueAppsWP\Service\TeamNormalizer;
use LeagueAppsWP\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class TeamNormalizerTest extends TestCase {

	private function normalizer(): TeamNormalizer {
		return new TeamNormalizer( new DivisionMapper( Fixtures::division_map() ) );
	}

	public function test_it_normalizes_a_valid_registration_into_a_team(): void {
		$result = $this->normalizer()->normalize( array( Fixtures::row( array( 'team' => '  Riverside   Rangers  ' ) ) ), Fixtures::config() );

		$team = $result->teams[84321];

		self::assertSame( 84321, $team->source_team_id );
		self::assertSame( 'Riverside Rangers', $team->name );
		self::assertSame( 'c', $team->division_key );
		self::assertSame( 'C Division', $team->division_label );
		self::assertSame( 1, $team->roster_count );
	}

	/**
	 * Identity is the id, never the name.
	 *
	 * Two rows for one team whose name differs only in casing must produce one
	 * team. Grouping by name produces two, and the page shows the same team
	 * twice with half a roster each.
	 */
	public function test_it_groups_on_team_id_and_not_on_name(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'team' => 'RIVERSIDE RANGERS' ) ),
			Fixtures::row( array( 'team' => 'Riverside Rangers' ) ),
		), Fixtures::config() );

		self::assertCount( 1, $result->teams );
		self::assertSame( 2, $result->teams[84321]->roster_count );
		// Longest wins, which is stable regardless of row order.
		self::assertSame( 'RIVERSIDE RANGERS', $result->teams[84321]->name );
	}

	public function test_two_teams_may_share_a_name(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'teamId' => 1, 'team' => 'Night Owls' ) ),
			Fixtures::row( array( 'teamId' => 2, 'team' => 'Night Owls' ) ),
		), Fixtures::config() );

		self::assertCount( 2, $result->teams );
	}

	public function test_only_registrations_holding_a_spot_count(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'teamId' => 1, 'registrationStatus' => 'SPOT_RESERVED' ) ),
			Fixtures::row( array( 'teamId' => 2, 'registrationStatus' => 'SPOT_PENDING' ) ),
			Fixtures::row( array( 'teamId' => 3, 'registrationStatus' => 'WAITING_LIST' ) ),
		), Fixtures::config() );

		self::assertSame( array( 1 ), array_keys( $result->teams ) );
	}

	/**
	 * A malformed id is rejected, not cast.
	 *
	 * (int) "12x" is 12, which would merge this row into a real team.
	 */
	public function test_a_malformed_team_id_is_excluded_rather_than_cast(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'teamId' => '12x' ) ),
			Fixtures::row( array( 'teamId' => 0 ) ),
			Fixtures::row( array( 'teamId' => null ) ),
		), Fixtures::config() );

		self::assertCount( 0, $result->teams );
		self::assertCount( 3, $result->excluded );
		self::assertSame( 'MISSING_TEAM_ID', $result->excluded[0]['reason'] );
	}

	public function test_a_numeric_string_team_id_is_accepted(): void {
		$result = $this->normalizer()->normalize( array( Fixtures::row( array( 'teamId' => '84321' ) ) ), Fixtures::config() );

		self::assertArrayHasKey( 84321, $result->teams );
	}

	public function test_a_blank_team_name_is_excluded(): void {
		$result = $this->normalizer()->normalize( array( Fixtures::row( array( 'team' => "   \t " ) ) ), Fixtures::config() );

		self::assertCount( 0, $result->teams );
		self::assertSame( 'MISSING_TEAM_NAME', $result->excluded[0]['reason'] );
	}

	public function test_control_characters_are_stripped_from_a_team_name(): void {
		$result = $this->normalizer()->normalize( array( Fixtures::row( array( 'team' => "Night\x00Owls\x1f" ) ) ), Fixtures::config() );

		self::assertSame( 'Night Owls', $result->teams[84321]->name );
	}

	/** An excluded row is reported for triage and carries ids only. */
	public function test_an_excluded_row_report_carries_no_personal_data(): void {
		$result = $this->normalizer()->normalize( array( Fixtures::row( array( 'teamId' => 'bad' ) ) ), Fixtures::config() );

		self::assertSame( array( 'programId', 'teamId' ), array_keys( $result->excluded[0]['row'] ) );
	}

	public function test_a_captain_is_named_only_when_the_event_asks_for_one(): void {
		$rows = array( Fixtures::row( array( 'role' => 'CAPTAIN', 'firstName' => 'Sam', 'lastName' => 'Okafor' ) ) );

		$off = $this->normalizer()->normalize( $rows, Fixtures::config() );
		$on  = $this->normalizer()->normalize( $rows, Fixtures::config( array( 'show_captain' => true ) ) );

		self::assertSame( '', $off->teams[84321]->captain );
		self::assertSame( 'Sam Okafor', $on->teams[84321]->captain );
	}

	public function test_a_team_below_the_roster_minimum_is_held_not_published(): void {
		$result = $this->normalizer()->normalize(
			array( Fixtures::row() ),
			Fixtures::config( array( 'min_roster' => 5 ) )
		);

		self::assertCount( 0, $result->teams );
		self::assertSame( 'BELOW_MIN_ROSTER', $result->held[0]['reason'] );
	}

	public function test_a_program_filter_limits_what_is_read(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'teamId' => 1, 'programName' => '2026 Summer Classic (C Division)' ) ),
			Fixtures::row( array( 'teamId' => 2, 'programName' => '2026 Winter League (C Division)' ) ),
		), Fixtures::config( array( 'program_filter' => 'summer classic' ) ) );

		self::assertSame( array( 1 ), array_keys( $result->teams ) );
	}

	public function test_a_team_with_an_unrecognised_division_is_held(): void {
		$result = $this->normalizer()->normalize(
			array( Fixtures::row( array( 'division' => 'Competitive Open' ) ) ),
			Fixtures::config()
		);

		self::assertCount( 0, $result->teams );
		self::assertSame( 'UNKNOWN_DIVISION', $result->held[0]['reason'] );
		self::assertSame( 'Competitive Open', $result->held[0]['source_value'] );
	}

	public function test_registrations_for_one_team_in_two_divisions_are_reported(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'division' => 'C' ) ),
			Fixtures::row( array( 'division' => 'D' ) ),
		), Fixtures::config() );

		self::assertTrue( $result->validation->has_warning_code( 'DIVISION_CONFLICT' ) );
		self::assertSame( 'c', $result->teams[84321]->division_key, 'the first division seen wins, not the last' );
	}

	public function test_it_reports_every_division_value_it_saw(): void {
		$result = $this->normalizer()->normalize( array(
			Fixtures::row( array( 'teamId' => 1, 'division' => 'C' ) ),
			Fixtures::row( array( 'teamId' => 2, 'division' => 'C' ) ),
			Fixtures::row( array( 'teamId' => 3, 'division' => 'Competitive Open' ) ),
		), Fixtures::config() );

		self::assertSame( 2, $result->division_report['C']['teams'] );
		self::assertSame( 1, $result->division_report['Competitive Open']['teams'] );
		self::assertNull( $result->division_report['Competitive Open']['key'] );
	}

	/** The privacy boundary, asserted rather than trusted. */
	public function test_denylisted_fields_never_reach_a_team(): void {
		$result = $this->normalizer()->normalize( array( Fixtures::row( array(
			'email'        => 'someone@example.com',
			'birthDate'    => '1984-02-03',
			'phone'        => '512-555-0100',
			'paymentStatus' => 'PAID',
		) ) ), Fixtures::config() );

		$encoded = json_encode( $result->teams );

		self::assertStringNotContainsString( 'example.com', (string) $encoded );
		self::assertStringNotContainsString( '1984-02-03', (string) $encoded );
		self::assertStringNotContainsString( '555-0100', (string) $encoded );
		self::assertStringNotContainsString( 'PAID', (string) $encoded );
	}
}
