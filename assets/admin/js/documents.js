/**
 * Document printing for the Packing Workspace (M4-C).
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). Browser printing
 * remains the only mechanism — hidden same-origin iframe + window.print().
 */

import { api } from './api.js';

var printInFlight = false;

function workspace() {
	return window.MpcfWorkspace;
}

function notify( message, variant ) {
	document.dispatchEvent(
		new CustomEvent( 'data-mpcf-toast', {
			detail: {
				message: message,
				variant: variant || 'error'
			}
		} )
	);
}

function notifyError( error ) {
	notify( error.message || 'Document print failed', 'error' );
}

/**
 * Renders HTML into a hidden iframe and prints it.
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

/**
 * Refreshes last-printed status for one type from a successful render response.
 *
 * @param {string} docType
 * @param {object} result
 */
function refreshStatus( docType, result ) {
	var item = document.querySelector( '[data-mpcf-doc-status="' + docType + '"]' );

	if ( ! item ) {
		return;
	}

	var version = result.template_version || '';
	var label = item.querySelector( 'strong' );
	var strong = label ? label.outerHTML + ' ' : '';
	var now = new Date();
	var stamp =
		now.getFullYear() +
		'-' +
		String( now.getMonth() + 1 ).padStart( 2, '0' ) +
		'-' +
		String( now.getDate() ).padStart( 2, '0' ) +
		' ' +
		String( now.getHours() ).padStart( 2, '0' ) +
		':' +
		String( now.getMinutes() ).padStart( 2, '0' );

	item.innerHTML =
		strong +
		'Last printed ' +
		stamp +
		' (template ' +
		version +
		')';
}

/**
 * Prints one document type for the current fulfillment.
 *
 * @param {string} docType
 * @returns {Promise<void>}
 */
export function printDocument( docType ) {
	if ( printInFlight ) {
		return Promise.resolve();
	}

	var ws = workspace();
	var fulfillmentId = ws && ws.store ? ws.store.getFulfillmentId() : 0;

	if ( ! fulfillmentId ) {
		notifyError( new Error( 'No fulfillment loaded.' ) );
		return Promise.resolve();
	}

	printInFlight = true;

	return api
		.renderDocument( fulfillmentId, docType )
		.then( function ( result ) {
			printHtml( result.html );
			refreshStatus( docType, result );
			notify(
				( result.document_type || docType ) + ' printed.',
				'success'
			);

			if ( ws && ws.focus && ws.focus.restingFocus ) {
				ws.focus.restingFocus();
			}
		} )
		.catch( notifyError )
		.finally( function () {
			printInFlight = false;
		} );
}

/**
 * Shift+P primary print: clicks the primary enabled button, or toasts the denial.
 */
export function printPrimaryDocument() {
	var primary = document.querySelector( '[data-mpcf-print-primary]' );

	if ( primary && ! primary.disabled ) {
		primary.click();
		return;
	}

	if ( primary && primary.getAttribute( 'data-mpcf-denied-reason' ) ) {
		notify( primary.getAttribute( 'data-mpcf-denied-reason' ), 'error' );
		return;
	}

	var denied = document.querySelector( '[data-mpcf-documents-denied]' );
	if ( denied ) {
		notify( denied.textContent.trim(), 'error' );
		return;
	}

	notify( 'No printable document is available in this stage.', 'error' );
}

document.addEventListener( 'DOMContentLoaded', function () {
	var root = document.querySelector( '[data-mpcf-documents]' );

	if ( ! root ) {
		return;
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-mpcf-print-document]' );

		if ( ! button || button.disabled ) {
			return;
		}

		printDocument( button.getAttribute( 'data-mpcf-print-document' ) );
	} );
} );
