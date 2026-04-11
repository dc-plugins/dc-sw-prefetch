/**
 * Footer credit -- finds the first © symbol in the footer and wraps it with a link.
 * Data injected by PHP via wp_localize_script as dcSwpFooterCreditData.
 *
 * @package DC_Service_Worker_Prefetcher
 */

/* global dcSwpFooterCreditData */

( function () {
	const f = document.querySelector( 'footer' );
	if ( ! f ) return;

	const w    = document.createTreeWalker( f, NodeFilter.SHOW_TEXT, null, false );
	let node;
	while ( ( node = w.nextNode() ) ) {
		if ( node.nodeValue.indexOf( '\u00A9' ) === -1 ) continue;
		const idx  = node.nodeValue.indexOf( '\u00A9' );
		const frag = document.createDocumentFragment();
		if ( idx > 0 ) frag.appendChild( document.createTextNode( node.nodeValue.slice( 0, idx ) ) );
		const a    = document.createElement( 'a' );
		a.href   = dcSwpFooterCreditData.url;
		a.title  = dcSwpFooterCreditData.title;
		a.target = '_blank';
		a.rel    = 'noopener noreferrer';
		a.textContent = '\u00A9';
		frag.appendChild( a );
		const rest = node.nodeValue.slice( idx + 1 );
		if ( rest ) frag.appendChild( document.createTextNode( rest ) );
		node.parentNode.replaceChild( frag, node );
		break;
	}
} )();
