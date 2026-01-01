# Coding Standards

This plugin follows **WordPress Coding Standards** with modern PHP 8.0+ features.

## PHP Standards

### Namespace Usage
```php
namespace PluginHub;

// For global WordPress classes, use backslash
$upgrader = new \Plugin_Upgrader( $skin );
$obj = new \stdClass();
```

### Class Naming
- Use PascalCase: `Admin`, `API`, `Main`
- No prefixes needed (namespace handles conflicts)
- One class per file

### File Naming
- Class files: `class-{name}.php` (lowercase, hyphenated)
- Example: `class-admin.php`, `class-api.php`

### Method Naming
- Use snake_case: `get_plugin_file()`, `ajax_install_github_plugin()`
- Prefix AJAX handlers with `ajax_`
- Private methods are okay, mark with `@access private`

### Bracing Style (K&R)
```php
// CORRECT
public function example() {
    if ( $condition ) {
        // code
    }
}

// WRONG (Allman style)
public function example()
{
    if ( $condition )
    {
        // code
    }
}
```

### Spacing
- Space after control structures: `if (`, `foreach (`, `while (`
- Space around operators: `$a = $b + $c`
- Space after commas: `array( 'key' => 'value' )`
- No space before semicolon: `$var;` not `$var ;`
- Use tabs for indentation (WordPress standard)

### Arrays
```php
// Multi-line arrays: trailing comma
$array = array(
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3', // <-- trailing comma
);

// Short array syntax is acceptable in PHP 8.0+
$array = [
    'key' => 'value',
];
```

### Yoda Conditions
```php
// CORRECT - Yoda conditions
if ( 'all' === $filter ) {
    // code
}

// WRONG
if ( $filter === 'all' ) {
    // code
}
```

### Boolean Checks
```php
// Use ! with space
if ( ! $value ) {
    // code
}

// Not
if ( !$value ) {
    // code
}
```

## Security Standards

### Input Validation
```php
// ALWAYS use wp_unslash before sanitizing POST data
$repo = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

// For arrays
$items = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['items'] ) ) : array();
```

### Output Escaping
```php
// HTML content
echo esc_html( $text );
echo esc_html__( 'Translatable text', 'plugin-hub' );

// Attributes
echo '<input value="' . esc_attr( $value ) . '">';
echo '<input placeholder="' . esc_attr__( 'Search...', 'plugin-hub' ) . '">';

// URLs
echo '<a href="' . esc_url( $link ) . '">Link</a>';

// JavaScript
echo '<script>var data = ' . wp_json_encode( $data ) . ';</script>';

// HTML content that should allow some tags
echo wp_kses_post( $html_content );
```

### Nonce Verification
```php
// AJAX handlers
check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

// Form submissions
wp_nonce_field( 'action_name', 'nonce_field_name' );
check_admin_referer( 'action_name', 'nonce_field_name' );
```

### Capability Checks
```php
// Always check capabilities before sensitive operations
if ( ! current_user_can( 'install_plugins' ) ) {
    wp_send_json_error( esc_html__( 'Permission denied.', 'plugin-hub' ) );
}
```

### Safe Redirects
```php
// Use wp_safe_redirect instead of wp_redirect
wp_safe_redirect( admin_url( 'plugins.php?page=plugin-hub' ) );
exit;
```

## Internationalization (i18n)

### Text Domain
- Always use: `plugin-hub`
- Set in main plugin header: `Text Domain: plugin-hub`

### Translation Functions
```php
// Simple string
__( 'Text', 'plugin-hub' );

// Echo string
_e( 'Text', 'plugin-hub' );

// With escaping (preferred)
esc_html__( 'Text', 'plugin-hub' );
esc_html_e( 'Text', 'plugin-hub' );
esc_attr__( 'Text', 'plugin-hub' );
esc_attr_e( 'Text', 'plugin-hub' );

// With variables (sprintf)
sprintf(
    /* translators: %s: Plugin name */
    esc_html__( 'Installing %s', 'plugin-hub' ),
    $plugin_name
);

// Plurals
_n(
    '%s plugin',
    '%s plugins',
    $count,
    'plugin-hub'
);
```

### Translator Comments
```php
/* translators: %1$s: Repository name, %2$s: Version number */
$message = sprintf(
    esc_html__( 'Unable to fetch %1$s v%2$s', 'plugin-hub' ),
    $repo_name,
    $version
);
```

## Documentation Standards (PHPDoc)

### File Headers
```php
<?php
/**
 * Short description of file.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.0.0
 */

namespace PluginHub;
```

### Class Documentation
```php
/**
 * Short description of class.
 *
 * Long description of what the class does,
 * its purpose, and any important notes.
 *
 * @since 1.0.0
 */
class Example {
```

### Property Documentation
```php
/**
 * Short description of property.
 *
 * @since  1.0.0
 * @access private
 * @var    string
 */
private $property;
```

### Method Documentation
```php
/**
 * Short description of method.
 *
 * Long description if needed.
 *
 * @since  1.0.0
 * @access private                     // Only if private/protected
 * @param  string $param1 Description.
 * @param  array  $param2 Description.
 * @return bool                        Return description.
 */
public function example_method( $param1, $param2 ) {
```

## Database Standards

### Using Options
```php
// Get option with default
$value = get_option( 'plugin_hub_option_name', 'default_value' );

// Update option
update_option( 'plugin_hub_option_name', $value );

// Delete option
delete_option( 'plugin_hub_option_name' );
```

### Using Transients
```php
// Get transient
$data = get_transient( 'plugin_hub_cache_key' );

// Set transient (24 hours)
set_transient( 'plugin_hub_cache_key', $data, DAY_IN_SECONDS );

// Delete transient
delete_transient( 'plugin_hub_cache_key' );
```

### Direct Database Queries
```php
// Use $wpdb properly
global $wpdb;
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->options} WHERE option_name LIKE %s",
        'plugin_hub_%'
    )
);

// For uninstall, direct queries are acceptable
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'plugin_hub_%'" );
```

## Constants

### Use WordPress Constants
```php
// CORRECT
DAY_IN_SECONDS    // 86400
HOUR_IN_SECONDS   // 3600
MINUTE_IN_SECONDS // 60

// WRONG
86400  // Magic number
```

### Plugin Constants
```php
// Define in main plugin file
define( 'PLUGIN_HUB_VERSION', '1.1.0' );
define( 'PLUGIN_HUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_HUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
```

## Error Handling

### Logging
```php
// Use error_log for debugging
error_log( 'Plugin Hub: Error message here' );
error_log( 'Plugin Hub: ' . print_r( $data, true ) );

// NEVER use var_dump or print_r for output
// NEVER leave debugging code in production
```

### AJAX Responses
```php
// Success
wp_send_json_success( $data );

// Error
wp_send_json_error( $message );

// ALWAYS exit after JSON response (wp_send_json_* does this automatically)
```

## File Organization

- **Main file**: Bootstrap, constants, run function
- **Class files**: One class per file in `includes/`
- **Templates**: Separate display logic in template files
- **Assets**: CSS in `assets/css/`, JS in `assets/js/`
- **Documentation**: Keep in `.claude/` directory

## Git Standards

### Commit Messages
```
Format: <type>: <description>

Types:
- feat: New feature
- fix: Bug fix
- docs: Documentation changes
- style: Code style changes (formatting)
- refactor: Code refactoring
- test: Adding tests
- chore: Maintenance tasks

Examples:
feat: Add beta plugin toggle
fix: Correct version comparison logic
docs: Update coding standards
refactor: Convert to namespaces
```

### Branching
- `main` - Production-ready code
- `develop` - Development branch
- `feature/*` - New features
- `fix/*` - Bug fixes
- `release/*` - Release preparation
