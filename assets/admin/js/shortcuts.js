/**
 * The Packing Workspace's full keyboard map (Architecture Plan §IV.5.3)
 * and the `?` shortcut sheet.
 *
 * Vanilla ES module, no build step (ADR-0003/ADR-0006). Roving checklist
 * focus mirrors the vendored `data-table-keynav.js` convention exactly —
 * `data-mpcf-row-focused` + a roving `tabindex="-1"` on the row itself,
 * never on the quantity input inside it, so resting focus only ever
 * leaves the scan sink for a deliberate interaction (§IV.5.4, rule 1) and
 * every single-letter/space/enter binding is suppressed the same way,
 * uniformly, whenever `document.activeElement` is a real form field the
 * operator is deliberately typing prose/data into.
 *
 * The scan sink is deliberately excluded from that check even though it
 * is itself an `<input>` — it holds *resting* focus by design (§IV.5.4
 * rule 1), which is the default, most common state on this screen, not a
 * deliberate-typing state; treating it as an ordinary form field would
 * suppress every single-letter shortcut under the single most common
 * condition they need to work in. Found the hard way: no PHPUnit test
 * ever presses a real key while a real scan-sink input holds real focus,
 * so this was silently broken from F17 until the Playwright suite's
 * first real keyboard-only run caught it (F22).
 *
 * `t`/`w`/`n`/`P`/`[`/`]`/`p` target selectors that F18-F20 have not all
 * built yet (`data-mpcf-queue-prev/next`, `data-mpcf-open-problem-modal`)
 * — each is a documented no-op today and activates automatically once
 * that commit lands, the same seaming pattern the scan sink itself uses
 * for M6 (§IV.5.5).
 *
 * Known, deliberately deferred tension for M6: a real barcode whose
 * payload contains one of these letters would have each such keystroke
 * intercepted (`preventDefault()`ed) here before `scan-sink.js`'s own
 * 50ms-quiet-period buffering ever sees it, corrupting the captured
 * string. M2 ships no scan decoding at all (§IV.5.5 — "captures and
 * displays... does not decode"), so there is no real scanning workflow
 * to protect yet; M6, which adds real decoding and is explicitly tasked
 * with "scan mismatch groundwork", is the milestone with real hardware
 * to validate a disambiguation strategy against, not this one.
 */

import { incrementRow, decrementRow, completeRow, completeAllRows, toggleCollapseCompleted } from './packing.js';
import { printPrimaryDocument } from './documents.js';

function isFormField( element ) {
	if ( ! element || -1 === [ 'INPUT', 'TEXTAREA', 'SELECT' ].indexOf( element.tagName ) ) {
		return false;
	}

	return ! element.hasAttribute( 'data-mpcf-scan-sink' );
}

function modalIsOpen() {
	return null !== document.querySelector( '[data-mpcf-modal]:not([hidden])' );
}

function checklistRows() {
	return Array.prototype.slice.call( document.querySelectorAll( '.mpcf-ui-checklist__row' ) ).filter( function ( row ) {
		return ! row.hidden;
	} );
}

function currentChecklistRow() {
	return document.querySelector( '.mpcf-ui-checklist__row[data-mpcf-row-focused]' );
}

function focusChecklistRow( row ) {
	checklistRows().forEach( function ( candidate ) {
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

function moveChecklistFocus( delta ) {
	var rows = checklistRows();

	if ( ! rows.length ) {
		return;
	}

	var current = currentChecklistRow();
	var index = current ? rows.indexOf( current ) : -1;
	var next = Math.max( 0, Math.min( rows.length - 1, index + delta ) );

	focusChecklistRow( rows[ next ] );
}

function focusField( selector ) {
	var field = document.querySelector( selector );

	if ( field ) {
		field.focus();
	}
}

function click( selector ) {
	var element = document.querySelector( selector );

	if ( element ) {
		element.click();
	}
}

/**
 * Return-focus-to-opener on modal close (§IV.5.4, rule 3 — `modal.js`
 * itself only owns autofocus-on-open). Tracked via a `MutationObserver`
 * on each modal's `hidden` attribute rather than the close click/Escape
 * events directly, so the return fires identically regardless of what
 * closed the modal.
 */
var modalOpener = null;

function rememberOpener( event ) {
	var opener = event.target.closest( '[data-mpcf-modal-open]' );

	if ( opener ) {
		modalOpener = opener;
	}
}

function returnFocusFromModal() {
	if ( modalOpener && document.body.contains( modalOpener ) ) {
		modalOpener.focus();
	} else if ( window.MpcfWorkspace ) {
		window.MpcfWorkspace.focus.restingFocus();
	}

	modalOpener = null;
}

function observeModals() {
	document.querySelectorAll( '[data-mpcf-modal]' ).forEach( function ( modal ) {
		if ( 'mpcf-reason-modal' === modal.id ) {
			// workspace.js opens this one programmatically (not via a
			// [data-mpcf-modal-open] trigger this module ever sees) and
			// owns its capture/restore exclusively (§IV.5.4) — tracking it
			// here too would restore focus to a stale or wrong element.
			return;
		}

		var wasHidden = modal.hidden;

		new MutationObserver( function () {
			if ( modal.hidden && ! wasHidden ) {
				returnFocusFromModal();
			}

			wasHidden = modal.hidden;
		} ).observe( modal, { attributes: true, attributeFilter: [ 'hidden' ] } );
	} );
}

document.addEventListener( 'DOMContentLoaded', function () {
	if ( ! document.querySelector( '[data-mpcf-workspace]' ) ) {
		return;
	}

	observeModals();
	document.addEventListener( 'click', rememberOpener );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.defaultPrevented || event.metaKey || event.altKey || event.ctrlKey ) {
			return;
		}

		if ( isFormField( event.target ) || modalIsOpen() || ( window.MpcfWorkspace && window.MpcfWorkspace.scanModeActive ) ) {
			return;
		}

		switch ( event.key ) {
			case 'j':
				event.preventDefault();
				moveChecklistFocus( 1 );
				break;

			case 'k':
				event.preventDefault();
				moveChecklistFocus( -1 );
				break;

			case ' ':
				if ( currentChecklistRow() ) {
					event.preventDefault();

					if ( event.shiftKey ) {
						decrementRow( currentChecklistRow() );
					} else {
						incrementRow( currentChecklistRow() );
					}
				}

				break;

			case 'Enter':
				if ( currentChecklistRow() ) {
					event.preventDefault();
					incrementRow( currentChecklistRow() );
				}

				break;

			case 'a':
				if ( currentChecklistRow() ) {
					event.preventDefault();
					completeRow( currentChecklistRow() );
				}

				break;

			case 'A':
				event.preventDefault();
				completeAllRows();
				break;

			case 'c':
				event.preventDefault();
				toggleCollapseCompleted();
				break;

			case '/':
				event.preventDefault();

				if ( window.MpcfWorkspace ) {
					window.MpcfWorkspace.focus.restingFocus();
				}

				break;

			case 't':
				event.preventDefault();
				focusField( '[data-mpcf-tracking-number]' );
				break;

			case 'w':
				event.preventDefault();
				focusField( '[data-mpcf-package-field="weight_grams"]' );
				break;

			case 'n':
				event.preventDefault();
				focusField( '[data-mpcf-new-note]' );
				break;

			case 'p':
				event.preventDefault();
				click( '[data-mpcf-open-problem-modal]' );
				break;

			case 'P':
				event.preventDefault();
				printPrimaryDocument();
				break;

			case '[':
				event.preventDefault();
				click( '[data-mpcf-queue-prev]' );
				break;

			case ']':
				event.preventDefault();
				click( '[data-mpcf-queue-next]' );
				break;

			case '?':
				event.preventDefault();
				click( '[data-mpcf-modal-open="mpcf-shortcut-sheet"]' );
				break;

			default:
				break;
		}
	} );
} );
