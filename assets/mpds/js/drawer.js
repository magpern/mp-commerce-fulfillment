/**
 * Drawer (slide-over panel) behavior module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). Any `[data-mpcf-drawer-open="{id}"]`
 * element opens the `#{id}` drawer; any `[data-mpcf-drawer-close]` element
 * inside it (or its backdrop) closes it; `Escape` closes every open drawer.
 * Visibility is the native `hidden` attribute — never a CSS class — so this
 * file survives class-prefix rewriting untouched.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes.
 */
( function () {
	'use strict';

	function openDrawer( drawer ) {
		drawer.hidden = false;

		var closeButton = drawer.querySelector( 'button[data-mpcf-drawer-close]' );

		if ( closeButton ) {
			closeButton.focus();
		}
	}

	function closeDrawer( drawer ) {
		drawer.hidden = true;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-mpcf-drawer-open]' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( event ) {
				var targetId = trigger.getAttribute( 'data-mpcf-drawer-open' );
				var drawer = targetId ? document.getElementById( targetId ) : null;

				if ( ! drawer ) {
					return;
				}

				event.preventDefault();
				openDrawer( drawer );
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var closer = event.target.closest( '[data-mpcf-drawer-close]' );

			if ( ! closer ) {
				return;
			}

			var drawer = closer.closest( '[data-mpcf-drawer]' );

			if ( drawer ) {
				closeDrawer( drawer );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}

			document.querySelectorAll( '[data-mpcf-drawer]' ).forEach( function ( drawer ) {
				if ( ! drawer.hidden ) {
					closeDrawer( drawer );
				}
			} );
		} );
	} );
} )();
