'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { mount, I18N } = require( './harness' );

test( 'the harness loads the script and the strings come from PHP', async () => {
	assert.equal( I18N.installing, 'Installing...' );
	assert.equal( I18N.bulk_status, 'Processing %1$s of %2$s' );
	assert.equal( I18N.whats_new, "What's new?" );

	const h = await mount( '<a href="#" class="install-now" data-repo="gamma" data-version="3.0.0">Install now</a>' );
	h.click( '.install-now' );

	assert.deepEqual( h.actions(), [ 'install_github_plugin' ] );
	assert.equal( h.text( '.install-now' ), 'Installing...' );
} );
