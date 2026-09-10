<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\Content\MetaTranslator;
use OneClickTranslation\Support\Arr;
use OneClickTranslation\Translation\FieldPolicy;

final class NestedMetaTest extends TestCase {

	public function testNestedArraysUseFieldPaths(): void {
		$value = array(
			'sections' => array(
				array(
					'heading' => 'Oferta',
					'button'  => array( 'title' => 'Więcej' ),
				),
			),
		);
		$flat  = Arr::flatten( $value, 'meta' );
		self::assertSame( 'Więcej', $flat['meta.sections.0.button.title'] ); }
	public function testSerializedAndJsonMetaAreDecoded(): void {
		$m                     = new MetaTranslator( new FieldPolicy() );
		[$serialized, $format] = $m->decode( serialize( array( 'heading' => 'Oferta' ) ) );
		self::assertSame( 'serialized', $format );
		self::assertSame( 'Oferta', $serialized['heading'] );
		[$json, $jsonFormat] = $m->decode( '{"heading":"Oferta"}' );
		self::assertSame( 'json', $jsonFormat );
		self::assertSame( 'Oferta', $json['heading'] ); }
	public function testDotsInNestedKeysDoNotBreakFieldPaths(): void {
		$value = array( 'section.featured' => array( 'button.title' => 'Dowiedz się więcej' ) );
		$flat  = Arr::flatten( $value, 'meta' );
		self::assertArrayHasKey( 'meta.section%2Efeatured.button%2Etitle', $flat );
		$root = array( 'meta' => $value );
		Arr::set( $root, 'meta.section%2Efeatured.button%2Etitle', 'Learn more' );
		self::assertSame( 'Learn more', $root['meta']['section.featured']['button.title'] );
	}
}
