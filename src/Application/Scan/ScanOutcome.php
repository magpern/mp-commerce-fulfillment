<?php
/**
 * Outcome of one ScanService operation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Scan;

use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Scan\ScanResolution;

/**
 * Rich result for resolve / pick / pack / undo — controllers map failures
 * through {@see \MPCF\Api\Rest\AbstractRestController::failure_error()}.
 */
final class ScanOutcome {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * Machine-readable result / failure code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Operator-readable message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Fulfillment version after success, or unchanged on pure resolve, or null on failure.
	 *
	 * @var int|null
	 */
	private ?int $version;

	/**
	 * Resolved / mutated item.
	 *
	 * @var FulfillmentItem|null
	 */
	private ?FulfillmentItem $item;

	/**
	 * All fulfillment lines after the operation (success paths).
	 *
	 * @var array<int, FulfillmentItem>
	 */
	private array $items;

	/**
	 * Underlying resolution when available.
	 *
	 * @var ScanResolution|null
	 */
	private ?ScanResolution $resolution;

	/**
	 * Whether every line is complete for the active mode.
	 *
	 * @var bool
	 */
	private bool $stage_complete;

	/**
	 * Active package id echoed back when relevant.
	 *
	 * @var int|null
	 */
	private ?int $active_package_id;

	/**
	 * Progress summary.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $progress;

	/**
	 * Assembles an outcome.
	 *
	 * @param bool                        $succeeded          Success flag.
	 * @param string                      $code               Result code.
	 * @param string                      $message            Operator message.
	 * @param int|null                    $version            Fulfillment version.
	 * @param FulfillmentItem|null        $item               Focus item.
	 * @param array<int, FulfillmentItem> $items              All lines.
	 * @param ScanResolution|null         $resolution         Resolution detail.
	 * @param bool                        $stage_complete     Stage complete flag.
	 * @param int|null                    $active_package_id  Active package.
	 * @param array<string, int>|null     $progress           Progress counts.
	 */
	private function __construct(
		bool $succeeded,
		string $code,
		string $message,
		?int $version,
		?FulfillmentItem $item,
		array $items,
		?ScanResolution $resolution,
		bool $stage_complete,
		?int $active_package_id,
		?array $progress
	) {
		$this->succeeded         = $succeeded;
		$this->code              = $code;
		$this->message           = $message;
		$this->version           = $version;
		$this->item              = $item;
		$this->items             = $items;
		$this->resolution        = $resolution;
		$this->stage_complete    = $stage_complete;
		$this->active_package_id = $active_package_id;
		$this->progress          = $progress;
	}

	/**
	 * Successful outcome.
	 *
	 * @param string                      $code               Result code.
	 * @param string                      $message            Operator message.
	 * @param int                         $version            Fulfillment version.
	 * @param array<int, FulfillmentItem> $items              All lines.
	 * @param FulfillmentItem|null        $item               Focus item.
	 * @param ScanResolution|null         $resolution         Resolution.
	 * @param bool                        $stage_complete     Stage complete.
	 * @param int|null                    $active_package_id  Active package.
	 * @param array<string, int>|null     $progress           Progress.
	 */
	public static function succeeded(
		string $code,
		string $message,
		int $version,
		array $items,
		?FulfillmentItem $item = null,
		?ScanResolution $resolution = null,
		bool $stage_complete = false,
		?int $active_package_id = null,
		?array $progress = null
	): self {
		return new self( true, $code, $message, $version, $item, $items, $resolution, $stage_complete, $active_package_id, $progress );
	}

	/**
	 * Failed outcome.
	 *
	 * @param string              $code       Failure code.
	 * @param string              $message    Operator message.
	 * @param ScanResolution|null $resolution Optional resolution context.
	 */
	public static function failed( string $code, string $message, ?ScanResolution $resolution = null ): self {
		return new self( false, $code, $message, null, null, array(), $resolution, false, null, null );
	}

	/**
	 * Whether the operation succeeded.
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * Result / failure code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Failure code alias for REST mapping.
	 */
	public function failure_code(): ?string {
		return $this->succeeded ? null : $this->code;
	}

	/**
	 * Failure message alias for REST mapping.
	 */
	public function failure_message(): ?string {
		return $this->succeeded ? null : $this->message;
	}

	/**
	 * Operator message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Fulfillment version.
	 */
	public function version(): ?int {
		return $this->version;
	}

	/**
	 * Focus item.
	 */
	public function item(): ?FulfillmentItem {
		return $this->item;
	}

	/**
	 * All lines.
	 *
	 * @return array<int, FulfillmentItem>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Resolution detail.
	 */
	public function resolution(): ?ScanResolution {
		return $this->resolution;
	}

	/**
	 * Stage complete flag.
	 */
	public function stage_complete(): bool {
		return $this->stage_complete;
	}

	/**
	 * Active package id.
	 */
	public function active_package_id(): ?int {
		return $this->active_package_id;
	}

	/**
	 * Progress summary.
	 *
	 * @return array<string, int>|null
	 */
	public function progress(): ?array {
		return $this->progress;
	}
}
