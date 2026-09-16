<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\DivisionMap;
use LeagueAppsWP\Domain\DivisionMatch;
use LeagueAppsWP\Service\DivisionMapper;
use LeagueAppsWP\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DivisionMapperTest extends TestCase {

	private function mapper(): DivisionMapper {
		return new DivisionMapper( Fixtures::division_map() );
	}

	#[DataProvider( 'program_names' )]
	public function test_it_reads_a_division_out_of_a_program_name( string $program, string $expected_key ): void {
		$match = $this->mapper()->map( $program );

		self::assertTrue( $match->is_known(), "did not match: $program" );
		self::assertSame( $expected_key, $match->key );
		self::assertSame( 'program_name', $match->matched_on );
	}

	/** Every one of these shapes appears in real program names. */
	public static function program_names(): iterable {
		yield 'division in brackets'   => array( '2026 Summer Classic (C Division)', 'c' );
		yield 'season last'            => array( 'Open D Division Fall 2026', 'd' );
		yield 'season first'           => array( '2019 Spring Open D Division', 'd' );
		yield 'no apostrophe'          => array( 'Spring 2018 Womens Division', 'womens' );
		yield 'curly apostrophe'       => array( "Spring 2026 Women\u{2019}s Division", 'womens' );
		yield 'straight apostrophe'    => array( "Spring 2026 Women's Division", 'womens' );
		yield 'combined a/b'           => array( '2026 Summer Classic (A/B Division)', 'ab' );
		yield 'legends beats d'        => array( '2026 Summer Classic (Legends D)', 'legends' );
		yield 'legends alone'          => array( 'Legends Division Fall 2026', 'legends' );
		yield 'old name masters'       => array( '2016 Fall Masters Division', 'legends' );
		yield 'old name singular'      => array( 'Open Master Division 2015', 'legends' );
	}

	/**
	 * The bug a test found and production did not.
	 *
	 * "Open End of Season" contains the substring "open e". Matched as a
	 * substring it became E Division; matched on word boundaries it is not a
	 * division at all.
	 */
	public function test_it_does_not_read_e_division_out_of_open_end_of_season(): void {
		$match = $this->mapper()->map( '2022 Open End of Season Tournament' );

		self::assertSame( DivisionMatch::UNASSIGNED, $match->outcome );
	}

	#[DataProvider( 'not_divisions' )]
	public function test_it_reports_no_division_for_a_program_that_is_not_one( string $program ): void {
		self::assertSame( DivisionMatch::UNASSIGNED, $this->mapper()->map( $program )->outcome );
	}

	public static function not_divisions(): iterable {
		yield 'free agents'    => array( '2026 Summer Classic Free Agents' );
		yield 'clinic'         => array( '2026 Ratings Clinic' );
		yield 'bare season'    => array( 'Fall 2026 Registration' );
		yield 'empty'          => array( '' );
	}

	public function test_an_explicit_division_field_beats_the_program_name(): void {
		// The program says C. The organiser said B. B wins.
		$match = $this->mapper()->map( '2026 Summer Classic (C Division)', 'B' );

		self::assertSame( 'b', $match->key );
		self::assertSame( 'division_field', $match->matched_on );
	}

	/**
	 * The whole reason DivisionMatch has three outcomes.
	 *
	 * A value we do not recognise is not the same as no value. Falling back to
	 * the program name here would publish this team in C Division, which nobody
	 * chose.
	 */
	public function test_an_unrecognised_division_field_is_held_and_never_inferred_past(): void {
		$match = $this->mapper()->map( '2026 Summer Classic (C Division)', 'Competitive Open' );

		self::assertSame( DivisionMatch::UNRECOGNISED, $match->outcome );
		self::assertNull( $match->key );
		self::assertSame( 'Competitive Open', $match->source_value );
	}

	public function test_whitespace_and_case_do_not_matter(): void {
		self::assertSame( 'd', $this->mapper()->map( '', "  \tD   DIVISION \n" )->key );
	}

	/**
	 * Longest alias first, so a map can be written in any order.
	 *
	 * The previous implementation needed compound entries declared before single
	 * letters, which worked until somebody added an entry in the wrong place.
	 */
	public function test_alias_length_decides_the_match_not_declaration_order(): void {
		$map = new DivisionMap( array(
			array( 'key' => 'd',       'label' => 'D',       'order' => 10, 'aliases' => array( 'd' ) ),
			array( 'key' => 'legends', 'label' => 'Legends', 'order' => 20, 'aliases' => array( 'legends d' ) ),
		) );

		self::assertSame( 'legends', $map->find_in( 'Fall 2026 Legends D' ) );
	}

	/**
	 * The case longest-first got wrong, found on a live Site.
	 *
	 * "legends d division" contains "d division", which is ten characters, while
	 * "legends d" is nine. Longest-alias-first therefore filed Legends teams
	 * under D. Nobody noticed because no Legends team had registered yet, which
	 * is exactly the kind of bug that surfaces on the busiest day of the season.
	 */
	#[DataProvider( 'compound_division_names' )]
	public function test_the_earliest_match_wins_not_the_longest( string $program, string $expected ): void {
		self::assertSame( $expected, Fixtures::division_map()->find_in( $program ), $program );
	}

	public static function compound_division_names(): iterable {
		yield 'the real one'      => array( '2026 Summer Classic (Legends D Division)', 'legends' );
		yield 'without brackets'  => array( 'Legends D Division 2026', 'legends' );
		yield 'masters variant'   => array( '2016 Masters D Division', 'legends' );
		yield 'plain D still D'   => array( '2026 Summer Classic (D Division)', 'd' );
		yield 'open D still D'    => array( 'Open D Division Fall 2026', 'd' );
		yield 'womens D is womens'=> array( "2018 Women's D/E Division", 'womens' );
	}

	/** Length still decides when two aliases start at the same place. */
	public function test_length_breaks_a_tie_at_the_same_position(): void {
		$map = new DivisionMap( array(
			array( 'key' => 'a',  'label' => 'A',      'order' => 10, 'aliases' => array( 'open' ) ),
			array( 'key' => 'ab', 'label' => 'A/B',    'order' => 20, 'aliases' => array( 'open a/b' ) ),
		) );

		self::assertSame( 'ab', $map->find_in( 'Open A/B Division' ) );
	}

	public function test_a_label_is_an_alias_of_itself(): void {
		$map = new DivisionMap( array(
			array( 'key' => 'masters', 'label' => 'Masters Division', 'order' => 10 ),
		) );

		self::assertSame( 'masters', $map->find_in( '2026 Masters Division' ) );
	}

	public function test_divisions_come_back_in_configured_order(): void {
		self::assertSame(
			array( 'ab', 'a', 'b', 'c', 'd', 'e', 'womens', 'legends' ),
			Fixtures::division_map()->keys_in_order()
		);
	}
}
