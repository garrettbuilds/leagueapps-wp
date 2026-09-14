<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\Team;
use LeagueAppsWP\Domain\TeamsView;
use LeagueAppsWP\Presentation\TeamsRenderer;
use LeagueAppsWP\Service\TeamsViewBuilder;
use LeagueAppsWP\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class TeamsRendererTest extends TestCase {

	private function view( array $teams, ?string $synced = '2026-09-14T15:15:00+00:00', bool $stale = false ): TeamsView {
		$keyed = array();
		foreach ( $teams as $team ) {
			$keyed[ $team->source_team_id ] = $team;
		}

		return ( new TeamsViewBuilder() )->build( Fixtures::config(), $keyed, $synced, 1, $stale );
	}

	private function team( int $id, string $name, string $division = 'c', string $captain = '', int $roster = 1 ): Team {
		return new Team(
			event_key: Fixtures::EVENT,
			source_team_id: $id,
			name: $name,
			division_key: $division,
			division_label: strtoupper( $division ) . ' Division',
			roster_count: $roster,
			captain: $captain,
		);
	}

	public function test_it_groups_teams_under_division_headings_in_configured_order(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array(
			$this->team( 2, 'Night Owls', 'e' ),
			$this->team( 1, 'Riverside Rangers', 'a' ),
		) ) );

		self::assertLessThan(
			strpos( $html, 'E Division' ),
			strpos( $html, 'A Division' ),
			'divisions must follow the configured order, not the order teams arrived'
		);
	}

	public function test_teams_are_sorted_by_name_regardless_of_case(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array(
			$this->team( 1, 'zebras' ),
			$this->team( 2, 'Aardvarks' ),
		) ) );

		self::assertLessThan( strpos( $html, 'zebras' ), strpos( $html, 'Aardvarks' ) );
	}

	/** Team names come from an external system and are escaped at output. */
	public function test_a_team_name_cannot_inject_markup(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array(
			$this->team( 1, '<script>alert(1)</script>' ),
			$this->team( 2, 'Smith & Jones "A" Team' ),
		) ) );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringContainsString( 'Smith &amp; Jones', $html );
	}

	public function test_a_source_url_cannot_carry_a_javascript_scheme(): void {
		$html = ( new TeamsRenderer() )->render(
			$this->view( array( $this->team( 1, 'Rangers' ) ) ),
			array( 'source_url' => 'javascript:alert(1)' )
		);

		self::assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_jump_links_point_at_the_division_headings(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array(
			$this->team( 1, 'Rangers', 'a' ),
			$this->team( 2, 'Owls', 'c' ),
		) ) );

		preg_match_all( '/href="#([^"]+)"/', $html, $links );
		preg_match_all( '/id="([^"]+)"/', $html, $ids );

		self::assertNotEmpty( $links[1] );
		foreach ( $links[1] as $anchor ) {
			self::assertContains( $anchor, $ids[1], "jump link #$anchor has no heading to land on" );
		}
	}

	/**
	 * Two events on one page would otherwise both emit #division-a, and the
	 * jump link would silently go to whichever rendered first.
	 */
	public function test_anchors_are_scoped_to_the_event(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array( $this->team( 1, 'Rangers', 'a' ) ) ) );

		self::assertStringContainsString( 'id="summer-classic-2026-a"', $html );
	}

	public function test_one_division_gets_no_jump_navigation(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array( $this->team( 1, 'Rangers', 'a' ) ) ) );

		self::assertStringNotContainsString( '<nav', $html, 'a jump list to a single heading is noise' );
	}

	public function test_the_table_is_accessible(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array( $this->team( 1, 'Rangers' ) ) ) );

		self::assertStringContainsString( '<th scope="col">', $html );
		self::assertStringContainsString( '<th scope="row"', $html );
		self::assertStringContainsString( '<caption', $html );
	}

	/** A wide table must scroll inside its own box, not drag the page sideways. */
	public function test_the_table_sits_in_a_scroll_container(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array( $this->team( 1, 'Rangers' ) ) ) );

		self::assertStringContainsString( 'lawp-teams__scroll', $html );
		self::assertLessThan( strpos( $html, '<table' ), strpos( $html, 'lawp-teams__scroll' ) );
	}

	public function test_captains_appear_only_when_asked_for(): void {
		$view = $this->view( array( $this->team( 1, 'Rangers', 'c', 'Sam Okafor' ) ) );

		self::assertStringNotContainsString( 'Sam Okafor', ( new TeamsRenderer() )->render( $view ) );
		self::assertStringContainsString( 'Sam Okafor', ( new TeamsRenderer() )->render( $view, array( 'show_captain' => true ) ) );
	}

	public function test_before_the_first_sync_it_says_so_rather_than_drawing_an_empty_table(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array(), null ) );

		self::assertStringContainsString( 'being prepared', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	public function test_a_successful_sync_with_no_teams_says_something_different(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array() ) );

		self::assertStringContainsString( 'No teams are registered yet', $html );
	}

	/** A stale notice tells a visitor what to expect, never what broke. */
	public function test_a_stale_list_carries_no_technical_detail(): void {
		$html = ( new TeamsRenderer() )->render( $this->view( array( $this->team( 1, 'Rangers' ) ), '2026-09-14T15:15:00+00:00', true ) );

		self::assertStringContainsString( 'may take a short time to appear', $html );
		foreach ( array( 'HTTP', 'error', 'failed', 'timeout', 'api' ) as $leak ) {
			self::assertStringNotContainsStringIgnoringCase( $leak, $html );
		}
	}

	public function test_heading_level_is_clamped_to_valid_html(): void {
		$view = $this->view( array( $this->team( 1, 'Rangers' ) ) );

		$html = ( new TeamsRenderer() )->render( $view, array( 'heading' => 'Teams', 'heading_level' => 9 ) );

		self::assertStringContainsString( '<h6 class="lawp-teams__heading"', $html );
		self::assertStringNotContainsString( '<h9', $html );
	}

	/** A team the planner held has no division and must never reach a page. */
	public function test_a_team_with_no_division_is_not_rendered(): void {
		$held = new Team(
			event_key: Fixtures::EVENT,
			source_team_id: 99,
			name: 'Unmapped Team',
			division_key: null,
			division_label: null,
		);

		$html = ( new TeamsRenderer() )->render( $this->view( array( $held ) ) );

		self::assertStringNotContainsString( 'Unmapped Team', $html );
	}

	public function test_empty_divisions_are_hidden_unless_asked_for(): void {
		$teams = array( 1 => $this->team( 1, 'Rangers', 'a' ) );

		$hidden = ( new TeamsViewBuilder() )->build( Fixtures::config(), $teams, null, 1 );
		$shown  = ( new TeamsViewBuilder( true ) )->build( Fixtures::config(), $teams, null, 1 );

		self::assertCount( 1, $hidden->divisions );
		self::assertCount( 8, $shown->divisions );
	}
}
