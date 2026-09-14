<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\MetroMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetroMapTest extends TestCase {

	private function map(): MetroMap {
		return new MetroMap( require dirname( __DIR__, 2 ) . '/presets/us-metros.php' );
	}

	/** Every one of these is a real value from a live tournament. */
	#[DataProvider( 'real_registrations' )]
	public function test_it_places_a_suburb_in_its_metro( string $city, string $state, string $expected ): void {
		self::assertSame( $expected, $this->map()->label( $city, $state ) );
	}

	public static function real_registrations(): iterable {
		yield 'Frisco'       => array( 'Frisco', 'TX', 'Dallas, TX' );
		yield 'Irving'       => array( 'Irving', 'TX', 'Dallas, TX' );
		yield 'Fort Worth'   => array( 'Fort Worth', 'Tx', 'Dallas, TX' );
		yield 'Chesterfield' => array( 'Chesterfield', 'MO', 'St. Louis, MO' );
		yield 'St Paul'      => array( 'St Paul', 'mN', 'Minneapolis, MN' );
		yield 'Austin'       => array( 'Austin', 'TX', 'Austin, TX' );
		yield 'Houston'      => array( 'Houston', 'Tx', 'Houston, TX' );
	}

	/** The source writes state codes three different ways on one tournament. */
	public function test_state_codes_are_normalised(): void {
		self::assertSame( 'Austin, TX', $this->map()->label( 'Austin', 'tx' ) );
		self::assertSame( 'Austin, TX', $this->map()->label( 'Austin', ' Tx ' ) );
	}

	public function test_a_malformed_state_is_dropped_rather_than_printed(): void {
		self::assertSame( 'Austin', $this->map()->label( 'Austin', 'Texas' ) );
		self::assertSame( 'Austin', $this->map()->label( 'Austin', '' ) );
		self::assertSame( 'Austin', $this->map()->label( 'Austin', 'T1' ) );
	}

	#[DataProvider( 'spellings' )]
	public function test_it_survives_how_people_actually_type( string $written ): void {
		self::assertSame( 'Minneapolis, MN', $this->map()->label( $written, 'MN' ) );
	}

	public static function spellings(): iterable {
		yield 'no full stop' => array( 'St Paul' );
		yield 'full stop'    => array( 'St. Paul' );
		yield 'spelled out'  => array( 'Saint Paul' );
		yield 'shouted'      => array( 'SAINT PAUL' );
		yield 'padded'       => array( '  st paul  ' );
	}

	/**
	 * An unknown town is published as written, tidied but not guessed at.
	 *
	 * Guessing a metro from an unknown name would put a team in the wrong city,
	 * which is worse than showing an unfamiliar one.
	 */
	public function test_an_unknown_town_falls_through_tidied(): void {
		self::assertSame( 'Smallville, KS', $this->map()->label( 'SMALLVILLE', 'KS' ) );
		self::assertFalse( $this->map()->knows( 'Smallville' ) );
	}

	public function test_no_city_means_no_label(): void {
		self::assertSame( '', $this->map()->label( '', 'TX' ) );
		self::assertSame( '', $this->map()->label( '   ', 'TX' ) );
	}

	public function test_the_preset_covers_the_leagues_this_is_for(): void {
		$map = $this->map();

		foreach ( array( 'Austin', 'Dallas', 'Houston', 'Oklahoma City', 'St Louis', 'Minneapolis', 'Atlanta', 'Phoenix', 'Seattle', 'New York' ) as $city ) {
			self::assertTrue( $map->knows( $city ), "$city missing from the preset" );
		}
	}
}
