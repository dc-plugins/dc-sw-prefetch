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
		const labelSafe    = $( '<span>' ).text( b.label || ( 'Block ' + ( idx + 1 ) ) ).html();
		const codeSafe     = $( '<span>' ).text( b.code  || '' ).html();
		const checked      = b.enabled       ? ' checked' : '';
		const forceChk     = b.force_partytown ? ' checked' : '';
		const skipChk      = b.skip_logged_in  ? ' checked' : '';
		const disCls       = b.enabled ? '' : ' dc-swp-blk-disabled';
		const forceLbl     = $( '<span>' ).text( dcSwpAdminData.forcePtLabel ).html();
		const noticeHtml   = $( '<span>' ).text( dcSwpAdminData.forcePtNotice ).html();
		const catLabel     = $( '<span>' ).text( dcSwpAdminData.blockCategoryLabel || 'Consent category' ).html();
		const skipLbl      = $( '<span>' ).text( dcSwpAdminData.blockSkipLoggedIn  || 'Skip for logged-in users' ).html();
		const cats         = dcSwpAdminData.consentCategories || [ 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ];
		const curCat       = b.category || 'marketing';
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
			'<div class="dc-swp-blk-skip-wrap" style="margin-bottom:8px"><label style="font-size:12px;font-weight:600"><input class="dc-swp-blk-skip" type="checkbox"' + skipChk + ' style="margin-right:5px">' + skipLbl + '</label></div>',
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

	// Skip for logged-in users toggle.
	$( document ).on( 'change', '.dc-swp-blk-skip', function () {
		patchBlock( $( this ).closest( '.dc-swp-blk-item' ).data( 'id' ), { skip_logged_in: $( this ).prop( 'checked' ) } );
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
	const GTM_REGEX      = /^(GTM-[A-Z0-9]{4,10}|G-[A-Z0-9]{6,}|UA-\d{4,}-\d+)$/i;
	const GTM_ONLY_REGEX = /^GTM-[A-Z0-9]{4,10}$/i;
	const gtmStr         = ( dcSwpAdminData.gtm || {} );

	/** Sync the hidden form field that actually gets submitted. */
	function syncId( val ) {
		$( '#dc_swp_gtm_id_field' ).val( val );
		updateGcmGate();
	}

	/** Validate GTM or GA4 IDs (used in the "own" panel). */
	function validId( val ) {
		return GTM_REGEX.test( ( val || '' ).trim() );
	}

	/** Validate GTM Container IDs only (GTM-XXXXX) -- used in the wizard. */
	function validGtmOnly( val ) {
		return GTM_ONLY_REGEX.test( ( val || '' ).trim() );
	}

	/** Enable/disable the GCM v2 toggle based on whether a GTM ID is actually saved. */
	function updateGcmGate() {
		const mode    = $( 'input[name="dc_swp_gtm_mode"]:checked' ).val() || 'off';
		const hasId   = !! $( '#dc_swp_gtm_id_field' ).val().trim();
		const active  = 'off' !== mode && hasId;
		const $toggle = $( 'input[name="dc_swp_consent_mode"]' );
		if ( ! active ) {
			$toggle.prop( 'checked', false );
		}
		$toggle.prop( 'disabled', ! active );
		$( '#dc-swp-gcm-prereq' ).toggle( 'off' !== mode && ! hasId );
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

	// If returning to managed mode with a stored GTM ID, show badge + re-enable step 2.
	if ( 'managed' === initMode ) {
		const storedId = $( '#dc-swp-gtm-wizard-id' ).val().trim();
		if ( validGtmOnly( storedId ) ) {
			$( '#dc-swp-wizard-step2-next' ).prop( 'disabled', false );
			const safeId = $( '<span>' ).text( storedId.toUpperCase() ).html();
			$( '#dc-swp-gtm-panel-managed' ).prepend(
				'<div id="dc-swp-gtm-managed-badge" style="margin-bottom:12px">' +
				'<span style="display:inline-block;background:#3cb034;color:#fff;font-family:monospace;' +
				'font-weight:700;font-size:13px;padding:4px 12px;border-radius:3px">' +
				'\u2714 ' + safeId + '</span>' +
				'<span style="color:#3cb034;font-size:12px;margin-left:8px">' +
				( gtmStr.active || 'Active' ) + '</span>' +
				'</div>'
			);
		}
	}

	updateGcmGate();

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
		updateGcmGate();
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
					$res.html(
						'<p style="color:#3cb034">\u2714 ' + ( gtmStr.detected || 'Detected' ) +
						': <strong><code>' + safeId + '</code></strong> (' + safeSource + ') \u2014 ' +
						( gtmStr.willBeUsed || 'will be re-detected on every Save Settings' ) + '</p>'
					);
				} else {
					$res.html( '<p style="color:#787c82"><em>' + ( gtmStr.none || 'No tag detected.' ) + '</em></p>' );
				}
			}
		).fail( function () { $btn.prop( 'disabled', false ); $spin.hide(); } );
	} );

	// -- Wizard: ID validation in step 2 ------------------------------------
	$( '#dc-swp-gtm-wizard-id' ).on( 'input', function () {
		const val     = $( this ).val().trim();
		const valid   = validGtmOnly( val );
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
		if ( validGtmOnly( id ) ) {
			syncId( id.toUpperCase() );
			$( '#dc-swp-wizard-summary-id' ).text( id.toUpperCase() );
			$( '#dc-swp-wizard-summary' ).show();
			$( this ).text( gtmStr.saved || '\u2714 Saved' ).prop( 'disabled', true );
			$( 'form.pwa-cache-settings' ).submit();
		}
	} );
} )( jQuery );

// -- Meta Pixel -- panel switcher + detect + wizard ---------------------------
( function ( $ ) {
	const PIXEL_REGEX = /^\d{10,20}$/;
	const pixelStr    = ( dcSwpAdminData.pixel || {} );

	/** Sync the hidden form field that gets submitted. */
	function syncPixelId( val ) {
		$( '#dc_swp_pixel_id_field' ).val( val );
	}

	function validPixelId( val ) {
		return PIXEL_REGEX.test( ( val || '' ).trim() );
	}

	/** Show the panel matching the active pixel mode; hide the rest. */
	function showPixelPanel( mode ) {
		$( '#dc-swp-pixel-panel-own, #dc-swp-pixel-panel-detect, #dc-swp-pixel-panel-managed' ).hide();
		if ( 'off' !== mode ) {
			$( '#dc-swp-pixel-panel-' + mode ).show();
		}
		$( '#dc-swp-pixel-sub-options' ).toggle( 'off' !== mode );
	}

	// -- Wizard step navigation ----------------------------------------------
	function goToPixelStep( step ) {
		$( '.dc-swp-wizard-step', '#dc-swp-pixel-panel-managed' ).removeClass( 'dc-swp-active' ).hide();
		$( '#dc-swp-pixel-wizard-step-' + step ).addClass( 'dc-swp-active' ).show();
		$( '#dc-swp-pixel-panel-managed .dc-swp-step-dot' ).each( function () {
			const s = parseInt( $( this ).data( 'step' ), 10 );
			$( this )
				.toggleClass( 'active', s === step )
				.toggleClass( 'done',   s < step );
		} );
	}

	// -- Init ----------------------------------------------------------------
	const initPixelMode = $( 'input[name="dc_swp_pixel_mode"]:checked' ).val() || 'off';
	showPixelPanel( initPixelMode );
	goToPixelStep( 1 );

	// Restore detect active state on page reload.
	if ( 'detect' === initPixelMode ) {
		const savedPixelId = $( '#dc-swp-pixel-panel-detect' ).data( 'saved-id' );
		if ( savedPixelId ) {
			const safeId = $( '<span>' ).text( savedPixelId ).html();
			$( '#dc-swp-pixel-detect-result' ).html(
				'<p style="color:#3cb034;font-weight:600">\u2714 <code>' + safeId + '</code>' +
				' \u2014 ' + ( pixelStr.active || 'Auto-detected and active' ) + '</p>'
			);
		}
	}

	// Restore managed active state on page reload.
	if ( 'managed' === initPixelMode ) {
		const storedPixelId = $( '#dc-swp-pixel-wizard-id' ).val().trim();
		if ( validPixelId( storedPixelId ) ) {
			$( '#dc-swp-pixel-wizard-step2-next' ).prop( 'disabled', false );
			const safeId = $( '<span>' ).text( storedPixelId ).html();
			$( '#dc-swp-pixel-panel-managed' ).prepend(
				'<div id="dc-swp-pixel-managed-badge" style="margin-bottom:12px">' +
				'<span style="display:inline-block;background:#3cb034;color:#fff;font-family:monospace;' +
				'font-weight:700;font-size:13px;padding:4px 12px;border-radius:3px">' +
				'\u2714 ' + safeId + '</span>' +
				'<span style="color:#3cb034;font-size:12px;margin-left:8px">' +
				( pixelStr.active || 'Active' ) + '</span>' +
				'</div>'
			);
		}
	}

	// -- Mode radio change ---------------------------------------------------
	$( 'input[name="dc_swp_pixel_mode"]' ).on( 'change', function () {
		const mode = $( this ).val();
		showPixelPanel( mode );

		// Cross-fill: own ↔ managed.
		if ( 'managed' === mode ) {
			const ownVal = $( '#dc-swp-pixel-id-own' ).val().trim();
			const wizVal = $( '#dc-swp-pixel-wizard-id' ).val().trim();
			if ( ! wizVal && ownVal ) {
				$( '#dc-swp-pixel-wizard-id' ).val( ownVal ).trigger( 'input' );
			}
		}
		if ( 'own' === mode ) {
			const wizVal2 = $( '#dc-swp-pixel-wizard-id' ).val().trim();
			const ownEl   = $( '#dc-swp-pixel-id-own' );
			if ( ! ownEl.val() && wizVal2 ) {
				ownEl.val( wizVal2 ).trigger( 'input' );
			}
		}
	} );

	// -- Own mode: live validation -------------------------------------------
	$( '#dc-swp-pixel-id-own' ).on( 'input', function () {
		const val     = $( this ).val().trim();
		const $status = $( '#dc-swp-pixel-id-status' );
		if ( ! val ) {
			$status.hide();
			syncPixelId( '' );
			return;
		}
		if ( validPixelId( val ) ) {
			$status.text( pixelStr.valid || '\u2714 Valid' )
				.addClass( 'dc-swp-gtm-valid' ).removeClass( 'dc-swp-gtm-invalid' ).show();
			syncPixelId( val );
		} else {
			$status.text( pixelStr.invalid || '\u26a0 Invalid format' )
				.addClass( 'dc-swp-gtm-invalid' ).removeClass( 'dc-swp-gtm-valid' ).show();
		}
	} ).trigger( 'input' );

	// -- Detect mode: scan button --------------------------------------------
	const PIXEL_DETECT_CACHE_KEY = 'dc_swp_pixel_detect_cache';
	const PIXEL_DETECT_CACHE_TTL = 5 * 60 * 1000; // 5 min

	$( '#dc-swp-pixel-detect-btn' ).on( 'click', function () {
		const $btn  = $( this );
		const $spin = $( '#dc-swp-pixel-detect-spinner' );
		const $res  = $( '#dc-swp-pixel-detect-result' );
		$btn.prop( 'disabled', true );
		$spin.css( 'display', 'inline-block' );
		$res.empty();

		// Client-side 5-min cache (same pattern as GTM detect).
		try {
			const raw = localStorage.getItem( PIXEL_DETECT_CACHE_KEY );
			if ( raw ) {
				const entry = JSON.parse( raw );
				if ( entry && ( Date.now() - entry.ts ) < PIXEL_DETECT_CACHE_TTL ) {
					renderPixelDetectResult( $res, entry.data );
					$btn.prop( 'disabled', false );
					$spin.hide();
					return;
				}
			}
		} catch { /* localStorage unavailable */ }

		$.post( ajaxurl, { action: 'dc_swp_detect_pixel', nonce: dcSwpAdminData.nonce },
			function ( r ) {
				$btn.prop( 'disabled', false );
				$spin.hide();
				if ( r.success && r.data ) {
					try {
						localStorage.setItem( PIXEL_DETECT_CACHE_KEY, JSON.stringify( { ts: Date.now(), data: r.data } ) );
					} catch { /* ignore */ }
					renderPixelDetectResult( $res, r.data );
				} else {
					$res.html( '<p style="color:#787c82"><em>' + ( pixelStr.none || 'No Meta Pixel found in page source.' ) + '</em></p>' );
				}
			}
		).fail( function () { $btn.prop( 'disabled', false ); $spin.hide(); } );
	} );

	function renderPixelDetectResult( $res, data ) {
		if ( data.found && data.id ) {
			const safeId = $( '<span>' ).text( data.id ).html();
			$res.html(
				'<p style="color:#3cb034">\u2714 ' + ( pixelStr.detected || 'Detected' ) +
				': <strong><code>' + safeId + '</code></strong> \u2014 ' +
				( pixelStr.willBeUsed || 'will be re-detected on every Save Settings' ) + '</p>'
			);
		} else {
			$res.html( '<p style="color:#787c82"><em>' + ( pixelStr.none || 'No Meta Pixel found in page source.' ) + '</em></p>' );
		}
	}

	// -- Wizard: ID validation in step 2 ------------------------------------
	$( '#dc-swp-pixel-wizard-id' ).on( 'input', function () {
		const val     = $( this ).val().trim();
		const valid   = validPixelId( val );
		const $status = $( '#dc-swp-pixel-wizard-status' );
		$( '#dc-swp-pixel-wizard-step2-next' ).prop( 'disabled', ! valid );
		if ( ! val ) { $status.hide(); return; }
		if ( valid ) {
			$status.text( pixelStr.valid || '\u2714 Valid' )
				.addClass( 'dc-swp-gtm-valid' ).removeClass( 'dc-swp-gtm-invalid' ).show();
			syncPixelId( val );
		} else {
			$status.text( pixelStr.invalid || '\u26a0 Invalid format' )
				.addClass( 'dc-swp-gtm-invalid' ).removeClass( 'dc-swp-gtm-valid' ).show();
		}
	} ).trigger( 'input' );

	// -- Wizard: next / prev navigation -------------------------------------
	$( document ).on( 'click', '.dc-swp-pixel-wizard-btn', function () {
		const dir  = $( this ).data( 'dir' );
		const step = parseInt( $( this ).data( 'step' ), 10 );
		goToPixelStep( 'next' === dir ? step + 1 : step - 1 );
	} );

	// -- Wizard: complete ----------------------------------------------------
	$( '#dc-swp-pixel-wizard-complete' ).on( 'click', function () {
		const id = $( '#dc-swp-pixel-wizard-id' ).val().trim();
		if ( validPixelId( id ) ) {
			syncPixelId( id );
			$( '#dc-swp-pixel-wizard-summary-id' ).text( id );
			$( '#dc-swp-pixel-wizard-summary' ).show();
			$( this ).text( pixelStr.saved || '\u2714 Saved' ).prop( 'disabled', true );
			$( 'form.pwa-cache-settings' ).submit();
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

// -- Partytown-dependent toggles --------------------------------------------
( function ( $ ) {
	'use strict';

	const $ptToggle  = $( '#dc_swp_sw_enabled' );
	const $depInputs = $( 'input[name="dc_swp_coi_headers"], input[name="dc_swp_debug_mode"]' );

	function syncPtDeps() {
		const ptOn = $ptToggle.is( ':checked' );
		$depInputs.each( function () {
			const $input = $( this );
			const $row   = $input.closest( 'tr' );
			if ( ptOn ) {
				$input.prop( 'disabled', false );
				$row.removeClass( 'dc-swp-row-disabled' );
			} else {
				$input.prop( 'checked', false ).prop( 'disabled', true );
				$row.addClass( 'dc-swp-row-disabled' );
			}
		} );
	}

	$ptToggle.on( 'change', syncPtDeps );
	syncPtDeps();
} )( jQuery );

// -- Tab navigation ----------------------------------------------------------
( function ( $ ) {
	'use strict';

	const $tabs   = $( '#dc-swp-tabs .nav-tab' );
	const $panels = $( '.dc-swp-tab-panel' );

	function activateTab( target ) {
		if ( ! target || ! $( target ).length ) {
			target = $tabs.first().attr( 'href' );
		}
		$tabs.removeClass( 'nav-tab-active' ).filter( '[href="' + target + '"]' ).addClass( 'nav-tab-active' );
		$panels.removeClass( 'dc-swp-tab-active' );
		$( target ).addClass( 'dc-swp-tab-active' );
		if ( history.replaceState ) {
			history.replaceState( null, '', location.pathname + location.search + target );
		}
	}

	$tabs.on( 'click', function ( e ) {
		e.preventDefault();
		activateTab( $( this ).attr( 'href' ) );
	} );

	// Restore tab from URL hash; fall back to first tab.
	const initialHash = location.hash && $( '#dc-swp-tabs .nav-tab[href="' + location.hash + '"]' ).length
		? location.hash
		: null;
	activateTab( initialHash );
} )( jQuery );
