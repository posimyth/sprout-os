<?php
/**
 * Core plugin bootstrap class.
 *
 * SECURITY: All admin UI, settings, and management features require
 * the 'manage_options' capability (Administrator role only).
 * No plugin admin features are accessible to other user roles.
 *
 * PERFORMANCE: Files are loaded conditionally based on context:
 * - Frontend: Only dependencies, filesystem helpers, and analytics hooks (if enabled)
 * - Admin: Full admin pages, analytics table check, cron scheduling
 * - REST API: Dependencies, filesystem helpers, analytics logging
 *
 * @link    https://posimyth.com/
 * @since   1.0.0
 * @package Sprout_MCP
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin loader - singleton.
 *
 * @since 1.0.0
 */
final class sprout_mcp_Load
{
    /** @var self|null */
    private static $instance;

    /** @var bool Whether admin-pages.php has been loaded. */
    private bool $admin_files_loaded = false;

    /**
     * Get Singleton Instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register bootstrap hooks and load required files.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        // Load core files needed on ALL requests (lightweight).
        $this->load_core_files();

        // MCP adapter bootstrap - needed on REST requests.
        add_action('plugins_loaded', 'sprout_mcp_bootstrap_mcp_adapter');

        // MCP server config filter - only fires during MCP REST requests.
        add_filter('mcp_adapter_default_server_config', [$this, 'sprout_extend_mcp_server_config']);

        // Fix empty schema properties - only fires during REST responses.
        add_filter('rest_pre_echo_response', [$this, 'sprout_fix_empty_schema_properties']);

        // Admin-only hooks - nothing here runs on frontend.
        if (is_admin()) {
            // PERFORMANCE: Defer loading the 2,950-line admin-pages.php until
            // we actually need it - either on our own plugin page, or for AJAX
            // handlers that reference functions defined there.
            add_action('admin_menu', function (): void {
                $this->load_admin_files();
                sprout_mcp_register_admin_menu();
            });

            add_action('admin_notices', 'sprout_mcp_render_dependency_notices');

            add_action('admin_init', function (): void {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
                if ( $page !== 'sprout-os' ) {
                    return;
                }
                // Load admin-pages.php on-demand - admin_init fires before admin_menu.
                $this->load_admin_files();
                if ($tab === 'connect') {
                    sprout_mcp_handle_revoke_password();
                }
                if ($tab === 'sandbox') {
                    sprout_mcp_handle_sandbox_actions();
                }
            });

            // AJAX handlers - load admin-pages.php on-demand for the callbacks.
            $ajax_loader = function (): void {
                $this->load_admin_files();
            };

            add_action('wp_ajax_sprout_mcp_view_sandbox_source', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_view_sandbox_source();
            });
            add_action('wp_ajax_sprout_mcp_toggle_safe_mode', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_toggle_safe_mode();
            });
            add_action('wp_ajax_sprout_mcp_test_webhook', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_test_webhook();
            });
            add_action('wp_ajax_sprout_mcp_get_session_entries', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_get_session_entries();
            });
            add_action('wp_ajax_sprout_mcp_toggle_ability', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_toggle_ability();
            });
            add_action('wp_ajax_sprout_mcp_bulk_toggle_abilities', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_bulk_toggle_abilities();
            });
            add_action('wp_ajax_sprout_mcp_save_setting', function () use ($ajax_loader): void {
                $ajax_loader();
                sprout_mcp_ajax_save_setting();
            });
        }

        // Admin bar indicator - only for logged-in admins with admin bar visible.
        // These hooks self-check current_user_can('manage_options') inside the callback,
        // so they safely no-op for non-admins and logged-out visitors.
        add_action('admin_bar_menu', [$this, 'sprout_admin_bar_indicator'], 999);
        add_action('admin_head', [$this, 'sprout_admin_bar_css']);
        add_action('wp_head', [$this, 'sprout_admin_bar_css']);

        // Analytics - hooks REST filters (lightweight) + admin AJAX + cron.
        $this->init_analytics();
    }

    /**
     * Load files needed on ALL requests (frontend, admin, REST).
     * These are lightweight and define helper functions + ability registration hooks.
     */
    private function load_core_files(): void
    {
        if (file_exists(SPROUT_MCP_PLUGIN_DIR . 'sprout-libs/autoload_packages.php')) {
            $this->load_jetpack_autoloader(SPROUT_MCP_PLUGIN_DIR . 'sprout-libs/autoload_packages.php');
        }

        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/dependencies.php';
        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/filesystem-helpers.php';
        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities_register/class-abilities-register.php';
    }

    /**
     * Safely load the generated Jetpack autoloader bootstrap.
     *
     * Another plugin may have already loaded the exact same generated autoloader
     * class, which would make requiring our local copy fatal. In that case we
     * reuse the existing class and run init() so this plugin still registers its
     * own package map.
     */
    private function load_jetpack_autoloader(string $autoload_packages_file): void
    {
        $autoloader_class = $this->get_jetpack_autoloader_class($autoload_packages_file);

        if ($autoloader_class && class_exists($autoloader_class, false) && is_callable([$autoloader_class, 'init'])) {
            $autoloader_class::init();
            return;
        }

        require_once $autoload_packages_file;
    }

    /**
     * Read the generated autoloader namespace without executing the file.
     */
    private function get_jetpack_autoloader_class(string $autoload_packages_file): ?string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $contents = file_get_contents($autoload_packages_file);
        if ($contents === false) {
            return null;
        }

        if (!preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]) . '\\Autoloader';
    }

    /**
     * Load admin UI file on-demand.
     *
     * PERFORMANCE: Only loads the ~2,950-line admin-pages.php when actually
     * needed (plugin page render or AJAX handler), not on every admin request.
     */
    private function load_admin_files(): void
    {
        if ($this->admin_files_loaded) {
            return;
        }
        $this->admin_files_loaded = true;
        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/admin-pages.php';
    }

    /**
     * Initialize analytics - deferred when tracking is off.
     *
     * PERFORMANCE: When analytics log_level is 'off' and analytics is disabled,
     * the 1,187-line analytics class is NOT loaded on frontend/REST requests.
     * It is only loaded in admin (for the UI) or when cron fires.
     */
    private function init_analytics(): void
    {
        $settings = sprout_mcp_get_settings();
        $tracking_off = ($settings['analytics_log_level'] === 'off') && !$settings['analytics_enabled'];

        // When tracking is completely off, only load for admin UI or cron.
        if ($tracking_off && !is_admin()) {
            // Register lightweight cron handlers that load the class on-demand.
            add_action('sprout_mcp_analytics_cleanup', static function (): void {
                require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/class-sprout-mcp-analytics.php';
                Sprout_MCP_Analytics::instance()->cleanup_old_logs();
            });
            add_action('sprout_mcp_daily_digest', static function (): void {
                require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/class-sprout-mcp-analytics.php';
                Sprout_MCP_Analytics::instance()->send_daily_digest();
            });
            return;
        }

        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/class-sprout-mcp-analytics.php';

        // DB table check only in admin (activation hook handles first-time).
        if (is_admin()) {
            Sprout_MCP_Analytics::maybe_create_table();
        }

        Sprout_MCP_Analytics::instance();
    }

    /**
     * Add public Sprout abilities to the MCP server tool list.
     *
     * @param mixed $config The default MCP adapter server config.
     * @return mixed
     */
    public function sprout_extend_mcp_server_config($config)
    {
        if (!is_array($config) || !sprout_mcp_is_enabled() || !function_exists('wp_get_abilities')) {
            return $config;
        }

        $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : [];
        $bridge_map = [
            'mcp-adapter/discover-abilities' => 'sprout-bridge/discover-tools',
            'mcp-adapter/get-ability-info'   => 'sprout-bridge/inspect-tool',
            'mcp-adapter/execute-ability'    => 'sprout-bridge/dispatch-tool',
        ];

        foreach ($tools as $index => $tool_name) {
            if (!is_string($tool_name) || !isset($bridge_map[$tool_name])) {
                continue;
            }
            $tools[$index] = $bridge_map[$tool_name];
        }

        foreach (wp_get_abilities() as $ability) {
            if (!($ability instanceof WP_Ability)) {
                continue;
            }

            $meta = $ability->get_meta();
            if (!(bool) ($meta['mcp']['public'] ?? false)) {
                continue;
            }

            $type = (string) ($meta['mcp']['type'] ?? 'tool');
            if ('tool' !== $type) {
                continue;
            }

            $name = $ability->get_name();
            if (sprout_mcp_is_ability_disabled($name)) {
                continue;
            }

            $tools[] = $name;
        }

        $config['tools'] = array_values(array_unique($tools));

        $instructions = sprout_mcp_build_server_instructions();
        if ($instructions !== '') {
            $config['server_description'] = $instructions;
        }

        return $config;
    }

    /**
     * Fix empty schema properties arrays so MCP clients receive valid JSON objects.
     *
     * @param mixed $result REST response payload.
     * @return mixed
     */
    public function sprout_fix_empty_schema_properties($result)
    {
        if (!is_array($result)) {
            return $result;
        }

        $result_obj = $result['result'] ?? null;
        if (!($result_obj instanceof \stdClass)) {
            return $result;
        }

        $tools = $result_obj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }

        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }

                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }

        $result_obj->tools = $tools;

        return $result;
    }

    /**
     * Show Sprout MCP status in the admin bar (administrators only).
     *
     * @param WP_Admin_Bar $admin_bar
     */
    public function sprout_admin_bar_indicator($admin_bar)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $is_on = sprout_mcp_is_enabled();
        $label = $is_on ? __('Sprout MCP: ON', 'sprout-os') : __('Sprout MCP: OFF', 'sprout-os');

        $admin_bar->add_node([
            'id'    => 'sprout-mcp-status',
            'title' => '<span class="sprout-mcp-bar-dot ' . ($is_on ? 'on' : 'off') . '"></span>' . esc_html($label),
            'href'  => admin_url('admin.php?page=sprout-os'),
        ]);
    }

    /**
     * Inline CSS for admin bar indicator (administrators only, ~200 bytes).
     */
    public function sprout_admin_bar_css()
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }
        ?>
        <style>
            #wp-admin-bar-sprout-mcp-status .sprout-mcp-bar-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 6px;
                vertical-align: middle;
            }
            #wp-admin-bar-sprout-mcp-status .sprout-mcp-bar-dot.on { background: #00d084; }
            #wp-admin-bar-sprout-mcp-status .sprout-mcp-bar-dot.off { background: #cc1818; }
            #adminmenu .toplevel_page_sprout-mcp .toplevel_page_sprout-mcp .wp-menu-image.svg {
                    background-size: 30px auto !important;
            }
        </style>
        <?php
    }
}

sprout_mcp_Load::instance();
