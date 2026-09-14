<?php
/**
 * Letter-graded divisions, the common shape in adult recreational softball.
 *
 * A STARTING POINT, NOT A DEFAULT. Nothing loads this unless an operator picks
 * it in the wizard, and the wizard still shows every value the Site actually
 * returned next to it so unmatched values are visible before anything is saved.
 *
 * The A/B entry exists because some leagues run a combined A/B division and some
 * run them separately. Leave it in if you run one, remove it if you do not: with
 * it present, a program named "A/B Division" matches the combined entry rather
 * than A, because aliases are matched longest first.
 */

return array(
	array( 'key' => 'ab',      'label' => 'A/B Division',     'order' => 10, 'aliases' => array( 'a/b', 'a and b', 'ab' ) ),
	array( 'key' => 'a',       'label' => 'A Division',       'order' => 20, 'aliases' => array( 'open a', 'division a', 'a' ) ),
	array( 'key' => 'b',       'label' => 'B Division',       'order' => 30, 'aliases' => array( 'open b', 'division b', 'b' ) ),
	array( 'key' => 'c',       'label' => 'C Division',       'order' => 40, 'aliases' => array( 'open c', 'division c', 'c' ) ),
	array( 'key' => 'd',       'label' => 'D Division',       'order' => 50, 'aliases' => array( 'open d', 'division d', 'd' ) ),
	array( 'key' => 'e',       'label' => 'E Division',       'order' => 60, 'aliases' => array( 'open e', 'division e', 'e' ) ),
	array( 'key' => 'womens',  'label' => "Women's Division", 'order' => 70, 'aliases' => array( "women's", 'womens', 'women' ) ),
	/*
	 * One Legends division rather than two.
	 *
	 * A LEAGUE MAY WANT THEM UNDER D INSTEAD, and that is configuration, not a
	 * rule this file should encode. In International Pride Softball, Legends is
	 * the 50-and-over bracket and often has a single team, so it plays within D
	 * Division. Other organisations do not do that.
	 *
	 * To publish them that way, give the Legends program the heading "D Division"
	 * on the setup screen. Several programs can share one heading; each program
	 * name simply becomes another alias of it. Nothing in the plugin needs to
	 * know about the convention.
	 *
	 * Source data is often inconsistent here: a league that renamed Masters to
	 * Legends has both in its history, and may also carry "Legends D" from when
	 * the division was graded. Collapsing them keeps a decade of programs under
	 * one heading instead of splitting it across near-duplicate labels.
	 *
	 * If a Site genuinely runs Legends AND Legends D as separate competitions,
	 * split this into two entries.
	 */
	array( 'key' => 'legends', 'label' => 'Legends', 'order' => 80, 'aliases' => array( 'legends d', 'legend d', 'legends-d', 'legends', 'masters d', 'masters', 'master d', 'master' ) ),
);
