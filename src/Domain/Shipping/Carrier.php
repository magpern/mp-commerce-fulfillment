<?php
/**
 * One entry in the carrier registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Shipping;

/**
 * Immutable value object for a registered carrier. The filterable registry
 * (`mpcf_carriers`) lives in Infrastructure; this class is only the pure
 * data shape one entry takes. Definitions are never mutated after
 * construction — runtime merchant preferences belong in Settings.
 */
final class Carrier {

	/**
	 * Registry key.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Display label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Tracking-URL template with a `{tracking}` placeholder, or null when
	 * the carrier has no derived URL (e.g. "other").
	 *
	 * @var string|null
	 */
	private ?string $tracking_url_template;

	/**
	 * Optional warn-only tracking-number format hint (PCRE without delimiters
	 * wrapper — stored as the inner pattern body, e.g. `^[A-Z0-9]+$`).
	 *
	 * @var string|null
	 */
	private ?string $tracking_number_pattern;

	/**
	 * Whether a phone number is expected for this carrier at ship time.
	 *
	 * @var bool
	 */
	private bool $phone_required;

	/**
	 * Assembles a carrier. Use {@see define()}.
	 *
	 * @param string      $id                      Registry key.
	 * @param string      $label                   Display label.
	 * @param string|null $tracking_url_template   URL template or null.
	 * @param string|null $tracking_number_pattern Warn-only pattern or null.
	 * @param bool        $phone_required          Phone expected flag.
	 */
	private function __construct(
		string $id,
		string $label,
		?string $tracking_url_template,
		?string $tracking_number_pattern,
		bool $phone_required
	) {
		$this->id                      = $id;
		$this->label                   = $label;
		$this->tracking_url_template   = $tracking_url_template;
		$this->tracking_number_pattern = $tracking_number_pattern;
		$this->phone_required          = $phone_required;
	}

	/**
	 * Builds a carrier from a definition array (filter round-trip shape).
	 *
	 * @param array<string, mixed> $definition Definition fields.
	 */
	public static function define( array $definition ): self {
		$template = $definition['tracking_url_template'] ?? null;
		if ( null !== $template && ! is_string( $template ) ) {
			$template = null;
		}
		if ( is_string( $template ) && '' === $template ) {
			$template = null;
		}

		$pattern = $definition['tracking_number_pattern'] ?? null;
		if ( null !== $pattern && ! is_string( $pattern ) ) {
			$pattern = null;
		}
		if ( is_string( $pattern ) && '' === $pattern ) {
			$pattern = null;
		}

		return new self(
			(string) ( $definition['id'] ?? '' ),
			(string) ( $definition['label'] ?? '' ),
			$template,
			$pattern,
			(bool) ( $definition['phone_required'] ?? false )
		);
	}

	/**
	 * Whether this definition has every field required to register.
	 */
	public function is_valid(): bool {
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $this->id ) ) {
			return false;
		}

		if ( '' === $this->label ) {
			return false;
		}

		if ( null !== $this->tracking_url_template ) {
			if ( ! preg_match( '#^https://#i', $this->tracking_url_template ) ) {
				return false;
			}
			if ( ! str_contains( $this->tracking_url_template, '{tracking}' ) ) {
				return false;
			}
		}

		if ( null !== $this->tracking_number_pattern ) {
			// Reject delimiters / modifiers that would make @preg_match unsafe
			// when consumers wrap the body themselves.
			if ( strlen( $this->tracking_number_pattern ) > 256 ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe only; invalid patterns are rejected.
			$ok = @preg_match( '/' . $this->tracking_number_pattern . '/', '' );
			if ( false === $ok ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Array shape for filters, REST, and tests.
	 *
	 * @return array{
	 *     id: string,
	 *     label: string,
	 *     tracking_url_template: string|null,
	 *     tracking_number_pattern: string|null,
	 *     phone_required: bool
	 * }
	 */
	public function to_array(): array {
		return array(
			'id'                      => $this->id,
			'label'                   => $this->label,
			'tracking_url_template'   => $this->tracking_url_template,
			'tracking_number_pattern' => $this->tracking_number_pattern,
			'phone_required'          => $this->phone_required,
		);
	}

	/**
	 * Registry key.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Display label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Tracking-URL template, or null.
	 */
	public function tracking_url_template(): ?string {
		return $this->tracking_url_template;
	}

	/**
	 * Warn-only tracking-number pattern body, or null.
	 */
	public function tracking_number_pattern(): ?string {
		return $this->tracking_number_pattern;
	}

	/**
	 * Whether a phone number is expected.
	 */
	public function phone_required(): bool {
		return $this->phone_required;
	}
}
