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
import { api } from './api.js';

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

		if ( candidate.requires_reason ) {
			button.setAttribute( 'data-mpcf-requires-reason', '' );
		}

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

	if ( primary.requires_reason ) {
		primaryButton.setAttribute( 'data-mpcf-requires-reason', '' );
	}

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

document.addEventListener( 'DOMContentLoaded', function () {
	var root = document.querySelector( '[data-mpcf-workspace]' );

	if ( ! root ) {
		return;
	}

	var store = createStore( root );
	var focus = createFocusManager( '[data-mpcf-scan-sink]' );
	var actionsRegion = root.querySelector( '.mpcf-ui-action-bar__actions' );
	var reasonModal = document.getElementById( 'mpcf-reason-modal' );
	var pendingReasonTarget = null;

	store.onUpdate( function ( payload ) {
		if ( actionsRegion && payload && payload.transitions ) {
			focus.capture();
			renderActionBar( actionsRegion, payload.transitions );
			focus.restore();
		}
	} );

	/**
	 * `packing.js`'s debounced batch flush is forced before every
	 * transition attempt (risk M2-R7: "no transition ever runs against
	 * unflushed local state") — `flushPendingItems` is only set once
	 * `packing.js`'s own `DOMContentLoaded` listener has run, which
	 * registration order guarantees happens before this function is ever
	 * *called* (it only runs later, from a click/submit).
	 */
	function flushPendingWrites() {
		if ( window.MpcfWorkspace && window.MpcfWorkspace.flushPendingItems ) {
			return window.MpcfWorkspace.flushPendingItems();
		}

		return Promise.resolve( null );
	}

	function offerNextOrder() {
		var nextLink = document.querySelector( '[data-mpcf-queue-next]' );

		document.dispatchEvent(
			new CustomEvent( 'data-mpcf-toast', {
				detail: {
					message: 'Shipped.',
					variant: 'success',
					actionLabel: nextLink ? 'Next order →' : undefined,
					actionHref: nextLink ? nextLink.getAttribute( 'href' ) : undefined
				}
			} )
		);
	}

	function refreshActionBarAfterFailure() {
		api.getTransitions( store.getFulfillmentId() )
			.then( function ( result ) {
				focus.capture();
				renderActionBar( actionsRegion, result.transitions );
				focus.restore();
			} )
			.catch( function () {} );
	}

	function submitTransition( target, reason ) {
		return flushPendingWrites()
			.then( function () {
				return store.mutate( function () {
					return api.submitTransition( store.getFulfillmentId(), target, store.getVersion(), reason );
				} );
			} )
			.then( function ( result ) {
				if ( result.fulfillment && 'shipped' === result.fulfillment.state ) {
					offerNextOrder();
				}

				return result;
			} )
			.catch( function ( error ) {
				notifyError( error );

				// A 422 guard rejection means the candidate list this
				// button was built from is stale (another actor changed
				// something server-side between page load and this
				// click) — refetch and re-render rather than leaving a
				// now-wrong action bar on screen.
				if ( 'mpcf_guard_rejected' === error.code || 422 === error.status ) {
					refreshActionBarAfterFailure();
				}
			} );
	}

	function openReasonModal( target ) {
		if ( ! reasonModal ) {
			submitTransition( target, null );
			return;
		}

		pendingReasonTarget = target;

		var textarea = reasonModal.querySelector( 'textarea' );

		if ( textarea ) {
			textarea.value = '';
		}

		focus.capture();
		reasonModal.hidden = false;

		var autofocus = reasonModal.querySelector( '[data-mpcf-modal-autofocus]' ) || reasonModal.querySelector( 'button[data-mpcf-modal-close]' );

		if ( autofocus ) {
			autofocus.focus();
		}
	}

	function beginTransition( element ) {
		if ( ! element ) {
			return;
		}

		var target = element.getAttribute( 'data-mpcf-target' );

		if ( ! target ) {
			return;
		}

		if ( element.hasAttribute( 'data-mpcf-requires-reason' ) ) {
			openReasonModal( target );
			return;
		}

		submitTransition( target, null );
	}

	root.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var submitter = event.submitter || root.querySelector( '[data-mpcf-primary-action]' );

		if ( reasonModal && submitter && reasonModal.contains( submitter ) ) {
			var textarea = reasonModal.querySelector( 'textarea' );
			var reason = textarea ? textarea.value : '';
			var target = pendingReasonTarget;

			reasonModal.hidden = true;

			if ( target ) {
				submitTransition( target, reason );
			}

			return;
		}

		beginTransition( submitter );
	} );

	root.addEventListener( 'click', function ( event ) {
		var secondary = event.target.closest( '[data-mpcf-secondary-action]' );

		if ( secondary ) {
			beginTransition( secondary );
		}
	} );

	/**
	 * One observer covers every way the reason modal can close — the
	 * Cancel button, Escape (`modal.js`'s own handler sets `hidden`
	 * directly, with no click event this module could otherwise hook),
	 * and the confirm-submit path above. This modal is opened
	 * programmatically (not via a `[data-mpcf-modal-open]` trigger click),
	 * so `shortcuts.js`'s generic opener-tracking has nothing to restore
	 * focus to for it — this module owns its capture (`openReasonModal()`)
	 * and its restore (here) exclusively, matching the "one manager owns
	 * focus" rule this file's own docblock states (§IV.5.4).
	 */
	if ( reasonModal ) {
		var reasonModalWasHidden = reasonModal.hidden;

		new MutationObserver( function () {
			if ( reasonModal.hidden && ! reasonModalWasHidden ) {
				pendingReasonTarget = null;
				focus.restore();
			}

			reasonModalWasHidden = reasonModal.hidden;
		} ).observe( reasonModal, { attributes: true, attributeFilter: [ 'hidden' ] } );
	}

	focus.restingFocus();

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}

		if ( document.querySelector( '[data-mpcf-modal]:not([hidden])' ) ) {
			// `modal.js`'s own Escape handler closes it; the return-focus
			// side of that (§IV.5.4, rule 3) is owned above, at the
			// `[data-mpcf-modal-close]` click handler and the reason-modal
			// confirm path — both fire regardless of what closed the modal.
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
