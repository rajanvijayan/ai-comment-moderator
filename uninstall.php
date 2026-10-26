<?php
/**
 * Removes all plugin data on uninstall.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'aicm_settings' );
delete_option( 'aicm_db_version' );
delete_option( 'aicm_reply_counter' );
delete_transient( 'aicm_backoff_until' );

// v1 options. `api_key` is a generic name, so only remove it if v1 settings
// that clearly belonged to this plugin are also present.
if ( false !== get_option( 'response_mode', false ) && false !== get_option( 'cron_schedule_time', false ) ) {
	foreach ( [ 'auto_response', 'response_mode', 'cron_schedule_time', 'api_key', 'moderator_user' ] as $opt ) {
		delete_option( $opt );
	}
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aicm_log" ); // phpcs:ignore WordPress.DB

// Comment meta (AI replies themselves are kept as normal comments).
$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\\_aicm\\_%' AND meta_key <> '_aicm_is_ai_reply'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key IN ('_ai_reply_message','_ai_reply_time')" ); // phpcs:ignore WordPress.DB

foreach ( [ 'aicm_sweep', 'aicm_analyze_comment', 'aicm_send_reply', 'ai_comment_moderation_cron' ] as $hook ) {
	wp_unschedule_hook( $hook );
}
