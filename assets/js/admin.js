/**
 * Admin UI -- autodetect scripts + inline script blocks accordion.
 * Data injected by PHP via wp_localize_script as dcSwpAdminData.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global dcSwpAdminData */

// -- Partytown Script List (per-entry rows) -----------------------------------
( function ( $ ) {
	const scriptEntries   = ( dcSwpAdminData.scriptListEntries || [] ).map( function ( e ) {
		return { pattern: e.pattern || '', category: e.category || 'marketing' };
	} );
	const cats          = dcSwpAdminData.consentCategories || [ 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ];
	const noEntriesMsg  = dcSwpAdminData.noEntriesMsg || 'No patterns added yet.';
	const hostCatMap    = dcSwpAdminData.hostCategoryMap || {};

	/** Suggest a WP Consent API category for a hostname (substring-matched). */
	function suggestCategory( host ) {
		host = ( host || '' ).toLowerCase();
		for ( const key in hostCatMap ) {
			if ( Object.prototype.hasOwnProperty.call( hostCatMap, key ) ) {
				if ( host.indexOf( key ) !== -1 || key.indexOf( host ) !== -1 ) {
					return hostCatMap[ key ];
				}
			}
		}
		return 'marketing';
	}

	/** Build the category <select> HTML. */
	function buildCatSelect( curCat, cls ) {
		const gateOn = $( '#dc_swp_consent_gate' ).prop( 'checked' );
		let html   = '<select class="' + cls + '"' + ( gateOn ? '' : ' style="display:none"' ) + '>';
		$.each( cats, function ( _i, cv ) {
			html += '<option value="' + cv + '"' + ( cv === curCat ? ' selected' : '' ) + '>' + cv.charAt( 0 ).toUpperCase() + cv.slice( 1 ) + '</option>';
		} );
		html += '</select>';
		return html;
	}

	function renderScriptList() {
		const $list = $( '#dc-swp-script-list' );
		$list.empty();
		if ( ! scriptEntries.length ) {
			$list.append( '<p style="color:#888;font-style:italic;margin:0 0 4px">' + $( '<span>' ).text( noEntriesMsg ).html() + '</p>' );
			return;
		}
		$.each( scriptEntries, function ( idx, entry ) {
			$list.append( buildScriptEntryRow( idx, entry ) );
		} );
	}

	function buildScriptEntryRow( idx, entry ) {
		const catSel = buildCatSelect( entry.category || 'marketing', 'dc-swp-sl-cat' );
		return $(
			'<div class="dc-swp-sl-row" data-idx="' + idx + '" style="display:flex;align-items:center;gap:6px;margin-bottom:5px">' +
			'<input type="text" class="dc-swp-sl-pattern regular-text code" value="' + $( '<span>' ).text( entry.pattern ).html() + '" style="flex:1;font-family:monospace;font-size:12px" placeholder="e.g. static.klaviyo.com">' +
			catSel +
			'<button type="button" class="dc-swp-sl-del button-link" style="color:#a00;padding:4px 8px;flex-shrink:0">&times;</button>' +
			'</div>'
		);
	}

	renderScriptList();

	// Add blank row.
	$( '#dc-swp-add-pattern-btn' ).on( 'click', function () {
		scriptEntries.push( { pattern: '', category: 'marketing' } );
		const idx = scriptEntries.length - 1;
		const $row = buildScriptEntryRow( idx, scriptEntries[ idx ] );
		const $list = $( '#dc-swp-script-list' );
		$list.find( 'p' ).remove(); // Remove "no entries" message.
		$list.append( $row );
		$row.find( '.dc-swp-sl-pattern' ).focus();
	} );

	// Live edit -- pattern.
	$( document ).on( 'input', '.dc-swp-sl-pattern', function () {
		const idx = $( this ).closest( '.dc-swp-sl-row' ).data( 'idx' );
		if ( scriptEntries[ idx ] !== undefined ) {
			scriptEntries[ idx ].pattern = $( this ).val();
		}
	} );

	// Live edit -- category.
	$( document ).on( 'change', '.dc-swp-sl-cat', function () {
		const idx = $( this ).closest( '.dc-swp-sl-row' ).data( 'idx' );
		if ( scriptEntries[ idx ] !== undefined ) {
			scriptEntries[ idx ].category = $( this ).val();
		}
	} );

	// Delete row.
	$( document ).on( 'click', '.dc-swp-sl-del', function () {
		const $row = $( this ).closest( '.dc-swp-sl-row' );
		const idx  = $row.data( 'idx' );
		scriptEntries.splice( idx, 1 );
		renderScriptList();
	} );

	// Sync to hidden field on submit.
	$( 'form.pwa-cache-settings' ).on( 'submit', function () {
		// Collect live DOM values in case user typed without triggering input event.
		$( '.dc-swp-sl-row' ).each( function () {
			const idx = $( this ).data( 'idx' );
			if ( scriptEntries[ idx ] !== undefined ) {
				scriptEntries[ idx ].pattern  = $( this ).find( '.dc-swp-sl-pattern' ).val();
				scriptEntries[ idx ].category = $( this ).find( '.dc-swp-sl-cat' ).val() || 'marketing';
			}
		} );
		// Filter out blank patterns before saving.
		const toSave = scriptEntries.filter( function ( e ) { return ( e.pattern || '' ).trim() !== ''; } );
		$( '#dc_swp_partytown_entries_json' ).val( JSON.stringify( toSave ) );
	} );

	// Expose helper for auto-detect section below.
	window.dcSwpAddScriptEntry = function ( host, category ) {
		// Avoid duplicates.
		const already = scriptEntries.some( function ( e ) { return e.pattern === host; } );
		if ( already ) { return; }
		scriptEntries.push( { pattern: host, category: category || suggestCategory( host ) } );
		renderScriptList();
	};
	window.dcSwpSuggestCategory = suggestCategory;

} )( jQuery );

// -- Autodetect scripts -------------------------------------------------------
jQuery( function ( $ ) {
	const nonce        = dcSwpAdminData.nonce;
	const noScriptsMsg = dcSwpAdminData.noScriptsMsg;

	$( '#dc-swp-autodetect-btn' ).on( 'click', function () {
		const $btn      = $( this );
		const $spin     = $( '#dc-swp-autodetect-spinner' );
		const $res      = $( '#dc-swp-autodetect-results' );
		const $list     = $( '#dc-swp-autodetect-list' );
		const unknownMsg = dcSwpAdminData.unknownMsg;
		const knownMsg   = dcSwpAdminData.knownMsg;

		$btn.prop( 'disabled', true );
		$spin.css( 'display', 'inline-block' );
		$res.hide();

		$.post( ajaxurl, { action: 'dc_swp_detect_scripts', nonce: nonce }, function ( r ) {
			$btn.prop( 'disabled', false );
			$spin.hide();

			const scripts = ( r.success && r.data && r.data.scripts ) ? r.data.scripts : [];

			if ( ! scripts.length ) {
				$list.html( '<em>' + $( '<span>' ).text( noScriptsMsg ).html() + '</em>' );
				$( '#dc-swp-add-selected' ).hide();
			} else {
				let html = '';
				$.each( scripts, function ( _i, item ) {
					const safe    = $( '<span>' ).text( item.host ).html();
					const badgeEl = $( '<span>' );
					if ( item.known ) {
						badgeEl.text( knownMsg ).css( { color: '#00a32a', 'font-size': '11px', 'margin-left': '6px' } );
					} else {
						badgeEl.text( '\u26a0 ' + unknownMsg ).css( { color: '#d63638', 'font-size': '11px', 'margin-left': '6px' } );
					}
					const badge   = badgeEl[0].outerHTML;
					const checked = item.known ? ' checked' : '';
					html += '<label style="display:block;margin:3px 0"><input type="checkbox" value="' + safe + '"' + checked + '> <code>' + safe + '</code>' + badge + '</label>';
				} );
				$list.html( html );
				$( '#dc-swp-add-selected' ).show();
			}
			$res.show();
		} ).fail( function () { $btn.prop( 'disabled', false ); $spin.hide(); } );
	} );

	$( '#dc-swp-add-selected' ).on( 'click', function () {
		$( '#dc-swp-autodetect-list' ).find( 'input[type="checkbox"]:checked' ).each( function () {
			const host = $( this ).val();
			if ( typeof window.dcSwpAddScriptEntry === 'function' ) {
				window.dcSwpAddScriptEntry( host );
			}
		} );
		$( '#dc-swp-autodetect-results' ).fadeOut();
	} );
} );

// -- Inline script blocks accordion ------------------------------------------
( function ( $ ) {
	let blocks      = dcSwpAdminData.blocks;
	const noBlocksMsg  = dcSwpAdminData.noBlocksMsg;
	const delMsg       = dcSwpAdminData.delMsg;
	const knownServices = dcSwpAdminData.knownServices || [];

	/** Extract all external src= URLs from a script block's code string. */
	function extractSrcUrls( code ) {
		const re  = /<script\b[^>]+\bsrc=["']([^"']+)["']/gi;
		const out = [];
		let m;
		while ( ( m = re.exec( code ) ) !== null ) {
			out.push( m[ 1 ] );
		}
		return out;
	}

	/** Return true if the URL hostname matches a Partytown-verified service. */
	function isKnownSvc( url ) {
		let host;
		try {
			host = new URL( url ).hostname.toLowerCase();
		} catch {
			return false;
		}
		return knownServices.some( function ( k ) {
			return host === k || host.endsWith( '.' + k );
		} );
	}

	/**
	 * Return true if ANY URL in the raw code (src= attrs or inline string literals)
	 * resolves to a Partytown-verified service hostname.
	 * This catches inline snippets like Meta Pixel that embed the CDN URL as a
	 * string argument rather than a separate <script src=""> tag.
	 */
	function hasKnownServiceAnywhere( code ) {
		const urlRe = /https?:\/\/([a-zA-Z0-9][a-zA-Z0-9.-]+)/g;
		let m;
		while ( ( m = urlRe.exec( code ) ) !== null ) {
			const host = m[ 1 ].toLowerCase();
			if ( knownServices.some( function ( k ) { return host === k || host.endsWith( '.' + k ); } ) ) {
				return true;
			}
		}
		return false;
	}

	/** Return true if the block has at least one non-empty inline <script> (no src=). */
	function hasInlineJs( code ) {
		const re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
		let m;
		while ( ( m = re.exec( code ) ) !== null ) {
			if ( ! /\bsrc\s*=/i.test( m[ 1 ] ) && m[ 2 ].trim() !== '' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render a shields.io badge image into $badge; fall back to styled text on error/offline.
	 *
	 * @param {jQuery} $badge       The badge span element.
	 * @param {string} shieldsUrl   Full shields.io badge URL.
	 * @param {string} altText      Alt / accessible text for the image.
	 * @param {string} fallbackText Text content for the offline/firewall fallback.
	 * @param {Object} fallbackCss  jQuery CSS map applied when falling back to text.
	 */
	function setBadgeImg( $badge, shieldsUrl, altText, fallbackText, fallbackCss ) {
		const img = new Image();
		img.alt   = altText;
		img.style.verticalAlign = 'middle';
		img.onload = function () {
			$badge
				.empty()
				.css( { color: '', background: '', border: '', padding: '0' } )
				.append( img )
				.show();
		};
		img.onerror = function () {
			$badge.empty().text( fallbackText ).css( fallbackCss ).show();
		};
		img.src = shieldsUrl;
	}

	/**
	 * Refresh the badge, force-toggle visibility, and warning notice for a block item.
	 *
	 * @param {jQuery} $item  The .dc-swp-blk-item element.
	 * @param {string} code   Current block code.
	 * @param {boolean} force Whether force_partytown is currently on.
	 */
	function refreshBlockBadge( $item, code, force ) {
		const srcUrls          = extractSrcUrls( code );
		const hasUnknownSrc    = srcUrls.some( function ( u ) { return ! isKnownSvc( u ); } );
		const anyKnown         = hasKnownServiceAnywhere( code );
		const inlineJs         = hasInlineJs( code );
		// Unknown inline = there is inline JS but no known-service URL found anywhere.
		const hasUnknownInline = inlineJs && ! anyKnown;
		const $badge           = $item.find( '.dc-swp-blk-badge' );
		const $fwrap           = $item.find( '.dc-swp-blk-force-wrap' );
		const $notice          = $item.find( '.dc-swp-blk-force-notice' );

		const hasAnything = anyKnown || hasUnknownSrc || hasUnknownInline;
		if ( ! hasAnything ) {
			$badge.hide();
			$fwrap.hide();
			$notice.hide();
			return;
		}

		const hasUnknown = hasUnknownSrc || hasUnknownInline;

		if ( ! hasUnknown ) {
			// Everything identified resolves to a known Partytown service.
			setBadgeImg(
				$badge,
				'https://img.shields.io/badge/Supported-Partytown-brightgreen',
				dcSwpAdminData.badgeSupported,
				dcSwpAdminData.badgeSupported,
				{ color: '#00a32a', background: '#f0fdf0', border: '1px solid #00a32a' }
			);
			$fwrap.hide();
			$notice.hide();
		} else if ( force ) {
			setBadgeImg(
				$badge,
				'https://img.shields.io/badge/Forced-Partytown-orange',
				'⚡ Forced / Partytown',
				'⚡ Forced / Partytown',
				{ color: '#a16207', background: '#fefce8', border: '1px solid #ca8a04' }
			);
			$fwrap.show();
			$notice.show();
		} else {
			setBadgeImg(
				$badge,
				'https://img.shields.io/badge/Unsupported-Deferred-red',
				dcSwpAdminData.badgeUnsupported,
				dcSwpAdminData.badgeUnsupported,
				{ color: '#d63638', background: '#fdf2f2', border: '1px solid #d63638' }
			);
			$fwrap.show();
			$notice.hide();
		}
	}

	function buildBlockEl( b, idx ) {
		const labelSafe  = $( '<span>' ).text( b.label || ( 'Block ' + ( idx + 1 ) ) ).html();
		const codeSafe   = $( '<span>' ).text( b.code  || '' ).html();
		const checked    = b.enabled       ? ' checked' : '';
		const forceChk   = b.force_partytown ? ' checked' : '';
		const disCls     = b.enabled ? '' : ' dc-swp-blk-disabled';
		const forceLbl   = $( '<span>' ).text( dcSwpAdminData.forcePtLabel ).html();
		const noticeHtml = $( '<span>' ).text( dcSwpAdminData.forcePtNotice ).html();
		const catLabel   = $( '<span>' ).text( dcSwpAdminData.blockCategoryLabel || 'Consent category' ).html();
		const cats       = dcSwpAdminData.consentCategories || [ 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ];
		const curCat     = b.category || 'marketing';
		let catOpts = '';
		$.each( cats, function ( _ci, cv ) {
			catOpts += '<option value="' + cv + '"' + ( cv === curCat ? ' selected' : '' ) + '>' + cv.charAt( 0 ).toUpperCase() + cv.slice( 1 ) + '</option>';
		} );
		const $el = $( [
			'<div class="dc-swp-blk-item' + disCls + '" data-id="' + b.id + '">',
			'<div class="dc-swp-blk-hdr">',
			'<span class="dc-swp-blk-chevron dashicons dashicons-arrow-right-alt2"></span>',
			'<label class="dc-swp-blk-toggle pwa-toggle" onclick="event.stopPropagation()">',
			'<input class="dc-swp-blk-enable" type="checkbox"' + checked + '>',
			'<span class="pwa-slider"></span></label>',
			'<span class="dc-swp-blk-label" contenteditable="true" spellcheck="false">' + labelSafe + '</span>',
			'<span class="dc-swp-blk-badge" style="display:none;font-size:11px;padding:1px 6px;border-radius:3px;line-height:1.4;white-space:nowrap"></span>',
			'<label class="dc-swp-blk-force-wrap pwa-toggle" onclick="event.stopPropagation()" title="' + forceLbl + '" style="display:none;margin-left:6px">',
			'<input class="dc-swp-blk-force" type="checkbox"' + forceChk + '>',
			'<span class="pwa-slider"></span></label>',
			'<span class="dc-swp-blk-force-label" style="display:none;font-size:11px;color:#666;margin-left:4px;white-space:nowrap">' + forceLbl + '</span>',
			'<button type="button" class="dc-swp-blk-del button-link" style="color:#a00;padding:4px 8px;margin-left:auto;flex-shrink:0">&times; Delete</button>',
			'</div>',
			'<div class="dc-swp-blk-body">',
			'<div class="dc-swp-blk-force-notice" style="display:none;margin-bottom:8px;padding:8px 10px;background:#fefce8;border-left:3px solid #ca8a04;font-size:12px;color:#92400e">' + noticeHtml + '</div>',
			'<div class="dc-swp-blk-cat-wrap" style="margin-bottom:8px"><label style="font-size:12px;font-weight:600;margin-right:6px">' + catLabel + '</label><select class="dc-swp-blk-category">' + catOpts + '</select></div>',
			'<textarea class="dc-swp-blk-code large-text code" rows="8" spellcheck="false">' + codeSafe + '</textarea>',
			'</div></div>',
		].join( '' ) );

		// Initialise badge state after DOM insertion returns synchronously.
		refreshBlockBadge( $el, b.code || '', !! b.force_partytown );

		// Also keep force-label visibility in sync with force-wrap.
		$el.find( '.dc-swp-blk-force-label' ).toggle( $el.find( '.dc-swp-blk-force-wrap' ).is( ':visible' ) );

		return $el;
	}

	function renderList() {
		const $list = $( '#dc-swp-block-list' );
		$list.empty();
		if ( ! blocks.length ) {
			$list.append( '<p style="color:#888;font-style:italic;margin:0 0 4px">' + $( '<span>' ).text( noBlocksMsg ).html() + '</p>' );
			return;
		}
		$.each( blocks, function ( _i, b ) { $list.append( buildBlockEl( b, _i ) ); } );
	}

	function patchBlock( id, changes ) {
		blocks = blocks.map( function ( b ) { return b.id === id ? Object.assign( {}, b, changes ) : b; } );
	}

	renderList();

	// Sync category field visibility with the consent gate initial state.
	if ( ! $( '#dc_swp_consent_gate' ).prop( 'checked' ) ) {
		$( '.dc-swp-blk-cat-wrap' ).hide();
	}

	// Expand / collapse.
	$( document ).on( 'click', '.dc-swp-blk-hdr', function ( e ) {
		if ( $( e.target ).closest( 'button,input,label' ).length ) return;
		const $it  = $( this ).closest( '.dc-swp-blk-item' );
		const open = ! $it.hasClass( 'dc-swp-blk-open' );
		$it.toggleClass( 'dc-swp-blk-open', open );
		$it.find( '.dc-swp-blk-body' ).stop( true, true ).slideToggle( 160 );
		$it.find( '.dc-swp-blk-chevron' )
			.toggleClass( 'dashicons-arrow-right-alt2', ! open )
			.toggleClass( 'dashicons-arrow-down-alt2',   open );
	} );

	// Enable / disable.
	$( document ).on( 'change', '.dc-swp-blk-enable', function () {
		const $it = $( this ).closest( '.dc-swp-blk-item' );
		const en  = $( this ).prop( 'checked' );
		$it.toggleClass( 'dc-swp-blk-disabled', ! en );
		patchBlock( $it.data( 'id' ), { enabled: en } );
	} );

	// Consent category change.
	$( document ).on( 'change', '.dc-swp-blk-category', function () {
		patchBlock( $( this ).closest( '.dc-swp-blk-item' ).data( 'id' ), { category: $( this ).val() } );
	} );

	// Force Partytown toggle for unsupported scripts.
	$( document ).on( 'change', '.dc-swp-blk-force', function () {
		const $it    = $( this ).closest( '.dc-swp-blk-item' );
		const forced = $( this ).prop( 'checked' );
		const id     = $it.data( 'id' );
		patchBlock( id, { force_partytown: forced } );
		const blk = blocks.find( function ( b ) { return b.id === id; } );
		refreshBlockBadge( $it, blk ? ( blk.code || '' ) : '', forced );
		$it.find( '.dc-swp-blk-force-label' ).toggle( $it.find( '.dc-swp-blk-force-wrap' ).is( ':visible' ) );
	} );

	// Delete.
	$( document ).on( 'click', '.dc-swp-blk-del', function () {
		const $it = $( this ).closest( '.dc-swp-blk-item' );
		if ( ! window.confirm( delMsg ) ) return;
		const id = $it.data( 'id' );
		blocks = blocks.filter( function ( b ) { return b.id !== id; } );
		$it.fadeOut( 180, function () { $( this ).remove(); if ( ! blocks.length ) renderList(); } );
	} );

	// Live label edit.
	$( document ).on( 'input', '.dc-swp-blk-label', function () {
		patchBlock( $( this ).closest( '.dc-swp-blk-item' ).data( 'id' ), { label: $( this ).text().trim() } );
	} );

	// Live code edit -- also refreshes the compatibility badge.
	$( document ).on( 'input', '.dc-swp-blk-code', function () {
		const $it  = $( this ).closest( '.dc-swp-blk-item' );
		const id   = $it.data( 'id' );
		const code = $( this ).val();
		patchBlock( id, { code: code } );
		const blk = blocks.find( function ( b ) { return b.id === id; } );
		refreshBlockBadge( $it, code, !! ( blk && blk.force_partytown ) );
		$it.find( '.dc-swp-blk-force-label' ).toggle( $it.find( '.dc-swp-blk-force-wrap' ).is( ':visible' ) );
	} );

	// Add new block.
	$( '#dc-swp-add-block-btn' ).on( 'click', function () {
		const code  = $.trim( $( '#dc-swp-new-code' ).val() );
		if ( ! code ) {
			$( '#dc-swp-new-code' ).focus().css( 'outline', '2px solid #d63638' );
			setTimeout( function () { $( '#dc-swp-new-code' ).css( 'outline', '' ); }, 1500 );
			return;
		}
		const label = $.trim( $( '#dc-swp-new-label' ).val() ) || ( 'Script Block ' + ( blocks.length + 1 ) );
		const nb    = { id: 'block_' + Date.now(), label: label, code: code, enabled: true };
		blocks.push( nb );
		renderList();
		const $ni = $( '.dc-swp-blk-item[data-id="' + nb.id + '"]' );
		if ( ! $( '#dc_swp_consent_gate' ).prop( 'checked' ) ) {
			$ni.find( '.dc-swp-blk-cat-wrap' ).hide();
		}
		$ni.addClass( 'dc-swp-blk-open' ).find( '.dc-swp-blk-body' ).show();
		$ni.find( '.dc-swp-blk-chevron' ).removeClass( 'dashicons-arrow-right-alt2' ).addClass( 'dashicons-arrow-down-alt2' );
		try { $ni[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); } catch { /* not supported in all browsers */ }
		$( '#dc-swp-new-label' ).val( '' );
		$( '#dc-swp-new-code' ).val( '' );
	} );

	// Sync to hidden field before form submit.
	$( 'form.pwa-cache-settings' ).on( 'submit', function () {
		$( '.dc-swp-blk-item' ).each( function () {
			const id = $( this ).data( 'id' );
			patchBlock( id, {
				code:            $( this ).find( '.dc-swp-blk-code' ).val(),
				label:           $( this ).find( '.dc-swp-blk-label' ).text().trim(),
				enabled:         $( this ).find( '.dc-swp-blk-enable' ).prop( 'checked' ),
				force_partytown: $( this ).find( '.dc-swp-blk-force' ).prop( 'checked' ),
				category:        $( this ).find( '.dc-swp-blk-category' ).val() || 'marketing',
			} );
		} );
		$( '#dc_swp_inline_scripts_json' ).val( JSON.stringify( blocks ) );
	} );
} )( jQuery );

// -- Google Tag Management -- panel switcher + wizard -------------------------
( function ( $ ) {
	const GTM_REGEX = /^(GTM-[A-Z0-9]{4,10}|G-[A-Z0-9]{6,}|UA-\d{4,}-\d+)$/i;
	const gtmStr    = ( dcSwpAdminData.gtm || {} );

	/** Sync the hidden form field that actually gets submitted. */
	function syncId( val ) {
		$( '#dc_swp_gtm_id_field' ).val( val );
	}

	/** Validate and return true for acceptable tag IDs. */
	function validId( val ) {
		return GTM_REGEX.test( ( val || '' ).trim() );
	}

	/** Show the panel matching the active mode; hide the rest. */
	function showPanel( mode ) {
		$( '.dc-swp-gtm-panel' ).hide();
		if ( 'off' !== mode ) {
			$( '#dc-swp-gtm-panel-' + mode ).show();
		}
	}

	// -- Wizard step navigation ----------------------------------------------

	function goToStep( step ) {
		$( '.dc-swp-wizard-step' ).removeClass( 'dc-swp-active' ).hide();
		$( '#dc-swp-wizard-step-' + step ).addClass( 'dc-swp-active' ).show();
		$( '.dc-swp-step-dot' ).each( function () {
			const s = parseInt( $( this ).data( 'step' ), 10 );
			$( this )
				.toggleClass( 'active', s === step )
				.toggleClass( 'done',   s < step );
		} );
	}

	// -- Init ----------------------------------------------------------------
	const initMode = $( 'input[name="dc_swp_gtm_mode"]:checked' ).val() || 'off';
	showPanel( initMode );
	$( '#dc-swp-consent-mode-row' ).toggle( 'off' !== initMode );
	goToStep( 1 );

	// If returning to detect mode with a stored ID, show the green "active" state.
	if ( 'detect' === initMode ) {
		const savedDetectId = $( '#dc-swp-gtm-panel-detect' ).data( 'saved-id' );
		if ( savedDetectId ) {
			const safeDetectId = $( '<span>' ).text( savedDetectId ).html();
			$( '#dc-swp-gtm-detect-result' ).html(
				'<p style="color:#3cb034;font-weight:600">\u2714 <code>' + safeDetectId + '</code>' +
				' \u2014 ' + ( gtmStr.active || 'Auto-detected and active' ) + '</p>'
			);
		}
	}

	// If returning to managed mode with a stored ID, re-validate step 2.
	if ( 'managed' === initMode ) {
		const storedId = $( '#dc-swp-gtm-wizard-id' ).val().trim();
		if ( validId( storedId ) ) {
			$( '#dc-swp-wizard-step2-next' ).prop( 'disabled', false );
		}
	}

	// -- Mode radio change ---------------------------------------------------
	$( 'input[name="dc_swp_gtm_mode"]' ).on( 'change', function () {
		const mode = $( this ).val();
		showPanel( mode );
		$( '#dc-swp-consent-mode-row' ).toggle( 'off' !== mode );

		// Cross-fill: switching between own ↔ managed copies the ID.
		if ( 'managed' === mode ) {
			const ownVal = $( '#dc-swp-gtm-id-own' ).val().trim();
			const wizVal = $( '#dc-swp-gtm-wizard-id' ).val().trim();
			if ( ! wizVal && ownVal ) {
				$( '#dc-swp-gtm-wizard-id' ).val( ownVal ).trigger( 'input' );
			}
		}
		if ( 'own' === mode ) {
			const wizVal2 = $( '#dc-swp-gtm-wizard-id' ).val().trim();
			const ownEl   = $( '#dc-swp-gtm-id-own' );
			if ( ! ownEl.val() && wizVal2 ) {
				ownEl.val( wizVal2 ).trigger( 'input' );
			}
		}
	} );

	// -- Own mode: live validation -------------------------------------------
	$( '#dc-swp-gtm-id-own' ).on( 'input', function () {
		const val     = $( this ).val().trim();
		const $status = $( '#dc-swp-gtm-id-status' );
		if ( ! val ) {
			$status.hide();
			syncId( '' );
			return;
		}
		if ( validId( val ) ) {
			$status.text( gtmStr.valid || '\u2714 Valid' )
				.addClass( 'dc-swp-gtm-valid' ).removeClass( 'dc-swp-gtm-invalid' ).show();
			syncId( val.toUpperCase() );
		} else {
			$status.text( gtmStr.invalid || '\u26a0 Invalid format' )
				.addClass( 'dc-swp-gtm-invalid' ).removeClass( 'dc-swp-gtm-valid' ).show();
		}
	} ).trigger( 'input' );

	// -- Detect mode: scan button --------------------------------------------
	$( '#dc-swp-gtm-detect-btn' ).on( 'click', function () {
		const $btn  = $( this );
		const $spin = $( '#dc-swp-gtm-detect-spinner' );
		const $res  = $( '#dc-swp-gtm-detect-result' );
		$btn.prop( 'disabled', true );
		$spin.css( 'display', 'inline-block' );
		$res.empty();

		$.post( ajaxurl, { action: 'dc_swp_detect_gtm', nonce: dcSwpAdminData.nonce },
			function ( r ) {
				$btn.prop( 'disabled', false );
				$spin.hide();
				if ( r.success && r.data && r.data.id ) {
					const safeId     = $( '<span>' ).text( r.data.id ).html();
					const safeSource = $( '<span>' ).text( r.data.source || r.data.plugin || '' ).html();

					// If googletagmanager.com is already in the Partytown Script List,
					// auto-switch to detect mode -- the tag is already being offloaded.
					const ptEntries   = dcSwpAdminData.scriptListEntries || [];
					const alreadyInPt = ptEntries.some( function ( e ) {
						return ( e.pattern || '' ).toLowerCase().indexOf( 'googletagmanager.com' ) !== -1;
					} ) || $( '.dc-swp-sl-pattern' ).toArray().some( function ( el ) {
						return ( el.value || '' ).toLowerCase().indexOf( 'googletagmanager.com' ) !== -1;
					} );

					if ( alreadyInPt ) {
						$( 'input[name="dc_swp_gtm_mode"][value="detect"]' ).prop( 'checked', true ).trigger( 'change' );
						syncId( r.data.id );
						$res.html(
							'<p style="color:#3cb034">\u2714 ' + ( gtmStr.detected || 'Detected' ) +
							': <strong><code>' + safeId + '</code></strong> (' + safeSource + ')</p>' +
							'<p style="color:#3cb034;font-size:12px">' +
							( gtmStr.autoSwitched || '\u2714 Auto-Detect selected \u2014 tag is already in the Partytown Script List.' ) +
							'</p>'
						);
					} else {
						$res.html(
							'<p style="color:#3cb034">\u2714 ' + ( gtmStr.detected || 'Detected' ) +
							': <strong><code>' + safeId + '</code></strong> (' + safeSource + ')</p>' +
							'<button type="button" class="button button-secondary" id="dc-swp-use-detected" data-id="' + safeId + '">' +
							( gtmStr.use || 'Use This ID' ) + '</button>'
						);
					}
				} else {
					$res.html( '<p style="color:#787c82"><em>' + ( gtmStr.none || 'No tag detected.' ) + '</em></p>' );
				}
			}
		).fail( function () { $btn.prop( 'disabled', false ); $spin.hide(); } );
	} );

	$( document ).on( 'click', '#dc-swp-use-detected', function () {
		const id = $( this ).data( 'id' );
		syncId( id );
		const msg = '<span style="color:#3cb034;font-weight:600">\u2714 <code>' +
			$( '<span>' ).text( id ).html() + '</code> \u2014 ' +
			( gtmStr.willBeUsed || 'will be used on next save' ) + '</span>';
		$( this ).replaceWith( msg );
	} );

	// -- Wizard: ID validation in step 2 ------------------------------------
	$( '#dc-swp-gtm-wizard-id' ).on( 'input', function () {
		const val     = $( this ).val().trim();
		const valid   = validId( val );
		const $status = $( '#dc-swp-gtm-wizard-status' );
		$( '#dc-swp-wizard-step2-next' ).prop( 'disabled', ! valid );
		if ( ! val ) { $status.hide(); return; }
		if ( valid ) {
			$status.text( gtmStr.valid || '\u2714 Valid' )
				.addClass( 'dc-swp-gtm-valid' ).removeClass( 'dc-swp-gtm-invalid' ).show();
			syncId( val.toUpperCase() );
		} else {
			$status.text( gtmStr.invalid || '\u26a0 Invalid format' )
				.addClass( 'dc-swp-gtm-invalid' ).removeClass( 'dc-swp-gtm-valid' ).show();
		}
	} ).trigger( 'input' );

	// -- Wizard: next / prev navigation -------------------------------------
	$( document ).on( 'click', '.dc-swp-wizard-btn', function () {
		const dir  = $( this ).data( 'dir' );
		const step = parseInt( $( this ).data( 'step' ), 10 );
		goToStep( 'next' === dir ? step + 1 : step - 1 );
	} );

	// -- Wizard: complete ----------------------------------------------------
	$( '#dc-swp-wizard-complete' ).on( 'click', function () {
		const id = $( '#dc-swp-gtm-wizard-id' ).val().trim();
		if ( validId( id ) ) {
			syncId( id.toUpperCase() );
			$( '#dc-swp-wizard-summary-id' ).text( id.toUpperCase() );
			$( '#dc-swp-wizard-summary' ).show();
			$( this ).text( gtmStr.saved || '\u2714 Saved' ).prop( 'disabled', true );
		}
	} );
} )( jQuery );

// -- GCM v2 conflict + WP Consent API check ---------------------------------
// Fired when the user enables the GCM v2 toggle, and on page load when it
// is already enabled. Fetches the homepage via AJAX and warns if another
// plugin's gtag('consent','default',...) stub is detected, or if the
// WP Consent API plugin is not installed.
( function ( $ ) {
	const gcmStr    = ( dcSwpAdminData.gcm || {} );
	const $checkbox = $( 'input[name="dc_swp_consent_mode"]' );
	const $notices  = $( '#dc-swp-gcm-notices' );

	if ( ! $checkbox.length || ! $notices.length ) {
		return;
	}

	function renderNotices( data ) {
		$notices.empty();

		if ( data.conflict ) {
			$notices.append(
				'<div class="notice notice-warning inline" style="margin:8px 0 0;padding:8px 12px">' +
				'<p><strong>' + $( '<span>' ).text( gcmStr.conflictTitle || '\u26a0 Existing GCM v2 stub detected' ).html() + '</strong> \u2014 ' +
				$( '<span>' ).text( gcmStr.conflictBody || 'Another plugin already outputs a GCM v2 default stub. Disable it before enabling this one.' ).html() +
				'</p></div>'
			);
		}

		if ( ! data.wp_consent_api ) {
			const linkText = gcmStr.noConsentApiLink || 'Install WP Consent API \u2197';
			const linkUrl  = gcmStr.wpConsentApiUrl  || '';
			$notices.append(
				'<div class="notice notice-info inline" style="margin:8px 0 0;padding:8px 12px">' +
				'<p><strong>' + $( '<span>' ).text( gcmStr.noConsentApiTitle || 'WP Consent API not installed' ).html() + '</strong> \u2014 ' +
				$( '<span>' ).text( gcmStr.noConsentApiBody || 'Required for reliable consent signal delivery.' ).html() +
				' <a href="' + linkUrl + '" target="_blank" rel="noopener">' + $( '<span>' ).text( linkText ).html() + '</a>' +
				'</p></div>'
			);
		}
	}

	function runCheck() {
		if ( ! $checkbox.is( ':checked' ) ) {
			$notices.empty();
			return;
		}

		$notices.html(
			'<p class="description" style="margin:6px 0;color:#50575e;font-style:italic">' +
			$( '<span>' ).text( gcmStr.checking || 'Checking for GCM v2 conflicts\u2026' ).html() +
			' <span class="spinner" style="float:none;margin:-3px 0 0 4px;vertical-align:middle;visibility:visible"></span></p>'
		);

		$.post(
			ajaxurl,
			{ action: 'dc_swp_check_gcm_conflict', nonce: dcSwpAdminData.nonce },
			function ( r ) {
				if ( r.success ) {
					renderNotices( r.data );
				} else {
					$notices.empty();
				}
			}
		).fail( function () { $notices.empty(); } );
	}

	// Trigger on toggle change.
	$checkbox.on( 'change', runCheck );

	// Run on page load if GCM v2 is already enabled.
	if ( $checkbox.is( ':checked' ) ) {
		runCheck();
	}
} )( jQuery );

// -- Consent Gate toggle -- show/hide Script List category row + block category -
( function ( $ ) {
	$( '#dc_swp_consent_gate' ).on( 'change', function () {
		const on = $( this ).prop( 'checked' );
		$( '#dc-swp-script-list-cat-row' ).toggle( on );
		$( '.dc-swp-blk-cat-wrap' ).toggle( on );
		$( '.dc-swp-sl-cat' ).toggle( on );
	} );
} )( jQuery );

// -- Server-Side GA4 (SSGA4) -- mode-based setup, wizard, detect, test --------
( function ( $ ) {
	const ssga4 = dcSwpAdminData.ssga4 || {};
	const MID_REGEX = /^G-[A-Z0-9]{6,}$/i;

	/** Sync hidden form fields from active panel inputs. */
	function syncSsga4Credentials() {
		const mode = $( 'input[name="dc_swp_ssga4_mode"]:checked' ).val() || 'off';
		let mid = '', secret = '';
		if ( 'own' === mode ) {
			mid    = $( '#dc-swp-ssga4-mid-own' ).val().trim();
			secret = $( '#dc-swp-ssga4-secret-own' ).val().trim();
		} else if ( 'detect' === mode ) {
			mid    = $( '#dc-swp-ssga4-panel-detect' ).data( 'detected-mid' ) || $( '#dc-swp-ssga4-panel-detect' ).data( 'saved-mid' ) || '';
			secret = $( '#dc-swp-ssga4-secret-detect' ).val().trim();
		} else if ( 'managed' === mode ) {
			mid    = $( '#dc-swp-ssga4-wizard-mid' ).val().trim();
			secret = $( '#dc-swp-ssga4-wizard-secret' ).val().trim();
		}
		$( '#dc_swp_ssga4_mid_field' ).val( mid );
		$( '#dc_swp_ssga4_secret_field' ).val( secret );
	}

	/** Get credentials from whichever panel has them. */
	function getSsga4Credentials() {
		const mode = $( 'input[name="dc_swp_ssga4_mode"]:checked' ).val() || 'off';
		if ( 'own' === mode ) {
			return {
				mid: $( '#dc-swp-ssga4-mid-own' ).val().trim(),
				secret: $( '#dc-swp-ssga4-secret-own' ).val().trim(),
			};
		} else if ( 'detect' === mode ) {
			return {
				mid: $( '#dc-swp-ssga4-panel-detect' ).data( 'detected-mid' ) || $( '#dc-swp-ssga4-panel-detect' ).data( 'saved-mid' ) || '',
				secret: $( '#dc-swp-ssga4-secret-detect' ).val().trim(),
			};
		} else if ( 'managed' === mode ) {
			return {
				mid: $( '#dc-swp-ssga4-wizard-mid' ).val().trim(),
				secret: $( '#dc-swp-ssga4-wizard-secret' ).val().trim(),
			};
		}
		return { mid: '', secret: '' };
	}

	/** Validate measurement ID format. */
	function validMid( val ) {
		return MID_REGEX.test( ( val || '' ).trim() );
	}

	/** Show the panel matching the active mode; hide others. */
	function showSsga4Panel( mode ) {
		$( '.dc-swp-ssga4-panel' ).hide();
		if ( 'off' !== mode ) {
			$( '#dc-swp-ssga4-panel-' + mode ).show();
		}
		$( '#dc-swp-ssga4-events-row, #dc-swp-ssga4-endpoint-row' ).toggle( 'off' !== mode );
	}

	// -- Wizard step navigation (5 steps) ----------------------------------------
	function goToSsga4Step( step ) {
		$( '.dc-swp-ssga4-wizard-step' ).removeClass( 'dc-swp-active' ).hide();
		$( '#dc-swp-ssga4-wizard-step-' + step ).addClass( 'dc-swp-active' ).show();
		$( '.dc-swp-ssga4-steps .dc-swp-step-dot' ).each( function () {
			const s = parseInt( $( this ).data( 'step' ), 10 );
			$( this )
				.toggleClass( 'active', s === step )
				.toggleClass( 'done', s < step );
		} );
	}

	/** Check if GTM mode is active (own/managed/detect with ID). */
	function isGtmActive() {
		const gtmMode = $( 'input[name="dc_swp_gtm_mode"]:checked' ).val() || 'off';
		if ( 'off' === gtmMode ) return false;
		const gtmId = $( '#dc_swp_gtm_id_field' ).val().trim();
		return !!gtmId;
	}

	/** Show/hide GTM conflict warning in wizard step 4. */
	function checkGtmConflict() {
		const clientTag = $( '#dc-swp-ssga4-wizard-client-tag' ).is( ':checked' );
		$( '#dc-swp-ssga4-gtm-conflict' ).toggle( clientTag && isGtmActive() );
	}

	// -- Init --------------------------------------------------------------------
	const initMode = $( 'input[name="dc_swp_ssga4_mode"]:checked' ).val() || 'off';
	showSsga4Panel( initMode );
	goToSsga4Step( 1 );

	// If returning to detect mode with a saved MID, show it.
	if ( 'detect' === initMode ) {
		const savedMid = $( '#dc-swp-ssga4-panel-detect' ).data( 'saved-mid' );
		if ( savedMid ) {
			$( '#dc-swp-ssga4-panel-detect' ).data( 'detected-mid', savedMid );
			$( '#dc-swp-ssga4-detect-result' ).html(
				'<p style="color:#3cb034;font-weight:600">\u2714 <code>' +
				$( '<span>' ).text( savedMid ).html() + '</code> \u2014 ' +
				( ssga4.active || 'Auto-detected and active' ) + '</p>'
			);
			$( '#dc-swp-ssga4-detect-secret-row' ).show();
		}
	}

	// If returning to managed mode, validate step 2/3 buttons.
	if ( 'managed' === initMode ) {
		const storedMid = $( '#dc-swp-ssga4-wizard-mid' ).val().trim();
		const storedSecret = $( '#dc-swp-ssga4-wizard-secret' ).val().trim();
		if ( validMid( storedMid ) ) {
			$( '#dc-swp-ssga4-wizard-step-2 .dc-swp-ssga4-wizard-btn[data-dir="next"]' ).prop( 'disabled', false );
		}
		if ( storedSecret ) {
			$( '#dc-swp-ssga4-wizard-step-3 .dc-swp-ssga4-wizard-btn[data-dir="next"]' ).prop( 'disabled', false );
		}
		checkGtmConflict();
	}

	// -- Mode radio change -------------------------------------------------------
	$( 'input[name="dc_swp_ssga4_mode"]' ).on( 'change', function () {
		const mode = $( this ).val();
		showSsga4Panel( mode );

		// Cross-fill credentials when switching modes.
		if ( 'managed' === mode ) {
			// Copy from own panel if wizard is empty.
			const ownMid = $( '#dc-swp-ssga4-mid-own' ).val().trim();
			const ownSecret = $( '#dc-swp-ssga4-secret-own' ).val().trim();
			if ( ! $( '#dc-swp-ssga4-wizard-mid' ).val().trim() && ownMid ) {
				$( '#dc-swp-ssga4-wizard-mid' ).val( ownMid ).trigger( 'input' );
			}
			if ( ! $( '#dc-swp-ssga4-wizard-secret' ).val().trim() && ownSecret ) {
				$( '#dc-swp-ssga4-wizard-secret' ).val( ownSecret ).trigger( 'input' );
			}
			checkGtmConflict();
		}
		if ( 'own' === mode ) {
			// Copy from wizard if own is empty.
			const wizMid = $( '#dc-swp-ssga4-wizard-mid' ).val().trim();
			const wizSecret = $( '#dc-swp-ssga4-wizard-secret' ).val().trim();
			if ( ! $( '#dc-swp-ssga4-mid-own' ).val().trim() && wizMid ) {
				$( '#dc-swp-ssga4-mid-own' ).val( wizMid ).trigger( 'input' );
			}
			if ( ! $( '#dc-swp-ssga4-secret-own' ).val().trim() && wizSecret ) {
				$( '#dc-swp-ssga4-secret-own' ).val( wizSecret );
			}
		}
	} );

	// -- Own mode: live validation -----------------------------------------------
	$( '#dc-swp-ssga4-mid-own' ).on( 'input', function () {
		const val = $( this ).val().trim();
		const $status = $( '#dc-swp-ssga4-mid-own-status' );
		if ( ! val ) { $status.hide(); return; }
		if ( validMid( val ) ) {
			$status.text( '\u2714' ).css( 'color', '#00a32a' ).show();
		} else {
			$status.text( '\u26a0' ).css( 'color', '#d63638' ).show();
		}
	} ).trigger( 'input' );

	// -- Detect mode: scan button ------------------------------------------------
	$( '#dc-swp-ssga4-detect-btn' ).on( 'click', function () {
		const $btn = $( this );
		const $spin = $( '#dc-swp-ssga4-detect-spinner' );
		const $res = $( '#dc-swp-ssga4-detect-result' );
		$btn.prop( 'disabled', true );
		$spin.css( 'display', 'inline-block' );
		$res.empty();

		$.post( ajaxurl, { action: 'dc_swp_detect_ga4_mid', nonce: dcSwpAdminData.nonce }, function ( r ) {
			$btn.prop( 'disabled', false );
			$spin.hide();
			if ( r.success && r.data && r.data.id ) {
				const mid = r.data.id;
				$( '#dc-swp-ssga4-panel-detect' ).data( 'detected-mid', mid );
				$res.html(
					'<p style="color:#3cb034;font-weight:600">\u2714 ' +
					( ssga4.detected || 'Detected' ) + ': <code>' +
					$( '<span>' ).text( mid ).html() + '</code></p>'
				);
				$( '#dc-swp-ssga4-detect-secret-row' ).show();
			} else {
				$res.html( '<p style="color:#787c82"><em>' +
					$( '<span>' ).text( ssga4.detectNone || 'No GA4 Measurement ID detected.' ).html() +
					'</em></p>' );
			}
		} ).fail( function () { $btn.prop( 'disabled', false ); $spin.hide(); } );
	} );

	// -- Wizard step 2: MID validation -------------------------------------------
	$( '#dc-swp-ssga4-wizard-mid' ).on( 'input', function () {
		const val = $( this ).val().trim();
		const valid = validMid( val );
		const $status = $( '#dc-swp-ssga4-wizard-mid-status' );
		$( '#dc-swp-ssga4-wizard-step-2 .dc-swp-ssga4-wizard-btn[data-dir="next"]' ).prop( 'disabled', ! valid );
		if ( ! val ) { $status.hide(); return; }
		if ( valid ) {
			$status.text( '\u2714' ).css( 'color', '#00a32a' ).show();
		} else {
			$status.text( '\u26a0' ).css( 'color', '#d63638' ).show();
		}
	} ).trigger( 'input' );

	// -- Wizard step 2: Auto-detect button ---------------------------------------
	$( '#dc-swp-ssga4-wizard-detect-btn' ).on( 'click', function () {
		const $btn = $( this );
		const $spin = $( '#dc-swp-ssga4-wizard-detect-spinner' );
		$btn.prop( 'disabled', true );
		$spin.css( 'display', 'inline-block' );

		$.post( ajaxurl, { action: 'dc_swp_detect_ga4_mid', nonce: dcSwpAdminData.nonce }, function ( r ) {
			$btn.prop( 'disabled', false );
			$spin.hide();
			if ( r.success && r.data && r.data.id ) {
				$( '#dc-swp-ssga4-wizard-mid' ).val( r.data.id ).trigger( 'input' );
			}
		} ).fail( function () { $btn.prop( 'disabled', false ); $spin.hide(); } );
	} );

	// -- Wizard step 3: API Secret validation ------------------------------------
	$( '#dc-swp-ssga4-wizard-secret' ).on( 'input', function () {
		const val = $( this ).val().trim();
		$( '#dc-swp-ssga4-wizard-step-3 .dc-swp-ssga4-wizard-btn[data-dir="next"]' ).prop( 'disabled', ! val );
	} ).trigger( 'input' );

	// -- Wizard step 4: GTM conflict check ---------------------------------------
	$( '#dc-swp-ssga4-wizard-client-tag' ).on( 'change', checkGtmConflict );
	$( 'input[name="dc_swp_gtm_mode"]' ).on( 'change', function () {
		setTimeout( checkGtmConflict, 50 );
	} );

	// -- Wizard: next / prev navigation ------------------------------------------
	$( document ).on( 'click', '.dc-swp-ssga4-wizard-btn', function () {
		const dir = $( this ).data( 'dir' );
		const step = parseInt( $( this ).data( 'step' ), 10 );
		goToSsga4Step( 'next' === dir ? step + 1 : step - 1 );
	} );

	// -- Wizard: complete --------------------------------------------------------
	$( '#dc-swp-ssga4-wizard-complete' ).on( 'click', function () {
		const mid = $( '#dc-swp-ssga4-wizard-mid' ).val().trim();
		const secret = $( '#dc-swp-ssga4-wizard-secret' ).val().trim();
		if ( validMid( mid ) && secret ) {
			$( '#dc-swp-ssga4-wizard-summary-mid' ).text( mid );
			$( '#dc-swp-ssga4-wizard-summary' ).show();
			$( this ).text( ssga4.saved || '\u2714 Saved' ).prop( 'disabled', true );
			syncSsga4Credentials();
		}
	} );

	// -- Test connection buttons (shared handler) --------------------------------
	$( document ).on( 'click', '.dc-swp-ssga4-test-btn', function () {
		const $btn = $( this );
		const $spinner = $btn.siblings( '.dc-swp-ssga4-test-spinner' );
		const $result = $btn.siblings( '.dc-swp-ssga4-test-result' );
		const creds = getSsga4Credentials();

		if ( ! creds.mid || ! creds.secret ) {
			$result.html( '<span style="color:#d63638">\u26a0 Enter Measurement ID &amp; API Secret first.</span>' );
			return;
		}

		$btn.prop( 'disabled', true );
		$spinner.css( 'display', 'inline-block' );
		$result.empty();

		$.post( ajaxurl, {
			action: 'dc_swp_test_ssga4',
			nonce: dcSwpAdminData.nonce,
			measurement_id: creds.mid,
			api_secret: creds.secret,
		}, function ( r ) {
			$btn.prop( 'disabled', false );
			$spinner.hide();
			if ( r.success && r.data && r.data.valid ) {
				$result.html( '<span style="color:#00a32a;font-weight:600">\u2714 ' +
					$( '<span>' ).text( ssga4.testSuccess || 'Connection successful!' ).html() +
					'</span>' );
			} else {
				let msg = ssga4.testFail || 'Connection failed.';
				if ( r.data && r.data.messages && r.data.messages.length ) {
					msg += ' -- ' + r.data.messages.map( function ( m ) { return m.description || m.validationCode; } ).join( '; ' );
				}
				$result.html( '<span style="color:#d63638">\u2718 ' +
					$( '<span>' ).text( msg ).html() +
					'</span>' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$spinner.hide();
			$result.html( '<span style="color:#d63638">\u2718 Request failed.</span>' );
		} );
	} );

	// -- Form submit: sync all hidden fields -------------------------------------
	$( 'form.pwa-cache-settings' ).on( 'submit', function () {
		// Sync credentials.
		syncSsga4Credentials();

		// Sync wizard config options.
		$( '#dc_swp_ga4_client_tag_field' ).val( $( '#dc-swp-ssga4-wizard-client-tag' ).is( ':checked' ) ? 'yes' : 'no' );
		$( '#dc_swp_ga4_exclude_logged_field' ).val( $( '#dc-swp-ssga4-wizard-exclude-logged' ).is( ':checked' ) ? 'yes' : 'no' );

		// Sync events checkboxes to hidden JSON field.
		const events = {};
		$( '.dc-swp-ssga4-event-cb' ).each( function () {
			events[ $( this ).data( 'event' ) ] = $( this ).is( ':checked' );
		} );
		$( '#dc_swp_ssga4_events_json' ).val( JSON.stringify( events ) );
	} );
} )( jQuery );

// -- Performance Metrics reset button ----------------------------------------
( function ( $ ) {
	const perf = ( dcSwpAdminData.perf ) || {};
	$( '#dc-swp-perf-reset-btn' ).on( 'click', function () {
		const $btn = $( this );
		const $spinner = $( '#dc-swp-perf-reset-spinner' );
		const $result  = $( '#dc-swp-perf-reset-result' );
		$btn.prop( 'disabled', true );
		$spinner.show();
		$result.text( '' );
		$.post(
			ajaxurl,
			{
				action: 'dc_swp_perf_reset',
				nonce:  perf.resetNonce || '',
			},
			function () {
				$spinner.hide();
				$btn.prop( 'disabled', false );
				$result.html( '<span style="color:#3cb034">' + ( $( '<span>' ).text( perf.resetted || '✔ Metrics reset.' ).html() ) + '</span>' );
			}
		).fail( function () {
			$spinner.hide();
			$btn.prop( 'disabled', false );
			$result.html( '<span style="color:#d63638">\u2718 Request failed.</span>' );
		} );
	} );
} )( jQuery );

// -- Meta CAPI -----------------------------------------------------------------
( function ( $ ) {
	const capi = dcSwpAdminData.capi || {};

	/** Sync credential hidden fields from whichever panel is active. */
	function syncCapiFields() {
		const mode = $( 'input[name="dc_swp_capi_mode"]:checked' ).val() || 'off';
		let pixel = '', token = '', exclude = 'yes';
		if ( 'own' === mode ) {
			pixel   = $( '#dc-swp-capi-pixel-own' ).val().trim();
			token   = $( '#dc-swp-capi-token-own' ).val().trim();
			exclude = $( '#dc-swp-capi-exclude-own' ).is( ':checked' ) ? 'yes' : 'no';
		} else if ( 'detect' === mode ) {
			pixel = $( '#dc-swp-capi-panel-detect' ).data( 'detected-pixel' ) || $( '#dc-swp-capi-panel-detect' ).data( 'saved-pixel' ) || '';
			token = $( '#dc-swp-capi-token-detect' ).val().trim();
		} else if ( 'managed' === mode ) {
			pixel   = $( '#dc-swp-capi-wizard-pixel' ).val().trim();
			token   = $( '#dc-swp-capi-wizard-token' ).val().trim();
			exclude = $( '#dc-swp-capi-wizard-exclude' ).is( ':checked' ) ? 'yes' : 'no';
			// Sync wizard TEC and PII into their named form fields.
			$( '#dc-swp-capi-tec-field' ).val( $( '#dc-swp-capi-wizard-tec' ).val().trim() );
			$( 'input[name="dc_swp_capi_send_pii"]' ).prop( 'checked', $( '#dc-swp-capi-wizard-pii' ).is( ':checked' ) );
			// Sync wizard event checkboxes to the named form checkboxes.
			$( '.dc-swp-capi-wizard-event-cb' ).each( function () {
				$( '.dc-swp-capi-event-cb[data-event="' + $( this ).data( 'event' ) + '"]' ).prop( 'checked', $( this ).is( ':checked' ) );
			} );
		}
		$( '#dc_swp_capi_pixel_field' ).val( pixel );
		$( '#dc_swp_capi_token_field' ).val( token );
		$( '#dc_swp_capi_exclude_field' ).val( exclude );
	}

	/** Get pixel + token for whichever panel is currently active. */
	function getCapiCredentials() {
		const mode = $( 'input[name="dc_swp_capi_mode"]:checked' ).val() || 'off';
		if ( 'own' === mode ) {
			return {
				pixel_id:     $( '#dc-swp-capi-pixel-own' ).val().trim(),
				access_token: $( '#dc-swp-capi-token-own' ).val().trim(),
				tec:          $( '#dc-swp-capi-tec-field' ).val().trim(),
			};
		}
		if ( 'managed' === mode ) {
			return {
				pixel_id:     $( '#dc-swp-capi-wizard-pixel' ).val().trim(),
				access_token: $( '#dc-swp-capi-wizard-token' ).val().trim(),
				tec:          $( '#dc-swp-capi-wizard-tec' ).val().trim(),
			};
		}
		return {
			pixel_id:     $( '#dc-swp-capi-panel-detect' ).data( 'detected-pixel' ) || $( '#dc-swp-capi-panel-detect' ).data( 'saved-pixel' ) || '',
			access_token: $( '#dc-swp-capi-token-detect' ).val().trim(),
			tec:          '',
		};
	}

	/** Show/hide panels and dependent rows when mode radio changes. */
	function updateCapiMode( mode ) {
		$( '.dc-swp-capi-panel' ).hide();
		if ( 'off' !== mode ) {
			$( '#dc-swp-capi-panel-' + mode ).show();
		}
		// Wizard (managed) owns its own events/PII UI inside the steps.
		$( '#dc-swp-capi-events-row, #dc-swp-capi-pii-row' ).toggle( 'off' !== mode && 'managed' !== mode );
		$( '#dc-swp-capi-tec-row' ).toggle( 'own' === mode );
		if ( 'managed' === mode ) {
			goToCapiStep( 1 );
		}
	}

	// Initialise on page load.
	const initCapiMode = $( 'input[name="dc_swp_capi_mode"]:checked' ).val() || 'off';
	updateCapiMode( initCapiMode );

	// Restore saved pixel in detect mode.
	if ( 'detect' === initCapiMode ) {
		const savedPixel = $( '#dc-swp-capi-panel-detect' ).data( 'saved-pixel' );
		if ( savedPixel ) {
			$( '#dc-swp-capi-panel-detect' ).data( 'detected-pixel', savedPixel );
			$( '#dc-swp-capi-detect-result' ).html(
				'<p style="color:#3cb034"><strong>' + $( '<span>' ).text( capi.active || 'Auto-detected and active' ).html() + ':</strong> <code>' + $( '<span>' ).text( savedPixel ).html() + '</code></p>'
			);
			$( '#dc-swp-capi-detect-token-row' ).show();
		}
	}

	$( 'input[name="dc_swp_capi_mode"]' ).on( 'change', function () {
		updateCapiMode( $( this ).val() );
	} );

	// -- Wizard functions (managed mode) -----------------------------------------

	/** Return true when val is a 15-16 digit numeric Pixel ID. */
	function validCapiPixel( val ) {
		return /^\d{15,16}$/.test( val );
	}

	/** Navigate the CAPI Getting Started wizard to a specific step. */
	function goToCapiStep( step ) {
		$( '.dc-swp-capi-wizard-step' ).removeClass( 'dc-swp-active' ).hide();
		$( '#dc-swp-capi-wizard-step-' + step ).addClass( 'dc-swp-active' ).show();
		$( '.dc-swp-capi-steps .dc-swp-step-dot' ).each( function () {
			const s = parseInt( $( this ).data( 'step' ), 10 );
			$( this )
				.toggleClass( 'active', s === step )
				.toggleClass( 'done', s < step );
		} );
		if ( 5 === step ) {
			updateCapiWizardSummary();
		}
	}

	/** Refresh the step-5 summary block from current wizard inputs. */
	function updateCapiWizardSummary() {
		const pixel  = $( '#dc-swp-capi-wizard-pixel' ).val().trim();
		const events = [];
		$( '.dc-swp-capi-wizard-event-cb:checked' ).each( function () {
			events.push( $( this ).data( 'event' ) );
		} );
		const pixelDisplay = pixel
			? '\u2026' + $( '<span>' ).text( pixel.slice( -4 ) ).html()
			: '<em>(not set)</em>';
		const evList  = events.length ? $( '<span>' ).text( events.join( ', ' ) ).html() : '<em>' + ( capi.wizardNoneSelected || 'None selected' ) + '</em>';
		const testMsg = $( '#dc-swp-capi-wizard-test-result' ).text().trim() || ( capi.wizardConnNotTested || 'Not tested yet' );
		$( '#dc-swp-capi-wizard-summary' ).html(
			'<strong>' + ( capi.wizardSummaryDataset || 'Dataset' ) + ':</strong> Pixel ID ending in <code>' + pixelDisplay + '</code><br>' +
			'<strong>' + ( capi.wizardSummaryEvents  || 'Events'  ) + ':</strong> ' + evList + '<br>' +
			'<strong>' + ( capi.wizardSummaryConn    || 'Connection' ) + ':</strong> ' + $( '<span>' ).text( testMsg ).html()
		);
	}

	// Re-validate wizard inputs when returning to managed mode.
	if ( 'managed' === initCapiMode ) {
		if ( validCapiPixel( $( '#dc-swp-capi-wizard-pixel' ).val().trim() ) ) {
			$( '#dc-swp-capi-wizard-step-1 .dc-swp-capi-wizard-btn[data-dir="next"]' ).prop( 'disabled', false );
		}
		if ( $( '#dc-swp-capi-wizard-token' ).val().trim() ) {
			$( '#dc-swp-capi-wizard-step-2 .dc-swp-capi-wizard-btn[data-dir="next"]' ).prop( 'disabled', false );
		}
	}

	// Pixel ID live validation in wizard step 1.
	$( '#dc-swp-capi-wizard-pixel' ).on( 'input', function () {
		const val   = $( this ).val().trim();
		const valid = validCapiPixel( val );
		const $st   = $( '#dc-swp-capi-wizard-pixel-status' );
		$st.text( val ? ( valid ? '\u2714 Valid Pixel ID' : '\u26a0 Expected 15\u201316 digits' ) : '' )
			.css( 'color', valid ? '#3cb034' : '#d63638' );
		$( '#dc-swp-capi-wizard-step-1 .dc-swp-capi-wizard-btn[data-dir="next"]' ).prop( 'disabled', ! valid );
	} );

	// Access Token input — enable Next on step 2.
	$( '#dc-swp-capi-wizard-token' ).on( 'input', function () {
		$( '#dc-swp-capi-wizard-step-2 .dc-swp-capi-wizard-btn[data-dir="next"]' ).prop( 'disabled', ! $( this ).val().trim() );
	} );

	// Wizard Next / Back navigation.
	$( document ).on( 'click', '.dc-swp-capi-wizard-btn', function () {
		const dir  = $( this ).data( 'dir' );
		const step = parseInt( $( this ).data( 'step' ), 10 );
		goToCapiStep( 'next' === dir ? step + 1 : step - 1 );
	} );

	// Test Connection inside the wizard (step 3).
	$( '#dc-swp-capi-wizard-test-btn' ).on( 'click', function () {
		const $spin   = $( '#dc-swp-capi-wizard-test-spinner' );
		const $result = $( '#dc-swp-capi-wizard-test-result' );
		const pixel   = $( '#dc-swp-capi-wizard-pixel' ).val().trim();
		const token   = $( '#dc-swp-capi-wizard-token' ).val().trim();
		const tec     = $( '#dc-swp-capi-wizard-tec' ).val().trim();

		if ( ! pixel || ! token ) {
			$result.html( '<span style="color:#d63638">\u26a0 Complete steps 1 and 2 first.</span>' );
			return;
		}

		$( this ).prop( 'disabled', true );
		$spin.show();
		$result.text( '' );

		$.post(
			ajaxurl,
			{
				action:          'dc_swp_test_capi',
				nonce:           dcSwpAdminData.nonce,
				pixel_id:        pixel,
				access_token:    token,
				test_event_code: tec,
			},
			function ( r ) {
				$spin.hide();
				$( '#dc-swp-capi-wizard-test-btn' ).prop( 'disabled', false );
				if ( r.success && r.data && r.data.valid ) {
					$result.html( '<span style="color:#3cb034">' + $( '<span>' ).text( capi.testSuccess || '\u2714 Connection OK -- Meta accepted the test event.' ).html() + '</span>' );
				} else {
					const errMsg = r.data && r.data.error ? r.data.error : ( capi.testFail || '\u26a0 Connection failed.' );
					$result.html( '<span style="color:#d63638">' + $( '<span>' ).text( errMsg ).html() + '</span>' );
				}
			}
		).fail( function () {
			$spin.hide();
			$( '#dc-swp-capi-wizard-test-btn' ).prop( 'disabled', false );
			$result.html( '<span style="color:#d63638">\u2718 Request failed.</span>' );
		} );
	} );

	// Complete Setup — triggers the standard form submit, which calls syncCapiFields()
	// (syncing all wizard inputs) and serialises events JSON before posting.
	$( '#dc-swp-capi-wizard-complete' ).on( 'click', function () {
		$( '.pwa-cache-settings' ).submit();
	} );
	$( '#dc-swp-capi-pixel-own' ).on( 'input', function () {
		const val     = $( this ).val().trim();
		const valid   = /^\d{15,16}$/.test( val );
		const $status = $( '#dc-swp-capi-pixel-own-status' );
		if ( val ) {
			$status
				.text( valid ? '\u2714 Valid Pixel ID' : '\u26a0 Expected 15-16 digit number' )
				.css( 'color', valid ? '#3cb034' : '#d63638' )
				.css( 'margin-left', '8px' );
		} else {
			$status.text( '' );
		}
	} );

	// Scan Website button (detect mode).
	$( '#dc-swp-capi-detect-btn' ).on( 'click', function () {
		const $spin = $( '#dc-swp-capi-detect-spinner' );
		const $res  = $( '#dc-swp-capi-detect-result' );
		$spin.show();
		$res.html( '' );
		$.post( ajaxurl, { action: 'dc_swp_detect_capi_pixel', nonce: dcSwpAdminData.nonce }, function ( r ) {
			$spin.hide();
			if ( r.success && r.data && r.data.pixel_id ) {
				const pid = r.data.pixel_id;
				$( '#dc-swp-capi-panel-detect' ).data( 'detected-pixel', pid );
				$res.html(
					'<p style="color:#3cb034"><strong>' + $( '<span>' ).text( capi.detected || 'Detected' ).html() + ':</strong> <code>' + $( '<span>' ).text( pid ).html() + '</code></p>'
				);
				$( '#dc-swp-capi-detect-token-row' ).show();
			} else {
				$res.html( '<p style="color:#888">' + $( '<span>' ).text( capi.detectNone || 'No Meta Pixel found in page source.' ).html() + '</p>' );
			}
		} ).fail( function () {
			$spin.hide();
			$res.html( '<p style="color:#d63638">\u2718 Scan request failed.</p>' );
		} );
	} );

	// Test Connection buttons (all panels share this class).
	$( document ).on( 'click', '.dc-swp-capi-test-btn', function () {
		const $btn     = $( this );
		const $spinner = $btn.siblings( '.dc-swp-capi-test-spinner' );
		const $result  = $btn.siblings( '.dc-swp-capi-test-result' );
		const creds    = getCapiCredentials();

		if ( ! creds.pixel_id || ! creds.access_token ) {
			$result.html( '<span style="color:#d63638">\u26a0 Pixel ID and Access Token required.</span>' );
			return;
		}

		$btn.prop( 'disabled', true );
		$spinner.show();
		$result.text( '' );

		$.post(
			ajaxurl,
			{
				action:          'dc_swp_test_capi',
				nonce:           dcSwpAdminData.nonce,
				pixel_id:        creds.pixel_id,
				access_token:    creds.access_token,
				test_event_code: creds.tec,
			},
			function ( r ) {
				$spinner.hide();
				$btn.prop( 'disabled', false );
				if ( r.success && r.data && r.data.valid ) {
					$result.html(
						'<span style="color:#3cb034">' + $( '<span>' ).text( capi.testSuccess || '\u2714 Connection OK -- Meta accepted the test event.' ).html() + '</span>'
					);
				} else {
					const errMsg = r.data && r.data.error ? r.data.error : ( capi.testFail || '\u26a0 Connection failed.' );
					$result.html( '<span style="color:#d63638">' + $( '<span>' ).text( errMsg ).html() + '</span>' );
				}
			}
		).fail( function () {
			$spinner.hide();
			$btn.prop( 'disabled', false );
			$result.html( '<span style="color:#d63638">\u2718 Request failed.</span>' );
		} );
	} );

	// Sync hidden fields and events JSON on form submit.
	$( '.pwa-cache-settings' ).on( 'submit', function () {
		syncCapiFields();

		const events = {};
		$( '.dc-swp-capi-event-cb' ).each( function () {
			events[ $( this ).data( 'event' ) ] = $( this ).is( ':checked' );
		} );
		$( '#dc_swp_capi_events_json' ).val( JSON.stringify( events ) );
	} );
} )( jQuery );
