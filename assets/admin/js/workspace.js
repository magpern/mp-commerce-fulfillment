/**
 * Packing Workspace bootstrap: the focus manager, and the action bar's
 * re-render after every mutation.
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). This is the entry
 * point `Admin\Assets` enqueues with `type="module"` — every other
 * workspace module (`packing.js`, `shipment.js`, `documents.js`,
 * `shortcuts.js`) is a sibling `<script type="module">` importing from
 * `store.js`/`api.js` directly, not from this file, so a failure in one
 * feature module never breaks another's import graph.
 */

import { createStore } from './store.js';

/**
 * Architecture Plan §IV.5.4 — one manager owns focus for the whole
 * screen, and its three rules are each one function here:
 *
 * 1. Resting focus is the scan sink — `restingFocus()`.
 * 2. A network response never steals focus — `capture()`/`restore()`
 *    around whatever triggered a re-render.
 * 3. Modals trap focus and return it — `capture()` before opening,
 *    `restore()` on close (the modal itself, `modal.js`, already
 *    autofocuses on open; this manager only owns the *return*).
 *
 * @param {string} scanSinkSelector
 */
function createFocusManager( scanSinkSelector ) {
	var savedElement = null;
	var savedStart = null;
	var savedEnd = null;

	function capture() {
		var active = document.activeElement;

		savedElement = active;
		savedStart = null;
		savedEnd = null;

		if ( active && 'number' === typeof active.selectionStart ) {
			savedStart = active.selectionStart;
			savedEnd = active.selectionEnd;
		}
	}

	function restore() {
		if ( ! savedElement || ! document.body.contains( savedElement ) ) {
			restingFocus();
			return;
		}

		savedElement.focus();

		if ( null !== savedStart && 'function' === typeof savedElement.setSelectionRange ) {
			savedElement.setSelectionRange( savedStart, savedEnd );
		}
	}

	function restingFocus() {
		var sink = document.querySelector( scanSinkSelector );

		if ( sink ) {
			sink.focus();
		}
	}

	return { capture: capture, restore: restore, restingFocus: restingFocus };
}

/**
 * Mirrors `Admin\WorkspacePage::choose_primary_candidate()` — the first
 * candidate whose target is neither `cancelled` nor (by convention, since
 * the client has no workflow definition to ask) one of the three bundled
 * exception-state keys. Necessary duplication: there is no shared
 * templating layer between the server-rendered initial state and a
 * client-side re-render in a no-build, no-framework setup (ADR-0003).
 *
 * @param {Array} transitions
 */
var EXCEPTION_TARGETS = [ 'problem', 'waiting', 'backordered', 'cancelled' ];

function pickPrimary( transitions ) {
	for ( var i = 0; i < transitions.length; i++ ) {
		if ( -1 === EXCEPTION_TARGETS.indexOf( transitions[ i ].target ) ) {
			return transitions[ i ];
		}
	}

	return null;
}

/**
 * Rebuilds the action bar's actions region from a fresh `transitions`
 * array — the one piece of markup every mutation response can change
 * (Architecture Plan §IV.9's "fresh state" convention), regardless of
 * which feature module triggered the mutation.
 *
 * @param {HTMLElement} actionsRegion `.mpcf-ui-action-bar__actions` element.
 * @param {Array}       transitions
 */
function renderActionBar( actionsRegion, transitions ) {
	var primary = pickPrimary( transitions );

	actionsRegion.textContent = '';

	transitions.forEach( function ( candidate ) {
		if ( candidate === primary || ! candidate.approved ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button';
		button.setAttribute( 'data-mpcf-secondary-action', '' );
		button.setAttribute( 'data-mpcf-target', candidate.target );
		button.textContent = candidate.label;
		actionsRegion.appendChild( button );
	} );

	if ( ! primary ) {
		return;
	}

	var primaryButton = document.createElement( 'button' );
	primaryButton.type = 'submit';
	primaryButton.className = 'button button-primary mpcf-ui-action-bar__primary';
	primaryButton.setAttribute( 'data-mpcf-primary-action', '' );
	primaryButton.setAttribute( 'data-mpcf-target', primary.target );
	primaryButton.textContent = primary.label;

	if ( ! primary.approved ) {
		primaryButton.disabled = true;
	}

	actionsRegion.appendChild( primaryButton );

	if ( ! primary.approved && primary.rejection_message ) {
		var message = document.createElement( 'span' );
		message.className = 'description';
		message.setAttribute( 'data-mpcf-guard-message', '' );
		message.textContent = primary.rejection_message;
		actionsRegion.appendChild( message );
	}
}

document.addEventListener( 'DOMContentLoaded', function () {
	var root = document.querySelector( '[data-mpcf-workspace]' );

	if ( ! root ) {
		return;
	}

	var store = createStore( root );
	var focus = createFocusManager( '[data-mpcf-scan-sink]' );
	var actionsRegion = root.querySelector( '.mpcf-ui-action-bar__actions' );

	store.onUpdate( function ( payload ) {
		if ( actionsRegion && payload && payload.transitions ) {
			focus.capture();
			renderActionBar( actionsRegion, payload.transitions );
			focus.restore();
		}
	} );

	focus.restingFocus();

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}

		if ( document.querySelector( '.mpcf-ui-modal[open], [data-mpcf-modal-open].is-open' ) ) {
			// A future modal-close binding (shortcuts.js, F17) owns
			// returning focus once it actually closes something; this
			// bootstrap only owns the no-modal-open fallback below.
			return;
		}

		focus.restingFocus();
	} );

	// Exposed for sibling feature modules (packing.js, shipment.js,
	// documents.js, shortcuts.js) to import without each re-deriving a
	// store/focus-manager instance of their own.
	window.MpcfWorkspace = {
		store: store,
		focus: focus,
		renderActionBar: function ( transitions ) {
			if ( actionsRegion ) {
				renderActionBar( actionsRegion, transitions );
			}
		}
	};
} );
