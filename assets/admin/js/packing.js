/**
 * Checklist quantity semantics for the Packing Workspace.
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). Enhances the
 * server-rendered checklist (`Admin\WorkspacePage::render_work_region()`)
 * with the whole-row click target, the collapse-completed toggle, and the
 * debounced batch flush that turns a burst of taps into one
 * `PUT /items` call (Architecture Plan §IV.10). `shortcuts.js` drives the
 * same behavior from the keyboard by importing the row-mutating functions
 * below directly — real ES `import`/`export` between plugin-owned modules
 * that genuinely cooperate (see `workspace.js`'s module docblock).
 *
 * Reads/writes only `.mpcf-ui-quantity-stepper__value` inputs whose
 * `name` matches `items[{id}][{field}]` — never a component CSS class as
 * a *behavior* hook beyond that one already-necessary selector (the
 * `data-mpcf-*` attributes it does key on are `data-mpcf-row-id`,
 * `data-mpcf-complete-all`, `data-mpcf-field`,
 * `data-mpcf-toggle-collapse-completed`).
 */

import { api } from './api.js';

var DEBOUNCE_MS = 750;
var NAME_PATTERN = /^items\[(\d+)\]\[(qty_\w+)\]$/;
var COMPLETE_CLASS = 'mpcf-ui-checklist__row--complete';

var pendingLines = {};
var flushTimer = null;
var collapseCompleted = false;

function workspace() {
	return window.MpcfWorkspace;
}

function parseName( name ) {
	var match = NAME_PATTERN.exec( name || '' );

	return match ? { itemId: parseInt( match[ 1 ], 10 ), field: match[ 2 ] } : null;
}

function stepperInput( row ) {
	return row.querySelector( '.mpcf-ui-quantity-stepper__value' );
}

function clamp( value, min, max ) {
	return Math.max( min, Math.min( max, value ) );
}

function currentValue( row ) {
	var input = stepperInput( row );

	return input ? parseInt( input.value, 10 ) || 0 : 0;
}

function queueChange( itemId, field, value ) {
	pendingLines[ itemId ] = pendingLines[ itemId ] || {};
	pendingLines[ itemId ][ field ] = value;
	scheduleFlush();
}

function scheduleFlush() {
	if ( null !== flushTimer ) {
		window.clearTimeout( flushTimer );
	}

	flushTimer = window.setTimeout( flush, DEBOUNCE_MS );
}

function hasPendingLines() {
	return Object.keys( pendingLines ).length > 0;
}

/**
 * Flushes every queued line as one `PUT /items` call. A forced flush (on
 * blur, on `visibilitychange`, and — once F19 wires the action bar —
 * before a transition attempt) always sends exactly what is currently
 * queued, never a stale closure over it.
 *
 * @return {Promise}
 */
export function flush() {
	if ( null !== flushTimer ) {
		window.clearTimeout( flushTimer );
		flushTimer = null;
	}

	if ( ! hasPendingLines() ) {
		return Promise.resolve( null );
	}

	var lines = Object.keys( pendingLines ).map( function ( itemId ) {
		var line = { item_id: parseInt( itemId, 10 ) };

		Object.keys( pendingLines[ itemId ] ).forEach( function ( field ) {
			line[ field ] = pendingLines[ itemId ][ field ];
		} );

		return line;
	} );

	pendingLines = {};

	var ws = workspace();

	return ws.store
		.mutate( function () {
			return api.updateItems( ws.store.getFulfillmentId(), ws.store.getVersion(), lines );
		} )
		.then( function ( result ) {
			( result.items || [] ).forEach( applyItemResult );
			applyCollapseState();

			return result;
		} )
		.catch( function ( error ) {
			notifyError( error );
			throw error;
		} );
}

/**
 * Reconciles one row from the authoritative server response — never from
 * whatever was last set optimistically (§IV.10, risk M2-R7).
 *
 * @param {Object} item
 */
function applyItemResult( item ) {
	var row = document.querySelector( '[data-mpcf-row-id="' + item.id + '"]' );

	if ( ! row ) {
		return;
	}

	var input = stepperInput( row );

	if ( input ) {
		var parsed = parseName( input.name );
		var authoritative = parsed ? item[ parsed.field ] : undefined;

		if ( undefined !== authoritative ) {
			input.value = String( authoritative );
			input.setAttribute( 'aria-valuenow', String( authoritative ) );
		}
	}

	if ( undefined !== item.qty_picked && undefined !== item.qty_packed && undefined !== item.qty_ordered ) {
		var complete = item.qty_picked >= item.qty_ordered && item.qty_packed >= item.qty_ordered;
		row.classList.toggle( COMPLETE_CLASS, complete );
	}
}

function notifyError( error ) {
	document.dispatchEvent(
		new CustomEvent( 'data-mpcf-toast', {
			detail: {
				message: error.message,
				variant: 'error',
				persistent: 409 === error.status,
				actionLabel: 409 === error.status ? 'Reload' : undefined,
				actionHref: 409 === error.status ? window.location.href : undefined
			}
		} )
	);
}

function setRowValue( row, nextValue ) {
	var input = stepperInput( row );

	if ( ! input ) {
		return;
	}

	var min = parseInt( input.min, 10 ) || 0;
	var max = parseInt( input.max, 10 ) || 0;
	var clamped = clamp( nextValue, min, max );

	input.value = String( clamped );
	input.setAttribute( 'aria-valuenow', String( clamped ) );
	row.classList.toggle( COMPLETE_CLASS, clamped >= max );

	var parsed = parseName( input.name );

	if ( parsed ) {
		queueChange( parsed.itemId, parsed.field, clamped );
	}

	applyCollapseState();
}

/**
 * Increments one row's quantity by one. Exported for `shortcuts.js`
 * (`Space`/`Enter`) and used internally by the whole-row click handler.
 *
 * @param {HTMLElement} row `.mpcf-ui-checklist__row` element.
 */
export function incrementRow( row ) {
	setRowValue( row, currentValue( row ) + 1 );
}

/**
 * Decrements one row's quantity by one. Exported for `shortcuts.js`
 * (`Shift+Space`) and used internally by the decrement button's handler.
 *
 * @param {HTMLElement} row `.mpcf-ui-checklist__row` element.
 */
export function decrementRow( row ) {
	setRowValue( row, currentValue( row ) - 1 );
}

/**
 * Sets one row to its ordered quantity. Exported for `shortcuts.js`
 * (`a`) and used internally by `completeAllRows()`.
 *
 * @param {HTMLElement} row `.mpcf-ui-checklist__row` element.
 */
export function completeRow( row ) {
	var input = stepperInput( row );

	if ( input ) {
		setRowValue( row, parseInt( input.max, 10 ) || 0 );
	}
}

/**
 * Sets every row to its ordered quantity in one batch. Exported for
 * `shortcuts.js` (`Shift+A`) and the `Complete all` button.
 */
export function completeAllRows() {
	document.querySelectorAll( '.mpcf-ui-checklist__row' ).forEach( completeRow );
}

/**
 * Hides (or reveals) every completed row — the `Collapse completed`
 * toggle a long order needs to stay a one-screen job (§IV.5.2). Exported
 * for `shortcuts.js` (`c`).
 */
export function toggleCollapseCompleted() {
	collapseCompleted = ! collapseCompleted;
	applyCollapseState();
}

function applyCollapseState() {
	document.querySelectorAll( '.mpcf-ui-checklist__row' ).forEach( function ( row ) {
		row.hidden = collapseCompleted && row.classList.contains( COMPLETE_CLASS );
	} );

	var toggle = document.querySelector( '[data-mpcf-toggle-collapse-completed]' );

	if ( toggle ) {
		toggle.setAttribute( 'aria-pressed', collapseCompleted ? 'true' : 'false' );
	}
}

document.addEventListener( 'DOMContentLoaded', function () {
	if ( ! document.querySelector( '.mpcf-ui-checklist' ) ) {
		return;
	}

	// The whole row is the increment target (Architecture Plan §IV.5.2) —
	// clicking or tapping anywhere in it that is not the decrement button
	// increments by one; the increment button and the number input itself
	// keep their own native behavior.
	document.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( '.mpcf-ui-checklist__row' );

		if ( ! row ) {
			return;
		}

		if ( event.target.closest( '[data-mpcf-quantity-decrement]' ) ) {
			decrementRow( row );
			return;
		}

		if ( event.target.closest( '[data-mpcf-quantity-increment]' ) || event.target.closest( '.mpcf-ui-quantity-stepper__value' ) ) {
			return;
		}

		incrementRow( row );
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '[data-mpcf-complete-all]' ) ) {
			completeAllRows();
		}

		if ( event.target.closest( '[data-mpcf-toggle-collapse-completed]' ) ) {
			toggleCollapseCompleted();
		}
	} );

	document.addEventListener(
		'focusout',
		function ( event ) {
			if ( event.target.closest && event.target.closest( '.mpcf-ui-quantity-stepper__value' ) ) {
				flush();
			}
		},
		true
	);

	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			flush();
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( hasPendingLines() ) {
			// A best-effort synchronous flush attempt; a true
			// `navigator.sendBeacon()` flush needs a nonce-free beacon
			// endpoint mpcf/v1 does not have today (every route requires
			// the same cookie+nonce auth this fetch already carries) — a
			// documented gap, not silently dropped.
			flush();
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	if ( window.MpcfWorkspace ) {
		window.MpcfWorkspace.flushPendingItems = flush;
	}
} );
