/**
 * Optimistic state store for the Packing Workspace.
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). Owns exactly two
 * pieces of authoritative state — the fulfillment's id and its current
 * optimistic-lock `version` — mirrored onto `data-mpcf-fulfillment-id`/
 * `data-mpcf-version` attributes on the workspace root element so any
 * other script (or a future page reload) can read them without importing
 * this module. Every other piece of workspace state (items, shipments,
 * transitions, …) is owned by the feature module that renders it
 * (`packing.js`, `shipment.js`, …); this store's job is the two things
 * every one of those modules shares: the version every mutation must
 * carry, and the retry/pending-write bookkeeping every mutation needs.
 *
 * `mutate()` is what makes "the store reconciles from the response, never
 * from its own optimistic value" (Architecture Plan §IV.10, risk M2-R7)
 * true in one place: every caller passes a function that performs one
 * REST call, and `mutate()` updates `version` from whatever that call's
 * response actually reports — never from what the caller optimistically
 * assumed would happen.
 */

var MAX_ATTEMPTS = 3;
var RETRY_DELAYS_MS = [ 500, 1500, 4000 ];

/**
 * @param {HTMLElement} root The `[data-mpcf-workspace]` form element.
 */
export function createStore( root ) {
	var version = parseInt( root.getAttribute( 'data-mpcf-version' ) || '0', 10 );
	var fulfillmentId = parseInt( root.getAttribute( 'data-mpcf-fulfillment-id' ) || '0', 10 );
	var pendingCount = 0;
	var listeners = [];

	function getFulfillmentId() {
		return fulfillmentId;
	}

	function getVersion() {
		return version;
	}

	function setVersion( nextVersion ) {
		version = nextVersion;
		root.setAttribute( 'data-mpcf-version', String( nextVersion ) );
	}

	/**
	 * Registers a listener called with a mutation response's full body
	 * every time one reconciles successfully — `transitions`/`fulfillment`
	 * are present on every mutation response (Architecture Plan §IV.9's
	 * "fresh state, no follow-up round trip" convention), so this is the
	 * one hook a caller needs to keep the action bar current regardless
	 * of which feature module triggered the mutation.
	 *
	 * @param {Function} listener
	 */
	function onUpdate( listener ) {
		listeners.push( listener );
	}

	function reconcile( payload ) {
		var nextVersion = payload && payload.version;

		if ( undefined === nextVersion && payload && payload.fulfillment ) {
			nextVersion = payload.fulfillment.version;
		}

		if ( undefined !== nextVersion ) {
			setVersion( nextVersion );
		}

		listeners.forEach( function ( listener ) {
			listener( payload );
		} );
	}

	function setPending( delta ) {
		pendingCount = Math.max( 0, pendingCount + delta );
		root.setAttribute( 'data-mpcf-pending-writes', String( pendingCount ) );
	}

	function isNetworkFailure( error ) {
		// A real HTTP error response (4xx/5xx) always carries `.status`
		// (see api.js) — only a `fetch()` that never got a response at
		// all (offline, DNS failure, a dropped connection) has none, and
		// that is the only case a retry can plausibly help with; a 409/422
		// retried unchanged would just fail identically every time.
		return undefined === error.status;
	}

	function attempt( attemptFn, attemptIndex ) {
		return attemptFn().catch( function ( error ) {
			if ( isNetworkFailure( error ) && attemptIndex < MAX_ATTEMPTS - 1 ) {
				return new Promise( function ( resolve ) {
					window.setTimeout( function () {
						resolve( attempt( attemptFn, attemptIndex + 1 ) );
					}, RETRY_DELAYS_MS[ attemptIndex ] );
				} );
			}

			throw error;
		} );
	}

	/**
	 * Performs one mutating REST call, tracking it as a pending write and
	 * reconciling the store's `version` from its response on success.
	 * Retries up to 3 times, with backoff, on a network failure only — a
	 * real error response (409/422/…) rejects immediately, unchanged, for
	 * the caller to handle.
	 *
	 * @param {Function} attemptFn Zero-arg function returning the API-call Promise.
	 * @return {Promise}
	 */
	function mutate( attemptFn ) {
		setPending( 1 );

		return attempt( attemptFn, 0 ).then(
			function ( result ) {
				setPending( -1 );
				reconcile( result );

				return result;
			},
			function ( error ) {
				setPending( -1 );
				throw error;
			}
		);
	}

	return {
		getFulfillmentId: getFulfillmentId,
		getVersion: getVersion,
		setVersion: setVersion,
		onUpdate: onUpdate,
		reconcile: reconcile,
		mutate: mutate
	};
}
