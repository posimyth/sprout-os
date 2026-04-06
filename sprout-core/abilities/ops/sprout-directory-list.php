<?php
/**
 * SproutOS MCP - List Directory Contents
 *
 * Lists files and directories with glob filtering, recursive depth,
 * configurable sorting, and summary statistics.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('sprout/list-directory', [
    'label'       => __('List Directory', 'sprout-os'),
    'description' => 'Lists files and directories with glob filtering, recursive traversal, configurable depth, hidden-file control, and sortable output. Response includes summary statistics (total size, file/dir counts).',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => [
                'type'    => 'string',
                'default' => '',
                'description' => 'Directory path - defaults to ABSPATH. Relative paths resolve from ABSPATH.',
            ],
            'pattern' => [
                'type'    => 'string',
                'default' => '*',
                'description' => 'Filename glob pattern for filtering (e.g. "*.php", "wp-*").',
            ],
            'recursive' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'List contents recursively.',
            ],
            'max_depth' => [
                'type'    => 'integer',
                'default' => 3,
                'minimum' => 1,
                'maximum' => 10,
                'description' => 'Maximum recursion depth (when recursive=true).',
            ],
            'include_hidden' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'Include dotfiles/dotdirs (hidden entries).',
            ],
            'sort_by' => [
                'type'    => 'string',
                'enum'    => ['name', 'size', 'modified'],
                'default' => 'name',
                'description' => 'Sort criterion: name (dirs-first, alpha), size (largest first), or modified (newest first).',
            ],
            'limit' => [
                'type'    => 'integer',
                'default' => 500,
                'minimum' => 1,
                'maximum' => 5000,
                'description' => 'Maximum entries to return.',
            ],
        ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Resolved directory path that was listed.'],
            'entries' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => ['type' => 'string'],
                        'path'        => ['type' => 'string'],
                        'type'        => ['type' => 'string', 'description' => '"file" or "directory".'],
                        'size'        => ['type' => 'integer', 'description' => 'Bytes (files only).'],
                        'permissions' => ['type' => 'string', 'description' => 'Octal string (e.g. "0755").'],
                        'modified'    => ['type' => 'string', 'description' => 'ISO 8601 timestamp.'],
                    ],
                ],
            ],
            'total'       => ['type' => 'integer', 'description' => 'Total matches (before limit).'],
            'truncated'   => ['type' => 'boolean', 'description' => 'True when results were capped.'],
            'total_files' => ['type' => 'integer', 'description' => 'Number of files in the results.'],
            'total_dirs'  => ['type' => 'integer', 'description' => 'Number of directories in the results.'],
            'total_size'  => ['type' => 'integer', 'description' => 'Combined byte size of all files.'],
            'query_mode'  => ['type' => 'string',  'description' => 'Either "recursive" or "flat".'],
            'policy_tag'  => ['type' => 'string',  'description' => 'Sprout directory-list policy marker.'],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_list_directory',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'title'        => 'List Directory',
            'instructions' => implode("\n", [
                'SORTING:',
                '• sort_by=name (default): directories first, then alphabetical.',
                '• sort_by=size: largest files first.',
                '• sort_by=modified: most recently changed first.',
                '',
                'SANDBOX:',
                '• AI-created PHP files live in wp-content/sproutos-mcp-sandbox/.',
                '• Check .crashed to see if safe mode is active.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * List directory contents.
 *
 * @param array $input Input with optional 'path', 'pattern', 'recursive', 'max_depth', 'include_hidden', 'limit'.
 * @return array|WP_Error
 */
function sprout_mcp_list_directory(array $input = [])
{
    $plan = sprout_mcp_make_directory_query_plan($input);

    $absolute_path = sprout_mcp_resolve_path($plan['path'], require_real: true);
    if (is_wp_error($absolute_path)) {
        return $absolute_path;
    }

    if (!is_dir($absolute_path)) {
        return new WP_Error('sprout_not_directory', sprintf('Not a directory: %s', $absolute_path));
    }
    if (!is_readable($absolute_path)) {
        return new WP_Error('sprout_dir_unreadable', sprintf('No read permission: %s', $absolute_path));
    }

    $all_entries = $plan['recursive']
        ? sprout_mcp_collect_recursive_entries($absolute_path, $plan['pattern'], $plan['include_hidden'], $plan['max_depth'])
        : sprout_mcp_collect_flat_entries($absolute_path, $plan['pattern'], $plan['include_hidden']);

    if (is_wp_error($all_entries)) {
        return $all_entries;
    }

    // Sort based on the chosen criterion.
    sprout_mcp_sort_entries($all_entries, $plan['sort_by']);

    $summary = sprout_mcp_build_directory_summary($all_entries);

    $total     = count($all_entries);
    $truncated = $total > $plan['limit'];

    return [
        'path'        => $absolute_path,
        'entries'     => array_slice($all_entries, 0, $plan['limit']),
        'total'       => $total,
        'truncated'   => $truncated,
        'total_files' => $summary['total_files'],
        'total_dirs'  => $summary['total_dirs'],
        'total_size'  => $summary['total_size'],
        'query_mode'  => $plan['recursive'] ? 'recursive' : 'flat',
        'policy_tag'  => 'sprout-dirlist-v2',
    ];
}

/**
 * Normalize user input into a deterministic directory-query plan.
 *
 * @param array<string,mixed> $input
 * @return array{path:string,pattern:string,recursive:bool,max_depth:int,include_hidden:bool,sort_by:string,limit:int}
 */
function sprout_mcp_make_directory_query_plan(array $input): array
{
    return [
        'path'           => (string) (($input['path'] ?? '') !== '' ? $input['path'] : ABSPATH),
        'pattern'        => (string) ($input['pattern'] ?? '*'),
        'recursive'      => !empty($input['recursive']),
        'max_depth'      => (int) ($input['max_depth'] ?? 3),
        'include_hidden' => !empty($input['include_hidden']),
        'sort_by'        => (string) ($input['sort_by'] ?? 'name'),
        'limit'          => max(1, min(5000, (int) ($input['limit'] ?? 500))),
    ];
}

/**
 * Build file/directory count and total size summary.
 *
 * @param list<array<string,mixed>> $entries
 * @return array{total_files:int,total_dirs:int,total_size:int}
 */
function sprout_mcp_build_directory_summary(array $entries): array
{
    $total_files = 0;
    $total_dirs  = 0;
    $total_size  = 0;

    foreach ($entries as $entry) {
        if (($entry['type'] ?? '') === 'directory') {
            $total_dirs++;
            continue;
        }
        $total_files++;
        $total_size += (int) ($entry['size'] ?? 0);
    }

    return [
        'total_files' => $total_files,
        'total_dirs'  => $total_dirs,
        'total_size'  => $total_size,
    ];
}

/**
 * Sort directory entries by the given criterion.
 *
 * @param array  &$entries Entries to sort in-place.
 * @param string $sort_by  One of 'name', 'size', 'modified'.
 */
function sprout_mcp_sort_entries(array &$entries, string $sort_by): void
{
    usort($entries, static function (array $a, array $b) use ($sort_by): int {
        if ($sort_by === 'size') {
            return ((int) $b['size']) <=> ((int) $a['size']);
        }

        if ($sort_by === 'modified') {
            return strcmp((string) $b['modified'], (string) $a['modified']);
        }

        // Default: directories first, then alpha.
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'directory' ? -1 : 1;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });
}

// Helper functions for recursive and flat collection.

/**
 * Walk a directory tree up to a maximum depth, collecting entries
 * that pass the visibility and pattern filters.
 *
 * @param string $root           Absolute directory to walk.
 * @param string $glob           Glob pattern for filename matching.
 * @param bool   $show_hidden    Include dot-prefixed entries.
 * @param int    $depth_limit    Maximum levels to descend.
 * @return list<array>
 */
function sprout_mcp_collect_recursive_entries(string $root, string $glob, bool $show_hidden, int $depth_limit): array
{
    $dir_iter  = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
    $tree_iter = new RecursiveIteratorIterator($dir_iter, RecursiveIteratorIterator::SELF_FIRST);
    $tree_iter->setMaxDepth($depth_limit - 1); // API is 0-indexed

    $results = [];
    foreach ($tree_iter as $node) {
        $record = sprout_mcp_file_info_to_entry($node, $glob, $show_hidden);
        if ($record !== null) {
            $results[] = $record;
        }
    }

    return $results;
}

/**
 * List immediate children of a directory, applying visibility and
 * pattern filters.
 *
 * @param string $dir         Absolute directory path.
 * @param string $glob        Glob pattern for filename matching.
 * @param bool   $show_hidden Include dot-prefixed entries.
 * @return list<array>|WP_Error
 */
function sprout_mcp_collect_flat_entries(string $dir, string $glob, bool $show_hidden)
{
    $handle = opendir($dir);
    if ($handle === false) {
        return new WP_Error('sprout_opendir_failed', sprintf('Cannot open directory: %s', $dir));
    }

    $results = [];
    while (($name = readdir($handle)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $node   = new SplFileInfo($dir . DIRECTORY_SEPARATOR . $name);
        $record = sprout_mcp_file_info_to_entry($node, $glob, $show_hidden);
        if ($record !== null) {
            $results[] = $record;
        }
    }

    closedir($handle);
    return $results;
}

/**
 * Convert an SplFileInfo node into a response-ready associative array,
 * returning null when the entry should be excluded.
 *
 * Each entry contains: name, path, type, size, extension, permissions,
 * modified timestamp, and a symlink flag - providing richer metadata
 * than a simple name/type listing.
 *
 * @param SplFileInfo $node        Filesystem node.
 * @param string      $glob        Glob pattern for name matching.
 * @param bool        $show_hidden Whether dot-prefixed names are visible.
 * @return array|null
 */
function sprout_mcp_file_info_to_entry(SplFileInfo $node, string $glob, bool $show_hidden): ?array
{
    $name = $node->getFilename();

    // Visibility gate.
    if (!$show_hidden && str_starts_with($name, '.')) {
        return null;
    }

    // Pattern gate.
    if ($glob !== '*' && !fnmatch($glob, $name)) {
        return null;
    }

    $is_dir = $node->isDir();

    return [
        'name'        => $name,
        'path'        => $node->getPathname(),
        'type'        => $is_dir ? 'directory' : 'file',
        'size'        => $is_dir ? 0 : $node->getSize(),
        'extension'   => $is_dir ? '' : strtolower($node->getExtension()),
        'is_symlink'  => $node->isLink(),
        'permissions' => substr(sprintf('%o', $node->getPerms()), -4),
        'modified'    => gmdate('c', (int) $node->getMTime()),
    ];
}
