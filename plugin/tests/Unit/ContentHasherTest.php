<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\Translation\ContentHasher;

final class ContentHasherTest extends TestCase {

	public function testOrderDoesNotChangeHash(): void {
		$h = new ContentHasher();
		self::assertSame(
			$h->hash(
				array(
					'b' => 2,
					'a' => 1,
				)
			),
			$h->hash(
				array(
					'a' => 1,
					'b' => 2,
				)
			)
		); }
	public function testSourceChangeChangesHash(): void {
		$h   = new ContentHasher();
		$old = $h->hash( array( 'title' => 'Oferta' ) );
		self::assertTrue( $h->hasChanged( $old, array( 'title' => 'Nowa oferta' ) ) ); }
}
