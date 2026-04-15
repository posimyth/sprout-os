<p align="center">
  <img src="sprout-ui/logo-white.png" alt="SproutOS" width="200">
</p>

<h1 align="center">SproutOS - WordPress MCP Plugin</h1>

<p align="center">
  <strong>Give AI agents full control over your WordPress site - pages, themes, files, code, and database - through the Model Context Protocol.</strong>
</p>

<p align="center">
  <a href="#installation">Installation</a> &bull;
  <a href="#connecting-to-ai-clients">Connect to AI</a> &bull;
  <a href="#available-tools">Tools</a> &bull;
  <a href="#sandbox">Sandbox</a> &bull;
  <a href="#use-cases">Use Cases</a> &bull;
  <a href="#safety--controls">Safety</a> &bull;
  <a href="#faq">FAQ</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/WordPress-6.5%2B-blue?logo=wordpress" alt="WordPress 6.5+">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/MCP-2025--06--18-green" alt="MCP Spec">
  <img src="https://img.shields.io/badge/License-GPLv2-orange" alt="License GPLv2">
  <img src="https://img.shields.io/badge/Version-0.0.1-brightgreen" alt="Version 0.0.1">
</p>

---

## What is SproutOS?

SproutOS is a WordPress plugin that turns your entire WordPress site into an MCP (Model Context Protocol) server. It lets AI agents like Claude, ChatGPT, Cursor, and others **read files, write code, edit themes, create pages, execute PHP, and manage your site** - all through a standardized protocol with granular admin controls.

Built on the official [WordPress MCP Adapter](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) and the [WordPress Abilities API](https://github.com/WordPress/abilities-api), SproutOS is the most comprehensive WordPress MCP implementation available.

**[Website](https://sproutos.ai)** &bull; **[Documentation](https://sproutos.documentationai.com/wordpress-plugin)**

> **"With great power comes great responsibility."** SproutOS gives AI agents deep access to your WordPress site. Every tool can be individually enabled or disabled. Use it wisely.

---

## Screenshots & Demo

> **Coming Soon** - Screenshots of the SproutOS admin dashboard, tool toggles, analytics panel, sandbox management UI, and a full video walkthrough showing SproutOS in action with Claude Code are on the way. Stay tuned!

<!--
Uncomment and replace with actual assets when ready:

### Admin Dashboard
![SproutOS Dashboard](assets/screenshots/dashboard.png)

### Tool Management
![Tool Toggles](assets/screenshots/tool-toggles.png)

### Analytics Panel
![Analytics](assets/screenshots/analytics.png)

### Sandbox Manager
![Sandbox](assets/screenshots/sandbox.png)

### Video Walkthrough
[![SproutOS Demo](assets/screenshots/video-thumbnail.png)](https://www.youtube.com/watch?v=YOUR_VIDEO_ID)
-->

---

## Why SproutOS?

| Problem | SproutOS Solution |
|---|---|
| AI can't see your WordPress files | Full filesystem read/write access within boundaries |
| Theme editing requires manual SSH | AI reads, edits, and updates theme files directly |
| Creating pages is slow and repetitive | AI creates and updates pages via MCP tools |
| No way to run diagnostics remotely | Execute PHP with full WordPress context + telemetry |
| Fear of AI breaking your site | Sandbox environment, Safe Mode, crash recovery, and per-tool toggles |
| Plugin bloat and overhead | Lightweight, modular - loads only what you enable |

---

## Available Tools

SproutOS exposes **20+ MCP tools** organized into modular categories:

### Content Management
| Tool | Description |
|---|---|
| `sprout/create-page` | Create WordPress pages with title, content, and status |
| `sprout/update-page` | Update existing page content, title, or status |

### Filesystem Operations
| Tool | Description |
|---|---|
| `sprout/read-file` | Read any file with byte-range or line-range selection, binary detection, and rich metadata |
| `sprout/write-file` | Write files to the WordPress filesystem (PHP files restricted to sandbox) |
| `sprout/edit-file` | Edit existing files with targeted modifications |
| `sprout/delete-file` | Delete files with safety checks |
| `sprout/directory-list` | List directory contents with metadata |

### Theme Management
| Tool | Description |
|---|---|
| `sprout/list-theme-files` | Browse active theme and child theme file structure |
| `sprout/read-theme-file` | Read theme template files |
| `sprout/update-theme-file` | Modify theme files (functions.php, templates, etc.) |
| `sprout/update-theme-stylesheet` | Edit theme CSS directly |

### Code Execution
| Tool | Description |
|---|---|
| `sprout/execute-php` | Run PHP in the live WordPress environment with full API access, configurable timeout (1-120s), query capture, and execution telemetry |
| `sprout/batch-execute` | Execute multiple operations in sequence |

### Sandbox Management
| Tool | Description |
|---|---|
| `sprout/sandbox-enable` | Enable a sandbox PHP file for execution |
| `sprout/sandbox-disable` | Disable a sandbox file without deleting it |
| `sprout/manage-modules` | Enable or disable entire tool categories |

### Bridge / Discovery
| Tool | Description |
|---|---|
| `sprout-bridge/discover-tools` | List all available MCP tools dynamically |
| `sprout-bridge/inspect-tool` | Get detailed schema and metadata for any tool |
| `sprout-bridge/dispatch-tool` | Execute any registered tool by name |

### Ecosystem Integrations

SproutOS also registers tool categories for other POSIMYTH products when detected:

- **Nexter Extension** - Code snippets, theme builder, security & performance settings, SMTP, image optimization, custom fonts
- **WDesignKit** - Custom widget builder, widget management, template deployment
- **Elementor** - Page building, container management, widget placement (when Elementor is active)
- **The Plus Addons** - 120+ advanced Elementor widgets exposed as MCP tools

---

## Installation

### Requirements

- WordPress 6.5 or higher
- PHP 8.0 or higher
- [WordPress Abilities API](https://github.com/WordPress/abilities-api) plugin (required)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin (bundled with SproutOS)

### Step 1: Install SproutOS

**Option A: Download from GitHub**

1. Download the latest release from the [Releases page](https://github.com/posimyth/sprout-os/releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin

**Option B: Clone from Git**

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/posimyth/sprout-os.git
```

Then activate from **Plugins** in your WordPress admin.

### Step 2: Install the WordPress Abilities API

SproutOS requires the WordPress Abilities API to function:

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/WordPress/abilities-api.git
```

Activate from the WordPress admin. The MCP Adapter is already bundled inside SproutOS (`sprout-libs/wordpress/mcp-adapter/`).

### Step 3: Enable SproutOS

1. Go to **SproutOS** in the WordPress admin sidebar
2. Toggle **AI Abilities** to ON
3. Configure which module groups and individual tools to enable
4. Set up your analytics and notification preferences

---

## Connecting to AI Clients

SproutOS supports two transport methods: **STDIO** (local sites) and **HTTP** (remote/production sites).

### Claude Code (STDIO - Local Development)

Add to your Claude Code MCP config (`~/.claude/claude_desktop_config.json` or project `.mcp.json`):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=admin"
      ]
    }
  }
}
```

### Claude Desktop / Cursor / VS Code (STDIO)

```json
{
  "mcpServers": {
    "sproutos": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=admin"
      ]
    }
  }
}
```

### Remote / Production Sites (HTTP Transport)

For sites where WP-CLI isn't available locally, use the HTTP proxy:

```json
{
  "mcpServers": {
    "wordpress-remote": {
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote@latest"
      ],
      "env": {
        "WP_API_URL": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

> **Note:** For HTTP transport, create an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) in **Users > Profile > Application Passwords** in your WordPress admin.

### Verify Connection

After configuring your client, test the connection:

```bash
# Via WP-CLI
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | \
  wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# Or list all MCP servers
wp mcp-adapter list
```

---

## Sandbox

The sandbox is a protected directory at `wp-content/sproutos-mcp-sandbox/` where AI agents can write and execute PHP code safely.

### How It Works

1. **Isolated directory** - PHP files written by AI go into the sandbox, not your theme or plugin directories
2. **Syntax validation** - Every file is linted before execution using PHP's tokenizer
3. **Symbol conflict detection** - Checks for class/function name collisions with existing plugins
4. **Crash recovery** - If a sandbox file causes a fatal error, SproutOS automatically:
   - Identifies the crashed file
   - Disables it (renames to `.disabled`)
   - Shows an admin notice
   - Keeps all other sandbox files running
5. **Safe Mode** - If a crash can't be isolated, Safe Mode suspends ALL sandbox files until you fix the issue
6. **Validation caching** - Files are only re-validated when their content changes (SHA-256 based)
7. **Project support** - Organize multi-file sandbox projects in subdirectories

### Sandbox Controls

From the **SproutOS > Sandbox** tab in WordPress admin:

- Enable/disable the sandbox globally
- View all sandbox files with their status (active, disabled, syntax error)
- View source code of any sandbox file
- Toggle individual files on/off
- Clear crashed state

---

## Safety & Controls

SproutOS is built with a defense-in-depth approach:

### Access Control
- **Administrator only** - All MCP operations require `manage_options` capability
- **Application Passwords** - HTTP transport uses WordPress Application Passwords for authentication
- **Per-tool permissions** - Each tool verifies user capabilities before executing

### Modular Architecture
- **6 module groups** that can be independently enabled/disabled:
  - WordPress (pages, themes, filesystem)
  - Elementor
  - The Plus Addons
  - Nexter Extension
  - Nexter Blocks
  - WDesignKit
- **Individual tool toggles** - Disable any specific tool from the admin UI
- **Bulk enable/disable** - Quickly toggle all tools in a category

### Safe Mode
- **One-click Safe Mode** - Instantly switches to read-only: all write/destructive tools are disabled, only read operations remain
- Automatically saves your previous configuration and restores it when Safe Mode is turned off

### File Protection
SproutOS blocks AI agents from touching critical files regardless of permissions:

- **Environment files** - `.env`, `.env.local`, `.env.production`, etc.
- **WordPress core config** - `wp-config.php`, `.htaccess`, `web.config`
- **System files** - `debug.log`, `db.php`, `object-cache.php`, `advanced-cache.php`
- **Version control** - `.git/`, `.svn/`, `.hg/` directories
- **SQL dumps** - Any `.sql` file at any depth
- **Symlink rejection** - Symlinks are blocked to prevent directory traversal

### Path Boundary Enforcement
- All file operations are verified to stay within the WordPress installation root (`ABSPATH`)
- PHP file writes are restricted to the sandbox directory
- Path traversal attacks (`../`) are caught by `realpath()` resolution

### Analytics & Monitoring
- **Usage logging** - Track every MCP tool call with timing, status, and error details
- **Configurable log levels** - All, errors only, or off
- **Retention controls** - Set how long logs are kept (auto-cleanup via cron)
- **Email notifications** - Get notified per-session or daily digest
- **Webhook integration** - Send events to Slack, Discord, or any webhook endpoint
- **Privacy controls** - Choose whether to store IP addresses, user identity, request/response bodies

### Server Instructions
Control what environment information is shared with AI clients:
- WordPress version, PHP version, active theme
- Elementor version, active plugins list
- Custom instruction text

---

## Use Cases

### 1. Instant Theme Customization
> "Update my child theme's `functions.php` to add a custom post type for testimonials, then create a single template for it."

The AI reads your theme structure, modifies `functions.php`, creates template files, and updates your stylesheet - all in one conversation.

### 2. Bulk Content Creation
> "Create 10 landing pages for each of our service areas. Use this template structure and vary the content for each city."

AI creates all pages programmatically with proper titles, content, and status - minutes instead of hours.

### 3. Database Diagnostics & Cleanup
> "Check if there are any orphaned postmeta entries and tell me how much space they're using. Then clean them up."

Using `execute-php`, the AI runs WP_Query and `$wpdb` operations with full telemetry including query count, execution time, and memory usage.

### 4. Performance Auditing
> "Analyze my site's autoloaded options, find the largest ones, and identify which plugin created them."

AI executes PHP to query `wp_options`, calculates sizes, traces origins, and gives you an actionable report.

### 5. Security Hardening
> "Check all my theme files for any direct database queries that aren't using prepared statements. List every file and line number."

AI reads every theme file, scans for `$wpdb->query()` without `$wpdb->prepare()`, and produces a vulnerability report.

### 6. Plugin Conflict Debugging
> "I'm getting a white screen after activating plugin X. Help me figure out what's conflicting."

AI reads error logs, checks active plugins, examines hooks and filters, and uses `execute-php` to test specific function calls in isolation.

### 7. WooCommerce Store Management
> "Show me all orders from the last 30 days that are still processing, and generate a CSV summary."

AI uses `execute-php` with full WooCommerce API access to query orders, format data, and write a CSV to the sandbox.

### 8. Automated Backup Verification
> "Check the sizes of my database tables, list any tables that aren't using InnoDB, and verify my uploads directory structure."

AI runs diagnostic queries and filesystem checks, presenting a comprehensive site health report.

### 9. Custom Admin Dashboard
> "Create a custom admin dashboard widget that shows our top 5 posts by views this month with a sparkline chart."

AI writes a complete sandbox plugin with WordPress hooks, admin widgets, and data queries - testable immediately.

### 10. Migration Preparation
> "I'm migrating from my.oldsite.com to my.newsite.com. Scan the database for all hardcoded URLs and tell me what needs updating."

AI runs targeted SQL queries to find serialized data, option values, and post content with hardcoded URLs, giving you a complete migration checklist.

---

## Architecture

```
sprout-os/
├── sprout-os.php                          # Main plugin bootstrap
├── sprout-core/
│   ├── plugin_loader.php                  # Singleton loader with conditional file loading
│   ├── dependencies.php                   # Feature flags, settings, permissions, server instructions
│   ├── filesystem-helpers.php             # Path resolution, sandbox enforcement, sensitive file guards
│   ├── admin-pages.php                    # Full admin UI (loaded on-demand, not on every request)
│   ├── class-sprout-mcp-analytics.php     # Usage tracking, notifications, webhooks
│   ├── abilities_register/                # Module-based ability registration
│   ├── abilities/
│   │   ├── bridge/                        # Discovery, inspection, dispatch
│   │   ├── wordpress/                     # Page CRUD
│   │   ├── theme/                         # Theme file operations
│   │   ├── ops/                           # Filesystem, code execution, sandbox controls
│   │   └── filesystem/                    # Module management, batch operations
│   └── sandbox/                           # Sandbox loader with strategy pattern
│       ├── bootstrap.php
│       ├── class-sprout-mcp-sandbox-loader.php    # Preflight > Discovery > Validation > Execution
│       └── class-sprout-mcp-sandbox-helper.php    # Linting, crash recovery, conflict detection
├── sprout-libs/
│   └── wordpress/mcp-adapter/             # Bundled MCP Adapter (MCP 2025-06-18 spec)
└── sprout-ui/                             # Admin CSS and assets
```

### Performance Design

- **Conditional loading** - Admin UI (2,950+ lines) only loads on SproutOS pages and AJAX handlers
- **Analytics bypass** - When tracking is off, the analytics class isn't loaded on frontend/REST requests
- **Request caching** - Settings and ability states cached per-request with static variables
- **Validation caching** - Sandbox files use SHA-256 sidecar files to skip re-linting unchanged code
- **Cron-based cleanup** - Log rotation runs via WordPress cron, never inline

---

## Built by POSIMYTH

SproutOS is built by the [POSIMYTH](https://posimyth.com) team, the same team powering **500,000+ WordPress websites** with:

- **[The Plus Addons for Elementor](https://theplusaddons.com)** - 120+ Elementor widgets, 1000+ templates
- **[Nexter Theme](https://nexterwp.com)** - Lightweight starter theme for Elementor
- **[Nexter Extension](https://nexterwp.com)** - 50+ WordPress extensions for performance, security, and code snippets
- **[Nexter Blocks](https://nexterwp.com)** - 90+ Gutenberg blocks
- **[WDesignKit](https://wdesignkit.com)** - Widget builder, template cloud, and widget converter
- **[UiChemy](https://uichemy.com)** - Figma to Elementor converter

SproutOS integrates natively with all these products - when detected on your site, their capabilities are automatically exposed as additional MCP tools.

This means **production-grade support**, regular updates, and a team that deeply understands the WordPress ecosystem.

---

## FAQ

<details>
<summary><strong>What is MCP (Model Context Protocol)?</strong></summary>

MCP is an open standard created by Anthropic that lets AI agents interact with external tools and data sources through a unified protocol. SproutOS implements the MCP 2025-06-18 specification, making your WordPress site accessible to any MCP-compatible AI client.
</details>

<details>
<summary><strong>Does SproutOS contact external services?</strong></summary>

No. The core plugin makes zero external requests. Webhook notifications are optional and only activate when you explicitly configure a webhook URL in the admin settings.
</details>

<details>
<summary><strong>Is this safe for production sites?</strong></summary>

SproutOS includes multiple safety layers: sandbox isolation, Safe Mode (one-click read-only), sensitive file protection, path boundary enforcement, and per-tool toggles. That said, tools like `execute-php` and filesystem writes are powerful by design. On production sites, we recommend enabling only the tools you need and using Safe Mode when you're not actively working with AI.
</details>

<details>
<summary><strong>What happens when I deactivate or delete SproutOS?</strong></summary>

**Deactivation** cleans up scheduled cron jobs (analytics cleanup, daily digest). All settings and data are preserved.

**Deletion** (via WordPress admin) removes everything: plugin options, the analytics database table, transients, cron jobs, and the entire sandbox directory (`wp-content/sproutos-mcp-sandbox/`). This is a clean uninstall.
</details>

<details>
<summary><strong>Does it work with any AI client?</strong></summary>

Yes. Any MCP-compatible client works, including Claude Desktop, Claude Code, Cursor, VS Code (with MCP extensions), Windsurf, Cline, and any custom client implementing the MCP 2025-06-18 specification. SproutOS supports both STDIO transport (local) and HTTP transport (remote).
</details>

<details>
<summary><strong>Can multiple AI clients connect simultaneously?</strong></summary>

Yes. Each connection gets its own session through the MCP transport layer. Multiple team members can use different AI clients against the same WordPress site at the same time.
</details>

<details>
<summary><strong>Does SproutOS work with WordPress Multisite?</strong></summary>

SproutOS is designed for single-site installations. Multisite support is not officially tested yet. If you install it on a multisite network, it will operate on the site where it's activated, but network-wide behavior has not been validated.
</details>

<details>
<summary><strong>What's the difference between the GitHub version and the WordPress.org version?</strong></summary>

SproutOS is maintained as one core product. Feature availability depends on the plugin build and release policy you choose for your site.
</details>

<details>
<summary><strong>Do I need WP-CLI installed?</strong></summary>

WP-CLI is required only for **STDIO transport** (local development). If you're connecting to a remote/production site, you can use **HTTP transport** instead, which only requires an Application Password - no WP-CLI needed on the server.
</details>

<details>
<summary><strong>What PHP version do I need?</strong></summary>

PHP 8.0 or higher is required. SproutOS uses typed properties, union types, named arguments, and other PHP 8.0+ features throughout the codebase.
</details>

<details>
<summary><strong>Can I restrict which files AI can access?</strong></summary>

Yes, in multiple ways:
- **Sensitive file guard** automatically blocks access to `.env`, `wp-config.php`, `.htaccess`, `debug.log`, `.git/`, `.sql` files, and more
- **Path boundary enforcement** prevents any operation outside your WordPress root
- **Sandbox enforcement** restricts PHP writes to the sandbox directory only
- **Individual tool toggles** let you disable specific filesystem tools entirely
- **Safe Mode** disables all write operations with one click
</details>

<details>
<summary><strong>How do I monitor what AI agents are doing on my site?</strong></summary>

SproutOS includes built-in analytics that track every MCP tool call:
- View usage logs in the **SproutOS > Analytics** tab
- Configure log levels: all calls, errors only, or off
- Enable email notifications per session or as a daily digest
- Set up webhook notifications to send events to Slack, Discord, or any endpoint
- Optionally store full request/response bodies for debugging
</details>

<details>
<summary><strong>Is the sandbox directory publicly accessible?</strong></summary>

The sandbox directory (`wp-content/sproutos-mcp-sandbox/`) follows standard WordPress content directory permissions. If your server is configured correctly, PHP files in `wp-content` subdirectories should not be directly executable via URL. For additional security, you can add an `.htaccess` deny rule or configure your web server to block direct access to this directory.
</details>

<details>
<summary><strong>Can I extend SproutOS with my own custom tools?</strong></summary>

Yes. SproutOS is built on the WordPress Abilities API. Any plugin can register new abilities using `wp_register_ability()`, and they'll automatically appear as MCP tools. You can also write custom tools as sandbox files. See the [WordPress Abilities API documentation](https://github.com/WordPress/abilities-api) for details.
</details>

<details>
<summary><strong>What happens if a sandbox file crashes my site?</strong></summary>

SproutOS has automatic crash recovery:
1. A shutdown handler detects the fatal error and identifies the crashed file
2. The crashed file is automatically disabled (renamed to `.disabled`)
3. An admin notice shows which file was disabled and why
4. All other sandbox files continue running normally
5. If the crash can't be isolated to a single file, **Safe Mode** activates and suspends all sandbox files until you fix the issue

Your site will recover automatically on the next page load.
</details>

<details>
<summary><strong>Where can I get help or report issues?</strong></summary>

- **Documentation**: [sproutos.documentationai.com/wordpress-plugin](https://sproutos.documentationai.com/wordpress-plugin)
- **GitHub Issues**: [github.com/posimyth/sprout-os/issues](https://github.com/posimyth/sprout-os/issues) for bug reports and feature requests
- **Support**: [store.posimyth.com/helpdesk/](https://store.posimyth.com/helpdesk/) for direct support from the POSIMYTH team
- **Security issues**: See [SECURITY.md](SECURITY.md) for responsible disclosure
</details>

---

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to get started.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes across versions.

---

## Security

Found a vulnerability? Please see [SECURITY.md](SECURITY.md) for our responsible disclosure policy.

---

## License

[GPL-2.0-or-later](LICENSE) - Same as WordPress itself.

---

<p align="center">
  Made with care by <a href="https://posimyth.com">POSIMYTH</a>
</p>
