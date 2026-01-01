# Plugin Hub - Codebase Overview

## What is Plugin Hub?

Plugin Hub is a WordPress plugin that manages and installs plugins directly from GitHub repositories, with a focus on the Open-WP-Club organization. It provides a seamless interface for browsing, installing, updating, and managing GitHub-hosted plugins.

## Current Version

- **Version**: 1.1.0
- **WordPress Compatibility**: 6.0 - 6.9
- **PHP Requirement**: 8.0+
- **License**: GPL-2.0+

## Key Features

1. **GitHub Integration**: Fetches plugin data from a CSV file on GitHub
2. **Plugin Management**: Install, update, activate, deactivate, and delete plugins
3. **Version Control**: Tracks versions via GitHub releases
4. **Beta Support**: Toggle visibility of beta plugins (< 1.0.0)
5. **Caching**: 24-hour cache with manual refresh option
6. **Bulk Actions**: Perform actions on multiple plugins at once
7. **Security**: Nonce verification, capability checks, proper escaping

## Architecture

### Namespace Structure
```
PluginHub\
├── Main    - Orchestrates the plugin, loads dependencies
├── Admin   - Handles admin UI, menu, scripts/styles
└── API     - GitHub API interactions, AJAX handlers
```

### File Structure
```
plugin-hub/
├── plugin-hub.php              # Main plugin file, bootstrap
├── uninstall.php               # Cleanup on uninstall
├── readme.txt                  # WordPress.org format
├── readme.md                   # GitHub format
├── includes/
│   ├── class-main.php          # Main orchestration class
│   ├── class-admin.php         # Admin functionality
│   ├── class-api.php           # GitHub API & AJAX
│   └── admin-display.php       # Admin UI template
├── assets/
│   ├── css/
│   │   └── style.css           # Admin styles
│   └── js/
│       └── script.js           # Admin JavaScript
└── .claude/
    ├── overview.md             # This file
    ├── coding-standards.md     # Coding guidelines
    ├── architecture.md         # Technical architecture
    └── development.md          # Development workflow
```

## Data Flow

1. **Plugin Initialization**:
   - `plugin-hub.php` runs `run_plugin_hub()`
   - Creates new `Main()` instance
   - `Main` loads `Admin` and `API` classes
   - Hooks are registered

2. **Loading Plugin List**:
   - `API->get_org_repos()` fetches CSV from GitHub
   - Data is cached for 24 hours
   - CSV parsed into repository array

3. **Plugin Installation**:
   - User clicks "Install" button
   - AJAX call to `ajax_install_github_plugin()`
   - API fetches release URL from GitHub
   - Uses WordPress `Plugin_Upgrader` to install

4. **Updates**:
   - `check_for_plugin_updates()` hooks into WordPress transient
   - Compares installed versions with CSV versions
   - Adds update info to WordPress update transient

## Key Concepts

### GitHub Integration
- Reads plugin metadata from CSV file: `https://raw.githubusercontent.com/Open-WP-Club/.github/main/plugins.csv`
- CSV format: `name,display_name,description,version,repo_url`
- Uses GitHub Releases API for downloads
- Downloads zipball from release tags

### Caching Strategy
- Transient key: `plugin_hub_csv_cache`
- Expiration: 24 hours (`DAY_IN_SECONDS`)
- Manual refresh available
- Cleared on update operations

### Security Model
- All AJAX handlers verify nonce: `check_ajax_referer()`
- Capability checks: `current_user_can()`
- Input sanitization: `sanitize_text_field()`, `wp_unslash()`
- Output escaping: `esc_html()`, `esc_attr()`, `esc_url()`

## Database Usage

### Options
- `plugin_hub_github_plugins` - Stores installed GitHub plugin data
- `plugin_hub_show_beta` - Boolean for beta plugin visibility
- `plugin_hub_disabled_{repo_name}` - Individual plugin disable flags

### Transients
- `plugin_hub_csv_cache` - Cached repository data (24h)

## External Dependencies

- **GitHub API**: For releases and download URLs
- **WordPress Core**: `Plugin_Upgrader`, `WP_Ajax_Upgrader_Skin`
- **CSV Source**: Open-WP-Club GitHub organization

## Future Considerations

1. **Authentication**: Support for private repositories (GitHub tokens)
2. **Multi-Organization**: Support multiple GitHub organizations
3. **Custom CSV URL**: Make CSV URL configurable
4. **Automatic Updates**: Background update checker
5. **Plugin Dependencies**: Check for required plugins
6. **Rollback**: Install previous versions
7. **Changelog Display**: Show release notes in modal
