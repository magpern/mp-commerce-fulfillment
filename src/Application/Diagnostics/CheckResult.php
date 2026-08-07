<?php
/**
 * Structured diagnostic result (Part XII).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Immutable check outcome. No secrets in metadata.
 */
final class CheckResult {

	/**
	 * Stable machine id (e.g. schema.migrator_version).
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * CheckCategory::* value.
	 *
	 * @var string
	 */
	private string $category;

	/**
	 * CheckStatus::* value.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Human summary.
	 *
	 * @var string
	 */
	private string $summary;

	/**
	 * Longer detail (safe for support).
	 *
	 * @var string
	 */
	private string $details;

	/**
	 * Actionable next step.
	 *
	 * @var string
	 */
	private string $remediation;

	/**
	 * Whether a bounded repair CLI exists.
	 *
	 * @var bool
	 */
	private bool $repairable;

	/**
	 * Structured non-secret detail.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata;

	/**
	 * Builds one result.
	 *
	 * @param string               $id          Stable machine id.
	 * @param string               $category    CheckCategory::* value.
	 * @param string               $status      CheckStatus::* value.
	 * @param string               $summary     Human summary.
	 * @param string               $details     Longer detail.
	 * @param string               $remediation Actionable next step.
	 * @param bool                 $repairable  Whether a bounded repair exists.
	 * @param array<string, mixed> $metadata    Structured non-secret detail.
	 */
	public function __construct(
		string $id,
		string $category,
		string $status,
		string $summary,
		string $details = '',
		string $remediation = '',
		bool $repairable = false,
		array $metadata = array()
	) {
		$this->id          = $id;
		$this->category    = $category;
		$this->status      = $status;
		$this->summary     = $summary;
		$this->details     = $details;
		$this->remediation = $remediation;
		$this->repairable  = $repairable;
		$this->metadata    = $metadata;
	}

	/**
	 * Stable machine id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Check category.
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Check status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Derived severity for Site Health / reporting.
	 */
	public function severity(): string {
		return CheckSeverity::from_status( $this->status );
	}

	/**
	 * Human summary.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Longer detail.
	 */
	public function details(): string {
		return $this->details;
	}

	/**
	 * Remediation hint.
	 */
	public function remediation(): string {
		return $this->remediation;
	}

	/**
	 * Whether repairable via CLI.
	 */
	public function repairable(): bool {
		return $this->repairable;
	}

	/**
	 * Structured metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Array shape for JSON CLI / Site Health.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'category'    => $this->category,
			'status'      => $this->status,
			'severity'    => $this->severity(),
			'summary'     => $this->summary,
			'details'     => $this->details,
			'remediation' => $this->remediation,
			'repairable'  => $this->repairable,
			'metadata'    => $this->metadata,
		);
	}

	/**
	 * Convenience constructor for a passing check.
	 *
	 * @param string               $id       Stable machine id.
	 * @param string               $category Check category.
	 * @param string               $summary  Human summary.
	 * @param array<string, mixed> $metadata Optional metadata.
	 */
	public static function pass( string $id, string $category, string $summary, array $metadata = array() ): self {
		return new self( $id, $category, CheckStatus::PASS, $summary, '', '', false, $metadata );
	}

	/**
	 * Convenience constructor for a warning.
	 *
	 * @param string               $id          Stable machine id.
	 * @param string               $category    Check category.
	 * @param string               $summary     Human summary.
	 * @param string               $details     Longer detail.
	 * @param string               $remediation Remediation hint.
	 * @param bool                 $repairable  Whether repairable.
	 * @param array<string, mixed> $metadata    Optional metadata.
	 */
	public static function warn(
		string $id,
		string $category,
		string $summary,
		string $details = '',
		string $remediation = '',
		bool $repairable = false,
		array $metadata = array()
	): self {
		return new self( $id, $category, CheckStatus::WARN, $summary, $details, $remediation, $repairable, $metadata );
	}

	/**
	 * Convenience constructor for a failure.
	 *
	 * @param string               $id          Stable machine id.
	 * @param string               $category    Check category.
	 * @param string               $summary     Human summary.
	 * @param string               $details     Longer detail.
	 * @param string               $remediation Remediation hint.
	 * @param bool                 $repairable  Whether repairable.
	 * @param array<string, mixed> $metadata    Optional metadata.
	 */
	public static function fail(
		string $id,
		string $category,
		string $summary,
		string $details = '',
		string $remediation = '',
		bool $repairable = false,
		array $metadata = array()
	): self {
		return new self( $id, $category, CheckStatus::FAIL, $summary, $details, $remediation, $repairable, $metadata );
	}
}
