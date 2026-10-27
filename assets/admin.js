/**
 * Content Powered > Amazon Product Boxes page behaviour. Only loaded on that page.
 *
 * Copy buttons, the "remove credentials" confirmation, the unsaved-changes
 * warning, and the Box Design tab's live preview. apb_admin.i18n is passed
 * in from PHP (see Amazon_Product_Boxes_Admin::enqueue_assets).
 */

/* global apb_admin */
( function () {
	'use strict';

	var data = window.apb_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		initCopy();
		initConfirms();
		initUnsaved();
		initPreview();
	} );

	// Copy buttons ------------------------------------------------------------

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.apb-copy' );
			if ( ! button ) {
				return;
			}

			var text = button.getAttribute( 'data-copy' ) || '';
			var label = button.querySelector( 'span' );
			var original = label ? label.textContent : '';

			function done( ok ) {
				button.classList.toggle( 'is-copied', ok );
				if ( label ) {
					label.textContent = ok ? data.i18n.copied : data.i18n.copyFailed;
					window.setTimeout( function () {
						label.textContent = original;
						button.classList.remove( 'is-copied' );
					}, 1800 );
				}
			}

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( function () {
					done( true );
				}, function () {
					done( false );
				} );
			} else {
				var area = document.createElement( 'textarea' );
				area.value = text;
				area.setAttribute( 'readonly', '' );
				area.style.position = 'absolute';
				area.style.left = '-9999px';
				document.body.appendChild( area );
				area.select();
				var ok = false;
				try {
					ok = document.execCommand( 'copy' );
				} catch ( e ) {
					ok = false;
				}
				document.body.removeChild( area );
				done( ok );
			}
		} );
	}

	// Confirmations ------------------------------------------------------------

	function initConfirms() {
		document.querySelectorAll( '[data-confirm="remove"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( data.i18n.confirmRemove ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	// Unsaved changes ----------------------------------------------------------

	function initUnsaved() {
		var dirty = false;

		document.querySelectorAll( 'form.apb-track' ).forEach( function ( form ) {
			form.addEventListener( 'input', function () {
				dirty = true;
			} );
			form.addEventListener( 'change', function () {
				dirty = true;
			} );
			form.addEventListener( 'submit', function () {
				dirty = false;
			} );
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = data.i18n.unsaved;
				return data.i18n.unsaved;
			}
		} );
	}

	// Live preview -------------------------------------------------------------

	function initPreview() {
		var form = document.querySelector( '.apb-design-form' );
		var preview = document.querySelector( '.apb-preview' );
		if ( ! form || ! preview ) {
			return;
		}

		var api = preview.getAttribute( 'data-api' ) === '1';

		function field( name ) {
			return form.querySelector( '[name="apb[' + name + ']"]' + ( name === 'layout' ? ':checked' : '' ) );
		}

		function checked( name ) {
			var input = field( name );
			return !! ( input && input.checked );
		}

		function update() {
			var box = preview.querySelector( '.apb-box' );
			if ( ! box ) {
				return;
			}

			var layout = field( 'layout' );
			if ( layout ) {
				box.classList.remove( 'apb-layout-horizontal', 'apb-layout-vertical', 'apb-layout-compact' );
				box.classList.add( 'apb-layout-' + layout.value );
			}

			var button = field( 'button_color' );
			var accent = field( 'accent_color' );
			if ( button ) {
				box.style.setProperty( '--apb-button', button.value );
				box.style.setProperty( '--apb-button-text', textOn( button.value ) );
			}
			if ( accent ) {
				box.style.setProperty( '--apb-accent', accent.value );
				box.style.setProperty( '--apb-accent-text', textOn( accent.value ) );
			}

			var text = field( api ? 'button_text' : 'button_text_noapi' );
			var link = box.querySelector( '.apb-button' );
			if ( text && link && text.value.trim() ) {
				link.textContent = text.value.trim();
			}

			var count = field( 'features' );
			var max = count ? parseInt( count.value, 10 ) || 0 : 3;
			box.querySelectorAll( '.apb-features li' ).forEach( function ( item, index ) {
				item.hidden = index >= max;
			} );

			toggle( box, '.apb-prime', checked( 'show_prime' ) );
			toggle( box, '.apb-rating', checked( 'show_rating' ) );
			toggle( box, '.apb-basis', checked( 'show_savings' ) );
			toggle( box, '.apb-save', checked( 'show_savings' ) );
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		update();
	}

	function toggle( box, selector, show ) {
		box.querySelectorAll( selector ).forEach( function ( el ) {
			el.hidden = ! show;
		} );
	}

	// Same rule as Amazon_Product_Boxes_Render::text_on(): the readable one of
	// white or near-black text on a color.
	function textOn( hex ) {
		hex = String( hex || '' ).replace( '#', '' );
		if ( hex.length === 3 ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}
		if ( hex.length !== 6 ) {
			return '#0f1111';
		}

		function channel( part ) {
			var c = parseInt( part, 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		}

		var lum = 0.2126 * channel( hex.substr( 0, 2 ) ) + 0.7152 * channel( hex.substr( 2, 2 ) ) + 0.0722 * channel( hex.substr( 4, 2 ) );

		return 1.05 / ( lum + 0.05 ) >= ( lum + 0.05 ) / 0.0566 ? '#ffffff' : '#0f1111';
	}
} )();
