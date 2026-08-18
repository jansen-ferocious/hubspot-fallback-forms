/**
 * HubSpot Fallback Forms — admin (sync / remove cached forms).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.HFFAdmin || {};

	function post( action, formId ) {
		return $.post( cfg.ajaxUrl, {
			action: action,
			nonce: cfg.nonce,
			form_id: formId
		} );
	}

	function status( msg, type ) {
		var $s = $( '#hff_sync_status' );
		$s.text( msg || '' ).removeClass( 'is-ok is-err' );
		if ( type ) { $s.addClass( type === 'error' ? 'is-err' : 'is-ok' ); }
	}

	function syncForm( formId, $btn ) {
		if ( ! formId ) {
			status( 'Enter a form ID first.', 'error' );
			return;
		}
		var original = $btn ? $btn.text() : '';
		if ( $btn ) { $btn.prop( 'disabled', true ).text( cfg.syncing ); }
		status( cfg.syncing, '' );

		post( 'hff_sync_form', formId )
			.done( function ( res ) {
				if ( res && res.success ) {
					status( res.data.message, 'ok' );
					upsertRow( formId, res.data.row );
					$( '#hff_sync_form_id' ).val( '' );
				} else {
					status( ( res && res.data && res.data.message ) || 'Sync failed.', 'error' );
				}
			} )
			.fail( function ( xhr ) {
				var m = 'Sync failed.';
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) { m = xhr.responseJSON.data.message; }
				status( m, 'error' );
			} )
			.always( function () {
				if ( $btn ) { $btn.prop( 'disabled', false ).text( original || 'Sync form' ); }
			} );
	}

	function upsertRow( formId, rowHtml ) {
		$( '.hff-empty-row' ).remove();
		var $existing = $( 'tr[data-form-id="' + $.escapeSelector( formId ) + '"]' );
		if ( $existing.length ) {
			$existing.replaceWith( rowHtml );
		} else {
			$( '#hff_forms_tbody' ).append( rowHtml );
		}
	}

	$( function () {
		$( '#hff_sync_btn' ).on( 'click', function () {
			syncForm( $.trim( $( '#hff_sync_form_id' ).val() ), $( this ) );
		} );

		$( '#hff_sync_form_id' ).on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				$( '#hff_sync_btn' ).trigger( 'click' );
			}
		} );

		// Re-sync / Remove (delegated).
		$( '#hff_forms_tbody' )
			.on( 'click', '.hff-resync', function () {
				syncForm( $( this ).data( 'form-id' ), $( this ) );
			} )
			.on( 'click', '.hff-preview', function () {
				var $btn   = $( this );
				var formId = $btn.data( 'form-id' );
				var orig   = $btn.text();
				$btn.prop( 'disabled', true ).text( cfg.loading );
				post( 'hff_preview_form', formId )
					.done( function ( res ) {
						if ( res && res.success ) {
							$( '#hff_preview' ).html( res.data.html );
							$( '#hff_preview_wrap' ).show();
							var el = document.getElementById( 'hff_preview_wrap' );
							if ( el && el.scrollIntoView ) { el.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
						} else {
							status( ( res && res.data && res.data.message ) || 'Preview failed.', 'error' );
						}
					} )
					.fail( function ( xhr ) {
						var m = 'Preview failed.';
						if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) { m = xhr.responseJSON.data.message; }
						status( m, 'error' );
					} )
					.always( function () {
						$btn.prop( 'disabled', false ).text( orig );
					} );
			} )
			.on( 'click', '.hff-test', function () {
				var $btn   = $( this );
				var formId = $btn.data( 'form-id' );
				var orig   = $btn.text();
				$btn.prop( 'disabled', true ).text( cfg.sending );
				status( cfg.sending, '' );
				post( 'hff_test_email', formId )
					.done( function ( res ) {
						if ( res && res.success ) {
							status( res.data.message, 'ok' );
						} else {
							status( ( res && res.data && res.data.message ) || 'Test failed.', 'error' );
						}
					} )
					.fail( function ( xhr ) {
						var m = 'Test failed.';
						if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) { m = xhr.responseJSON.data.message; }
						status( m, 'error' );
					} )
					.always( function () {
						$btn.prop( 'disabled', false ).text( orig );
					} );
			} )
			.on( 'click', '.hff-remove', function () {
				var formId = $( this ).data( 'form-id' );
				if ( ! window.confirm( cfg.confirmDel ) ) { return; }
				var $row = $( this ).closest( 'tr' );
				post( 'hff_remove_form', formId ).done( function ( res ) {
					if ( res && res.success ) {
						$row.remove();
						status( res.data.message, 'ok' );
						if ( ! $( '#hff_forms_tbody tr' ).length ) {
							$( '#hff_forms_tbody' ).append( '<tr class="hff-empty-row"><td colspan="5">No forms cached yet.</td></tr>' );
						}
					}
				} );
			} );

		// Close the preview panel.
		$( '#hff_preview_close' ).on( 'click', function () {
			$( '#hff_preview_wrap' ).hide();
			$( '#hff_preview' ).empty();
		} );

		// Never actually submit from the preview.
		$( '#hff_preview' ).on( 'submit', 'form', function ( e ) {
			e.preventDefault();
		} );
	} );
} )( jQuery );
