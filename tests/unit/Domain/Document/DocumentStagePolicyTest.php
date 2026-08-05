<?php
/**
 * Tests for document stage eligibility.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Document;

use DateTimeImmutable;
use MPCF\Documents\DocumentTypeRegistry;
use MPCF\Domain\Document\DocumentStagePolicy;
use MPCF\Domain\Document\DocumentType;
use MPCF\Domain\Fulfillment;
use PHPUnit\Framework\TestCase;

/**
 * Stage-policy matrix for packing_slip and picking_list.
 */
final class DocumentStagePolicyTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function packing_slip_matrix(): array {
		$cases = array();
		foreach ( DocumentTypeRegistry::PACKING_SLIP_STATES as $state ) {
			$cases[ 'packing_slip allows ' . $state ] = array( 'packing_slip', $state, true );
		}
		foreach ( DocumentTypeRegistry::PICKING_LIST_STATES as $state ) {
			$cases[ 'packing_slip denies ' . $state ] = array( 'packing_slip', $state, false );
		}
		$cases['packing_slip denies cancelled'] = array( 'packing_slip', 'cancelled', false );

		return $cases;
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function picking_list_matrix(): array {
		$cases = array();
		foreach ( DocumentTypeRegistry::PICKING_LIST_STATES as $state ) {
			$cases[ 'picking_list allows ' . $state ] = array( 'picking_list', $state, true );
		}
		foreach ( DocumentTypeRegistry::PACKING_SLIP_STATES as $state ) {
			$cases[ 'picking_list denies ' . $state ] = array( 'picking_list', $state, false );
		}
		$cases['picking_list denies cancelled'] = array( 'picking_list', 'cancelled', false );

		return $cases;
	}

	/**
	 * @dataProvider packing_slip_matrix
	 * @dataProvider picking_list_matrix
	 */
	public function test_matrix( string $doc_type, string $state, bool $allowed ): void {
		$type        = ( new DocumentTypeRegistry() )->get( $doc_type );
		$fulfillment = $this->fulfillment_in( $state );

		self::assertNotNull( $type );
		self::assertSame( $allowed, DocumentStagePolicy::allows( $type, $fulfillment ) );
	}

	public function test_cancelled_always_denied_for_both_types(): void {
		$fulfillment = $this->fulfillment_in( 'cancelled' );

		foreach ( array( 'packing_slip', 'picking_list' ) as $doc_type ) {
			$type = ( new DocumentTypeRegistry() )->get( $doc_type );
			self::assertNotNull( $type );
			self::assertFalse( DocumentStagePolicy::allows( $type, $fulfillment ) );
			self::assertSame( 'stage_not_allowed', DocumentStagePolicy::denial_code( $type, $fulfillment ) );
			self::assertStringContainsString( 'cancelled', DocumentStagePolicy::denial_message( $type, $fulfillment ) );
		}
	}

	public function test_exception_uses_return_to_state_for_eligibility(): void {
		$packing = ( new DocumentTypeRegistry() )->get( 'packing_slip' );
		$picking = ( new DocumentTypeRegistry() )->get( 'picking_list' );
		self::assertNotNull( $packing );
		self::assertNotNull( $picking );

		$problem_from_packing = $this->fulfillment_in( 'problem', 'packing' );
		self::assertTrue( DocumentStagePolicy::allows( $packing, $problem_from_packing ) );
		self::assertFalse( DocumentStagePolicy::allows( $picking, $problem_from_packing ) );

		$problem_from_picking = $this->fulfillment_in( 'waiting', 'picking' );
		self::assertTrue( DocumentStagePolicy::allows( $picking, $problem_from_picking ) );
		self::assertFalse( DocumentStagePolicy::allows( $packing, $problem_from_picking ) );
	}

	public function test_exception_without_return_to_is_denied(): void {
		$type        = DocumentType::define(
			array(
				'id'             => 'packing_slip',
				'label'          => 'Packing slip',
				'assembler'      => 'packing_slip',
				'template_key'   => 'packing_slip',
				'allowed_states' => DocumentTypeRegistry::PACKING_SLIP_STATES,
			)
		);
		$fulfillment = $this->fulfillment_in( 'problem', null );

		self::assertFalse( DocumentStagePolicy::allows( $type, $fulfillment ) );
	}

	/**
	 * @param string      $state     Current state.
	 * @param string|null $return_to Interrupted state when in exception.
	 */
	private function fulfillment_in( string $state, ?string $return_to = null ): Fulfillment {
		$data = Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'queued', '#1', 'A', 1, new DateTimeImmutable() )->to_array();
		$data['id']              = 1;
		$data['state']           = $state;
		$data['return_to_state'] = $return_to;

		return Fulfillment::from_array( $data );
	}
}
