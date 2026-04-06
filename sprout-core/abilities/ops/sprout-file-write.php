<?php
/**
 * SproutOS MCP - File Writer
 *
 * Persists content to disk with encoding support, optional backup,
 * dry-run preview, PHP sandbox enforcement, and syntax linting.
 *
 * @package  SproutOS_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register ability definition.

wp_register_ability( 'sprout/write-file', [
    'label'       => __( 'Write File', 'sprout-os' ),
    'description' => 'Writes content to a file. PHP-executable files are restricted to the sandbox (wp-content/sproutos-mcp-sandbox/). Supports UTF-8 and base64 encoding, overwrite / append modes, automatic parent-directory creation, optional pre-write backup (.bak), and dry-run preview.',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'    => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Destination path - relative paths resolve from ABSPATH.' ],
            'content' => [ 'type' => 'string', 'description' => 'Payload to persist.' ],
            'encoding' => [
                'type' => 'string', 'enum' => [ 'utf-8', 'base64' ], 'default' => 'utf-8',
                'description' => 'How the content field is encoded.',
            ],
            'mode' => [
                'type' => 'string', 'enum' => [ 'overwrite', 'append' ], 'default' => 'overwrite',
                'description' => 'Write strategy - overwrite replaces the file, append adds to it.',
            ],
            'create_directories' => [ 'type' => 'boolean', 'default' => true, 'description' => 'Auto-create missing parent directories.' ],
            'backup'  => [ 'type' => 'boolean', 'default' => false, 'description' => 'Create a .bak snapshot before overwriting an existing file.' ],
            'dry_run' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Validate without writing; returns a preview of what would happen.' ],
        ],
        'required'             => [ 'path', 'content' ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'                => [ 'type' => 'string',  'description' => 'Resolved destination path.' ],
            'bytes_written'       => [ 'type' => 'integer', 'description' => 'Bytes persisted (0 for dry-run).' ],
            'created'             => [ 'type' => 'boolean', 'description' => 'True when a brand-new file was created.' ],
            'directories_created' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Directories that were (or would be) created.' ],
            'size'                => [ 'type' => 'integer', 'description' => 'Final file size.' ],
            'backup_path'         => [ 'type' => 'string',  'description' => 'Path to the .bak copy (empty when unused).' ],
            'dry_run'             => [ 'type' => 'boolean', 'description' => 'True when this was a preview.' ],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_write_file',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => [ 'public' => true ],
        'annotations'  => [
            'title'        => 'Write File',
            'instructions' => implode( "\n", [
                'PHP SANDBOX:',
                'PHP-executable files (.php, .phtml, .phar, etc.) can ONLY live in',
                'wp-content/sproutos-mcp-sandbox/. Non-PHP goes anywhere under ABSPATH.',
                '',
                'SAFETY:',
                'backup=true creates a .bak before overwriting.',
                'dry_run=true validates without touching disk.',
            ] ),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
] );

// Helper functions.

/**
 * Interpret the content payload based on declared encoding.
 *
 * @return string|WP_Error Decoded bytes.
 */
function sprout_mcp_unpack_payload( string $raw, string $enc ): string|WP_Error {
    if ( $enc === 'base64' ) {
        $decoded = base64_decode( $raw, true );
        if ( $decoded === false ) {
            return new WP_Error( 'sprout_base64_invalid', 'Malformed base64 payload.' );
        }
        return $decoded;
    }
    return $raw;
}

/**
 * Ensure the directory tree exists up to the given leaf.
 *
 * @return string[]|WP_Error  Paths created (empty when already present).
 */
function sprout_mcp_provision_directory( string $dir_path ): array|WP_Error {
    if ( is_dir( $dir_path ) ) {
        return [];
    }

    // Walk up to discover which levels are missing.
    $needed = [];
    $probe  = $dir_path;
    while ( ! is_dir( $probe ) && dirname( $probe ) !== $probe ) {
        $needed[] = $probe;
        $probe    = dirname( $probe );
    }

    if ( ! wp_mkdir_p( $dir_path ) ) {
        return new WP_Error( 'sprout_mkdir_failed', sprintf( 'Could not create: %s', $dir_path ) );
    }

    return array_reverse( $needed );
}

/**
 * Assemble the full source for syntax checking when appending.
 */
function sprout_mcp_combined_source( string $dest, string $new_content, string $write_mode ): string {
    if ( $write_mode !== 'append' || ! file_exists( $dest ) ) {
        return $new_content;
    }
    $prev = file_get_contents( $dest );
    return ( $prev !== false ) ? $prev . $new_content : $new_content;
}

/**
 * PHP lint check via temp file + proc_open (with 5-second timeout).
 * Gracefully degrades when exec/proc_open is unavailable.
 *
 * @return true|WP_Error
 */
function sprout_mcp_lint_php( string $source ) {
    if ( PHP_BINARY === '' ) {
        return true;
    }

    $tmp = tempnam( sys_get_temp_dir(), 'sprout_lint_' );
    if ( $tmp === false ) {
        return true;
    }

    file_put_contents( $tmp, $source, LOCK_EX );

    // Use Sandbox Helper if available (has timeout-safe proc_open).
    if ( class_exists( 'Sprout_MCP_Sandbox_Helper' ) ) {
        $binary = Sprout_MCP_Sandbox_Helper::resolve_php_binary();
        if ( $binary !== '' ) {
            $lint = Sprout_MCP_Sandbox_Helper::lint_php_file( $tmp, $binary );
            wp_delete_file( $tmp );
            if ( ! $lint['ok'] ) {
                $msg = str_replace( $tmp, 'source.php', $lint['message'] );
                return new WP_Error( 'sprout_syntax_error', 'PHP lint failed: ' . $msg );
            }
            return true;
        }
    }

    // Fallback: skip lint if no safe method available.
    wp_delete_file( $tmp );
    return true;
}

// Handle request and return response payload.

function sprout_mcp_write_file( array $input ) {

    $dest = sprout_mcp_resolve_path( (string) $input['path'], require_real: false );
    if ( is_wp_error( $dest ) ) {
        return $dest;
    }

    // Strict policy: all MCP writes stay inside the sandbox directory.
    $sandbox_scope = sprout_mcp_enforce_sandbox_writes( $dest );
    if ( is_wp_error( $sandbox_scope ) ) {
        return $sandbox_scope;
    }

    $guard = sprout_mcp_check_sensitive_file( $dest );
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }

    $enc      = (string) ( $input['encoding'] ?? 'utf-8' );
    $strategy = (string) ( $input['mode'] ?? 'overwrite' );
    $auto_dir = ( $input['create_directories'] ?? true ) !== false;
    $snapshot = ! empty( $input['backup'] );
    $preview  = ! empty( $input['dry_run'] );
    $php_file = sprout_mcp_is_php_extension( $dest );

    // Validate sandbox restrictions for PHP-executable files.
    if ( $php_file ) {
        $sandbox = sprout_mcp_check_php_sandbox( $dest );
        if ( is_wp_error( $sandbox ) ) {
            return $sandbox;
        }
    }

    // Decode incoming content payload.
    $body = sprout_mcp_unpack_payload( (string) $input['content'], $enc );
    if ( is_wp_error( $body ) ) {
        return $body;
    }

    $already_exists = file_exists( $dest );
    $is_new         = ! $already_exists;
    $parent         = dirname( $dest );

    // Ensure parent directory exists and is writable.
    if ( ! is_dir( $parent ) && ! $auto_dir ) {
        return new WP_Error( 'sprout_no_parent', sprintf( 'Parent directory absent: %s', $parent ) );
    }

    $dirs_made = sprout_mcp_provision_directory( $parent );
    if ( is_wp_error( $dirs_made ) ) {
        return $dirs_made;
    }

    // Run syntax validation for PHP content before writing.
    if ( $php_file ) {
        $full_source = sprout_mcp_combined_source( $dest, $body, $strategy );
        $lint        = sprout_mcp_lint_php( $full_source );
        if ( is_wp_error( $lint ) ) {
            return $lint;
        }
    }

    // Stop before writing when dry_run is enabled.
    if ( $preview ) {
        return [
            'path'                => $dest,
            'bytes_written'       => 0,
            'created'             => $is_new,
            'directories_created' => $dirs_made,
            'size'                => $already_exists ? (int) filesize( $dest ) : strlen( $body ),
            'backup_path'         => '',
            'dry_run'             => true,
        ];
    }

    // Create backup when requested.
    $bak_path = '';
    if ( $snapshot && $already_exists ) {
        $bak_path = $dest . '.bak';
        if ( ! copy( $dest, $bak_path ) ) {
            return new WP_Error( 'sprout_backup_failed', sprintf( 'Backup failed: %s', $bak_path ) );
        }
    }

    // Persist content to disk.
    $write_flags = LOCK_EX | ( $strategy === 'append' ? FILE_APPEND : 0 );
    $written     = file_put_contents( $dest, $body, $write_flags );

    if ( $written === false ) {
        return new WP_Error( 'sprout_write_failed', sprintf( 'Write failed: %s', $dest ) );
    }

    // Set sane permissions on newly created files.
    if ( $is_new ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        @chmod( $dest, 0644 );
    }

    return [
        'path'                => $dest,
        'bytes_written'       => $written,
        'created'             => $is_new,
        'directories_created' => $dirs_made,
        'size'                => (int) filesize( $dest ),
        'backup_path'         => $bak_path,
        'dry_run'             => false,
    ];
}
