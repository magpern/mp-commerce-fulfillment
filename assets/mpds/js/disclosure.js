/**
 * Disclosure toggle module.
 *
 * Vanilla ES module. Ported from Universal Multicurrency's compatibility
 * evidence-toggle behavior, generalized: any `[data-mpcf-disclosure-toggle]`
 * button controls the panel named by its `aria-controls` id, flipping
 * `aria-expanded` and the panel's `hidden` attribute. Optional
 * `data-mpcf-label-expanded` / `data-mpcf-label-collapsed` attributes on the
 * button swap its visible text — read from markup rather than a bespoke
 * localized JS strings object, since this library ships no text domain.
 */
( function () {
	'use strict';

	function toggle( button ) {
		var panelId = button.getAttribute( 'aria-controls' );
		var panel = panelId ? document.getElementById( panelId ) : null;

		if ( ! panel ) {
			return;
		}

		var expanded = button.getAttribute( 'aria-expanded' ) === 'true';

		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		panel.hidden = expanded;

		var labelExpanded = button.getAttribute( 'data-mpcf-label-expanded' );
		var labelCollapsed = button.getAttribute( 'data-mpcf-label-collapsed' );

		if ( expanded && labelCollapsed ) {
			button.textContent = labelCollapsed;
		} else if ( ! expanded && labelExpanded ) {
			button.textContent = labelExpanded;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-mpcf-disclosure-toggle]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				toggle( button );
			} );
		} );
	} );
} )();
