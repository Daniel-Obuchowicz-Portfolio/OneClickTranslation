<?php

declare(strict_types=1);

namespace OneClickTranslation\Providers;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use RuntimeException;

final class PolylangProvider implements LanguageProviderInterface {

	public function isAvailable(): bool {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post_language' ); }
	public function getProviderName(): string {
		return 'Polylang'; }

	public function getLanguages(): array {
		if ( ! $this->isAvailable() ) {
			return array();
		}
		$objects = pll_languages_list( array( 'fields' => null ) );
		$result  = array();
		foreach ( (array) $objects as $language ) {
			if ( ! is_object( $language ) || empty( $language->slug ) ) {
				continue;
			}
			$result[ (string) $language->slug ] = array(
				'code'        => (string) $language->slug,
				'name'        => (string) ( $language->name ?? $language->slug ),
				'native_name' => (string) ( $language->name ?? $language->slug ),
				'locale'      => (string) ( $language->locale ?? $language->slug ),
				'flag_url'    => (string) ( $language->flag_url ?? '' ),
				'active'      => function_exists( 'pll_current_language' ) && pll_current_language( 'slug' ) === $language->slug,
			);
		}
		return $result;
	}

	public function getDefaultLanguage(): string {
		return $this->isAvailable() ? (string) pll_default_language( 'slug' ) : ''; }
	public function getCurrentLanguage(): string {
		if ( ! $this->isAvailable() ) {
			return '';
		}
		$current = pll_current_language( 'slug' );
		return (string) ( $current ? $current : $this->getDefaultLanguage() ); }
	public function getPostLanguage( int $postId ): string {
		return $this->isAvailable() ? (string) pll_get_post_language( $postId, 'slug' ) : ''; }

	public function getPostTranslations( int $postId ): array {
		return $this->isAvailable() ? array_map( 'intval', (array) pll_get_post_translations( $postId ) ) : array();
	}

	public function getPostTranslation( int $postId, string $language ): ?int {
		if ( ! $this->isAvailable() ) {
			return null;
		}
		$id = (int) pll_get_post( $postId, $language );
		return $id > 0 ? $id : null;
	}

	public function setPostLanguage( int $postId, string $language ): void {
		if ( ! $this->isAvailable() ) {
			throw new RuntimeException( 'Polylang is not available.' ); }
		pll_set_post_language( $postId, $language );
	}

	public function connectPostTranslations( array $translations ): void {
		$clean = array_filter( array_map( 'intval', $translations ) );
		if ( ! $this->isAvailable() || ! $clean ) {
			throw new RuntimeException( 'Polylang could not connect post translations.' );
		}
		pll_save_post_translations( $clean );
	}

	public function getTermLanguage( int $termId ): string {
		return $this->isAvailable() ? (string) pll_get_term_language( $termId, 'slug' ) : ''; }
	public function getTermTranslations( int $termId ): array {
		return $this->isAvailable() ? array_map( 'intval', (array) pll_get_term_translations( $termId ) ) : array(); }

	public function setTermLanguage( int $termId, string $language ): void {
		if ( ! $this->isAvailable() ) {
			throw new RuntimeException( 'Polylang is not available.' ); }
		pll_set_term_language( $termId, $language );
	}

	public function connectTermTranslations( array $translations ): void {
		$clean = array_filter( array_map( 'intval', $translations ) );
		if ( ! $this->isAvailable() || ! $clean ) {
			throw new RuntimeException( 'Polylang could not connect term translations.' );
		}
		pll_save_term_translations( $clean );
	}

	public function isPostTypeTranslatable( string $postType ): bool {
		return $this->isAvailable() && function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( $postType );
	}

	public function isTaxonomyTranslatable( string $taxonomy ): bool {
		return $this->isAvailable() && function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy );
	}

	public function getCustomFieldStrategy( string $metaKey, bool $creating, ?array $fieldDefinition = null ): ?string {
		unset( $metaKey, $creating, $fieldDefinition );
		return null;
	}
}
