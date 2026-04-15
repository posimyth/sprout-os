# Changelog

All notable changes to SproutOS will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.1] - 2026-04-12

### Added
- WordPress admin dashboard with connection settings, module controls, analytics, and privacy options
- AI-powered workflow tools via Model Context Protocol (MCP 2025-06-18 specification)
- **Content tools**: `sprout/create-page`, `sprout/update-page`
- **Filesystem tools**: `sprout/read-file`, `sprout/write-file`, `sprout/edit-file`, `sprout/delete-file`, `sprout/directory-list`
- **Theme tools**: `sprout/list-theme-files`, `sprout/read-theme-file`, `sprout/update-theme-file`, `sprout/update-theme-stylesheet`
- **Code execution tools**: `sprout/execute-php` with configurable timeout (1-120s), query capture, and execution telemetry; `sprout/batch-execute`
- **Sandbox tools**: `sprout/sandbox-enable`, `sprout/sandbox-disable`
- **Module management**: `sprout/manage-modules` for enabling/disabling tool categories at runtime
- **Bridge tools**: `sprout-bridge/discover-tools`, `sprout-bridge/inspect-tool`, `sprout-bridge/dispatch-tool`
- Sandbox environment (`wp-content/sproutos-mcp-sandbox/`) with:
  - Syntax validation via PHP tokenizer before execution
  - Symbol conflict detection (class/function collision checks)
  - Automatic crash recovery with per-file isolation
  - Safe Mode for full sandbox suspension
  - Validation caching via SHA-256 sidecar files
  - Multi-file project directory support
- 6 modular ability categories (WordPress, Elementor, The Plus Addons, Nexter Extension, Nexter Blocks, WDesignKit) with independent enable/disable
- Per-tool enable/disable toggles from the admin UI
- One-click Safe Mode (read-only) with automatic configuration save/restore
- Sensitive file protection (`.env`, `wp-config.php`, `.htaccess`, `debug.log`, `.git/`, `.sql` files)
- Path boundary enforcement - all operations restricted to ABSPATH
- Symlink rejection on filesystem operations
- Analytics and activity tracking with configurable log levels (all, errors, off)
- Configurable log retention with automatic cron-based cleanup
- Email notifications (per-session or daily digest)
- Webhook notifications for external monitoring (Slack, Discord, custom endpoints)
- Privacy controls for IP storage, IP anonymization, user identity, request/response body logging
- Server instruction customization (WP version, PHP version, theme info, plugin list, custom text)
- Admin bar status indicator (Sprout MCP: ON/OFF)
- Bundled MCP Adapter with HTTP and STDIO transport support
- WP-CLI integration via `wp mcp-adapter serve` and `wp mcp-adapter list`
- Clean uninstall handler removing all options, database tables, transients, cron jobs, and sandbox directory
- Conditional file loading for performance (admin UI loaded on-demand only)
- Per-request static caching for settings and ability state lookups

### Security
- All MCP operations require `manage_options` capability (Administrator role)
- Application Password authentication for HTTP transport
- Nonce verification on all AJAX handlers
- Input sanitization via `sanitize_text_field()`, `sanitize_email()`, `sanitize_textarea_field()`, `esc_url_raw()`
- `$wpdb->prepare()` used for all database queries
- Ability source tracking limited to admin context only (zero overhead on frontend/REST)


[0.0.1]: https://github.com/posimyth/sprout-os/releases/tag/v0.0.1
