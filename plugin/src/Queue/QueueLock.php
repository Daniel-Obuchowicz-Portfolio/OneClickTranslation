<?php

declare(strict_types=1);

namespace OneClickTranslation\Queue;

final class QueueLock {

	private string $key   = 'oct_queue_worker_lock';
	private string $token = '';

	public function acquire( int $ttl = 300 ): bool {
		$this->token = wp_generate_uuid4();
		if ( add_option(
			$this->key,
			array(
				'token'   => $this->token,
				'expires' => time() + $ttl,
			),
			'',
			false
		) ) {
			return true;
		}
		$existing = get_option( $this->key );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) < time() ) {
			delete_option( $this->key );
			return add_option(
				$this->key,
				array(
					'token'   => $this->token,
					'expires' => time() + $ttl,
				),
				'',
				false
			);
		}
		return false;
	}

	public function release(): void {
		$existing = get_option( $this->key );
		if ( is_array( $existing ) && hash_equals( (string) ( $existing['token'] ?? '' ), $this->token ) ) {
			delete_option( $this->key );
		}
	}
}
