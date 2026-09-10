<?php

declare(strict_types=1);

namespace OneClickTranslation\Support;

final class Logger {

	public function debug( string $message, array $context = array() ): void {
		if ( Helpers::debug() ) {
			$this->log( 'debug', $message, $context );
		}
	}

	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context ); }
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context ); }
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context ); }

	public function log( string $level, string $message, array $context = array() ): void {
		global $wpdb;
		$context = $this->redact( $context );
		$apiKey  = Helpers::apiKey();
		if ( '' !== $apiKey ) {
			$message = str_replace( $apiKey, '[redacted]', $message );
		}
		$message = (string) preg_replace( '/DeepL-Auth-Key\s+[A-Za-z0-9:_-]+/i', 'DeepL-Auth-Key [redacted]', $message );
		$wpdb->insert(
			$wpdb->prefix . 'oct_logs',
			array(
				'level'           => in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'message'         => wp_strip_all_tags( $message ),
				'context'         => wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'object_id'       => isset( $context['object_id'] ) ? absint( $context['object_id'] ) : null,
				'target_language' => isset( $context['target_language'] ) ? sanitize_key( (string) $context['target_language'] ) : null,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	private function redact( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( preg_match( '/key|token|authorization|credential|password|secret/i', (string) $key ) ) {
				$value[ $key ] = '[redacted]';
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = $this->redact( $item );
			}
		}
		return $value;
	}
}
