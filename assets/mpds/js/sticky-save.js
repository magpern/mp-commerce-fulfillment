/**
 * Sticky save / dirty-state module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). Ported from Universal Multicurrency's
 * jQuery `stickySaveModule()`; behavior is unchanged: scope to
 * `[data-mpcf-sticky-root]`, serialize every named field into a sorted-key
 * JSON snapshot, compare on every `change`/`input`, and toggle the unsaved
 * indicator / discard button accordingly.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes, so this file survives class-prefix rewriting untouched (only the
 * `data-mpcf-` -> `data-{prefix}-` rewrite in bin/sync-mpds.sh applies to it).
 */
( function () {
	'use strict';

	function serializeScope( scope ) {
		var state = {};
		var fields = scope.querySelectorAll( 'input, select, textarea' );

		fields.forEach( function ( field ) {
			var name = field.getAttribute( 'name' );

			if ( ! name || field.hasAttribute( 'data-mpcf-sticky-ignore' ) ) {
				return;
			}

			var type = ( field.getAttribute( 'type' ) || '' ).toLowerCase();

			if ( 'checkbox' === type ) {
				state[ name ] = field.checked ? '1' : '0';
				return;
			}

			if ( 'radio' === type ) {
				if ( field.checked ) {
					state[ name ] = String( field.value );
				} else if ( ! Object.prototype.hasOwnProperty.call( state, name ) ) {
					state[ name ] = '';
				}
				return;
			}

			if ( 'hidden' === type && scope.querySelector( '[name="' + name.replace( /"/g, '\\"' ) + '"][type="checkbox"]' ) ) {
				return;
			}

			state[ name ] = String( field.value );
		} );

		return JSON.stringify( state, Object.keys( state ).sort() );
	}

	function initScope( scope ) {
		var scopeId = scope.getAttribute( 'data-mpcf-sticky-root' );
		var bar = document.querySelector( '[data-mpcf-sticky-save][data-mpcf-sticky-scope="' + scopeId + '"]' );

		if ( ! bar ) {
			bar = scope.querySelector( '[data-mpcf-sticky-save]' );
		}

		if ( ! bar ) {
			return;
		}

		var initialSnapshot = serializeScope( scope );

		function setDirtyState( dirty ) {
			bar.hidden = false;

			var indicator = bar.querySelector( '[data-mpcf-unsaved-indicator]' );
			var discard = bar.querySelector( '[data-mpcf-sticky-discard]' );
			var saved = bar.querySelector( '[data-mpcf-sticky-saved]' );

			if ( indicator ) {
				indicator.hidden = ! dirty;
			}

			if ( discard ) {
				discard.hidden = ! dirty;
			}

			if ( saved ) {
				saved.hidden = true;
			}
		}

		function checkDirty() {
			setDirtyState( serializeScope( scope ) !== initialSnapshot );
		}

		setDirtyState( false );

		scope.addEventListener( 'change', checkDirty, true );
		scope.addEventListener( 'input', checkDirty, true );

		var discardButton = bar.querySelector( '[data-mpcf-sticky-discard]' );
		if ( discardButton ) {
			discardButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				window.location.reload();
			} );
		}

		bar.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( 'button[type="submit"]' );

			if ( ! target ) {
				return;
			}

			window.onbeforeunload = null;

			var saved = bar.querySelector( '[data-mpcf-sticky-saved]' );
			if ( saved ) {
				saved.hidden = false;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-mpcf-sticky-root]' ).forEach( initScope );
	} );
} )();
