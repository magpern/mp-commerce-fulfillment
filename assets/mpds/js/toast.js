/**
 * Toast (transient notification) behavior module.
 *
 * Vanilla ES module, no jQuery (house discipline — see ADR-0003 in the
 * consuming plugin's architecture). Unlike every other module in this
 * directory, a toast has no server-known content at page-render time — it
 * is triggered by a consumer's own script reacting to an async result (a
 * save, a conflict, a completed action) — so there is no `[data-mpcf-*]`
 * element for this module to discover on `DOMContentLoaded` and enhance.
 * Instead, a consumer dispatches:
 *
 *   document.dispatchEvent(new CustomEvent('data-mpcf-toast', {
 *     detail: { message, variant, persistent, duration, actionLabel, actionHref }
 *   }))
 *
 * `variant` is one of "default" (omit), "success", "warning", "error".
 * `persistent` (bool) skips auto-dismiss entirely — for a conflict a
 * consumer wants the operator to explicitly acknowledge. `duration`
 * overrides the 5s default. The event type is the unusual-looking string
 * `"data-mpcf-toast"` rather than a conventional `namespace:event` name —
 * deliberately, so that `bin/sync-mpds.sh`'s existing `data-mpcf-` ->
 * `data-{prefix}-` rewrite rule renames it per consumer automatically (no
 * new rewrite rule needed), the same guarantee every `data-mpcf-*`
 * attribute already gets: two different MP Commerce plugins vendoring this
 * file never collide on the same event name.
 *
 * This module fabricates a new DOM node per toast, which every other
 * module here avoids needing to do (they only toggle state — `hidden`,
 * `aria-*`, a `data-mpcf-*` attribute — on markup a consumer's own PHP
 * already rendered). It gets away with never referencing a component CSS
 * class string itself by cloning the `<template data-mpcf-toast-template>`
 * a consumer's `ComponentRenderer::toast_region()` call rendered once per
 * page — every class the clone needs was already baked in server-side;
 * this module only ever sets `data-mpcf-*` attributes and text content on
 * it afterward.
 *
 * Keyed entirely on `data-mpcf-*` attributes — never on component CSS
 * classes, so this file survives class-prefix rewriting untouched.
 */
( function () {
	'use strict';

	var DEFAULT_DURATION_MS = 5000;

	function region() {
		return document.querySelector( '[data-mpcf-toast-region]' );
	}

	function dismiss( toast ) {
		if ( toast.parentNode ) {
			toast.parentNode.removeChild( toast );
		}
	}

	function scheduleDismiss( toast, duration ) {
		var remaining = duration;
		var timer = null;
		var startedAt = null;

		function start() {
			startedAt = new Date().getTime();
			timer = window.setTimeout( function () {
				dismiss( toast );
			}, remaining );
		}

		function pause() {
			if ( null === timer ) {
				return;
			}

			window.clearTimeout( timer );
			timer = null;
			remaining = Math.max( 0, remaining - ( new Date().getTime() - startedAt ) );
		}

		toast.addEventListener( 'mouseenter', pause );
		toast.addEventListener( 'focusin', pause );
		toast.addEventListener( 'mouseleave', start );
		toast.addEventListener( 'focusout', start );

		start();
	}

	function showToast( detail ) {
		var host = region();
		var template = host ? host.querySelector( '[data-mpcf-toast-template]' ) : null;

		if ( ! host || ! template || ! template.content ) {
			return;
		}

		var toast = template.content.firstElementChild.cloneNode( true );
		var message = toast.querySelector( '[data-mpcf-toast-message]' );
		var action = toast.querySelector( '[data-mpcf-toast-action]' );

		if ( message ) {
			message.textContent = detail.message || '';
		}

		if ( detail.variant && 'default' !== detail.variant ) {
			toast.setAttribute( 'data-mpcf-toast-variant', detail.variant );
		}

		if ( action && detail.actionLabel && detail.actionHref ) {
			action.textContent = detail.actionLabel;
			action.setAttribute( 'href', detail.actionHref );
			action.hidden = false;
		}

		host.appendChild( toast );

		if ( ! detail.persistent ) {
			scheduleDismiss( toast, detail.duration || DEFAULT_DURATION_MS );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.addEventListener( 'data-mpcf-toast', function ( event ) {
			showToast( event.detail || {} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var closer = event.target.closest( '[data-mpcf-toast-dismiss]' );

			if ( ! closer ) {
				return;
			}

			var toast = closer.closest( '[data-mpcf-toast]' );

			if ( toast ) {
				dismiss( toast );
			}
		} );
	} );
} )();
