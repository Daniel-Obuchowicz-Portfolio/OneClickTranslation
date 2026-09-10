<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_unschedule_hook( 'oct_process_queue' );
wp_unschedule_hook( 'oct_recheck_source' );
delete_option( 'oct_queue_worker_lock' );
delete_transient( 'oct_deepl_usage' );
delete_transient( 'oct_deepl_connection' );

if ( ! (bool) get_option( 'oct_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
foreach ( array( 'oct_queue', 'oct_logs', 'oct_translation_cache', 'oct_status' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
delete_option( 'oct_settings' );
delete_option( 'oct_content_types' );
delete_option( 'oct_field_policies' );
delete_option( 'oct_db_version' );
delete_option( 'oct_cache_misses' );
delete_option( 'oct_delete_data_on_uninstall' );
foreach ( array( '_oct_source_post_id', '_oct_source_hash', '_oct_translated_at' ) as $metaKey ) {
	delete_post_meta_by_key( $metaKey );
}
