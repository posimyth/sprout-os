<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/list-theme-files', [
    'label' => __('List Theme Files', 'sprout-os'),
    'description' => __('Lists editable files from the active child theme or parent theme.', 'sprout-os'),
    'category' => 'sprout-theme',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => [
                'type' => 'string',
                'enum' => ['child', 'parent'],
                'default' => 'child',
            ],
            'subdir' => [
                'type' => 'string',
                'description' => 'Optional subdirectory inside the theme.',
            ],
            'max_files' => [
                'type' => 'integer',
                'default' => 200,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => ['type' => 'string'],
            'theme_root' => ['type' => 'string'],
            'files' => ['type' => 'array'],
        ],
    ],
    'execute_callback' => 'sprout_mcp_list_theme_files_ability',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
]);

function sprout_mcp_list_theme_files_ability(array $input)
{
    $theme_scope = ($input['theme_scope'] ?? 'child') === 'parent' ? 'parent' : 'child';
    $subdir = sanitize_text_field((string) ($input['subdir'] ?? ''));
    $max_files = max(1, min(500, absint($input['max_files'] ?? 200)));

    $files = sprout_mcp_list_theme_files($theme_scope, $subdir, $max_files);
    if (is_wp_error($files)) {
        return $files;
    }

    return [
        'theme_scope' => $theme_scope,
        'theme_root' => sprout_mcp_get_theme_root_by_scope($theme_scope),
        'files' => $files,
    ];
}

