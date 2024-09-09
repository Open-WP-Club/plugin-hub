// plugin-hub-script.js

jQuery(document).ready(function ($) {
  // Install plugin
  $(".install-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    installPlugin(button);
  });

  // Update plugin
  $(".update-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    updatePlugin(button);
  });

  // Activate plugin
  $(".activate-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    activatePlugin(button);
  });

  // Deactivate plugin
  $(".deactivate-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    deactivatePlugin(button);
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
        bulkInstall(selectedPlugins);
        break;
      case "activate":
        bulkActivate(selectedPlugins);
        break;
      case "deactivate":
        bulkDeactivate(selectedPlugins);
        break;
      case "update":
        bulkUpdate(selectedPlugins);
        break;
    }
  });

  function installPlugin(button) {
    var repo = button.data("repo");
    var url = button.data("url");
    performAction(
      "install_github_plugin",
      { repo: repo, url: url },
      button,
      "Installing...",
      "Installed",
      "Install Failed"
    );
  }

  function updatePlugin(button) {
    var repo = button.data("repo");
    var url = button.data("url");
    performAction(
      "update_github_plugin",
      { repo: repo, url: url },
      button,
      "Updating...",
      "Updated",
      "Update Failed"
    );
  }

  function activatePlugin(button) {
    var repo = button.data("repo");
    performAction(
      "activate_github_plugin",
      { repo: repo },
      button,
      "Activating...",
      "Activated",
      "Activation Failed"
    );
  }

  function deactivatePlugin(button) {
    var repo = button.data("repo");
    performAction(
      "deactivate_github_plugin",
      { repo: repo },
      button,
      "Deactivating...",
      "Deactivated",
      "Deactivation Failed"
    );
  }

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
          alert("Failed to update setting: " + response.data);
        }
      },
      error: function () {
        alert("An error occurred. Please try again.");
      },
    });
  });

  function performAction(
    action,
    data,
    button,
    processingText,
    successText,
    failText
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

  function bulkInstall(plugins) {
    bulkAction("install_github_plugin", plugins);
  }

  function bulkActivate(plugins) {
    bulkAction("activate_github_plugin", plugins);
  }

  function bulkDeactivate(plugins) {
    bulkAction("deactivate_github_plugin", plugins);
  }

  function bulkUpdate(plugins) {
    bulkAction("update_github_plugin", plugins);
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
          .find(".row-actions span a");
        var url = button.data("url");

        performAction(
          action,
          { repo: plugin, url: url },
          button,
          "Processing...",
          "Processed",
          "Failed",
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

  function performAction(
    action,
    data,
    button,
    processingText,
    successText,
    failText,
    callback
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
          if (callback) callback(true);
        } else {
          button.text(failText);
          showMessage(response.data, "error");
          if (callback) callback(false);
        }
      },
      error: function () {
        button.text(failText);
        showMessage("An error occurred. Please try again.", "error");
        if (callback) callback(false);
      },
    });
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

  // Install plugin
  $(".install-now").on("click", function (e) {
    e.preventDefault();
    var button = $(this);
    performAction(
      "install_github_plugin",
      { repo: button.data("repo"), url: button.data("url") },
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
      { repo: button.data("repo"), url: button.data("url") },
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
      { repo: button.data("repo") },
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
      { repo: button.data("repo") },
      button,
      "Deactivating...",
      "Deactivated",
      "Deactivation Failed"
    );
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
    }
  });
});