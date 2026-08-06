/**
 * Fulfillment Detail CS photo gallery — thumbnails + lightbox via protected REST.
 * Read-only; no upload/delete. Reuses window.mpcfWorkspace restUrl/nonce.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-mpcf-detail-photos]' );

	if ( ! root || ! window.mpcfWorkspace ) {
		return;
	}

	var cfg = window.mpcfWorkspace;
	var blobUrls = [];

	function restBase() {
		return String( cfg.restUrl || '' ).replace( /\/?$/, '/' );
	}

	function fetchBlob( photoId, which ) {
		var url = restBase() + 'photos/' + encodeURIComponent( String( photoId ) ) + '/' + which;
		return fetch( url, {
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce || ''
			}
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'preview_failed' );
			}
			return response.blob();
		} );
	}

	function closeLightbox() {
		var dialog = document.querySelector( '[data-mpcf-photo-lightbox]' );
		if ( ! dialog ) {
			return;
		}
		var returnFocus = dialog._mpcfReturnFocus;
		if ( typeof dialog.close === 'function' ) {
			dialog.close();
		}
		if ( dialog.parentNode ) {
			dialog.parentNode.removeChild( dialog );
		}
		blobUrls.forEach( function ( u ) {
			URL.revokeObjectURL( u );
		} );
		blobUrls = [];
		if ( returnFocus && typeof returnFocus.focus === 'function' ) {
			returnFocus.focus();
		}
	}

	function openLightbox( photoId, returnFocus ) {
		closeLightbox();
		var dialog = document.createElement( 'dialog' );
		dialog.className = 'mpcf-workspace__photo-lightbox';
		dialog.setAttribute( 'data-mpcf-photo-lightbox', '' );
		dialog._mpcfReturnFocus = returnFocus;

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'button';
		closeBtn.textContent = 'Close';
		closeBtn.addEventListener( 'click', closeLightbox );

		var status = document.createElement( 'p' );
		status.textContent = 'Loading preview…';

		var img = document.createElement( 'img' );
		img.alt = 'Package photo preview';

		dialog.appendChild( closeBtn );
		dialog.appendChild( status );
		dialog.appendChild( img );
		document.body.appendChild( dialog );
		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			closeLightbox();
		} );
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		}

		fetchBlob( photoId, 'content' )
			.then( function ( blob ) {
				var url = URL.createObjectURL( blob );
				blobUrls.push( url );
				img.src = url;
				status.textContent = '';
				closeBtn.focus();
			} )
			.catch( function () {
				status.textContent = 'Preview unavailable.';
			} );
	}

	root.querySelectorAll( '[data-mpcf-detail-photo-thumb]' ).forEach( function ( node ) {
		var photoId = parseInt( node.getAttribute( 'data-mpcf-detail-photo-thumb' ) || '0', 10 );
		if ( ! photoId ) {
			return;
		}
		fetchBlob( photoId, 'thumb' )
			.then( function ( blob ) {
				var url = URL.createObjectURL( blob );
				blobUrls.push( url );
				var img = document.createElement( 'img' );
				img.src = url;
				img.alt = 'Package photo thumbnail';
				img.width = 120;
				img.height = 120;
				node.textContent = '';
				node.appendChild( img );
			} )
			.catch( function () {
				node.textContent = 'Unavailable';
			} );
	} );

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-mpcf-detail-photo-preview]' );
		if ( ! button || ! root.contains( button ) ) {
			return;
		}
		var photoId = parseInt( button.getAttribute( 'data-mpcf-detail-photo-preview' ) || '0', 10 );
		if ( photoId ) {
			openLightbox( photoId, button );
		}
	} );
}() );
