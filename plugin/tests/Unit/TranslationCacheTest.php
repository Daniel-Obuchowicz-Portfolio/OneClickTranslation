<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\Translation\TranslationCache;

final class TranslationCacheTest extends TestCase {

	public function testKeyIncludesLanguagesTextAndCanonicalOptions(): void {
		$cache = new TranslationCache();
		$a     = $cache->buildKey(
			'pl',
			'en',
			'Oferta',
			array(
				'formality' => 'more',
				'html'      => true,
			)
		);
		$b     = $cache->buildKey(
			'pl',
			'en',
			'Oferta',
			array(
				'html'      => true,
				'formality' => 'more',
			)
		);
		self::assertSame( $a, $b );
		self::assertNotSame(
			$a,
			$cache->buildKey(
				'pl',
				'de',
				'Oferta',
				array(
					'html'      => true,
					'formality' => 'more',
				)
			)
		);
	}
}
