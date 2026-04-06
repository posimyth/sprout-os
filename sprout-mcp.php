<?php
/**
 * Plugin Name: Sprout MCP
 * Plugin URI: https://posimyth.com/sproutos
 * Description: Foundation plugin for connecting WordPress with MCP clients like Claude.
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Posimyth
 * License: GPL-2.0-or-later
 * Text Domain: sprout-os
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SPROUT_MCP_VERSION', '1.1.0');
define('SPROUT_MCP_PLUGIN_FILE', __FILE__);
define('SPROUT_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPROUT_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPROUT_MCP_URL', plugins_url('/', SPROUT_MCP_PLUGIN_FILE));
define('SPROUT_MCP_MAX_EXECUTION_TIME', 30);
define('SPROUT_MCP_SANDBOX_DIR', WP_CONTENT_DIR . '/sproutos-mcp-sandbox/');

/**
 * On activation: create sandbox directory and analytics DB table.
 * This avoids doing filesystem/DB checks on every page load.
 */
register_activation_hook(__FILE__, static function (): void {
    wp_mkdir_p(SPROUT_MCP_SANDBOX_DIR);

    // Pre-create the analytics table so it's ready before first use.
    require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/dependencies.php';
    require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/class-sprout-mcp-analytics.php';
    Sprout_MCP_Analytics::maybe_create_table();
});

/**
 * On deactivation: clean up scheduled cron events.
 */
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

// Sandbox loader - only create dir if it doesn't exist (skip filesystem call when it does).
if (!is_dir(SPROUT_MCP_SANDBOX_DIR)) {
    wp_mkdir_p(SPROUT_MCP_SANDBOX_DIR);
}

require SPROUT_MCP_PLUGIN_DIR . 'sprout-core/sandbox/bootstrap.php';
