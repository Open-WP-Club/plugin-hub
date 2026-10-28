'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { mount } = require( './harness' );

const BULK = ( table, rows, actions = '' ) => `
<form id="plugin-hub-form" method="get" data-table="${ table }">
	<div class="tablenav top">
		<select name="action"><option value="-1">Bulk actions</option>${ actions }</select>
		<input type="submit" id="doaction" class="button action" value="Apply">
		<input type="search" name="s"><input type="submit" id="search-submit" class="button" value="Search">
	</div>
	<table><tbody>${ rows }</tbody></table>
	<div class="tablenav bottom">
		<select name="action2"><option value="-1">Bulk actions</option>${ actions }</select>
		<input type="submit" id="doaction2" class="button action" value="Apply">
	</div>
</form>`;

const OPTION = ( ...values ) => values.map( ( value ) => `<option value="${ value }">${ value }</option>` ).join( '' );
const ROW = ( value, inner = '', checked = true ) => `<tr><th><input type="checkbox" name="checked[]" value="${ value }"${ checked ? ' checked' : '' }></th><td>${ inner }</td></tr>`;

const PLUGIN_ROWS =
	ROW( 'alpha', '<a class="update-now" data-repo="alpha" data-version="1.5.0">Update</a><a class="deactivate-now" data-repo="alpha" data-dependents="Zeta">Deactivate</a><input type="checkbox" class="autoupdate-toggle" data-repo="alpha"><a class="delete-now" data-repo="alpha">x</a>' ) +
	ROW( 'beta', '<a class="activate-now" data-repo="beta">Activate</a><a class="activate-now network-activate-now" data-network="1" data-repo="beta">Net</a><a class="deactivate-now" data-network="1" data-repo="beta">NetOff</a>' ) +
	ROW( 'gamma', '<a class="install-now" data-repo="gamma" data-version="3.0.0">Install</a>' ) +
	ROW( 'unchecked', '<a class="update-now" data-repo="unchecked" data-version="9.9.9">Update</a>', false );

const PLUGIN_ACTIONS = OPTION( 'install', 'activate', 'deactivate', 'update', 'delete', 'enable_autoupdate', 'disable_autoupdate', 'network_activate', 'network_deactivate' );

async function plugins( options = {} ) {
	return mount( `<div class="plugin-hub-main-inner"></div>${ BULK( 'plugins', PLUGIN_ROWS, PLUGIN_ACTIONS ) }`, options );
}

function apply( h, action, button = '#doaction' ) {
	h.$( 'doaction2' === button.slice( 1 ) ? 'select[name="action2"]' : 'select[name="action"]' ).val( action );
	return h.submit( '#plugin-hub-form', button );
}

// ---------------------------------------------------------------- behaviour

test( 'search and filter submits keep their normal behaviour', async () => {
	const h = await plugins();
	assert.equal( h.submit( '#plugin-hub-form', '#search-submit' ), false );
	assert.equal( h.submit( '#plugin-hub-form', null ), false );
	assert.equal( h.ajax.length, 0 );
} );

test( 'applying with no action or no selection explains what is missing', async () => {
	const h = await plugins();

	assert.ok( apply( h, '-1' ), 'The submit is taken over.' );
	assert.deepEqual( h.alerts, [ 'Please select an action and at least one item.' ] );

	h.$( 'input[name="checked[]"]' ).prop( 'checked', false );
	apply( h, 'update' );
	assert.equal( h.alerts.length, 2 );
	assert.equal( h.ajax.length, 0 );
} );

test( 'the bottom Apply button reads the bottom action menu', async () => {
	const h = await plugins();
	h.$( 'select[name="action"]' ).val( '-1' );
	h.$( 'select[name="action2"]' ).val( 'update' );
	h.submit( '#plugin-hub-form', '#doaction2' );
	assert.equal( h.ajax[ 0 ].data.action, 'update_github_plugin' );
} );

// ------------------------------------------------------------- plugin table

test( 'bulk update runs the selected rows one at a time and skips rows without the action', async () => {
	const h = await plugins();
	apply( h, 'update' );

	assert.equal( h.text( '#bulk-action-status p' ), 'Processing 0 of 3' );
	assert.equal( h.$( '.tablenav .button' ).prop( 'disabled' ), true, 'The controls are locked while it runs.' );
	assert.equal( h.$( '#bulk-action-status' ).attr( 'aria-live' ), 'polite' );
	assert.equal( h.ajax.length, 1 );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'update_github_plugin', nonce: 'nonce-1', repo: 'alpha', version: '1.5.0' } );

	h.ok( 0, 'Plugin updated successfully.' );
	assert.equal( h.ajax.length, 1, 'beta and gamma have no update button, so they are skipped without a request.' );
	assert.equal( h.text( '#bulk-action-status p' ), '1 of 3 completed' );
	assert.ok( h.$( '#bulk-action-status' ).hasClass( 'notice-success' ) );
	assert.ok( ! h.$( '#bulk-action-status' ).hasClass( 'notice-info' ) );

	h.flush( 2000 );
	assert.equal( h.reloads, 1 );
	assert.equal( h.$( 'input[value="unchecked"]' ).length, 1, 'Unselected rows are not touched.' );
} );

test( 'a failed job is reported and turns the summary into a warning', async () => {
	const h = await plugins();
	apply( h, 'install' );
	assert.equal( h.ajax.length, 1, 'Only gamma has an install button.' );

	h.fail( 0, 'Download failed' );
	assert.equal( h.text( '#plugin-hub-messages' ), 'Download failed' );
	assert.ok( h.$( '#bulk-action-status' ).hasClass( 'notice-warning' ) );
	assert.ok( ! h.$( '#bulk-action-status' ).hasClass( 'notice-success' ) );
	assert.equal( h.text( '#bulk-action-status p' ), '0 of 3 completed' );
} );

test( 'the queue carries on after a failure', async () => {
	const rows = ROW( 'a', '<a class="update-now" data-repo="a" data-version="1.0.1">u</a>' ) + ROW( 'b', '<a class="update-now" data-repo="b" data-version="2.0.1">u</a>' );
	const h = await mount( BULK( 'plugins', rows, OPTION( 'update' ) ) );
	apply( h, 'update' );

	h.fail( 0, 'first failed' );
	assert.equal( h.ajax.length, 2, 'The second job still runs.' );
	h.ok( 1, 'ok' );
	assert.equal( h.text( '#bulk-action-status p' ), '1 of 2 completed' );
	assert.ok( h.$( '#bulk-action-status' ).hasClass( 'notice-warning' ) );
} );

test( 'a transport error counts as a failed job', async () => {
	const h = await plugins();
	apply( h, 'update' );
	h.error( 0 );
	assert.equal( h.text( '#plugin-hub-messages' ), 'An error occurred. Please try again.' );
	assert.ok( h.$( '#bulk-action-status' ).hasClass( 'notice-warning' ) );
	assert.equal( h.text( '#bulk-action-status p' ), '0 of 3 completed' );
} );

const BULK_CASES = [
	[ 'install', [ { action: 'install_github_plugin', repo: 'gamma', version: '3.0.0' } ] ],
	[ 'activate', [ { action: 'activate_github_plugin', repo: 'beta' } ] ],
	[ 'network_activate', [ { action: 'activate_github_plugin', repo: 'beta', network: 1 } ] ],
	[ 'network_deactivate', [ { action: 'deactivate_github_plugin', repo: 'beta', network: 1 } ] ],
	[ 'enable_autoupdate', [ { action: 'save_autoupdate_setting', repo: 'alpha', enabled: 1 } ] ],
	[ 'disable_autoupdate', [ { action: 'save_autoupdate_setting', repo: 'alpha', enabled: 0 } ] ],
];

for ( const [ action, jobs ] of BULK_CASES ) {
	test( `bulk "${ action }" sends the right requests`, async () => {
		const h = await plugins();
		apply( h, action );

		for ( let i = 0; i < jobs.length; i++ ) {
			assert.deepEqual( h.ajax[ i ].data, Object.assign( { nonce: 'nonce-1' }, jobs[ i ] ) );
			h.ok( i, 'ok' );
		}
		assert.equal( h.ajax.length, jobs.length, 'Rows without the matching control are skipped.' );
	} );
}

test( 'bulk deactivate warns once about dependents and then forces', async () => {
	const h = await plugins( { confirms: [ false, true ] } );

	apply( h, 'deactivate' );
	assert.deepEqual( h.confirms, [ 'These active plugins depend on it: Zeta. Continue anyway?' ] );
	assert.equal( h.ajax.length, 0, 'Declining runs nothing.' );

	apply( h, 'deactivate' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'deactivate_github_plugin', nonce: 'nonce-1', repo: 'alpha', force: 1 } );
} );

test( 'bulk delete asks before deleting', async () => {
	const h = await plugins( { confirms: [ false, true ] } );
	apply( h, 'delete' );
	assert.deepEqual( h.confirms, [ 'Are you sure you want to delete the selected plugins?' ] );
	assert.equal( h.ajax.length, 0 );

	apply( h, 'delete' );
	assert.equal( h.ajax[ 0 ].data.action, 'delete_github_plugin' );
	assert.equal( h.ajax[ 0 ].data.repo, 'alpha' );
} );

test( 'several runnable rows are processed strictly in order', async () => {
	const rows = ROW( 'a', '<a class="update-now" data-repo="a" data-version="1.0.1">u</a>' ) + ROW( 'b', '<a class="update-now" data-repo="b" data-version="2.0.1">u</a>' ) + ROW( 'c', '<a class="update-now" data-repo="c" data-version="3.0.1">u</a>' );
	const h = await mount( BULK( 'plugins', rows, OPTION( 'update' ) ) );
	apply( h, 'update' );

	for ( const [ i, repo ] of [ 'a', 'b', 'c' ].entries() ) {
		assert.equal( h.ajax.length, i + 1 );
		assert.equal( h.ajax[ i ].data.repo, repo );
		assert.equal( h.text( '#bulk-action-status p' ), `Processing ${ i } of 3` );
		h.ok( i, 'ok' );
	}
	assert.equal( h.text( '#bulk-action-status p' ), '3 of 3 completed' );
} );

// --------------------------------------------------- activity and backups

test( 'activity entries are deleted after confirmation', async () => {
	const h = await mount( BULK( 'activity', ROW( 'e1-0' ) + ROW( 'e2-0' ) + ROW( 'e3-0', '', false ), OPTION( 'delete' ) ), { confirms: [ false, true ] } );

	apply( h, 'delete' );
	assert.deepEqual( h.confirms, [ 'Delete the selected activity entries?' ] );
	assert.equal( h.ajax.length, 0 );

	apply( h, 'delete' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'delete_activity_entries', nonce: 'nonce-1', ids: [ 'e1-0', 'e2-0' ] } );
	h.ok( 0, '2 entries deleted.' );
	h.flush( 800 );
	assert.equal( h.reloads, 1 );
} );

test( 'backups are deleted after confirmation', async () => {
	const h = await mount( BULK( 'backups', ROW( 'alpha__1.0.0_1.zip' ), OPTION( 'delete' ) ), { confirms: [ true ] } );
	apply( h, 'delete' );
	assert.deepEqual( h.confirms, [ 'Delete the selected backups?' ] );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'delete_backups', nonce: 'nonce-1', ids: [ 'alpha__1.0.0_1.zip' ] } );
} );

// ---------------------------------------------------------------- MU tables

const MU_ROWS =
	ROW( 'one.php', '<a class="mu-update-now" data-file="one.php">u</a><a class="mu-rollback-now" data-file="one.php">r</a><input type="checkbox" class="mu-autoupdate-toggle" data-file="one.php">' ) +
	ROW( 'two.php', '<span class="description">Up to date</span>' );

test( 'MU update, rollback and auto-update skip rows that cannot do it', async () => {
	const h = await mount( BULK( 'mu-installed', MU_ROWS, OPTION( 'update', 'rollback', 'enable_autoupdate', 'disable_autoupdate' ) ), { confirms: [ true ] } );

	apply( h, 'update' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'update_mu_plugin', nonce: 'nonce-1', file: 'one.php' } );
	assert.equal( h.text( '#bulk-action-status p' ), 'Processing 0 of 2' );
	h.ok( 0, 'ok' );
	assert.equal( h.ajax.length, 1, 'two.php is skipped.' );
	assert.equal( h.text( '#bulk-action-status p' ), '1 of 2 completed' );
} );

test( 'MU rollback asks for confirmation', async () => {
	const h = await mount( BULK( 'mu-installed', MU_ROWS, OPTION( 'rollback' ) ), { confirms: [ false, true ] } );
	apply( h, 'rollback' );
	assert.equal( h.confirms[ 0 ], 'Restore the previous version of this MU plugin? Automatic updates will be switched off for it.' );
	assert.equal( h.ajax.length, 0 );
	apply( h, 'rollback' );
	assert.equal( h.ajax[ 0 ].data.action, 'rollback_mu_plugin' );
} );

test( 'MU auto-update toggles per file', async () => {
	const h = await mount( BULK( 'mu-installed', MU_ROWS, OPTION( 'enable_autoupdate', 'disable_autoupdate' ) ) );
	apply( h, 'enable_autoupdate' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'save_mu_autoupdate', nonce: 'nonce-1', file: 'one.php', enabled: 1 } );
	h.ok( 0, 'ok' );

	apply( h, 'disable_autoupdate' );
	assert.equal( h.ajax[ 1 ].data.enabled, 0 );
} );

test( 'installing available MU plugins asks first and never skips', async () => {
	const rows = ROW( 'a.php' ) + ROW( 'b.php' );
	const h = await mount( BULK( 'mu-available', rows, OPTION( 'install' ) ), { confirms: [ false, true ] } );

	apply( h, 'install' );
	assert.equal( h.confirms[ 0 ], 'Install this MU plugin? MU plugins load automatically on every request.' );
	assert.equal( h.ajax.length, 0 );

	apply( h, 'install' );
	assert.deepEqual( h.ajax[ 0 ].data, { action: 'install_mu_plugin', nonce: 'nonce-1', file: 'a.php' } );
	h.ok( 0, 'ok' );
	assert.deepEqual( h.ajax[ 1 ].data, { action: 'install_mu_plugin', nonce: 'nonce-1', file: 'b.php' } );
} );
