/**
 * Data-table keyboard navigation module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). Scopes to every `[data-mpcf-table]`
 * container: `j`/`k` move a roving row focus, `Enter` activates the focused
 * row (clicks its `[data-mpcf-row-open]` element if one exists), `/` moves
 * focus to the nearest `[data-mpcf-search-focus]` field on the page. Ignored
 * entirely while focus is inside a form field, and while any modifier key is
 * held, so normal typing and browser shortcuts are never intercepted.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes, so this file survives class-prefix rewriting untouched.
 */
( function () {
	'use strict';

	function rows( scope ) {
		return Array.prototype.slice.call( scope.querySelectorAll( '[data-mpcf-row]' ) );
	}

	function focusRow( scope, row ) {
		rows( scope ).forEach( function ( candidate ) {
			candidate.removeAttribute( 'data-mpcf-row-focused' );
			candidate.removeAttribute( 'tabindex' );
		} );

		if ( ! row ) {
			return;
		}

		row.setAttribute( 'data-mpcf-row-focused', '' );
		row.setAttribute( 'tabindex', '-1' );
		row.focus();
	}

	function activateRow( row ) {
		if ( ! row ) {
			return;
		}

		var opener = row.querySelector( '[data-mpcf-row-open]' );

		if ( opener ) {
			opener.click();
		}
	}

	function isFormField( element ) {
		if ( ! element ) {
			return false;
		}

		return -1 !== [ 'INPUT', 'TEXTAREA', 'SELECT' ].indexOf( element.tagName );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var scopes = Array.prototype.slice.call( document.querySelectorAll( '[data-mpcf-table]' ) );

		if ( ! scopes.length ) {
			return;
		}

		scopes.forEach( function ( scope ) {
			scope.addEventListener( 'click', function ( event ) {
				focusRow( scope, event.target.closest( '[data-mpcf-row]' ) );
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey ) {
				return;
			}

			if ( '/' === event.key && ! isFormField( event.target ) ) {
				var searchField = document.querySelector( '[data-mpcf-search-focus]' );

				if ( searchField ) {
					event.preventDefault();
					searchField.focus();
				}

				return;
			}

			if ( isFormField( event.target ) ) {
				return;
			}

			var current = document.querySelector( '[data-mpcf-row-focused]' );
			var scope = current ? current.closest( '[data-mpcf-table]' ) : scopes[ 0 ];

			if ( ! scope ) {
				return;
			}

			var list = rows( scope );

			if ( ! list.length ) {
				return;
			}

			var index = current ? list.indexOf( current ) : -1;

			if ( 'j' === event.key ) {
				event.preventDefault();
				focusRow( scope, list[ Math.min( list.length - 1, index + 1 ) ] );
				return;
			}

			if ( 'k' === event.key ) {
				event.preventDefault();
				focusRow( scope, list[ Math.max( 0, index - 1 ) ] );
				return;
			}

			if ( 'Enter' === event.key && current ) {
				event.preventDefault();
				activateRow( current );
			}
		} );
	} );
} )();
