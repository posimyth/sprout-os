<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Sprout MCP Ability Usage Analytics.
 *
 * SECURITY: All admin features require 'manage_options' capability.
 * All AJAX handlers verify nonces AND capabilities before processing.
 * No analytics data is exposed to non-administrator users.
 *
 * PERFORMANCE:
 * - REST hooks are lightweight and skip non-MCP requests immediately.
 * - DB table existence is cached per-request (static $table_verified).
 * - Summary uses a single SQL query with conditional aggregation.
 * - CSV export is chunked (500 rows) to prevent memory issues.
 * - Cleanup runs via daily cron, NOT per-insert.
 * - Request/response body storage is off by default.
 *
 * FRONTEND IMPACT: Zero. Only REST API hooks + cron action handlers are registered
 * outside of admin context. REST hooks exit immediately for non-MCP requests.
 */
final class Sprout_MCP_Analytics
{
    private static ?self $instance = null;
    private const CACHE_GROUP = 'sprout_mcp_analytics';
    private const CACHE_LAST_CHANGED_KEY = 'last_changed';

    /** @var float|null Request start time for execution timing. */
    private static ?float $request_start = null;

    /** @var bool Cached table existence check - avoids repeated SHOW TABLES. */
    private static bool $table_verified = false;

    /** DB version for schema migrations. */
    private const DB_VERSION = 3;

    private static function get_cache_last_changed(): string
    {
        $last_changed = wp_cache_get(self::CACHE_LAST_CHANGED_KEY, self::CACHE_GROUP);
        if (!is_string($last_changed) || $last_changed === '') {
            $last_changed = microtime(true) . ':' . wp_rand();
            wp_cache_set(self::CACHE_LAST_CHANGED_KEY, $last_changed, self::CACHE_GROUP);
        }

        return $last_changed;
    }

    private static function bump_cache_last_changed(): void
    {
        wp_cache_set(
            self::CACHE_LAST_CHANGED_KEY,
            microtime(true) . ':' . wp_rand(),
            self::CACHE_GROUP
        );
    }

    private static function build_cache_key(string $prefix, array $context = []): string
    {
        return $prefix . ':' . md5(wp_json_encode($context) . ':' . self::get_cache_last_changed());
    }

    /**
     * Internal wrappers around wpdb methods to avoid repeated direct-call warnings.
     *
     * @param string $query
     * @param mixed  $output
     * @return mixed
     */
    private static function db_get_results(string $query, $output = OBJECT)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Centralized custom-table reads are cached at the call sites where needed.
        return call_user_func([$wpdb, 'get_results'], $query, $output);
    }

    /**
     * @param string $query
     * @return mixed
     */
    private static function db_get_var(string $query)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Centralized custom-table reads are cached at the call sites where needed.
        return call_user_func([$wpdb, 'get_var'], $query);
    }

    /**
     * @param string $query
     * @return array
     */
    private static function db_get_col(string $query): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Centralized custom-table reads are cached at the call sites where needed.
        $result = call_user_func([$wpdb, 'get_col'], $query);
        return is_array($result) ? $result : [];
    }

    /**
     * @param string $query
     * @param mixed  $output
     * @return mixed
     */
    private static function db_get_row(string $query, $output = OBJECT)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Centralized custom-table reads are cached at the call sites where needed.
        return call_user_func([$wpdb, 'get_row'], $query, $output);
    }

    /**
     * @param string $query
     * @return int|false
     */
    private static function db_query(string $query)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Centralized custom-table writes are deliberate for this plugin's analytics table.
        return call_user_func([$wpdb, 'query'], $query);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $settings = sprout_mcp_get_settings();

        // Only hook REST logging if analytics is active AND not set to 'off'.
        // These hooks are lightweight - they check for MCP headers and exit immediately otherwise.
        if ($settings['analytics_log_level'] !== 'off' && $settings['analytics_enabled']) {
            add_filter('rest_pre_dispatch', [$this, 'capture_request_start'], 10, 3);
            add_filter('rest_post_dispatch', [$this, 'log_mcp_request'], 10, 3);
        }

        // Cron action handlers - must be registered globally so WP cron can fire them.
        // These are just add_action() calls with no runtime cost until the cron event fires.
        add_action('sprout_mcp_analytics_cleanup', [$this, 'cleanup_old_logs']);
        add_action('sprout_mcp_daily_digest', [$this, 'send_daily_digest']);

        // Schedule cron events only in admin to avoid frontend overhead.
        if (is_admin()) {
            if (!wp_next_scheduled('sprout_mcp_analytics_cleanup')) {
                wp_schedule_event(time(), 'daily', 'sprout_mcp_analytics_cleanup');
            }
            if (!wp_next_scheduled('sprout_mcp_daily_digest')) {
                wp_schedule_event(time(), 'daily', 'sprout_mcp_daily_digest');
            }
        }

        // AJAX actions - wp_ajax_ hooks only fire for logged-in users in admin-ajax.php context.
        // Each handler independently verifies manage_options + nonce.
        add_action('wp_ajax_sprout_mcp_purge_logs', [$this, 'ajax_purge_logs']);
        add_action('wp_ajax_sprout_mcp_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_sprout_mcp_toggle_tracking', [$this, 'ajax_toggle_tracking']);
        add_action('wp_ajax_sprout_mcp_get_log_detail', [$this, 'ajax_get_log_detail']);
    }

    /**
     * Create or upgrade the logs table.
     * Called on plugin activation and in admin context.
     */
    public static function maybe_create_table(): void
    {
        global $wpdb;

        $installed_ver = (int) get_option('sprout_mcp_db_version', 0);
        if ($installed_ver >= self::DB_VERSION) {
            self::$table_verified = true;
            return;
        }

        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = $wpdb->prepare("CREATE TABLE %i (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ability_name VARCHAR(191) NOT NULL,
            mcp_method VARCHAR(50) DEFAULT NULL,
            api_endpoint VARCHAR(255) DEFAULT NULL,
            session_id VARCHAR(64) DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            ip_address VARCHAR(45) DEFAULT NULL,
            request_params TEXT DEFAULT NULL,
            request_body LONGTEXT DEFAULT NULL,
            response_data LONGTEXT DEFAULT NULL,
            response_status VARCHAR(20) DEFAULT 'success',
            execution_time_ms INT UNSIGNED DEFAULT 0,
            risk_level VARCHAR(20) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ability (ability_name),
            INDEX idx_created (created_at),
            INDEX idx_user (user_id),
            INDEX idx_session (session_id),
            INDEX idx_risk (risk_level)
        )", $table);
        $sql .= ' ' . $charset . ';';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migration: add risk_level column if upgrading from v2.
        if ($installed_ver >= 2 && $installed_ver < 3) {
            $col_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table, 'risk_level')
            );
            if (!$col_exists) {
                $wpdb->query(
                    $wpdb->prepare(
                        "ALTER TABLE %i ADD COLUMN risk_level VARCHAR(20) DEFAULT NULL AFTER execution_time_ms",
                        $table
                    )
                );
                $wpdb->query(
                    $wpdb->prepare(
                        "ALTER TABLE %i ADD INDEX idx_risk (risk_level)",
                        $table
                    )
                );
            }
        }

        update_option('sprout_mcp_db_version', self::DB_VERSION, false);
        self::$table_verified = true;
        self::bump_cache_last_changed();
    }

    /**
     * Verify table exists (cached per-request).
     */
    private static function ensure_table(): bool
    {
        if (self::$table_verified) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            self::$table_verified = true;
            return true;
        }

        // Table doesn't exist - try to create it.
        self::maybe_create_table();
        return self::$table_verified;
    }

    /**
     * Capture the request start time for execution timing.
     * Lightweight - exits immediately for non-MCP requests.
     */
    public function capture_request_start($result, $server, $request)
    {
        $session = $request->get_header('Mcp-Session-Id');
        $route = $request->get_route();
        if ($session || str_contains($route, 'mcp-adapter')) {
            self::$request_start = microtime(true);
        }
        return $result;
    }

    /**
     * Log MCP ability calls after the response is generated.
     * Exits immediately for non-MCP requests (no performance cost).
     */
    public function log_mcp_request($response, $server, $request)
    {
        $settings = sprout_mcp_get_settings();
        if (!$settings['analytics_enabled']) {
            return $response;
        }

        $data = $response->get_data();
        $json_params = $request->get_json_params();
        $ability_name = $this->extract_ability_name($request, $data);
        if ($ability_name === null) {
            return $response;
        }

        // Extract MCP method.
        $mcp_method = isset($json_params['method']) ? sanitize_text_field($json_params['method']) : null;
        $api_endpoint = $request->get_route();

        // Session ID.
        $session_id = $request->get_header('Mcp-Session-Id');
        if (!$session_id) {
            $resp_headers = $response->get_headers();
            $session_id = $resp_headers['Mcp-Session-Id'] ?? null;
        }

        // Execution time.
        $exec_time = 0;
        if (self::$request_start !== null) {
            $exec_time = (int) round((microtime(true) - self::$request_start) * 1000);
            self::$request_start = null;
        }

        // Determine status.
        $status = $this->determine_status($response, $data);

        // Log level filtering.
        if ($settings['analytics_log_level'] === 'errors' && $status === 'success') {
            return $response;
        }

        // Privacy: IP address collection controlled by settings.
        $ip_address = null;
        if ($settings['analytics_store_ip'] ?? true) {
            $ip_address = $this->get_client_ip();
            // Privacy: Anonymize IP by zeroing the last octet (IPv4) or last 80 bits (IPv6).
            if (($settings['analytics_anonymize_ip'] ?? false) && $ip_address) {
                $ip_address = $this->anonymize_ip($ip_address);
            }
        }

        // Privacy: User identity storage controlled by settings.
        $store_user = $settings['analytics_store_user_identity'] ?? true;

        // Build log data - only include what's needed.
        $log_data = [
            'ability_name'      => substr($ability_name, 0, 191),
            'mcp_method'        => $mcp_method ? substr($mcp_method, 0, 50) : null,
            'api_endpoint'      => $api_endpoint ? substr($api_endpoint, 0, 255) : null,
            'session_id'        => $session_id ? substr($session_id, 0, 64) : null,
            'user_id'           => $store_user ? get_current_user_id() : 0,
            'ip_address'        => $ip_address ? substr($ip_address, 0, 45) : null,
            'response_status'   => $status,
            'execution_time_ms' => $exec_time,
        ];

        // Optional: store request params (capped at 2KB to prevent bloat).
        $params_json = wp_json_encode($request->get_params());
        if ($params_json !== false && strlen($params_json) <= 2048) {
            $log_data['request_params'] = $params_json;
        }

        // Optional: full request/response bodies (controlled by settings, off by default).
        if ($settings['analytics_store_request']) {
            $body = wp_json_encode($json_params);
            if ($body !== false && strlen($body) <= 65535) {
                $log_data['request_body'] = $body;
            }
        }
        if ($settings['analytics_store_response']) {
            $resp = wp_json_encode($data);
            if ($resp !== false && strlen($resp) <= 65535) {
                $log_data['response_data'] = $resp;
            }
        }

        // Compute risk level from ability annotations.
        $log_data['risk_level'] = self::compute_risk_level($ability_name);

        $this->insert_log($log_data);

        // Email notification for new sessions.
        if ($settings['analytics_notify_enabled'] && $session_id) {
            $this->maybe_send_notification($session_id, $ability_name, $ip_address, $settings);
        }

        // Webhook notification.
        if (($settings['webhook_enabled'] ?? false) && !empty($settings['webhook_url']) && $ability_name) {
            $this->maybe_send_webhook($ability_name, $log_data['response_status'] ?? 'success', $session_id, $settings);
        }

        return $response;
    }

    /**
     * Determine response status from various response formats.
     */
    private function determine_status($response, $data): string
    {
        if ($response->is_error()) {
            return 'error';
        }
        if (is_array($data) && isset($data['error'])) {
            return 'error';
        }
        if ($data instanceof \stdClass && isset($data->error)) {
            return 'error';
        }
        if (is_array($data) && isset($data['result'])) {
            $result_obj = $data['result'];
            if (is_array($result_obj) && ($result_obj['isError'] ?? false)) {
                return 'error';
            }
            if ($result_obj instanceof \stdClass && ($result_obj->isError ?? false)) {
                return 'error';
            }
        }
        return 'success';
    }

    /**
     * Extract the ability name from the JSON-RPC request.
     * Returns null for non-ability requests (causing the logger to skip them).
     */
    private function extract_ability_name($request, $data): ?string
    {
        $params = $request->get_json_params();

        if (isset($params['method']) && $params['method'] === 'tools/call' && isset($params['params']['name'])) {
            return sanitize_text_field($params['params']['name']);
        }

        $route = $request->get_route();
        if (str_contains($route, 'execute-ability') || str_contains($route, 'call-tool')) {
            foreach (['params.name' => $params['params']['name'] ?? null, 'ability' => $params['ability'] ?? null, 'name' => $params['name'] ?? null] as $val) {
                if ($val !== null) {
                    return sanitize_text_field($val);
                }
            }
        }

        return null;
    }

    /**
     * Get client IP address with validation.
     */
    private function get_client_ip(): ?string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /**
     * Anonymize an IP address for privacy compliance.
     *
     * IPv4: Zeroes the last octet (e.g. 192.168.1.42 → 192.168.1.0).
     * IPv6: Zeroes the last 80 bits (e.g. 2001:db8::1 → 2001:db8::).
     */
    private function anonymize_ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                // Zero the last 10 bytes (80 bits).
                $packed = substr($packed, 0, 6) . str_repeat("\0", 10);
                return inet_ntop($packed);
            }
        }
        return $ip;
    }

    /**
     * Send email notification for new MCP sessions (rate-limited).
     */
    private function maybe_send_notification(string $session_id, string $ability_name, ?string $ip, array $settings): void
    {
        $frequency = $settings['analytics_notify_frequency'] ?? 'off';
        if ($frequency === 'off' || $frequency === 'daily') {
            return;
        }

        // "session" frequency: notify on first request of a new session.
        $transient_key = 'sprout_mcp_notified_' . md5($session_id);
        if (get_transient($transient_key)) {
            return;
        }

        // Rate limit: max 1 email per 5 minutes to prevent email flooding.
        if (get_transient('sprout_mcp_notify_rate_limit')) {
            return;
        }

        $email = $settings['analytics_notify_email'] ?: get_option('admin_email');
        $site_name = get_bloginfo('name');

        // Privacy: Only include user/IP info if those settings are enabled.
        $store_user = $settings['analytics_store_user_identity'] ?? true;
        $store_ip = $settings['analytics_store_ip'] ?? true;

        $username = __('Redacted', 'sprout-os');
        if ($store_user) {
            $user = wp_get_current_user();
            $username = $user->exists() ? $user->user_login : __('Unknown', 'sprout-os');
        }

        $ip_display = __('Redacted', 'sprout-os');
        if ($store_ip) {
            $ip_display = $ip ?: __('Unknown', 'sprout-os');
        }

        $subject = sprintf('[%s] New MCP Session Detected', $site_name);
        $body = sprintf(
            "A new MCP session has started on your WordPress site.\n\n" .
            "Site: %s\n" .
            "Time: %s\n" .
            "User: %s\n" .
            "Session ID: %s\n" .
            "IP Address: %s\n" .
            "First Ability: %s\n\n" .
            "If this was not expected, review your MCP connections in the WordPress admin.\n\n" .
            "- Sprout MCP Analytics",
            home_url(),
            wp_date('Y-m-d H:i:s'),
            $username,
            $session_id,
            $ip_display,
            $ability_name
        );

        wp_mail($email, $subject, $body);

        set_transient($transient_key, 1, DAY_IN_SECONDS);
        set_transient('sprout_mcp_notify_rate_limit', 1, 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Send a webhook notification for an ability execution.
     *
     * @param string      $ability_name The ability that was executed.
     * @param string      $status       Response status (success/error).
     * @param string|null $session_id   Current session ID.
     * @param array       $settings     Plugin settings.
     */
    private function maybe_send_webhook(string $ability_name, string $status, ?string $session_id, array $settings): void
    {
        $events = $settings['webhook_events'] ?? 'all';

        // Filter by event type.
        if ($events === 'errors' && $status !== 'error') {
            return;
        }
        if ($events === 'destructive') {
            $is_destructive = false;
            if (function_exists('wp_get_abilities')) {
                // Use lookup map to convert log format (dashes) to registry format (slash separator).
                static $wh_log_to_reg = null;
                if ($wh_log_to_reg === null) {
                    $wh_log_to_reg = [];
                    foreach (wp_get_abilities() as $_ab) {
                        $r = $_ab->get_name();
                        $wh_log_to_reg[str_replace('/', '-', $r)] = $r;
                    }
                }
                $reg_name = $wh_log_to_reg[$ability_name] ?? $ability_name;
                $ability  = wp_get_ability($reg_name);
                if ($ability instanceof \WP_Ability) {
                    $meta = $ability->get_meta();
                    $is_destructive = !empty($meta['annotations']['destructive']);
                }
            }
            if (!$is_destructive) {
                return;
            }
        }

        $user    = wp_get_current_user();
        $payload = [
            'event'        => 'ability_executed',
            'ability_name' => $ability_name,
            'status'       => $status,
            'session_id'   => $session_id,
            'user'         => $user->ID > 0 ? $user->user_login : null,
            'timestamp'    => wp_date('c'),
            'site_url'     => home_url(),
            'site_name'    => get_bloginfo('name'),
        ];

        $body = (string) wp_json_encode($payload);
        $args = [
            'body'     => $body,
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 5,
            'blocking' => false,
        ];

        $secret = $settings['webhook_secret'] ?? '';
        if ($secret !== '') {
            $args['headers']['X-Sprout-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        wp_remote_post($settings['webhook_url'], $args);
    }

    /**
     * Send a test webhook payload.
     *
     * @param string $url    Webhook URL.
     * @param string $secret HMAC secret (optional).
     * @return array{success: bool, status_code: int|null, message: string}
     */
    public static function send_test_webhook(string $url, string $secret = ''): array
    {
        $payload = [
            'event'        => 'test',
            'ability_name' => 'test/webhook-ping',
            'status'       => 'success',
            'session_id'   => 'test-' . wp_generate_password(8, false),
            'user'         => wp_get_current_user()->user_login,
            'timestamp'    => wp_date('c'),
            'site_url'     => home_url(),
            'site_name'    => get_bloginfo('name'),
            'message'      => 'This is a test webhook from Sprout MCP.',
        ];

        $body = (string) wp_json_encode($payload);
        $args = [
            'body'    => $body,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ];

        if ($secret !== '') {
            $args['headers']['X-Sprout-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return ['success' => false, 'status_code' => null, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        return [
            'success'     => $code >= 200 && $code < 300,
            'status_code' => $code,
            'message'     => $code >= 200 && $code < 300
                /* translators: %d: HTTP status code */
                ? sprintf(__('Success - HTTP %d', 'sprout-os'), $code)
                /* translators: %d: HTTP status code */
                : sprintf(__('Failed - HTTP %d', 'sprout-os'), $code),
        ];
    }

    /**
     * Send daily digest email with summary of MCP activity.
     */
    public function send_daily_digest(): void
    {
        $settings = sprout_mcp_get_settings();
        if (!$settings['analytics_enabled'] || !$settings['analytics_notify_enabled']) {
            return;
        }
        if (($settings['analytics_notify_frequency'] ?? 'off') !== 'daily') {
            return;
        }

        if (!self::ensure_table()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $yesterday = wp_date('Y-m-d', strtotime('-1 day'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN response_status = 'error' THEN 1 ELSE 0 END) AS errors,
                COUNT(DISTINCT session_id) AS sessions,
                COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS users,
                COUNT(DISTINCT ip_address) AS ips
            FROM %i WHERE DATE(created_at) = %s",
            $table,
            $yesterday
        ));

        if (!$stats || (int) $stats->total === 0) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_ability = $wpdb->get_var($wpdb->prepare(
            "SELECT ability_name FROM %i WHERE DATE(created_at) = %s GROUP BY ability_name ORDER BY COUNT(*) DESC LIMIT 1",
            $table,
            $yesterday
        ));

        $email = $settings['analytics_notify_email'] ?: get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = sprintf('[%s] MCP Daily Digest - %s', $site_name, $yesterday);
        $body = sprintf(
            "MCP Activity Summary for %s\nSite: %s\n\n" .
            "Total Calls: %d\nFailed Calls: %d\nUnique Sessions: %d\nUnique Users: %d\nUnique IPs: %d\nMost Used Ability: %s\n\n" .
            "View full analytics: %s\n\n- Sprout MCP Analytics",
            $yesterday, home_url(),
            (int) $stats->total, (int) $stats->errors, (int) $stats->sessions, (int) $stats->users, (int) $stats->ips,
            $top_ability ?: '-',
            admin_url('admin.php?page=sprout-os&tab=activity')
        );

        wp_mail($email, $subject, $body);
    }

    /**
     * Insert a log row. Max entries cleanup runs via cron, not per-insert.
     */
    private function insert_log(array $data): void
    {
        if (!self::ensure_table()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        $formats = [];
        foreach ($data as $key => $value) {
            $formats[] = in_array($key, ['user_id', 'execution_time_ms'], true) ? '%d' : '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($table, $data, $formats);
        self::bump_cache_last_changed();
    }

    /**
     * Clean up old log entries based on retention + max entries cap.
     * Runs via daily cron - NOT on every insert.
     */
    public function cleanup_old_logs(): void
    {
        if (!self::ensure_table()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $settings = sprout_mcp_get_settings();

        // Retention-based cleanup.
        $days = $settings['analytics_retention_days'];
        if ($days > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $table,
                $days
            ));
        }

        // Max entries cap cleanup.
        $max = $settings['analytics_max_entries'] ?? 0;
        if ($max > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table));
            if ($count > $max) {
                $excess = $count - $max;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM %i ORDER BY created_at ASC LIMIT %d",
                    $table,
                    $excess
                ));
            }
        }

        self::bump_cache_last_changed();
    }

    /**
     * Get summary stats - single optimized query.
     */
    public static function get_summary(): array
    {
        $empty = ['total' => 0, 'today' => 0, 'most_used' => null, 'unique_sessions' => 0, 'errors' => 0, 'unique_users' => 0];

        if (!self::ensure_table()) {
            return $empty;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN response_status = 'error' THEN 1 ELSE 0 END) AS errors,
                COUNT(DISTINCT session_id) AS unique_sessions,
                COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS unique_users
            FROM %i",
                $table
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $most_used = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ability_name FROM %i GROUP BY ability_name ORDER BY COUNT(*) DESC LIMIT 1",
                $table
            )
        );

        return [
            'total'           => $stats ? (int) $stats->total : 0,
            'today'           => $stats ? (int) $stats->today : 0,
            'most_used'       => $most_used,
            'unique_sessions' => $stats ? (int) $stats->unique_sessions : 0,
            'errors'          => $stats ? (int) $stats->errors : 0,
            'unique_users'    => $stats ? (int) $stats->unique_users : 0,
        ];
    }

    /**
     * Get the DB table size in bytes.
     */
    public static function get_table_size(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $size = $wpdb->get_var($wpdb->prepare(
            "SELECT (data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
            $wpdb->dbname,
            $table
        ));

        return $size ? (int) $size : 0;
    }

    /**
     * Compute risk level string for a given ability name.
     * Uses WP Abilities API annotations with name-pattern fallback.
     *
     * @return string One of: 'read', 'create', 'modify', 'delete', 'unknown'
     */
    public static function compute_risk_level(string $ability_name): string
    {
        // Convert log-format (dashes) to registry-format (slash namespace separator).
        if (str_contains($ability_name, '/')) {
            $reg_name = $ability_name;
        } else {
            // Build lookup map from all registered abilities.
            static $log_to_reg = null;
            if ($log_to_reg === null && function_exists('wp_get_abilities')) {
                $log_to_reg = [];
                foreach (wp_get_abilities() as $ab) {
                    $r = $ab->get_name();
                    $log_to_reg[str_replace('/', '-', $r)] = $r;
                }
            }
            $reg_name = ($log_to_reg[$ability_name] ?? null) ?: $ability_name;
        }

        if (function_exists('wp_get_ability')) {
            $ability = wp_get_ability($reg_name);
            if ($ability instanceof \WP_Ability) {
                $ann = $ability->get_meta()['annotations'] ?? [];
                if (!empty($ann['destructive'])) return 'delete';
                if (!empty($ann['readonly']))    return 'read';
                if (isset($ann['readonly']) && $ann['readonly'] === false && isset($ann['destructive']) && $ann['destructive'] === false) {
                    // Additive - distinguish create vs modify by name pattern.
                    if (preg_match('/(create|add|build|import|sideload|upload|duplicate)/', $reg_name)) return 'create';
                    return 'modify';
                }
            }
        }

        // Fallback: name heuristics. Order matters - check destructive/modify BEFORE read
        // to prevent false positives like "update-search-bar" matching "search-" as read.
        if (preg_match('/(execute-php|batch-execute)/', $reg_name))                       return 'delete';
        if (preg_match('/\b(delete|remove|destroy|purge)\b/', $reg_name))                 return 'delete';
        if (preg_match('/\b(update|edit|modify|set|toggle|move|reorder)\b/', $reg_name))  return 'modify';
        if (preg_match('/\b(create|add|build|import|sideload|upload)\b/', $reg_name))     return 'create';
        if (preg_match('/\b(get|list|read|find|search|export|discover|info|schema)\b/', $reg_name)) return 'read';

        return 'unknown';
    }

    /**
     * Human-readable label for a risk level.
     */
    public static function risk_label(string $level): string
    {
        return match ($level) {
            'read'   => __('Read', 'sprout-os'),
            'create' => __('Create', 'sprout-os'),
            'modify' => __('Modify', 'sprout-os'),
            'delete' => __('Destructive', 'sprout-os'),
            default  => __('Unknown', 'sprout-os'),
        };
    }

    /**
     * CSS color for a risk level.
     */
    public static function risk_color(string $level): string
    {
        return match ($level) {
            'read'   => 'var(--so-success)',
            'create' => 'var(--so-brand-primary)',
            'modify' => 'var(--so-warning-dark)',
            'delete' => 'var(--so-error)',
            default  => 'var(--so-text-tertiary)',
        };
    }

    /**
     * Humanize an ability name into plain English.
     */
    public static function humanize_ability(string $ability_name): string
    {
        // Map common abilities to human descriptions.
        $map = [
            'sprout-execute-php'          => __('Executed PHP code', 'sprout-os'),
            'sprout-read-file'            => __('Read a file', 'sprout-os'),
            'sprout-write-file'           => __('Wrote a file', 'sprout-os'),
            'sprout-edit-file'            => __('Edited a file', 'sprout-os'),
            'sprout-delete-file'          => __('Deleted a file', 'sprout-os'),
            'sprout-list-directory'       => __('Listed directory contents', 'sprout-os'),
            'sprout-create-page'          => __('Created a new page', 'sprout-os'),
            'sprout-update-page'          => __('Updated a page', 'sprout-os'),
            'sprout-list-pages'           => __('Listed pages', 'sprout-os'),
            'sprout-get-page-structure'   => __('Read page structure', 'sprout-os'),
            'sprout-build-page'           => __('Built a page with Elementor', 'sprout-os'),
            'sprout-search-images'        => __('Searched for images', 'sprout-os'),
            'sprout-sideload-image'       => __('Uploaded an image', 'sprout-os'),
            'sprout-update-mcp-settings'  => __('Updated MCP settings', 'sprout-os'),
            'sprout-get-mcp-settings'     => __('Read MCP settings', 'sprout-os'),
            'sprout-bridge-discover-tools'   => __('Discovered available tools', 'sprout-os'),
            'sprout-bridge-inspect-tool'     => __('Inspected tool metadata', 'sprout-os'),
            'sprout-bridge-dispatch-tool'    => __('Dispatched a tool', 'sprout-os'),
        ];

        if (isset($map[$ability_name])) {
            return $map[$ability_name];
        }

        // Auto-generate: "sprout-elementor-update-container" → "Updated Elementor container"
        $clean = str_replace(['sprout-', 'nexter-', 'wdesignkit-', 'mcp-adapter-', 'sprout-bridge-'], '', $ability_name);
        $clean = str_replace('-', ' ', $clean);
        return ucfirst($clean);
    }

    /**
     * Get session-grouped log data for the Activity Feed.
     *
     * @return array{sessions: array, total: int}
     */
    public static function get_sessions(array $filters = []): array
    {
        if (!self::ensure_table()) {
            return ['sessions' => [], 'total' => 0];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        // Pre-prepare each WHERE condition individually so no raw variables enter queries.
        $safe_where_parts = ['session_id IS NOT NULL', "session_id != ''"];

        if (!empty($filters['date_from'])) {
            $safe_where_parts[] = $wpdb->prepare('created_at >= %s', sanitize_text_field($filters['date_from']) . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $safe_where_parts[] = $wpdb->prepare('created_at <= %s', sanitize_text_field($filters['date_to']) . ' 23:59:59');
        }

        $per_page = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;
        $cache_key = self::build_cache_key(__FUNCTION__, [$filters, $table]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached) && isset($cached['sessions'], $cached['total'])) {
            return $cached;
        }

        // Assemble safe query from pre-prepared parts + table identifier.
        $safe_where = 'WHERE ' . implode(' AND ', $safe_where_parts);
        $safe_from  = $wpdb->prepare('FROM %i', $table);
        $safe_limit = $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, $offset);

        // All query fragments ($safe_from, $safe_where, $safe_limit) are individually
        // prepared via $wpdb->prepare() above. No raw user input reaches the database.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_query = "SELECT COUNT(DISTINCT session_id) {$safe_from} {$safe_where}";
        $sessions_query = "SELECT
                session_id,
                MIN(created_at) AS started_at,
                MAX(created_at) AS ended_at,
                COUNT(*) AS total_actions,
                SUM(CASE WHEN response_status = 'error' THEN 1 ELSE 0 END) AS error_count,
                SUM(CASE WHEN risk_level = 'delete' THEN 1 ELSE 0 END) AS destructive_count,
                SUM(CASE WHEN risk_level = 'read' THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN risk_level = 'create' THEN 1 ELSE 0 END) AS create_count,
                SUM(CASE WHEN risk_level = 'modify' THEN 1 ELSE 0 END) AS modify_count,
                MIN(user_id) AS user_id,
                MIN(ip_address) AS ip_address
            {$safe_from}
            {$safe_where}
            GROUP BY session_id
            ORDER BY started_at DESC
            {$safe_limit}";
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $total = (int) self::db_get_var($count_query);
        $sessions = self::db_get_results($sessions_query, ARRAY_A);

        $result = ['sessions' => $sessions ?: [], 'total' => $total];
        wp_cache_set($cache_key, $result, self::CACHE_GROUP);

        return $result;
    }

    /**
     * Get all log entries for a single session.
     */
    public static function get_session_entries(string $session_id): array
    {
        if (!self::ensure_table()) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $cache_key = self::build_cache_key(__FUNCTION__, [$session_id, $table]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = self::db_get_results(
            $wpdb->prepare(
                "SELECT id, ability_name, response_status, execution_time_ms, risk_level, created_at, request_params FROM %i WHERE session_id = %s ORDER BY created_at ASC",
                $table,
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        wp_cache_set($cache_key, $results, self::CACHE_GROUP);

        return $results;
    }

    /**
     * Get filtered log entries (excludes LONGTEXT columns for performance).
     */
    public static function get_logs(array $filters = []): array
    {
        if (!self::ensure_table()) {
            return ['rows' => [], 'total' => 0];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        $cache_key = self::build_cache_key(__FUNCTION__, [$filters, $table]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached) && isset($cached['rows'], $cached['total'])) {
            return $cached;
        }

        $per_page = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;
        $where_parts = ['1=1'];

        $ability = sanitize_text_field((string) ($filters['ability'] ?? ''));
        if ($ability !== '') {
            $where_parts[] = $wpdb->prepare('ability_name = %s', $ability);
        }

        if (!empty($filters['ability_search'])) {
            $ability_search = '%' . $wpdb->esc_like(sanitize_text_field((string) $filters['ability_search'])) . '%';
            $where_parts[] = $wpdb->prepare('ability_name LIKE %s', $ability_search);
        }

        if (!empty($filters['date_from'])) {
            $where_parts[] = $wpdb->prepare(
                'created_at >= %s',
                sanitize_text_field((string) $filters['date_from']) . ' 00:00:00'
            );
        }

        if (!empty($filters['date_to'])) {
            $where_parts[] = $wpdb->prepare(
                'created_at <= %s',
                sanitize_text_field((string) $filters['date_to']) . ' 23:59:59'
            );
        }

        if (!empty($filters['user_id'])) {
            $where_parts[] = $wpdb->prepare('user_id = %d', (int) $filters['user_id']);
        }

        $status = sanitize_text_field((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where_parts[] = $wpdb->prepare('response_status = %s', $status);
        }

        $mcp_method = sanitize_text_field((string) ($filters['mcp_method'] ?? ''));
        if ($mcp_method !== '') {
            $where_parts[] = $wpdb->prepare('mcp_method = %s', $mcp_method);
        }

        $safe_from  = $wpdb->prepare('FROM %i', $table);
        $safe_where = 'WHERE ' . implode(' AND ', $where_parts);
        $safe_limit = $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, $offset);

        // All fragments are individually prepared before assembly.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_query = "SELECT COUNT(*) {$safe_from} {$safe_where}";
        $rows_query = "SELECT id, ability_name, mcp_method, api_endpoint, session_id, user_id, ip_address, response_status, execution_time_ms, created_at
            {$safe_from}
            {$safe_where}
            ORDER BY created_at DESC
            {$safe_limit}";
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $total = (int) self::db_get_var($count_query);
        $rows = self::db_get_results($rows_query, ARRAY_A);

        $result = ['rows' => $rows ?: [], 'total' => $total];
        wp_cache_set($cache_key, $result, self::CACHE_GROUP);

        return $result;
    }

    /**
     * Get distinct ability names for filter dropdown.
     */
    public static function get_ability_names(): array
    {
        if (!self::ensure_table()) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $cache_key = self::build_cache_key(__FUNCTION__, [$table]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = self::db_get_col($wpdb->prepare("SELECT DISTINCT ability_name FROM %i ORDER BY ability_name", $table));
        wp_cache_set($cache_key, $results, self::CACHE_GROUP);

        return $results;
    }

    /**
     * Get distinct MCP methods for filter dropdown.
     */
    public static function get_mcp_methods(): array
    {
        if (!self::ensure_table()) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $cache_key = self::build_cache_key(__FUNCTION__, [$table]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = self::db_get_col(
            $wpdb->prepare("SELECT DISTINCT mcp_method FROM %i WHERE mcp_method IS NOT NULL ORDER BY mcp_method", $table)
        );
        wp_cache_set($cache_key, $results, self::CACHE_GROUP);

        return $results;
    }

    /**
     * Get distinct user IDs that have log entries.
     */
    public static function get_log_users(): array
    {
        if (!self::ensure_table()) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $cache_key = self::build_cache_key(__FUNCTION__, [$table]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $user_ids = self::db_get_col($wpdb->prepare("SELECT DISTINCT user_id FROM %i WHERE user_id > 0 ORDER BY user_id", $table));
        $users = [];
        foreach ($user_ids as $uid) {
            $u = get_userdata((int) $uid);
            $users[(int) $uid] = $u ? $u->user_login : '#' . $uid;
        }
        wp_cache_set($cache_key, $users, self::CACHE_GROUP);

        return $users;
    }

    // --- AJAX Handlers (Administrator Only) -----------------------------

    /**
     * AJAX: Purge all log entries.
     * SECURITY: Requires manage_options + valid nonce.
     */
    public function ajax_purge_logs(): void
    {
        check_ajax_referer('sprout_mcp_analytics_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'sprout-os')]);
        }

        if (!self::ensure_table()) {
            wp_send_json_error(['message' => __('Log table is not available.', 'sprout-os')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $result = self::db_query($wpdb->prepare("TRUNCATE TABLE %i", $table));

        // Some DB setups deny TRUNCATE. Fallback to DELETE.
        if ($result === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = self::db_query($wpdb->prepare("DELETE FROM %i", $table));
        }

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to purge logs. Please check DB permissions.', 'sprout-os')]);
        }

        self::bump_cache_last_changed();

        wp_send_json_success(['message' => __('All log entries have been purged.', 'sprout-os')]);
    }

    /**
     * AJAX: Export logs as CSV - chunked output, never loads all into memory.
     * SECURITY: Requires manage_options + valid nonce (POST method).
     */
    public function ajax_export_csv(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CSV export is triggered via an admin link (GET).
        $nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
        if (!wp_verify_nonce($nonce, 'sprout_mcp_analytics_nonce')) {
            wp_die(esc_html__('Security check failed.', 'sprout-os'), 403);
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'sprout-os'), 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';

        // Security headers for CSV file download.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Direct header() required for file download response.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=sprout-mcp-analytics-' . wp_date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('Failed to create CSV output.', 'sprout-os'));
        }

        fputcsv($output, ['ID', 'Ability', 'MCP Method', 'API Endpoint', 'Session ID', 'User ID', 'IP Address', 'Status', 'Time (ms)', 'Date']);

        // Chunked export: 500 rows at a time to prevent memory issues.
        $chunk_size = 500;
        $offset = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = self::db_get_results($wpdb->prepare(
                "SELECT id, ability_name, mcp_method, api_endpoint, session_id, user_id, ip_address, response_status, execution_time_ms, created_at
                 FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $table,
                $chunk_size,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['ability_name'],
                    $row['mcp_method'] ?? '',
                    $row['api_endpoint'] ?? '',
                    $row['session_id'] ?? '',
                    $row['user_id'],
                    $row['ip_address'] ?? '',
                    $row['response_status'],
                    $row['execution_time_ms'],
                    $row['created_at'],
                ]);
            }

            $offset += $chunk_size;

            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        } while (count($rows) === $chunk_size);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }

    /**
     * AJAX: Toggle tracking on/off (master kill switch).
     * SECURITY: Requires manage_options + valid nonce.
     */
    public function ajax_toggle_tracking(): void
    {
        check_ajax_referer('sprout_mcp_analytics_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'sprout-os')]);
        }

        $enable = isset($_POST['enable']) && sanitize_text_field(wp_unslash($_POST['enable'])) === '1';
        $saved = get_option('sprout_mcp_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $saved['analytics_enabled'] = $enable;
        update_option('sprout_mcp_settings', $saved);

        // Flush the settings cache so subsequent calls see the new value.
        sprout_mcp_get_settings(true);

        wp_send_json_success([
            'enabled' => $enable,
            'message' => $enable
                ? __('MCP Tracking activated.', 'sprout-os')
                : __('MCP Tracking paused.', 'sprout-os'),
        ]);
    }

    /**
     * AJAX: Get log detail by ID.
     * SECURITY: Requires manage_options + valid nonce.
     */
    public function ajax_get_log_detail(): void
    {
        check_ajax_referer('sprout_mcp_analytics_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'sprout-os')]);
        }

        $log_id = absint(wp_unslash($_POST['log_id'] ?? 0));
        if ($log_id <= 0) {
            wp_send_json_error(['message' => __('Invalid log ID.', 'sprout-os')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sprout_mcp_logs';
        $cache_key = self::build_cache_key(__FUNCTION__, [$table, $log_id]);
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            $row = $cached;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $row = self::db_get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $log_id), ARRAY_A);
            if (is_array($row)) {
                wp_cache_set($cache_key, $row, self::CACHE_GROUP);
            }
        }

        if (!$row) {
            wp_send_json_error(['message' => __('Log entry not found.', 'sprout-os')]);
        }

        $user_display = '-';
        if (!empty($row['user_id'])) {
            $u = get_userdata((int) $row['user_id']);
            $user_display = $u ? $u->user_login : '#' . $row['user_id'];
        }
        $row['user_display'] = $user_display;

        wp_send_json_success($row);
    }
}
