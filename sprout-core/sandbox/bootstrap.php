<?php
/**
 * SproutOS MCP - Sandbox Loader Bootstrap
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/sandbox/class-sprout-mcp-sandbox-helper.php';
require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/sandbox/class-sprout-mcp-sandbox-loader.php';

/**
 * Boot sandbox loader in controlled contexts.
 *
 * Prevents frontend crash loops from sandbox file mistakes.
 */
add_action('init', static function (): void {
    $is_rest = defined('REST_REQUEST') && REST_REQUEST;
    $is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
    $is_cli  = defined('WP_CLI') && WP_CLI;

    if (!is_admin() && !$is_rest && !$is_ajax && !$is_cli) {
        return;
    }

    Sprout_MCP_Sandbox_Loader::boot();
}, 20);
