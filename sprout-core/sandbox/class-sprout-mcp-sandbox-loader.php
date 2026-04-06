<?php
/**
 * SproutOS MCP Sandbox Loader.
 *
 * @package SproutOS_MCP
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Sprout_MCP_Sandbox_Load_State
{
    public string $sandbox_dir;
    public string $sentinel_loading;
    public string $sentinel_crashed;
    public bool $safe_mode = false;
    public bool $halt = false;

    /** @var string */
    public string $auto_disabled_label = '';

    /** @var array<int,string> */
    public array $discovered_files = [];

    /** @var array<int,string> */
    public array $validated_files = [];

    /** @var array<string,string> */
    public array $syntax_errors = [];

    public function __construct(string $sandbox_dir)
    {
        $this->sandbox_dir = $sandbox_dir;
        $this->sentinel_loading = $sandbox_dir . '.loading';
        $this->sentinel_crashed = $sandbox_dir . '.crashed';
    }
}

interface Sprout_MCP_Sandbox_Strategy
{
    public function run(Sprout_MCP_Sandbox_Load_State $state): void;
}

final class Sprout_MCP_Sandbox_Preflight_Strategy implements Sprout_MCP_Sandbox_Strategy
{
    private int $legacy_crash_threshold;

    public function __construct(int $legacy_crash_threshold = 120)
    {
        $this->legacy_crash_threshold = $legacy_crash_threshold;
    }

    public function run(Sprout_MCP_Sandbox_Load_State $state): void
    {
        $recovery = Sprout_MCP_Sandbox_Helper::auto_disable_crashed_entry(
            $state->sandbox_dir,
            $state->sentinel_crashed
        );

        $state->auto_disabled_label = $recovery['label'];
        $state->safe_mode = (bool) $recovery['safe_mode'];

        // Legacy crash detection: a stale .loading sentinel from a previous
        // request that died without the shutdown handler cleaning up.
        // Only trigger safe mode when ALL conditions are met:
        //  1. .loading file exists and is older than threshold
        //  2. The process that wrote it is no longer running
        //  3. Sandbox actually has loadable files (not an unrelated slow request)
        if (file_exists($state->sentinel_loading)) {
            clearstatcache(true, $state->sentinel_loading);
            $age = time() - (int) filemtime($state->sentinel_loading);

            if ($age > $this->legacy_crash_threshold) {
                $stale_pid = trim((string) @file_get_contents($state->sentinel_loading));
                $pid_alive = ($stale_pid !== '' && $stale_pid !== '0'
                    && function_exists('posix_getpgid')
                    && @posix_getpgid((int) $stale_pid) !== false);

                if ($pid_alive) {
                    // Process still running — this is a slow request, not a crash.
                    // Do nothing, let it finish.
                } else {
                    // Process dead + sentinel stale = likely crashed.
                    // But only enter safe mode if sandbox has files to load.
                    $has_files = Sprout_MCP_Sandbox_Helper::discover_entries($state->sandbox_dir) !== [];
                    wp_delete_file($state->sentinel_loading);

                    if ($has_files) {
                        @file_put_contents($state->sentinel_crashed, '1', LOCK_EX);
                        $state->safe_mode = true;
                    }
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$state->safe_mode && (sanitize_text_field(wp_unslash($_GET['sprout_mcp_safe_mode'] ?? '')) === '1')) {
            if (function_exists('current_user_can') && current_user_can('manage_options')) {
                $state->safe_mode = true;
            }
        }

        $this->register_admin_notices($state);

        if ($state->safe_mode) {
            do_action('sprout_mcp_sandbox_safe_mode', $state->sentinel_crashed);
            $state->halt = true;
        }
    }

    private function register_admin_notices(Sprout_MCP_Sandbox_Load_State $state): void
    {
        $auto_disabled_label = $state->auto_disabled_label;
        $sentinel_crashed = $state->sentinel_crashed;

        add_action('admin_notices', static function () use ($auto_disabled_label, $sentinel_crashed): void {
            if ($auto_disabled_label !== '') {
                wp_admin_notice(
                    sprintf(
                        '<strong>%s</strong> %s',
                        esc_html__('SproutOS Sandbox:', 'sprout-os'),
                        sprintf(
                            /* translators: %s: filename that caused the error */
                            esc_html__('The file "%s" caused a fatal error and was automatically disabled. All other sandbox files continue to run normally. Fix the file and re-enable it from the Sandbox tab.', 'sprout-os'),
                            '<code>' . esc_html($auto_disabled_label) . '</code>'
                        )
                    ),
                    ['type' => 'warning', 'dismissible' => true]
                );
            }

            if (!file_exists($sentinel_crashed)) {
                return;
            }

            wp_admin_notice(
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__('SproutOS Sandbox: Safe mode is active.', 'sprout-os'),
                    esc_html__(
                        'A sandbox file caused a fatal error that could not be automatically isolated. All sandbox files are suspended. Fix or delete the broken file, then remove the .crashed file from the sandbox directory to resume.',
                        'sprout-os'
                    )
                ),
                ['type' => 'error', 'dismissible' => false]
            );
        });
    }
}

final class Sprout_MCP_Sandbox_Discovery_Strategy implements Sprout_MCP_Sandbox_Strategy
{
    public function run(Sprout_MCP_Sandbox_Load_State $state): void
    {
        $state->discovered_files = Sprout_MCP_Sandbox_Helper::discover_entries($state->sandbox_dir);
    }
}

final class Sprout_MCP_Sandbox_Validation_Strategy implements Sprout_MCP_Sandbox_Strategy
{
    public function run(Sprout_MCP_Sandbox_Load_State $state): void
    {
        if ($state->discovered_files === []) {
            return;
        }

        $can_lint = Sprout_MCP_Sandbox_Helper::has_exec_php_lint_support();
        $php_binary = Sprout_MCP_Sandbox_Helper::resolve_php_binary();

        foreach ($state->discovered_files as $file) {
            $contract = Sprout_MCP_Sandbox_Helper::validate_entry_contract($file);
            if (!$contract['ok']) {
                $state->syntax_errors[basename($file)] = $contract['message'];
                continue;
            }

            if (Sprout_MCP_Sandbox_Helper::is_validation_cache_fresh($file)) {
                $state->validated_files[] = $file;
                continue;
            }

            if (!$can_lint) {
                $state->validated_files[] = $file;
                continue;
            }

            // Lint the entry file AND all PHP files in its project folder.
            $files_to_lint = Sprout_MCP_Sandbox_Helper::collect_project_php_files($file);
            $lint_failed   = false;

            foreach ($files_to_lint as $lint_target) {
                $lint = Sprout_MCP_Sandbox_Helper::lint_php_file($lint_target, $php_binary);
                if (!$lint['ok']) {
                    $label = str_replace(SPROUT_MCP_SANDBOX_DIR, '', $lint_target);
                    $state->syntax_errors[$label] = $lint['message'];
                    Sprout_MCP_Sandbox_Helper::maybe_log_syntax_error($lint_target, $lint['message']);
                    $lint_failed = true;
                    break; // Stop on first error in the project.
                }
            }

            if (!$lint_failed) {
                Sprout_MCP_Sandbox_Helper::create_validation_cache($file);
                $state->validated_files[] = $file;
                continue;
            }

            // NOTE: We do NOT auto-disable on syntax errors.
            // The file stays enabled (.php) but is skipped from loading.
            // The admin sandbox tab shows a red "Syntax Error" indicator.
            // User can fix the error and refresh — file will load automatically.
            // Auto-disable only happens on RUNTIME fatal errors (via shutdown handler).
        }

        // if ($state->syntax_errors !== []) {
        //     $syntax_errors = $state->syntax_errors;
        //     add_action('admin_notices', static function () use ($syntax_errors): void {
        //         foreach ($syntax_errors as $filename => $error) {
        //             wp_admin_notice(
        //                 sprintf(
        //                     '<strong>%s</strong> %s<br><code style="display:block;margin-top:6px;padding:8px;background:#f8f8fb;border-radius:4px;font-size:12px;">%s</code>',
        //                     esc_html__('SproutOS Sandbox:', 'sprout-os'),
        //                     sprintf(
        //                         esc_html__('Syntax error in "%s" - file skipped until fixed:', 'sprout-os'),
        //                         '<code>' . esc_html($filename) . '</code>'
        //                     ),
        //                     esc_html(trim($error))
        //                 ),
        //                 ['type' => 'warning', 'dismissible' => true]
        //             );
        //         }
        //     });
        // }
    }
}

final class Sprout_MCP_Sandbox_Execution_Strategy implements Sprout_MCP_Sandbox_Strategy
{
    public function run(Sprout_MCP_Sandbox_Load_State $state): void
    {
        if ($state->validated_files === []) {
            return;
        }

        do_action('sprout_mcp_before_sandbox_load', $state->validated_files);
        $loader_marker = (string) wp_generate_uuid4();
        @file_put_contents($state->sentinel_loading, $loader_marker, LOCK_EX);

        $current_file_ref = '';
        register_shutdown_function(static function () use ($state, &$current_file_ref): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }

            $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
            if (!($error['type'] & $fatal_types)) {
                return;
            }

            $error_file = (string) ($error['file'] ?? '');
            $crashed_file = '';

            if ($current_file_ref !== '' && file_exists($current_file_ref)) {
                $crashed_file = $current_file_ref;
            } elseif (str_starts_with($error_file, $state->sandbox_dir)) {
                $crashed_file = $error_file;
            }

            if ($crashed_file !== '') {
                @file_put_contents($state->sentinel_crashed, $crashed_file, LOCK_EX);
            } else {
                @file_put_contents($state->sentinel_crashed, '1', LOCK_EX);
            }

            if (file_exists($state->sentinel_loading)) {
                wp_delete_file($state->sentinel_loading);
            }
        });

        $loaded = [];
        foreach ($state->validated_files as $file) {
            // Pre-load conflict check: skip files whose classes/functions
            // are already declared by an installed plugin or theme.
            $conflict = Sprout_MCP_Sandbox_Helper::detect_symbol_conflicts($file);
            if ($conflict !== '') {
                $state->syntax_errors[basename($file)] = $conflict;
                Sprout_MCP_Sandbox_Helper::maybe_log_syntax_error($file, $conflict);
                continue;
            }

            $current_file_ref = $file;
            require_once $file;
            $loaded[] = $file;
        }
        $current_file_ref = '';

        if (file_exists($state->sentinel_loading)) {
            wp_delete_file($state->sentinel_loading);
        }

        do_action('sprout_mcp_after_sandbox_load', $loaded);
    }
}

final class Sprout_MCP_Sandbox_Loader
{
    public static function boot(): void
    {
        $state = new Sprout_MCP_Sandbox_Load_State(SPROUT_MCP_SANDBOX_DIR);

        if (!is_dir($state->sandbox_dir)) {
            return;
        }

        if (!sprout_mcp_is_sandbox_enabled()) {
            return;
        }

        if (!sprout_mcp_is_enabled()) {
            return;
        }

        $strategies = [
            new Sprout_MCP_Sandbox_Preflight_Strategy(),
            new Sprout_MCP_Sandbox_Discovery_Strategy(),
            new Sprout_MCP_Sandbox_Validation_Strategy(),
            new Sprout_MCP_Sandbox_Execution_Strategy(),
        ];

        foreach ($strategies as $strategy) {
            $strategy->run($state);
            if ($state->halt) {
                return;
            }
        }
    }
}
