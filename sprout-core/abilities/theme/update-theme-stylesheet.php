<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/update-theme-stylesheet', [
    'label' => __('Update Theme Stylesheet', 'sprout-os'),
    'description' => __('Appends, prepends, or replaces CSS in the active theme stylesheet (style.css). Useful for design tweaks without editing templates.', 'sprout-os'),
    'category' => 'sprout-theme',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => [
                'type' => 'string',
                'enum' => ['child', 'parent'],
                'default' => 'child',
            ],
            'css' => [
                'type' => 'string',
                'description' => 'CSS code to write into style.css',
            ],
            'mode' => [
                'type' => 'string',
                'enum' => ['append', 'prepend', 'replace'],
                'default' => 'append',
            ],
        ],
        'required' => ['css'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => ['type' => 'string'],
            'relative_path' => ['type' => 'string'],
            'bytes_written' => ['type' => 'integer'],
            'mode' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'sprout_mcp_update_theme_stylesheet_ability',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
]);

function sprout_mcp_update_theme_stylesheet_ability(array $input)
{
    return sprout_mcp_update_theme_file_ability([
        'theme_scope' => ($input['theme_scope'] ?? 'child') === 'parent' ? 'parent' : 'child',
        'relative_path' => 'style.css',
        'content' => (string) ($input['css'] ?? ''),
        'mode' => in_array(($input['mode'] ?? 'append'), ['append', 'prepend', 'replace'], true) ? (string) $input['mode'] : 'append',
        'create_if_missing' => true,
    ]);
}

