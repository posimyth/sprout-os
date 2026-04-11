<?php
/**
 * SproutOS MCP - File Reader
 *
 * Fetches file contents with byte-range or line-range selection,
 * transparent binary detection, and rich file metadata.
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

wp_register_ability( 'sprout/read-file', [
    'label'       => __( 'Read File', 'sprout-os' ),
    'description' => 'Reads a file from the WordPress filesystem. Supports partial reads via byte offset/limit or line-range selection (start_line / end_line). Binary content is returned as base64. Response includes file metadata: total line count, byte size, MIME type, permissions, and last-modified time.',
    'category' => 'sprout-filesystem',

    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'path' => [
                'type'        => 'string',
                'minLength'   => 1,
                'description' => 'File path - relative paths resolve from ABSPATH.',
            ],
            'offset' => [
                'type'        => 'integer',
                'minimum'     => 0,
                'default'     => 0,
                'description' => 'Starting byte position for partial reads.',
            ],
            'limit' => [
                'type'        => 'integer',
                'default'     => 1_048_576,
                'description' => 'Max bytes to read (-1 for the full file).',
            ],
            'start_line' => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Return content starting at this line number (1-based). '
                    . 'When set, offset/limit are ignored and line-based reading is used.',
            ],
            'end_line' => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Return content up to and including this line number. '
                    . 'Requires start_line. Omit to read from start_line to end-of-file.',
            ],
            'head' => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Shortcut: read only the first N lines of the file.',
            ],
            'tail' => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Shortcut: read only the last N lines of the file.',
            ],
        ],
        'required'             => [ 'path' ],
        'additionalProperties' => false,
    ],

    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'path'          => [ 'type' => 'string',  'description' => 'Resolved absolute file path.' ],
            'content'       => [ 'type' => 'string',  'description' => 'File content (text or base64-encoded).' ],
            'encoding'      => [ 'type' => 'string',  'description' => '"utf-8" or "base64".' ],
            'size'          => [ 'type' => 'integer', 'description' => 'Total file size in bytes.' ],
            'bytes_read'    => [ 'type' => 'integer', 'description' => 'Bytes actually returned.' ],
            'truncated'     => [ 'type' => 'boolean', 'description' => 'True when content was truncated.' ],
            'mime_type'     => [ 'type' => 'string',  'description' => 'File MIME type (auto-detected).' ],
            'line_count'    => [ 'type' => 'integer', 'description' => 'Total lines in the file.' ],
            'permissions'   => [ 'type' => 'string',  'description' => 'Octal permission string (e.g. "0644").' ],
            'last_modified' => [ 'type' => 'string',  'description' => 'Last-modified time (ISO 8601).' ],
        ],
    ],

    'execute_callback'    => 'sprout_mcp_read_file',
    'permission_callback' => 'sprout_mcp_permission_callback',

    'meta' => [
        'show_in_rest' => true,
        'mcp'          => [ 'public' => true ],
        'annotations'  => [
            'title'        => 'Read File',
            'instructions' => implode( "\n", [
                'LINE RANGE READING:',
                'Use start_line / end_line for specific lines (1-based).',
                'Use head=N for the first N lines, tail=N for the last N.',
                '',
                'BYTE RANGE READING:',
                'Use offset + limit for byte-level partial reads.',
                'Set limit=-1 to read the entire file.',
                '',
                'METADATA:',
                'Response always includes line_count, size, permissions, last_modified.',
                'Binary files are auto-detected and returned as base64.',
            ] ),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
] );

// Handle request and return response payload.

function sprout_mcp_read_file( array $input ) {

    $resolved = sprout_mcp_resolve_path( (string) $input['path'], require_real: true );
    if ( is_wp_error( $resolved ) ) {
        return $resolved;
    }

    $guard = sprout_mcp_check_sensitive_file( $resolved );
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }

    if ( ! is_file( $resolved ) ) {
        return new WP_Error( 'sprout_not_file', sprintf( 'Not a regular file: %s', $resolved ) );
    }
    if ( ! is_readable( $resolved ) ) {
        return new WP_Error( 'sprout_file_unreadable', sprintf( 'Cannot read: %s', $resolved ) );
    }

    $file_size = (int) filesize( $resolved );
    $mime      = sprout_mcp_detect_mime( $resolved );
    $file_stat = stat( $resolved );

    // Base metadata - always returned.
    $envelope = [
        'path'          => $resolved,
        'size'          => $file_size,
        'mime_type'     => $mime,
        'line_count'    => sprout_mcp_count_lines( $resolved ),
        'permissions'   => $file_stat ? substr( decoct( $file_stat['mode'] ), -4 ) : '0000',
        'last_modified' => $file_stat ? gmdate( 'c', $file_stat['mtime'] ) : '',
    ];

    // Decide read strategy.
    $wants_lines = isset( $input['start_line'] ) || isset( $input['head'] ) || isset( $input['tail'] );

    return $wants_lines
        ? sprout_mcp_fetch_lines( $resolved, $input, $envelope )
        : sprout_mcp_fetch_bytes( $resolved, $input, $file_size, $mime, $envelope );
}

// Read file content by byte range.

function sprout_mcp_fetch_bytes( string $filepath, array $input, int $total_bytes, string $mime, array $envelope ): array|WP_Error {

    $skip  = max( 0, (int) ( $input['offset'] ?? 0 ) );
    $cap   = (int) ( $input['limit'] ?? 1_048_576 );
    $want  = ( $cap === -1 ) ? ( $total_bytes - $skip ) : $cap;

    // Use stream wrapper for partial reads.
    $ctx    = stream_context_create();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $stream = fopen( $filepath, 'rb', false, $ctx );
    if ( $stream === false ) {
        return new WP_Error( 'sprout_open_failed', sprintf( 'Cannot open: %s', $filepath ) );
    }

    if ( $skip > 0 ) {
        fseek( $stream, $skip );
    }

    $raw = stream_get_contents( $stream, max( 1, $want ) );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose( $stream );

    if ( $raw === false ) {
        return new WP_Error( 'sprout_read_failed', sprintf( 'Read error: %s', $filepath ) );
    }

    $got       = strlen( $raw );
    $was_cut   = $cap !== -1 && ( $skip + $got ) < $total_bytes;
    $text_safe = sprout_mcp_content_is_text( $mime, $raw );

    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary content encoding for safe JSON transport.
    $envelope['content']    = $text_safe ? $raw : base64_encode( $raw );
    $envelope['encoding']   = $text_safe ? 'utf-8' : 'base64';
    $envelope['bytes_read'] = $got;
    $envelope['truncated']  = $was_cut;

    return $envelope;
}

// Read file content by line range.

function sprout_mcp_fetch_lines( string $filepath, array $input, array $envelope ): array|WP_Error {

    $rows = file( $filepath );
    if ( $rows === false ) {
        return new WP_Error( 'sprout_read_failed', sprintf( 'Cannot read: %s', $filepath ) );
    }

    $row_count = count( $rows );
    $envelope['line_count'] = $row_count;

    // Resolve requested window.
    if ( isset( $input['tail'] ) ) {
        $take  = min( (int) $input['tail'], $row_count );
        $from  = $row_count - $take;
        $until = $row_count - 1;
    } elseif ( isset( $input['head'] ) ) {
        $from  = 0;
        $until = min( (int) $input['head'], $row_count ) - 1;
    } else {
        $from  = max( 0, (int) ( $input['start_line'] ?? 1 ) - 1 );
        $until = isset( $input['end_line'] )
            ? min( (int) $input['end_line'] - 1, $row_count - 1 )
            : $row_count - 1;
    }

    $slice   = array_slice( $rows, $from, $until - $from + 1 );
    $payload = implode( '', $slice );

    $envelope['content']    = $payload;
    $envelope['encoding']   = 'utf-8';
    $envelope['bytes_read'] = strlen( $payload );
    $envelope['truncated']  = ( $until < $row_count - 1 );

    return $envelope;
}

// Helper functions.

/**
 * Detect MIME type for a file on disk.
 */
function sprout_mcp_detect_mime( string $filepath ): string {
    if ( function_exists( 'mime_content_type' ) ) {
        $result = mime_content_type( $filepath );
        if ( is_string( $result ) && $result !== '' ) {
            return $result;
        }
    }
    return 'application/octet-stream';
}

/**
 * Count newlines without loading the entire file into memory.
 */
function sprout_mcp_count_lines( string $filepath ): int {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $fh = fopen( $filepath, 'rb' );
    if ( $fh === false ) {
        return 0;
    }
    $newlines = 0;
    while ( ! feof( $fh ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $block = fread( $fh, 65_536 );
        if ( $block === false ) {
            break;
        }
        $newlines += substr_count( $block, "\n" );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose( $fh );
    return $newlines;
}

/**
 * Decide whether content is safe to return as UTF-8 text.
 */
function sprout_mcp_content_is_text( string $mime, string $raw ): bool {
    return sprout_mcp_mime_is_textual( $mime ) && mb_check_encoding( $raw, 'UTF-8' );
}

/**
 * Does this MIME string represent a textual format?
 *
 * Uses a combined regex instead of prefix/suffix arrays.
 */
function sprout_mcp_mime_is_textual( string $mime ): bool {
    // Anything under text/* is textual.
    if ( strncmp( $mime, 'text/', 5 ) === 0 ) {
        return true;
    }

    // Structured text formats served under application/*.
    $app_textual = [
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-httpd-php',
        'application/sql',
        'application/x-sh',
        'application/xhtml+xml',
        'application/ld+json',
        'application/graphql',
        'application/toml',
    ];
    if ( in_array( $mime, $app_textual, true ) ) {
        return true;
    }

    // Structured-syntax suffixes (RFC 6839).
    if ( preg_match( '/\+(xml|json|yaml|wbxml)$/', $mime ) ) {
        return true;
    }

    return false;
}
