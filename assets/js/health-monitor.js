/* DC SW Prefetch — Partytown Health Monitor */
/* global PerformanceObserver, FormData */
( function () {
	if ( ! window.dcSwpHealthData ) return;
	const data = window.dcSwpHealthData;
	const hosts = data.hosts;
	const nonce = data.nonce;
	const ajaxUrl = data.ajaxUrl;
	const timeout = data.timeout;
	if ( ! hosts || ! hosts.length ) return;

	const observed = new Set();

	const observer = new PerformanceObserver( function ( list ) {
		list.getEntries().forEach( function ( entry ) {
			if ( entry.initiatorType !== 'script' && entry.initiatorType !== 'fetch' && entry.initiatorType !== 'xmlhttprequest' ) return;
			hosts.forEach( function ( host ) {
				if ( entry.name.includes( host ) ) observed.add( host );
			} );
		} );
	} );

	try {
		observer.observe( { type: 'resource', buffered: true } );
	} catch {
		return; // PerformanceObserver not supported — skip silently.
	}

	setTimeout( function () {
		observer.disconnect();
		hosts.forEach( function ( host ) {
			if ( ! observed.has( host ) ) {
				const formData = new FormData();
				formData.append( 'action', 'dc_swp_health_report' );
				formData.append( 'nonce', nonce );
				formData.append( 'host', host );
				if ( navigator.sendBeacon ) {
					navigator.sendBeacon( ajaxUrl, formData );
				} else {
					fetch( ajaxUrl, { method: 'POST', body: formData } );
				}
			}
		} );
	}, timeout );
} )();
