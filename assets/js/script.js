jQuery( document ).ready( function( $ ) {
	var i18n = pluginHubAjax.i18n;
	var modalTrigger = null;

	function isActionBusy( control ) {
		return 'true' === control.attr( 'aria-disabled' ) || control.is( ':disabled' );
	}

	function setActionBusy( control, busy, text ) {
		control
			.toggleClass( 'is-busy', busy )
			.attr( 'aria-busy', busy ? 'true' : 'false' );

		if ( control.is( 'button, input, select' ) ) {
			control.prop( 'disabled', busy );
		} else if ( busy ) {
			control.attr( 'aria-disabled', 'true' );
		} else {
			control.removeAttr( 'aria-disabled' );
		}

		if ( text ) {
			control.text( text );
		}
	}

	// =========================================================================
	// Install
	// =========================================================================

	$( document ).on( 'click', '.install-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) ) {
			return;
		}
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
	// Update (opens the changelog first)
	// =========================================================================

	$( document ).on( 'click', '.update-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) ) {
			return;
		}
		openUpdateModal( button );
	} );

	function openUpdateModal( button ) {
		var repo    = button.data( 'repo' );
		var version = button.data( 'version' );
		var current = button.data( 'current-version' );

		$( '#plugin-hub-modal-title' ).text( i18n.update_modal_title.replace( '%s', repo ) );
		$( '#plugin-hub-modal-content' ).html( '<p>' + i18n.loading_changelog + '</p>' );

		var footer = $( '#plugin-hub-modal-footer' ).empty().prop( 'hidden', false );
		$( '<button type="button" class="button button-primary">' )
			.text( i18n.update_to + ' ' + version )
			.on( 'click', function() {
				closeModal( true );
				updatePlugin( button, repo, version, false );
			} )
			.appendTo( footer );
		$( '<button type="button" class="button">' )
			.text( i18n.cancel )
			.on( 'click', function() { closeModal(); } )
			.appendTo( footer );

		openModal();

		if ( ! current ) {
			$( '#plugin-hub-modal-content' ).html( '<p>' + i18n.no_changelog + '</p>' );
			return;
		}

		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: {
				action:          'get_changelog',
				nonce:           pluginHubAjax.nonce,
				repo:            repo,
				current_version: current,
				new_version:     version,
			},
			success: function( response ) {
				$( '#plugin-hub-modal-content' ).html(
					response.success && response.data ? response.data : '<p>' + i18n.no_changelog + '</p>'
				);
			},
			error: function() {
				$( '#plugin-hub-modal-content' ).text( i18n.error_occurred );
			},
		} );
	}

	function updatePlugin( button, repo, version, isRollback ) {
		var processingText = isRollback ? i18n.rolling_back : i18n.updating;
		var successText    = isRollback ? i18n.rolled_back  : i18n.updated;
		var failText       = isRollback ? i18n.rollback_failed : i18n.update_failed;

		setActionBusy( button, true, processingText );
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
					setTimeout( function() { verifyUpdate( repo, version, button, failText ); }, 500 );
				} else {
					setActionBusy( button, false, failText );
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				setActionBusy( button, false, failText );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	}

	function verifyUpdate( repo, version, button, failText ) {
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
				} else {
					setActionBusy( button, false, failText );
				}
			},
			error: function() {
				setActionBusy( button, false, failText );
				showMessage( i18n.verify_error, 'error' );
			},
		} );
	}

	// =========================================================================
	// Activate / Deactivate (with dependency warnings and network scope)
	// =========================================================================

	// Returns false when the user declines the dependents warning.
	function confirmDependents( button ) {
		var dependents = button.data( 'dependents' );
		return ! dependents || window.confirm( i18n.dependents_confirm.replace( '%s', dependents ) );
	}

	function scopeData( button, data ) {
		var extra = {};
		if ( button.data( 'network' ) ) {
			extra.network = 1;
		}
		if ( button.data( 'dependents' ) ) {
			extra.force = 1;
		}
		return $.extend( data, extra );
	}

	$( document ).on( 'click', '.activate-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) ) {
			return;
		}
		performAction( 'activate_github_plugin', button, i18n.activating, i18n.activated, i18n.activation_failed, scopeData( button, { repo: button.data( 'repo' ) } ) );
	} );

	$( document ).on( 'click', '.deactivate-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! confirmDependents( button ) ) {
			return;
		}
		performAction( 'deactivate_github_plugin', button, i18n.deactivating, i18n.deactivated, i18n.deactivation_failed, scopeData( button, { repo: button.data( 'repo' ) } ) );
	} );

	// =========================================================================
	// Delete
	// =========================================================================

	$( document ).on( 'click', '.delete-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.delete_confirm ) || ! confirmDependents( button ) ) {
			return;
		}
		performAction( 'delete_github_plugin', button, i18n.deleting, i18n.deleted, i18n.delete_failed, scopeData( button, { repo: button.data( 'repo' ) } ) );
	} );

	// =========================================================================
	// Changelog modal
	// =========================================================================

	$( document ).on( 'click', '.open-changelog', function( e ) {
		e.preventDefault();
		var link = $( this );
		if ( isActionBusy( link ) ) {
			return;
		}
		setActionBusy( link, true );

		$( '#plugin-hub-modal-title' ).text( i18n.loading_changelog );
		$( '#plugin-hub-modal-content' ).empty();
		$( '#plugin-hub-modal-footer' ).empty().prop( 'hidden', true );
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
			complete: function() {
				setActionBusy( link, false );
			},
		} );
	} );

	$( '#plugin-hub-modal-close, #plugin-hub-modal-overlay' ).on( 'click', function() { closeModal(); } );
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

	function closeModal( skipFocus ) {
		$( '#plugin-hub-modal-overlay, #plugin-hub-modal' ).fadeOut( 150, function() {
			$( this ).prop( 'hidden', true );
		} );
		if ( modalTrigger && ! skipFocus ) {
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
		if ( isActionBusy( link ) ) {
			return;
		}

		if ( listEl.is( ':visible' ) ) {
			listEl.hide();
			return;
		}

		if ( listEl.data( 'loaded' ) ) {
			listEl.show();
			return;
		}

		setActionBusy( link, true, i18n.loading_versions );

		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'get_plugin_releases', nonce: pluginHubAjax.nonce, repo: repo },
			success: function( response ) {
				setActionBusy( link, false, i18n.versions );

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
				setActionBusy( link, false, i18n.versions );
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
		setActionBusy( checkbox, true );

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
			complete: function() {
				setActionBusy( checkbox, false );
			},
		} );
	} );

	// =========================================================================
	// MU plugins
	// =========================================================================

	$( document ).on( 'click', '.mu-install-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.mu_install_confirm ) ) {
			return;
		}
		performAction( 'install_mu_plugin', button, i18n.mu_installing, i18n.installed, i18n.install_failed, { file: button.data( 'file' ) } );
	} );

	$( document ).on( 'click', '.mu-update-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) ) {
			return;
		}
		performAction( 'update_mu_plugin', button, i18n.updating, i18n.updated, i18n.update_failed, { file: button.data( 'file' ) } );
	} );

	$( document ).on( 'click', '.mu-rollback-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.mu_rollback_confirm ) ) {
			return;
		}
		performAction( 'rollback_mu_plugin', button, i18n.mu_rolling_back, i18n.mu_restored, i18n.update_failed, { file: button.data( 'file' ) } );
	} );

	$( document ).on( 'click', '#mu-update-all', function() {
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.mu_update_all_confirm ) ) {
			return;
		}
		performAction( 'update_all_mu_plugins', button, i18n.updating, i18n.updated, i18n.update_failed, {} );
	} );

	$( document ).on( 'change', '.mu-autoupdate-toggle', function() {
		var checkbox = $( this );
		var enabled  = checkbox.is( ':checked' );
		setActionBusy( checkbox, true );

		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'save_mu_autoupdate', nonce: pluginHubAjax.nonce, file: checkbox.data( 'file' ), enabled: enabled ? 1 : 0 },
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
			complete: function() {
				setActionBusy( checkbox, false );
			},
		} );
	} );

	// =========================================================================
	// Version pinning
	// =========================================================================

	function pinOptions( version, current ) {
		var parts   = String( version ).split( '.' );
		var options = [ { value: '', label: i18n.pin_none } ];

		options.push( { value: version, label: i18n.pin_hold.replace( '%s', version ) } );
		if ( parts.length > 0 && /^\d+$/.test( parts[ 0 ] ) ) {
			options.push( { value: parts[ 0 ] + '.x', label: i18n.pin_stay.replace( '%s', parts[ 0 ] + '.x' ) } );
		}
		if ( parts.length > 1 && /^\d+$/.test( parts[ 0 ] ) && /^\d+$/.test( parts[ 1 ] ) ) {
			options.push( { value: parts[ 0 ] + '.' + parts[ 1 ] + '.x', label: i18n.pin_stay.replace( '%s', parts[ 0 ] + '.' + parts[ 1 ] + '.x' ) } );
		}
		if ( current && ! options.some( function( option ) { return option.value === current; } ) ) {
			options.push( { value: current, label: i18n.pin_stay.replace( '%s', current ) } );
		}

		return options;
	}

	$( document ).on( 'click', '.pin-toggle', function( e ) {
		e.preventDefault();
		var link  = $( this );
		var cell  = link.closest( 'td, th' );
		var panel = cell.find( '.plugin-hub-pin-panel' );

		if ( panel.length ) {
			panel.remove();
			return;
		}

		var select = $( '<select>' ).attr( 'aria-label', i18n.pin_label );
		$.each( pinOptions( String( link.data( 'version' ) ), String( link.data( 'pin' ) || '' ) ), function( i, option ) {
			select.append( $( '<option>' ).val( option.value ).text( option.label ) );
		} );
		select.val( String( link.data( 'pin' ) || '' ) );

		var save = $( '<button type="button" class="button button-primary">' ).text( i18n.save );
		var cancel = $( '<button type="button" class="button button-link">' ).text( i18n.cancel );
		panel = $( '<div class="plugin-hub-pin-panel">' ).append( select, ' ', save, ' ', cancel ).appendTo( cell );

		cancel.on( 'click', function() { panel.remove(); } );
		save.on( 'click', function() {
			setActionBusy( save, true );
			$.ajax( {
				url:  pluginHubAjax.ajax_url,
				type: 'POST',
				data: { action: 'save_plugin_pin', nonce: pluginHubAjax.nonce, repo: link.data( 'repo' ), pin: select.val() },
				success: function( response ) {
					if ( response.success ) {
						showMessage( i18n.pin_saved, 'success' );
						setTimeout( function() { location.reload(); }, 600 );
					} else {
						setActionBusy( save, false );
						showMessage( response.data, 'error' );
					}
				},
				error: function() {
					setActionBusy( save, false );
					showMessage( i18n.error_occurred, 'error' );
				},
			} );
		} );
	} );

	// =========================================================================
	// Backups and activity entries
	// =========================================================================

	function postIds( action, ids, button ) {
		if ( button ) {
			setActionBusy( button, true );
		}
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: action, nonce: pluginHubAjax.nonce, ids: ids },
			success: function( response ) {
				showMessage( response.data, response.success ? 'success' : 'error' );
				if ( response.success ) {
					setTimeout( function() { location.reload(); }, 800 );
				} else if ( button ) {
					setActionBusy( button, false );
				}
			},
			error: function() {
				showMessage( i18n.error_occurred, 'error' );
				if ( button ) {
					setActionBusy( button, false );
				}
			},
		} );
	}

	$( document ).on( 'click', '.restore-backup-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.backup_restore_confirm ) ) {
			return;
		}
		performAction( 'restore_backup', button, i18n.restoring, i18n.done, i18n.failed, { id: button.data( 'id' ) } );
	} );

	$( document ).on( 'click', '.delete-backup-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.backup_delete_confirm ) ) {
			return;
		}
		postIds( 'delete_backups', [ button.data( 'id' ) ], button );
	} );

	$( document ).on( 'click', '.delete-activity-now', function( e ) {
		e.preventDefault();
		var button = $( this );
		if ( isActionBusy( button ) || ! window.confirm( i18n.log_delete_confirm ) ) {
			return;
		}
		postIds( 'delete_activity_entries', [ button.data( 'id' ) ], button );
	} );

	// =========================================================================
	// Settings, test notification, import
	// =========================================================================

	function inlineStatus( target, message, ok ) {
		target.text( ( ok ? '✓ ' : '✗ ' ) + message ).css( 'color', ok ? '#46b450' : '#dc3232' );
	}

	$( '#plugin-hub-settings-form' ).on( 'submit', function( e ) {
		e.preventDefault();
		var form   = $( this );
		var button = $( '#plugin-hub-save-settings' );
		var status = $( '#plugin-hub-settings-status' );
		var label  = button.text();

		setActionBusy( button, true, i18n.saving );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: form.serialize() + '&action=save_hub_settings&nonce=' + encodeURIComponent( pluginHubAjax.nonce ),
			success: function( response ) {
				inlineStatus( status, response.success ? i18n.settings_saved : response.data, response.success );
			},
			error: function() {
				inlineStatus( status, i18n.error_occurred, false );
			},
			complete: function() {
				setActionBusy( button, false, label );
			},
		} );
	} );

	$( '#plugin-hub-test-notification' ).on( 'click', function() {
		var button = $( this );
		var status = $( '#plugin-hub-test-status' );
		var label  = button.text();

		setActionBusy( button, true, i18n.sending );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'send_test_notification', nonce: pluginHubAjax.nonce },
			success: function( response ) {
				inlineStatus( status, response.data, response.success );
			},
			error: function() {
				inlineStatus( status, i18n.error_occurred, false );
			},
			complete: function() {
				setActionBusy( button, false, label );
			},
		} );
	} );

	$( '#plugin-hub-import' ).on( 'click', function() {
		var button = $( this );
		var input  = $( '#plugin-hub-import-file' )[ 0 ];
		var result = $( '#plugin-hub-import-result' );

		if ( ! input.files || ! input.files.length ) {
			inlineStatus( result, i18n.import_empty, false );
			return;
		}

		var reader = new window.FileReader();
		reader.onload = function() {
			setActionBusy( button, true );
			$.ajax( {
				url:  pluginHubAjax.ajax_url,
				type: 'POST',
				data: { action: 'import_hub_config', nonce: pluginHubAjax.nonce, config: reader.result },
				success: function( response ) {
					if ( ! response.success ) {
						inlineStatus( result, response.data, false );
						return;
					}
					renderImportResult( result, response.data );
				},
				error: function() {
					inlineStatus( result, i18n.error_occurred, false );
				},
				complete: function() {
					setActionBusy( button, false );
				},
			} );
		};
		reader.readAsText( input.files[ 0 ] );
	} );

	function renderImportResult( result, data ) {
		var missing   = data.missing || [];
		var muMissing = data.mu_missing || [];
		var box       = $( '<div class="notice notice-success inline">' ).append( $( '<p>' ).text( data.message ) );

		if ( missing.length || muMissing.length ) {
			var list = $( '<ul class="ul-disc">' );
			$.each( missing, function( i, item ) {
				list.append( $( '<li>' ).text( item.repo + ' ' + item.version ) );
			} );
			$.each( muMissing, function( i, file ) {
				list.append( $( '<li>' ).text( 'MU: ' + file ) );
			} );
			var install = $( '<button type="button" class="button button-primary">' ).text( i18n.install_missing );
			box.append( $( '<p>' ).text( i18n.import_missing ), list, $( '<p>' ).append( install ) );

			install.on( 'click', function() {
				var queue = [];
				$.each( missing, function( i, item ) {
					queue.push( { action: 'install_github_plugin', data: { repo: item.repo, version: item.version } } );
					if ( item.active ) {
						queue.push( { action: 'activate_github_plugin', data: { repo: item.repo }, after: item.repo } );
					}
				} );
				$.each( muMissing, function( i, file ) {
					queue.push( { action: 'install_mu_plugin', data: { file: file } } );
				} );
				setActionBusy( install, true );
				runQueue( queue, function( job, done ) {
					postAction( job.action, job.data, done );
				} );
			} );
		}

		result.empty().append( box );
	}

	// =========================================================================
	// GitHub token
	// =========================================================================

	$( '#save-github-token' ).on( 'click', function() {
		var button = $( this );
		var token  = $( '#github-token' ).val();
		setActionBusy( button, true, i18n.saving );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'save_github_token', nonce: pluginHubAjax.nonce, token: token },
			success: function( response ) {
				setActionBusy( button, false, i18n.save_token );
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
				setActionBusy( button, false, i18n.save_token );
				$( '#token-status' ).text( '✗ ' + i18n.error_occurred ).css( 'color', '#dc3232' );
			},
		} );
	} );

	$( '#remove-github-token' ).on( 'click', function() {
		var button = $( this );
		setActionBusy( button, true );
		$.ajax( {
			url: pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'save_github_token', nonce: pluginHubAjax.nonce, clear: 1 },
			success: function( response ) {
				if ( response.success ) {
					location.reload();
					return;
				}
				setActionBusy( button, false );
				showMessage( response.data, 'error' );
			},
			error: function() {
				setActionBusy( button, false );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	} );

	// =========================================================================
	// Beta toggle
	// =========================================================================

	$( '#show-beta-plugins' ).on( 'change', function() {
		var checkbox = $( this );
		setActionBusy( checkbox, true );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'toggle_beta_plugins', nonce: pluginHubAjax.nonce, show_beta: checkbox.is( ':checked' ) },
			success: function( response ) {
				if ( response.success ) {
					location.reload();
				} else {
					showMessage( response.data, 'error' );
				}
			},
			error: function() { showMessage( i18n.error_occurred, 'error' ); },
			complete: function() { setActionBusy( checkbox, false ); },
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
		setActionBusy( button, true, i18n.clearing );
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: { action: 'clear_activity_log', nonce: pluginHubAjax.nonce },
			success: function( response ) {
				setActionBusy( button, false, i18n.clear_log );
				if ( response.success ) {
					location.reload();
				} else {
					showMessage( response.data, 'error' );
				}
			},
			error: function() {
				setActionBusy( button, false, i18n.clear_log );
				showMessage( i18n.error_occurred, 'error' );
			},
		} );
	} );

	// =========================================================================
	// Bulk actions (WP_List_Table form)
	// =========================================================================

	function postAction( action, data, done ) {
		$.ajax( {
			url:  pluginHubAjax.ajax_url,
			type: 'POST',
			data: $.extend( { action: action, nonce: pluginHubAjax.nonce }, data ),
			success: function( response ) {
				done( response.success ? 'ok' : 'fail', response.data );
			},
			error: function() {
				done( 'fail', i18n.error_occurred );
			},
		} );
	}

	// Run jobs one after another with a progress notice. worker( job, done( 'ok'|'fail'|'skip' ) ).
	function runQueue( jobs, worker ) {
		var total     = jobs.length;
		var processed = 0;
		var success   = 0;
		var failed    = 0;

		$( '.tablenav .button, .tablenav select' ).prop( 'disabled', true );
		var status = $( '<div id="bulk-action-status" class="notice notice-info" role="status" aria-live="polite"><p></p></div>' );
		status.find( 'p' ).text( i18n.bulk_status.replace( '%1$s', 0 ).replace( '%2$s', total ) );
		$( '.plugin-hub-main' ).prepend( status );

		function next() {
			if ( processed >= total ) {
				status
					.removeClass( 'notice-info' )
					.addClass( failed > 0 ? 'notice-warning' : 'notice-success' )
					.find( 'p' )
					.text( i18n.bulk_result.replace( '%1$s', success ).replace( '%2$s', total ) );
				setTimeout( function() { location.reload(); }, 2000 );
				return;
			}

			worker( jobs[ processed ], function( result, message ) {
				processed++;
				if ( 'ok' === result ) {
					success++;
				} else if ( 'fail' === result ) {
					failed++;
					if ( message ) {
						showMessage( message, 'error' );
					}
				}
				status.find( 'p' ).text( i18n.bulk_status.replace( '%1$s', processed ).replace( '%2$s', total ) );
				next();
			} );
		}

		next();
	}

	function selectedValues( form ) {
		return form.find( 'tbody input[name="checked[]"]:checked' ).map( function() { return $( this ).val(); } ).get();
	}

	// Rows of the plugin table keyed by the checkbox value.
	function rowFor( form, value ) {
		return form.find( 'tbody input[name="checked[]"]' ).filter( function() { return $( this ).val() === value; } ).closest( 'tr' );
	}

	function pluginJobs( form, action, selected ) {
		var map = {
			install:            { selector: '.install-now', ajax: 'install_github_plugin' },
			activate:           { selector: '.activate-now:not(.network-activate-now)', ajax: 'activate_github_plugin' },
			deactivate:         { selector: '.deactivate-now:not([data-network])', ajax: 'deactivate_github_plugin' },
			network_activate:   { selector: '.network-activate-now', ajax: 'activate_github_plugin', network: 1 },
			network_deactivate: { selector: '.deactivate-now[data-network]', ajax: 'deactivate_github_plugin', network: 1 },
			update:             { selector: '.update-now', ajax: 'update_github_plugin' },
			delete:             { selector: '.delete-now', ajax: 'delete_github_plugin' },
		};
		var jobs = [];

		$.each( selected, function( i, repo ) {
			var row = rowFor( form, repo );
			var data;
			var button;

			if ( 'enable_autoupdate' === action || 'disable_autoupdate' === action ) {
				jobs.push( { skip: ! row.find( '.autoupdate-toggle' ).length, ajax: 'save_autoupdate_setting', data: { repo: repo, enabled: 'enable_autoupdate' === action ? 1 : 0 } } );
				return;
			}

			button = row.find( map[ action ].selector ).first();
			data   = { repo: repo };
			if ( button.length ) {
				if ( button.data( 'version' ) ) {
					data.version = button.data( 'version' );
				}
				if ( map[ action ].network ) {
					data.network = 1;
				}
				if ( button.data( 'dependents' ) ) {
					data.force = 1;
				}
			}
			jobs.push( { skip: ! button.length, ajax: map[ action ].ajax, data: data, dependents: button.data( 'dependents' ) || '' } );
		} );

		return jobs;
	}

	function muJobs( form, action, selected ) {
		var jobs = [];

		$.each( selected, function( i, file ) {
			var row = rowFor( form, file );

			switch ( action ) {
				case 'install':
					jobs.push( { skip: false, ajax: 'install_mu_plugin', data: { file: file } } );
					break;
				case 'update':
					jobs.push( { skip: ! row.find( '.mu-update-now' ).length, ajax: 'update_mu_plugin', data: { file: file } } );
					break;
				case 'rollback':
					jobs.push( { skip: ! row.find( '.mu-rollback-now' ).length, ajax: 'rollback_mu_plugin', data: { file: file } } );
					break;
				default:
					jobs.push( { skip: ! row.find( '.mu-autoupdate-toggle' ).length, ajax: 'save_mu_autoupdate', data: { file: file, enabled: 'enable_autoupdate' === action ? 1 : 0 } } );
			}
		} );

		return jobs;
	}

	$( '#plugin-hub-form' ).on( 'submit', function( e ) {
		var form      = $( this );
		var table     = form.data( 'table' );
		var submitter = e.originalEvent && e.originalEvent.submitter ? $( e.originalEvent.submitter ) : $();

		// Search and filter submits keep their normal GET behaviour.
		if ( ! submitter.is( '#doaction, #doaction2' ) ) {
			return;
		}

		e.preventDefault();

		var action   = submitter.is( '#doaction2' ) ? form.find( 'select[name="action2"]' ).val() : form.find( 'select[name="action"]' ).val();
		var selected = selectedValues( form );

		if ( ! action || '-1' === action || 0 === selected.length ) {
			window.alert( i18n.select_action_plugin );
			return;
		}

		if ( 'activity' === table ) {
			if ( window.confirm( i18n.log_delete_confirm ) ) {
				postIds( 'delete_activity_entries', selected );
			}
			return;
		}
		if ( 'backups' === table ) {
			if ( window.confirm( i18n.backup_delete_confirm ) ) {
				postIds( 'delete_backups', selected );
			}
			return;
		}

		var jobs;
		if ( 'plugins' === table ) {
			jobs = pluginJobs( form, action, selected );
			if ( 'delete' === action && ! window.confirm( i18n.bulk_delete_confirm ) ) {
				return;
			}
			var warned = jobs.filter( function( job ) { return ! job.skip && job.dependents && 'deactivate_github_plugin' === job.ajax; } );
			if ( warned.length && ! window.confirm( i18n.dependents_confirm.replace( '%s', warned.map( function( job ) { return job.dependents; } ).join( ', ' ) ) ) ) {
				return;
			}
		} else {
			if ( 'install' === action && ! window.confirm( i18n.mu_install_confirm ) ) {
				return;
			}
			if ( 'rollback' === action && ! window.confirm( i18n.mu_rollback_confirm ) ) {
				return;
			}
			jobs = muJobs( form, action, selected );
		}

		runQueue( jobs, function( job, done ) {
			if ( job.skip ) {
				done( 'skip' );
				return;
			}
			postAction( job.ajax, job.data, done );
		} );
	} );

	function performAction( action, button, processingText, successText, failText, data, callback ) {
		setActionBusy( button, true, processingText );
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
					setActionBusy( button, false, failText );
					showMessage( response.data, 'error' );
					if ( typeof callback === 'function' ) {
						callback( false );
					}
				}
			},
			error: function() {
				setActionBusy( button, false, failText );
				showMessage( i18n.error_occurred, 'error' );
				if ( typeof callback === 'function' ) {
					callback( false );
				}
			},
		} );
	}

	// =========================================================================
	// Utilities
	// =========================================================================

	function showMessage( message, type ) {
		var messageDiv = $( '#plugin-hub-messages' );
		if ( ! messageDiv.length ) {
			messageDiv = $( '<div id="plugin-hub-messages"></div>' ).prependTo( '.plugin-hub-main' );
		}
		messageDiv
			.attr( {
				role: 'error' === type ? 'alert' : 'status',
				'aria-live': 'error' === type ? 'assertive' : 'polite',
			} )
			.removeClass( 'notice-success notice-error notice-warning notice-info' )
			.addClass( 'notice notice-' + type )
			.empty()
			.append( $( '<p>' ).text( message ) )
			.fadeIn();
		setTimeout( function() { messageDiv.fadeOut(); }, 5000 );
	}
} );
