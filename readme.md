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

<!-- OPENWPCLUB_SPONSORS_START -->
## Open-source supporters

These companies help keep Open WP Club plugins free and maintained.

<a href="https://openwpclub.com/sponsors/"><picture><source media="(prefers-color-scheme: dark)" srcset="https://openwpclub.com/sponsorship/supporters-dark.png"><img src="https://openwpclub.com/sponsorship/supporters.png" alt="Open WP Club supporters" width="960"></picture></a>

## Sponsored placements

Clearly labelled promotional campaigns, separate from our supporters.

<a href="https://openwpclub.com/advertise/"><picture><source media="(prefers-color-scheme: dark)" srcset="https://openwpclub.com/sponsorship/repos/plugin-hub/campaigns-dark.png"><img src="https://openwpclub.com/sponsorship/repos/plugin-hub/campaigns.png" alt="Open WP Club sponsored placements" width="960"></picture></a>

[Support our work](https://openwpclub.com/sponsors/) · [Discuss a campaign](https://openwpclub.com/advertise/)
<!-- OPENWPCLUB_SPONSORS_END -->

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

### Supporter recognition and privacy

Plugin Hub receives up to three Partner/Champion open-source supporter credits inside its existing
Open WP Club catalog request to GitHub. The authoritative registry is maintained
at https://openwpclub.com/sponsorship.json. Credits are cached locally and refresh
with the catalog (normally daily). Embedded PNG logos do not contact advertisers
or external image servers, and there are no impression counters, tracking pixels,
referral parameters or additional sponsorship HTTP requests from WordPress.

Thanks appear only on the Plugin Hub page. Use **Hide supporter thanks** to save
your preference across updates, or define `PLUGIN_HUB_HIDE_SUPPORTERS` as `true`
in `wp-config.php` to hide them for the entire site. Scheduled campaigns are not
shown in the WordPress admin; they have separate positions in READMEs and on the
website. Sponsor data never controls installation, updates or access to features.

### Catalog outage handling

When GitHub is unavailable or the catalog is invalid, Plugin Hub uses the last
validated catalog and pauses automatic retries for five minutes. Use **Refresh
Open WP Club catalog** to retry immediately. Uninstall removes both the current
catalog cache and the short retry backoff.
