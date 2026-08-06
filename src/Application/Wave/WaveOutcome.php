<?php
/**
 * Outcome of one WaveService / WaveScanService operation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Wave;

use MPCF\Domain\Wave\Wave;

/**
 * Same outcome shape as PackingOutcome / ScanOutcome.
 */
final class WaveOutcome {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private bool $succeeded;

	/**
	 * Wave after the operation, or null on failure.
	 *
	 * @var Wave|null
	 */
	private ?Wave $wave;

	/**
	 * Machine success/result code.
	 *
	 * @var string|null
	 */
	private ?string $code;

	/**
	 * Human message.
	 *
	 * @var string|null
	 */
	private ?string $message;

	/**
	 * Extra payload (scan progress, walk, etc.).
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Machine-readable failure code.
	 *
	 * @var string|null
	 */
	private ?string $failure_code;

	/**
	 * Human-readable failure message.
	 *
	 * @var string|null
	 */
	private ?string $failure_message;

	/**
	 * Assembles an outcome.
	 *
	 * @param bool                 $succeeded       Success flag.
	 * @param Wave|null            $wave            Wave when success.
	 * @param string|null          $code            Result code.
	 * @param string|null          $message         Message.
	 * @param array<string, mixed> $data            Extra data.
	 * @param string|null          $failure_code    Failure code.
	 * @param string|null          $failure_message Failure message.
	 */
	private function __construct(
		bool $succeeded,
		?Wave $wave,
		?string $code,
		?string $message,
		array $data,
		?string $failure_code,
		?string $failure_message
	) {
		$this->succeeded       = $succeeded;
		$this->wave            = $wave;
		$this->code            = $code;
		$this->message         = $message;
		$this->data            = $data;
		$this->failure_code    = $failure_code;
		$this->failure_message = $failure_message;
	}

	/**
	 * Success outcome.
	 *
	 * @param Wave                 $wave    Wave after mutation.
	 * @param string               $code    Result code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $data    Extra data.
	 */
	public static function succeeded( Wave $wave, string $code = 'ok', string $message = 'OK', array $data = array() ): self {
		return new self( true, $wave, $code, $message, $data, null, null );
	}

	/**
	 * Failure outcome.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 */
	public static function failed( string $code, string $message ): self {
		return new self( false, null, null, null, array(), $code, $message );
	}

	/**
	 * Whether succeeded.
	 */
	public function is_success(): bool {
		return $this->succeeded;
	}

	/**
	 * Wave after success.
	 */
	public function wave(): ?Wave {
		return $this->wave;
	}

	/**
	 * Result code.
	 */
	public function code(): ?string {
		return $this->code;
	}

	/**
	 * Message.
	 */
	public function message(): ?string {
		return $this->message;
	}

	/**
	 * Extra data.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Failure code.
	 */
	public function failure_code(): ?string {
		return $this->failure_code;
	}

	/**
	 * Failure message.
	 */
	public function failure_message(): ?string {
		return $this->failure_message;
	}
}
