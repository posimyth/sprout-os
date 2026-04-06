=== Sprout MCP ===
Contributors: posimyth
Tags: mcp, ai, wordpress, automation, sandbox
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to MCP clients with controlled AI abilities, sandboxed custom tools, analytics, and admin controls.

== Description ==

Sprout MCP is a foundation plugin that connects your WordPress site with MCP (Model Context Protocol) clients such as Claude and other AI tools that support MCP.

It exposes selected WordPress abilities through a controlled interface so AI clients can inspect content, create or update pages, work with files in a sandboxed environment, and interact with site tools more safely.

Sprout MCP is designed for sites that want practical AI workflows without giving unrestricted access to WordPress internals.

= Key Features =

* Connect WordPress to MCP-compatible AI clients.
* Enable or disable ability modules from the admin panel.
* Manage AI abilities with Safe Mode support.
* Run custom sandbox abilities from `wp-content/sproutos-mcp-sandbox/`.
* Automatically detect and isolate broken sandbox files when possible.
* Track analytics for MCP activity, usage, and errors.
* Configure email and webhook notifications for MCP events.
* Control what server and environment details are shared with connected AI clients.
* Support WordPress, theme, filesystem, and bridge-style abilities.

= Included Admin Areas =

* MCP Connect
* AI Abilities
* Sandbox
* Analytics
* Settings

== Installation ==

1. Upload the `sprout-os` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `SproutOS` from the WordPress admin menu.
4. Configure your MCP connection settings.
5. Enable only the ability modules you want to expose.
6. If needed, add custom sandbox abilities inside `wp-content/sproutos-mcp-sandbox/`.

== Frequently Asked Questions ==

= What is MCP? =

MCP stands for Model Context Protocol. It is a protocol that allows AI clients to connect to tools and structured capabilities in external systems like WordPress.

= Which AI clients can work with this plugin? =

Any AI client that supports MCP and can connect to your WordPress MCP endpoint may be compatible. Actual setup depends on the client.

= Is there a safe mode? =

Yes. Sprout MCP includes Safe Mode controls for AI abilities and sandbox crash protection for custom sandbox files.

= Where should custom sandbox PHP files go? =

Place them inside:

`wp-content/sproutos-mcp-sandbox/`

Sandbox files are validated and loaded by the plugin when the sandbox module is enabled.

= What happens if a sandbox file breaks? =

If a sandbox file causes a fatal error, Sprout MCP attempts to isolate that file automatically. If it cannot safely isolate the source, sandbox safe mode can suspend sandbox loading until the problem is fixed.

= Can I disable specific ability groups? =

Yes. Sprout MCP includes module-level controls so you can enable or disable different groups of abilities from the admin UI.

= Does this plugin log MCP activity? =

Yes. Sprout MCP includes analytics and activity tracking, with optional notification settings such as email summaries and webhook delivery.

== Screenshots ==

1. MCP Connect screen
2. AI Abilities management screen
3. Sandbox management screen
4. Analytics dashboard
5. Settings screen

== Changelog ==

= 1.1.0 =

* Initial public release.
* Added MCP connection and admin management screens.
* Added AI ability controls and Safe Mode handling.
* Added sandbox loader, validation, and recovery flow.
* Added analytics, logging controls, and notification settings.

== Upgrade Notice ==

= 1.1.0 =

Initial release of Sprout MCP with MCP connectivity, sandbox tooling, analytics, and admin controls.
