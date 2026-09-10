<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Providers\ProviderResolver;
use RuntimeException;

final class ProviderResolverTest extends TestCase {

	public function testResolvesOnlyAvailableProvider(): void {
		$resolver = new ProviderResolver( array( $this->provider( 'WPML', false ), $this->provider( 'Polylang', true ) ) );
		self::assertSame( 'Polylang', $resolver->resolve( 'auto' )->getProviderName() );
	}
	public function testRequiresChoiceWhenBothAvailable(): void {
		$resolver = new ProviderResolver( array( $this->provider( 'WPML', true ), $this->provider( 'Polylang', true ) ) );
		$this->expectException( RuntimeException::class );
		$resolver->resolve( 'auto' );
	}
	private function provider( string $name, bool $available ): LanguageProviderInterface {
		return new class($name,$available) implements LanguageProviderInterface {
			public function __construct( private string $name, private bool $available ) {}public function getLanguages(): array {
				return array();
			}public function getDefaultLanguage(): string {
				return 'pl';
			}public function getCurrentLanguage(): string {
				return 'pl';
			}public function getPostLanguage( int $postId ): string {
				return 'pl';
			}public function getPostTranslations( int $postId ): array {
				return array();
			}public function getPostTranslation( int $postId, string $language ): ?int {
				return null;
			}public function setPostLanguage( int $postId, string $language ): void {}public function connectPostTranslations( array $translations ): void {}public function getTermLanguage( int $termId ): string {
				return 'pl';
			}public function getTermTranslations( int $termId ): array {
				return array();
			}public function setTermLanguage( int $termId, string $language ): void {}public function connectTermTranslations( array $translations ): void {}public function isPostTypeTranslatable( string $postType ): bool {
				return true;
			}public function isTaxonomyTranslatable( string $taxonomy ): bool {
				return true;
			}public function getCustomFieldStrategy( string $metaKey, bool $creating, ?array $fieldDefinition = null ): ?string {
				return null;
			}public function isAvailable(): bool {
				return $this->available;
			}public function getProviderName(): string {
				return $this->name;}
		};
	}
}
