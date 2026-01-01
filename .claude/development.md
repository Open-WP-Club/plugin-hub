# Development Guide

## Getting Started

### Prerequisites
- WordPress 6.0+ (tested up to 6.9)
- PHP 8.0+
- Git
- Code editor (VS Code recommended)
- Local WordPress development environment

### Initial Setup
```bash
# Clone the repository
git clone https://github.com/Open-WP-Club/plugin-hub.git

# Navigate to WordPress plugins directory
cd /path/to/wordpress/wp-content/plugins/

# Symlink or copy the plugin
ln -s /path/to/plugin-hub plugin-hub

# Activate in WordPress admin
```

### Development Environment
Recommended tools:
- **Local Server**: Local WP, XAMPP, MAMP, or Docker
- **PHP**: PHP 8.0+ with extensions: curl, json, mbstring
- **Editor**: VS Code with extensions:
  - PHP Intelephense
  - WordPress Snippets
  - EditorConfig for VS Code

## Project Structure

```
plugin-hub/
├── .claude/                    # Documentation (this folder)
│   ├── overview.md
│   ├── coding-standards.md
│   ├── architecture.md
│   └── development.md
├── assets/
│   ├── css/
│   │   └── style.css          # Admin styles
│   └── js/
│       └── script.js          # Admin JavaScript (AJAX)
├── includes/
│   ├── class-main.php         # Main orchestrator
│   ├── class-admin.php        # Admin functionality
│   ├── class-api.php          # GitHub API & AJAX
│   └── admin-display.php      # Admin UI template
├── plugin-hub.php             # Main plugin file
├── uninstall.php              # Cleanup on uninstall
├── readme.txt                 # WordPress.org format
├── readme.md                  # GitHub format
├── .gitignore
└── LICENSE
```

## Development Workflow

### 1. Feature Development
```bash
# Create feature branch
git checkout -b feature/your-feature-name

# Make changes
# Test thoroughly

# Commit with conventional commits
git commit -m "feat: add new feature description"

# Push branch
git push origin feature/your-feature-name

# Create pull request on GitHub
```

### 2. Bug Fixes
```bash
# Create fix branch
git checkout -b fix/bug-description

# Fix the bug
# Add tests if applicable

# Commit
git commit -m "fix: resolve bug description"

# Push and create PR
git push origin fix/bug-description
```

### 3. Testing Checklist
- [ ] Plugin activates without errors
- [ ] Plugin deactivates cleanly
- [ ] Uninstall removes all data
- [ ] AJAX requests work correctly
- [ ] Nonce verification passes
- [ ] Capability checks work
- [ ] GitHub API integration works
- [ ] Caching functions properly
- [ ] No JavaScript console errors
- [ ] No PHP errors or warnings
- [ ] Translations load correctly
- [ ] WordPress Coding Standards pass

## Common Development Tasks

### Adding a New AJAX Handler

1. **Register the hook** in `class-main.php`:
```php
add_action( 'wp_ajax_your_new_action', array( $this->api, 'ajax_your_new_action' ) );
```

2. **Create the method** in `class-api.php`:
```php
/**
 * AJAX handler for your new action.
 *
 * @since 1.1.0
 */
public function ajax_your_new_action() {
    check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Permission denied.', 'plugin-hub' ) );
    }

    $data = isset( $_POST['data'] ) ? sanitize_text_field( wp_unslash( $_POST['data'] ) ) : '';

    // Process data

    wp_send_json_success( esc_html__( 'Success message', 'plugin-hub' ) );
}
```

3. **Add JavaScript** in `assets/js/script.js`:
```javascript
jQuery('.your-button').on('click', function(e) {
    e.preventDefault();

    jQuery.ajax({
        url: pluginHubAjax.ajax_url,
        type: 'POST',
        data: {
            action: 'your_new_action',
            nonce: pluginHubAjax.nonce,
            data: yourData
        },
        success: function(response) {
            if (response.success) {
                alert(response.data);
            }
        }
    });
});
```

### Adding a New Admin Setting

1. **Add option handling** in `class-admin.php`:
```php
public function ajax_update_setting() {
    check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Permission denied.', 'plugin-hub' ) );
    }

    $value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
    update_option( 'plugin_hub_setting_name', $value );

    wp_send_json_success( esc_html__( 'Setting saved.', 'plugin-hub' ) );
}
```

2. **Register the AJAX action** in `class-main.php`:
```php
add_action( 'wp_ajax_update_setting', array( $this->admin, 'ajax_update_setting' ) );
```

3. **Add UI element** in `admin-display.php`:
```php
<input
    type="text"
    id="setting-name"
    value="<?php echo esc_attr( get_option( 'plugin_hub_setting_name', '' ) ); ?>"
>
```

4. **Don't forget cleanup** in `uninstall.php`:
```php
delete_option( 'plugin_hub_setting_name' );
```

### Modifying GitHub Organization

The organization is currently hardcoded. To make it dynamic:

1. **Add filter** in `class-api.php`:
```php
$this->organization = apply_filters( 'plugin_hub_organization', 'Open-WP-Club' );
```

2. **Add setting** for user configuration
3. **Update CSV URL** accordingly

### Adding New Plugin Metadata

If you need to add fields to the CSV:

1. **Update CSV format** in GitHub repository
2. **Modify parser** in `parse_csv_content()`:
```php
$repos[] = array(
    'name'         => trim( $data[0] ),
    'display_name' => trim( $data[1] ),
    'description'  => trim( $data[2] ),
    'version'      => trim( $data[3] ),
    'repo_url'     => trim( $data[4] ),
    'new_field'    => trim( $data[5] ), // Add this
);
```

3. **Update display** in `admin-display.php`

## Debugging

### Enable WordPress Debug Mode
```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true ); // Use unminified scripts
```

### View Error Log
```bash
# Location
tail -f wp-content/debug.log

# Or check PHP error log
tail -f /var/log/php/error.log
```

### Browser Console
- Open Developer Tools (F12)
- Check Console tab for JavaScript errors
- Check Network tab for AJAX requests

### Common Issues

**AJAX returns 0**:
- Hook not registered
- Function name mismatch
- User not logged in

**Nonce verification fails**:
- Nonce expired (12-24 hours)
- Different user session
- Cache issue

**Plugin not showing**:
- CSV format incorrect
- GitHub API rate limit
- Cache not cleared

## Testing

### Manual Testing Steps

1. **Installation**:
   - Activate plugin
   - Check for PHP errors
   - Verify admin menu appears

2. **Plugin List**:
   - Open Plugin Hub page
   - Verify plugins load
   - Check filtering works

3. **Installation**:
   - Click "Install Now"
   - Verify success message
   - Check plugin appears in WordPress plugins

4. **Updates**:
   - Change version in CSV
   - Clear cache
   - Verify update notification
   - Click "Update Now"

5. **Activation/Deactivation**:
   - Test activate button
   - Test deactivate button
   - Verify status changes

6. **Deletion**:
   - Deactivate plugin
   - Click delete
   - Verify removal

7. **Settings**:
   - Toggle beta plugins
   - Verify visibility changes
   - Test cache refresh

8. **Uninstall**:
   - Deactivate plugin
   - Delete plugin
   - Check database for leftover data

### Automated Testing (Future)
Consider adding:
- PHPUnit for unit tests
- WordPress test suite
- JavaScript tests (Jest)
- Integration tests

## Code Quality Tools

### PHP CodeSniffer
```bash
# Install
composer require --dev squizlabs/php_codesniffer
composer require --dev wp-coding-standards/wpcs

# Configure
phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs

# Run
phpcs --standard=WordPress includes/
phpcs --standard=WordPress plugin-hub.php

# Auto-fix
phpcbf --standard=WordPress includes/
```

### PHP Stan (Static Analysis)
```bash
# Install
composer require --dev phpstan/phpstan

# Run
phpstan analyse includes/
```

## Version Management

### Semantic Versioning
- **Major** (X.0.0): Breaking changes
- **Minor** (1.X.0): New features, backward compatible
- **Patch** (1.0.X): Bug fixes

### Before Release
1. Update version in:
   - `plugin-hub.php` (header)
   - `plugin-hub.php` (PLUGIN_HUB_VERSION constant)
   - `readme.txt` (Stable tag)
   - `readme.txt` (Changelog)

2. Test thoroughly
3. Update documentation
4. Create git tag:
```bash
git tag -a v1.1.0 -m "Release version 1.1.0"
git push origin v1.1.0
```

5. Create GitHub release
6. Update CSV file with new version

## Security Best Practices

### Input Validation
- Always use `wp_unslash()` before sanitizing `$_POST`
- Use appropriate sanitization functions
- Validate data types

### Output Escaping
- Always escape output
- Use correct escaping function for context
- Never trust user input

### Nonce Usage
- Create nonce: `wp_create_nonce()`
- Verify nonce: `check_ajax_referer()`
- Use unique nonce names

### Capability Checks
- Check before sensitive operations
- Use specific capabilities
- Fail closed (deny by default)

### Database Operations
- Use Options API when possible
- Use `$wpdb->prepare()` for custom queries
- Never use user input directly in SQL

## Performance Tips

### Optimize AJAX
- Only load scripts on necessary pages
- Use event delegation
- Debounce rapid requests

### Optimize PHP
- Cache expensive operations
- Use transients for external API calls
- Avoid N+1 queries

### Optimize Database
- Use appropriate indexes
- Limit option autoloading
- Clean up on uninstall

## Contributing

### Pull Request Process
1. Fork the repository
2. Create feature branch
3. Make changes following coding standards
4. Test thoroughly
5. Update documentation
6. Submit pull request with:
   - Clear description
   - Related issue number
   - Screenshots if UI changes

### Code Review Checklist
- [ ] Follows coding standards
- [ ] Has PHPDoc comments
- [ ] Includes security measures
- [ ] No PHP/JavaScript errors
- [ ] Tested in multiple environments
- [ ] Documentation updated
- [ ] Changelog updated

## Resources

### WordPress
- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Coding Standards](https://developer.wordpress.org/coding-standards/)
- [Plugin Security](https://developer.wordpress.org/plugins/security/)
- [AJAX in Plugins](https://developer.wordpress.org/plugins/javascript/ajax/)

### GitHub
- [REST API Documentation](https://docs.github.com/en/rest)
- [Releases API](https://docs.github.com/en/rest/releases)

### Tools
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
- [VS Code WordPress Extension](https://marketplace.visualstudio.com/items?itemName=wordpresstoolbox.wordpress-toolbox)

## Support

### Getting Help
- GitHub Issues: Bug reports and feature requests
- Documentation: This `.claude/` directory
- WordPress Forums: General WordPress questions

### Reporting Bugs
Include:
- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected behavior
- Actual behavior
- Error messages
- Screenshots if applicable
