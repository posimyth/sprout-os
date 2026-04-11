<?php
/**
 * Register Sprout MCP ability categories and ability loaders.
 *
 * @link https://posimyth.com/
 * @since 1.0.0
 * @package SproutOS_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ability registrar bootstrap.
 *
 * @since 1.0.0
 */
class Sprout_Abilities_Register {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Return the Elementor plugin instance.
	 *
	 * @return \Elementor\Plugin
	 */
	public static function elementor() {
		return \Elementor\Plugin::$instance;
	}

	/**
	 * Return the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
        add_action('wp_abilities_api_categories_init', [ $this, 'sprout_register_ability_categories' ], 1);
        add_action('wp_abilities_api_init', [ $this, 'sprout_manage_ability_file' ], 1);
	}

    public function sprout_register_ability_categories(): void {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        // Always keep bridge category available for adapter compatibility.
        if (!function_exists('wp_has_ability_category') || !wp_has_ability_category('sprout-bridge')) {
            wp_register_ability_category('sprout-bridge', [
                'label' => __('Sprout Bridge', 'sprout-os'),
                'description' => __('Bridge abilities for discovery, inspection, and dispatch.', 'sprout-os'),
            ]);
        }

        if (!sprout_mcp_is_enabled()) {
            return;
        }

        wp_register_ability_category('sprout-content', [
            'label' => __('Sprout Content', 'sprout-os'),
            'description' => __('Content creation abilities for Sprout MCP.', 'sprout-os'),
        ]);

        wp_register_ability_category('sprout-elementor', [
            'label' => __('Sprout Elementor', 'sprout-os'),
            'description' => __('Elementor widget abilities for Sprout MCP.', 'sprout-os'),
        ]);

        wp_register_ability_category('sprout-theme', [
            'label' => __('Sprout Theme', 'sprout-os'),
            'description' => __('Theme and child theme editing abilities for Sprout MCP.', 'sprout-os'),
        ]);

        if (!function_exists('wp_has_ability_category') || !wp_has_ability_category('sprout-filesystem')) {
            wp_register_ability_category('sprout-filesystem', [
                'label' => __('Sprout Filesystem', 'sprout-os'),
                'description' => __('Filesystem and sandbox abilities for Sprout MCP.', 'sprout-os'),
            ]);
        }

        if (sprout_mcp_allows_remote_code_execution() && (!function_exists('wp_has_ability_category') || !wp_has_ability_category('sprout-code-execution'))) {
            wp_register_ability_category('sprout-code-execution', [
                'label' => __('Sprout Code Execution', 'sprout-os'),
                'description' => __('Server-side code execution abilities for Sprout MCP.', 'sprout-os'),
            ]);
        }

        if (!function_exists('wp_has_ability_category') || !wp_has_ability_category('nexter-extension')) {
            wp_register_ability_category('nexter-extension', [
                'label' => __('Nexter Extension', 'sprout-os'),
                'description' => __('Nexter Extension abilities for code snippets, theme builder, and site settings.', 'sprout-os'),
            ]);
        }

        if (!function_exists('wp_has_ability_category') || !wp_has_ability_category('wdesignkit')) {
            wp_register_ability_category('wdesignkit', [
                'label' => __('WDesignKit', 'sprout-os'),
                'description' => __('WDesignKit widget builder abilities for creating, managing, and deploying custom widgets.', 'sprout-os'),
            ]);
        }
    }

    public function sprout_manage_ability_file() {
        // Load core bridge abilities first (always available for compatibility).
        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/bridge/sprout-bridge-discovery.php';
        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/bridge/sprout-bridge-inspector.php';
        require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/bridge/sprout-bridge-dispatch.php';

        if (!sprout_mcp_is_enabled()) {
            return;
        }

        // Load WordPress-focused abilities.
        if (sprout_mcp_is_module_enabled('wordpress')) {
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/wordpress/create-page.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/wordpress/update-page.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/theme/theme-helpers.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/theme/list-theme-files.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/theme/read-theme-file.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/theme/update-theme-file.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/theme/update-theme-stylesheet.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-file-read.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-file-write.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-file-edit.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-file-delete.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-directory-list.php';
            require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/filesystem/manage-modules.php';

            if (sprout_mcp_allows_remote_code_execution()) {
                require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-code-execute.php';
                require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/filesystem/batch-execute.php';
            }

            if (sprout_mcp_allows_dynamic_code_loading()) {
                require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-sandbox-disable.php';
                require_once SPROUT_MCP_PLUGIN_DIR . 'sprout-core/abilities/ops/sprout-sandbox-enable.php';
            }
        }

    }

}

Sprout_Abilities_Register::instance();
