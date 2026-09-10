<?php

declare(strict_types=1);

namespace OneClickTranslation\Contracts;

interface LanguageProviderInterface {

	/** @return array<string, array<string, mixed>> Language code keyed definitions. */
	public function getLanguages(): array;
	public function getDefaultLanguage(): string;
	public function getCurrentLanguage(): string;
	public function getPostLanguage( int $postId ): string;
	/** @return array<string, int> */
	public function getPostTranslations( int $postId ): array;
	public function getPostTranslation( int $postId, string $language ): ?int;
	public function setPostLanguage( int $postId, string $language ): void;
	/** @param array<string, int> $translations */
	public function connectPostTranslations( array $translations ): void;
	public function getTermLanguage( int $termId ): string;
	/** @return array<string, int> */
	public function getTermTranslations( int $termId ): array;
	public function setTermLanguage( int $termId, string $language ): void;
	/** @param array<string, int> $translations */
	public function connectTermTranslations( array $translations ): void;
	public function isPostTypeTranslatable( string $postType ): bool;
	public function isTaxonomyTranslatable( string $taxonomy ): bool;
	/** @param array<string,mixed>|null $fieldDefinition */
	public function getCustomFieldStrategy( string $metaKey, bool $creating, ?array $fieldDefinition = null ): ?string;
	public function isAvailable(): bool;
	public function getProviderName(): string;
}
