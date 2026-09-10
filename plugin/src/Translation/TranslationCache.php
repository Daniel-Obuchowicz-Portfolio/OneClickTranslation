<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

use OneClickTranslation\Support\Arr;

final class TranslationCache {

	public function buildKey( string $sourceLanguage, string $targetLanguage, string $text, array $options ): string {
		return hash( 'sha256', strtolower( $sourceLanguage ) . "\0" . strtolower( $targetLanguage ) . "\0" . $text . "\0" . wp_json_encode( Arr::canonicalize( $options ) ) );
	}

	public function get( string $sourceLanguage, string $targetLanguage, string $text, array $options ): ?string {
		global $wpdb;
		$key   = $this->buildKey( $sourceLanguage, $targetLanguage, $text, $options );
		$table = $wpdb->prefix . 'oct_translation_cache';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, translated_text FROM {$table} WHERE cache_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			update_option( 'oct_cache_misses', (int) get_option( 'oct_cache_misses', 0 ) + 1, false );
			return null;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_used_at = %s WHERE id = %d", current_time( 'mysql', true ), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $row['translated_text'];
	}

	public function put( string $sourceLanguage, string $targetLanguage, string $text, string $translation, array $options ): void {
		global $wpdb;
		$key         = $this->buildKey( $sourceLanguage, $targetLanguage, $text, $options );
		$optionsHash = hash( 'sha256', (string) wp_json_encode( Arr::canonicalize( $options ) ) );
		$now         = current_time( 'mysql', true );
		$table       = $wpdb->prefix . 'oct_translation_cache';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (cache_key,source_hash,source_language,target_language,source_text,translated_text,options_hash,source_length,created_at,last_used_at,hits)
             VALUES (%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,0)
             ON DUPLICATE KEY UPDATE translated_text=VALUES(translated_text),last_used_at=VALUES(last_used_at)",
				$key,
				hash( 'sha256', $text ),
				strtolower( $sourceLanguage ),
				strtolower( $targetLanguage ),
				$text,
				$translation,
				$optionsHash,
				mb_strlen( $text ),
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array{entries:int,hits:int,misses:int,hit_ratio:float,characters_saved:int,requests_saved:int} */
	public function stats(): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'oct_translation_cache';
		$row    = (array) $wpdb->get_row( "SELECT COUNT(*) entries, COALESCE(SUM(hits),0) hits, COALESCE(SUM(hits * source_length),0) characters_saved FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$hits   = (int) ( $row['hits'] ?? 0 );
		$misses = (int) get_option( 'oct_cache_misses', 0 );
		return array(
			'entries'          => (int) ( $row['entries'] ?? 0 ),
			'hits'             => $hits,
			'misses'           => $misses,
			'hit_ratio'        => ( $hits + $misses ) ? round( 100 * $hits / ( $hits + $misses ), 2 ) : 0.0,
			'characters_saved' => (int) ( $row['characters_saved'] ?? 0 ),
			'requests_saved'   => $hits,
		);
	}

	public function clear(): int {
		global $wpdb;
		update_option( 'oct_cache_misses', 0, false );
		return (int) $wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'oct_translation_cache`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
