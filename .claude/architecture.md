# Technical Architecture

## System Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    WordPress Core                        │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                  Plugin Hub Bootstrap                    │
│                  (plugin-hub.php)                        │
│  - Defines constants                                     │
│  - Loads Main class                                      │
│  - Runs plugin                                          │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    PluginHub\Main                        │
│  - Orchestrates plugin execution                         │
│  - Loads dependencies (Admin, API)                       │
│  - Registers hooks                                       │
└─────────────────────────────────────────────────────────┘
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
    ┌──────────────────┐      ┌──────────────────┐
    │  PluginHub\Admin │      │   PluginHub\API  │
    └──────────────────┘      └──────────────────┘
              │                         │
              ▼                         ▼
    ┌──────────────────┐      ┌──────────────────┐
    │   Admin UI       │      │  GitHub API      │
    │   - Menu         │      │  - Fetch repos   │
    │   - Display      │      │  - Downloads     │
    │   - Scripts      │      │  - AJAX handlers │
    └──────────────────┘      └──────────────────┘
              │                         │
              └────────────┬────────────┘
                           ▼
              ┌────────────────────────┐
              │   External Services    │
              │   - GitHub API         │
              │   - GitHub Raw Content │
              └────────────────────────┘
```

## Class Relationships

### PluginHub\Main
**Purpose**: Central orchestrator

**Dependencies**:
- `PluginHub\Admin`
- `PluginHub\API`

**Responsibilities**:
1. Load dependency classes
2. Register admin hooks
3. Register API hooks
4. Provide run() method for future expansion

**Hooks Registered**:
```php
// Admin hooks
add_action( 'admin_menu', [ $admin, 'add_admin_menu' ] );
add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_styles' ] );
add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_scripts' ] );
add_action( 'admin_init', [ $admin, 'handle_refresh_cache' ] );

// API hooks
add_filter( 'pre_set_site_transient_update_plugins', [ $api, 'check_for_plugin_updates' ] );
add_action( 'wp_ajax_install_github_plugin', [ $api, 'ajax_install_github_plugin' ] );
add_action( 'wp_ajax_activate_github_plugin', [ $api, 'ajax_activate_github_plugin' ] );
add_action( 'wp_ajax_deactivate_github_plugin', [ $api, 'ajax_deactivate_github_plugin' ] );
add_action( 'wp_ajax_update_github_plugin', [ $api, 'ajax_update_github_plugin' ] );
add_action( 'wp_ajax_delete_github_plugin', [ $api, 'ajax_delete_github_plugin' ] );
add_action( 'wp_ajax_toggle_beta_plugins', [ $admin, 'ajax_toggle_beta_plugins' ] );
```

### PluginHub\Admin
**Purpose**: Handle all admin UI operations

**Properties**:
- `$organization` - GitHub organization name

**Key Methods**:
```php
add_admin_menu()              // Register admin page
enqueue_styles( $hook )       // Load CSS
enqueue_scripts( $hook )      // Load JS + localize
handle_refresh_cache()        // Manual cache refresh
display_admin_page()          // Render main UI
get_plugin_counts( $repos )   // Calculate filter counts
ajax_toggle_beta_plugins()    // Toggle beta visibility
```

**Template**:
- Uses `admin-display.php` for UI rendering
- Passes `$api`, `$repos`, `$filter`, `$counts` variables

### PluginHub\API
**Purpose**: GitHub integration and plugin management

**Properties**:
```php
$organization      // GitHub org name
$csv_url           // CSV file URL
$github_plugins    // Installed plugin data
$cache_key         // Transient key
$cache_expiration  // Cache duration
```

**Plugin Check Methods**:
```php
is_plugin_installed( $name )      // Check if installed
is_plugin_active( $name )         // Check if active
is_plugin_disabled( $name )       // Check if disabled
get_plugin_file( $name )          // Get plugin file path
is_update_available( $repo, $v )  // Version comparison
get_installed_plugin_version( $name ) // Get version
```

**GitHub Methods**:
```php
get_org_repos()                   // Fetch & cache CSV
parse_csv_content( $csv )         // Parse CSV data
refresh_csv_cache()               // Clear & refetch
get_github_release_download_url() // Get zipball URL
get_latest_github_version()       // Fetch latest release
get_github_changelog()            // Get release notes
```

**AJAX Handlers**:
```php
ajax_install_github_plugin()      // Install from GitHub
ajax_activate_github_plugin()     // Activate plugin
ajax_deactivate_github_plugin()   // Deactivate plugin
ajax_update_github_plugin()       // Update plugin
ajax_delete_github_plugin()       // Delete plugin
ajax_disable_github_plugin()      // Disable via flag
ajax_force_refresh_plugins()      // Manual refresh
ajax_get_changelog()              // Fetch changelog
verify_plugin_update()            // Verify version
```

**WordPress Integration**:
```php
check_for_plugin_updates( $transient ) // Hook into WP updates
force_refresh_plugins()                // Update all from GitHub
```

## Data Flow Diagrams

### Plugin Installation Flow
```
User clicks "Install"
         │
         ▼
JavaScript (script.js)
  - Captures click
  - Sends AJAX request
         │
         ▼
ajax_install_github_plugin()
  - Verify nonce
  - Check capability
  - Sanitize input
         │
         ▼
get_github_release_download_url()
  - Query GitHub API
  - Get release data
  - Extract zipball URL
         │
         ▼
WordPress Plugin_Upgrader
  - Download ZIP
  - Extract files
  - Install plugin
         │
         ▼
Update option
  - Store plugin data
  - Send JSON success
         │
         ▼
JavaScript callback
  - Update UI
  - Show success message
```

### Update Check Flow
```
WordPress Cron
         │
         ▼
pre_set_site_transient_update_plugins
         │
         ▼
check_for_plugin_updates()
  - Get cached repos
         │
         ▼
For each repo:
  - Get plugin file
  - Compare versions
  - If newer available:
      │
      ▼
    Add to transient
      - Set slug
      - Set new_version
      - Set download URL
         │
         ▼
Return modified transient
         │
         ▼
WordPress shows update notification
```

### Cache Management Flow
```
Initial Request
         │
         ▼
get_org_repos()
         │
         ▼
Check transient
  - Exists? ──Yes──> Return cached data
  - No?
         │
         ▼
Fetch CSV from GitHub
         │
         ▼
Parse CSV content
         │
         ▼
Set transient (24h)
         │
         ▼
Return data
```

## Security Architecture

### Defense Layers

1. **Authentication Layer**
   - WordPress user authentication
   - Session management

2. **Authorization Layer**
   - Capability checks: `current_user_can()`
   - Role-based access control

3. **Input Validation Layer**
   - Nonce verification: `check_ajax_referer()`
   - Data sanitization: `sanitize_text_field()`, `wp_unslash()`
   - Type checking: `filter_var()`, `absint()`

4. **Output Escaping Layer**
   - HTML escaping: `esc_html()`
   - Attribute escaping: `esc_attr()`
   - URL escaping: `esc_url()`
   - JavaScript escaping: `wp_json_encode()`

5. **Database Layer**
   - Prepared statements: `$wpdb->prepare()`
   - WordPress Options API
   - Transients API

### AJAX Security Model
```
AJAX Request
     │
     ▼
check_ajax_referer()
  - Verify nonce
  - Prevent CSRF
     │
     ▼
current_user_can()
  - Check capability
  - Verify permissions
     │
     ▼
Sanitize Input
  - wp_unslash()
  - sanitize_text_field()
     │
     ▼
Process Request
     │
     ▼
Escape Output
  - esc_html__()
  - For JSON: automatic
     │
     ▼
wp_send_json_*()
  - Auto-exits
  - Prevents output after
```

## Performance Considerations

### Caching Strategy
- **What**: Repository data from CSV
- **Where**: WordPress transients
- **Duration**: 24 hours (configurable)
- **Invalidation**: Manual refresh button
- **Key**: `plugin_hub_csv_cache`

### Optimization Points
1. **Conditional Loading**:
   - Scripts/styles only on plugin page
   - Hook check: `plugins_page_plugin-hub`

2. **Lazy Loading**:
   - GitHub API calls only when needed
   - No automatic background checks

3. **Efficient Queries**:
   - Single transient for all repos
   - Batch operations possible

### Bottlenecks
1. **GitHub API Rate Limits**:
   - 60 requests/hour (unauthenticated)
   - Mitigated by caching

2. **CSV Parsing**:
   - Linear operation O(n)
   - Acceptable for reasonable plugin counts

3. **Plugin Installation**:
   - Network-bound (download speed)
   - WordPress handles efficiently

## Extension Points

### Filters
Potential filters for customization:
```php
// Allow filtering organization name
apply_filters( 'plugin_hub_organization', $organization );

// Allow filtering CSV URL
apply_filters( 'plugin_hub_csv_url', $csv_url );

// Allow filtering cache expiration
apply_filters( 'plugin_hub_cache_expiration', $cache_expiration );

// Allow filtering repo data
apply_filters( 'plugin_hub_repos', $repos );
```

### Actions
Potential actions for extensions:
```php
// After plugin installed
do_action( 'plugin_hub_after_install', $repo_name, $version );

// After plugin updated
do_action( 'plugin_hub_after_update', $repo_name, $old_version, $new_version );

// Before cache refresh
do_action( 'plugin_hub_before_cache_refresh' );

// After cache refresh
do_action( 'plugin_hub_after_cache_refresh', $repos );
```

## Dependencies

### WordPress Core
- Minimum: 6.0
- Tested: 6.9
- Required Functions:
  - Options API
  - Transients API
  - HTTP API (`wp_remote_get`)
  - Plugin API (hooks/filters)
  - Upgrader classes

### PHP Requirements
- Minimum: 8.0
- Features Used:
  - Namespaces
  - Type declarations
  - Short array syntax (optional but used)

### External Services
- **GitHub API**: Release data
  - No authentication (public repos only)
  - Rate limit: 60/hour
- **GitHub Raw Content**: CSV file
  - No rate limit
  - Public repository required

### JavaScript Dependencies
- jQuery (WordPress bundled)
- WordPress AJAX infrastructure

## Error Handling Strategy

### Graceful Degradation
1. **CSV Fetch Fails**: Empty plugin list, error logged
2. **GitHub API Fails**: No download URL, user notified
3. **Install Fails**: WordPress error message shown
4. **Cache Issues**: Fetch fresh data

### Error Logging
- All errors logged via `error_log()`
- Prefix: "Plugin Hub: "
- User-friendly messages via JSON responses
- Technical details in error log

### User Notifications
- Success: `wp_send_json_success()`
- Error: `wp_send_json_error()`
- Admin notices: `add_settings_error()`
- JavaScript alerts for immediate feedback
