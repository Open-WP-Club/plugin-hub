'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { mount } = require( './harness' );

// --------------------------------------------------------------------- pins

const PIN = ( version, pin = '' ) => `<table><tbody><tr><td><a href="#" class="pin-toggle" data-repo="alpha" data-version="${ version }" data-pin="${ pin }">Pin version</a></td></tr></tbody></table>`;

const options = ( h ) => h.$( '.plugin-hub-pin-panel select option' ).map( ( i, el ) => [ [ el.value, el.textContent ] ] ).get();

test( 'the pin panel offers hold, major and minor pins for the installed version', async () => {
	const h = await mount( PIN( '1.2.3' ) );
	h.click( '.pin-toggle' );

	assert.deepEqual( options( h ), [
		[ '', 'No pin (follow every update)' ],
		[ '1.2.3', 'Hold at 1.2.3 (never update)' ],
		[ '1.x', 'Stay on 1.x' ],
		[ '1.2.x', 'Stay on 1.2.x' ],
	] );
	assert.equal( h.$( '.plugin-hub-pin-panel select' ).val(), '', 'No pin is selected by default.' );
	assert.equal( h.$( '.plugin-hub-pin-panel select' ).attr( 'aria-label' ), 'Pin' );
} );

test( 'the current pin is selected, and an unusual one is kept as an option', async () => {
	let h = await mount( PIN( '1.2.3', '1.x' ) );
	h.click( '.pin-toggle' );
	assert.equal( h.$( '.plugin-hub-pin-panel select' ).val(), '1.x' );

	h = await mount( PIN( '1.2.3', '2.x' ) );
	h.click( '.pin-toggle' );
	assert.deepEqual( options( h ).pop(), [ '2.x', 'Stay on 2.x' ] );
	assert.equal( h.$( '.plugin-hub-pin-panel select' ).val(), '2.x' );
} );

test( 'non-numeric versions only get the hold option', async () => {
	const h = await mount( PIN( 'beta' ) );
	h.click( '.pin-toggle' );
	assert.deepEqual( options( h ).map( ( option ) => option[ 0 ] ), [ '', 'beta' ] );
} );

test( 'the pin panel toggles and cancels', async () => {
	const h = await mount( PIN( '1.2.3' ) );
	h.click( '.pin-toggle' );
	assert.equal( h.$( '.plugin-hub-pin-panel' ).length, 1 );
	h.click( '.pin-toggle' );
	assert.equal( h.$( '.plugin-hub-pin-panel' ).length, 0, 'Clicking again closes it.' );

	h.click( '.pin-toggle' );
	h.$( '.plugin-hub-pin-panel .button-link' ).trigger( 'click' );
	assert.equal( h.$( '.plugin-hub-pin-panel' ).length, 0, 'Cancel closes it.' );
	assert.equal( h.ajax.length, 0 );
} );

test( 'saving a pin posts it and reloads', async () => {
	const h = await mount( PIN( '1.2.3' ) );
	h.click( '.pin-toggle' );
	h.$( '.plugin-hub-pin-panel select' ).val( '1.x' );
	h.$( '.plugin-hub-pin-panel .button-primary' ).trigger( 'click' );

	assert.deepEqual( h.ajax[ 0 ].data, { action: 'save_plugin_pin', nonce: 'nonce-1', repo: 'alpha', pin: '1.x' } );
	assert.equal( h.$( '.plugin-hub-pin-panel .button-primary' ).prop( 'disabled' ), true );
	h.ok( 0, 'Pin saved.' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Pin saved.' );
	h.flush( 600 );
	assert.equal( h.reloads, 1 );
} );

test( 'a refused pin keeps the panel open', async () => {
	const h = await mount( PIN( '1.2.3' ) );
	h.click( '.pin-toggle' );
	h.$( '.plugin-hub-pin-panel .button-primary' ).trigger( 'click' );
	h.fail( 0, 'Invalid version pin.' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Invalid version pin.' );
	assert.equal( h.$( '.plugin-hub-pin-panel .button-primary' ).prop( 'disabled' ), false );

	h.$( '.plugin-hub-pin-panel .button-primary' ).trigger( 'click' );
	h.error( 1 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
	assert.equal( h.reloads, 0 );
} );

// ------------------------------------------------- backups and activity rows

const ROWS = `<table><tbody><tr><td>
	<a href="#" class="restore-backup-now" data-id="alpha__1.0.0_1.zip" data-version="1.0.0">Restore</a>
	<a href="#" class="delete-backup-now" data-id="alpha__1.0.0_1.zip">Delete</a>
	<a href="#" class="delete-activity-now" data-id="e1-0">Delete</a>
</td></tr></tbody></table>`;

test( 'restoring a backup asks first and reports the result', async () => {
	const h = await mount( ROWS, { confirms: [ false, true ] } );

	h.click( '.restore-backup-now' );
	assert.deepEqual( h.confirms, [ 'Restore this backup? The current plugin files will be replaced.' ] );
	assert.equal( h.ajax.length, 0 );

	h.click( '.restore-backup-now' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'restore_backup', nonce: 'nonce-1', id: 'alpha__1.0.0_1.zip' } );
	assert.equal( h.text( '.restore-backup-now' ), 'Restoring…' );
	h.ok( 0, 'alpha restored to version 1.0.0.' );
	assert.equal( h.text( '.restore-backup-now' ), 'Done' );
} );

test( 'a failed restore says so', async () => {
	const h = await mount( ROWS );
	h.click( '.restore-backup-now' );
	h.fail( 0, 'The backup archive is damaged.' );
	assert.equal( h.text( '.restore-backup-now' ), 'Failed' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'The backup archive is damaged.' );
} );

test( 'deleting a backup or a log entry posts the id list and reloads', async () => {
	const h = await mount( ROWS, { confirms: [ false, true, true ] } );

	h.click( '.delete-backup-now' );
	assert.equal( h.ajax.length, 0, 'Declined.' );
	h.click( '.delete-backup-now' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'delete_backups', nonce: 'nonce-1', ids: [ 'alpha__1.0.0_1.zip' ] } );
	h.ok( 0, '1 backup(s) deleted.' );
	h.flush( 800 );
	assert.equal( h.reloads, 1 );

	h.click( '.delete-activity-now' );
	assert.deepEqual( h.ajax[ 1 ].data, { action: 'delete_activity_entries', nonce: 'nonce-1', ids: [ 'e1-0' ] } );
	h.fail( 1, 'You do not have permission to do this.' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'You do not have permission to do this.' );
	assert.equal( h.$( '.delete-activity-now' ).attr( 'aria-disabled' ), undefined, 'The link is usable again.' );
} );

test( 'a transport error while deleting re-enables the link', async () => {
	const h = await mount( ROWS );
	h.click( '.delete-backup-now' );
	h.error( 0 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
	assert.equal( h.$( '.delete-backup-now' ).attr( 'aria-disabled' ), undefined );
} );

// ----------------------------------------------------------------- settings

const SETTINGS = `
<form id="plugin-hub-settings-form">
	<input type="text" name="settings[notify_emails]" value="ops@example.test">
	<input type="checkbox" name="settings[window_days][]" value="1" checked>
	<button type="submit" id="plugin-hub-save-settings" class="button button-primary">Save settings</button>
	<span id="plugin-hub-settings-status"></span>
</form>
<button type="button" id="plugin-hub-test-notification" class="button">Send test notification</button>
<span id="plugin-hub-test-status"></span>`;

test( 'saving the settings serializes the form', async () => {
	const h = await mount( SETTINGS );

	const taken = h.submit( '#plugin-hub-settings-form', '#plugin-hub-save-settings' );
	assert.ok( taken, 'The browser submit is replaced by AJAX.' );

	const body = new URLSearchParams( h.ajax[ 0 ].data );
	assert.equal( body.get( 'action' ), 'save_hub_settings' );
	assert.equal( body.get( 'nonce' ), 'nonce-1' );
	assert.equal( body.get( 'settings[notify_emails]' ), 'ops@example.test' );
	assert.equal( body.get( 'settings[window_days][]' ), '1' );
	assert.equal( h.text( '#plugin-hub-save-settings' ), 'Saving...' );

	h.ok( 0, 'Settings saved.' );
	assert.equal( h.text( '#plugin-hub-settings-status' ), '✓ Settings saved.' );
	assert.equal( h.text( '#plugin-hub-save-settings' ), 'Save settings', 'The button label is restored.' );
	assert.equal( h.$( '#plugin-hub-save-settings' ).prop( 'disabled' ), false );
} );

test( 'a refused or failed settings save is marked as an error', async () => {
	const h = await mount( SETTINGS );
	h.submit( '#plugin-hub-settings-form', '#plugin-hub-save-settings' );
	h.fail( 0, 'You do not have permission to do this.' );
	assert.equal( h.text( '#plugin-hub-settings-status' ), '✗ You do not have permission to do this.' );

	h.submit( '#plugin-hub-settings-form', '#plugin-hub-save-settings' );
	h.error( 1 );
	assert.equal( h.text( '#plugin-hub-settings-status' ), '✗ An error occurred. Please try again.' );
} );

test( 'the test notification reports each channel', async () => {
	const h = await mount( SETTINGS );
	h.click( '#plugin-hub-test-notification' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'send_test_notification', nonce: 'nonce-1' } );
	assert.equal( h.text( '#plugin-hub-test-notification' ), 'Sending…' );

	h.ok( 0, 'Email sent. Webhook delivered.' );
	assert.equal( h.text( '#plugin-hub-test-status' ), '✓ Email sent. Webhook delivered.' );
	assert.equal( h.text( '#plugin-hub-test-notification' ), 'Send test notification' );

	h.click( '#plugin-hub-test-notification' );
	h.fail( 1, 'Email failed.' );
	assert.equal( h.text( '#plugin-hub-test-status' ), '✗ Email failed.' );
} );

// ------------------------------------------------------------------- import

const IMPORT = `<input type="file" id="plugin-hub-import-file"><button type="button" id="plugin-hub-import">Import configuration</button><div id="plugin-hub-import-result"></div>`;

function choose( h, contents ) {
	const file = new h.window.File( [ contents ], 'config.json', { type: 'application/json' } );
	Object.defineProperty( h.$( '#plugin-hub-import-file' )[ 0 ], 'files', { value: [ file ], configurable: true } );
}

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 30 ) );

test( 'importing without a file asks for one', async () => {
	const h = await mount( IMPORT );
	h.click( '#plugin-hub-import' );
	assert.equal( h.text( '#plugin-hub-import-result' ), '✗ Choose a configuration file first.' );
	assert.equal( h.ajax.length, 0 );
} );

test( 'the chosen file is sent as text', async () => {
	const h = await mount( IMPORT );
	choose( h, '{"format":"plugin-hub-config"}' );
	h.click( '#plugin-hub-import' );
	await settle();

	assert.deepEqual( h.ajax[ 0 ].data, { action: 'import_hub_config', nonce: 'nonce-1', config: '{"format":"plugin-hub-config"}' } );
	h.ok( 0, { message: 'Configuration imported.', missing: [], mu_missing: [] } );
	assert.equal( h.text( '#plugin-hub-import-result .notice p' ), 'Configuration imported.' );
	assert.equal( h.$( '#plugin-hub-import-result button' ).length, 0, 'Nothing is missing, so there is nothing to install.' );
} );

test( 'import errors are shown', async () => {
	const h = await mount( IMPORT );
	choose( h, 'junk' );
	h.click( '#plugin-hub-import' );
	await settle();
	h.fail( 0, 'This is not a Plugin Hub configuration file.' );
	assert.equal( h.text( '#plugin-hub-import-result' ), '✗ This is not a Plugin Hub configuration file.' );

	h.click( '#plugin-hub-import' );
	await settle();
	h.error( 1 );
	assert.equal( h.text( '#plugin-hub-import-result' ), '✗ An error occurred. Please try again.' );
} );

test( 'missing plugins can be installed from the import result, one after another', async () => {
	const h = await mount( `<div class="tablenav"><button class="button">Apply</button></div>${ IMPORT }` );
	choose( h, '{}' );
	h.click( '#plugin-hub-import' );
	await settle();
	h.ok( 0, {
		message: 'Configuration imported.',
		missing: [ { repo: 'gamma', version: '3.0.0', active: true }, { repo: 'delta', version: '1.0.0', active: false } ],
		mu_missing: [ 'one.php' ],
	} );

	assert.deepEqual( h.$( '#plugin-hub-import-result li' ).map( ( i, el ) => el.textContent ).get(), [ 'gamma 3.0.0', 'delta 1.0.0', 'MU: one.php' ] );
	assert.equal( h.text( '#plugin-hub-import-result p:first' ), 'Configuration imported.' );
	assert.match( h.text( '#plugin-hub-import-result' ), /Some plugins from this file are not installed yet\./ );

	h.$( '#plugin-hub-import-result .button-primary' ).trigger( 'click' );
	assert.equal( h.text( '#bulk-action-status p' ), 'Processing 0 of 4' );
	assert.equal( h.$( '.tablenav .button' ).prop( 'disabled' ), true );

	const expected = [
		[ 'install_github_plugin', { repo: 'gamma', version: '3.0.0' } ],
		[ 'activate_github_plugin', { repo: 'gamma' } ],
		[ 'install_github_plugin', { repo: 'delta', version: '1.0.0' } ],
		[ 'install_mu_plugin', { file: 'one.php' } ],
	];
	for ( let i = 0; i < expected.length; i++ ) {
		assert.equal( h.ajax.length, i + 2, 'Only one job runs at a time.' );
		assert.deepEqual( h.ajax[ i + 1 ].data, Object.assign( { action: expected[ i ][ 0 ], nonce: 'nonce-1' }, expected[ i ][ 1 ] ) );
		h.ok( i + 1, 'done' );
	}
	assert.equal( h.text( '#bulk-action-status p' ), '4 of 4 completed' );
	h.flush( 2000 );
	assert.equal( h.reloads, 1 );
} );

// --------------------------------------------------------- token / beta / log

const SIDEBAR = `
<input type="password" id="github-token" value="ghp_secret">
<button type="button" id="save-github-token" class="button">Save token</button>
<button type="button" id="remove-github-token" class="button">Remove</button>
<span id="token-status"></span>
<input type="checkbox" id="show-beta-plugins">
<button type="button" id="clear-activity-log" class="button">Clear Log</button>`;

test( 'saving the GitHub token clears the field and reports success', async () => {
	const h = await mount( SIDEBAR );
	h.click( '#save-github-token' );

	assert.deepEqual( h.ajax[ 0 ].data, { action: 'save_github_token', nonce: 'nonce-1', token: 'ghp_secret' } );
	assert.equal( h.text( '#save-github-token' ), 'Saving...' );
	h.ok( 0, 'Token saved successfully.' );

	assert.equal( h.$( '#github-token' ).val(), '', 'The secret does not stay in the page.' );
	assert.equal( h.text( '#token-status' ), '✓ Token saved successfully.' );
	assert.equal( h.text( '#save-github-token' ), 'Save token' );
	assert.ok( h.timerDelays().includes( 3000 ), 'The status fades away.' );
} );

test( 'a refused token keeps the field and shows the reason', async () => {
	const h = await mount( SIDEBAR );
	h.click( '#save-github-token' );
	h.fail( 0, 'The GitHub token is managed in wp-config.php and cannot be changed here.' );
	assert.equal( h.$( '#github-token' ).val(), 'ghp_secret' );
	assert.match( h.text( '#token-status' ), /^✗ The GitHub token is managed/ );

	h.click( '#save-github-token' );
	h.error( 1 );
	assert.equal( h.text( '#token-status' ), '✗ An error occurred. Please try again.' );
} );

test( 'removing the token reloads on success', async () => {
	const h = await mount( SIDEBAR );
	h.click( '#remove-github-token' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'save_github_token', nonce: 'nonce-1', clear: 1 } );
	h.ok( 0, 'Token removed.' );
	assert.equal( h.reloads, 1 );

	h.click( '#remove-github-token' );
	h.fail( 1, 'nope' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'nope' );
	assert.equal( h.reloads, 1 );
} );

test( 'the beta checkbox saves and reloads', async () => {
	const h = await mount( SIDEBAR );
	h.$( '#show-beta-plugins' ).prop( 'checked', true ).trigger( 'change' );
	assert.equal( h.ajax[ 0 ].data.action, 'toggle_beta_plugins' );
	assert.equal( h.ajax[ 0 ].data.show_beta, true );
	h.ok( 0, 'Setting updated successfully.' );
	assert.equal( h.reloads, 1 );
	assert.equal( h.$( '#show-beta-plugins' ).prop( 'disabled' ), false );

	h.$( '#show-beta-plugins' ).prop( 'checked', false ).trigger( 'change' );
	assert.equal( h.ajax[ 1 ].data.show_beta, false );
	h.fail( 1, 'You do not have permission to change this setting.' );
	assert.equal( h.reloads, 1 );

	h.$( '#show-beta-plugins' ).trigger( 'change' );
	h.error( 2 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
} );

test( 'clearing the activity log asks first', async () => {
	const h = await mount( SIDEBAR, { confirms: [ false, true, true, true ] } );

	h.click( '#clear-activity-log' );
	assert.deepEqual( h.confirms, [ 'Clear the entire activity log?' ] );
	assert.equal( h.ajax.length, 0 );

	h.click( '#clear-activity-log' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'clear_activity_log', nonce: 'nonce-1' } );
	assert.equal( h.text( '#clear-activity-log' ), 'Clearing…' );
	h.ok( 0, 'Activity log cleared.' );
	assert.equal( h.reloads, 1 );
	assert.equal( h.text( '#clear-activity-log' ), 'Clear Log' );

	h.click( '#clear-activity-log' );
	h.fail( 1, 'You do not have permission.' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'You do not have permission.' );

	h.click( '#clear-activity-log' );
	h.error( 2 );
	assert.equal( h.text( '#clear-activity-log' ), 'Clear Log' );
} );
