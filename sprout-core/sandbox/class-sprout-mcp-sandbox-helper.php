<?php
/**
 * SproutOS MCP Sandbox Helper.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Sprout_MCP_Sandbox_Helper
{
    private function __construct()
    {
    }

    /**
     * @return array<int,string>
     */
    public static function discover_entries(string $sandbox_dir): array
    {
        $standalone = glob($sandbox_dir . '*.php') ?: [];
        $standalone = array_values(array_filter(
            $standalone,
            static fn (string $file): bool => !is_link($file)
        ));

        $project_dirs = glob($sandbox_dir . '*', GLOB_ONLYDIR) ?: [];
        $projects = [];

        foreach ($project_dirs as $dir) {
            if (is_link($dir)) {
                continue;
            }

            $dir_name = basename($dir);
            if ($dir_name === '' || str_starts_with($dir_name, '.')) {
                continue;
            }

            // Skip disabled project folders (e.g. my-project.disabled/).
            if (str_ends_with($dir_name, '.disabled')) {
                continue;
            }

            $entry = $dir . '/' . $dir_name . '.php';
            if (!file_exists($entry)) {
                $entry = $dir . '/index.php';
            }

            if (file_exists($entry) && !is_link($entry)) {
                $projects[] = $entry;
            }
        }

        return array_merge($standalone, $projects);
    }

    /**
     * For a given entry file, collect ALL .php files that belong to it.
     *
     * Standalone files return just themselves.
     * Project entry files return every .php in the project folder (recursively).
     *
     * @param string $entry_file Absolute path to the entry file.
     * @return array<int,string> All PHP files to lint.
     */
    public static function collect_project_php_files(string $entry_file): array
    {
        $sandbox_dir = rtrim(SPROUT_MCP_SANDBOX_DIR, '/');
        $file_dir    = dirname($entry_file);

        // Standalone file — not inside a subfolder of the sandbox.
        if (rtrim($file_dir, '/') === $sandbox_dir) {
            return [$entry_file];
        }

        // Project folder — find all .php files recursively.
        $project_root = $file_dir;
        // Walk up to find the direct child of sandbox dir (the project root).
        while (dirname($project_root) !== $sandbox_dir && dirname($project_root) !== $project_root) {
            $project_root = dirname($project_root);
        }

        $all_php = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($project_root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $all_php[] = $item->getPathname();
            }
        }

        return $all_php ?: [$entry_file];
    }

    public static function has_exec_php_lint_support(): bool
    {
        return function_exists( 'token_get_all' );
    }

    public static function resolve_php_binary(): string
    {
        if ( PHP_BINARY !== '' && @is_executable( PHP_BINARY ) ) {
            return PHP_BINARY;
        }
        $fallback = PHP_BINDIR . '/php';
        if ( @is_executable( $fallback ) ) {
            return $fallback;
        }
        return '';
    }

    /**
     * Lint a PHP file using PHP's internal tokenizer parser.
     *
     * @return array{ok: bool, message: string}
     */
    public static function lint_php_file( string $file, string $php_binary ): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $source = @file_get_contents( $file );
        if ( ! is_string( $source ) ) {
            return [
                'ok'      => false,
                'message' => 'Unable to read file for syntax validation.',
            ];
        }

        if ( ! str_contains( $source, '<?php' ) && ! str_contains( $source, '<?=' ) ) {
            $source = "<?php\n" . $source;
        }

        try {
            token_get_all( $source, TOKEN_PARSE );
            return [
                'ok'      => true,
                'message' => '',
            ];
        } catch ( \ParseError $error ) {
            return [
                'ok'      => false,
                'message' => $error->getMessage(),
            ];
        }
    }

    public static function create_validation_cache(string $file): void
    {
        $signature = self::build_validation_signature($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write.
        @file_put_contents($file . '.validated', $signature, LOCK_EX);
    }

    public static function is_validation_cache_fresh(string $file): bool
    {
        $sidecar = $file . '.validated';
        if (!file_exists($sidecar)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $stored_signature = (string) @file_get_contents($sidecar);
        if ($stored_signature === '') {
            wp_delete_file($sidecar);
            return false;
        }

        $current_signature = self::build_validation_signature($file);
        if (!hash_equals($stored_signature, $current_signature)) {
            wp_delete_file($sidecar);
            return false;
        }

        return true;
    }

    /**
     * Build a content-based signature for all PHP files linked to an entry.
     *
     * @param string $entry_file
     * @return string
     */
    private static function build_validation_signature(string $entry_file): string
    {
        $targets = self::collect_project_php_files($entry_file);
        sort($targets, SORT_STRING);

        $parts = [];
        foreach ($targets as $target) {
            if (!is_file($target) || !is_readable($target)) {
                $parts[] = $target . ':missing';
                continue;
            }

            $hash = @hash_file('sha256', $target);
            if ($hash === false) {
                $parts[] = $target . ':unhashable';
                continue;
            }

            $parts[] = $target . ':' . $hash;
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array{label: string, safe_mode: bool}
     */
    public static function auto_disable_crashed_entry(string $sandbox_dir, string $sentinel_crashed): array
    {
        if (!file_exists($sentinel_crashed)) {
            return ['label' => '', 'safe_mode' => false];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $crashed_content = trim((string) @file_get_contents($sentinel_crashed));
        if (
            $crashed_content === ''
            || $crashed_content === '1'
            || !str_starts_with($crashed_content, $sandbox_dir)
            || !file_exists($crashed_content)
            || str_ends_with($crashed_content, '.disabled')
        ) {
            return ['label' => '', 'safe_mode' => true];
        }

        $crashed_dir = dirname($crashed_content);
        $is_project = ($crashed_dir !== rtrim($sandbox_dir, '/'));

        if ($is_project) {
            $project_dir = $crashed_dir;
            $disabled_dir = $project_dir . '.disabled';
            if (!file_exists($disabled_dir)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                @rename($project_dir, $disabled_dir);
            }
            wp_delete_file($sentinel_crashed);

            return ['label' => basename($project_dir) . '/ (project)', 'safe_mode' => false];
        }

        $disabled_file = $crashed_content . '.disabled';
        if (!file_exists($disabled_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            @rename($crashed_content, $disabled_file);
        }
        wp_delete_file($sentinel_crashed);

        return ['label' => basename($crashed_content), 'safe_mode' => false];
    }

    public static function maybe_log_syntax_error(string $file, string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[SproutOS Sandbox] Syntax error in ' . basename($file) . ': ' . $message);
        }
    }

    /**
     * Scan a PHP file (and its require/include targets) for class/function
     * declarations that already exist in the current runtime.
     *
     * Returns a conflict description string, or empty if safe to load.
     *
     * @param string $file Entry file to scan.
     * @return string Conflict description, or '' if no conflict.
     */
    public static function detect_symbol_conflicts(string $file): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
        $source = @file_get_contents($file);
        if ($source === false) {
            return '';
        }

        // Also scan files referenced by require/include in the entry file.
        $dir = dirname($file);
        $all_sources = [$source];

        if (preg_match_all('/(?:require|include)(?:_once)?\s+[^;]*?[\'"]([^\'"]+\.php)[\'"]/s', $source, $inc_matches)) {
            foreach ($inc_matches[1] as $inc_path) {
                // Resolve relative paths and constant-based paths.
                $resolved = $inc_path;
                $resolved = preg_replace('/^.*?\.\s*[\'"]/', '', $resolved);
                $resolved = ltrim($resolved, '/\\');

                $full_path = $dir . '/' . $resolved;
                if (file_exists($full_path)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
                    $inc_source = @file_get_contents($full_path);
                    if ($inc_source !== false) {
                        $all_sources[] = $inc_source;
                    }
                }
            }
        }

        $combined = implode("\n", $all_sources);

        // Check for class conflicts.
        if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_]\w*)/m', $combined, $cls)) {
            foreach ($cls[1] as $class_name) {
                if (class_exists($class_name, false)) {
                    return sprintf('Class "%s" is already declared by another plugin or theme.', $class_name);
                }
            }
        }

        // Check for function conflicts.
        if (preg_match_all('/^\s*function\s+([A-Za-z_]\w*)\s*\(/m', $combined, $fns)) {
            foreach ($fns[1] as $fn_name) {
                if (function_exists($fn_name)) {
                    return sprintf('Function "%s" is already declared by another plugin or theme.', $fn_name);
                }
            }
        }

        return '';
    }

    /**
     * Auto-disable a file that has a syntax error.
     *
     * Renames foo.php → foo.php.disabled so the admin UI switcher
     * correctly shows the file as OFF and it won't be loaded again.
     * Also stores a transient notice so the admin sees the message
     * even after redirect (since admin_notices gets cleared on our page).
     *
     * @param string $file    Absolute path to the broken sandbox file.
     * @param string $reason  Human-readable error description.
     */
    public static function auto_disable_on_syntax_error(string $file, string $reason = ''): void
    {
        if ( ! file_exists($file) || str_ends_with($file, '.disabled') ) {
            return;
        }

        $disabled_path = $file . '.disabled';
        if ( ! file_exists($disabled_path) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            @rename($file, $disabled_path);
        }

        // Clean up stale validation cache — it's no longer valid.
        $validated_cache = $file . '.validated';
        if ( file_exists($validated_cache) ) {
            wp_delete_file($validated_cache);
        }

        // Store a transient so the notice survives page redirects and
        // our own remove_all_actions('admin_notices') on the plugin page.
        $notice = sprintf(
            'Sandbox file "%s" had a syntax error and was automatically disabled.',
            basename($file)
        );
        if ($reason !== '') {
            $notice .= ' Error: ' . $reason;
        }

        $existing = get_transient('sprout_mcp_sandbox_notices');
        if ( ! is_array($existing) ) {
            $existing = [];
        }
        $existing[] = $notice;
        set_transient('sprout_mcp_sandbox_notices', $existing, 300);
    }

    /**
     * Basic sanity check for sandbox entries.
     *
     * Rejects unreadable files and empty files (0 bytes) which would
     * cause warnings or no-ops. Any valid PHP file passes.
     *
     * @param string $file Absolute path to the sandbox PHP file.
     * @return array{ok: bool, message: string}
     */
    public static function validate_entry_contract(string $file): array
    {
        if (!is_readable($file)) {
            return ['ok' => false, 'message' => 'Sandbox file is not readable.'];
        }

        $size = @filesize($file);
        if ($size === false || $size === 0) {
            return ['ok' => false, 'message' => 'Sandbox file is empty (0 bytes) — skipped.'];
        }

        return ['ok' => true, 'message' => ''];
    }
}
