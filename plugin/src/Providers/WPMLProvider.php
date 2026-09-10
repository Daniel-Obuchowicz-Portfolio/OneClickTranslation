<?php

declare(strict_types=1);

namespace OneClickTranslation\Providers;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use RuntimeException;

final class WPMLProvider implements LanguageProviderInterface {

	public function isAvailable(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress', false );
	}

	public function getProviderName(): string {
		return 'WPML'; }

	public function getLanguages(): array {
		if ( ! $this->isAvailable() ) {
			return array();
		}
		$raw       = (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$languages = array();
		foreach ( $raw as $code => $language ) {
			if ( ! is_array( $language ) ) {
				continue;
			}
			$languages[ (string) $code ] = array(
				'code'        => (string) $code,
				'name'        => (string) ( $language['translated_name'] ?? $language['native_name'] ?? $code ),
				'native_name' => (string) ( $language['native_name'] ?? $code ),
				'locale'      => (string) ( $language['default_locale'] ?? $code ),
				'flag_url'    => (string) ( $language['country_flag_url'] ?? '' ),
				'active'      => ! empty( $language['active'] ),
			);
		}
		return $languages;
	}

	public function getDefaultLanguage(): string {
		return (string) apply_filters( 'wpml_default_language', '' );
	}

	public function getCurrentLanguage(): string {
		return (string) apply_filters( 'wpml_current_language', $this->getDefaultLanguage() );
	}

	public function getPostLanguage( int $postId ): string {
		$postType = get_post_type( $postId );
		if ( ! $postType ) {
			return '';
		}
		return (string) apply_filters(
			'wpml_element_language_code',
			'',
			array(
				'element_id'   => $postId,
				'element_type' => 'post_' . $postType,
			)
		);
	}

	public function getPostTranslations( int $postId ): array {
		$postType = get_post_type( $postId );
		if ( ! $postType ) {
			return array();
		}
		$elementType = (string) apply_filters( 'wpml_element_type', 'post_' . $postType );
		$trid        = (int) apply_filters( 'wpml_element_trid', 0, $postId, $elementType );
		if ( ! $trid ) {
			return array( $this->getPostLanguage( $postId ) => $postId );
		}
		$rows   = (array) apply_filters( 'wpml_get_element_translations', array(), $trid, $elementType );
		$result = array();
		foreach ( $rows as $code => $row ) {
			$id = is_object( $row ) ? (int) ( $row->element_id ?? 0 ) : (int) ( $row['element_id'] ?? 0 );
			if ( $id > 0 ) {
				$result[ (string) $code ] = $id;
			}
		}
		return $result;
	}

	public function getPostTranslation( int $postId, string $language ): ?int {
		$translations = $this->getPostTranslations( $postId );
		return isset( $translations[ $language ] ) ? (int) $translations[ $language ] : null;
	}

	public function setPostLanguage( int $postId, string $language ): void {
		$postType = get_post_type( $postId );
		if ( ! $postType ) {
			throw new RuntimeException( 'Cannot set language for an unknown post.' );
		}
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $postId,
				'element_type'         => 'post_' . $postType,
				'trid'                 => false,
				'language_code'        => $language,
				'source_language_code' => null,
			)
		);
	}

	public function connectPostTranslations( array $translations ): void {
		$translations = array_filter( $translations, static fn ( $id ): bool => (int) $id > 0 );
		if ( ! $translations ) {
			return;
		}
		$firstId  = (int) reset( $translations );
		$postType = get_post_type( $firstId );
		if ( ! $postType ) {
			throw new RuntimeException( 'Cannot connect translations for an unknown post type.' );
		}
		$elementType    = 'post_' . $postType;
		$trid           = (int) apply_filters( 'wpml_element_trid', 0, $firstId, $elementType );
		$sourceLanguage = (string) array_key_first( $translations );
		foreach ( $translations as $language => $postId ) {
			$details = apply_filters(
				'wpml_element_language_details',
				null,
				array(
					'element_id'   => (int) $postId,
					'element_type' => $elementType,
				)
			);
			if ( is_object( $details ) && empty( $details->source_language_code ) ) {
				$sourceLanguage = (string) $language;
				break;
			}
		}
		foreach ( $translations as $language => $postId ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'           => (int) $postId,
					'element_type'         => $elementType,
					'trid'                 => $trid ? $trid : null,
					'language_code'        => (string) $language,
					'source_language_code' => $language === $sourceLanguage ? null : $sourceLanguage,
					'check_duplicates'     => true,
				)
			);
			if ( ! $trid ) {
				$trid = (int) apply_filters( 'wpml_element_trid', 0, (int) $postId, $elementType );
			}
		}
	}

	public function getTermLanguage( int $termId ): string {
		$term = get_term( $termId );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		return (string) apply_filters(
			'wpml_element_language_code',
			'',
			array(
				'element_id'   => (int) $term->term_taxonomy_id,
				'element_type' => 'tax_' . $term->taxonomy,
			)
		);
	}

	public function getTermTranslations( int $termId ): array {
		$term = get_term( $termId );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}
		$elementType = 'tax_' . $term->taxonomy;
		$trid        = (int) apply_filters( 'wpml_element_trid', 0, (int) $term->term_taxonomy_id, $elementType );
		$rows        = $trid ? (array) apply_filters( 'wpml_get_element_translations', array(), $trid, $elementType ) : array();
		$result      = array();
		foreach ( $rows as $code => $row ) {
			$ttId = is_object( $row ) ? (int) ( $row->element_id ?? 0 ) : (int) ( $row['element_id'] ?? 0 );
			if ( $ttId ) {
				$found = get_term_by( 'term_taxonomy_id', $ttId, $term->taxonomy );
				if ( $found ) {
					$result[ (string) $code ] = (int) $found->term_id;
				}
			}
		}
		return $result;
	}

	public function setTermLanguage( int $termId, string $language ): void {
		$term = get_term( $termId );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new RuntimeException( 'Cannot set language for an unknown term.' );
		}
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => (int) $term->term_taxonomy_id,
				'element_type'         => 'tax_' . $term->taxonomy,
				'trid'                 => false,
				'language_code'        => $language,
				'source_language_code' => null,
			)
		);
	}

	public function connectTermTranslations( array $translations ): void {
		$translations = array_filter( $translations, static fn ( $id ): bool => (int) $id > 0 );
		if ( ! $translations ) {
			return;
		}
		$first = get_term( (int) reset( $translations ) );
		if ( ! $first || is_wp_error( $first ) ) {
			throw new RuntimeException( 'Cannot connect unknown terms.' );
		}
		$elementType    = 'tax_' . $first->taxonomy;
		$sourceLanguage = (string) array_key_first( $translations );
		foreach ( $translations as $language => $termId ) {
			$candidate = get_term( (int) $termId, $first->taxonomy );
			if ( ! $candidate || is_wp_error( $candidate ) ) {
				continue;
			}
			$details = apply_filters(
				'wpml_element_language_details',
				null,
				array(
					'element_id'   => (int) $candidate->term_taxonomy_id,
					'element_type' => $elementType,
				)
			);
			if ( is_object( $details ) && empty( $details->source_language_code ) ) {
				$sourceLanguage = (string) $language;
				break;
			}
		}
		$trid = 0;
		foreach ( $translations as $language => $termId ) {
			$term = get_term( (int) $termId, $first->taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'           => (int) $term->term_taxonomy_id,
					'element_type'         => $elementType,
					'trid'                 => $trid ? $trid : null,
					'language_code'        => (string) $language,
					'source_language_code' => $language === $sourceLanguage ? null : $sourceLanguage,
				)
			);
			if ( ! $trid ) {
				$trid = (int) apply_filters( 'wpml_element_trid', 0, (int) $term->term_taxonomy_id, $elementType );
			}
		}
	}

	public function isPostTypeTranslatable( string $postType ): bool {
		return (bool) apply_filters( 'wpml_is_translated_post_type', false, $postType );
	}

	public function isTaxonomyTranslatable( string $taxonomy ): bool {
		return (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy );
	}

	/** WPML translation preference: 1 copy, 2 translate, 3 copy once, 0 ignore. */
	public function getCustomFieldStrategy( string $metaKey, bool $creating, ?array $fieldDefinition = null ): ?string {
		$definitionPreference = is_array( $fieldDefinition ) && isset( $fieldDefinition['wpml_cf_preferences'] ) && is_numeric( $fieldDefinition['wpml_cf_preferences'] )
			? (int) $fieldDefinition['wpml_cf_preferences']
			: null;
		$preferences          = (array) apply_filters( 'wpml_cf_translation_preferences', array() );
		if ( ! array_key_exists( $metaKey, $preferences ) ) {
			$settings    = (array) get_option( 'icl_sitepress_settings', array() );
			$translation = isset( $settings['translation-management'] ) && is_array( $settings['translation-management'] )
				? (array) ( $settings['translation-management']['custom_fields_translation'] ?? array() )
				: array();
			$preferences = $translation + (array) ( $settings['custom_fields_translation'] ?? array() ) + $preferences;
		}
		$preference = $definitionPreference ?? ( $preferences[ $metaKey ] ?? null );
		if ( is_array( $preference ) ) {
			$preference = $preference['translation_option'] ?? null;
		}
		if ( $preference === null || $preference === '' ) {
			return null;
		}
		return match ( (int) $preference ) {
			2 => 'translate',
			1 => 'copy',
			3 => $creating ? 'copy' : 'ignore',
			default => 'ignore',
		};
	}
}
