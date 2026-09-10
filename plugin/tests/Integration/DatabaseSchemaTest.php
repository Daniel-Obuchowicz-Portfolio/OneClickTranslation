<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Integration;

final class DatabaseSchemaTest extends \PHPUnit\Framework\TestCase {

	public function testInstalledWordPressHasPluginTables(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			$this->markTestSkipped( 'Run in the WordPress integration test environment.' );
		}global $wpdb;
		foreach ( array( 'oct_queue', 'oct_logs', 'oct_translation_cache', 'oct_status' ) as $suffix ) {
			self::assertSame( $wpdb->prefix . $suffix, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $suffix ) ) );}
	}
}
