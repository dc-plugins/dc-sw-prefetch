/* DC SW Prefetch -- Performance Reporter */
/* global PerformanceObserver, FormData, sessionStorage, requestIdleCallback */
( function () {
	if ( ! window.dcSwpPerfData ) return;
	const data = window.dcSwpPerfData;
	const nonce = data.nonce;
	const ajaxUrl = data.ajaxUrl;
	const sessionKey = data.sessionKey;
	if ( sessionStorage.getItem( sessionKey ) ) return;

	let tbt = 0;
	let inp = 0;
	const LONG_TASK_THRESHOLD = 50;

	try {
		new PerformanceObserver( function ( list ) {
			list.getEntries().forEach( function ( e ) {
				tbt += Math.max( 0, e.duration - LONG_TASK_THRESHOLD );
			} );
		} ).observe( { type: 'longtask', buffered: true } );
	} catch { /* not supported */ }

	try {
		new PerformanceObserver( function ( list ) {
			list.getEntries().forEach( function ( e ) {
				if ( e.interactionId > 0 ) inp = Math.max( inp, e.duration );
			} );
		} ).observe( { type: 'event', buffered: true, durationThreshold: 16 } );
	} catch { /* not supported */ }

	function report() {
		sessionStorage.setItem( sessionKey, '1' );
		const formData = new FormData();
		formData.append( 'action', 'dc_swp_perf_report' );
		formData.append( 'nonce', nonce );
		formData.append( 'tbt', tbt.toFixed( 2 ) );
		formData.append( 'inp', inp.toFixed( 2 ) );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( ajaxUrl, formData );
		} else {
			fetch( ajaxUrl, { method: 'POST', body: formData } );
		}
	}

	const IDLE_DELAY = 10000;
	if ( 'requestIdleCallback' in window ) {
		requestIdleCallback( report, { timeout: IDLE_DELAY } );
	} else {
		setTimeout( report, IDLE_DELAY );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' && ! sessionStorage.getItem( sessionKey ) ) report();
	} );
} )();
