<?php

declare(strict_types=1);

namespace OneClickTranslation\Database;

final class Installer {

	public const DB_VERSION = '1.0.0';

	public static function activate(): void {
		self::install();
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['oct_minutely'] = array(
					'interval' => 60,
					'display'  => 'Every minute',
				);
				return $schedules;
			}
		);
		if ( ! wp_next_scheduled( 'oct_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'oct_minutely', 'oct_process_queue' );
		}
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'oct_process_queue' );
		flush_rewrite_rules();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$queue   = $wpdb->prefix . 'oct_queue';
		$logs    = $wpdb->prefix . 'oct_logs';
		$cache   = $wpdb->prefix . 'oct_translation_cache';
		$status  = $wpdb->prefix . 'oct_status';

		dbDelta(
			"CREATE TABLE {$queue} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_object_id bigint(20) unsigned NOT NULL,
            object_type varchar(32) NOT NULL DEFAULT 'post',
            target_language varchar(20) NOT NULL,
            provider varchar(32) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            priority smallint NOT NULL DEFAULT 10,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            payload longtext NULL,
            last_error text NULL,
            available_at datetime NOT NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY worker (status,available_at,priority),
            KEY object (source_object_id,target_language)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(12) NOT NULL,
            message text NOT NULL,
            context longtext NULL,
            object_id bigint(20) unsigned NULL,
            target_language varchar(20) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at),
            KEY object_id (object_id)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$cache} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key char(64) NOT NULL,
            source_hash char(64) NOT NULL,
            source_language varchar(20) NOT NULL,
            target_language varchar(20) NOT NULL,
            source_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            options_hash char(64) NOT NULL,
            source_length int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            last_used_at datetime NOT NULL,
            hits bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY languages (source_language,target_language),
            KEY last_used_at (last_used_at)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$status} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_object_id bigint(20) unsigned NOT NULL,
            translated_object_id bigint(20) unsigned NULL,
            object_type varchar(32) NOT NULL DEFAULT 'post',
            target_language varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            source_hash char(64) NULL,
            last_error text NULL,
            translated_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_language (source_object_id,object_type,target_language),
            KEY status (status)
        ) {$charset};"
		);

		update_option( 'oct_db_version', self::DB_VERSION, false );
	}
}
