<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\Translation\FieldPolicy;

final class FieldPolicyTest extends TestCase {

	public function testTextFieldIsTranslated(): void {
		self::assertSame( 'translate', ( new FieldPolicy() )->decide( 'hero_title', 'Najlepsza oferta' ) ); }
	public function testUrlsAndEmailsAreCopied(): void {
		$p = new FieldPolicy();
		self::assertSame( 'copy', $p->decide( 'website', 'https://example.com/a' ) );
		self::assertSame( 'copy', $p->decide( 'contact', 'team@example.com' ) ); }
	public function testIdsAndNumbersAreCopied(): void {
		$p = new FieldPolicy();
		self::assertSame( 'copy', $p->decide( 'product_id', '12345' ) );
		self::assertSame( 'copy', $p->decide( 'price', 199 ) ); }
	public function testAcfMapping(): void {
		$p = new FieldPolicy();
		self::assertSame( 'translate', $p->decide( 'intro', 'Tekst', 'wysiwyg' ) );
		self::assertSame( 'copy', $p->decide( 'photo', 123, 'image' ) ); }
}
