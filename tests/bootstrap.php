<?php
/**
 * Unit suite bootstrap: Composer autoload only.
 *
 * Everything under src/Domain, src/Service and src/Contracts is plain PHP with
 * no WordPress functions in it, which is what makes this possible. If a test in
 * tests/Unit ever needs WordPress bootstrapped, the class under test is in the
 * wrong layer.
 *
 * The integration suite loads the WordPress test library from its own bootstrap
 * (tests/Integration/bootstrap.php) so the fast suite never pays for it.
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Escaping and translation polyfills, for the renderer only.
 *
 * The renderer is the one class in src/ that legitimately calls WordPress, because
 * escaping at the point of output is not something to reimplement: esc_html has
 * handled character-set edge cases for fifteen years and a hand-rolled substitute
 * would be a security regression dressed as purity.
 *
 * So the unit suite polyfills the six functions it uses rather than bootstrapping
 * WordPress for them. These are close enough to assert escaping behaviour and are
 * never loaded in production, where the real ones exist. Anything relying on
 * WordPress BEHAVIOUR rather than WordPress escaping belongs in the integration
 * suite.
 */
foreach ( array( 'esc_html', 'esc_attr' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}( \$text ) { return htmlspecialchars( (string) \$text, ENT_QUOTES, 'UTF-8' ); }" );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = trim( (string) $url );

		// Only the schemes a link may use. A javascript: href in a team name or a
		// settings field must not survive this.
		if ( '' !== $url && ! preg_match( '#^(https?:)?//#i', $url ) && ! str_starts_with( $url, '/' ) ) {
			return '';
		}

		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { return $text; }
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
}
