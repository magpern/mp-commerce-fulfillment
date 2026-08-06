/**
 * Package photography gallery + capture for the Packing Workspace (M6-C).
 *
 * Vanilla ES module, no getUserMedia / video / editing (ADR-0003/ADR-0006).
 * Uses M6-B REST routes only.
 */

import { api } from './api.js';

var uploadInFlight = false;
var lastKnownSatisfied = null;
var blobUrls = [];

function workspace() {
	return window.MpcfWorkspace;
}

function photoConfig() {
	var cfg = ( window.mpcfWorkspace && window.mpcfWorkspace.photos ) || {};

	return {
		required: !! cfg.required,
		maxPerFulfillment: cfg.maxPerFulfillment || 10,
		maxUploadBytes: cfg.maxUploadBytes || 12582912,
		canCapture: !! cfg.canCapture,
		canDelete: !! cfg.canDelete
	};
}

function notify( message, variant ) {
	document.dispatchEvent(
		new CustomEvent( 'data-mpcf-toast', {
			detail: {
				message: message,
				variant: variant || 'error'
			}
		} )
	);
}

function friendlyError( error ) {
	var code = error && error.code;

	if ( 'mpcf_photo_limit_reached' === code ) {
		return 'Photo limit reached for this fulfillment.';
	}

	if ( 'mpcf_photo_invalid_upload' === code ) {
		return 'That image could not be uploaded. Use JPEG, PNG, or WebP under the size limit.';
	}

	if ( 'mpcf_version_conflict' === code ) {
		return 'This fulfillment changed while you were uploading. Try again.';
	}

	if ( 'mpcf_photo_package_mismatch' === code ) {
		return 'That package does not belong to this fulfillment.';
	}

	if ( 'mpcf_photo_storage_failed' === code ) {
		return 'Unable to store the photo. Try again.';
	}

	return ( error && error.message ) || 'Photo request failed.';
}

function kindLabel( kind ) {
	if ( 'contents' === kind ) {
		return 'Contents';
	}

	if ( 'package' === kind ) {
		return 'Sealed package';
	}

	return kind || 'Unknown';
}

function formatTime( iso ) {
	if ( ! iso ) {
		return '';
	}

	var date = new Date( iso );

	if ( Number.isNaN( date.getTime() ) ) {
		return iso;
	}

	return date.toLocaleString();
}

function mutate( attemptFn ) {
	var ws = workspace();

	if ( ! ws || ! ws.store ) {
		return attemptFn();
	}

	return ws.store.mutate( attemptFn );
}

function revokeBlobUrls() {
	blobUrls.forEach( function ( url ) {
		try {
			URL.revokeObjectURL( url );
		} catch ( e ) {
			// Ignore revoke failures.
		}
	} );
	blobUrls = [];
}

function setRequirementStatus( satisfied ) {
	lastKnownSatisfied = satisfied;
	var cfg = photoConfig();
	var nodes = document.querySelectorAll( '[data-mpcf-photo-requirement-status]' );
	var banners = document.querySelectorAll( '[data-mpcf-photo-requirement-banner]' );
	var text;

	if ( ! cfg.required ) {
		text = '';
	} else if ( satisfied ) {
		text = 'Photo requirement satisfied (sealed-package photo present).';
	} else {
		text = 'Sealed-package photo still required before marking packed.';
	}

	nodes.forEach( function ( node ) {
		node.textContent = text;
		node.classList.toggle( 'mpcf-workspace__photo-requirement--ok', !! ( cfg.required && satisfied ) );
		node.classList.toggle( 'mpcf-workspace__photo-requirement--warn', !! ( cfg.required && ! satisfied ) );
	} );

	banners.forEach( function ( banner ) {
		if ( ! cfg.required ) {
			banner.hidden = true;
			return;
		}

		banner.hidden = false;
		banner.textContent = satisfied
			? 'Photo requirement satisfied — sealed-package photo is on file.'
			: 'A sealed-package photo is required before this fulfillment can be marked packed.';
		banner.classList.toggle( 'mpcf-workspace__photo-requirement--ok', !! satisfied );
		banner.classList.toggle( 'mpcf-workspace__photo-requirement--warn', ! satisfied );
	} );
}

function updateCounts( allPhotos ) {
	var cfg = photoConfig();
	var total = ( allPhotos || [] ).length;
	var max = cfg.maxPerFulfillment;
	var label = total + ' / ' + max + ' photos';

	document.querySelectorAll( '[data-mpcf-photo-count]' ).forEach( function ( node ) {
		node.textContent = label;
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

	revokeBlobUrls();

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

	var img = document.createElement( 'img' );
	img.alt = 'Package photo preview';

	var status = document.createElement( 'p' );
	status.textContent = 'Loading preview…';

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

	api.fetchPhotoBlob( photoId, 'content' )
		.then( function ( blob ) {
			var url = URL.createObjectURL( blob );
			blobUrls.push( url );
			img.src = url;
			status.textContent = '';
			closeBtn.focus();
		} )
		.catch( function ( error ) {
			status.textContent = friendlyError( error );
			notify( friendlyError( error ), 'error' );
		} );
}

function renderGallery( section, photos ) {
	var list = section.querySelector( '[data-mpcf-photo-gallery]' );
	var canDelete = section.getAttribute( 'data-mpcf-can-delete' ) === '1';

	if ( ! list ) {
		return;
	}

	list.innerHTML = '';

	( photos || [] ).forEach( function ( photo ) {
		var li = document.createElement( 'li' );
		li.className = 'mpcf-workspace__photo-card';
		li.setAttribute( 'data-mpcf-photo-id', String( photo.id ) );

		var thumb = document.createElement( 'img' );
		thumb.alt = kindLabel( photo.kind );
		thumb.loading = 'lazy';
		thumb.width = 160;
		thumb.height = 160;

		api.fetchPhotoBlob( photo.id, 'thumb' )
			.then( function ( blob ) {
				var url = URL.createObjectURL( blob );
				blobUrls.push( url );
				thumb.src = url;
			} )
			.catch( function () {
				thumb.alt = 'Thumbnail unavailable';
			} );

		var meta = document.createElement( 'div' );
		meta.className = 'mpcf-workspace__photo-meta';
		meta.innerHTML =
			'<strong>' +
			kindLabel( photo.kind ) +
			'</strong><span>' +
			formatTime( photo.created_at ) +
			'</span>' +
			( photo.captured_by
				? '<span>User #' + String( photo.captured_by ) + '</span>'
				: '' );

		var actions = document.createElement( 'div' );
		actions.className = 'mpcf-workspace__photo-actions';

		var preview = document.createElement( 'button' );
		preview.type = 'button';
		preview.className = 'button';
		preview.textContent = 'Preview';
		preview.addEventListener( 'click', function () {
			openLightbox( photo.id, preview );
		} );
		actions.appendChild( preview );

		if ( canDelete ) {
			var del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'button-link-delete';
			del.textContent = 'Delete';
			del.addEventListener( 'click', function () {
				deletePhoto( photo.id );
			} );
			actions.appendChild( del );
		}

		li.appendChild( thumb );
		li.appendChild( meta );
		li.appendChild( actions );
		list.appendChild( li );
	} );
}

function bindSection( section ) {
	if ( section.getAttribute( 'data-mpcf-photos-bound' ) === '1' ) {
		return;
	}

	section.setAttribute( 'data-mpcf-photos-bound', '1' );

	var input = section.querySelector( '[data-mpcf-photo-input]' );
	var dropzone = section.querySelector( '[data-mpcf-photo-dropzone]' );

	if ( input ) {
		input.addEventListener( 'change', function () {
			var files = input.files ? Array.prototype.slice.call( input.files ) : [];
			input.value = '';
			uploadFiles( section, files );
		} );
	}

	if ( dropzone ) {
		dropzone.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			dropzone.classList.add( 'is-dragover' );
		} );
		dropzone.addEventListener( 'dragleave', function () {
			dropzone.classList.remove( 'is-dragover' );
		} );
		dropzone.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			dropzone.classList.remove( 'is-dragover' );
			var files = event.dataTransfer && event.dataTransfer.files
				? Array.prototype.slice.call( event.dataTransfer.files )
				: [];
			uploadFiles( section, files );
		} );
		dropzone.addEventListener( 'keydown', function ( event ) {
			if ( ( 'Enter' === event.key || ' ' === event.key ) && input ) {
				event.preventDefault();
				input.click();
			}
		} );
	}
}

function uploadFiles( section, files ) {
	if ( uploadInFlight || ! files.length ) {
		return;
	}

	var ws = workspace();
	var fulfillmentId = ws && ws.store ? ws.store.getFulfillmentId() : 0;
	var packageId = parseInt( section.getAttribute( 'data-mpcf-package-id' ) || '0', 10 );
	var kindSelect = section.querySelector( '[data-mpcf-photo-kind]' );
	var kind = kindSelect ? kindSelect.value : 'package';
	var status = section.querySelector( '[data-mpcf-photo-upload-status]' );
	var cfg = photoConfig();
	var capture = section.querySelector( '[data-mpcf-photo-capture]' );

	if ( ! fulfillmentId || ! packageId ) {
		notify( 'Create a package before uploading photos.', 'error' );
		return;
	}

	var queue = files.filter( function ( file ) {
		if ( ! file || ! file.type || 0 !== file.type.indexOf( 'image/' ) ) {
			notify( 'Only image files can be uploaded.', 'error' );
			return false;
		}

		if ( file.size > cfg.maxUploadBytes ) {
			notify( 'Image exceeds the maximum upload size.', 'error' );
			return false;
		}

		return true;
	} );

	if ( ! queue.length ) {
		return;
	}

	uploadInFlight = true;

	if ( capture ) {
		capture.setAttribute( 'aria-busy', 'true' );
	}

	var chain = Promise.resolve();

	queue.forEach( function ( file, index ) {
		chain = chain.then( function () {
			if ( status ) {
				status.textContent = 'Uploading ' + ( index + 1 ) + ' of ' + queue.length + '…';
			}

			return mutate( function () {
				return api.uploadPhoto( fulfillmentId, {
					file: file,
					package_id: packageId,
					kind: kind,
					version: ws.store.getVersion()
				} );
			} ).then( function ( result ) {
				if ( result && typeof result.photo_requirement_satisfied === 'boolean' ) {
					setRequirementStatus( result.photo_requirement_satisfied );
				}
			} );
		} );
	} );

	chain
		.then( function () {
			if ( status ) {
				status.textContent = 'Upload complete.';
			}
			notify( 'Photo uploaded.', 'success' );
			return refreshAll();
		} )
		.catch( function ( error ) {
			notify( friendlyError( error ), 'error' );
			if ( status ) {
				status.textContent = friendlyError( error );
			}
		} )
		.finally( function () {
			uploadInFlight = false;
			if ( capture ) {
				capture.removeAttribute( 'aria-busy' );
			}
		} );
}

function deletePhoto( photoId ) {
	if ( ! window.confirm( 'Delete this package photo?' ) ) {
		return;
	}

	var ws = workspace();
	var fulfillmentId = ws && ws.store ? ws.store.getFulfillmentId() : 0;

	if ( ! fulfillmentId ) {
		return;
	}

	mutate( function () {
		return api.deletePhoto( photoId, ws.store.getVersion() );
	} )
		.then( function ( result ) {
			if ( result && typeof result.photo_requirement_satisfied === 'boolean' ) {
				setRequirementStatus( result.photo_requirement_satisfied );
			}
			notify( 'Photo deleted.', 'success' );
			return refreshAll();
		} )
		.catch( function ( error ) {
			notify( friendlyError( error ), 'error' );
		} );
}

function refreshAll() {
	var root = document.querySelector( '[data-mpcf-workspace]' );
	var ws = workspace();
	var fulfillmentId = ws && ws.store ? ws.store.getFulfillmentId() : 0;

	if ( ! root || ! fulfillmentId ) {
		return Promise.resolve();
	}

	var sections = root.querySelectorAll( '[data-mpcf-photos]' );

	sections.forEach( bindSection );

	return api.listPhotos( fulfillmentId ).then( function ( result ) {
		var all = result.photos || [];
		var cfg = photoConfig();
		var satisfied = all.some( function ( photo ) {
			return photo.kind === 'package';
		} );

		if ( null === lastKnownSatisfied || cfg.required ) {
			setRequirementStatus( ! cfg.required || satisfied );
		}

		updateCounts( all );

		sections.forEach( function ( section ) {
			var packageId = parseInt( section.getAttribute( 'data-mpcf-package-id' ) || '0', 10 );
			var photos = all.filter( function ( photo ) {
				return photo.package_id === packageId;
			} );
			renderGallery( section, photos );
		} );
	} ).catch( function ( error ) {
		notify( friendlyError( error ), 'error' );
	} );
}

document.addEventListener( 'DOMContentLoaded', function () {
	var root = document.querySelector( '[data-mpcf-workspace]' );

	if ( ! root ) {
		return;
	}

	document.addEventListener( 'mpcf-photos-refresh', function () {
		refreshAll();
	} );

	refreshAll();
} );

window.MpcfPhotos = {
	refreshAll: refreshAll
};
