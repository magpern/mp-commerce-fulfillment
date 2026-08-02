<?php
/**
 * Test double subscriber that records every event it receives.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Application\EventSubscriber;
use MPCF\Domain\Event\DomainEvent;

/**
 * Records every event it receives, in order.
 */
final class RecordingSubscriber implements EventSubscriber {

	/**
	 * @var array<int, DomainEvent>
	 */
	private array $received = array();

	public function handle( DomainEvent $event ): void {
		$this->received[] = $event;
	}

	/**
	 * @return array<int, DomainEvent>
	 */
	public function received(): array {
		return $this->received;
	}
}
