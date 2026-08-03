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

/**
 * Optimistically un-disables the primary action button the instant every
 * checklist row is complete, without waiting for the debounced flush's
 * round trip to confirm it server-side. Without this, a fast keyboard
 * operator's `Shift+A` immediately followed by `Ctrl+Enter` — exactly the
 * §IV.5.6 "minimal clicks" workflow this screen is built around — presses
 * `Ctrl+Enter` while `action-bar.js` still sees the button `disabled`
 * (unchanged since page load, since nothing before this re-evaluated it)
 * and silently drops the keystroke: `action-bar.js` itself checks
 * `button.disabled` before it will even click the button, so there is no
 * later point this module could otherwise intervene. Found the same way
 * as every other fix in this commit — a real, fast, automated keyboard
 * sequence in the Playwright suite, which a human's slower reaction time
 * had been masking (F22).
 *
 * Deliberately unconditional on which target the button currently shows
 * ('picked' or 'packed' are the only two AllItems*Guard gates, but this
 * does not re-derive that from the DOM): the guard rejection path
 * (`workspace.js`'s 422 handling) already reconciles from server truth if
 * this optimistic guess is ever wrong, the same "reconcile from the
 * response, never assume" rule every other optimistic update here follows.
 */
function maybeEnablePrimaryActionOptimistically() {
	var rows = document.querySelectorAll( '.mpcf-ui-checklist__row' );

	if ( ! rows.length ) {
		return;
	}

	var allComplete = Array.prototype.every.call( rows, function ( row ) {
		return row.classList.contains( COMPLETE_CLASS );
	} );

	if ( ! allComplete ) {
		return;
	}

	var primary = document.querySelector( '[data-mpcf-primary-action]' );

	if ( primary ) {
		primary.disabled = false;
	}

	var guardMessage = document.querySelector( '[data-mpcf-guard-message]' );

	if ( guardMessage ) {
		guardMessage.remove();
	}
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
	maybeEnablePrimaryActionOptimistically();
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

function buildStepper( name, value, max ) {
	var wrap = document.createElement( 'div' );
	wrap.className = 'mpcf-ui-quantity-stepper';
	wrap.setAttribute( 'role', 'group' );

	var decrement = document.createElement( 'button' );
	decrement.type = 'button';
	decrement.className = 'mpcf-ui-quantity-stepper__decrement';
	decrement.setAttribute( 'data-mpcf-quantity-decrement', '' );
	decrement.setAttribute( 'aria-label', 'Decrease' );
	decrement.textContent = '−';

	var input = document.createElement( 'input' );
	input.type = 'number';
	input.className = 'mpcf-ui-quantity-stepper__value';
	input.name = name;
	input.value = String( value );
	input.min = '0';
	input.max = String( max );
	input.setAttribute( 'aria-valuenow', String( value ) );
	input.setAttribute( 'aria-valuemin', '0' );
	input.setAttribute( 'aria-valuemax', String( max ) );

	var increment = document.createElement( 'button' );
	increment.type = 'button';
	increment.className = 'mpcf-ui-quantity-stepper__increment';
	increment.setAttribute( 'data-mpcf-quantity-increment', '' );
	increment.setAttribute( 'aria-label', 'Increase' );
	increment.textContent = '+';

	wrap.appendChild( decrement );
	wrap.appendChild( input );
	wrap.appendChild( increment );

	return wrap;
}

/**
 * Rebuilds every checklist row's control (a live stepper, or read-only
 * text) after a transition changes the fulfillment's state — the one
 * client-side re-render `workspace.js`'s `submitTransition()` triggers
 * with a fresh `GET /fulfillments/{id}` read, because nothing else
 * refreshes the checklist and a transition response itself carries no
 * item data (Architecture Plan §IV.5.8 step 2: "the checklist becomes
 * live", not "the page reloads"). Mirrors
 * `WorkspacePage::render_work_region()`'s control markup — the same
 * necessary duplication `shipment.js`'s `buildPackageItem()` already
 * documents, there being no shared templating layer (ADR-0003).
 *
 * @param {Array}       items       Fresh item resources from `GET /fulfillments/{id}`.
 * @param {string|null} activeField `qty_picked`, `qty_packed`, or null for read-only.
 */
export function refreshChecklist( items, activeField ) {
	items.forEach( function ( item ) {
		var row = document.querySelector( '[data-mpcf-row-id="' + item.id + '"]' );
		var control = row ? row.querySelector( '.mpcf-ui-checklist__control' ) : null;

		if ( ! row || ! control ) {
			return;
		}

		if ( null === activeField ) {
			control.textContent = item.qty_picked + ' / ' + item.qty_ordered + ' · ' + item.qty_packed + ' / ' + item.qty_ordered;
			row.classList.toggle( COMPLETE_CLASS, item.qty_picked >= item.qty_ordered && item.qty_packed >= item.qty_ordered );
			return;
		}

		var current = 'qty_picked' === activeField ? item.qty_picked : item.qty_packed;

		control.textContent = '';
		control.appendChild( buildStepper( 'items[' + item.id + '][' + activeField + ']', current, item.qty_ordered ) );
		row.classList.toggle( COMPLETE_CLASS, current >= item.qty_ordered );
	} );

	var completeAllButton = document.querySelector( '[data-mpcf-complete-all]' );
	var collapseButton = document.querySelector( '[data-mpcf-toggle-collapse-completed]' );

	if ( completeAllButton ) {
		completeAllButton.hidden = null === activeField;
	}

	if ( collapseButton ) {
		collapseButton.hidden = null === activeField;
	}

	applyCollapseState();
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
		window.MpcfWorkspace.refreshChecklist = refreshChecklist;
	}
} );
