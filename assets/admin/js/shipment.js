/**
 * Shipment/package panel live edits for the Packing Workspace.
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). Weight and
 * dimensions are always stored canonically as integer grams/millimetres
 * (Architecture Plan §IV.6, D15); every field the operator sees is
 * converted to the store's configured display unit ({@see
 * \MPCF\Woo\StoreUnits}, localized as `data-mpcf-grams-per-unit`/
 * `data-mpcf-mm-per-unit`/`data-mpcf-weight-unit-label`/
 * `data-mpcf-dimension-unit-label` on the `[data-mpcf-package-repeater]`
 * wrapper) — this module converts back to canonical before every write
 * and never stores a display-unit value.
 *
 * `PATCH /shipments/{id}` has no partial-update semantics for
 * `carrier_id`/`service` (an omitted field arrives as `''` from the
 * route's own arg defaults), so every shipment write resends the full
 * current field set, reading `service` back from the card's own
 * `data-mpcf-shipment-service` round-trip attribute since M2 has no
 * visible field for it.
 *
 * The "no shipment yet" card (`data-mpcf-shipment-id="0"`) creates the
 * shipment on its first edit (§IV.5.8 step 6). The creation response
 * carries the new shipment but not its auto-created package 1 — there is
 * no route to fetch one package by id (the REST surface is frozen
 * additive-only from `v0.2.0`, §4), so the one authoritative way to learn
 * it is a follow-up `listShipments()` read. The same read (and the same
 * `renderPackageItems()` rebuild-from-response) is reused after every
 * add/remove, so the package list is always rendered from server truth,
 * never assembled from a guess about what the mutation just did.
 */

import { api } from './api.js';

var PACKAGE_FLUSH_DEBOUNCE_MS = 750;

var pendingPackageFields = {};
var packageFlushTimers = {};

function workspace() {
	return window.MpcfWorkspace;
}

function mutate( attemptFn ) {
	return workspace()
		.store.mutate( attemptFn )
		.catch( function ( error ) {
			notifyError( error );
			throw error;
		} );
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

function shipmentCard( element ) {
	return element.closest( '.mpcf-workspace__shipment' );
}

function shipmentId( card ) {
	return parseInt( card.getAttribute( 'data-mpcf-shipment-id' ) || '0', 10 );
}

function currentShipmentFields( card ) {
	var select = card.querySelector( '[data-mpcf-carrier-select]' );
	var tracking = card.querySelector( '[data-mpcf-tracking-number]' );

	return {
		carrier_id: select ? select.value : '',
		service: card.getAttribute( 'data-mpcf-shipment-service' ) || '',
		tracking_number: tracking ? tracking.value : ''
	};
}

function applyShipmentResource( card, shipment ) {
	card.setAttribute( 'data-mpcf-shipment-id', String( shipment.id ) );
	card.setAttribute( 'data-mpcf-shipment-service', shipment.service || '' );

	var select = card.querySelector( '[data-mpcf-carrier-select]' );
	var tracking = card.querySelector( '[data-mpcf-tracking-number]' );

	if ( select ) {
		select.value = shipment.carrier_id || '';
	}

	if ( tracking ) {
		tracking.value = shipment.tracking_number || '';
	}

	var repeater = card.querySelector( '[data-mpcf-package-repeater]' );

	if ( repeater ) {
		repeater.setAttribute( 'data-mpcf-shipment-id', String( shipment.id ) );
	}
}

function readUnitConfig( card ) {
	var repeater = card.querySelector( '[data-mpcf-package-repeater]' );

	return {
		gramsPerUnit: repeater ? parseFloat( repeater.getAttribute( 'data-mpcf-grams-per-unit' ) ) || 1 : 1,
		mmPerUnit: repeater ? parseFloat( repeater.getAttribute( 'data-mpcf-mm-per-unit' ) ) || 1 : 1,
		weightLabel: repeater ? repeater.getAttribute( 'data-mpcf-weight-unit-label' ) || '' : '',
		dimensionLabel: repeater ? repeater.getAttribute( 'data-mpcf-dimension-unit-label' ) || '' : ''
	};
}

function toDisplay( canonical, perUnit ) {
	if ( null === canonical || undefined === canonical ) {
		return '';
	}

	var value = Math.round( ( canonical / perUnit ) * 100 ) / 100;

	return String( value );
}

function unitInputElement( name, value, suffix, field, label ) {
	var wrap = document.createElement( 'div' );
	wrap.className = 'mpcf-ui-unit-input';

	var input = document.createElement( 'input' );
	input.type = 'text';
	input.className = 'mpcf-ui-unit-input__control';
	input.name = name;
	input.value = value;
	input.setAttribute( 'aria-label', label );
	input.setAttribute( 'data-mpcf-package-field', field );

	var suffixSpan = document.createElement( 'span' );
	suffixSpan.className = 'mpcf-ui-unit-input__suffix';
	suffixSpan.setAttribute( 'aria-hidden', 'true' );
	suffixSpan.textContent = suffix;

	wrap.appendChild( input );
	wrap.appendChild( suffixSpan );

	return wrap;
}

/**
 * Rebuilds one `.mpcf-ui-repeater__item` from a package resource — the
 * JS-side mirror of `WorkspacePage::render_package_item()`. Necessary
 * duplication: there is no shared templating layer between the
 * server-rendered initial state and a client-side rebuild (ADR-0003),
 * the same accepted trade-off `workspace.js`'s `pickPrimary()` documents.
 *
 * @param {HTMLElement} card
 * @param {Object}      pkg
 */
function buildPackageItem( card, pkg ) {
	var units = readUnitConfig( card );

	var item = document.createElement( 'div' );
	item.className = 'mpcf-ui-repeater__item';
	item.setAttribute( 'data-mpcf-package-id', String( pkg.id ) );

	item.appendChild( unitInputElement( 'packages[' + pkg.id + '][weight_grams]', toDisplay( pkg.weight_grams, units.gramsPerUnit ), units.weightLabel, 'weight_grams', 'Weight' ) );
	item.appendChild( unitInputElement( 'packages[' + pkg.id + '][length_mm]', toDisplay( pkg.length_mm, units.mmPerUnit ), units.dimensionLabel, 'length_mm', 'Length' ) );
	item.appendChild( unitInputElement( 'packages[' + pkg.id + '][width_mm]', toDisplay( pkg.width_mm, units.mmPerUnit ), units.dimensionLabel, 'width_mm', 'Width' ) );
	item.appendChild( unitInputElement( 'packages[' + pkg.id + '][height_mm]', toDisplay( pkg.height_mm, units.mmPerUnit ), units.dimensionLabel, 'height_mm', 'Height' ) );

	var tracking = document.createElement( 'input' );
	tracking.type = 'text';
	tracking.name = 'packages[' + pkg.id + '][tracking_number]';
	tracking.value = pkg.tracking_number || '';
	tracking.placeholder = 'Colli tracking number';
	tracking.setAttribute( 'aria-label', 'Colli tracking number' );
	tracking.setAttribute( 'data-mpcf-package-field', 'tracking_number' );
	item.appendChild( tracking );

	var remove = document.createElement( 'button' );
	remove.type = 'button';
	remove.className = 'mpcf-ui-repeater__remove';
	remove.setAttribute( 'data-mpcf-repeater-remove', '' );
	remove.setAttribute( 'aria-label', 'Remove' );
	remove.textContent = '×';
	item.appendChild( remove );

	return item;
}

function renderPackageItems( card, packages ) {
	var repeater = card.querySelector( '[data-mpcf-package-repeater] .mpcf-ui-repeater' );

	if ( ! repeater ) {
		return;
	}

	repeater.querySelectorAll( '.mpcf-ui-repeater__item' ).forEach( function ( item ) {
		item.remove();
	} );

	var addButton = repeater.querySelector( '[data-mpcf-repeater-add]' );

	packages.forEach( function ( pkg ) {
		var item = buildPackageItem( card, pkg );

		if ( addButton ) {
			repeater.insertBefore( item, addButton );
		} else {
			repeater.appendChild( item );
		}
	} );
}

/**
 * Re-reads this shipment's packages from the server and rebuilds the
 * repeater from that response — the reconcile-from-truth rule (§IV.10,
 * risk M2-R7) applied to the package list, not just to line quantities.
 *
 * @param {HTMLElement} card
 * @return {Promise}
 */
function refreshPackages( card ) {
	return api.listShipments( workspace().store.getFulfillmentId() ).then( function ( result ) {
		var id = shipmentId( card );
		var match = ( result.shipments || [] ).filter( function ( shipment ) {
			return shipment.id === id;
		} )[ 0 ];

		if ( match ) {
			renderPackageItems( card, match.packages || [] );
		}
	} );
}

function createShipmentThen( card, fieldOverride ) {
	return mutate( function () {
		return api.createShipment( workspace().store.getFulfillmentId() );
	} )
		.then( function ( result ) {
			applyShipmentResource( card, result.shipment );

			var description = card.querySelector( '.description' );

			if ( description ) {
				description.remove();
			}

			var fields = Object.assign( currentShipmentFields( card ), fieldOverride );

			return mutate( function () {
				return api.updateShipment( result.shipment.id, fields );
			} );
		} )
		.then( function ( result ) {
			applyShipmentResource( card, result.shipment );

			return refreshPackages( card );
		} )
		.catch( function () {
			// Already surfaced via notifyError() inside mutate(); the card
			// stays on shipment id "0" so the next edit simply retries.
		} );
}

function handleShipmentFieldChange( target ) {
	var card = shipmentCard( target );

	if ( ! card ) {
		return;
	}

	if ( 0 === shipmentId( card ) ) {
		var override = {};

		if ( target.matches( '[data-mpcf-carrier-select]' ) ) {
			override.carrier_id = target.value;
		}

		if ( target.matches( '[data-mpcf-tracking-number]' ) ) {
			override.tracking_number = target.value;
		}

		createShipmentThen( card, override );
		return;
	}

	mutate( function () {
		return api.updateShipment( shipmentId( card ), currentShipmentFields( card ) );
	} )
		.then( function ( result ) {
			applyShipmentResource( card, result.shipment );
		} )
		.catch( function () {} );
}

function queuePackageChange( packageId, field, value ) {
	pendingPackageFields[ packageId ] = pendingPackageFields[ packageId ] || {};
	pendingPackageFields[ packageId ][ field ] = value;

	if ( packageFlushTimers[ packageId ] ) {
		window.clearTimeout( packageFlushTimers[ packageId ] );
	}

	packageFlushTimers[ packageId ] = window.setTimeout( function () {
		flushPackage( packageId );
	}, PACKAGE_FLUSH_DEBOUNCE_MS );
}

function flushPackage( packageId ) {
	delete packageFlushTimers[ packageId ];

	var fields = pendingPackageFields[ packageId ];

	if ( ! fields ) {
		return Promise.resolve( null );
	}

	delete pendingPackageFields[ packageId ];

	return mutate( function () {
		return api.updatePackage( packageId, fields );
	} ).catch( function () {} );
}

/**
 * Optimistically un-disables the primary action button — the shipment/
 * package counterpart of `packing.js`'s identically-named function, same
 * reasoning: `action-bar.js` checks `button.disabled` before it will even
 * click the button, so a fast keyboard operator entering a package's
 * weight and immediately pressing `Ctrl+Enter` (§IV.5.8 step 8) would
 * otherwise have that keystroke silently dropped while waiting on the
 * debounced flush's round trip to confirm `package_spec_present` server-
 * side. Self-corrects via `workspace.js`'s 422 handling if ever wrong.
 */
function maybeEnablePrimaryActionOptimistically() {
	var primary = document.querySelector( '[data-mpcf-primary-action]' );

	if ( ! primary || ! primary.disabled ) {
		return;
	}

	primary.disabled = false;

	var guardMessage = document.querySelector( '[data-mpcf-guard-message]' );

	if ( guardMessage ) {
		guardMessage.remove();
	}
}

function handlePackageFieldInput( input ) {
	var item = input.closest( '[data-mpcf-package-id]' );
	var card = shipmentCard( input );

	if ( ! item || ! card ) {
		return;
	}

	var packageId = parseInt( item.getAttribute( 'data-mpcf-package-id' ), 10 );
	var field = input.getAttribute( 'data-mpcf-package-field' );

	if ( ! field ) {
		return;
	}

	if ( 'tracking_number' === field ) {
		queuePackageChange( packageId, field, input.value );
		return;
	}

	var units = readUnitConfig( card );
	var perUnit = 'weight_grams' === field ? units.gramsPerUnit : units.mmPerUnit;
	var numeric = parseFloat( input.value );
	var canonical = isNaN( numeric ) ? null : Math.round( numeric * perUnit );

	queuePackageChange( packageId, field, canonical );

	// A package with any spec value present is what package_spec_present
	// actually checks (Architecture Plan §IV.5.8 step 8) — a shipment
	// already exists by the time this field is editable at all, so
	// HasShipmentGuard is moot here.
	if ( null !== canonical ) {
		maybeEnablePrimaryActionOptimistically();
	}
}

/**
 * Force-flushes every package field edit still waiting on its debounce
 * timer. `workspace.js`'s `flushPendingWrites()` calls this immediately
 * before every transition attempt — the shipment/package counterpart of
 * `packing.js`'s `flushPendingItems()` (Architecture Plan §IV.10, risk
 * M2-R7: "no transition ever runs against unflushed local state"). Without
 * it, a fast operator who edits a weight field and presses `Ctrl+Enter`
 * before the 750ms debounce fires would have that edit silently dropped —
 * `queuePackageChange()`'s timer only calls `flushPackage()` on its own
 * schedule otherwise, and pressing `Ctrl+Enter` never blurs the field.
 *
 * @return {Promise}
 */
function flushPendingPackages() {
	return Promise.all(
		Object.keys( packageFlushTimers ).map( function ( packageId ) {
			return flushPackage( parseInt( packageId, 10 ) );
		} )
	);
}

function handleAddPackage( button ) {
	var card = shipmentCard( button );

	if ( ! card || 0 === shipmentId( card ) ) {
		return;
	}

	mutate( function () {
		return api.addPackage( shipmentId( card ) );
	} )
		.then( function () {
			return refreshPackages( card );
		} )
		.catch( function () {} );
}

function handleRemovePackage( button ) {
	var item = button.closest( '[data-mpcf-package-id]' );
	var card = shipmentCard( button );

	if ( ! item || ! card ) {
		return;
	}

	var packageId = parseInt( item.getAttribute( 'data-mpcf-package-id' ), 10 );

	mutate( function () {
		return api.removePackage( packageId );
	} )
		.then( function () {
			return refreshPackages( card );
		} )
		.catch( function () {} );
}

document.addEventListener( 'DOMContentLoaded', function () {
	if ( ! document.querySelector( '.mpcf-workspace__shipment' ) ) {
		return;
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches && event.target.matches( '[data-mpcf-carrier-select]' ) ) {
			handleShipmentFieldChange( event.target );
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( event.target.matches && event.target.matches( '[data-mpcf-package-field]' ) ) {
			handlePackageFieldInput( event.target );
		}
	} );

	document.addEventListener(
		'focusout',
		function ( event ) {
			var target = event.target;

			if ( ! target.matches ) {
				return;
			}

			if ( target.matches( '[data-mpcf-tracking-number]' ) && ! target.closest( '[data-mpcf-package-repeater]' ) ) {
				handleShipmentFieldChange( target );
				return;
			}

			if ( target.matches( '[data-mpcf-package-field]' ) ) {
				var item = target.closest( '[data-mpcf-package-id]' );

				if ( item ) {
					flushPackage( parseInt( item.getAttribute( 'data-mpcf-package-id' ), 10 ) );
				}
			}
		},
		true
	);

	document.addEventListener( 'click', function ( event ) {
		var addButton = event.target.closest( '[data-mpcf-repeater-add]' );

		if ( addButton ) {
			handleAddPackage( addButton );
			return;
		}

		var removeButton = event.target.closest( '[data-mpcf-repeater-remove]' );

		if ( removeButton ) {
			handleRemovePackage( removeButton );
		}
	} );

	if ( window.MpcfWorkspace ) {
		window.MpcfWorkspace.flushPendingPackages = flushPendingPackages;
	}
} );
