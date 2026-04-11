<?php
/**
 * SproutOS Uninstall Handler.
 *
 * Cleans up plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package SproutOS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'sprout_mcp_settings' );
delete_option( 'sprout_mcp_db_version' );
delete_option( 'sprout_mcp_ai_abilities_enabled' );

// Remove the analytics log table.
global $wpdb;
$sprout_mcp_table = $wpdb->prefix . 'sprout_mcp_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $sprout_mcp_table ) );

// Clear any leftover scheduled cron events.
wp_clear_scheduled_hook( 'sprout_mcp_analytics_cleanup' );
wp_clear_scheduled_hook( 'sprout_mcp_daily_digest' );

// Remove transients.
delete_transient( 'sprout_mcp_sandbox_notices' );
delete_transient( 'sprout_mcp_notify_rate_limit' );

// Remove sandbox directory and its contents.
$sprout_mcp_sandbox_dir = WP_CONTENT_DIR . '/sproutos-mcp-sandbox/';
if ( is_dir( $sprout_mcp_sandbox_dir ) ) {
	$sprout_mcp_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $sprout_mcp_sandbox_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $sprout_mcp_files as $sprout_mcp_file ) {
		if ( $sprout_mcp_file->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $sprout_mcp_file->getRealPath() );
		} else {
			wp_delete_file( $sprout_mcp_file->getRealPath() );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $sprout_mcp_sandbox_dir );
}
