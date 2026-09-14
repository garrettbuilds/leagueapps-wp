<?php
/**
 * Accumulated errors and warnings, with codes rather than prose.
 *
 * Codes because three audiences read these: an operator in wp-admin, a cron log,
 * and a test asserting a specific failure. Prose serves the first and fails the
 * other two.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class ValidationResult {

	private array $errors   = array();
	private array $warnings = array();
	private array $meta     = array();

	public function error( string $code, string $message, array $context = array() ): self {
		$this->errors[] = array( 'code' => $code, 'message' => $message ) + $context;
		return $this;
	}

	public function warn( string $code, string $message, array $context = array() ): self {
		$this->warnings[] = array( 'code' => $code, 'message' => $message ) + $context;
		return $this;
	}

	public function note( string $key, mixed $value ): self {
		$this->meta[ $key ] = $value;
		return $this;
	}

	public function is_valid(): bool {
		return array() === $this->errors;
	}

	public function has_warnings(): bool {
		return array() !== $this->warnings;
	}

	public function errors(): array {
		return $this->errors;
	}

	public function warnings(): array {
		return $this->warnings;
	}

	public function meta(): array {
		return $this->meta;
	}

	public function has_error_code( string $code ): bool {
		foreach ( $this->errors as $error ) {
			if ( $code === $error['code'] ) {
				return true;
			}
		}
		return false;
	}

	public function has_warning_code( string $code ): bool {
		foreach ( $this->warnings as $warning ) {
			if ( $code === $warning['code'] ) {
				return true;
			}
		}
		return false;
	}

	public function merge( self $other ): self {
		$this->errors   = array_merge( $this->errors, $other->errors );
		$this->warnings = array_merge( $this->warnings, $other->warnings );
		$this->meta     = array_merge( $this->meta, $other->meta );
		return $this;
	}
}
