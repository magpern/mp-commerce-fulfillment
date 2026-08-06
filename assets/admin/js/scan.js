/**
 * Workspace Scan Mode — keyboard-wedge pick/pack via ScanService REST.
 *
 * Subscribes to `data-mpcf-scan` only while Scan Mode is active. Manual
 * checklist controls remain available when the mode is exited.
 */

import { api } from './api.js';

var RECENT_LIMIT = 8;
var requestLock = false;
var mode = null; // 'picking' | 'packing' | null
var recent = [];
var soundEnabled = false;

function workspaceRoot() {
	return document.querySelector( '[data-mpcf-workspace]' );
}

function stage() {
	var root = workspaceRoot();

	return root ? root.getAttribute( 'data-mpcf-stage' ) || '' : '';
}

function fulfillmentId() {
	var root = workspaceRoot();
	var ws = window.MpcfWorkspace;

	if ( ws && ws.store && typeof ws.store.getFulfillmentId === 'function' ) {
		return ws.store.getFulfillmentId();
	}

	return root
		? parseInt( root.getAttribute( 'data-mpcf-fulfillment-id' ) || root.getAttribute( 'data-mpcf-id' ) || '0', 10 )
		: 0;
}

function version() {
	var ws = window.MpcfWorkspace;

	if ( ws && ws.store && typeof ws.store.getVersion === 'function' ) {
		return ws.store.getVersion();
	}

	var root = workspaceRoot();

	return root ? parseInt( root.getAttribute( 'data-mpcf-version' ) || '0', 10 ) : 0;
}

function panel() {
	return document.querySelector( '[data-mpcf-scan-mode]' );
}

function statusEl() {
	return document.querySelector( '[data-mpcf-scan-mode-status]' );
}

function resultEl() {
	return document.querySelector( '[data-mpcf-scan-mode-result]' );
}

function progressEl() {
	return document.querySelector( '[data-mpcf-scan-mode-progress]' );
}

function recentEl() {
	return document.querySelector( '[data-mpcf-scan-mode-recent]' );
}

function sink() {
	return document.querySelector( '[data-mpcf-scan-sink]' );
}

function activePackageId() {
	var selected = document.querySelector( '[data-mpcf-package].is-active, [data-mpcf-package][aria-current="true"]' );

	if ( ! selected ) {
		return null;
	}

	var id = parseInt( selected.getAttribute( 'data-mpcf-package-id' ) || selected.getAttribute( 'data-mpcf-package' ) || '0', 10 );

	return id > 0 ? id : null;
}

function setFeedback( kind, message ) {
	var status = statusEl();
	var result = resultEl();

	if ( status ) {
		status.textContent = kind.charAt( 0 ).toUpperCase() + kind.slice( 1 );
		status.setAttribute( 'data-mpcf-scan-mode-status-state', kind );
	}

	if ( result ) {
		result.textContent = message || '';
	}

	if ( soundEnabled && window.AudioContext ) {
		try {
			var ctx = new window.AudioContext();
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();
			osc.connect( gain );
			gain.connect( ctx.destination );
			osc.frequency.value = 'error' === kind || 'warning' === kind ? 220 : 880;
			gain.gain.value = 0.03;
			osc.start();
			osc.stop( ctx.currentTime + 0.08 );
		} catch ( e ) {
			// Optional sound must never block the flow.
		}
	}
}

function renderProgress( progress ) {
	var el = progressEl();

	if ( ! el || ! progress ) {
		return;
	}

	el.textContent =
		'Processed ' +
		( progress.processed || 0 ) +
		' / ' +
		( progress.ordered || 0 ) +
		' (remaining ' +
		( progress.remaining || 0 ) +
		')';
}

function pushRecent( entry ) {
	recent.unshift( entry );
	recent = recent.slice( 0, RECENT_LIMIT );

	var el = recentEl();

	if ( ! el ) {
		return;
	}

	el.innerHTML = '';
	recent.forEach( function ( row ) {
		var li = document.createElement( 'li' );
		li.textContent = row;
		el.appendChild( li );
	} );
}

function setMode( next ) {
	mode = next;
	var el = panel();
	var live = document.querySelector( '[data-mpcf-scan-mode-live]' );
	var undo = document.querySelector( '[data-mpcf-scan-mode-undo]' );
	var exit = document.querySelector( '[data-mpcf-scan-mode-exit]' );
	var sound = document.querySelector( '[data-mpcf-scan-mode-sound]' );

	if ( el ) {
		if ( next ) {
			el.setAttribute( 'data-mpcf-scan-mode-active', next );
			document.documentElement.setAttribute( 'data-mpcf-scan-mode-active', next );
		} else {
			el.removeAttribute( 'data-mpcf-scan-mode-active' );
			document.documentElement.removeAttribute( 'data-mpcf-scan-mode-active' );
		}
	}

	if ( live ) {
		live.hidden = ! next;
	}

	if ( undo ) {
		undo.hidden = ! next;
	}

	if ( exit ) {
		exit.hidden = ! next;
	}

	if ( sound ) {
		sound.hidden = ! next;
	}

	window.MpcfWorkspace = window.MpcfWorkspace || {};
	window.MpcfWorkspace.scanModeActive = !! next;

	if ( next ) {
		var field = sink();

		if ( field ) {
			field.focus();
		}

		setFeedback( 'ready', 'Scan Mode active — scan an item barcode or SKU.' );
	} else {
		setFeedback( 'ready', 'Scan Mode exited.' );
	}
}

function refreshChecklistFromItems( items ) {
	var ws = window.MpcfWorkspace;

	if ( ws && typeof ws.refreshChecklist === 'function' ) {
		var field = 'picking' === mode ? 'qty_picked' : 'packing' === mode ? 'qty_packed' : null;
		ws.refreshChecklist( items || [], field );
	}
}

function applyVersion( nextVersion ) {
	var ws = window.MpcfWorkspace;

	if ( ws && ws.store && typeof ws.store.setVersion === 'function' ) {
		ws.store.setVersion( nextVersion );
		return;
	}

	var root = workspaceRoot();

	if ( root && null != nextVersion ) {
		root.setAttribute( 'data-mpcf-version', String( nextVersion ) );
	}
}

function handleScanValue( value ) {
	if ( ! mode || requestLock ) {
		return;
	}

	var trimmed = String( value || '' ).replace( /^\s+|\s+$/g, '' );

	if ( ! trimmed ) {
		return;
	}

	requestLock = true;

	var body = {
		action: 'picking' === mode ? 'pick' : 'pack',
		payload: trimmed,
		version: version(),
	};
	var pkg = activePackageId();

	if ( pkg ) {
		body.active_package_id = pkg;
	}

	api
		.scan( fulfillmentId(), body )
		.then( function ( data ) {
			applyVersion( data.version );
			refreshChecklistFromItems( data.items || [] );
			renderProgress( data.progress );

			if ( data.active_package_id ) {
				pushRecent( 'Package #' + data.active_package_id );
			}

			var label = data.message || data.result || 'OK';
			var kind = 'stage_complete' === data.result || 'item_complete' === data.result || 'quantity_incremented' === data.result
				? 'success'
				: 'package_switched' === data.result || 'fulfillment_identity' === data.result
					? 'warning'
					: 'success';

			setFeedback( kind, label );

			if ( data.item ) {
				pushRecent(
					( data.item.sku_snapshot || data.item.sku || 'item' ) +
						' → ' +
						( 'picking' === mode ? data.item.qty_picked : data.item.qty_packed )
				);
			}
		} )
		.catch( function ( err ) {
			var code = ( err && err.code ) || '';
			var message = ( err && err.message ) || 'Scan failed.';

			if ( 'mpcf_version_conflict' === code ) {
				setFeedback( 'error', 'Not recorded — fulfillment changed. Reload and retry the scan.' );
			} else if ( -1 !== message.toLowerCase().indexOf( 'over' ) || 'over_scan' === ( err && err.guard ) ) {
				setFeedback( 'warning', message );
			} else {
				setFeedback( 'error', message );
			}

			pushRecent( '✗ ' + message );
		} )
		.finally( function () {
			requestLock = false;
			var field = sink();

			if ( field && mode ) {
				field.focus();
			}
		} );
}

function undoLast() {
	if ( ! mode || requestLock ) {
		return;
	}

	requestLock = true;
	api
		.scan( fulfillmentId(), { action: 'undo', version: version() } )
		.then( function ( data ) {
			applyVersion( data.version );
			refreshChecklistFromItems( data.items || [] );
			renderProgress( data.progress );
			setFeedback( 'success', data.message || 'Last scan undone.' );
			pushRecent( '↩ undo' );
		} )
		.catch( function ( err ) {
			setFeedback( 'error', ( err && err.message ) || 'Undo failed.' );
		} )
		.finally( function () {
			requestLock = false;
		} );
}

function canEnter( target ) {
	return stage() === target;
}

/**
 * Keep enter-button `disabled` in sync with `data-mpcf-stage` after AJAX
 * transitions. PHP renders the initial disabled flags for the first paint
 * only; without this, Picking/Packing Scan Mode stays unclickable after
 * Ctrl+Enter advances the stage without a full reload.
 */
function syncEnterButtons() {
	var current = stage();
	var buttons = document.querySelectorAll( '[data-mpcf-scan-mode-enter]' );
	var i;

	for ( i = 0; i < buttons.length; i++ ) {
		buttons[ i ].disabled =
			buttons[ i ].getAttribute( 'data-mpcf-scan-mode-enter' ) !== current;
	}

	if ( mode && mode !== current ) {
		setMode( null );
	}
}

function bindUi() {
	var el = panel();

	if ( ! el ) {
		return;
	}

	el.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-mpcf-scan-mode-enter]' );

		if ( button ) {
			var target = button.getAttribute( 'data-mpcf-scan-mode-enter' );

			if ( ! canEnter( target ) ) {
				setFeedback( 'error', 'Scan Mode is only available during the matching workflow stage.' );
				return;
			}

			setMode( target );
			return;
		}

		if ( event.target.closest( '[data-mpcf-scan-mode-exit]' ) ) {
			setMode( null );
			return;
		}

		if ( event.target.closest( '[data-mpcf-scan-mode-undo]' ) ) {
			undoLast();
			return;
		}

		if ( event.target.closest( '[data-mpcf-scan-mode-sound]' ) ) {
			soundEnabled = ! soundEnabled;
			event.target.setAttribute( 'aria-pressed', soundEnabled ? 'true' : 'false' );
			event.target.textContent = soundEnabled ? 'Sound on' : 'Sound off';
		}
	} );

	document.addEventListener( 'data-mpcf-scan', function ( event ) {
		if ( ! mode ) {
			return;
		}

		handleScanValue( event.detail && event.detail.value );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! mode ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			setMode( null );
		}
	} );
}

function initScanMode() {
	if ( ! workspaceRoot() || ! panel() ) {
		return;
	}

	bindUi();
	window.MpcfWorkspace = window.MpcfWorkspace || {};
	window.MpcfWorkspace.scanModeActive = false;
	window.MpcfWorkspace.enterScanMode = setMode;
	window.MpcfWorkspace.exitScanMode = function () {
		setMode( null );
	};
	window.MpcfWorkspace.syncScanModeButtons = syncEnterButtons;
	syncEnterButtons();
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', initScanMode );
} else {
	initScanMode();
}
