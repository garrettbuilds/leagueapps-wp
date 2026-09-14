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
