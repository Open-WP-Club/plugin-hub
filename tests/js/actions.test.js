'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { mount } = require( './harness' );

const ROW = ( inner ) => `<table><tbody><tr><td>${ inner }</td></tr></tbody></table>`;

// ----------------------------------------------------------------- install

test( 'install posts the repo and version, shows progress and reloads on success', async () => {
	const h = await mount( ROW( '<a href="#" class="install-now" data-repo="gamma" data-version="3.0.0">Install now</a>' ) );

	h.click( '.install-now' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'install_github_plugin', nonce: 'nonce-1', repo: 'gamma', version: '3.0.0' } );
	assert.equal( h.ajax[ 0 ].url, '/wp-admin/admin-ajax.php' );
	assert.equal( h.text( '.install-now' ), 'Installing...' );
	assert.equal( h.$( '.install-now' ).attr( 'aria-disabled' ), 'true' );

	h.click( '.install-now' );
	assert.equal( h.ajax.length, 1, 'A busy link ignores further clicks.' );

	h.ok( 0, 'Plugin installed successfully.' );
	assert.equal( h.text( '.install-now' ), 'Installed' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Plugin installed successfully.' );
	assert.ok( h.$( '#plugin-hub-messages' ).hasClass( 'notice-success' ) );
	assert.equal( h.reloads, 0 );
	h.flush( 1000 );
	assert.equal( h.reloads, 1, 'The page reloads a second later.' );
} );

test( 'a failed install is reported and can be retried', async () => {
	const h = await mount( ROW( '<a href="#" class="install-now" data-repo="gamma" data-version="3.0.0">Install now</a>' ) );

	h.click( '.install-now' );
	h.fail( 0, 'Download failed' );
	assert.equal( h.text( '.install-now' ), 'Install Failed' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Download failed' );
	assert.ok( h.$( '#plugin-hub-messages' ).hasClass( 'notice-error' ) );
	assert.equal( h.$( '#plugin-hub-messages' ).attr( 'role' ), 'alert' );
	assert.equal( h.$( '.install-now' ).attr( 'aria-disabled' ), undefined );

	h.click( '.install-now' );
	assert.equal( h.ajax.length, 2 );
	h.error( 1 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
	assert.equal( h.reloads, 0 );
} );

test( 'messages disappear after five seconds', async () => {
	const h = await mount( ROW( '<a href="#" class="install-now" data-repo="g" data-version="1.0.0">x</a>' ) );
	h.click( '.install-now' );
	h.ok( 0, 'Done' );
	assert.ok( h.timerDelays().includes( 5000 ) );
} );

// ------------------------------------------------------------------ update

const UPDATE = '<a href="#" class="update-now" data-repo="alpha" data-version="1.5.0" data-current-version="1.0.0">Update now</a>';

test( 'update opens the changelog first and only updates after confirmation', async () => {
	const h = await mount( ROW( UPDATE ) );

	h.click( '.update-now' );
	assert.equal( h.ajax.length, 1, 'Only the changelog is requested.' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'get_changelog', nonce: 'nonce-1', repo: 'alpha', current_version: '1.0.0', new_version: '1.5.0' } );
	assert.equal( h.text( '#plugin-hub-modal-title' ), 'Update alpha' );
	assert.equal( h.text( '#plugin-hub-modal-content' ), 'Loading changelog…' );
	assert.equal( h.$( '#plugin-hub-modal' ).prop( 'hidden' ), false );
	assert.deepEqual( h.$( '#plugin-hub-modal-footer button' ).map( ( i, el ) => el.textContent ).get(), [ 'Update to 1.5.0', 'Cancel' ] );

	h.ok( 0, '<h4>Version 1.5.0</h4><p>Fixes</p>' );
	assert.equal( h.text( '#plugin-hub-modal-content h4' ), 'Version 1.5.0' );

	h.$( '#plugin-hub-modal-footer button' ).first().trigger( 'click' );
	assert.deepEqual( h.ajax[ 1 ].data, { action: 'update_github_plugin', nonce: 'nonce-1', repo: 'alpha', version: '1.5.0', is_rollback: 0 } );
	assert.equal( h.text( '.update-now' ), 'Updating...' );
	assert.equal( h.$( '#plugin-hub-modal' ).prop( 'hidden' ), true );
} );

test( 'a successful update is verified and then the page reloads', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );
	h.ok( 0, '' );
	h.$( '#plugin-hub-modal-footer button' ).first().trigger( 'click' );

	h.ok( 1, 'Plugin updated successfully to version 1.5.0' );
	assert.equal( h.text( '.update-now' ), 'Updated' );
	assert.equal( h.ajax.length, 2, 'Verification waits half a second.' );

	h.flush( 500 );
	assert.deepEqual( h.ajax[ 2 ].data, { action: 'verify_plugin_update', nonce: 'nonce-1', repo: 'alpha', version: '1.5.0' } );
	h.ok( 2, 'Plugin version verified: 1.5.0' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Plugin version verified: 1.5.0' );
	h.flush( 1000 );
	assert.equal( h.reloads, 1 );
} );

test( 'a failed verification re-enables the button', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );
	h.ok( 0, '' );
	h.$( '#plugin-hub-modal-footer button' ).first().trigger( 'click' );
	h.ok( 1, 'ok' );
	h.flush( 500 );
	h.fail( 2, 'Plugin version mismatch.' );

	assert.equal( h.text( '.update-now' ), 'Update Failed' );
	assert.equal( h.$( '.update-now' ).attr( 'aria-disabled' ), undefined );
	assert.equal( h.reloads, 0 );
} );

test( 'a failed update is reported', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );
	h.ok( 0, '' );
	h.$( '#plugin-hub-modal-footer button' ).first().trigger( 'click' );
	h.fail( 1, 'The site failed its health check' );

	assert.equal( h.text( '.update-now' ), 'Update Failed' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'The site failed its health check' );
	assert.equal( h.ajax.length, 2, 'No verification after a failure.' );
} );

test( 'a transport error during the update is reported', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );
	h.ok( 0, '' );
	h.$( '#plugin-hub-modal-footer button' ).first().trigger( 'click' );
	h.error( 1 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
} );

test( 'a transport error during verification has its own message', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );
	h.ok( 0, '' );
	h.$( '#plugin-hub-modal-footer button' ).first().trigger( 'click' );
	h.ok( 1, 'ok' );
	h.flush( 500 );
	h.error( 2 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Failed to verify update. Please refresh the page and check the plugin version.' );
} );

test( 'the update modal can be cancelled and works without a changelog', async () => {
	const h = await mount( ROW( '<a href="#" class="update-now" data-repo="alpha" data-version="1.5.0">Update</a>' ) );
	h.click( '.update-now' );
	assert.equal( h.ajax.length, 0, 'Without a current version there is nothing to compare.' );
	assert.equal( h.text( '#plugin-hub-modal-content' ), 'No changelog available for this version.' );

	h.$( '#plugin-hub-modal-footer button' ).eq( 1 ).trigger( 'click' );
	assert.equal( h.$( '#plugin-hub-modal' ).prop( 'hidden' ), true );
	assert.equal( h.ajax.length, 0, 'Cancel never updates.' );
} );

test( 'changelog errors are shown inside the modal', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );
	h.error( 0 );
	assert.equal( h.text( '#plugin-hub-modal-content' ), 'An error occurred. Please try again.' );

	h.click( '.update-now' );
	h.fail( 1, 'Unable to fetch changelog.' );
	assert.equal( h.text( '#plugin-hub-modal-content' ), 'No changelog available for this version.' );
} );

// -------------------------------------------------- activate / deactivate / delete

test( 'activate posts the repo and the network flag', async () => {
	const h = await mount( ROW( '<a href="#" class="activate-now" data-repo="alpha">Activate</a><a href="#" class="activate-now network-activate-now" data-network="1" data-repo="beta">Network</a>' ) );

	h.click( '.activate-now', 0 );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'activate_github_plugin', nonce: 'nonce-1', repo: 'alpha' } );
	assert.equal( h.text( '.activate-now:first' ), 'Activating...' );
	h.ok( 0, 'Plugin activated successfully.' );
	assert.equal( h.text( '.activate-now:first' ), 'Activated' );
	h.flush( 1000 );
	assert.equal( h.reloads, 1 );

	h.click( '.activate-now', 1 );
	assert.equal( h.ajax[ 1 ].data.network, 1 );
	h.fail( 1, 'Plugin not found.' );
	assert.equal( h.text( '.network-activate-now' ), 'Activation Failed' );
} );

test( 'deactivating a plugin others depend on asks first and forces after consent', async () => {
	const h = await mount( ROW( '<a href="#" class="deactivate-now" data-repo="alpha" data-dependents="Zeta, Omega">Deactivate</a>' ), { confirms: [ false, true ] } );

	h.click( '.deactivate-now' );
	assert.deepEqual( h.confirms, [ 'These active plugins depend on it: Zeta, Omega. Continue anyway?' ] );
	assert.equal( h.ajax.length, 0, 'Declining stops everything.' );

	h.click( '.deactivate-now' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'deactivate_github_plugin', nonce: 'nonce-1', repo: 'alpha', force: 1 } );
	h.ok( 0, 'Plugin deactivated successfully.' );
	assert.equal( h.text( '.deactivate-now' ), 'Deactivated' );
} );

test( 'deactivating without dependents does not ask or force', async () => {
	const h = await mount( ROW( '<a href="#" class="deactivate-now" data-network="1" data-repo="alpha">Deactivate</a>' ) );
	h.click( '.deactivate-now' );
	assert.deepEqual( h.confirms, [] );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'deactivate_github_plugin', nonce: 'nonce-1', repo: 'alpha', network: 1 } );
	h.fail( 0, 'Network-active plugins must be managed from Network Admin.' );
	assert.equal( h.text( '.deactivate-now' ), 'Deactivation Failed' );
} );

test( 'delete confirms, warns about dependents and then deletes', async () => {
	const h = await mount( ROW( '<a href="#" class="delete-now" data-repo="alpha" data-dependents="Zeta">Delete</a>' ), { confirms: [ false, true, false, true, true ] } );

	h.click( '.delete-now' );
	assert.deepEqual( h.confirms, [ 'Are you sure you want to delete this plugin?' ] );
	assert.equal( h.ajax.length, 0 );

	h.click( '.delete-now' );
	assert.equal( h.confirms.length, 3, 'The dependents warning follows the first confirmation.' );
	assert.equal( h.ajax.length, 0, 'Declining the second one also stops.' );

	h.click( '.delete-now' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'delete_github_plugin', nonce: 'nonce-1', repo: 'alpha', force: 1 } );
	h.ok( 0, 'Plugin deleted successfully.' );
	assert.equal( h.text( '.delete-now' ), 'Deleted' );
} );

// ---------------------------------------------------------- changelog / modal

const CHANGELOG = '<a href="#" class="open-changelog" data-repo="alpha" data-current-version="1.0.0" data-new-version="1.5.0">What\'s new?</a>';

test( 'the changelog link opens the modal and loads the notes', async () => {
	const h = await mount( ROW( CHANGELOG ) );

	h.click( '.open-changelog' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'get_changelog', nonce: 'nonce-1', repo: 'alpha', current_version: '1.0.0', new_version: '1.5.0' } );
	assert.equal( h.text( '#plugin-hub-modal-title' ), 'Loading changelog…' );
	assert.equal( h.$( '#plugin-hub-modal-footer' ).prop( 'hidden' ), true );
	assert.equal( h.$( '.open-changelog' ).attr( 'aria-disabled' ), 'true' );

	h.ok( 0, '<h4>Version 1.5.0</h4>' );
	assert.equal( h.text( '#plugin-hub-modal-title' ), 'Changelog: alpha' );
	assert.equal( h.text( '#plugin-hub-modal-content h4' ), 'Version 1.5.0' );
	assert.equal( h.$( '.open-changelog' ).attr( 'aria-disabled' ), undefined, 'The link is released when the request ends.' );
} );

test( 'an empty or failed changelog explains itself', async () => {
	const h = await mount( ROW( CHANGELOG ) );
	h.click( '.open-changelog' );
	h.fail( 0, 'x' );
	assert.equal( h.text( '#plugin-hub-modal-content' ), 'No changelog available for this version.' );

	h.click( '.open-changelog' );
	h.error( 1 );
	assert.equal( h.text( '#plugin-hub-modal-content' ), 'An error occurred. Please try again.' );
} );

test( 'the modal closes with the button, the overlay and Escape, and returns focus', async () => {
	const h = await mount( ROW( CHANGELOG ) );
	const link = h.$( '.open-changelog' )[ 0 ];

	let request = 0;
	for ( const close of [
		() => h.click( '#plugin-hub-modal-close' ),
		() => h.click( '#plugin-hub-modal-overlay' ),
		() => h.$( h.document ).trigger( h.$.Event( 'keydown', { which: 27 } ) ),
	] ) {
		link.focus();
		h.click( '.open-changelog' );
		h.ok( request++, '<p>Notes</p>' );
		assert.equal( h.$( '#plugin-hub-modal' ).prop( 'hidden' ), false );
		close();
		assert.equal( h.$( '#plugin-hub-modal' ).prop( 'hidden' ), true );
		assert.equal( h.$( '#plugin-hub-modal-overlay' ).prop( 'hidden' ), true );
		assert.equal( h.document.activeElement, link, 'Focus goes back to what opened the modal.' );
	}
} );

test( 'Tab is trapped inside the modal', async () => {
	const h = await mount( ROW( UPDATE ) );
	h.click( '.update-now' );

	const buttons = h.$( '#plugin-hub-modal button' ).get();
	const first = buttons[ 0 ];
	const last = buttons[ buttons.length - 1 ];
	assert.equal( first.id, 'plugin-hub-modal-close' );

	last.focus();
	let tab = h.$.Event( 'keydown', { which: 9 } );
	h.$( '#plugin-hub-modal' ).trigger( tab );
	assert.ok( tab.isDefaultPrevented() );
	assert.equal( h.document.activeElement, first, 'Tab on the last control wraps to the first.' );

	tab = h.$.Event( 'keydown', { which: 9, shiftKey: true } );
	h.$( '#plugin-hub-modal' ).trigger( tab );
	assert.ok( tab.isDefaultPrevented() );
	assert.equal( h.document.activeElement, last, 'Shift+Tab on the first control wraps to the last.' );

	buttons[ 1 ].focus();
	tab = h.$.Event( 'keydown', { which: 9 } );
	h.$( '#plugin-hub-modal' ).trigger( tab );
	assert.ok( ! tab.isDefaultPrevented(), 'Tab in the middle moves naturally.' );

	const other = h.$.Event( 'keydown', { which: 65 } );
	h.$( '#plugin-hub-modal' ).trigger( other );
	assert.ok( ! other.isDefaultPrevented(), 'Other keys are left alone.' );
} );

// ----------------------------------------------------------------- rollback

const ROLLBACK = `<tr><td><div class="row-actions">
	<a href="#" class="update-now" data-repo="alpha" data-version="1.5.0">Update now</a>
	<span><a href="#" class="rollback-toggle" data-repo="alpha">Versions</a><span class="rollback-list" style="display:none;"></span></span>
</div></td></tr>`;

const RELEASES = [
	{ version: '1.0.0', name: 'Current', date: '2026-01-02', current: true },
	{ version: '0.9.0', name: 'Old', date: '', current: false },
];

test( 'the version list loads once, toggles and offers older releases', async () => {
	const h = await mount( `<table><tbody>${ ROLLBACK }</tbody></table>` );

	h.click( '.rollback-toggle' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'get_plugin_releases', nonce: 'nonce-1', repo: 'alpha' } );
	assert.equal( h.text( '.rollback-toggle' ), 'Loading…' );

	h.ok( 0, RELEASES );
	assert.equal( h.text( '.rollback-toggle' ), 'Versions' );
	assert.notEqual( h.$( '.rollback-list' ).css( 'display' ), 'none', 'The loaded list is visible.' );
	assert.match( h.text( '.rollback-current' ), /1\.0\.0 \(2026-01-02\) — current/ );
	assert.equal( h.$( 'a.rollback-version-item' ).length, 1 );
	assert.equal( h.$( 'a.rollback-version-item' ).attr( 'data-version' ), '0.9.0' );
	assert.equal( h.$( 'a.rollback-version-item' ).attr( 'data-repo' ), 'alpha' );

	h.click( '.rollback-toggle' );
	assert.equal( h.$( '.rollback-list' ).css( 'display' ), 'none', 'A second click hides the list.' );
	h.click( '.rollback-toggle' );
	assert.equal( h.ajax.length, 1, 'It is shown again without another request.' );
	assert.notEqual( h.$( '.rollback-list' ).css( 'display' ), 'none' );
} );

test( 'an empty or failed version list shows an error', async () => {
	const h = await mount( `<table><tbody>${ ROLLBACK }</tbody></table>` );
	h.click( '.rollback-toggle' );
	h.ok( 0, [] );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
	assert.equal( h.text( '.rollback-toggle' ), 'Versions' );

	h.click( '.rollback-toggle' );
	h.error( 1 );
	assert.equal( h.text( '.rollback-toggle' ), 'Versions' );
} );

test( 'choosing an older version asks and then rolls back', async () => {
	const h = await mount( `<table><tbody>${ ROLLBACK }</tbody></table>`, { confirms: [ false, true ] } );
	h.click( '.rollback-toggle' );
	h.ok( 0, RELEASES );

	h.click( 'a.rollback-version-item' );
	assert.deepEqual( h.confirms, [ 'Roll back to version 0.9.0?' ] );
	assert.equal( h.ajax.length, 1, 'Declining does nothing.' );

	h.click( 'a.rollback-version-item' );
	assert.deepEqual( h.ajax[ 1 ].data, { action: 'update_github_plugin', nonce: 'nonce-1', repo: 'alpha', version: '0.9.0', is_rollback: 1 } );
	assert.equal( h.text( '.update-now' ), 'Rolling back…' );
	h.ok( 1, 'Plugin updated successfully to version 0.9.0' );
	assert.equal( h.text( '.update-now' ), 'Rolled back' );
} );

test( 'rolling back works from a row without an update button', async () => {
	const h = await mount( '<table><tbody><tr><td><div class="row-actions"><a href="#" class="rollback-toggle" data-repo="alpha">Versions</a><span class="rollback-list" style="display:none;"></span></div></td></tr></tbody></table>' );
	h.click( '.rollback-toggle' );
	h.ok( 0, RELEASES );
	h.click( 'a.rollback-version-item' );
	assert.equal( h.ajax[ 1 ].data.is_rollback, 1 );
	h.fail( 1, 'x' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'x' );
} );

// ------------------------------------------------------ auto-update toggles

test( 'the auto-update checkbox saves and reverts when the server refuses', async () => {
	const h = await mount( ROW( '<input type="checkbox" class="autoupdate-toggle" data-repo="alpha">' ) );
	const box = h.$( '.autoupdate-toggle' );

	box.prop( 'checked', true ).trigger( 'change' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'save_autoupdate_setting', nonce: 'nonce-1', repo: 'alpha', enabled: 1 } );
	assert.equal( box.prop( 'disabled' ), true, 'The checkbox is locked while saving.' );
	h.ok( 0, 'Setting updated.' );
	assert.equal( box.prop( 'disabled' ), false );
	assert.equal( box.prop( 'checked' ), true );

	box.prop( 'checked', false ).trigger( 'change' );
	assert.equal( h.ajax[ 1 ].data.enabled, 0 );
	h.fail( 1, 'You do not have permission to change this setting.' );
	assert.equal( box.prop( 'checked' ), true, 'The previous state is restored.' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'You do not have permission to change this setting.' );

	box.prop( 'checked', false ).trigger( 'change' );
	h.error( 2 );
	assert.equal( box.prop( 'checked' ), true );
} );

// --------------------------------------------------------------- MU plugins

test( 'MU plugin buttons confirm where they should and post the file', async () => {
	const h = await mount( ROW( `
		<a href="#" class="mu-install-now" data-file="a.php">Install</a>
		<a href="#" class="mu-update-now" data-file="b.php">Update</a>
		<a href="#" class="mu-rollback-now" data-file="c.php">Rollback</a>
		<button id="mu-update-all" type="button">Update all</button>` ), { confirms: [ false, true, true, false, true ] } );

	h.click( '.mu-install-now' );
	assert.equal( h.ajax.length, 0 );
	assert.deepEqual( h.confirms, [ 'Install this MU plugin? MU plugins load automatically on every request.' ] );
	h.click( '.mu-install-now' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'install_mu_plugin', nonce: 'nonce-1', file: 'a.php' } );
	assert.equal( h.text( '.mu-install-now' ), 'Installing...' );

	h.click( '.mu-update-now' );
	assert.deepEqual( h.ajax[ 1 ].data, { action: 'update_mu_plugin', nonce: 'nonce-1', file: 'b.php' } );
	assert.equal( h.confirms.length, 2, 'Updating does not ask.' );

	h.click( '.mu-rollback-now' );
	assert.deepEqual( h.ajax[ 2 ].data, { action: 'rollback_mu_plugin', nonce: 'nonce-1', file: 'c.php' } );
	assert.equal( h.text( '.mu-rollback-now' ), 'Restoring...' );
	h.ok( 2, 'restored' );
	assert.equal( h.text( '.mu-rollback-now' ), 'Restored' );

	h.click( '#mu-update-all' );
	assert.equal( h.ajax.length, 3, 'Declining the update-all prompt stops it.' );
	h.click( '#mu-update-all' );
	assert.deepEqual( h.ajax[ 3 ].data, { action: 'update_all_mu_plugins', nonce: 'nonce-1' } );
} );

test( 'the MU auto-update checkbox saves and reverts on failure', async () => {
	const h = await mount( ROW( '<input type="checkbox" class="mu-autoupdate-toggle" data-file="a.php">' ) );
	const box = h.$( '.mu-autoupdate-toggle' );

	box.prop( 'checked', true ).trigger( 'change' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'save_mu_autoupdate', nonce: 'nonce-1', file: 'a.php', enabled: 1 } );
	h.fail( 0, 'The requested MU plugin is not in the trusted catalog.' );
	assert.equal( box.prop( 'checked' ), false );
	assert.equal( h.text( '#plugin-hub-messages' ), 'The requested MU plugin is not in the trusted catalog.' );
} );
