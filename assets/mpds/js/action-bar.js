/**
 * Action bar behavior module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). `Ctrl`/`Cmd`+`Enter` anywhere on the
 * page clicks the current `[data-mpcf-primary-action]` button, unless it
 * is disabled. Deliberately not restricted to a particular focus location
 * (unlike the row-navigation module's single-letter shortcuts, which
 * suppress while a form field has focus): the sticky action bar's
 * one-button design (§9.4) depends on the shortcut working while the
 * operator is mid-type in a field — a tracking number, a weight — since
 * that is exactly when they are ready to advance.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes, so this file survives class-prefix rewriting untouched.
 */
( function () {
	'use strict';

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' !== event.key || ! ( event.metaKey || event.ctrlKey ) ) {
			return;
		}

		var button = document.querySelector( '[data-mpcf-primary-action]' );

		if ( ! button || button.disabled ) {
			return;
		}

		event.preventDefault();
		button.click();
	} );
} )();
