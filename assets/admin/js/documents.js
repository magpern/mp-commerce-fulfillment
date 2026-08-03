/**
 * Packing-slip printing for the Packing Workspace.
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). Architecture Plan
 * §10.8: browser printing is the only mechanism M2 ships — this renders
 * into a hidden same-origin iframe and calls `window.print()`, never a new
 * tab (which would need closing) and never navigating the workspace away
 * from itself.
 */

import { api } from './api.js';

function workspace() {
	return window.MpcfWorkspace;
}

function notifyError( error ) {
	document.dispatchEvent(
		new CustomEvent( 'data-mpcf-toast', {
			detail: {
				message: error.message,
				variant: 'error'
			}
		} )
	);
}

/**
 * Renders the given HTML into a hidden iframe and prints it. The iframe
 * is removed a minute later — long enough to outlive any print dialog,
 * short enough that repeated prints in one session do not accumulate
 * hidden iframes indefinitely.
 *
 * @param {string} html
 */
function printHtml( html ) {
	var iframe = document.createElement( 'iframe' );
	iframe.setAttribute( 'aria-hidden', 'true' );
	iframe.style.position = 'fixed';
	iframe.style.right = '0';
	iframe.style.bottom = '0';
	iframe.style.width = '0';
	iframe.style.height = '0';
	iframe.style.border = '0';

	// Set srcdoc and the load handler before inserting into the DOM.
	// Appending an empty iframe fires `load` for about:blank; assigning
	// srcdoc afterward fires a second `load` — which opened an empty print
	// dialog before the packing slip was ready.
	iframe.srcdoc = html;
	iframe.addEventListener(
		'load',
		function () {
			iframe.contentWindow.focus();
			iframe.contentWindow.print();
		},
		{ once: true }
	);

	document.body.appendChild( iframe );

	window.setTimeout( function () {
		if ( iframe.parentNode ) {
			iframe.parentNode.removeChild( iframe );
		}
	}, 60000 );
}

document.addEventListener( 'DOMContentLoaded', function () {
	var button = document.querySelector( '[data-mpcf-print-packing-slip]' );

	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var ws = workspace();

		api.renderDocument( ws.store.getFulfillmentId() )
			.then( function ( result ) {
				printHtml( result.html );

				// The print dialog is effectively modal in every browser
				// this needs to support, though no standard event marks
				// its close — restoring immediately after `print()`
				// returns is the closest approximation available
				// (§IV.5.4: "focus returns to the scan sink").
				ws.focus.restingFocus();
			} )
			.catch( notifyError );
	} );
} );
