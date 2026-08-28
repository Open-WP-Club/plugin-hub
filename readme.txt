=== Plugin Hub ===
Contributors: gkanev
Tags: plugins, github, installer, plugin-manager, open-wp-club
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Manage WordPress plugins directly from GitHub repositories with easy installation, updates, and management.

== Description ==

Plugin Hub is a powerful WordPress plugin that enables you to manage and install plugins directly from GitHub repositories, with a focus on the Open-WP-Club organization. It provides a seamless interface for browsing, installing, updating, and managing GitHub-hosted plugins without leaving your WordPress admin area.

= Features =

* **Browse GitHub Repositories**: View all available plugins from the Open-WP-Club organization
* **Easy Installation**: Install plugins directly from GitHub with one click
* **Automatic Updates**: Check for and install updates from GitHub releases
* **Plugin Management**: Activate, deactivate, and delete plugins from a centralized interface
* **Beta Plugin Support**: Option to show or hide beta versions (< 1.0.0)
* **Bulk Actions**: Perform actions on multiple plugins simultaneously
* **Plugin Filtering**: Filter plugins by status (active, inactive, updates available, beta)
* **Cache Management**: Built-in caching for improved performance with manual refresh option
* **Secure**: Implements WordPress security best practices with nonce verification and capability checks
* **Rollback**: Install a previous published GitHub release when needed
* **Activity Log**: Review manual and automatic plugin-management actions
* **GitHub Authentication**: Optionally use a token for higher API limits

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher

== Installation ==

1. Upload the `plugin-hub` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to the 'Plugin Hub' page under the Plugins menu
4. Start managing your GitHub-hosted plugins!

== Frequently Asked Questions ==

= What GitHub organization does this plugin support? =

Plugin Hub is configured to work with the Open-WP-Club organization by default. The organization can be modified in the source code if needed.

= How does plugin updating work? =

The plugin checks for updates by comparing version numbers from GitHub releases with installed versions. Updates are pulled from the latest GitHub release tags.

= Can I use this with private repositories? =

Plugin Hub installs packages from public GitHub releases. An optional GitHub token increases API rate limits, but private release assets are not currently supported.

= How often is the plugin list cached? =

The plugin list is cached for 24 hours by default to improve performance. You can manually refresh the cache using the "Refresh Plugin List" button.

= What are beta plugins? =

Beta plugins are those with version numbers less than 1.0.0. You can toggle their visibility in the plugin settings.

== Screenshots ==

1. Main Plugin Hub interface showing available plugins
2. Plugin management with filters and bulk actions
3. Settings panel with beta plugin toggle

== Changelog ==

= 1.4.0 =
* Tested with WordPress 7.1 and updated custom admin list-table markup
* Fixed manual updates, automatic updates, and rollbacks to use the WordPress upgrader correctly
* Added exact post-update version verification and stable GitHub archive directory handling
* Validated catalog entries, repository URLs, requested releases, and download hosts
* Added last-known-good catalog fallback and release URL caching
* Added direct versioned release downloads before the GitHub REST API fallback
* Prevented stored GitHub tokens from being exposed in page markup
* Hardened release rendering and improved changelog modal accessibility
* Synchronized plugin metadata and improved uninstall cleanup

= 1.3.0 =
* Added GitHub token and API rate-limit support
* Added changelog and release history views
* Added rollback, per-plugin automatic updates, e-mail reports, and activity logging

= 1.1.0 =
* Added PHP 8.0 namespaces for modern code organization
* Improved security with proper escaping and sanitization
* Added comprehensive PHPDoc documentation
* Updated to WordPress Coding Standards
* Added uninstall.php for proper cleanup
* Improved internationalization support
* Better error handling and logging
* Updated minimum requirements (PHP 8.0, WordPress 6.0)
* Enhanced translatability with proper text domain usage
* Security improvements in AJAX handlers

= 1.0.2 =
* Bug fixes and improvements
* Better error handling

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.4.0 =
Recommended compatibility and reliability update for WordPress 7.1.

= 1.1.0 =
This version requires PHP 8.0 or higher and WordPress 6.0 or higher. Please ensure your server meets these requirements before upgrading.

== Development ==

Development happens on GitHub. Contributions and bug reports are welcome!

Repository: https://github.com/Open-WP-Club/plugin-hub

== Privacy Policy ==

Plugin Hub does not collect or store any personal data. It only communicates with GitHub's public API to fetch plugin information.
