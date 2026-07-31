/**
 * Clipboard copy module.
 *
 * Vanilla ES module. Ported from Universal Multicurrency's compatibility
 * report copy button, generalized: `[data-mpcf-copy-trigger]` copies the
 * value (or text content) of the element named by its `data-mpcf-copy-target`
 * id attribute, and announces the result in the element named by
 * `data-mpcf-copy-status`. Success/failure messages come from the trigger's
 * `data-mpcf-copy-success` / `data-mpcf-copy-failed` attributes — read from
 * markup rather than a bespoke localized JS strings object.
 */
( function () {
	'use strict';

	function announce( status, message ) {
		if ( status && message ) {
			status.textContent = message;
		}
	}

	function fallbackCopy( field, onDone ) {
		field.focus();

		if ( typeof field.select === 'function' ) {
			field.select();
		}

		try {
			onDone( document.execCommand( 'copy' ) );
		} catch ( error ) {
			onDone( false );
		}
	}

	function initTrigger( button ) {
		var targetId = button.getAttribute( 'data-mpcf-copy-target' );
		var target = targetId ? document.getElementById( targetId ) : null;
		var statusId = button.getAttribute( 'data-mpcf-copy-status' );
		var status = statusId ? document.getElementById( statusId ) : null;

		if ( ! target ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var text = 'value' in target ? target.value : target.textContent;
			var successMessage = button.getAttribute( 'data-mpcf-copy-success' ) || '';
			var failedMessage = button.getAttribute( 'data-mpcf-copy-failed' ) || '';

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						announce( status, successMessage );
					},
					function () {
						fallbackCopy( target, function ( copied ) {
							announce( status, copied ? successMessage : failedMessage );
						} );
					}
				);
				return;
			}

			fallbackCopy( target, function ( copied ) {
				announce( status, copied ? successMessage : failedMessage );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-mpcf-copy-trigger]' ).forEach( initTrigger );
	} );
} )();
