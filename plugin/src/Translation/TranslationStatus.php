<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

final class TranslationStatus {

	public const TRANSLATED = 'translated';
	public const MISSING    = 'missing';
	public const OUTDATED   = 'outdated';
	public const QUEUED     = 'queued';
	public const PROCESSING = 'processing';
	public const ERROR      = 'error';

	public function set( int $sourceId, string $language, string $status, ?int $translatedId = null, ?string $hash = null, ?string $error = null ): void {
		global $wpdb;
		$table        = $wpdb->prefix . 'oct_status';
		$now          = current_time( 'mysql', true );
		$translatedAt = $status === self::TRANSLATED ? $now : null;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_object_id,translated_object_id,object_type,target_language,status,source_hash,last_error,translated_at,updated_at)
             VALUES (%d,NULLIF(%d,0),'post',%s,%s,NULLIF(%s,''),NULLIF(%s,''),NULLIF(%s,''),%s)
             ON DUPLICATE KEY UPDATE translated_object_id=COALESCE(VALUES(translated_object_id),translated_object_id),status=VALUES(status),source_hash=COALESCE(VALUES(source_hash),source_hash),last_error=VALUES(last_error),translated_at=COALESCE(VALUES(translated_at),translated_at),updated_at=VALUES(updated_at)",
				$sourceId,
				$translatedId,
				$language,
				$status,
				$hash,
				$error,
				$translatedAt,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<string, mixed>|null */
	public function get( int $sourceId, string $language ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'oct_status';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_object_id=%d AND object_type='post' AND target_language=%s", $sourceId, $language ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? (array) $row : null;
	}

	/** @return array<string, int> */
	public function counts(): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'oct_status';
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) count FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[ (string) $row['status'] ] = (int) $row['count'];
		}
		return $result;
	}
}
