/**
 * Scan-sink behavior module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). Keeps the `[data-mpcf-scan-sink]`
 * field focused whenever nothing else deliberately holds focus, so a
 * keyboard-wedge barcode scanner's keystrokes land there without the
 * operator clicking anything first (§9.4/M6). Focus is reclaimed only when
 * it lands on `document.body` — never when the operator deliberately
 * focused a form field, a button, or anything else — and the visible
 * `[data-mpcf-scan-status]` indicator flips to a paused state whenever a
 * real form field currently holds focus, so the operator always knows
 * where their next keystroke will land.
 *
 * Buffers keystrokes on a 50ms quiet-period boundary (scanner suffix
 * configuration and typing speed both vary) and treats `Enter`/`Tab` as
 * explicit scan-complete terminators in addition to the quiet period, then
 * dispatches the buffered string on the event type `data-mpcf-scan` — the
 * same deliberately unconventional-looking event-name convention
 * `js/toast.js` establishes, so `bin/sync-mpds.sh`'s existing `data-mpcf-`
 * -> `data-{prefix}-` rewrite rule renames it per consumer automatically,
 * with no new rewrite rule needed. Decoding what the scanned string means
 * (SKU lookup, tracking-number match, mismatch handling) is never this
 * module's job — a consumer's own script subscribes to `data-mpcf-scan`
 * and decides. Manual typing into the field always works exactly the same
 * way; there is no scanner-only code path.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes, so this file survives class-prefix rewriting untouched.
 */
( function () {
	'use strict';

	var QUIET_PERIOD_MS = 50;
	var buffer = '';
	var flushTimer = null;

	function sink() {
		return document.querySelector( '[data-mpcf-scan-sink]' );
	}

	function status() {
		return document.querySelector( '[data-mpcf-scan-status]' );
	}

	function setPaused( paused ) {
		var indicator = status();

		if ( ! indicator ) {
			return;
		}

		indicator.textContent = paused ? 'Paused' : 'Ready';
		indicator.setAttribute( 'data-mpcf-scan-status-state', paused ? 'paused' : 'ready' );
	}

	function flush() {
		if ( '' === buffer ) {
			return;
		}

		var value = buffer;
		buffer = '';

		document.dispatchEvent( new CustomEvent( 'data-mpcf-scan', { detail: { value: value } } ) );
	}

	function scheduleFlush() {
		if ( null !== flushTimer ) {
			window.clearTimeout( flushTimer );
		}

		flushTimer = window.setTimeout( flush, QUIET_PERIOD_MS );
	}

	function isFormField( element ) {
		if ( ! element ) {
			return false;
		}

		return -1 !== [ 'INPUT', 'TEXTAREA', 'SELECT' ].indexOf( element.tagName );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var field = sink();

		if ( ! field ) {
			return;
		}

		field.focus();

		field.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || 'Tab' === event.key ) {
				event.preventDefault();
				// Append any characters not yet taken by `input` (e.g. IME),
				// then flush immediately — do not replace the buffer, or a
				// prior keystroke burst is discarded when the field is empty.
				if ( field.value ) {
					buffer += field.value;
				}
				field.value = '';
				if ( null !== flushTimer ) {
					window.clearTimeout( flushTimer );
					flushTimer = null;
				}
				flush();
			}
		} );

		field.addEventListener( 'input', function () {
			// Field is cleared after each input so `field.value` is only the
			// newest character(s); append into the quiet-period buffer.
			buffer += field.value;
			field.value = '';
			scheduleFlush();
		} );

		document.addEventListener( 'focusout', function () {
			window.setTimeout( function () {
				if ( document.activeElement === document.body ) {
					field.focus();
				}
			}, 0 );
		} );

		document.addEventListener( 'focusin', function ( event ) {
			setPaused( event.target !== field && isFormField( event.target ) );
		} );
	} );
} )();
