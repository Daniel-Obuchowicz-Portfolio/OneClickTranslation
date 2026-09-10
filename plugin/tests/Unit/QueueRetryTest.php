<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use Brain\Monkey\Functions;
use OneClickTranslation\Queue\QueueJob;
use OneClickTranslation\Queue\QueueRepository;

final class QueueRetryTest extends TestCase {

	public function testRetriesUntilMaximumAttempts(): void {
		global $wpdb;
		$wpdb = new class() {public string $prefix = 'wp_';
			public array $data                     = array();
			public function update( $table, $data, $where, $formats, $whereFormats ): int {
				$this->data = $data;
				return 1;
			}};
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		$repo = new QueueRepository();
		$job  = new QueueJob( 1, 10, 'post', 'en', 'polylang', 'processing', 10, 1, array() );
		self::assertSame( 'pending', $repo->fail( $job, 'temporary', 3 ) );
		self::assertSame( 'pending', $wpdb->data['status'] );
		$last = new QueueJob( 1, 10, 'post', 'en', 'polylang', 'processing', 10, 3, array() );
		self::assertSame( 'failed', $repo->fail( $last, 'permanent', 3 ) );
	}
}
