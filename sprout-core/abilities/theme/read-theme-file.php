<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/read-theme-file', [
    'label' => __('Read Theme File', 'sprout-os'),
    'description' => __('Reads a file from the active child theme or parent theme.', 'sprout-os'),
    'category' => 'sprout-theme',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => [
                'type' => 'string',
                'enum' => ['child', 'parent'],
                'default' => 'child',
            ],
            'relative_path' => [
                'type' => 'string',
                'description' => 'Theme-relative file path, for example style.css or functions.php',
            ],
        ],
        'required' => ['relative_path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'theme_scope' => ['type' => 'string'],
            'relative_path' => ['type' => 'string'],
            'content' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'sprout_mcp_read_theme_file_ability',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
]);

function sprout_mcp_read_theme_file_ability(array $input)
{
    $theme_scope = ($input['theme_scope'] ?? 'child') === 'parent' ? 'parent' : 'child';
    $relative_path = (string) ($input['relative_path'] ?? '');
    $absolute = sprout_mcp_resolve_theme_file_path($theme_scope, $relative_path);

    if (is_wp_error($absolute)) {
        return $absolute;
    }

    if (!is_file($absolute)) {
        return new WP_Error('theme_file_not_found', __('The requested theme file was not found.', 'sprout-os'));
    }

    if (!sprout_mcp_is_supported_theme_extension($absolute)) {
        return new WP_Error('unsupported_theme_file', __('This theme file type is not allowed for MCP access.', 'sprout-os'));
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $content = file_get_contents($absolute);
    if ($content === false) {
        return new WP_Error('theme_file_read_failed', __('Failed to read the requested theme file.', 'sprout-os'));
    }

    return [
        'theme_scope' => $theme_scope,
        'relative_path' => ltrim(str_replace(sprout_mcp_get_theme_root_by_scope($theme_scope), '', $absolute), '/'),
        'content' => $content,
    ];
}

