<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\DeepL\DeepLClient;

final class DeepLClientTest extends TestCase {

	public function testMapsAuthenticationAndQuotaErrors(): void {
		$auth  = DeepLClient::exceptionForStatus( 403 );
		$quota = DeepLClient::exceptionForStatus( 456 );
		self::assertFalse( $auth->isRetryable() );
		self::assertStringContainsString( 'authentication', $auth->getMessage() );
		self::assertFalse( $quota->isRetryable() ); }
	public function testMapsRateLimitAndServerErrorsAsRetryable(): void {
		self::assertTrue( DeepLClient::exceptionForStatus( 429, '', 4 )->isRetryable() );
		self::assertSame( 4, DeepLClient::exceptionForStatus( 429, '', 4 )->getRetryAfter() );
		self::assertTrue( DeepLClient::exceptionForStatus( 503 )->isRetryable() ); }
}
