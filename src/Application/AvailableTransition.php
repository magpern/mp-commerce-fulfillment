<?php
/**
 * One candidate next state for a fulfillment, with its real eligibility.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Engine\TransitionResult;

/**
 * The one shape {@see WorkflowService::available_transitions()} produces
 * and every consumer renders from — the Fulfillment Detail screen, the
 * Packing Workspace, and `GET /mpcf/v1/fulfillments/{id}/transitions`
 * alike (Architecture Plan §IV.3.B: "One rule source, three consumers").
 */
final class AvailableTransition {

	/**
	 * Candidate target state key.
	 *
	 * @var string
	 */
	private string $target;

	/**
	 * Target state's display label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Whether the engine currently approves this transition.
	 *
	 * @var bool
	 */
	private bool $approved;

	/**
	 * Machine-readable rejection code, if not approved.
	 *
	 * @var string|null
	 */
	private ?string $rejection_code;

	/**
	 * Human-readable rejection message, if not approved.
	 *
	 * @var string|null
	 */
	private ?string $rejection_message;

	/**
	 * Whether taking this edge requires an audited free-text reason.
	 *
	 * @var bool
	 */
	private bool $requires_reason;

	/**
	 * Capability an actor must hold to take this edge.
	 *
	 * @var string
	 */
	private string $required_capability;

	/**
	 * Assembles an available transition. Use {@see from_result()} instead
	 * of calling this directly.
	 *
	 * @param string      $target              Candidate target state key.
	 * @param string      $label               Target state's display label.
	 * @param bool        $approved            Whether the engine currently approves this transition.
	 * @param string|null $rejection_code      Machine-readable rejection code, if not approved.
	 * @param string|null $rejection_message   Human-readable rejection message, if not approved.
	 * @param bool        $requires_reason     Whether taking this edge requires an audited reason.
	 * @param string      $required_capability Capability an actor must hold to take this edge.
	 */
	private function __construct(
		string $target,
		string $label,
		bool $approved,
		?string $rejection_code,
		?string $rejection_message,
		bool $requires_reason,
		string $required_capability
	) {
		$this->target              = $target;
		$this->label               = $label;
		$this->approved            = $approved;
		$this->rejection_code      = $rejection_code;
		$this->rejection_message   = $rejection_message;
		$this->requires_reason     = $requires_reason;
		$this->required_capability = $required_capability;
	}

	/**
	 * Builds an available transition from the engine's real decision.
	 *
	 * @param string           $target              Candidate target state key.
	 * @param string           $label               Target state's display label.
	 * @param TransitionResult $result              The engine's decision for this attempt.
	 * @param bool             $requires_reason     Whether taking this edge requires an audited reason.
	 * @param string           $required_capability Capability an actor must hold to take this edge.
	 */
	public static function from_result(
		string $target,
		string $label,
		TransitionResult $result,
		bool $requires_reason,
		string $required_capability
	): self {
		return new self(
			$target,
			$label,
			$result->is_approved(),
			$result->is_approved() ? null : (string) $result->rejection_code(),
			$result->is_approved() ? null : (string) $result->rejection_message(),
			$requires_reason,
			$required_capability
		);
	}

	/**
	 * Candidate target state key.
	 */
	public function target(): string {
		return $this->target;
	}

	/**
	 * Target state's display label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Whether the engine currently approves this transition.
	 */
	public function is_approved(): bool {
		return $this->approved;
	}

	/**
	 * Machine-readable rejection code, if not approved.
	 */
	public function rejection_code(): ?string {
		return $this->rejection_code;
	}

	/**
	 * Human-readable rejection message, if not approved.
	 */
	public function rejection_message(): ?string {
		return $this->rejection_message;
	}

	/**
	 * Whether taking this edge requires an audited free-text reason.
	 */
	public function requires_reason(): bool {
		return $this->requires_reason;
	}

	/**
	 * Capability an actor must hold to take this edge.
	 */
	public function required_capability(): string {
		return $this->required_capability;
	}

	/**
	 * The array shape a REST response or admin view renders from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'target'              => $this->target,
			'label'               => $this->label,
			'approved'            => $this->approved,
			'rejection_code'      => $this->rejection_code,
			'rejection_message'   => $this->rejection_message,
			'requires_reason'     => $this->requires_reason,
			'required_capability' => $this->required_capability,
		);
	}
}
