<?php
/**
 * Who (or what) caused a domain event.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Event;

/**
 * Immutable value object. `label` is a snapshot taken at the moment the
 * event is recorded — so the audit trail stays legible even after the
 * underlying user account is later deleted (`id` is nullable for exactly
 * that reason: erasure removes the reference, never the history).
 */
final class Actor {

	/**
	 * A human operator or lead.
	 */
	public const TYPE_USER = 'user';

	/**
	 * The plugin itself (a hook callback, a scheduled task).
	 */
	public const TYPE_SYSTEM = 'system';

	/**
	 * An external caller authenticated against the API.
	 */
	public const TYPE_API = 'api';

	/**
	 * One of the `TYPE_*` constants.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The acting user's id, or null for system/API actors and for a user
	 * actor whose account has since been erased.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Display-name snapshot.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Assembles an actor. Use the named factories ({@see user()},
	 * {@see system()}, {@see api()}) instead of calling this directly.
	 *
	 * @param string   $type  One of the `TYPE_*` constants.
	 * @param int|null $id    Acting user id, if any.
	 * @param string   $label Display-name snapshot.
	 */
	private function __construct( string $type, ?int $id, string $label ) {
		$this->type  = $type;
		$this->id    = $id;
		$this->label = $label;
	}

	/**
	 * Builds a user actor.
	 *
	 * @param int    $id    Acting user id.
	 * @param string $label Display-name snapshot.
	 */
	public static function user( int $id, string $label ): self {
		return new self( self::TYPE_USER, $id, $label );
	}

	/**
	 * Builds a system actor.
	 *
	 * @param string $label Display label.
	 */
	public static function system( string $label = 'System' ): self {
		return new self( self::TYPE_SYSTEM, null, $label );
	}

	/**
	 * Builds an API actor.
	 *
	 * @param string $label Display label.
	 */
	public static function api( string $label = 'API' ): self {
		return new self( self::TYPE_API, null, $label );
	}

	/**
	 * Rebuilds an actor from its array shape.
	 *
	 * @param array{type:string,id?:int|null,label:string} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self( (string) $data['type'], isset( $data['id'] ) ? (int) $data['id'] : null, (string) $data['label'] );
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array{type:string,id:int|null,label:string}
	 */
	public function to_array(): array {
		return array(
			'type'  => $this->type,
			'id'    => $this->id,
			'label' => $this->label,
		);
	}

	/**
	 * One of the `TYPE_*` constants.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The acting user's id, or null for system/API actors.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Display-name snapshot.
	 */
	public function label(): string {
		return $this->label;
	}
}
