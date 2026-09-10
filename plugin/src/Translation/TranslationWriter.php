<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

use OneClickTranslation\Content\ACFTranslator;
use OneClickTranslation\Content\GutenbergTranslator;
use OneClickTranslation\Content\MetaTranslator;
use OneClickTranslation\Content\PostTranslator;
use OneClickTranslation\Content\TaxonomyTranslator;
use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Support\Helpers;

final class TranslationWriter {

	public function __construct(
		private readonly LanguageProviderInterface $provider,
		private readonly PostTranslator $posts,
		private readonly MetaTranslator $meta,
		private readonly ACFTranslator $acf,
		private readonly GutenbergTranslator $gutenberg,
		private readonly TaxonomyTranslator $taxonomies
	) {}

	/** @param array<string,mixed> $extracted @param array<string,string> $translations */
	public function write( int $sourceId, ?int $targetId, string $targetLanguage, array $extracted, array $translations ): int {
		/** @var \WP_Post $source */
		$source = $extracted['post'];
		$fields = $extracted['post_fields'];
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) as $field ) {
			if ( isset( $translations[ 'post.' . $field ] ) ) {
				$fields[ $field ] = $translations[ 'post.' . $field ];
			}
		}
		if ( ! empty( $extracted['gutenberg']['blocks'] ) ) {
			$fields['post_content'] = $this->gutenberg->apply( $extracted['gutenberg']['blocks'], $translations );
		}
		if ( isset( $translations['post.post_name'] ) ) {
			$fields['post_name'] = sanitize_title( $translations['post.post_name'] );
		}
		if ( $source->post_parent > 0 ) {
			$fields['post_parent'] = $this->provider->getPostTranslation( (int) $source->post_parent, $targetLanguage ) ?? 0;
		}
		$savedId = $this->posts->save( $targetId, $source, $fields );
		$this->provider->setPostLanguage( $savedId, $targetLanguage );
		$sourceLanguage               = $this->provider->getPostLanguage( $sourceId );
		$relations                    = array( $sourceLanguage => $sourceId ) + $this->provider->getPostTranslations( $sourceId );
		$relations[ $targetLanguage ] = $savedId;
		$this->provider->connectPostTranslations( $relations );
		$this->meta->save( $savedId, $extracted['meta'], $translations );
		$this->taxonomies->sync( $sourceId, $savedId, $targetLanguage, $translations );
		$this->acf->save( $savedId, $extracted['acf'], $translations, $targetLanguage );
		if ( Helpers::settings()['copy_featured_image'] ) {
			$thumbnailId = get_post_thumbnail_id( $sourceId );
			if ( $thumbnailId ) {
				set_post_thumbnail( $savedId, $thumbnailId );
			} else {
				delete_post_thumbnail( $savedId );
			}
		}
		return $savedId;
	}
}
