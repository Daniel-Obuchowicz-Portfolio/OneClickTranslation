<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Support\Arr;
use OneClickTranslation\Support\Helpers;
use RuntimeException;

final class TaxonomyTranslator {

	public function __construct( private readonly LanguageProviderInterface $provider ) {}

	/** @param array<string,string> $translations */
	public function sync( int $sourcePostId, int $targetPostId, string $targetLanguage, array $translations ): void {
		if ( ! Helpers::settings()['translate_taxonomies'] ) {
			return;
		}
		$sourceLanguage = $this->provider->getPostLanguage( $sourcePostId );
		foreach ( get_object_taxonomies( (string) get_post_type( $sourcePostId ), 'names' ) as $taxonomy ) {
			if ( ! $this->provider->isTaxonomyTranslatable( (string) $taxonomy ) ) {
				continue;
			}
			$targetTerms = array();
			$terms       = wp_get_object_terms( $sourcePostId, (string) $taxonomy );
			if ( is_wp_error( $terms ) ) {
				throw new RuntimeException( $terms->get_error_message() );
			}
			foreach ( $terms as $term ) {
				$targetTermId  = $this->ensureTranslation( $term, (string) $taxonomy, $sourceLanguage, $targetLanguage, $translations );
				$targetTerms[] = $targetTermId;
			}
			$assigned = wp_set_object_terms( $targetPostId, $targetTerms, (string) $taxonomy, false );
			if ( is_wp_error( $assigned ) ) {
				throw new RuntimeException( $assigned->get_error_message() );
			}
		}
	}

	/** @param array<string,string> $translations */
	private function ensureTranslation( \WP_Term $term, string $taxonomy, string $sourceLanguage, string $targetLanguage, array $translations, array $stack = array() ): int {
		if ( isset( $stack[ $term->term_id ] ) ) {
			throw new RuntimeException( 'A cyclic taxonomy hierarchy was detected.' );
		}
		$stack[ $term->term_id ] = true;
		$termTranslations        = $this->provider->getTermTranslations( (int) $term->term_id );
		$parentId                = 0;
		if ( (int) $term->parent > 0 ) {
			$parent = get_term( (int) $term->parent, $taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$parentId = $this->ensureTranslation( $parent, $taxonomy, $sourceLanguage, $targetLanguage, $translations, $stack );
			}
		}
		$prefix      = 'taxonomy.' . Arr::encodeSegment( $taxonomy ) . '.' . (int) $term->term_id;
		$name        = $translations[ $prefix . '.name' ] ?? (string) $term->name;
		$description = $translations[ $prefix . '.description' ] ?? (string) $term->description;
		$args        = array(
			'description' => $description,
			'parent'      => $parentId,
			'slug'        => (string) $term->slug,
		);
		if ( Helpers::settings()['translate_slug'] ) {
			$args['slug'] = sanitize_title( $translations[ $prefix . '.slug' ] ?? (string) $term->slug );
		}
		if ( isset( $termTranslations[ $targetLanguage ] ) ) {
			$targetTermId = (int) $termTranslations[ $targetLanguage ];
			$updated      = wp_update_term( $targetTermId, $taxonomy, $args + array( 'name' => $name ) );
			if ( is_wp_error( $updated ) && in_array( $updated->get_error_code(), array( 'duplicate_term_slug', 'term_exists' ), true ) ) {
				$args['slug'] = $this->uniqueSlug( sanitize_title( $name ) . '-' . $targetLanguage, $taxonomy, $parentId, $targetTermId );
				$updated      = wp_update_term( $targetTermId, $taxonomy, $args + array( 'name' => $name ) );
			}
			if ( is_wp_error( $updated ) ) {
				throw new RuntimeException( $updated->get_error_message() );
			}
			return $targetTermId;
		}
		$inserted = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $inserted ) && 'term_exists' === $inserted->get_error_code() ) {
			$candidateId       = (int) $inserted->get_error_data();
			$candidateLanguage = $candidateId ? $this->provider->getTermLanguage( $candidateId ) : '';
			if ( $candidateId && ( '' === $candidateLanguage || $targetLanguage === $candidateLanguage ) ) {
				$targetTermId = $candidateId;
			} else {
				$args['slug'] = $this->uniqueSlug( sanitize_title( $name ) . '-' . $targetLanguage, $taxonomy, $parentId );
				$inserted     = wp_insert_term( $name, $taxonomy, $args );
				if ( is_wp_error( $inserted ) ) {
					throw new RuntimeException( $inserted->get_error_message() );
				}
				$targetTermId = (int) $inserted['term_id'];
			}
		} elseif ( is_wp_error( $inserted ) ) {
			throw new RuntimeException( $inserted->get_error_message() );
		} else {
			$targetTermId = (int) $inserted['term_id'];
		}
		$this->provider->setTermLanguage( $targetTermId, $targetLanguage );
		$termTranslations                    = array( $sourceLanguage => (int) $term->term_id ) + $termTranslations;
		$termTranslations[ $targetLanguage ] = $targetTermId;
		$this->provider->connectTermTranslations( $termTranslations );
		return $targetTermId;
	}

	private function uniqueSlug( string $base, string $taxonomy, int $parentId, int $excludeTermId = 0 ): string {
		$slug = $base;
		for ( $suffix = 2; ; ++$suffix ) {
			$existing   = term_exists( $slug, $taxonomy, $parentId );
			$existingId = is_array( $existing ) ? (int) ( $existing['term_id'] ?? 0 ) : (int) $existing;
			if ( ! $existingId || $existingId === $excludeTermId ) {
				break;
			}
			$slug = $base . '-' . $suffix;
		}
		return $slug;
	}
}
