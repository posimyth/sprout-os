<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

wp_register_ability('sprout/update-theme-file', [
    'label' => __('Update Theme File', 'sprout-os'),
    'description' => __('Creates or updates a file inside the active child theme or parent theme. Supports replace, append, and prepend modes. PHP syntax validation is optional to avoid server timeout issues on local stacks.', 'sprout-os'),
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
                'description' => 'Theme-relative target file path.',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'File content to write.',
            ],
            'mode' => [
                'type' => 'string',
                'enum' => ['replace', 'append', 'prepend'],
                'default' => 'replace',
            ],
            'create_if_missing' => [
                'type' => 'boolean',
                'default' => true,
            ],
            'validate_php' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'When true, run PHP CLI syntax validation before saving PHP files. Leave false on constrained local servers where linting may timeout.',
            ],
        ],
        'required' => ['relative_path', 'content'],
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
    'execute_callback' => 'sprout_mcp_update_theme_file_ability',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
]);

function sprout_mcp_update_theme_file_ability(array $input)
{
    $theme_scope = ($input['theme_scope'] ?? 'child') === 'parent' ? 'parent' : 'child';
    $relative_path = (string) ($input['relative_path'] ?? '');
    $content = (string) ($input['content'] ?? '');
    $mode = in_array(($input['mode'] ?? 'replace'), ['replace', 'append', 'prepend'], true) ? (string) $input['mode'] : 'replace';
    $create_if_missing = !array_key_exists('create_if_missing', $input) || (bool) $input['create_if_missing'];
    $validate_php = array_key_exists('validate_php', $input) ? (bool) $input['validate_php'] : false;

    $absolute = sprout_mcp_resolve_theme_file_path($theme_scope, $relative_path);
    if (is_wp_error($absolute)) {
        return $absolute;
    }

    if (!sprout_mcp_is_supported_theme_extension($absolute)) {
        return new WP_Error('unsupported_theme_file', __('This theme file type is not allowed for MCP writes.', 'sprout-os'));
    }

    $safe_type = sprout_mcp_assert_safe_mutable_file_type($absolute);
    if (is_wp_error($safe_type)) {
        return $safe_type;
    }

    $dir = dirname($absolute);
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return new WP_Error('theme_directory_create_failed', __('Failed to create the target theme directory.', 'sprout-os'));
    }

    if (!is_file($absolute) && !$create_if_missing) {
        return new WP_Error('theme_file_missing', __('Target theme file does not exist and create_if_missing is false.', 'sprout-os'));
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $existing = is_file($absolute) ? file_get_contents($absolute) : '';
    if ($existing === false) {
        return new WP_Error('theme_file_read_failed', __('Failed to read the existing theme file.', 'sprout-os'));
    }

    $final_content = match ($mode) {
        'append' => (string) $existing . $content,
        'prepend' => $content . (string) $existing,
        default => $content,
    };

    if ($validate_php && strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION)) === 'php') {
        $validation = sprout_mcp_validate_theme_php_syntax($final_content);
        if (is_wp_error($validation)) {
            return $validation;
        }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
    $bytes = file_put_contents($absolute, $final_content);
    if ($bytes === false) {
        return new WP_Error('theme_file_write_failed', __('Failed to write the theme file.', 'sprout-os'));
    }

    return [
        'theme_scope' => $theme_scope,
        'relative_path' => ltrim(str_replace(sprout_mcp_get_theme_root_by_scope($theme_scope), '', $absolute), '/'),
        'bytes_written' => (int) $bytes,
        'mode' => $mode,
    ];
}
