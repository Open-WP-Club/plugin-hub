'use strict';

/**
 * Test harness for assets/js/script.js.
 *
 * Loads the real script into jsdom with real jQuery, replaces $.ajax with a
 * recorder you answer by hand, and captures timers, confirm()/alert() and page
 * reloads so every code path can be driven deterministically.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );
const createJQuery = require( 'jquery' );

const ROOT = path.join( __dirname, '..', '..' );
const SCRIPT = fs.readFileSync( path.join( ROOT, 'assets', 'js', 'script.js' ), 'utf8' );
const ADMIN_PHP = fs.readFileSync( path.join( ROOT, 'includes', 'admin.php' ), 'utf8' );

/**
 * The translated strings, read from Admin::get_script_strings() so the tests use
 * the exact placeholders PHP ships.
 */
function loadI18n() {
	const body = ADMIN_PHP.split( 'private function get_script_strings()' )[ 1 ].split( 'return array(' )[ 1 ].split( /\n\t\t\);/ )[ 0 ];
	const strings = {};
	const pattern = /'([a-z_]+)'\s*=>\s*__\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g;
	let match;
	while ( ( match = pattern.exec( body ) ) !== null ) {
		strings[ match[ 1 ] ] = ( match[ 2 ] !== undefined ? match[ 2 ].replace( /\\'/g, "'" ) : match[ 3 ] );
	}
	return strings;
}

const I18N = loadI18n();

const PAGE = `
<div class="wrap plugin-hub-wrap">
	<div class="plugin-hub-layout">
		<main class="plugin-hub-main">__MAIN__</main>
	</div>
</div>
<div id="plugin-hub-modal-overlay" hidden></div>
<div id="plugin-hub-modal" hidden tabindex="-1">
	<h2 id="plugin-hub-modal-title"></h2>
	<button type="button" id="plugin-hub-modal-close">&times;</button>
	<div id="plugin-hub-modal-content"></div>
	<div id="plugin-hub-modal-footer" hidden></div>
</div>`;

/**
 * Mount the script against some markup.
 *
 * @param {string} main  HTML placed inside .plugin-hub-main.
 * @param {object} [options]
 * @param {boolean[]} [options.confirms] Answers for window.confirm(), in order (default: true).
 * @return {Promise<object>} The harness.
 */
async function mount( main, options = {} ) {
	const h = {
		ajax: [],
		timers: [],
		confirms: [],
		confirmAnswers: ( options.confirms || [] ).slice(),
		alerts: [],
		reloads: 0,
	};

	const virtualConsole = new VirtualConsole();
	virtualConsole.on( 'jsdomError', ( error ) => {
		if ( /navigation/i.test( error.message ) ) {
			h.reloads++;
		} else {
			throw error;
		}
	} );

	const dom = new JSDOM( PAGE.replace( '__MAIN__', main ), {
		url: 'https://example.test/wp-admin/plugins.php?page=plugin-hub',
		runScripts: 'outside-only',
		pretendToBeVisual: true,
		virtualConsole,
	} );
	const { window } = dom;
	h.dom = dom;
	h.window = window;
	h.document = window.document;

	if ( 'complete' !== window.document.readyState ) {
		await new Promise( ( resolve ) => window.addEventListener( 'load', resolve ) );
	}

	// jsdom has no layout, so jQuery's :visible would always be false. Treat an
	// element as visible unless it or an ancestor is hidden.
	window.HTMLElement.prototype.getClientRects = function() {
		for ( let el = this; el; el = el.parentElement ) {
			if ( 'none' === el.style.display || el.hidden ) {
				return [];
			}
		}
		return [ {} ];
	};

	const $ = createJQuery( window );
	$.fx.off = true;
	window.jQuery = window.$ = $;
	h.$ = $;

	window.pluginHubAjax = Object.assign(
		{ ajax_url: '/wp-admin/admin-ajax.php', nonce: 'nonce-1', rate_limit: [], has_token: false, is_network: false, i18n: I18N },
		options.ajax || {}
	);

	window.confirm = ( message ) => {
		h.confirms.push( message );
		return h.confirmAnswers.length ? h.confirmAnswers.shift() : true;
	};
	window.alert = ( message ) => h.alerts.push( message );

	// Run the script; jQuery's ready callbacks fire asynchronously.
	window.eval( SCRIPT );
	await new Promise( ( resolve ) => setTimeout( resolve, 20 ) );

	// From here on timers are captured and run by the test.
	window.setTimeout = ( fn, ms ) => {
		h.timers.push( { fn, ms } );
		return h.timers.length;
	};

	// Request data is copied into this realm's plain objects so deepEqual works.
	$.ajax = ( opts ) => {
		h.ajax.push( Object.assign( {}, opts, { data: 'string' === typeof opts.data ? opts.data : JSON.parse( JSON.stringify( opts.data ) ) } ) );
		return {};
	};

	/** Run captured timers with a delay of at most `maxMs`, oldest first. */
	h.flush = ( maxMs = Infinity ) => {
		const due = h.timers.filter( ( timer ) => timer.ms <= maxMs );
		h.timers = h.timers.filter( ( timer ) => timer.ms > maxMs );
		due.forEach( ( timer ) => timer.fn() );
	};
	h.timerDelays = () => h.timers.map( ( timer ) => timer.ms );

	/** Resolve request n successfully with { success: true, data }. */
	h.ok = ( n, data ) => answer( h.ajax[ n ], 'success', { success: true, data } );
	/** Resolve request n with a server-side error { success: false, data }. */
	h.fail = ( n, data ) => answer( h.ajax[ n ], 'success', { success: false, data } );
	/** Fail request n at the transport level. */
	h.error = ( n ) => answer( h.ajax[ n ], 'error' );

	function answer( opts, kind, response ) {
		if ( ! opts ) {
			throw new Error( 'No such request' );
		}
		if ( opts[ kind ] ) {
			opts[ kind ]( response );
		}
		if ( opts.complete ) {
			opts.complete();
		}
	}

	h.actions = () => h.ajax.map( ( opts ) => ( 'string' === typeof opts.data ? new URLSearchParams( opts.data ).get( 'action' ) : opts.data.action ) );
	/** Dispatch a submit event on a form as if `submitterSelector` was pressed; returns whether the script took over. */
	h.submit = ( formSelector, submitterSelector ) => {
		const form = h.$( formSelector )[ 0 ];
		const submitter = submitterSelector ? h.$( submitterSelector )[ 0 ] : null;
		const event = new window.SubmitEvent( 'submit', { bubbles: true, cancelable: true, submitter } );
		form.dispatchEvent( event );
		return event.defaultPrevented;
	};
	h.click = ( selector, index = 0 ) => h.$( selector ).eq( index ).trigger( 'click' );
	h.text = ( selector ) => h.$( selector ).first().text();

	return h;
}

module.exports = { mount, I18N };
