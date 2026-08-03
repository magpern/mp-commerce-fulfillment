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
	var inFlight = [];
	var queue = Promise.resolve();

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
	 * Registers `promise` in `inFlight` under a never-rejecting wrapper (so
	 * a `settle()` caller's own `Promise.all()` cannot be short-circuited
	 * by an unrelated mutation's real failure) and removes it once settled.
	 * Returns `promise` itself, unchanged, for the original caller.
	 *
	 * @param {Promise} promise
	 * @return {Promise}
	 */
	function track( promise ) {
		var safe = promise.catch( function () {
			return null;
		} );

		inFlight.push( safe );

		safe.then( function () {
			var index = inFlight.indexOf( safe );

			if ( -1 !== index ) {
				inFlight.splice( index, 1 );
			}
		} );

		return promise;
	}

	/**
	 * Performs one mutating REST call, tracking it as a pending write and
	 * reconciling the store's `version` from its response on success.
	 * Retries up to 3 times, with backoff, on a network failure only — a
	 * real error response (409/422/…) rejects immediately, unchanged, for
	 * the caller to handle.
	 *
	 * Every call is run through `queue` — a strict FIFO, one request in
	 * flight at a time — rather than started immediately. Two feature
	 * modules can call `mutate()` within the same tick (a checklist's own
	 * debounce timer firing while a shipment card's edit is still being
	 * created, say); each caller's `attemptFn` reads `getVersion()` itself
	 * (never a value captured by `mutate()`'s caller ahead of time), so
	 * queuing means the second call's request is only ever built once the
	 * first one's response has already reconciled `version` — the only way
	 * two independent, same-tick mutations can both succeed instead of one
	 * of them 409ing against a version the other has since advanced past.
	 *
	 * @param {Function} attemptFn Zero-arg function returning the API-call Promise.
	 * @return {Promise}
	 */
	function mutate( attemptFn ) {
		setPending( 1 );

		var scheduled = queue.then( function () {
			return attempt( attemptFn, 0 );
		} );

		// The queue itself must keep moving even when this entry fails —
		// only the caller's own returned promise (below) rejects for it.
		queue = scheduled.then(
			function () {},
			function () {}
		);

		return track(
			scheduled.then(
				function ( result ) {
					setPending( -1 );
					reconcile( result );

					return result;
				},
				function ( error ) {
					setPending( -1 );
					throw error;
				}
			)
		);
	}

	/**
	 * Resolves once every `mutate()` call currently in flight has settled —
	 * including one that started before `settle()` was ever called (a
	 * blur-triggered package-field PATCH, say). This is what makes "no
	 * transition ever runs against unflushed local state" (Architecture
	 * Plan §IV.10, risk M2-R7) true for writes that are already in flight
	 * by the time a transition is attempted, as opposed to writes still
	 * waiting on a debounce timer — those need their own feature module to
	 * force-flush first (see `packing.js`'s `flush()`/`shipment.js`'s
	 * `flushPendingPackages()`), since `settle()` has nothing to await
	 * until the request actually exists.
	 *
	 * @return {Promise}
	 */
	function settle() {
		return Promise.all( inFlight.slice() );
	}

	return {
		getFulfillmentId: getFulfillmentId,
		getVersion: getVersion,
		setVersion: setVersion,
		onUpdate: onUpdate,
		reconcile: reconcile,
		mutate: mutate,
		settle: settle
	};
}
