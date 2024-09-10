jQuery(document).ready(function ($) {
  // Install plugin
  $(".install-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    performAction(
      "install_github_plugin",
      button,
      "Installing...",
      "Installed",
      "Install Failed"
    );
  });

  // Update plugin
  $(".update-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    performAction(
      "update_github_plugin",
      button,
      "Updating...",
      "Updated",
      "Update Failed"
    );
  });

  // Activate plugin
  $(".activate-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    performAction(
      "activate_github_plugin",
      button,
      "Activating...",
      "Activated",
      "Activation Failed"
    );
  });

  // Deactivate plugin
  $(".deactivate-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    performAction(
      "deactivate_github_plugin",
      button,
      "Deactivating...",
      "Deactivated",
      "Deactivation Failed"
    );
  });

  // Delete plugin
  $(".delete-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    if (confirm("Are you sure you want to delete this plugin?")) {
      performAction(
        "delete_github_plugin",
        button,
        "Deleting...",
        "Deleted",
        "Delete Failed"
      );
    }
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
        // Filter out active plugins
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
        if (
          confirm(
            "Are you sure you want to delete the selected inactive plugins?"
          )
        ) {
          bulkAction("delete_github_plugin", inactivePlugins);
        }
        break;
    }
  });

  function performAction(
    action,
    button,
    processingText,
    successText,
    failText
  ) {
    var repo = button.data("repo");
    var url = button.data("url");
    button.text(processingText);
    $.ajax({
      url: pluginHubAjax.ajax_url,
      type: "POST",
      data: {
        action: action,
        nonce: pluginHubAjax.nonce,
        repo: repo,
        url: url,
      },
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
        var url = button.data("url");

        $.ajax({
          url: pluginHubAjax.ajax_url,
          type: "POST",
          data: {
            action: action,
            nonce: pluginHubAjax.nonce,
            repo: plugin,
            url: url,
          },
          success: function (response) {
            processedPlugins++;
            if (response.success) {
              successCount++;
            } else {
              failCount++;
            }
            updateBulkActionStatus();
            processNextPlugin();
          },
          error: function () {
            processedPlugins++;
            failCount++;
            updateBulkActionStatus();
            processNextPlugin();
          },
        });
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
});
