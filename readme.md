# Plugin Hub

Plugin Hub is a WordPress plugin that manages and installs plugins directly from GitHub repositories, focusing on the Open-WP-Club organization.

## Features

- List plugins from a specified GitHub organization
- Install, update, activate, and deactivate plugins directly from the WordPress admin
- Bulk actions for managing multiple plugins at once
- Cache plugin information for improved performance
- Display beta plugins (optional)
- Refresh plugin list manually

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
- The plugin list is cached for 24 hours by default. You can modify this duration by changing the `$cache_expiration` value in the `Plugin_Hub_API` class.

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
