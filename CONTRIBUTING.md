# Contributing to Plugin Hub

Thank you for your interest in contributing to Plugin Hub! This document provides guidelines and instructions for contributing.

## Code of Conduct

- Be respectful and constructive
- Welcome newcomers and help them learn
- Focus on what is best for the community
- Show empathy towards other community members

## How to Contribute

### Reporting Bugs

Before creating a bug report:
1. Check existing issues to avoid duplicates
2. Collect relevant information (versions, environment, steps to reproduce)

When creating a bug report, include:
- **Clear title**: Summarize the issue in one line
- **Description**: Detailed explanation of the problem
- **Steps to reproduce**: Numbered list of exact steps
- **Expected behavior**: What should happen
- **Actual behavior**: What actually happens
- **Environment**:
  - WordPress version
  - PHP version
  - Plugin version
  - Browser (if relevant)
  - Server environment
- **Screenshots**: If applicable
- **Error messages**: Full text from logs

### Suggesting Features

Feature requests are welcome! Include:
- **Clear use case**: Why is this needed?
- **Proposed solution**: How would it work?
- **Alternatives**: What other approaches did you consider?
- **Additional context**: Screenshots, examples, etc.

### Pull Requests

1. **Fork the repository**
   ```bash
   # Click "Fork" on GitHub, then:
   git clone https://github.com/YOUR-USERNAME/plugin-hub.git
   cd plugin-hub
   git remote add upstream https://github.com/Open-WP-Club/plugin-hub.git
   ```

2. **Create a branch**
   ```bash
   # For features
   git checkout -b feature/your-feature-name

   # For bug fixes
   git checkout -b fix/bug-description

   # For documentation
   git checkout -b docs/what-you-updated
   ```

3. **Make your changes**
   - Follow the [coding standards](.claude/coding-standards.md)
   - Add/update PHPDoc comments
   - Include inline comments for complex logic
   - Update documentation if needed

4. **Test thoroughly**
   - Test on a clean WordPress installation
   - Test with WordPress 6.0 and 6.9
   - Test with PHP 8.0+
   - Check for PHP errors and warnings
   - Verify AJAX functionality
   - Test security (nonces, capabilities, escaping)

5. **Commit your changes**
   ```bash
   # Use conventional commits format
   git commit -m "feat: add new feature"
   git commit -m "fix: resolve bug with..."
   git commit -m "docs: update documentation for..."
   git commit -m "style: fix code formatting in..."
   git commit -m "refactor: improve structure of..."
   git commit -m "test: add tests for..."
   git commit -m "chore: update dependencies"
   ```

6. **Push to your fork**
   ```bash
   git push origin your-branch-name
   ```

7. **Create Pull Request**
   - Go to the original repository
   - Click "New Pull Request"
   - Select your branch
   - Fill in the PR template

### Pull Request Guidelines

Your PR should:
- ✅ Have a clear, descriptive title
- ✅ Reference related issues (e.g., "Fixes #123")
- ✅ Include a description of changes
- ✅ Follow coding standards
- ✅ Include tests if applicable
- ✅ Update documentation if needed
- ✅ Have no merge conflicts
- ✅ Pass all checks (if automated testing is set up)

### PR Review Process

1. **Automated checks** (if configured)
   - Code standards (PHPCS)
   - Static analysis (PHPStan)
   - Automated tests

2. **Code review**
   - Maintainer reviews code
   - May request changes
   - Discussion and iteration

3. **Approval and merge**
   - Once approved, maintainer merges
   - Branch is deleted
   - Issue is closed

## Development Setup

### Requirements
- WordPress 6.0+ (tested to 6.9)
- PHP 8.0+
- Git
- Code editor
- Local development environment

### Setup Steps

1. **Clone and install**
   ```bash
   git clone https://github.com/Open-WP-Club/plugin-hub.git
   cd plugin-hub
   ```

2. **Install dev dependencies** (optional)
   ```bash
   composer install --dev
   ```

3. **Enable debugging**
   ```php
   // In wp-config.php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   define( 'SCRIPT_DEBUG', true );
   ```

4. **Read the documentation**
   - [Overview](.claude/overview.md)
   - [Architecture](.claude/architecture.md)
   - [Coding Standards](.claude/coding-standards.md)
   - [Development Guide](.claude/development.md)

## Coding Standards

### PHP
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use PHP 8.0+ features (namespaces, type hints)
- Use K&R bracing style
- Use tabs for indentation
- Use Yoda conditions
- Add PHPDoc to all methods

Example:
```php
/**
 * Short description.
 *
 * @since  1.1.0
 * @param  string $param Description.
 * @return bool          Description.
 */
public function example_method( $param ) {
    if ( 'value' === $param ) {
        return true;
    }
    return false;
}
```

### Security
- **Always** verify nonces
- **Always** check capabilities
- **Always** sanitize input with `wp_unslash()`
- **Always** escape output
- Use `wp_send_json_*()` for AJAX responses

### Internationalization
- Use text domain: `plugin-hub`
- Use translation functions: `__()`, `_e()`, `esc_html__()`, etc.
- Add translator comments for context

```php
/* translators: %s: Plugin name */
sprintf( esc_html__( 'Installing %s', 'plugin-hub' ), $name );
```

### JavaScript
- Use jQuery (WordPress bundled)
- Properly enqueue scripts
- Use localized data for AJAX URL and nonce
- Handle errors gracefully

### CSS
- Use tabs for indentation
- Follow WordPress CSS standards
- Keep specificity low
- Comment complex rules

## Testing

### Manual Testing Checklist
- [ ] Plugin activates without errors
- [ ] Plugin deactivates cleanly
- [ ] Admin menu appears correctly
- [ ] Plugin list loads from GitHub
- [ ] Install functionality works
- [ ] Update functionality works
- [ ] Activate/deactivate works
- [ ] Delete functionality works
- [ ] Settings persist correctly
- [ ] Cache refresh works
- [ ] No PHP errors or warnings
- [ ] No JavaScript console errors
- [ ] Nonce verification works
- [ ] Capability checks work
- [ ] All output is escaped
- [ ] All input is sanitized

### Code Quality Tools

Run PHP CodeSniffer:
```bash
phpcs --standard=WordPress includes/ plugin-hub.php
```

Auto-fix issues:
```bash
phpcbf --standard=WordPress includes/ plugin-hub.php
```

Run static analysis:
```bash
phpstan analyse includes/
```

## Documentation

### Code Documentation
- Add PHPDoc to all classes, methods, and properties
- Include `@since` tags
- Document parameters and return types
- Add inline comments for complex logic

### User Documentation
- Update `readme.txt` for user-facing changes
- Update `.claude/` documentation for technical changes
- Include examples where helpful

### Commit Messages
Use conventional commits format:
```
<type>: <description>

[optional body]

[optional footer]
```

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Code style (formatting, missing semi-colons, etc.)
- `refactor`: Code refactoring
- `test`: Adding tests
- `chore`: Maintenance

Example:
```
feat: add support for private repositories

Add GitHub token authentication to support private repos.
Includes new settings page for token management.

Closes #45
```

## Release Process

For maintainers releasing new versions:

1. **Update version numbers**
   - `plugin-hub.php` header
   - `PLUGIN_HUB_VERSION` constant
   - `readme.txt` stable tag

2. **Update changelog**
   - `readme.txt` changelog section
   - Document all changes since last release

3. **Test thoroughly**
   - Fresh WordPress install
   - Upgrade from previous version
   - All functionality

4. **Create release**
   ```bash
   git tag -a v1.1.0 -m "Release version 1.1.0"
   git push origin v1.1.0
   ```

5. **GitHub release**
   - Create release on GitHub
   - Include changelog
   - Attach zip file

6. **Update CSV**
   - Update version in plugins.csv
   - Push to GitHub repository

## Questions?

- **Documentation**: Check `.claude/` directory
- **Issues**: GitHub Issues
- **Discussion**: GitHub Discussions (if enabled)

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0+ license.

---

Thank you for contributing to Plugin Hub! 🎉
