# Plugin Hub

Plugin Hub installs, updates, and manages WordPress plugins published by Open WP Club and distributed through GitHub.

## Features

- Browse the curated Open WP Club plugin catalog
- Install, update, activate, and deactivate plugins directly from the WordPress admin
- Bulk actions for managing multiple plugins at once
- Cache plugin information for improved performance
- Display beta plugins (optional)
- Refresh plugin list manually
- Roll back to a previous published release
- Opt selected plugins into daily automatic updates
- Review an activity log of plugin-management actions
- Use an optional GitHub token for higher API rate limits
- Resolve standard versioned release ZIPs directly, without spending GitHub REST API quota

## Installation

1. Download the plugin zip file or clone the repository into your WordPress plugins directory.
2. Activate the plugin through the WordPress admin interface.

## Usage

1. Navigate to the "Plugin Hub" page in your WordPress admin menu.
2. You'll see a list of available plugins from the Open-WP-Club organization.
3. Use the action buttons next to each plugin to install, update, activate, or deactivate as needed.
4. You can also perform bulk actions by selecting multiple plugins and choosing an action from the dropdown menu.
5. To refresh the plugin list, click the "Refresh Plugin List" button at the top of the page.

## Configuration

- To show or hide beta plugins, use the checkbox in the sidebar of the Plugin Hub page.
- The plugin list is cached for 24 hours and falls back to the last validated catalog if GitHub is temporarily unavailable.
- For production, the GitHub token can be managed outside the database by defining `PLUGIN_HUB_GITHUB_TOKEN` in `wp-config.php`. The constant takes precedence over the admin setting.
- Daily update reports are only sent when the set of updated or available plugins changes.
- Requires WordPress 6.0 or later (tested through 7.1) and PHP 8.0 or later.

## Development

To contribute to this plugin or modify it for your needs:

1. Fork the repository.
2. Create a new branch for your feature or bug fix.
3. Make your changes and test thoroughly.
4. Create a pull request with a clear description of your changes.

## License

This project is licensed under the GPL2 License.

## Support

For support or feature requests, please open an issue on the GitHub repository.
