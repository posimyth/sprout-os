# Contributing to SproutOS

Thank you for your interest in contributing to SproutOS! This guide will help you get started.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment for everyone.

## Getting Started

### Prerequisites

- WordPress 6.5 or higher
- PHP 8.0 or higher
- [WP-CLI](https://wp-cli.org/) installed
- A local WordPress development environment (e.g., [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), [Local](https://localwp.com/), [DDEV](https://ddev.com/), or [Lando](https://lando.dev/))
- Git

### Local Setup

1. **Clone the repository** into your WordPress plugins directory:

   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/posimyth/sprout-os.git
   cd sprout-os
   ```

2. **Install the required dependencies**:

   - [WordPress Abilities API](https://github.com/WordPress/abilities-api) - clone into your plugins directory and activate
   - The MCP Adapter is already bundled in `sprout-libs/wordpress/mcp-adapter/`

3. **Activate the plugin** from WordPress admin and enable AI Abilities from the SproutOS settings page.

4. **Verify the MCP server** is running:

   ```bash
   wp mcp-adapter list
   ```

## How to Contribute

### Reporting Bugs

- Search [existing issues](https://github.com/posimyth/sprout-os/issues) first to avoid duplicates
- Use the bug report issue template
- Include:
  - WordPress version, PHP version, and SproutOS version
  - Steps to reproduce
  - Expected vs actual behavior
  - Error messages or logs (check `wp-content/debug.log` if `WP_DEBUG` is enabled)
  - Which MCP client you're using (Claude Code, Cursor, etc.)

### Suggesting Features

- Open an issue with the feature request template
- Describe the use case and why the feature would be valuable
- If proposing a new MCP tool, include the input/output schema you'd expect

### Submitting Code

1. **Fork the repository** and create a feature branch:

   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes** following the coding standards below.

3. **Test your changes**:
   - Verify the plugin activates without errors
   - Test with at least one MCP client (Claude Code recommended)
   - Check the admin UI renders correctly
   - If you added a new ability, verify it appears in tool discovery

4. **Commit with a clear message**:

   ```bash
   git commit -m "Add: brief description of what and why"
   ```

5. **Push and create a Pull Request** against the `main` branch.

## Coding Standards

### PHP

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use `declare(strict_types=1)` in all new files
- Prefix all global functions with `sprout_mcp_`
- Prefix all classes with `Sprout_MCP_`
- Use type declarations for parameters and return types
- All database queries must use `$wpdb->prepare()`
- All user input must be sanitized (`sanitize_text_field()`, `esc_url_raw()`, etc.)
- All output must be escaped (`esc_html()`, `esc_attr()`, `wp_kses()`, etc.)
- AJAX handlers must verify nonces AND capabilities

### File Organization

- New MCP abilities go in `sprout-core/abilities/` under the appropriate subdirectory
- Register abilities in `sprout-core/abilities_register/class-abilities-register.php`
- Filesystem/security helpers go in `sprout-core/filesystem-helpers.php`
- Admin UI changes go in `sprout-core/admin-pages.php`

### Ability Registration

When adding a new MCP tool, follow this pattern:

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/your-tool-name', [
    'label'       => __('Your Tool Label', 'sprout-os'),
    'description' => 'Clear description of what this tool does.',
    'category'    => 'sprout-filesystem', // or appropriate category

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            // Define your input parameters
        ],
        'required'             => ['required_param'],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            // Define your output shape
        ],
    ],

    'execute_callback'    => 'sprout_mcp_your_tool_callback',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'title'       => 'Your Tool Label',
            'readonly'    => false,    // true if this only reads data
            'destructive' => false,    // true if this deletes/overwrites data
            'idempotent'  => true,     // true if repeated calls produce the same result
        ],
    ],
]);

function sprout_mcp_your_tool_callback(array $input): array|WP_Error {
    // Validate input
    // Check permissions/boundaries
    // Execute logic
    // Return result array or WP_Error
}
```

### Security Checklist

Before submitting a PR that adds or modifies MCP tools:

- [ ] All file paths are resolved through `sprout_mcp_resolve_path()`
- [ ] Sensitive files are checked via `sprout_mcp_check_sensitive_file()`
- [ ] PHP file writes use `sprout_mcp_check_php_sandbox()` to enforce sandbox boundaries
- [ ] The `permission_callback` verifies appropriate capabilities
- [ ] No user input is used directly in SQL, file paths, or shell commands without sanitization
- [ ] Destructive operations have `'destructive' => true` in annotations
- [ ] Read-only operations have `'readonly' => true` in annotations

## Pull Request Guidelines

- Keep PRs focused on a single change
- Include a clear description of what changed and why
- Reference related issues with `Fixes #123` or `Closes #123`
- Ensure no debugging code (`var_dump`, `error_log`, `console.log`) is left in
- Do not modify files in `sprout-libs/` - these are managed dependencies

## Need Help?

- Open a [GitHub Discussion](https://github.com/posimyth/sprout-os/discussions) for questions
- Contact us at [store.posimyth.com/helpdesk/](https://store.posimyth.com/helpdesk/)

Thank you for helping make SproutOS better!
