jQuery( document ).ready( function( $ ) {
	var i18n = pluginHubAjax.i18n;
	var modalTrigger = null;

	// =========================================================================
	// Install
	// =========================================================================

	$( '.install-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		performAction(
			'install_github_plugin',
			button,
			i18n.installing,
			i18n.installed,
			i18n.install_failed,
			{ repo: button.data( 'repo' ), version: button.data( 'version' ) }
		);
	} );

	// =========================================================================
	// Update
	// =========================================================================

	$( '.update-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		updatePlugin( button, button.data( 'repo' ), button.data( 'version' ), false );
	} );

	function updatePlugin( button, repo, version, isRollback ) {
		var processingText = isRollback ? i18n.rolling_back : i18n.updating;
		var successText    = isRollback ? i18n.rolled_back  : i18n.updated;
		var failText       = isRollback ? i18n.rollback_failed : i18n.update_failed;

		button.text( processingText );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action:      'update_github_plugin',
				nonce:       pluginHubAjax.nonce,
				repo:        repo,
				version:     version,
				is_rollback: isRollback ? 1 : 0,
			},
			success: function( response ) {
				if ( response.success ) {
					button.text( successText );
					showMessage( response.data, 'success' );
					setTimeout( function() { verifyUpdate( repo, version ); }, 500 );
				} else {
					button.text( failText );
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				button.text( failText );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	}

	function verifyUpdate( repo, version ) {
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action:  'verify_plugin_update',
				nonce:   pluginHubAjax.nonce,
				repo:    repo,
				version: version,
			},
			success: function( response ) {
				showMessage( response.data, response.success ? 'success' : 'error' );
				if ( response.success ) {
					setTimeout( function() { location.reload(); }, 1000 );
				}
			},
			error: function() {
				showMessage( i18n.verify_error, 'error' );
			},
		} );
	}

	// =========================================================================
	// Activate / Deactivate
	// =========================================================================

	$( '.activate-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		performAction( 'activate_github_plugin', button, i18n.activating, i18n.activated, i18n.activation_failed, { repo: button.data( 'repo' ) } );
	} );

	$( '.deactivate-now' ).on( 'click', function( e ) {
		e.preventDefault();
		var button = $( this );
		performAction( 'deactivate_github_plugin', button, i18n.deactivating, i18n.deactivated, i18n.deactivation_failed, { repo: button.data( 'repo' ) } );
	} );

	// =========================================================================
	// Delete
	// =========================================================================

	$( '.delete-now' ).on( 'click', function( e ) {
		e.preventDefault();
		if ( ! window.confirm( i18n.delete_confirm ) ) {
			return;
		}
		var button = $( this );
		performAction( 'delete_github_plugin', button, i18n.deleting, i18n.deleted, i18n.delete_failed, { repo: button.data( 'repo' ) } );
	} );

	// =========================================================================
	// Changelog modal
	// =========================================================================

	$( document ).on( 'click', '.open-changelog', function( e ) {
		e.preventDefault();
		var link = $( this );

		$( '#plugin-hub-modal-title' ).text( i18n.loading_changelog );
		$( '#plugin-hub-modal-content' ).empty();
		openModal();

		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action:          'get_changelog',
				nonce:           pluginHubAjax.nonce,
				repo:            link.data( 'repo' ),
				current_version: link.data( 'current-version' ),
				new_version:     link.data( 'new-version' ),
			},
			success: function( response ) {
				$( '#plugin-hub-modal-title' ).text( i18n.changelog + ': ' + link.data( 'repo' ) );
				$( '#plugin-hub-modal-content' ).html(
					response.success && response.data ? response.data : '<p>' + i18n.no_changelog + '</p>'
				);
			},
			error: function() {
				$( '#plugin-hub-modal-content' ).text( i18n.error_occurred );
			},
		} );
	} );

	$( '#plugin-hub-modal-close, #plugin-hub-modal-overlay' ).on( 'click', closeModal );
	$( document ).on( 'keydown', function( e ) {
		if ( 27 === e.which ) {
			closeModal();
		}
	} );

	function openModal() {
		modalTrigger = document.activeElement;
		$( '#plugin-hub-modal-overlay, #plugin-hub-modal' ).prop( 'hidden', false ).hide().fadeIn( 150 );
		$( '#plugin-hub-modal' ).trigger( 'focus' );
	}

	function closeModal() {
		$( '#plugin-hub-modal-overlay, #plugin-hub-modal' ).fadeOut( 150, function() {
			$( this ).prop( 'hidden', true );
		} );
		if ( modalTrigger ) {
			modalTrigger.focus();
			modalTrigger = null;
		}
	}

	$( '#plugin-hub-modal' ).on( 'keydown', function( e ) {
		if ( 9 !== e.which ) {
			return;
		}

		var focusable = $( this ).find( 'a[href], button:not(:disabled), [tabindex]:not([tabindex="-1"])' ).filter( ':visible' );
		if ( ! focusable.length ) {
			e.preventDefault();
			return;
		}

		var first = focusable.first()[ 0 ];
		var last  = focusable.last()[ 0 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	} );

	// =========================================================================
	// Rollback
	// =========================================================================

	$( document ).on( 'click', '.rollback-toggle', function( e ) {
		e.preventDefault();
		var link    = $( this );
		var repo    = link.data( 'repo' );
		var listEl  = link.siblings( '.rollback-list' );

		if ( listEl.is( ':visible' ) ) {
			listEl.hide();
			return;
		}

		if ( listEl.data( 'loaded' ) ) {
			listEl.show();
			return;
		}

		link.text( i18n.loading_versions );

		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'get_plugin_releases', nonce: pluginHubAjax.nonce, repo: repo },
			success: function( response ) {
				link.text( i18n.versions );

				if ( ! response.success || ! response.data.length ) {
					showMessage( i18n.error_occurred, 'error' );
					return;
				}

				listEl.empty().append( $( '<br>' ) );
				$.each( response.data, function( idx, release ) {
					var item;
					var label = $( '<span>' ).text( release.version );
					if ( release.date ) {
						label.append( ' ' ).append( $( '<span class="rollback-date">' ).text( '(' + release.date + ')' ) );
					}
					if ( release.current ) {
						item = $( '<span class="rollback-version-item rollback-current">' )
							.append( label )
							.append( ' — ' )
							.append( $( '<em>' ).text( i18n.current_version ) );
					} else {
						item = $( '<a href="#" class="rollback-version-item">' )
							.attr( 'data-repo', repo )
							.attr( 'data-version', release.version )
							.append( label );
					}
					listEl.append( item ).append( ' ' );
				} );

				listEl.data( 'loaded', true ).show();
			},
			error: function() {
				link.text( i18n.versions );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	} );

	$( document ).on( 'click', '.rollback-version-item[data-version]', function( e ) {
		e.preventDefault();
		var link    = $( this );
		var repo    = link.data( 'repo' );
		var version = link.data( 'version' );
		var confirm_msg = i18n.rollback_confirm.replace( '%s', version );

		if ( ! window.confirm( confirm_msg ) ) {
			return;
		}

		link.closest( '.rollback-list' ).hide();
		var fakeButton = link.closest( 'tr' ).find( '.update-now' );
		if ( ! fakeButton.length ) {
			fakeButton = $( '<span>' ).appendTo( link.closest( '.row-actions' ) );
		}

		updatePlugin( fakeButton, repo, version, true );
	} );

	// =========================================================================
	// Auto-update toggle
	// =========================================================================

	$( document ).on( 'change', '.autoupdate-toggle', function() {
		var checkbox = $( this );
		var repo     = checkbox.data( 'repo' );
		var enabled  = checkbox.is( ':checked' );

		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'save_autoupdate_setting', nonce: pluginHubAjax.nonce, repo: repo, enabled: enabled ? 1 : 0 },
			success: function( response ) {
				if ( ! response.success ) {
					checkbox.prop( 'checked', ! enabled );
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				checkbox.prop( 'checked', ! enabled );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	} );

	// =========================================================================
	// GitHub token
	// =========================================================================

	$( '#save-github-token' ).on( 'click', function() {
		var button = $( this );
		var token  = $( '#github-token' ).val();
		button.prop( 'disabled', true ).text( i18n.saving );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'save_github_token', nonce: pluginHubAjax.nonce, token: token },
			success: function( response ) {
				button.prop( 'disabled', false ).text( i18n.save_token );
				if ( response.success ) {
					$( '#github-token' ).val( '' );
				}
				var color = response.success ? '#46b450' : '#dc3232';
				var mark  = response.success ? '✓ ' : '✗ ';
				$( '#token-status' ).text( mark + response.data ).css( 'color', color );
				setTimeout( function() {
					$( '#token-status' ).fadeOut( function() { $( this ).text( '' ).show(); } );
				}, 3000 );
			},
			error: function() {
				button.prop( 'disabled', false ).text( i18n.save_token );
				$( '#token-status' ).text( '✗ ' + i18n.error_occurred ).css( 'color', '#dc3232' );
			},
		} );
	} );

	$( '#remove-github-token' ).on( 'click', function() {
		var button = $( this );
		button.prop( 'disabled', true );
		$.ajax( {
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'save_github_token', nonce: pluginHubAjax.nonce, clear: 1 },
			success: function( response ) {
				if ( response.success ) {
					location.reload();
					return;
				}
				button.prop( 'disabled', false );
				showMessage( response.data, 'error' );
			},
			error: function() {
				button.prop( 'disabled', false );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	} );

	// =========================================================================
	// Beta toggle
	// =========================================================================

	$( '#show-beta-plugins' ).on( 'change', function() {
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'toggle_beta_plugins', nonce: pluginHubAjax.nonce, show_beta: $( this ).is( ':checked' ) },
			success: function( response ) {
				if ( response.success ) {
					location.reload();
				} else {
					showMessage( response.data, 'error' );
				}
			},
			error: function() { showMessage( i18n.error_occurred, 'error' ); },
		} );
	} );

	// =========================================================================
	// Clear activity log
	// =========================================================================

	$( '#clear-activity-log' ).on( 'click', function() {
		if ( ! window.confirm( i18n.clear_log_confirm ) ) {
			return;
		}
		var button = $( this );
		button.prop( 'disabled', true ).text( i18n.clearing );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'clear_activity_log', nonce: pluginHubAjax.nonce },
			success: function( response ) {
				button.prop( 'disabled', false ).text( i18n.clear_log );
				if ( response.success ) {
					location.reload();
				} else {
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				button.prop( 'disabled', false ).text( i18n.clear_log );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	} );

	// =========================================================================
	// Bulk actions
	// =========================================================================

	$( '#plugin-hub-form' ).on( 'submit', function( e ) {
		e.preventDefault();
		var action          = $( '#bulk-action-selector-top' ).val();
		var selectedPlugins = $( 'input[name="checked[]"]:checked' ).map( function() { return $( this ).val(); } ).get();

		if ( '-1' === action || 0 === selectedPlugins.length ) {
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
						.closest( 'tr' ).find( '.deactivate-now' ).length;
				} );
				if ( 0 === inactivePlugins.length ) {
					window.alert( i18n.no_inactive_selected );
					return;
				}
				bulkAction( 'delete_github_plugin', inactivePlugins );
				break;
		}
	} );

	function performAction( action, button, processingText, successText, failText, data, callback ) {
		button.text( processingText );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: $.extend( { action: action, nonce: pluginHubAjax.nonce }, data ),
			success: function( response ) {
				if ( response.success ) {
					button.text( successText );
					showMessage( response.data, 'success' );
					if ( typeof callback === 'function' ) {
						callback( true );
					} else {
						setTimeout( function() { location.reload(); }, 1000 );
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
			},
		} );
	}

	function bulkAction( action, plugins ) {
		var total     = plugins.length;
		var processed = 0;
		var success   = 0;
		var fail      = 0;

		var statusDiv = $( '<div id="bulk-action-status" class="notice notice-info"><p></p></div>' );
		statusDiv.find( 'p' ).text( i18n.processing + ' 0/' + total );
		statusDiv.insertBefore( '.wp-list-table' );

		function next() {
			if ( processed >= total ) {
				statusDiv
					.removeClass( 'notice-info' )
					.addClass( fail > 0 ? 'notice-warning' : 'notice-success' )
					.find( 'p' )
					.text( i18n.processing + ' ' + success + '/' + total + ' ' + i18n.done.toLowerCase() );
				setTimeout( function() { location.reload(); }, 2000 );
				return;
			}

			var plugin = plugins[ processed ];
			var row    = $( 'input[name="checked[]"][value="' + plugin + '"]' ).closest( 'tr' );
			var selectors = {
				activate_github_plugin: '.activate-now',
				deactivate_github_plugin: '.deactivate-now',
				update_github_plugin: '.update-now',
				delete_github_plugin: '.delete-now',
			};
			var button = row.find( selectors[ action ] );
			var version;

			if ( ! button.length ) {
				processed++;
				fail++;
				statusDiv.find( 'p' ).text( i18n.processing + ' ' + processed + '/' + total );
				next();
				return;
			}
			version = button.data( 'version' ) || '';

			performAction(
				action, button,
				i18n.processing, i18n.done, i18n.failed,
				{ repo: plugin, version: version },
				function( ok ) {
					processed++;
					ok ? success++ : fail++;
					statusDiv.find( 'p' ).text( i18n.processing + ' ' + processed + '/' + total );
					next();
				}
			);
		}

		next();
	}

	// =========================================================================
	// Utilities
	// =========================================================================

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
		setTimeout( function() { messageDiv.fadeOut(); }, 5000 );
	}

	// Client-side search filter.
	$( '#plugin-search-input' ).on( 'keyup', function() {
		var searchText = $( this ).val().toLowerCase();
		$( '#the-list tr' ).each( function() {
			var name = $( this ).find( '.plugin-title strong' ).text().toLowerCase();
			var desc = $( this ).find( '.plugin-description p' ).text().toLowerCase();
			$( this ).toggle( name.indexOf( searchText ) > -1 || desc.indexOf( searchText ) > -1 );
		} );
	} );
} );
