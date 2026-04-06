<?php
/**
 * SproutOS MCP - Pause a Sandbox File
 *
 * Appends a .disabled extension to a sandbox file so the loader skips
 * it on subsequent requests. The file stays on disk for later restoration.
 * Includes dry-run preview and status reporting.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Register ability definition.

wp_register_ability('sprout/disable-file', [
    'label'       => __('Disable File', 'sprout-os'),
    'description' => 'Pauses a sandbox file by renaming it with a .disabled extension. The file remains on disk but the sandbox loader will skip it. Use enable-file to re-activate. Supports dry-run preview. If the file is already paused, returns a no-op.',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => [
                'type'      => 'string',
                'minLength' => 1,
                'description' => 'Sandbox file to pause. Relative paths resolve from ABSPATH.',
            ],
            'dry_run' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'Preview the result without actually renaming.',
            ],
        ],
        'required'             => ['path'],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'from_path'      => ['type' => 'string',  'description' => 'Path before the rename.'],
            'to_path'        => ['type' => 'string',  'description' => 'Path after the rename (.disabled variant).'],
            'paused'         => ['type' => 'boolean', 'description' => 'True when the file was actually renamed.'],
            'was_paused'     => ['type' => 'boolean', 'description' => 'True when the file was already disabled (no-op).'],
            'file_size'      => ['type' => 'integer', 'description' => 'File size in bytes.'],
            'dry_run'        => ['type' => 'boolean', 'description' => 'True when this was a preview only.'],
            'rename_plan'    => ['type' => 'string',  'description' => 'Planned rename mode used by Sprout sandbox pause flow.'],
            'policy_tag'     => ['type' => 'string',  'description' => 'Sprout sandbox policy marker for pause operation.'],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_disable_file',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'title'        => 'Disable File',
            'instructions' => implode("\n", [
                'HOW IT WORKS:',
                '• Operates exclusively on files within the SproutOS sandbox directory.',
                '• Adds a .disabled suffix so the loader ignores the file on next request.',
                '• Reverse the action with the enable-file ability.',
                '• Non-destructive: the original content is preserved for future use.',
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
 * Pause a sandbox file by appending .disabled to its name.
 *
 * @param array $input
 * @return array|WP_Error
 */
function sprout_mcp_disable_file(array $input)
{
    $source_path = sprout_mcp_resolve_path((string) $input['path'], require_real: true);
    if (is_wp_error($source_path)) {
        return $source_path;
    }

    $preview_mode = !empty($input['dry_run']);

    // Must be inside the sandbox.
    $sandbox_check = sprout_mcp_validate_sandbox_path($source_path);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }

    if (!is_file($source_path)) {
        return new WP_Error('sprout_not_file', sprintf('Not a regular file: %s', $source_path));
    }

    $source_size = (int) filesize($source_path);

    // Already paused - no-op.
    if (sprout_mcp_is_disabled_file($source_path)) {
        return [
            'from_path'  => $source_path,
            'to_path'    => $source_path,
            'paused'     => false,
            'was_paused' => true,
            'file_size'  => $source_size,
            'dry_run'    => $preview_mode,
            'rename_plan'=> 'noop_already_paused',
            'policy_tag' => 'sprout-sandbox-pause-v2',
        ];
    }

    $paused_path = $source_path . '.disabled';

    // Conflict: a .disabled copy already sits at the target path.
    if (file_exists($paused_path)) {
        return new WP_Error(
            'sprout_disable_conflict',
            sprintf('A paused copy already exists at: %s', $paused_path)
        );
    }

    // Dry-run: report what would happen.
    if ($preview_mode) {
        return [
            'from_path'  => $source_path,
            'to_path'    => $paused_path,
            'paused'     => false,
            'was_paused' => false,
            'file_size'  => $source_size,
            'dry_run'    => true,
            'rename_plan'=> 'preview_pause_suffix',
            'policy_tag' => 'sprout-sandbox-pause-v2',
        ];
    }

    // Perform the rename.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
    if (!rename($source_path, $paused_path)) {
        return new WP_Error('sprout_rename_failed', sprintf('Could not rename: %s', $source_path));
    }

    return [
        'from_path'  => $source_path,
        'to_path'    => $paused_path,
        'paused'     => true,
        'was_paused' => false,
        'file_size'  => $source_size,
        'dry_run'    => false,
        'rename_plan'=> 'apply_pause_suffix',
        'policy_tag' => 'sprout-sandbox-pause-v2',
    ];
}
