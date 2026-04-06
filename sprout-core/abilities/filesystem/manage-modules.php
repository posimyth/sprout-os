<?php
/**
 * Manage Sprout MCP module settings and sandbox behavior.
 *
 * Exposes read/update abilities so AI clients can inspect and adjust
 * enabled modules without editing plugin options directly.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('sprout/get-mcp-settings', [
    'label'       => __('Get MCP Settings', 'sprout-os'),
    'description' => __('Returns current MCP module settings, sandbox status, and ability counts per module.', 'sprout-os'),
    'category'    => 'sprout-bridge',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'sprout_mcp_ability_get_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Returns MCP settings including which ability modules are enabled/disabled, sandbox status, and a count of registered abilities. Use this to understand what's active. Use sprout/update-mcp-settings to toggle modules.",
            'readonly' => true, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

wp_register_ability('sprout/update-mcp-settings', [
    'label'       => __('Update MCP Settings', 'sprout-os'),
    'description' => __('Enable or disable ability modules and sandbox. Changes take effect on the next request.', 'sprout-os'),
    'category'    => 'sprout-bridge',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'sandbox_enabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable the sandbox loader (loads custom abilities from wp-content/sproutos-mcp-sandbox/).',
            ],
            'modules' => [
                'type' => 'object',
                'description' => 'Module toggles. Keys: wordpress (pages, filesystem, theme, design, PHP execution), elementor (page builder layouts & widgets), the_plus_addons (The Plus Addons widget abilities), nexter_extension (Nexter Extension: snippets, theme builder, settings), nexter_blocks (Nexter Blocks for Gutenberg - coming soon). Values: true/false.',
                'properties' => [
                    'wordpress'        => ['type' => 'boolean', 'description' => 'Core WordPress abilities: pages, filesystem, theme, design, PHP execution.'],
                    'elementor'        => ['type' => 'boolean', 'description' => 'Elementor page builder abilities.'],
                    'the_plus_addons'  => ['type' => 'boolean', 'description' => 'The Plus Addons for Elementor widget abilities.'],
                    'nexter_extension' => ['type' => 'boolean', 'description' => 'Nexter Extension abilities (sandbox-based).'],
                    'nexter_blocks'    => ['type' => 'boolean', 'description' => 'Nexter Blocks for Gutenberg.'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'sprout_mcp_ability_update_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => "Toggles ability modules on/off. Only provided keys are changed - omitted keys keep their current value. Changes take effect on the next MCP request. Tip: disable modules you don't need to reduce the number of tools exposed and save tokens.",
            'readonly' => false, 'destructive' => false, 'idempotent' => true,
        ],
    ],
]);

function sprout_mcp_ability_get_settings(array $input): array {
    $settings = sprout_mcp_get_settings();
    $ai_enabled = sprout_mcp_is_enabled();

    // Count abilities per prefix to show what each module provides.
    $all = wp_get_abilities();
    $prefix_counts = ['sprout/' => 0, 'nexter/' => 0, 'theplus' => 0];
    foreach ($all as $name => $ab) {
        if (str_starts_with($name, 'sprout/')) { $prefix_counts['sprout/']++; }
        elseif (str_starts_with($name, 'nexter/')) { $prefix_counts['nexter/']++; }
        if (str_contains($name, 'theplus')) { $prefix_counts['theplus']++; }
    }

    $module_info = [
        'wordpress' => [
            'enabled' => $settings['modules']['wordpress'],
            'description' => 'Core WordPress: pages, filesystem, theme, design, PHP execution',
        ],
        'elementor' => [
            'enabled' => $settings['modules']['elementor'],
            'description' => 'Elementor page builder: layouts, containers, widgets, templates',
        ],
        'the_plus_addons' => [
            'enabled' => $settings['modules']['the_plus_addons'],
            'description' => 'The Plus Addons for Elementor: 100+ widget abilities',
        ],
        'nexter_extension' => [
            'enabled' => $settings['modules']['nexter_extension'],
            'description' => 'Nexter Extension: snippets, theme builder, fonts, SMTP, security, performance, admin settings',
        ],
        'nexter_blocks' => [
            'enabled' => $settings['modules']['nexter_blocks'],
            'description' => 'Nexter Blocks for Gutenberg (coming soon)',
        ],
    ];

    return [
        'success' => true,
        'ai_abilities_enabled' => $ai_enabled,
        'sandbox_enabled' => $settings['sandbox_enabled'],
        'modules' => $module_info,
        'ability_counts' => [
            'total' => count($all),
            'sprout_core' => $prefix_counts['sprout/'],
            'nexter' => $prefix_counts['nexter/'],
            'the_plus' => $prefix_counts['theplus'],
        ],
        'tip' => 'Disable modules you don\'t need to reduce tool count and save tokens. Use sprout/update-mcp-settings to toggle.',
    ];
}

function sprout_mcp_ability_update_settings(array $input): array {
    $current = sprout_mcp_get_settings();
    $changed = [];

    if (isset($input['sandbox_enabled'])) {
        $current['sandbox_enabled'] = (bool) $input['sandbox_enabled'];
        $changed[] = 'sandbox_enabled → ' . ($current['sandbox_enabled'] ? 'on' : 'off');
    }

    if (isset($input['modules']) && is_array($input['modules'])) {
        foreach ($input['modules'] as $key => $val) {
            if (isset($current['modules'][$key])) {
                $current['modules'][$key] = (bool) $val;
                $changed[] = $key . ' → ' . ($current['modules'][$key] ? 'on' : 'off');
            }
        }
    }

    if (empty($changed)) {
        return [
            'success' => true,
            'message' => 'No changes provided.',
            'settings' => $current,
        ];
    }

    update_option('sprout_mcp_settings', $current);

    return [
        'success' => true,
        'message' => 'Settings updated. Changes take effect on next request.',
        'changed' => $changed,
        'settings' => $current,
    ];
}
