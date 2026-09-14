<?php
/**
 * The public HTML. Plain semantic markup, no builder required.
 *
 * Core blocks and core markup on purpose: this has to render the same inside
 * Kadence, a block theme, a classic theme, or a shortcode dropped into a page
 * builder nobody here has heard of. A renderer that assumed one builder would be
 * useless to most of the people this plugin is published for.
 *
 * Everything that reaches output is escaped at the point of output, not on the
 * way in. Team names come from an external system and may legitimately contain
 * an ampersand or an apostrophe.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Presentation;

use LeagueAppsWP\Domain\TeamsView;

final class TeamsRenderer {

	/**
	 * @param array $options heading, heading_level, show_jump_links, show_counts,
	 *                       show_captain, show_last_updated, source_url.
	 */
	public function render( TeamsView $view, array $options = array() ): string {
		$options = array_merge( array(
			'heading'           => '',
			'heading_level'     => 2,
			'show_jump_links'   => true,
			'show_counts'       => true,
			'show_captain'      => false,
			'show_last_updated' => true,
			'source_url'        => '',
			'source_label'      => __( 'View on LeagueApps', 'leagueapps-wp' ),
		), $options );

		if ( $view->never_synced() || $view->is_empty() ) {
			return $this->empty_state( $view, $options );
		}

		$level = min( 6, max( 2, (int) $options['heading_level'] ) );
		$out   = array();

		$out[] = '<div class="lawp-teams" data-event="' . esc_attr( $view->event_key ) . '">';

		if ( '' !== $options['heading'] ) {
			$out[] = sprintf(
				'<h%1$d class="lawp-teams__heading">%2$s</h%1$d>',
				$level,
				esc_html( (string) $options['heading'] )
			);
		}

		if ( $options['show_jump_links'] && count( $view->divisions ) > 1 ) {
			$out[] = $this->jump_links( $view, (bool) $options['show_counts'] );
		}

		foreach ( $view->divisions as $division ) {
			$out[] = $this->division( $division, $level + 1, $options );
		}

		if ( $options['show_last_updated'] ) {
			$out[] = $this->updated( $view, $options );
		}

		$out[] = '</div>';

		return implode( "\n", $out );
	}

	private function jump_links( TeamsView $view, bool $show_counts ): string {
		$items = array();

		foreach ( $view->divisions as $division ) {
			$label = $division['label'];

			if ( $show_counts ) {
				$label .= ' (' . (int) $division['count'] . ')';
			}

			$items[] = sprintf(
				'<li class="lawp-teams__jump-item"><a class="lawp-teams__jump-link" href="#%s">%s</a></li>',
				esc_attr( $division['anchor'] ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<nav class="lawp-teams__jump" aria-label="%s"><ul class="lawp-teams__jump-list">%s</ul></nav>',
			esc_attr__( 'Jump to a division', 'leagueapps-wp' ),
			implode( '', $items )
		);
	}

	private function division( array $division, int $level, array $options ): string {
		$level = min( 6, $level );
		$out   = array();

		$out[] = '<section class="lawp-teams__division">';

		// The id lives on the heading, so a jump link lands on the heading and a
		// screen reader announces what the visitor just moved to.
		$out[] = sprintf(
			'<h%1$d class="lawp-teams__division-heading" id="%2$s">%3$s</h%1$d>',
			$level,
			esc_attr( $division['anchor'] ),
			esc_html( $division['label'] )
		);

		if ( array() === $division['teams'] ) {
			$out[] = '<p class="lawp-teams__none">' . esc_html__( 'No teams registered yet.', 'leagueapps-wp' ) . '</p>';
			$out[] = '</section>';
			return implode( "\n", $out );
		}

		$columns = array( esc_html__( 'Team', 'leagueapps-wp' ) );

		if ( $options['show_captain'] ) {
			$columns[] = esc_html__( 'Manager', 'leagueapps-wp' );
		}

		if ( $options['show_counts'] ) {
			$columns[] = esc_html__( 'Players', 'leagueapps-wp' );
		}

		$head = '';
		foreach ( $columns as $column ) {
			$head .= '<th scope="col">' . $column . '</th>';
		}

		$rows = '';
		foreach ( $division['teams'] as $team ) {
			// th scope="row" on the name, so a screen reader reading across the
			// row says the team before it says anything about the team.
			$rows .= '<tr><th scope="row" class="lawp-teams__name">' . esc_html( $team['name'] ) . '</th>';

			if ( $options['show_captain'] ) {
				$rows .= '<td class="lawp-teams__captain">' . esc_html( $team['captain'] ) . '</td>';
			}

			if ( $options['show_counts'] ) {
				$rows .= '<td class="lawp-teams__count">' . (int) $team['roster_count'] . '</td>';
			}

			$rows .= '</tr>';
		}

		/*
		 * The scroll container is not decoration.
		 *
		 * A three-column table of team names overflows at 320px, and an
		 * overflowing table drags the whole page sideways. Scrolling it inside
		 * its own box keeps the body still.
		 */
		$out[] = sprintf(
			'<div class="lawp-teams__scroll"><table class="lawp-teams__table">'
			. '<caption class="screen-reader-text">%s</caption>'
			. '<thead><tr>%s</tr></thead><tbody>%s</tbody></table></div>',
			esc_attr( sprintf(
				/* translators: %s: division name. */
				__( 'Teams registered in %s', 'leagueapps-wp' ),
				$division['label']
			) ),
			$head,
			$rows
		);

		$out[] = '</section>';

		return implode( "\n", $out );
	}

	private function updated( TeamsView $view, array $options ): string {
		$out = array( '<p class="lawp-teams__updated">' );

		$out[] = esc_html( sprintf(
			/* translators: %s: formatted date and time. */
			__( 'Updated from LeagueApps on %s.', 'leagueapps-wp' ),
			$this->local_time( $view->last_sync_at )
		) );

		/*
		 * A stale notice says what a visitor can do, not what went wrong.
		 *
		 * "HTTP 503 from the source" is true, useless to the reader, and tells an
		 * attacker something about the stack. The list on screen is still the
		 * last one that passed every check, so the honest thing to say is that it
		 * may be a little behind.
		 */
		if ( $view->is_stale ) {
			$out[] = ' ' . esc_html__( 'Recent registrations may take a short time to appear.', 'leagueapps-wp' );
		}

		if ( '' !== $options['source_url'] ) {
			$out[] = sprintf(
				' <a class="lawp-teams__source" href="%s" rel="noopener">%s</a>',
				esc_url( (string) $options['source_url'] ),
				esc_html( (string) $options['source_label'] )
			);
		}

		$out[] = '</p>';

		return implode( '', $out );
	}

	/**
	 * Never an empty table.
	 *
	 * A heading with nothing under it reads as a broken page. Before the first
	 * successful sync there is genuinely nothing to show, and saying so plainly
	 * with a link onward is more use than an empty grid.
	 */
	private function empty_state( TeamsView $view, array $options ): string {
		$message = $view->never_synced()
			? __( 'Team information is being prepared.', 'leagueapps-wp' )
			: __( 'No teams are registered yet.', 'leagueapps-wp' );

		$out = '<div class="lawp-teams lawp-teams--empty"><p>' . esc_html( $message );

		if ( '' !== $options['source_url'] ) {
			$out .= ' ' . sprintf(
				'<a class="lawp-teams__source" href="%s" rel="noopener">%s</a>',
				esc_url( (string) $options['source_url'] ),
				esc_html( (string) $options['source_label'] )
			);
		}

		return $out . '</p></div>';
	}

	private function local_time( ?string $utc ): string {
		if ( null === $utc ) {
			return '';
		}

		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date(
				get_option( 'date_format' ) . ', ' . get_option( 'time_format' ),
				strtotime( $utc ) ?: null
			);
		}

		return $utc;
	}
}
