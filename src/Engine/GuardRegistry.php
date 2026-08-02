<?php
/**
 * Resolves a {@see \MPCF\Domain\Workflow\Transition}'s guard identifiers to
 * real {@see TransitionGuard} implementations.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine;

use InvalidArgumentException;
use MPCF\Engine\Guard\AllItemsPackedGuard;
use MPCF\Engine\Guard\AllItemsPickedGuard;
use MPCF\Engine\Guard\HasShipmentGuard;
use MPCF\Engine\Guard\HasTrackingGuard;
use MPCF\Engine\Guard\PackageSpecPresentGuard;
use MPCF\Engine\Guard\PhotoRequiredGuard;

/**
 * An unknown guard id is a workflow-data configuration bug (a
 * {@see \MPCF\Domain\Workflow\WorkflowDefinition} naming a guard nothing
 * implements) — {@see get()} throws rather than silently treating it as
 * satisfied, since a guard that can't run must never be mistaken for a
 * guard that passed.
 */
final class GuardRegistry {

	/**
	 * Registered guards, keyed by id.
	 *
	 * @var array<string, TransitionGuard>
	 */
	private array $guards;

	/**
	 * Builds the registry, indexing every guard by its own id.
	 *
	 * @param array<int, TransitionGuard> $guards Guards to index by id.
	 */
	public function __construct( array $guards ) {
		$indexed = array();

		foreach ( $guards as $guard ) {
			$indexed[ $guard->id() ] = $guard;
		}

		$this->guards = $indexed;
	}

	/**
	 * The registry wired with every standard-workflow guard.
	 */
	public static function standard(): self {
		return new self(
			array(
				new AllItemsPickedGuard(),
				new AllItemsPackedGuard(),
				new PackageSpecPresentGuard(),
				new HasShipmentGuard(),
				new HasTrackingGuard(),
				new PhotoRequiredGuard(),
			)
		);
	}

	/**
	 * Whether a guard id is registered.
	 *
	 * @param string $id Guard id.
	 */
	public function has( string $id ): bool {
		return isset( $this->guards[ $id ] );
	}

	/**
	 * The guard registered for an id.
	 *
	 * @param string $id Guard id.
	 * @throws InvalidArgumentException When no guard is registered for `$id`.
	 */
	public function get( string $id ): TransitionGuard {
		if ( ! isset( $this->guards[ $id ] ) ) {
			throw new InvalidArgumentException( "Unknown transition guard \"{$id}\"." );
		}

		return $this->guards[ $id ];
	}
}
