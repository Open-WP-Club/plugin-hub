jQuery( document ).ready( function( $ ) {
	var i18n = pluginHubAjax.i18n;

	// Install plugin.
	$( '.install-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		var repo = button.data( 'repo' );
		var version = button.data( 'version' );
		performAction(
			'install_github_plugin',
			button,
			i18n.installing,
			i18n.installed,
			i18n.install_failed,
			{ repo: repo, version: version }
		);
	});

	// Update plugin.
	$( '.update-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		var repo = button.data( 'repo' );
		var version = button.data( 'version' );
		updatePlugin( button, repo, version );
	});

	function updatePlugin( button, repo, version ) {
		button.text( i18n.updating );
		$.ajax({
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'update_github_plugin',
				nonce: pluginHubAjax.nonce,
				repo: repo,
				version: version
			},
			success: function( response ) {
				if ( response.success ) {
					button.text( i18n.updated );
					showMessage( response.data, 'success' );
					setTimeout( function() {
						verifyUpdate( repo, version );
					}, 2000 );
				} else {
					button.text( i18n.update_failed );
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				button.text( i18n.update_failed );
				showMessage( i18n.error_occurred, 'error' );
			}
		});
	}

	function verifyUpdate( repo, version ) {
		$.ajax({
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'verify_plugin_update',
				nonce: pluginHubAjax.nonce,
				repo: repo,
				version: version
			},
			success: function( response ) {
				if ( response.success ) {
					showMessage( response.data, 'success' );
					setTimeout( function() {
						location.reload();
					}, 1000 );
				} else {
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				showMessage( i18n.verify_error, 'error' );
			}
		});
	}

	// Activate plugin.
	$( '.activate-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		var repo = button.data( 'repo' );
		performAction(
			'activate_github_plugin',
			button,
			i18n.activating,
			i18n.activated,
			i18n.activation_failed,
			{ repo: repo }
		);
	});

	// Deactivate plugin.
	$( '.deactivate-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		var repo = button.data( 'repo' );
		performAction(
			'deactivate_github_plugin',
			button,
			i18n.deactivating,
			i18n.deactivated,
			i18n.deactivation_failed,
			{ repo: repo }
		);
	});

	// Delete plugin.
	$( '.delete-now' ).on( 'click', function( e ) {
		e.preventDefault();
		if ( ! window.confirm( i18n.delete_confirm ) ) {
			return;
		}
		var button = $( this );
		var repo = button.data( 'repo' );
		performAction(
			'delete_github_plugin',
			button,
			i18n.deleting,
			i18n.deleted,
			i18n.delete_failed,
			{ repo: repo }
		);
	});

	// Save GitHub token.
	$( '#save-github-token' ).on( 'click', function() {
		var button = $( this );
		var token = $( '#github-token' ).val();
		button.prop( 'disabled', true ).text( i18n.saving );
		$.ajax({
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'save_github_token',
				nonce: pluginHubAjax.nonce,
				token: token
			},
			success: function( response ) {
				button.prop( 'disabled', false ).text( i18n.save_token );
				if ( response.success ) {
					$( '#token-status' ).text( '✓ ' + response.data ).css( 'color', '#46b450' );
				} else {
					$( '#token-status' ).text( '✗ ' + response.data ).css( 'color', '#dc3232' );
				}
				setTimeout( function() {
					$( '#token-status' ).fadeOut( function() {
						$( this ).text( '' ).show();
					});
				}, 3000 );
			},
			error: function() {
				button.prop( 'disabled', false ).text( i18n.save_token );
				$( '#token-status' ).text( '✗ ' + i18n.error_occurred ).css( 'color', '#dc3232' );
			}
		});
	});

	// Beta plugin toggle.
	$( '#show-beta-plugins' ).on( 'change', function() {
		var showBeta = $( this ).is( ':checked' );
		$.ajax({
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'toggle_beta_plugins',
				nonce: pluginHubAjax.nonce,
				show_beta: showBeta
			},
			success: function( response ) {
				if ( response.success ) {
					location.reload();
				} else {
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				showMessage( i18n.error_occurred, 'error' );
			}
		});
	});

	// Bulk actions.
	$( '#plugin-hub-form' ).on( 'submit', function( e ) {
		e.preventDefault();
		var action = $( '#bulk-action-selector-top' ).val();
		var selectedPlugins = $( 'input[name="checked[]"]:checked' )
			.map( function() {
				return $( this ).val();
			})
			.get();

		if ( action === '-1' || selectedPlugins.length === 0 ) {
			window.alert( i18n.select_action_plugin );
			return;
		}

		switch ( action ) {
			case 'activate':
				bulkAction( 'activate_github_plugin', selectedPlugins );
				break;
			case 'deactivate':
				bulkAction( 'deactivate_github_plugin', selectedPlugins );
				break;
			case 'update':
				bulkAction( 'update_github_plugin', selectedPlugins );
				break;
			case 'delete':
				if ( ! window.confirm( i18n.bulk_delete_confirm ) ) {
					return;
				}
				var inactivePlugins = selectedPlugins.filter( function( plugin ) {
					return ! $( 'input[name="checked[]"][value="' + plugin + '"]' )
						.closest( 'tr' )
						.find( '.deactivate-now' ).length;
				});
				if ( inactivePlugins.length === 0 ) {
					window.alert( i18n.no_inactive_selected );
					return;
				}
				bulkAction( 'delete_github_plugin', inactivePlugins );
				break;
		}
	});

	/**
	 * Perform a single AJAX action on a plugin.
	 *
	 * @param {string}   action         The AJAX action name.
	 * @param {jQuery}   button         The button element.
	 * @param {string}   processingText Text shown while processing.
	 * @param {string}   successText    Text shown on success.
	 * @param {string}   failText       Text shown on failure.
	 * @param {Object}   data           Additional AJAX data.
	 * @param {Function} callback       Optional callback(success).
	 */
	function performAction( action, button, processingText, successText, failText, data, callback ) {
		button.text( processingText );
		$.ajax({
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: $.extend({
				action: action,
				nonce: pluginHubAjax.nonce
			}, data ),
			success: function( response ) {
				if ( response.success ) {
					button.text( successText );
					showMessage( response.data, 'success' );
					if ( typeof callback === 'function' ) {
						callback( true );
					} else {
						setTimeout( function() {
							location.reload();
						}, 1000 );
					}
				} else {
					button.text( failText );
					showMessage( response.data, 'error' );
					if ( typeof callback === 'function' ) {
						callback( false );
					}
				}
			},
			error: function() {
				button.text( failText );
				showMessage( i18n.error_occurred, 'error' );
				if ( typeof callback === 'function' ) {
					callback( false );
				}
			}
		});
	}

	function bulkAction( action, plugins ) {
		var totalPlugins = plugins.length;
		var processedPlugins = 0;
		var successCount = 0;
		var failCount = 0;

		var statusDiv = $( '<div id="bulk-action-status" class="notice notice-info"><p></p></div>' );
		statusDiv.find( 'p' ).text( i18n.processing + ' 0/' + totalPlugins );
		statusDiv.insertBefore( '.wp-list-table' );

		function processNextPlugin() {
			if ( processedPlugins >= totalPlugins ) {
				statusDiv
					.removeClass( 'notice-info' )
					.addClass( failCount > 0 ? 'notice-warning' : 'notice-success' )
					.find( 'p' )
					.text( i18n.processing.replace( /\.\.\.$/, '' ) + ' ' + i18n.done + '. ' + successCount + ' ' + i18n.done.toLowerCase() + ', ' + failCount + ' ' + i18n.failed.toLowerCase() );
				setTimeout( function() {
					location.reload();
				}, 2000 );
				return;
			}

			var plugin = plugins[ processedPlugins ];
			var row = $( 'input[name="checked[]"][value="' + plugin + '"]' ).closest( 'tr' );
			var button, version;

			// For update actions, target the update button specifically (it carries data-version).
			// For all other actions, the first visible action link is the right target.
			if ( 'update_github_plugin' === action ) {
				button = row.find( '.update-now' );
			} else {
				button = row.find( '.row-actions a:first' );
			}
			version = button.data( 'version' ) || '';

			performAction(
				action,
				button,
				i18n.processing,
				i18n.done,
				i18n.failed,
				{ repo: plugin, version: version },
				function( success ) {
					processedPlugins++;
					if ( success ) {
						successCount++;
					} else {
						failCount++;
					}
					statusDiv.find( 'p' ).text( i18n.processing + ' ' + processedPlugins + '/' + totalPlugins );
					processNextPlugin();
				}
			);
		}

		processNextPlugin();
	}

	function showMessage( message, type ) {
		var messageDiv = $( '#plugin-hub-messages' );
		if ( ! messageDiv.length ) {
			messageDiv = $( '<div id="plugin-hub-messages"></div>' ).insertBefore( '.wp-list-table' );
		}
		messageDiv
			.removeClass( 'notice-success notice-error notice-warning notice-info' )
			.addClass( 'notice notice-' + type )
			.empty()
			.append( $( '<p>' ).text( message ) )
			.fadeIn();
		setTimeout( function() {
			messageDiv.fadeOut();
		}, 5000 );
	}

	// Plugin search functionality.
	$( '#plugin-search-input' ).on( 'keyup', function() {
		var searchText = $( this ).val().toLowerCase();
		$( '#the-list tr' ).each( function() {
			var pluginName = $( this ).find( '.plugin-title strong' ).text().toLowerCase();
			var pluginDescription = $( this ).find( '.plugin-description p' ).text().toLowerCase();

			if ( pluginName.indexOf( searchText ) > -1 || pluginDescription.indexOf( searchText ) > -1 ) {
				$( this ).show();
			} else {
				$( this ).hide();
			}
		});
	});
});
