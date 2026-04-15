<?php
/**
 * SproutOS MCP - Edit File via String Replacement
 *
 * Targeted file editing with exact-match replacement, optional occurrence
 * selection, context-line output, and diff generation.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Register ability definition.

wp_register_ability('sprout/edit-file', [
    'label'       => __('Edit File', 'sprout-os'),
    'description' => 'Edits an existing file by replacing an exact string match. Supports replace-all, specific occurrence targeting, and context-line output so the caller can verify the edit landed in the right place.',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => [
                'type'      => 'string',
                'minLength' => 1,
                'description' => 'File path - relative paths resolve from ABSPATH.',
            ],
            'old_string' => [
                'type'        => 'string',
                'description' => 'Exact text to find. Must match character-for-character.',
            ],
            'new_string' => [
                'type'        => 'string',
                'description' => 'Replacement text. Empty string deletes the match.',
            ],
            'replace_all' => [
                'type'    => 'boolean',
                'default' => false,
                'description' => 'Replace every occurrence (default: only one allowed).',
            ],
            'occurrence' => [
                'type'    => 'integer',
                'minimum' => 1,
                'description' => 'When multiple matches exist and replace_all is false, '
                    . 'replace only the Nth occurrence (1-based). Avoids ambiguity.',
            ],
            'context_lines' => [
                'type'    => 'integer',
                'minimum' => 0,
                'maximum' => 20,
                'default' => 3,
                'description' => 'Lines of context to show around each replacement in the response.',
            ],
        ],
        'required'             => ['path', 'old_string', 'new_string'],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'         => ['type' => 'string',  'description' => 'Resolved path of the modified file.'],
            'replacements' => ['type' => 'integer', 'description' => 'Total occurrences replaced.'],
            'size'         => ['type' => 'integer', 'description' => 'File size after edit (bytes).'],
            'line_number'  => ['type' => 'integer', 'description' => 'Line number of the first replacement.'],
            'context'      => ['type' => 'string',  'description' => 'Lines around the edit for verification.'],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_edit_file',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'title'        => 'Edit File',
            'instructions' => implode("\n", [
                'WORKFLOW:',
                '• Always read the file beforehand so you can copy the exact text to replace.',
                '• Provide a unique snippet for old_string, or use occurrence to target a specific match.',
                '• Sufficient surrounding context prevents ambiguous-match errors.',
                '• The two strings must not be identical.',
                '• An empty new_string removes the matched segment.',
                '',
                'OCCURRENCE TARGETING:',
                '• If old_string appears 3 times and you want the 2nd, set occurrence=2.',
                '• This avoids the "ambiguous match" error without enabling replace_all.',
                '',
                'CONTEXT OUTPUT:',
                '• The response includes context_lines around the edit (default 3).',
                '• Use this to verify the edit landed in the correct location.',
                '',
                'PHP SANDBOX:',
                '• PHP-executable files can only be edited inside the sandbox directory.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

// Execute runtime logic.

/**
 * Edit a file by replacing an exact string match.
 *
 * @param array $input
 * @return array|WP_Error
 */
function sprout_mcp_edit_file(array $input)
{
    $resolved = sprout_mcp_resolve_path((string) $input['path'], require_real: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    // Strict policy: all MCP edits stay inside the sandbox directory.
    $sandbox_scope = sprout_mcp_enforce_sandbox_writes($resolved);
    if (is_wp_error($sandbox_scope)) {
        return $sandbox_scope;
    }

    $sensitive = sprout_mcp_check_sensitive_file($resolved);
    if (is_wp_error($sensitive)) {
        return $sensitive;
    }

    $safe_type = sprout_mcp_assert_safe_mutable_file_type($resolved);
    if (is_wp_error($safe_type)) {
        return $safe_type;
    }

    if (!is_file($resolved)) {
        return new WP_Error('sprout_not_file', sprintf('Not an editable file: %s', $resolved));
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
    if (!is_readable($resolved) || !is_writable($resolved)) {
        return new WP_Error('sprout_not_writable', sprintf('Insufficient permissions: %s', $resolved));
    }

    $old_string    = (string) $input['old_string'];
    $new_string    = (string) $input['new_string'];
    $replace_all   = !empty($input['replace_all']);
    $occurrence    = isset($input['occurrence']) ? (int) $input['occurrence'] : null;
    $context_lines = max(0, min(20, (int) ($input['context_lines'] ?? 3)));

    if ($old_string === $new_string) {
        return new WP_Error('sprout_no_change', 'Old and new text are identical - nothing to do.');
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
    $content = file_get_contents($resolved);
    if ($content === false) {
        return new WP_Error('sprout_read_failed', sprintf('Cannot read: %s', $resolved));
    }

    $count = substr_count($content, $old_string);

    if ($count === 0) {
        return new WP_Error(
            'sprout_no_match',
            'The search text was not found. Check for exact whitespace / line-break match.'
        );
    }

    // When there are multiple matches, require either replace_all or occurrence.
    if ($count > 1 && !$replace_all && $occurrence === null) {
        return new WP_Error('sprout_ambiguous_match', sprintf(
            'Found %d matches. Set replace_all=true or specify occurrence (1–%d).',
            $count, $count
        ));
    }

    // Perform replacement.
    if ($replace_all) {
        $new_content     = str_replace($old_string, $new_string, $content);
        $replacements    = $count;
        $first_edit_pos  = strpos($content, $old_string);
    } elseif ($occurrence !== null) {
        $result          = sprout_mcp_replace_nth($content, $old_string, $new_string, $occurrence);
        if (is_wp_error($result)) {
            return $result;
        }
        $new_content     = $result['content'];
        $replacements    = 1;
        $first_edit_pos  = $result['position'];
    } else {
        $first_edit_pos  = strpos($content, $old_string);
        $new_content     = sprout_mcp_str_replace_first($content, $old_string, $new_string);
        $replacements    = 1;
    }

    // PHP sandbox + syntax check.
    if (sprout_mcp_is_php_extension($resolved)) {
        $sandbox_check = sprout_mcp_check_php_sandbox($resolved);
        if (is_wp_error($sandbox_check)) {
            return $sandbox_check;
        }

        $syntax = sprout_mcp_edit_validate_sandbox_php($resolved, $new_content);
        if (is_wp_error($syntax)) {
            return $syntax;
        }
    }

    // Write the edited content.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
    $bytes = file_put_contents($resolved, $new_content, LOCK_EX);
    if ($bytes === false) {
        return new WP_Error('sprout_write_failed', sprintf('Cannot write: %s', $resolved));
    }

    // Build context around the first edit.
    $line_number = $first_edit_pos !== false
        ? substr_count($new_content, "\n", 0, min((int) $first_edit_pos, strlen($new_content))) + 1
        : 0;

    $context = '';
    if ($context_lines > 0 && $line_number > 0) {
        $context = sprout_mcp_extract_context($new_content, $line_number, $context_lines);
    }

    return [
        'path'         => $resolved,
        'replacements' => $replacements,
        'size'         => $bytes,
        'line_number'  => $line_number,
        'context'      => $context,
    ];
}

// Helper functions.

/**
 * Replace only the first occurrence of a substring.
 */
function sprout_mcp_str_replace_first(string $haystack, string $needle, string $replacement): string
{
    $pos = strpos($haystack, $needle);
    if ($pos === false) {
        return $haystack;
    }
    return substr($haystack, 0, $pos) . $replacement . substr($haystack, $pos + strlen($needle));
}

/**
 * Replace the Nth occurrence of a substring.
 *
 * @return array{content: string, position: int}|WP_Error
 */
function sprout_mcp_replace_nth(string $haystack, string $needle, string $replacement, int $n)
{
    $offset = 0;
    for ($i = 1; $i <= $n; $i++) {
        $pos = strpos($haystack, $needle, $offset);
        if ($pos === false) {
            return new WP_Error('sprout_occurrence_missing', sprintf(
                'Only %d occurrence(s) found - cannot replace #%d.', $i - 1, $n
            ));
        }
        if ($i === $n) {
            return [
                'content'  => substr($haystack, 0, $pos) . $replacement . substr($haystack, $pos + strlen($needle)),
                'position' => $pos,
            ];
        }
        $offset = $pos + strlen($needle);
    }

    return new WP_Error('sprout_occurrence_missing', 'Unexpected error in occurrence search.');
}

/**
 * Extract N lines of context around a given line number.
 */
function sprout_mcp_extract_context(string $content, int $line_number, int $context): string
{
    $lines = explode("\n", $content);
    $start = max(0, $line_number - $context - 1);
    $end   = min(count($lines), $line_number + $context);

    $result = [];
    for ($i = $start; $i < $end; $i++) {
        $num      = $i + 1;
        $marker   = ($num === $line_number) ? '>>>' : '   ';
        $result[] = sprintf('%s %4d | %s', $marker, $num, $lines[$i]);
    }

    return implode("\n", $result);
}

/**
 * Validate PHP syntax for sandbox PHP files.
 */
function sprout_mcp_edit_validate_sandbox_php(string $resolved, string $new_content)
{
    $sandbox_dir  = sprout_mcp_get_sandbox_dir(false);
    $real_sandbox = realpath($sandbox_dir);
    $real_file    = realpath($resolved);

    if ($real_sandbox === false || $real_file === false) {
        return true;
    }
    if (!str_starts_with($real_file, $real_sandbox)) {
        return true;
    }

    return sprout_mcp_lint_php($new_content);
}
