<?php
/**
 * SproutOS MCP - Restore a Paused Sandbox File
 *
 * Strips the .disabled extension from a sandbox file so the loader
 * picks it up again on subsequent requests. Includes dry-run preview
 * and status reporting.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Register ability definition.

wp_register_ability('sprout/enable-file', [
    'label'       => __('Enable File', 'sprout-os'),
    'description' => 'Restores a previously paused sandbox file by stripping the .disabled extension, allowing the sandbox loader to include it again. Accepts either the original filename or the .disabled variant. Supports dry-run preview. If the file is already active, returns a no-op.',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => [
                'type'      => 'string',
                'minLength' => 1,
                'description' => 'Sandbox file to restore - pass either the active '
                    . 'name (e.g. "my-feature.php") or the disabled variant '
                    . '("my-feature.php.disabled"). Relative paths resolve from ABSPATH.',
            ],
            'dry_run' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'Preview what would happen without actually renaming.',
            ],
        ],
        'required'             => ['path'],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'from_path'    => ['type' => 'string',  'description' => 'Path before the rename.'],
            'to_path'      => ['type' => 'string',  'description' => 'Path after the rename (active name).'],
            'restored'     => ['type' => 'boolean', 'description' => 'True when the file was actually renamed.'],
            'was_active'   => ['type' => 'boolean', 'description' => 'True when the file was already active (no-op).'],
            'file_size'    => ['type' => 'integer', 'description' => 'File size in bytes.'],
            'dry_run'      => ['type' => 'boolean', 'description' => 'True when this was a preview only.'],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_enable_file',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'title'        => 'Enable File',
            'instructions' => implode("\n", [
                'USAGE:',
                '• Targets files inside the SproutOS sandbox directory only.',
                '• You can pass either the active filename or the .disabled variant.',
                '• Pairs with disable-file: strips the .disabled suffix so the',
                '  sandbox loader includes the file on the next request.',
                '• Set dry_run=true to check the outcome without renaming.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

// Execute runtime logic.

/**
 * Restore a paused sandbox file by removing the .disabled extension.
 *
 * @param array $input
 * @return array|WP_Error
 */
function sprout_mcp_enable_file(array $input)
{
    $raw_path   = (string) $input['path'];
    $is_dry_run = !empty($input['dry_run']);

    // Normalise: always look for the .disabled variant first.
    $disabled_candidate = sprout_mcp_is_disabled_file($raw_path)
        ? $raw_path
        : $raw_path . '.disabled';

    $resolved = sprout_mcp_resolve_path($disabled_candidate, require_real: true);

    // If the .disabled file doesn't exist, check if the active file is already there.
    if (is_wp_error($resolved) && !sprout_mcp_is_disabled_file($raw_path)) {
        $active_path = sprout_mcp_resolve_path($raw_path, require_real: true);
        if (!is_wp_error($active_path) && is_file($active_path)) {
            return [
                'from_path'  => $active_path,
                'to_path'    => $active_path,
                'restored'   => false,
                'was_active' => true,
                'file_size'  => (int) filesize($active_path),
                'dry_run'    => $is_dry_run,
            ];
        }
        return $resolved; // Return the original "not found" error.
    }
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    // Must be inside the sandbox.
    $sandbox_check = sprout_mcp_validate_sandbox_path($resolved);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }

    if (!is_file($resolved)) {
        return new WP_Error('sprout_not_file', sprintf('Not a regular file: %s', $resolved));
    }

    // Already active - no-op.
    if (!sprout_mcp_is_disabled_file($resolved)) {
        return [
            'from_path'  => $resolved,
            'to_path'    => $resolved,
            'restored'   => false,
            'was_active' => true,
            'file_size'  => (int) filesize($resolved),
            'dry_run'    => $is_dry_run,
        ];
    }

    $active_name = substr($resolved, 0, -9); // Strip ".disabled"
    $file_size   = (int) filesize($resolved);

    // Conflict: an active copy already exists at the target name.
    if (file_exists($active_name)) {
        return new WP_Error(
            'sprout_enable_conflict',
            sprintf('An active file already exists at: %s', $active_name)
        );
    }

    // Dry-run: report what would happen.
    if ($is_dry_run) {
        return [
            'from_path'  => $resolved,
            'to_path'    => $active_name,
            'restored'   => false,
            'was_active' => false,
            'file_size'  => $file_size,
            'dry_run'    => true,
        ];
    }

    // Perform the rename.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
    if (!rename($resolved, $active_name)) {
        return new WP_Error('sprout_rename_failed', sprintf('Could not rename: %s', $resolved));
    }

    return [
        'from_path'  => $resolved,
        'to_path'    => $active_name,
        'restored'   => true,
        'was_active' => false,
        'file_size'  => $file_size,
        'dry_run'    => false,
    ];
}
