<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\NameCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameCaseTest extends TestCase {

	#[DataProvider( 'shouted' )]
	public function test_it_calms_a_shouted_name( string $in, string $expected ): void {
		self::assertSame( $expected, NameCase::person( $in ) );
	}

	public static function shouted(): iterable {
		yield 'the real case'    => array( 'Mathew HALL', 'Mathew Hall' );
		yield 'short first name' => array( 'IAN SMITH', 'Ian Smith' );
		yield 'short both'       => array( 'BOB KIM', 'Bob Kim' );
		yield 'both names'       => array( 'JANE SMITH', 'Jane Smith' );
		yield 'hyphenated'       => array( 'ANNA SMITH-JONES', 'Anna Smith-Jones' );
		yield 'apostrophe'       => array( "SEAN O'BRIEN", "Sean O'Brien" );
		yield 'curly apostrophe' => array( "SEAN O\u{2019}BRIEN", "Sean O\u{2019}Brien" );
		yield 'mc prefix'        => array( 'IAN MCDONALD', 'Ian McDonald' );
		yield 'mac prefix'       => array( 'IAN MACLEOD', 'Ian MacLeod' );
		yield 'particle'         => array( 'PIET VAN DER BERG', 'Piet van der Berg' );
		yield 'accented'         => array( 'JOSÉ GARZA', 'José Garza' );
	}

	/**
	 * A name somebody typed deliberately is left alone.
	 *
	 * This is the half that matters. Naive title-casing turns McDonald into
	 * Mcdonald and JoAnne into Joanne, which is not tidying a name, it is
	 * getting it wrong.
	 */
	#[DataProvider( 'left_alone' )]
	public function test_it_leaves_a_deliberate_spelling_alone( string $name ): void {
		self::assertSame( $name, NameCase::person( $name ) );
	}

	public static function left_alone(): iterable {
		yield 'already correct'   => array( 'Mathew Hall' );
		yield 'mixed case mc'     => array( 'Ian McDonald' );
		yield 'internal capital'  => array( 'JoAnne Weaver' );
		yield 'lower particle'    => array( 'Piet van der Berg' );
		yield 'apostrophe intact' => array( "Sean O'Brien" );
		yield 'initials'          => array( 'J R Smith' );
		yield 'short shout'       => array( 'Bob JR' );
		yield 'roman numeral'     => array( 'Henry III' );
		yield 'two letters'       => array( 'Al MD' );
		yield 'digits'            => array( 'Team F5' );
		yield 'empty'             => array( '' );
	}

	/** Whitespace is preserved exactly, so nothing shifts on the page. */
	public function test_spacing_survives(): void {
		self::assertSame( 'Jane  Smith', NameCase::person( 'JANE  SMITH' ) );
	}

	/**
	 * The threshold is a heuristic and this records where it is wrong.
	 *
	 * A four-letter acronym is indistinguishable from a shouted short surname,
	 * and the rule chooses in favour of the surname because a person's name on a
	 * public page is the more common case and the more embarrassing to get wrong.
	 */
	public function test_a_four_letter_acronym_is_a_known_limit(): void {
		self::assertSame( 'Nasa', NameCase::person( 'NASA' ) );
	}

	/** Suffixes are protected by name, not by being short. */
	#[DataProvider( 'suffixes' )]
	public function test_a_suffix_keeps_its_capitals( string $name ): void {
		self::assertSame( $name, NameCase::person( $name ) );
	}

	public static function suffixes(): iterable {
		yield 'junior'   => array( 'Bob JR' );
		yield 'third'    => array( 'Henry III' );
		yield 'doctor'   => array( 'Ana MD' );
		yield 'initials' => array( 'J R Smith' );
		yield 'dotted'   => array( 'J. R. Smith' );
	}
}
