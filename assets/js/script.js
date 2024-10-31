jQuery(document).ready(function ($) {
  // Install plugin
  $(".install-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    var repo = button.data("repo");
    var version = button.data("version");
    performAction(
      "install_github_plugin",
      button,
      "Installing...",
      "Installed",
      "Install Failed",
      { repo: repo, version: version }
    );
  });

  // Update plugin
  $(".update-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    var repo = button.data("repo");
    var version = button.data("version");
    updatePlugin(button, repo, version);
  });

  function updatePlugin(button, repo, version) {
    button.text("Updating...");
    $.ajax({
      url: pluginHubAjax.ajax_url,
      type: "POST",
      data: {
        action: "update_github_plugin",
        nonce: pluginHubAjax.nonce,
        repo: repo,
        version: version,
      },
      success: function (response) {
        if (response.success) {
          button.text("Updated");
          showMessage(response.data, "success");
          // Verify update after a short delay
          setTimeout(function () {
            verifyUpdate(repo, version);
          }, 2000);
        } else {
          button.text("Update Failed");
          showMessage(response.data, "error");
        }
      },
      error: function () {
        button.text("Update Failed");
        showMessage("An error occurred. Please try again.", "error");
      },
    });
  }

  function verifyUpdate(repo, version) {
    $.ajax({
      url: pluginHubAjax.ajax_url,
      type: "POST",
      data: {
        action: "verify_plugin_update",
        nonce: pluginHubAjax.nonce,
        repo: repo,
        version: version,
      },
      success: function (response) {
        if (response.success) {
          showMessage("Update verified: " + response.data, "success");
          setTimeout(function () {
            location.reload();
          }, 1000);
        } else {
          showMessage("Update verification failed: " + response.data, "error");
        }
      },
      error: function () {
        showMessage(
          "Failed to verify update. Please refresh the page and check the plugin version.",
          "error"
        );
      },
    });
  }

  // Activate plugin
  $(".activate-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    var repo = button.data("repo");
    performAction(
      "activate_github_plugin",
      button,
      "Activating...",
      "Activated",
      "Activation Failed",
      { repo: repo }
    );
  });

  // Deactivate plugin
  $(".deactivate-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    var repo = button.data("repo");
    performAction(
      "deactivate_github_plugin",
      button,
      "Deactivating...",
      "Deactivated",
      "Deactivation Failed",
      { repo: repo }
    );
  });

  // Delete plugin
  $(".delete-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    var repo = button.data("repo");
    performAction(
      "delete_github_plugin",
      button,
      "Deleting...",
      "Deleted",
      "Delete Failed",
      { repo: repo }
    );
  });

  // Beta plugin toggle
  $("#show-beta-plugins").on("change", function () {
    var showBeta = $(this).is(":checked");
    $.ajax({
      url: pluginHubAjax.ajax_url,
      type: "POST",
      data: {
        action: "toggle_beta_plugins",
        nonce: pluginHubAjax.nonce,
        show_beta: showBeta,
      },
      success: function (response) {
        if (response.success) {
          location.reload();
        } else {
          showMessage(response.data, "error");
        }
      },
      error: function () {
        showMessage("An error occurred. Please try again.", "error");
      },
    });
  });

  // Bulk actions
  $("#plugin-hub-form").on("submit", function (e) {
    e.preventDefault();
    var action = $("#bulk-action-selector-top").val();
    var selectedPlugins = $('input[name="checked[]"]:checked')
      .map(function () {
        return $(this).val();
      })
      .get();

    if (action === "-1" || selectedPlugins.length === 0) {
      alert("Please select an action and at least one plugin.");
      return;
    }

    switch (action) {
      case "install":
        bulkAction("install_github_plugin", selectedPlugins);
        break;
      case "activate":
        bulkAction("activate_github_plugin", selectedPlugins);
        break;
      case "deactivate":
        bulkAction("deactivate_github_plugin", selectedPlugins);
        break;
      case "update":
        bulkAction("update_github_plugin", selectedPlugins);
        break;
      case "delete":
        var inactivePlugins = selectedPlugins.filter(function (plugin) {
          return !$('input[name="checked[]"][value="' + plugin + '"]')
            .closest("tr")
            .find(".deactivate-now").length;
        });
        if (inactivePlugins.length === 0) {
          alert(
            "No inactive plugins selected for deletion. Active plugins cannot be deleted."
          );
          return;
        }
        bulkAction("delete_github_plugin", inactivePlugins);
        break;
    }
  });

  function performAction(
    action,
    button,
    processingText,
    successText,
    failText,
    data
  ) {
    button.text(processingText);
    $.ajax({
      url: pluginHubAjax.ajax_url,
      type: "POST",
      data: $.extend(
        {
          action: action,
          nonce: pluginHubAjax.nonce,
        },
        data
      ),
      success: function (response) {
        if (response.success) {
          button.text(successText);
          showMessage(response.data, "success");
          setTimeout(function () {
            location.reload();
          }, 1000);
        } else {
          button.text(failText);
          showMessage(response.data, "error");
        }
      },
      error: function () {
        button.text(failText);
        showMessage("An error occurred. Please try again.", "error");
      },
    });
  }

  function bulkAction(action, plugins) {
    var totalPlugins = plugins.length;
    var processedPlugins = 0;
    var successCount = 0;
    var failCount = 0;

    function processNextPlugin() {
      if (processedPlugins < totalPlugins) {
        var plugin = plugins[processedPlugins];
        var button = $('input[name="checked[]"][value="' + plugin + '"]')
          .closest("tr")
          .find(".plugin-actions a:first");
        var version = button.data("version");

        performAction(
          action,
          button,
          "Processing...",
          "Processed",
          "Failed",
          { repo: plugin, version: version },
          function (success) {
            processedPlugins++;
            if (success) {
              successCount++;
            } else {
              failCount++;
            }
            updateBulkActionStatus();
            processNextPlugin();
          }
        );
      } else {
        showMessage(
          "Bulk action completed. Success: " +
            successCount +
            ", Failed: " +
            failCount,
          "info"
        );
        setTimeout(function () {
          location.reload();
        }, 2000);
      }
    }

    function updateBulkActionStatus() {
      var status = "Processing: " + processedPlugins + "/" + totalPlugins;
      $("#bulk-action-status").text(status);
    }

    $('<div id="bulk-action-status"></div>').insertAfter("#plugin-hub-form");
    processNextPlugin();
  }

  function showMessage(message, type) {
    var messageDiv = $("#plugin-hub-messages");
    if (!messageDiv.length) {
      messageDiv = $('<div id="plugin-hub-messages"></div>').insertBefore(
        ".wp-list-table"
      );
    }
    messageDiv
      .removeClass("notice-success notice-error notice-warning notice-info")
      .addClass("notice notice-" + type)
      .text(message)
      .fadeIn();
    setTimeout(function () {
      messageDiv.fadeOut();
    }, 3000);
  }
  // Plugin search functionality
  $("#plugin-search-input").on("keyup", function () {
    var searchText = $(this).val().toLowerCase();
    $("#the-list tr").each(function () {
      var pluginName = $(this)
        .find(".plugin-title strong")
        .text()
        .toLowerCase();
      var pluginDescription = $(this)
        .find(".plugin-description p")
        .text()
        .toLowerCase();

      if (
        pluginName.indexOf(searchText) > -1 ||
        pluginDescription.indexOf(searchText) > -1
      ) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  });
});
