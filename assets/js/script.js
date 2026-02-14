jQuery( document ).ready( function( $ ) {

	// Install plugin.
	$( '.install-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		var repo = button.data( 'repo' );
		var version = button.data( 'version' );
		performAction(
			'install_github_plugin',
			button,
			'Installing...',
			'Installed',
			'Install Failed',
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
		button.text( 'Updating...' );
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
					button.text( 'Updated' );
					showMessage( response.data, 'success' );
					setTimeout( function() {
						verifyUpdate( repo, version );
					}, 2000 );
				} else {
					button.text( 'Update Failed' );
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				button.text( 'Update Failed' );
				showMessage( 'An error occurred. Please try again.', 'error' );
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
					showMessage( 'Update verified: ' + response.data, 'success' );
					setTimeout( function() {
						location.reload();
					}, 1000 );
				} else {
					showMessage( 'Update verification failed: ' + response.data, 'error' );
				}
			},
			error: function() {
				showMessage(
					'Failed to verify update. Please refresh the page and check the plugin version.',
					'error'
				);
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
			'Activating...',
			'Activated',
			'Activation Failed',
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
			'Deactivating...',
			'Deactivated',
			'Deactivation Failed',
			{ repo: repo }
		);
	});

	// Delete plugin.
	$( '.delete-now' ).on( 'click', function( e ) {
		e.preventDefault();
		if ( ! confirm( 'Are you sure you want to delete this plugin?' ) ) {
			return;
		}
		var button = $( this );
		var repo = button.data( 'repo' );
		performAction(
			'delete_github_plugin',
			button,
			'Deleting...',
			'Deleted',
			'Delete Failed',
			{ repo: repo }
		);
	});

	// Save GitHub token.
	$( '#save-github-token' ).on( 'click', function() {
		var button = $( this );
		var token = $( '#github-token' ).val();
		button.prop( 'disabled', true ).text( 'Saving...' );
		$.ajax({
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action: 'save_github_token',
				nonce: pluginHubAjax.nonce,
				token: token
			},
			success: function( response ) {
				button.prop( 'disabled', false ).text( 'Save Token' );
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
				button.prop( 'disabled', false ).text( 'Save Token' );
				$( '#token-status' ).text( '✗ An error occurred.' ).css( 'color', '#dc3232' );
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
				showMessage( 'An error occurred. Please try again.', 'error' );
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
			alert( 'Please select an action and at least one plugin.' );
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
				if ( ! confirm( 'Are you sure you want to delete the selected plugins?' ) ) {
					return;
				}
				var inactivePlugins = selectedPlugins.filter( function( plugin ) {
					return ! $( 'input[name="checked[]"][value="' + plugin + '"]' )
						.closest( 'tr' )
						.find( '.deactivate-now' ).length;
				});
				if ( inactivePlugins.length === 0 ) {
					alert( 'No inactive plugins selected for deletion. Active plugins cannot be deleted.' );
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
				showMessage( 'An error occurred. Please try again.', 'error' );
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

		$( '<div id="bulk-action-status" class="notice notice-info"><p>Processing: 0/' + totalPlugins + '</p></div>' )
			.insertBefore( '.wp-list-table' );

		function processNextPlugin() {
			if ( processedPlugins >= totalPlugins ) {
				$( '#bulk-action-status' )
					.removeClass( 'notice-info' )
					.addClass( failCount > 0 ? 'notice-warning' : 'notice-success' )
					.find( 'p' )
					.text( 'Bulk action completed. Success: ' + successCount + ', Failed: ' + failCount );
				setTimeout( function() {
					location.reload();
				}, 2000 );
				return;
			}

			var plugin = plugins[ processedPlugins ];
			var row = $( 'input[name="checked[]"][value="' + plugin + '"]' ).closest( 'tr' );
			var button = row.find( '.row-actions a:first' );
			var version = button.data( 'version' );

			performAction(
				action,
				button,
				'Processing...',
				'Done',
				'Failed',
				{ repo: plugin, version: version },
				function( success ) {
					processedPlugins++;
					if ( success ) {
						successCount++;
					} else {
						failCount++;
					}
					$( '#bulk-action-status p' ).text( 'Processing: ' + processedPlugins + '/' + totalPlugins );
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
			.html( '<p>' + message + '</p>' )
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
