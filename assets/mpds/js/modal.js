/**
 * Modal (centered dialog) behavior module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). Any `[data-mpcf-modal-open="{id}"]`
 * element opens the `#{id}` modal; any `[data-mpcf-modal-close]` element
 * inside it (or its backdrop) closes it; `Escape` closes every open modal.
 * On open, focus moves to `[data-mpcf-modal-autofocus]` if the modal has
 * one (e.g. a reason-capture textarea), otherwise to the close button.
 * Visibility is the native `hidden` attribute — never a CSS class — so this
 * file survives class-prefix rewriting untouched.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes. Deliberately independent of `js/drawer.js` (separate modules,
 * same house pattern) rather than a shared base — the two behaviors are
 * small enough that sharing would cost more in indirection than it saves.
 */
( function () {
	'use strict';

	function openModal( modal ) {
		modal.hidden = false;

		var focusTarget = modal.querySelector( '[data-mpcf-modal-autofocus]' ) || modal.querySelector( 'button[data-mpcf-modal-close]' );

		if ( focusTarget ) {
			focusTarget.focus();
		}
	}

	function closeModal( modal ) {
		modal.hidden = true;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-mpcf-modal-open]' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( event ) {
				var targetId = trigger.getAttribute( 'data-mpcf-modal-open' );
				var modal = targetId ? document.getElementById( targetId ) : null;

				if ( ! modal ) {
					return;
				}

				event.preventDefault();
				openModal( modal );
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var closer = event.target.closest( '[data-mpcf-modal-close]' );

			if ( ! closer ) {
				return;
			}

			var modal = closer.closest( '[data-mpcf-modal]' );

			if ( modal ) {
				closeModal( modal );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}

			document.querySelectorAll( '[data-mpcf-modal]' ).forEach( function ( modal ) {
				if ( ! modal.hidden ) {
					closeModal( modal );
				}
			} );
		} );
	} );
} )();
