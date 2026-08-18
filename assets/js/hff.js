/**
 * HubSpot Fallback Forms — front-end submission handling.
 * Submits via admin-ajax with fetch; falls back to a normal POST if fetch
 * is unavailable.
 */
( function () {
	'use strict';

	function ajaxUrl() {
		return ( window.HFFData && window.HFFData.ajaxUrl ) || '/wp-admin/admin-ajax.php';
	}

	function onSubmit( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'hff-form' ) ) {
			return;
		}
		e.preventDefault();

		var wrap    = form.closest( '.hff-form-wrap' );
		var msgEl   = form.querySelector( '.hff-message' );
		var button  = form.querySelector( '.hff-submit' );
		var spinner = form.querySelector( '.hff-spinner' );

		// Native HTML5 validation first.
		if ( typeof form.reportValidity === 'function' && ! form.reportValidity() ) {
			return;
		}

		setMessage( msgEl, '', '' );
		if ( button ) { button.disabled = true; }
		if ( spinner ) { spinner.classList.add( 'is-active' ); }

		var data = new FormData( form );

		fetch( ajaxUrl(), {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					var okMsg = ( res.data && res.data.message ) || 'Thank you. Your submission has been received.';
					if ( wrap ) {
						wrap.innerHTML = '<div class="hff-success" role="status">' + escapeHtml( okMsg ) + '</div>';
					} else {
						setMessage( msgEl, okMsg, 'success' );
						form.reset();
					}
				} else {
					var errMsg = ( res && res.data && res.data.message ) || 'Something went wrong. Please try again.';
					setMessage( msgEl, errMsg, 'error' );
					restore( button, spinner );
				}
			} )
			.catch( function () {
				setMessage( msgEl, 'Network error. Please try again.', 'error' );
				restore( button, spinner );
			} );
	}

	function restore( button, spinner ) {
		if ( button ) { button.disabled = false; }
		if ( spinner ) { spinner.classList.remove( 'is-active' ); }
	}

	function setMessage( el, text, type ) {
		if ( ! el ) { return; }
		el.textContent = text || '';
		el.className = 'hff-message' + ( type ? ' hff-message-' + type : '' );
	}

	function escapeHtml( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	document.addEventListener( 'submit', onSubmit, false );
} )();
