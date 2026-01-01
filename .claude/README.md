# Plugin Hub Documentation

Welcome to the Plugin Hub documentation! This directory contains comprehensive technical documentation for developers working on or with Plugin Hub.

## 📚 Documentation Files

### [overview.md](overview.md)
High-level overview of the plugin including:
- What Plugin Hub is and what it does
- Current version and compatibility
- Key features
- Architecture overview
- File structure
- Data flow
- Key concepts
- Database usage
- Future considerations

**Start here** if you're new to the codebase!

### [coding-standards.md](coding-standards.md)
Complete coding guidelines including:
- PHP standards (namespaces, naming, bracing, spacing)
- Security standards (input validation, output escaping, nonces)
- Internationalization (i18n)
- PHPDoc documentation standards
- Database standards
- Error handling
- File organization
- Git commit standards

**Reference this** when writing or reviewing code.

### [architecture.md](architecture.md)
Deep technical architecture documentation:
- System architecture diagrams
- Class relationships and responsibilities
- Data flow diagrams
- Security architecture
- Performance considerations
- Extension points (filters/actions)
- Dependencies
- Error handling strategy

**Read this** to understand how everything fits together.

### [development.md](development.md)
Practical development guide covering:
- Getting started
- Development workflow
- Common development tasks
- Debugging techniques
- Testing procedures
- Code quality tools
- Version management
- Contributing guidelines
- Resources and support

**Use this** for day-to-day development work.

## 🚀 Quick Start

### For New Developers
1. Read [overview.md](overview.md) to understand what the plugin does
2. Review [architecture.md](architecture.md) to see how it's structured
3. Check [coding-standards.md](coding-standards.md) to learn our conventions
4. Follow [development.md](development.md) to set up your environment

### For Code Contributors
1. Review [coding-standards.md](coding-standards.md) before writing code
2. Reference [architecture.md](architecture.md) when modifying core functionality
3. Follow [development.md](development.md) for the contribution process

### For Maintainers
1. Use [development.md](development.md) for release procedures
2. Update [overview.md](overview.md) when adding major features
3. Update [architecture.md](architecture.md) when changing core structure
4. Keep [coding-standards.md](coding-standards.md) current with best practices

## 📋 Quick Reference

### Key Concepts
- **Namespace**: `PluginHub\`
- **Main Classes**: `Main`, `Admin`, `API`
- **Text Domain**: `plugin-hub`
- **Cache Duration**: 24 hours (DAY_IN_SECONDS)
- **GitHub Organization**: Open-WP-Club

### Important Files
```
plugin-hub.php           → Bootstrap, constants, entry point
includes/class-main.php  → Main orchestrator
includes/class-admin.php → Admin UI and hooks
includes/class-api.php   → GitHub API and AJAX handlers
uninstall.php           → Cleanup on plugin deletion
```

### Common Tasks
- **Adding AJAX handler**: See [development.md](development.md#adding-a-new-ajax-handler)
- **Adding admin setting**: See [development.md](development.md#adding-a-new-admin-setting)
- **Modifying GitHub org**: See [development.md](development.md#modifying-github-organization)
- **Adding CSV field**: See [development.md](development.md#adding-new-plugin-metadata)

### Security Checklist
- ✅ Nonce verification with `check_ajax_referer()`
- ✅ Capability checks with `current_user_can()`
- ✅ Input sanitization with `sanitize_text_field()` + `wp_unslash()`
- ✅ Output escaping with `esc_html()`, `esc_attr()`, `esc_url()`
- ✅ Use `wp_send_json_*()` for AJAX responses

### Code Standards
- ✅ K&R bracing style
- ✅ Tabs for indentation
- ✅ Yoda conditions (`'value' === $var`)
- ✅ Spaces after control structures
- ✅ PHPDoc for all methods
- ✅ Text domain in all translations

## 🔍 Finding Information

### "How do I...?"
- Add a feature → [development.md](development.md#common-development-tasks)
- Fix a bug → [development.md](development.md#debugging)
- Contribute → [development.md](development.md#contributing)
- Release a version → [development.md](development.md#version-management)

### "What is...?"
- The architecture → [architecture.md](architecture.md#system-architecture)
- The data flow → [architecture.md](architecture.md#data-flow-diagrams)
- The security model → [architecture.md](architecture.md#security-architecture)
- A specific class → [architecture.md](architecture.md#class-relationships)

### "Why does it...?"
- Use this structure → [architecture.md](architecture.md)
- Follow these standards → [coding-standards.md](coding-standards.md)
- Work this way → [overview.md](overview.md#data-flow)

## 🛠 Development Tools

### Recommended VS Code Extensions
- PHP Intelephense
- WordPress Snippets
- EditorConfig for VS Code
- GitLens

### Code Quality
- PHP_CodeSniffer with WordPress Coding Standards
- PHPStan for static analysis
- WordPress Debug mode for development

### Testing
- Manual testing checklist in [development.md](development.md#manual-testing-steps)
- Browser DevTools for AJAX debugging
- WordPress error logging

## 📝 Maintenance

### Keeping Documentation Current
When you make changes to the codebase:

1. **Adding a feature**: Update [overview.md](overview.md) with new functionality
2. **Changing architecture**: Update [architecture.md](architecture.md) diagrams and flows
3. **New coding patterns**: Update [coding-standards.md](coding-standards.md)
4. **Development process changes**: Update [development.md](development.md)

### Documentation Updates
This documentation should be updated:
- ✅ When adding new features
- ✅ When changing core architecture
- ✅ When establishing new coding patterns
- ✅ When modifying development workflow
- ✅ Before each major release

## 🤝 Contributing to Documentation

Documentation improvements are welcome! When contributing:

1. Keep language clear and concise
2. Include code examples where helpful
3. Update table of contents if adding sections
4. Maintain consistent formatting
5. Cross-reference related sections

## 📞 Support

If you can't find what you're looking for:
- Check all four documentation files
- Search for keywords in the files
- Review code comments and PHPDoc blocks
- Check GitHub issues for related discussions

## 🔄 Version

This documentation is for **Plugin Hub v1.1.0**

Last updated: 2026-01-02

---

*Built with ❤️ for the Open-WP-Club community*
