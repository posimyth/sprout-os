<?php
/**
 * Plugin Name: SproutOS
 * Plugin URI: https://sproutos.ai
 * Description: AI-powered WordPress workflow tools with admin controls, analytics, notifications, and safer advanced site management.
 * Version: 0.0.1
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Posimyth
 * Author URI: https://posimyth.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sprout-os
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SPROUT_MCP_WPORG_SAFE_BUILD')) {
    define('SPROUT_MCP_WPORG_SAFE_BUILD', true);
}

define('SPROUT_MCP_VERSION', '0.0.1');
define('SPROUT_MCP_PLUGIN_FILE', __FILE__);
define('SPROUT_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPROUT_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPROUT_MCP_URL', plugins_url('/', SPROUT_MCP_PLUGIN_FILE));
define('SPROUT_MCP_MAX_EXECUTION_TIME', 30);
define('SPROUT_MCP_SANDBOX_DIR', WP_CONTENT_DIR . '/sproutos-mcp-sandbox/');

register_activation_hook(__FILE__, static function (): void {
    wp_mkdir_p(SPROUT_MCP_SANDBOX_DIR);

    require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/dependencies.php';
    require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/class-sprout-mcp-analytics.php';
    Sprout_MCP_Analytics::maybe_create_table();
});

register_deactivation_hook(__FILE__, static function (): void {
    $timestamp = wp_next_scheduled('sprout_mcp_analytics_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'sprout_mcp_analytics_cleanup');
    }
    $timestamp = wp_next_scheduled('sprout_mcp_daily_digest');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'sprout_mcp_daily_digest');
    }
});

require SPROUT_MCP_PLUGIN_DIR . 'sprout-core/plugin_loader.php';

if (!is_dir(SPROUT_MCP_SANDBOX_DIR)) {
    wp_mkdir_p(SPROUT_MCP_SANDBOX_DIR);
}

require SPROUT_MCP_PLUGIN_DIR . 'sprout-core/sandbox/bootstrap.php';
