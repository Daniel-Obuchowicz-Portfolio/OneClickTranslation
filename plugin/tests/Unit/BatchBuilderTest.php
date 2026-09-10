<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\Translation\BatchBuilder;

final class BatchBuilderTest extends TestCase {

	public function testDeduplicatesTextAndKeepsPaths(): void {
		$b = new BatchBuilder();
		$b->add( 'a', 'Learn more' );
		$b->add( 'b', 'Learn more' );
		self::assertSame( 1, $b->count() );
		$items = $b->items();
		$first = reset( $items );
		self::assertSame( array( 'a', 'b' ), array_values( $first['paths'] ) ); }
	public function testChunksByCountAndHtmlMode(): void {
		$b = new BatchBuilder();
		$b->add( 'a', 'One' );
		$b->add( 'b', 'Two' );
		$b->add( 'c', '<p>Three</p>', true );
		self::assertCount( 3, $b->chunks( 1000, 1 ) ); }
}
