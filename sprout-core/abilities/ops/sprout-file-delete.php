<?php
/**
 * SproutOS MCP - Delete Files and Directories
 *
 * Removes files or directories with optional dry-run preview,
 * PHP-sandbox enforcement, and file metadata in the response.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Register ability definition.

wp_register_ability('sprout/delete-file', [
    'label'       => __('Delete File', 'sprout-os'),
    'description' => 'Deletes a file or directory. Non-empty directories need the recursive flag. Critical WordPress paths and sensitive files are protected. Idempotent: deleting a missing path succeeds with deleted=false. Supports dry-run preview.',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => [
                'type'      => 'string',
                'minLength' => 1,
                'description' => 'Path to delete - relative paths resolve from ABSPATH.',
            ],
            'recursive' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'Recursively delete directory contents.',
            ],
            'dry_run' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'Preview what would be deleted without actually removing anything.',
            ],
        ],
        'required'             => ['path'],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'          => ['type' => 'string',  'description' => 'Absolute target path.'],
            'type'          => ['type' => 'string',  'description' => '"file", "directory", or "not_found".'],
            'target_kind'   => ['type' => 'string',  'description' => 'Sprout classification of target: file, directory, symlink, or not_found.'],
            'deleted'       => ['type' => 'boolean', 'description' => 'Whether something was removed.'],
            'items_deleted' => ['type' => 'integer', 'description' => 'Count of items removed.'],
            'file_size'     => ['type' => 'integer', 'description' => 'Size of the file before deletion (bytes).'],
            'last_modified' => ['type' => 'string',  'description' => 'Last-modified time (ISO 8601).'],
            'dry_run'       => ['type' => 'boolean', 'description' => 'True when this was a preview only.'],
            'dry_run_mode'  => ['type' => 'string',  'description' => 'Either "preview" or "execute".'],
            'policy_revision' => ['type' => 'string', 'description' => 'Deletion-policy revision tag used by Sprout safety checks.'],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_delete_file',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'title'        => 'Delete File',
            'instructions' => implode("\n", [
                'SAFETY:',
                '• Set dry_run=true to see what would be deleted without removing anything.',
                '• Critical directories (ABSPATH, wp-admin, wp-includes) are protected.',
                '• Sensitive files (wp-config.php, .htaccess, .env) are blocked.',
                '',
                'SANDBOX:',
                '• To exit safe mode after a crash, delete:',
                '  wp-content/sproutos-mcp-sandbox/.crashed',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => true,
        ],
    ],
]);

// Execute runtime logic.

/**
 * Delete a file or directory with optional dry-run.
 *
 * @param array $input
 * @return array|WP_Error
 */
function sprout_mcp_delete_file(array $input)
{
    $target_path = sprout_mcp_resolve_path((string) $input['path'], require_real: false);
    if (is_wp_error($target_path)) {
        return $target_path;
    }

    // Strict policy: all MCP deletes stay inside the sandbox directory.
    $sandbox_scope = sprout_mcp_enforce_sandbox_writes($target_path);
    if (is_wp_error($sandbox_scope)) {
        return $sandbox_scope;
    }

    $recursive_flag = !empty($input['recursive']);
    $preview_mode   = !empty($input['dry_run']);

    // Idempotent: missing target is a no-op.
    if (!file_exists($target_path) && !is_link($target_path)) {
        return sprout_mcp_delete_build_result($target_path, 'not_found', false, 0, 0, '', $preview_mode);
    }

    // SECURITY: Block deletion of sensitive / protected files.
    $sensitive = sprout_mcp_check_sensitive_file($target_path);
    if (is_wp_error($sensitive)) {
        return $sensitive;
    }

    // SECURITY: PHP-executable files restricted to sandbox.
    if ((is_file($target_path) || is_link($target_path)) && sprout_mcp_is_php_extension($target_path)) {
        $sandbox_check = sprout_mcp_check_php_sandbox($target_path);
        if (is_wp_error($sandbox_check)) {
            return $sandbox_check;
        }
    }

    $protected_check = sprout_mcp_assert_path_not_protected($target_path);
    if (is_wp_error($protected_check)) {
        return $protected_check;
    }

    // Collect metadata before deletion.
    $target_size     = is_file($target_path) ? (int) filesize($target_path) : 0;
    $target_modified = gmdate('c', (int) filemtime($target_path));

    // Handle file or symlink deletion path.
    if (is_file($target_path) || is_link($target_path)) {
        $entry_kind = is_link($target_path) ? 'symlink' : 'file';

        if ($preview_mode) {
            return sprout_mcp_delete_build_result($target_path, $entry_kind, false, 0, $target_size, $target_modified, true);
        }

        wp_delete_file($target_path);
        if (file_exists($target_path)) {
            return new WP_Error('sprout_unlink_failed', sprintf('Cannot remove: %s', $target_path));
        }

        return sprout_mcp_delete_build_result($target_path, $entry_kind, true, 1, $target_size, $target_modified, false);
    }

    // Handle directory deletion path.
    if (is_dir($target_path)) {
        return sprout_mcp_delete_directory($target_path, $recursive_flag, $preview_mode, $target_modified);
    }

    return new WP_Error('sprout_unknown_type', sprintf('Not a file or directory: %s', $target_path));
}

// Recursively delete directory contents.

/**
 * Delete a directory, optionally recursively, with dry-run support.
 */
function sprout_mcp_delete_directory(string $resolved, bool $recursive, bool $dry_run, string $last_modified)
{
    $contents = scandir($resolved);
    if ($contents === false) {
        return new WP_Error('sprout_scan_failed', sprintf('Cannot list: %s', $resolved));
    }

    $is_empty = count($contents) <= 2;

    if (!$is_empty && !$recursive) {
        return new WP_Error('sprout_dir_not_empty', sprintf(
            'Directory is not empty - set recursive=true to remove: %s', $resolved
        ));
    }

    // Count items for dry-run reporting.
    $item_count = $is_empty ? 1 : sprout_mcp_count_dir_items($resolved) + 1;

    if ($dry_run) {
        return sprout_mcp_delete_build_result($resolved, 'directory', false, 0, 0, $last_modified, true);
    }

    if ($is_empty) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        if (!rmdir($resolved)) {
            return new WP_Error('sprout_rmdir_failed', sprintf('Cannot remove empty dir: %s', $resolved));
        }
        return sprout_mcp_delete_build_result($resolved, 'directory', true, 1, 0, $last_modified, false);
    }

    // Recursive depth-first deletion.
    $items_deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        $ok = $item->isDir() ? rmdir($item->getPathname()) : false;
        if (!$ok && $item->isDir()) {
            return new WP_Error('sprout_recursive_failed', sprintf('Cannot remove: %s', $item->getPathname()));
        }
        if (!$item->isDir()) {
            wp_delete_file($item->getPathname());
            if (file_exists($item->getPathname())) {
                return new WP_Error('sprout_recursive_failed', sprintf('Cannot remove: %s', $item->getPathname()));
            }
        }
        $items_deleted++;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    if (!rmdir($resolved)) {
        return new WP_Error('sprout_rmdir_final', sprintf('Cannot remove root dir: %s', $resolved));
    }
    $items_deleted++;

    return sprout_mcp_delete_build_result($resolved, 'directory', true, $items_deleted, 0, $last_modified, false);
}

/**
 * Count all items inside a directory recursively.
 */
function sprout_mcp_count_dir_items(string $dir): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $_) {
        $count++;
    }
    return $count;
}

/**
 * Build the canonical delete response shape.
 *
 * @return array<string,mixed>
 */
function sprout_mcp_delete_build_result(
    string $path,
    string $kind,
    bool $did_delete,
    int $items_deleted,
    int $size,
    string $last_modified,
    bool $is_dry_run
): array {
    return [
        'path'            => $path,
        'type'            => $kind === 'not_found' ? 'not_found' : ($kind === 'directory' ? 'directory' : 'file'),
        'target_kind'     => $kind,
        'deleted'         => $did_delete,
        'items_deleted'   => $items_deleted,
        'file_size'       => $size,
        'last_modified'   => $last_modified,
        'dry_run'         => $is_dry_run,
        'dry_run_mode'    => $is_dry_run ? 'preview' : 'execute',
        'policy_revision' => 'sprout-delete-v2',
    ];
}

/**
 * Ensure protected core and content paths cannot be removed.
 *
 * @return true|WP_Error
 */
function sprout_mcp_assert_path_not_protected(string $path)
{
    $normalized_target = realpath($path);
    $protected_paths = sprout_mcp_deletion_policy_paths();

    if (in_array($normalized_target, $protected_paths, true)) {
        return new WP_Error(
            'sprout_protected_path',
            sprintf('Cannot delete protected WordPress directory: %s', $path)
        );
    }

    return true;
}

/**
 * Return normalized protected directories used by deletion policy.
 *
 * @return array<int,string>
 */
function sprout_mcp_deletion_policy_paths(): array
{
    return array_filter([
        realpath(ABSPATH),
        realpath(ABSPATH . 'wp-admin'),
        realpath(ABSPATH . 'wp-includes'),
        realpath(WP_CONTENT_DIR . '/mu-plugins'),
        realpath(WP_CONTENT_DIR . '/plugins'),
        realpath(WP_CONTENT_DIR . '/themes'),
    ]);
}
