/**
 * mpcf/v1 REST client for the Packing Workspace.
 *
 * Vanilla ES module, no jQuery, no build step (ADR-0003/ADR-0006). Reads
 * its base URL and nonce from `window.mpcfWorkspace`, the small object
 * `Admin\WorkspacePage` localizes once per page load — never hardcoded,
 * so this file works unchanged whatever the site's REST prefix is.
 *
 * Every method returns a Promise resolving to the parsed JSON body on a
 * 2xx response, or rejecting with an `Error` carrying `.code` (the stable
 * `mpcf_*` error code from docs/API.md), `.status` (HTTP status), and
 * `.data` (the error body's `data` object — e.g. `{ guard: "..." }` for a
 * 422) — callers branch on `.code`, never on the human-readable message.
 *
 * Wave Workspace / Queue create-wave also call `api` as a function:
 * `api( 'waves/1' )` or `api( 'waves', { method: 'POST', body: {...} } )`.
 * Named methods remain available as `api.getFulfillment( … )` etc.
 */

function config() {
	return window.mpcfWorkspace || {};
}

function request( method, path, body ) {
	var settings = config();
	var headers = { 'Content-Type': 'application/json' };

	if ( settings.nonce ) {
		headers[ 'X-WP-Nonce' ] = settings.nonce;
	}

	return window
		.fetch( ( settings.restUrl || '' ) + path, {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			body: undefined === body ? undefined : JSON.stringify( body )
		} )
		.then( function ( response ) {
			return response.json().then(
				function ( data ) {
					if ( ! response.ok ) {
						throw toApiError( response, data );
					}

					return data;
				},
				function () {
					// A non-JSON error body (a raw 500, a proxy timeout
					// page) still needs to reject with the same shape a
					// caller's retry/rollback logic already handles.
					throw toApiError( response, {} );
				}
			);
		} );
}

function toApiError( response, data ) {
	var error = new Error( ( data && data.message ) || response.statusText || 'Request failed' );
	error.code = data && data.code;
	error.status = response.status;
	error.data = ( data && data.data ) || {};

	return error;
}

/**
 * @param {string} path Relative mpcf/v1 path.
 * @param {{ method?: string, body?: * }=} options Fetch options.
 * @return {Promise<*>}
 */
function api( path, options ) {
	var opts = options || {};
	var method = ( opts.method || 'GET' ).toUpperCase();

	return request( method, path, opts.body );
}

api.getFulfillment = function ( fulfillmentId ) {
	return request( 'GET', 'fulfillments/' + fulfillmentId );
};

api.getTransitions = function ( fulfillmentId ) {
	return request( 'GET', 'fulfillments/' + fulfillmentId + '/transitions' );
};

api.submitTransition = function ( fulfillmentId, target, version, reason ) {
	return request( 'POST', 'fulfillments/' + fulfillmentId + '/transitions', {
		target: target,
		version: version,
		reason: reason
	} );
};

api.updateItems = function ( fulfillmentId, version, lines ) {
	return request( 'PUT', 'fulfillments/' + fulfillmentId + '/items', {
		version: version,
		lines: lines
	} );
};

api.listNotes = function ( fulfillmentId ) {
	return request( 'GET', 'fulfillments/' + fulfillmentId + '/notes' );
};

api.addNote = function ( fulfillmentId, body, isPinned ) {
	return request( 'POST', 'fulfillments/' + fulfillmentId + '/notes', {
		body: body,
		is_pinned: !! isPinned
	} );
};

api.assign = function ( fulfillmentId, userId ) {
	return request( 'PUT', 'fulfillments/' + fulfillmentId + '/assignment', { user_id: userId } );
};

api.unassign = function ( fulfillmentId ) {
	return request( 'DELETE', 'fulfillments/' + fulfillmentId + '/assignment' );
};

api.listShipments = function ( fulfillmentId ) {
	return request( 'GET', 'fulfillments/' + fulfillmentId + '/shipments' );
};

api.createShipment = function ( fulfillmentId ) {
	return request( 'POST', 'fulfillments/' + fulfillmentId + '/shipments' );
};

api.updateShipment = function ( shipmentId, fields ) {
	return request( 'PATCH', 'shipments/' + shipmentId, fields );
};

api.deleteShipment = function ( shipmentId ) {
	return request( 'DELETE', 'shipments/' + shipmentId );
};

api.shipShipment = function ( shipmentId ) {
	return request( 'POST', 'shipments/' + shipmentId + '/ship' );
};

api.notifyShipment = function ( shipmentId, force ) {
	return request( 'POST', 'shipments/' + shipmentId + '/notify', {
		force: false !== force
	} );
};

api.notificationStatus = function ( shipmentId ) {
	return request( 'GET', 'shipments/' + shipmentId + '/notification-status' );
};

api.addPackage = function ( shipmentId ) {
	return request( 'POST', 'shipments/' + shipmentId + '/packages' );
};

api.updatePackage = function ( packageId, fields ) {
	return request( 'PATCH', 'packages/' + packageId, fields );
};

api.removePackage = function ( packageId ) {
	return request( 'DELETE', 'packages/' + packageId );
};

api.listCarriers = function () {
	return request( 'GET', 'carriers' );
};

api.renderDocument = function ( fulfillmentId, docType ) {
	return request( 'POST', 'fulfillments/' + fulfillmentId + '/documents/render', {
		doc_type: docType || 'packing_slip'
	} );
};

api.listPhotos = function ( fulfillmentId, filters ) {
	var query = [];
	var options = filters || {};

	if ( options.package_id ) {
		query.push( 'package_id=' + encodeURIComponent( String( options.package_id ) ) );
	}

	if ( options.kind ) {
		query.push( 'kind=' + encodeURIComponent( String( options.kind ) ) );
	}

	return request(
		'GET',
		'fulfillments/' + fulfillmentId + '/photos' + ( query.length ? '?' + query.join( '&' ) : '' )
	);
};

api.uploadPhoto = function ( fulfillmentId, fields ) {
	var settings = config();
	var form = new window.FormData();
	var options = fields || {};

	form.append( 'file', options.file );
	form.append( 'package_id', String( options.package_id ) );
	form.append( 'kind', String( options.kind ) );
	form.append( 'version', String( options.version ) );

	var headers = {};

	if ( settings.nonce ) {
		headers[ 'X-WP-Nonce' ] = settings.nonce;
	}

	return window
		.fetch( ( settings.restUrl || '' ) + 'fulfillments/' + fulfillmentId + '/photos', {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: form
		} )
		.then( function ( response ) {
			return response.json().then(
				function ( data ) {
					if ( ! response.ok ) {
						throw toApiError( response, data );
					}

					return data;
				},
				function () {
					throw toApiError( response, {} );
				}
			);
		} );
};

api.deletePhoto = function ( photoId, version ) {
	return request( 'DELETE', 'photos/' + photoId, { version: version } );
};

api.scan = function ( fulfillmentId, body ) {
	return request( 'POST', 'fulfillments/' + fulfillmentId + '/scan', body || {} );
};

api.photoThumbUrl = function ( photoId ) {
	return ( config().restUrl || '' ) + 'photos/' + photoId + '/thumb';
};

api.photoContentUrl = function ( photoId ) {
	return ( config().restUrl || '' ) + 'photos/' + photoId + '/content';
};

api.fetchPhotoBlob = function ( photoId, which ) {
	var settings = config();
	var path = which === 'content' ? api.photoContentUrl( photoId ) : api.photoThumbUrl( photoId );
	var headers = {};

	if ( settings.nonce ) {
		headers[ 'X-WP-Nonce' ] = settings.nonce;
	}

	return window
		.fetch( path, {
			method: 'GET',
			credentials: 'same-origin',
			headers: headers
		} )
		.then( function ( response ) {
			if ( ! response.ok ) {
				return response.json().then(
					function ( data ) {
						throw toApiError( response, data );
					},
					function () {
						throw toApiError( response, {} );
					}
				);
			}

			return response.blob();
		} );
};

export { api };
