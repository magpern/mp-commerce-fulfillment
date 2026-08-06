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

var GUARD_MESSAGES = {
	all_items_picked: 'Pick all ordered items before marking this fulfillment as picked.',
	all_items_packed: 'Pack all picked items before marking this fulfillment as packed.',
	package_spec_present: 'Enter package weight and dimensions before marking this fulfillment as packed.',
	photo_required: 'A sealed-package photo is required before this fulfillment can be marked packed.',
	has_shipment: 'Add a shipment before shipping this fulfillment.',
	has_tracking: 'Enter a tracking number before shipping.'
};

var SHIPMENT_OPEN_STATES = {
	picked: true,
	packing: true,
	packed: true,
	shipped: true,
	delivered: true,
	completed: true
};

function pickPrimary( transitions ) {
	for ( var i = 0; i < transitions.length; i++ ) {
		if ( -1 === EXCEPTION_TARGETS.indexOf( transitions[ i ].target ) ) {
			return transitions[ i ];
		}
	}

	return null;
}

function operatorGuardMessage( candidate ) {
	if ( ! candidate ) {
		return '';
	}

	if ( candidate.rejection_code && GUARD_MESSAGES[ candidate.rejection_code ] ) {
		return GUARD_MESSAGES[ candidate.rejection_code ];
	}

	return candidate.rejection_message || 'This action is not available yet.';
}

function stageGuidanceMap() {
	var node = document.getElementById( 'mpcf-stage-guidance-data' );

	if ( ! node ) {
		return {};
	}

	try {
		return JSON.parse( node.textContent || '{}' );
	} catch ( error ) {
		return {};
	}
}

function refreshStageBanner( state, transitions ) {
	var map = stageGuidanceMap();
	var guidance = map[ state ] || {
		state_label: state || '',
		title: state || '',
		instruction: 'Review this fulfillment and use the primary action when ready.',
		next_action_label: 'Continue',
		shipment_emphasis: 'secondary'
	};
	var primary = pickPrimary( transitions || [] );

	var stateValue = document.querySelector( '[data-mpcf-stage-state-value]' );
	var title = document.querySelector( '[data-mpcf-stage-title]' );
	var instruction = document.querySelector( '[data-mpcf-stage-instruction]' );
	var nextAction = document.querySelector( '[data-mpcf-stage-next-action]' );

	if ( stateValue ) {
		stateValue.textContent = guidance.state_label || state || '';
	}

	if ( title ) {
		title.textContent = guidance.title || guidance.state_label || state || '';
	}

	if ( instruction ) {
		instruction.textContent = guidance.instruction || '';
	}

	if ( nextAction ) {
		nextAction.textContent = primary ? primary.label : ( guidance.next_action_label || '' );
	}
}

function refreshStageChrome( state, transitions ) {
	var root = document.querySelector( '[data-mpcf-workspace]' );

	if ( root && state ) {
		root.setAttribute( 'data-mpcf-stage', state );
	}

	refreshStageBanner( state, transitions );

	if (
		window.MpcfWorkspace &&
		typeof window.MpcfWorkspace.syncScanModeButtons === 'function'
	) {
		window.MpcfWorkspace.syncScanModeButtons();
	}

	var disclosure = document.querySelector( '[data-mpcf-shipment-disclosure]' );

	if ( disclosure && state ) {
		disclosure.open = !! SHIPMENT_OPEN_STATES[ state ];
		disclosure.classList.remove(
			'mpcf-workspace__shipment-disclosure--muted',
			'mpcf-workspace__shipment-disclosure--secondary',
			'mpcf-workspace__shipment-disclosure--primary'
		);

		var emphasis = ( stageGuidanceMap()[ state ] || {} ).shipment_emphasis || 'secondary';
		disclosure.classList.add( 'mpcf-workspace__shipment-disclosure--' + emphasis );
	}

	var success = document.querySelector( '[data-mpcf-shipped-success]' );

	if ( success ) {
		var show = -1 !== [ 'shipped', 'delivered', 'completed' ].indexOf( state );
		success.hidden = ! show;

		if ( show ) {
			var next = success.querySelector( '[data-mpcf-shipped-next-order]' );

			if ( next ) {
				next.focus();
			}
		}
	}
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
		button.className = 'button mpcf-workspace__secondary-action';
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
	// The reason modal's hidden `required` textarea lives in this same
	// form (WorkspacePage.php); native constraint validation cannot focus
	// a hidden field to satisfy it, so it silently blocks every submit
	// unless this is set — see WorkspacePage::render_action_bar()'s own
	// comment on the server-rendered primary button for the full story.
	primaryButton.formNoValidate = true;
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

	if ( ! primary.approved ) {
		var message = document.createElement( 'span' );
		message.className = 'description mpcf-workspace__guard-message';
		message.setAttribute( 'data-mpcf-guard-message', '' );
		message.textContent = operatorGuardMessage( primary );
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

function initWorkspace() {
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

		if ( payload && payload.fulfillment && payload.fulfillment.state ) {
			refreshStageChrome( payload.fulfillment.state, payload.transitions || [] );
		}
	} );

	/**
	 * `packing.js`'s debounced item batch and `shipment.js`'s debounced
	 * package-field edits are both forced before every transition attempt
	 * (risk M2-R7: "no transition ever runs against unflushed local
	 * state") — `flushPendingItems`/`flushPendingPackages` are only set
	 * once each module's own `DOMContentLoaded` listener has run, which
	 * registration order guarantees happens before this function is ever
	 * *called* (it only runs later, from a click/submit).
	 *
	 * Forcing those two covers a write still waiting on its debounce timer;
	 * it does not cover one already in flight from an earlier, unrelated
	 * edit (a blur-triggered package PATCH, say, or a still-running
	 * shipment-creation chain) — `store.settle()` is what waits for those,
	 * regardless of which module started them.
	 */
	function flushPendingWrites() {
		var pending = [];

		if ( window.MpcfWorkspace && window.MpcfWorkspace.flushPendingItems ) {
			pending.push( window.MpcfWorkspace.flushPendingItems() );
		}

		if ( window.MpcfWorkspace && window.MpcfWorkspace.flushPendingPackages ) {
			pending.push( window.MpcfWorkspace.flushPendingPackages() );
		}

		return Promise.all( pending ).then( function () {
			return store.settle();
		} );
	}

	function offerNextOrder() {
		var nextLink = document.querySelector( '[data-mpcf-shipped-next-order]' ) ||
			document.querySelector( '[data-mpcf-queue-next]' );
		var success = document.querySelector( '[data-mpcf-shipped-success]' );

		if ( success ) {
			success.hidden = false;

			var focusTarget = success.querySelector( '[data-mpcf-shipped-next-order]' );

			if ( focusTarget ) {
				focusTarget.focus();
			}
		}

		document.dispatchEvent(
			new CustomEvent( 'data-mpcf-toast', {
				detail: {
					message: 'Shipped. No further warehouse action is required on this order.',
					variant: 'success',
					actionLabel: nextLink ? ( nextLink.textContent || 'Next order →' ).trim() : undefined,
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

	/**
	 * Reconciles the workflow-position stepper after a transition — the same
	 * complete/current/upcoming rules {@see WorkspacePage::build_stepper_steps()}
	 * uses server-side, driven by each step's `data-mpcf-step-key`.
	 *
	 * @param {string|null} state Fulfillment's state after the transition.
	 */
	function refreshWorkflowStepper( state ) {
		var stepper = document.querySelector( '.mpcf-ui-stepper' );

		if ( ! stepper ) {
			return;
		}

		var steps = stepper.querySelectorAll( '.mpcf-ui-stepper__step' );
		var keys = [];

		steps.forEach( function ( step ) {
			var key = step.getAttribute( 'data-mpcf-step-key' );

			if ( key ) {
				keys.push( key );
			}
		} );

		var currentIndex = state ? keys.indexOf( state ) : -1;

		if ( -1 === currentIndex ) {
			currentIndex = false;
		}

		steps.forEach( function ( step, index ) {
			var stepState;

			if ( false === currentIndex ) {
				stepState = 'upcoming';
			} else if ( index < currentIndex ) {
				stepState = 'complete';
			} else if ( index === currentIndex ) {
				stepState = 'current';
			} else {
				stepState = 'upcoming';
			}

			step.classList.remove(
				'mpcf-ui-stepper__step--complete',
				'mpcf-ui-stepper__step--current',
				'mpcf-ui-stepper__step--upcoming'
			);
			step.classList.add( 'mpcf-ui-stepper__step--' + stepState );

			if ( 'current' === stepState ) {
				step.setAttribute( 'aria-current', 'step' );
			} else {
				step.removeAttribute( 'aria-current' );
			}
		} );
	}

	/**
	 * A transition response carries no item data, only `fulfillment` and
	 * `transitions` — but the checklist's controls (a live stepper while
	 * picking/packing, read-only otherwise) must become correct for the
	 * new state without a page reload (§IV.5.8 step 2: "the checklist
	 * becomes live"). One extra `GET /fulfillments/{id}` read per
	 * transition is the only way to learn the fresh items; `packing.js`
	 * does the actual rebuild via `refreshChecklist`, exposed on
	 * `window.MpcfWorkspace` the same way `flushPendingItems` is.
	 *
	 * @param {string|null} state Fulfillment's state after the transition.
	 */
	function refreshChecklistForState( state ) {
		if ( ! window.MpcfWorkspace || ! window.MpcfWorkspace.refreshChecklist ) {
			return Promise.resolve( null );
		}

		var activeField = 'picking' === state ? 'qty_picked' : 'packing' === state ? 'qty_packed' : null;

		return api
			.getFulfillment( store.getFulfillmentId() )
			.then( function ( result ) {
				focus.capture();
				window.MpcfWorkspace.refreshChecklist( result.items || [], activeField );
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
				var state = result.fulfillment ? result.fulfillment.state : null;

				refreshWorkflowStepper( state );
				refreshStageChrome( state, result.transitions || [] );

				return refreshChecklistForState( state ).then( function () {
					if ( result.fulfillment && 'shipped' === result.fulfillment.state ) {
						offerNextOrder();
					}

					return result;
				} );
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
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', initWorkspace );
} else {
	initWorkspace();
}
