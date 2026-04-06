<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check whether Abilities API is available.
 */
function sprout_mcp_has_abilities_api(): bool
{
    return class_exists('WP_Ability');
}

/**
 * Check whether MCP Adapter classes are available.
 */
function sprout_mcp_has_mcp_adapter(): bool
{
    return class_exists('WP\\MCP\\Core\\McpAdapter');
}

/**
 * Check whether Elementor is available.
 */
function sprout_mcp_has_elementor(): bool
{
    return did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin');
}

/**
 * Check whether AI abilities are enabled in plugin settings.
 *
 * PERFORMANCE: Result is cached for the duration of the request to avoid
 * repeated get_option() calls (sandbox-loader, admin bar, config filter).
 *
 * @param bool $flush Force a fresh read (e.g. after AJAX toggle).
 */
function sprout_mcp_is_enabled(bool $flush = false): bool
{
    static $cached = null;
    if ($cached !== null && !$flush) {
        return $cached;
    }

    $value = get_option('sprout_mcp_ai_abilities_enabled', false);
    $cached = ($value === '1' || $value === true);

    return $cached;
}

/**
 * Get all Sprout MCP module settings.
 *
 * Uses a static cache to avoid repeated get_option() calls within the same request.
 * Pass $flush = true to force a fresh read (e.g. after saving settings).
 *
 * @param bool $flush Whether to bypass the static cache.
 * @return array{sandbox_enabled: bool, modules: array<string, bool>}
 */
function sprout_mcp_get_settings(bool $flush = false): array
{
    static $cached = null;

    if (null !== $cached && !$flush) {
        return $cached;
    }
    $defaults = [
        'safe_mode_enabled' => false,
        'safe_mode_previous_disabled' => [],
        'sandbox_enabled' => true,
        'disabled_abilities' => [],
        'modules' => [
            'wordpress'        => true,
            'elementor'        => true,
            'the_plus_addons'  => true,
            'nexter_extension' => true,
            'nexter_blocks'    => true,
            'wdesignkit'       => true,
        ],
        'analytics_enabled' => true,
        'analytics_retention_days' => 30,
        'analytics_log_level' => 'all',
        'analytics_store_request' => false,
        'analytics_store_response' => false,
        'analytics_max_entries' => 5000,
        'analytics_notify_enabled' => false,
        'analytics_notify_email' => '',
        'analytics_notify_frequency' => 'off',
        'analytics_store_ip' => true,
        'analytics_anonymize_ip' => false,
        'analytics_store_user_identity' => true,
        'webhook_enabled' => false,
        'webhook_url' => '',
        'webhook_events' => 'all',
        'webhook_secret' => '',
        'server_instructions' => [
            'wp_version'        => true,
            'php_version'       => true,
            'theme_info'        => true,
            'plugins_list'      => false,
            'elementor_version' => true,
            'custom_text'       => '',
        ],
    ];

    $saved = get_option('sprout_mcp_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    $settings = $defaults;
    if (isset($saved['safe_mode_enabled'])) {
        $settings['safe_mode_enabled'] = (bool) $saved['safe_mode_enabled'];
    }
    if (isset($saved['safe_mode_previous_disabled']) && is_array($saved['safe_mode_previous_disabled'])) {
        $settings['safe_mode_previous_disabled'] = array_values(array_map('sanitize_text_field', $saved['safe_mode_previous_disabled']));
    }
    if (isset($saved['sandbox_enabled'])) {
        $settings['sandbox_enabled'] = (bool) $saved['sandbox_enabled'];
    }
    if (isset($saved['disabled_abilities']) && is_array($saved['disabled_abilities'])) {
        $settings['disabled_abilities'] = array_values(array_map('sanitize_text_field', $saved['disabled_abilities']));
    }
    if (isset($saved['modules']) && is_array($saved['modules'])) {
        foreach ($defaults['modules'] as $key => $default_val) {
            $settings['modules'][$key] = isset($saved['modules'][$key]) ? (bool) $saved['modules'][$key] : $default_val;
        }
    }
    if (isset($saved['analytics_enabled'])) {
        $settings['analytics_enabled'] = (bool) $saved['analytics_enabled'];
    }
    if (isset($saved['analytics_retention_days'])) {
        $settings['analytics_retention_days'] = max(0, (int) $saved['analytics_retention_days']);
    }
    if (isset($saved['analytics_log_level'])) {
        $allowed_levels = ['all', 'errors', 'off'];
        $level = sanitize_text_field($saved['analytics_log_level']);
        $settings['analytics_log_level'] = in_array($level, $allowed_levels, true) ? $level : 'all';
    }
    if (isset($saved['analytics_store_request'])) {
        $settings['analytics_store_request'] = (bool) $saved['analytics_store_request'];
    }
    if (isset($saved['analytics_store_response'])) {
        $settings['analytics_store_response'] = (bool) $saved['analytics_store_response'];
    }
    if (isset($saved['analytics_max_entries'])) {
        $settings['analytics_max_entries'] = max(0, (int) $saved['analytics_max_entries']);
    }
    if (isset($saved['analytics_notify_enabled'])) {
        $settings['analytics_notify_enabled'] = (bool) $saved['analytics_notify_enabled'];
    }
    if (isset($saved['analytics_notify_email'])) {
        $settings['analytics_notify_email'] = sanitize_email($saved['analytics_notify_email']);
    }
    if (isset($saved['analytics_notify_frequency'])) {
        $allowed_freq = ['off', 'session', 'daily'];
        $freq = sanitize_text_field($saved['analytics_notify_frequency']);
        $settings['analytics_notify_frequency'] = in_array($freq, $allowed_freq, true) ? $freq : 'off';
    }
    if (isset($saved['analytics_store_ip'])) {
        $settings['analytics_store_ip'] = (bool) $saved['analytics_store_ip'];
    }
    if (isset($saved['analytics_anonymize_ip'])) {
        $settings['analytics_anonymize_ip'] = (bool) $saved['analytics_anonymize_ip'];
    }
    if (isset($saved['analytics_store_user_identity'])) {
        $settings['analytics_store_user_identity'] = (bool) $saved['analytics_store_user_identity'];
    }
    if (isset($saved['webhook_enabled'])) {
        $settings['webhook_enabled'] = (bool) $saved['webhook_enabled'];
    }
    if (isset($saved['webhook_url'])) {
        $settings['webhook_url'] = esc_url_raw($saved['webhook_url']);
    }
    if (isset($saved['webhook_events'])) {
        $allowed = ['all', 'destructive', 'errors'];
        $val = sanitize_text_field($saved['webhook_events']);
        $settings['webhook_events'] = in_array($val, $allowed, true) ? $val : 'all';
    }
    if (isset($saved['webhook_secret'])) {
        $settings['webhook_secret'] = sanitize_text_field($saved['webhook_secret']);
    }
    if (isset($saved['server_instructions']) && is_array($saved['server_instructions'])) {
        foreach ($defaults['server_instructions'] as $key => $default_val) {
            if ($key === 'custom_text') {
                $settings['server_instructions'][$key] = isset($saved['server_instructions'][$key])
                    ? sanitize_textarea_field($saved['server_instructions'][$key])
                    : $default_val;
            } else {
                $settings['server_instructions'][$key] = isset($saved['server_instructions'][$key])
                    ? (bool) $saved['server_instructions'][$key]
                    : $default_val;
            }
        }
    }

    $cached = $settings;

    return $settings;
}

/**
 * Check whether a specific ability is disabled by the user.
 *
 * @param string $ability_name Fully qualified ability name, e.g. 'sprout/delete-file'.
 */
function sprout_mcp_is_ability_disabled(string $ability_name): bool
{
    $settings = sprout_mcp_get_settings();
    return in_array($ability_name, $settings['disabled_abilities'], true);
}

/**
 * Check whether Safe Mode (read-only) is active.
 */
function sprout_mcp_is_safe_mode(): bool
{
    $settings = sprout_mcp_get_settings();
    return $settings['safe_mode_enabled'] ?? false;
}

/**
 * Determine if an ability is read-only using WP Abilities API annotations + name heuristics.
 *
 * @param string $ability_name Fully qualified ability name.
 * @return bool True if the ability only reads data.
 */
function sprout_mcp_is_ability_readonly(string $ability_name): bool
{
    if (function_exists('wp_get_ability')) {
        $ability = wp_get_ability($ability_name);
        if ($ability instanceof WP_Ability) {
            $meta = $ability->get_meta();
            $readonly = $meta['annotations']['readonly'] ?? null;
            if ($readonly === true) {
                return true;
            }
            if ($readonly === false) {
                return false;
            }
        }
    }

    // Fallback: name-pattern heuristic for abilities with null annotations.
    return (bool) preg_match('/(get-|list-|read-|find-|search-|export-|discover|info|schema)/', $ability_name);
}

/**
 * Toggle Safe Mode on or off. Saves/restores previous disabled_abilities.
 *
 * @param bool $enable True to enable safe mode, false to disable.
 */
function sprout_mcp_toggle_safe_mode(bool $enable): void
{
    $saved = get_option('sprout_mcp_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    if ($enable) {
        // Save current disabled list so we can restore it later.
        $saved['safe_mode_previous_disabled'] = $saved['disabled_abilities'] ?? [];

        // Build list of ALL non-readonly abilities to disable.
        $disable = [];
        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                if (!($ability instanceof WP_Ability)) {
                    continue;
                }
                $name = $ability->get_name();
                if (!sprout_mcp_is_ability_readonly($name)) {
                    $disable[] = $name;
                }
            }
        }

        $saved['disabled_abilities'] = $disable;
        $saved['safe_mode_enabled'] = true;
    } else {
        // Restore previous disabled list.
        $saved['disabled_abilities'] = $saved['safe_mode_previous_disabled'] ?? [];
        $saved['safe_mode_previous_disabled'] = [];
        $saved['safe_mode_enabled'] = false;
    }

    update_option('sprout_mcp_settings', $saved);
    // Flush cache.
    sprout_mcp_get_settings(true);
}

/**
 * Check whether the sandbox loader is enabled.
 */
function sprout_mcp_is_sandbox_enabled(): bool
{
    $settings = sprout_mcp_get_settings();
    return $settings['sandbox_enabled'];
}

/**
 * Check whether a specific ability module is enabled.
 *
 * @param string $module One of: wordpress, elementor, the_plus_addons, nexter_extension, nexter_blocks
 */
function sprout_mcp_is_module_enabled(string $module): bool
{
    $settings = sprout_mcp_get_settings();
    return $settings['modules'][$module] ?? false;
}

/**
 * Permission callback shared by MCP abilities.
 */
function sprout_mcp_permission_callback(): bool
{
    return current_user_can('manage_options');
}

/**
 * Permission callback for Elementor page/post editing abilities.
 *
 * This must be available before any design/layout abilities are registered,
 * otherwise WordPress 6.9+ will reject the string callback as non-callable.
 *
 * @param array<string, mixed>|null $input Ability input arguments.
 */
function sprout_mcp_elementor_post_permission(?array $input = null): bool
{
    if (!current_user_can('edit_posts')) {
        return false;
    }

    $post_id = absint($input['post_id'] ?? 0);
    if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
        return false;
    }

    return true;
}

/**
 * Render dependency notices in wp-admin.
 */
function sprout_mcp_render_dependency_notices(): void
{
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (!sprout_mcp_has_abilities_api()) {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('SproutOS MCP requires the WordPress Abilities API. Please install and activate it to enable AI abilities.', 'sprout-os')
            . '</p></div>';
    }

    if (!sprout_mcp_has_mcp_adapter()) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('SproutOS MCP could not locate the MCP Adapter. Please install and activate the MCP Adapter plugin, or ensure it is bundled in the vendor directory.', 'sprout-os')
            . '</p></div>';
    }

}

/**
 * Build server instructions text from enabled toggles.
 */
function sprout_mcp_build_server_instructions(): string
{
    $settings = sprout_mcp_get_settings();
    $si = $settings['server_instructions'];
    $lines = [];

    $lines[] = 'You are connected to a WordPress site via Sprout MCP (SproutOS).';
    $lines[] = 'IMPORTANT: Always use sprout-* tools (NOT use another-* tools). Write PHP files to the Sprout sandbox: wp-content/sproutos-mcp-sandbox/';
    $lines[] = 'Sandbox directory: ' . SPROUT_MCP_SANDBOX_DIR;

    if ($si['wp_version']) {
        $lines[] = 'WordPress version: ' . get_bloginfo('version');
    }
    if ($si['php_version']) {
        $lines[] = 'PHP version: ' . PHP_VERSION;
    }
    if ($si['theme_info']) {
        $theme = wp_get_theme();
        $lines[] = 'Active theme: ' . $theme->get('Name') . ' ' . $theme->get('Version');
        if ($theme->parent()) {
            $lines[] = 'Parent theme: ' . $theme->parent()->get('Name') . ' ' . $theme->parent()->get('Version');
        }
    }
    if ($si['elementor_version'] && defined('ELEMENTOR_VERSION')) {
        $lines[] = 'Elementor version: ' . ELEMENTOR_VERSION;
    }
    if ($si['plugins_list']) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = get_option('active_plugins', []);
        $plugins = get_plugins();
        $active_names = [];
        foreach ($active as $plugin_file) {
            if (isset($plugins[$plugin_file])) {
                $active_names[] = $plugins[$plugin_file]['Name'] . ' ' . $plugins[$plugin_file]['Version'];
            }
        }
        if ($active_names) {
            $lines[] = 'Active plugins: ' . implode(', ', $active_names);
        }
    }
    if (trim($si['custom_text']) !== '') {
        $lines[] = trim($si['custom_text']);
    }

    return implode("\n", $lines);
}

/**
 * Bootstrap MCP Adapter when dependencies are ready.
 */
function sprout_mcp_bootstrap_mcp_adapter(): void
{
    if (!sprout_mcp_has_abilities_api() || !sprout_mcp_has_mcp_adapter()) {
        return;
    }

    \WP\MCP\Core\McpAdapter::instance();
}

// -- Ability Source Tracking -------------------------------------------------

/**
 * Global map: ability_name => plugin file path.
 * Populated by the wp_register_ability_args filter.
 *
 * @var array<string, string>
 */
global $sprout_mcp_ability_sources;
$sprout_mcp_ability_sources = [];

/**
 * Filter: record which plugin file registered each ability.
 *
 * PERFORMANCE: Uses debug_backtrace() which is expensive. This data is only
 * needed for the admin UI (ability source badges), so the filter is only
 * registered during admin requests. On frontend and REST (MCP) requests,
 * the backtrace is skipped entirely - zero overhead.
 */
if (is_admin()) {
    add_filter('wp_register_ability_args', function (array $args, string $name): array {
        global $sprout_mcp_ability_sources;

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $plugins_dir = WP_PLUGIN_DIR . '/';

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && str_starts_with($file, $plugins_dir)) {
                $relative = substr($file, strlen($plugins_dir));
                $slash = strpos($relative, '/');
                if ($slash !== false) {
                    $sprout_mcp_ability_sources[$name] = substr($relative, 0, $slash);
                }
                break;
            }
        }

        return $args;
    }, 1, 2);
}

/**
 * Get the plugin slug that registered a given ability.
 *
 * @param string $ability_name Fully-qualified ability name.
 * @return string Plugin directory slug, or '' if unknown.
 */
function sprout_mcp_get_ability_source(string $ability_name): string
{
    global $sprout_mcp_ability_sources;
    return $sprout_mcp_ability_sources[$ability_name] ?? '';
}

/**
 * Get display-friendly plugin info from a plugin directory slug.
 * Returns ['name' => 'Human Name', 'slug' => 'dir-slug', 'version' => '1.0.0'].
 *
 * @param string $plugin_slug Plugin directory name.
 * @return array{name: string, slug: string, version: string}
 */
function sprout_mcp_get_plugin_info(string $plugin_slug): array
{
    static $cache = [];

    if (isset($cache[$plugin_slug])) {
        return $cache[$plugin_slug];
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    foreach ($all_plugins as $file => $data) {
        if (str_starts_with($file, $plugin_slug . '/')) {
            $cache[$plugin_slug] = [
                'name'    => $data['Name'] ?? $plugin_slug,
                'slug'    => $plugin_slug,
                'version' => $data['Version'] ?? '',
            ];
            return $cache[$plugin_slug];
        }
    }

    // Fallback: humanize the slug.
    $cache[$plugin_slug] = [
        'name'    => ucwords(str_replace(['-', '_'], ' ', $plugin_slug)),
        'slug'    => $plugin_slug,
        'version' => '',
    ];
    return $cache[$plugin_slug];
}
