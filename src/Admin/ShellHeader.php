<?php
/**
 * Builds the shared page-shell header view model.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Vendor\Mpds\PageShell\ViewModel\AdminPageShellViewModel;

/**
 * Every Fulfillment screen shares the same branded header and icon
 * navigation; only which item is marked active differs.
 */
final class ShellHeader {

	/**
	 * Builds the header view model for a given current page.
	 *
	 * @param string $current_slug The currently displayed page's slug.
	 */
	public static function view_model( string $current_slug ): AdminPageShellViewModel {
		return new AdminPageShellViewModel(
			__( 'Commerce Fulfillment', 'mp-commerce-fulfillment' ),
			__( 'Warehouse fulfillment workspace', 'mp-commerce-fulfillment' ),
			'dashicons-archive',
			false,
			'',
			'',
			ShellNavigation::items( $current_slug ),
			__( 'Fulfillment sections', 'mp-commerce-fulfillment' )
		);
	}
}
