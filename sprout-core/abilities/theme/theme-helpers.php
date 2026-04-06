<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve an active theme root by scope.
 */
function sprout_mcp_get_theme_root_by_scope(string $theme_scope): string
{
    $theme_scope = $theme_scope === 'parent' ? 'parent' : 'child';
    $root = $theme_scope === 'parent' ? get_template_directory() : get_stylesheet_directory();

    return wp_normalize_path((string) $root);
}

/**
 * Resolve a relative theme file path to a safe absolute path.
 *
 * Only files inside the active parent or child theme are allowed.
 *
 * @return string|WP_Error
 */
function sprout_mcp_resolve_theme_file_path(string $theme_scope, string $relative_path)
{
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($relative_path === '') {
        return new WP_Error('missing_relative_path', __('The relative_path parameter is required.', 'sprout-os'));
    }

    if (str_contains($relative_path, '../') || str_contains($relative_path, '..\\')) {
        return new WP_Error('invalid_relative_path', __('Path traversal is not allowed.', 'sprout-os'));
    }

    $root = sprout_mcp_get_theme_root_by_scope($theme_scope);
    $absolute = wp_normalize_path($root . '/' . $relative_path);

    if (!str_starts_with($absolute, trailingslashit($root))) {
        return new WP_Error('invalid_theme_path', __('The requested path is outside the allowed theme directory.', 'sprout-os'));
    }

    return $absolute;
}

/**
 * Validate the file type we allow for theme editing.
 */
function sprout_mcp_is_supported_theme_extension(string $absolute_path): bool
{
    $extension = strtolower((string) pathinfo($absolute_path, PATHINFO_EXTENSION));
    return in_array($extension, ['php', 'css', 'js', 'json', 'html', 'txt'], true);
}

/**
 * Validate PHP syntax before saving theme PHP files.
 *
 * @return true|WP_Error
 */
function sprout_mcp_validate_theme_php_syntax(string $content)
{
    $tmp_file = '';

    if (function_exists('wp_tempnam')) {
        $tmp_file = (string) wp_tempnam('sprout-theme-php');
    }

    if ($tmp_file === '') {
        $tmp_dir = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $fallback = tempnam($tmp_dir, 'sprout-theme-php-');
        $tmp_file = $fallback !== false ? $fallback : '';
    }

    if (!$tmp_file) {
        return new WP_Error('temp_file_failed', __('Unable to create a temporary file for PHP validation.', 'sprout-os'));
    }

    file_put_contents($tmp_file, $content);

    // Use Sandbox Helper's timeout-safe lint if available.
    if ( class_exists( 'Sprout_MCP_Sandbox_Helper' ) ) {
        $binary = Sprout_MCP_Sandbox_Helper::resolve_php_binary();
        if ( $binary !== '' ) {
            $lint = Sprout_MCP_Sandbox_Helper::lint_php_file( $tmp_file, $binary );
            wp_delete_file( $tmp_file );
            if ( ! $lint['ok'] ) {
                return new WP_Error(
                    'php_syntax_error',
                    /* translators: %s: lint error details */
                    sprintf( __( 'PHP syntax validation failed: %s', 'sprout-os' ), $lint['message'] )
                );
            }
            return true;
        }
    }

    // Fallback: skip lint if no safe method available.
    wp_delete_file($tmp_file);
    return true;
}

/**
 * Resolve a PHP CLI binary for syntax validation.
 *
 * @return string|WP_Error
 */
function sprout_mcp_get_php_cli_binary()
{
    $candidates = array_filter([
        defined('PHP_BINARY') ? PHP_BINARY : '',
        '/Applications/MAMP/bin/php/php8.3.14/bin/php',
        '/Applications/MAMP/bin/php/php8.2.26/bin/php',
        '/opt/homebrew/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php',
        'php',
    ]);

    foreach ($candidates as $candidate) {
        $candidate = (string) $candidate;

        if ($candidate === 'php') {
            $output = [];
            $code = 0;
            exec('command -v php 2>/dev/null', $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return trim((string) $output[0]);
            }

            continue;
        }

        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return new WP_Error(
        'php_cli_missing',
        __('PHP CLI binary was not found for syntax validation. Set a valid PHP binary path or install PHP CLI.', 'sprout-os')
    );
}

/**
 * Recursively list files within an allowed theme path.
 *
 * @return array<int, array<string, mixed>>|WP_Error
 */
function sprout_mcp_list_theme_files(string $theme_scope, string $subdir = '', int $max_files = 200)
{
    $base_path = sprout_mcp_resolve_theme_file_path($theme_scope, $subdir === '' ? '.' : $subdir);
    if (is_wp_error($base_path)) {
        return $base_path;
    }

    if (!is_dir($base_path)) {
        return new WP_Error('theme_directory_not_found', __('The requested theme directory was not found.', 'sprout-os'));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base_path, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $absolute = wp_normalize_path($file->getPathname());
        if (!sprout_mcp_is_supported_theme_extension($absolute)) {
            continue;
        }

        $theme_root = sprout_mcp_get_theme_root_by_scope($theme_scope);
        $relative = ltrim(str_replace($theme_root, '', $absolute), '/');

        $files[] = [
            'relative_path' => $relative,
            'size' => (int) $file->getSize(),
            'modified' => (int) $file->getMTime(),
        ];

        if (count($files) >= $max_files) {
            break;
        }
    }

    return $files;
}
