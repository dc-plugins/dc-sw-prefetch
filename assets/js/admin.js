/**
 * Admin UI — autodetect scripts + inline script blocks accordion.
 * Data injected by PHP via wp_localize_script as dcSwpAdminData.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global dcSwpAdminData */

// ── Autodetect scripts ───────────────────────────────────────────────────────
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

			// Show all third-party scripts found; warn on those not on Partytown's known-compatible list.
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
					const badge = badgeEl[0].outerHTML;
					// Known services pre-checked; unknown scripts unchecked — user must opt in explicitly.
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
		const $ta      = $( 'textarea[name="dc_swp_partytown_scripts"]' );
		const $list    = $( '#dc-swp-autodetect-list' );
		const existing = $ta.val().split( '\n' ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
		const toAdd    = [];

		$list.find( 'input[type="checkbox"]:checked' ).each( function () {
			const url = $( this ).val();
			if ( existing.indexOf( url ) === -1 ) { toAdd.push( url ); }
		} );

		if ( toAdd.length ) {
			$ta.val( existing.concat( toAdd ).join( '\n' ) );
		}
		$( '#dc-swp-autodetect-results' ).fadeOut();
	} );
} );

// ── Inline script blocks accordion ──────────────────────────────────────────
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

	// Live code edit — also refreshes the compatibility badge.
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
			} );
		} );
		$( '#dc_swp_inline_scripts_json' ).val( JSON.stringify( blocks ) );
	} );
} )( jQuery );

// ── Google Tag Management — panel switcher + wizard ─────────────────────────
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

	// ── Wizard step navigation ──────────────────────────────────────────────

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

	// ── Init ────────────────────────────────────────────────────────────────
	const initMode = $( 'input[name="dc_swp_gtm_mode"]:checked' ).val() || 'off';
	showPanel( initMode );
	goToStep( 1 );

	// If returning to managed mode with a stored ID, re-validate step 2.
	if ( 'managed' === initMode ) {
		const storedId = $( '#dc-swp-gtm-wizard-id' ).val().trim();
		if ( validId( storedId ) ) {
			$( '#dc-swp-wizard-step2-next' ).prop( 'disabled', false );
		}
	}

	// ── Mode radio change ───────────────────────────────────────────────────
	$( 'input[name="dc_swp_gtm_mode"]' ).on( 'change', function () {
		const mode = $( this ).val();
		showPanel( mode );

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

	// ── Own mode: live validation ───────────────────────────────────────────
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

	// ── Detect mode: scan button ────────────────────────────────────────────
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
					const safePlugin = $( '<span>' ).text( r.data.plugin ).html();
					$res.html(
						'<p style="color:#3cb034">\u2714 ' + ( gtmStr.detected || 'Detected' ) +
						': <strong><code>' + safeId + '</code></strong> (' + safePlugin + ')</p>' +
						'<button type="button" class="button button-secondary" id="dc-swp-use-detected" data-id="' + safeId + '">' +
						( gtmStr.use || 'Use This ID' ) + '</button>'
					);
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

	// ── Wizard: ID validation in step 2 ────────────────────────────────────
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

	// ── Wizard: next / prev navigation ─────────────────────────────────────
	$( document ).on( 'click', '.dc-swp-wizard-btn', function () {
		const dir  = $( this ).data( 'dir' );
		const step = parseInt( $( this ).data( 'step' ), 10 );
		goToStep( 'next' === dir ? step + 1 : step - 1 );
	} );

	// ── Wizard: complete ────────────────────────────────────────────────────
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
