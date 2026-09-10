<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

use OneClickTranslation\Content\ACFTranslator;
use OneClickTranslation\Content\GutenbergTranslator;
use OneClickTranslation\Content\MetaTranslator;
use OneClickTranslation\Content\PostTranslator;
use OneClickTranslation\Content\ShortcodeProtector;
use OneClickTranslation\Content\TaxonomyTranslator;
use OneClickTranslation\DeepL\DeepLClient;
use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Support\Logger;
use RuntimeException;
use Throwable;

final class TranslationService {

	public function __construct(
		private readonly ProviderResolver $resolver,
		private readonly TranslationCache $cache,
		private readonly TranslationStatus $status,
		private readonly Logger $logger,
		private readonly ?DeepLClient $client = null
	) {}

	public function translatePost( int $sourcePostId, string $targetLanguage, ?string $providerName = null ): TranslationResult {
		$provider       = $this->resolver->resolve( $providerName );
		$targetLanguage = sanitize_key( $targetLanguage );
		$source         = get_post( $sourcePostId );
		if ( ! $source instanceof \WP_Post ) {
			throw new RuntimeException( 'Source post does not exist.' );
		}
		if ( ! current_user_can( 'edit_post', $sourcePostId ) && ! wp_doing_cron() && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
			throw new RuntimeException( 'You are not allowed to translate this post.' );
		}
		if ( ! $provider->isPostTypeTranslatable( $source->post_type ) ) {
			throw new RuntimeException( 'The post type is not translatable in ' . $provider->getProviderName() . '.' );
		}
		$languages = $provider->getLanguages();
		if ( ! isset( $languages[ $targetLanguage ] ) ) {
			throw new RuntimeException( 'Target language is not configured in ' . $provider->getProviderName() . '.' );
		}
		$sourceLanguage = $provider->getPostLanguage( $sourcePostId );
		if ( $sourceLanguage === '' || $sourceLanguage === $targetLanguage ) {
			throw new RuntimeException( 'Source and target languages must be configured and different.' );
		}
		$targetId  = $provider->getPostTranslation( $sourcePostId, $targetLanguage );
		$created   = ! $targetId;
		$policy    = new FieldPolicy( $provider );
		$meta      = new MetaTranslator( $policy );
		$acf       = new ACFTranslator( $policy, $provider );
		$gutenberg = new GutenbergTranslator();
		$extractor = new ContentExtractor( $meta, $acf, $gutenberg );
		$batch     = new BatchBuilder();
		$extracted = $extractor->extract( $sourcePostId, $batch, $created );
		$this->filterTaxonomies( $extracted, $provider );
		$this->addTaxonomyBatch( $batch, $extracted, $provider, $targetLanguage );
		$hash               = ( new ContentHasher() )->hash( $extractor->hashPayload( $extracted ) );
		$savedId            = 0;
		$characters         = 0;
		$cacheHits          = 0;
		$translations       = array();
		$transactionStarted = false;
		global $wpdb;
		try {
			$this->logger->debug(
				'Translation batch prepared.',
				array(
					'object_id'       => $sourcePostId,
					'provider'        => $provider->getProviderName(),
					'source_language' => $sourceLanguage,
					'target_language' => $targetLanguage,
					'batch_items'     => $batch->count(),
					'characters'      => $batch->characters(),
					'chunks'          => count( $batch->chunks() ),
				)
			);
			foreach ( $batch->items() as $item ) {
				foreach ( $item['paths'] as $path ) {
					do_action( 'oct_before_translate_field', $path, $item['text'], $sourcePostId );
				}
			}
			do_action( 'oct_before_translate_post', $sourcePostId, $targetLanguage, $extracted );
			$this->status->set( $sourcePostId, $targetLanguage, TranslationStatus::PROCESSING, $targetId, $hash );
			[$translations, $characters, $cacheHits] = $this->translateBatch( $batch, $sourceLanguage, $targetLanguage );
			$writer                                  = new TranslationWriter(
				$provider,
				new PostTranslator(),
				$meta,
				$acf,
				$gutenberg,
				new TaxonomyTranslator( $provider )
			);
			$wpdb->query( 'START TRANSACTION' );
			$transactionStarted         = true;
			$GLOBALS['oct_translating'] = true;
			$savedId                    = $writer->write(
				$sourcePostId,
				$targetId,
				$targetLanguage,
				$extracted,
				$translations
			);
			update_post_meta( $savedId, '_oct_source_post_id', $sourcePostId );
			update_post_meta( $savedId, '_oct_source_hash', $hash );
			update_post_meta( $savedId, '_oct_translated_at', current_time( 'mysql', true ) );
			$this->status->set( $sourcePostId, $targetLanguage, TranslationStatus::TRANSLATED, $savedId, $hash );
			$wpdb->query( 'COMMIT' );
			$transactionStarted = false;
		} catch ( Throwable $exception ) {
			unset( $GLOBALS['oct_translating'] );
			if ( $transactionStarted ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( $created && $savedId > 0 && get_post( $savedId ) ) {
				wp_delete_post( $savedId, true );
			}
			$this->status->set( $sourcePostId, $targetLanguage, TranslationStatus::ERROR, $targetId, $hash, $exception->getMessage() );
			$this->logger->error(
				'Post translation failed: ' . $exception->getMessage(),
				array(
					'object_id'       => $sourcePostId,
					'target_language' => $targetLanguage,
					'exception'       => get_class( $exception ),
				)
			);
			$this->dispatchAction( 'oct_translation_failed', $sourcePostId, $targetLanguage, $exception );
			throw $exception;
		}
		unset( $GLOBALS['oct_translating'] );
		foreach ( $translations as $path => $value ) {
			$this->dispatchAction( 'oct_after_translate_field', $path, $value, $savedId );
		}
		$action = $created ? 'oct_translation_created' : 'oct_translation_updated';
		$this->dispatchAction( $action, $sourcePostId, $savedId, $targetLanguage );
		$this->dispatchAction( 'oct_after_translate_post', $sourcePostId, $savedId, $targetLanguage );
		$this->logger->info(
			'Post translation completed.',
			array(
				'object_id'            => $sourcePostId,
				'target_language'      => $targetLanguage,
				'translated_object_id' => $savedId,
				'characters'           => $characters,
				'cache_hits'           => $cacheHits,
				'batch_items'          => $batch->count(),
			)
		);
		return new TranslationResult( $sourcePostId, $savedId, $targetLanguage, TranslationStatus::TRANSLATED, $hash, $characters, $cacheHits, $created );
	}

	private function dispatchAction( string $hook, mixed ...$arguments ): void {
		try {
			do_action( $hook, ...$arguments );
		} catch ( Throwable $exception ) {
			try {
				$this->logger->warning(
					'Extension hook failed after translation state was saved.',
					array(
						'hook'  => $hook,
						'error' => $exception->getMessage(),
					)
				);
			} catch ( Throwable ) {
				// The completed translation remains valid even if diagnostic persistence fails.
			}
		}
	}

	/** @return array{0:array<string,string>,1:int,2:int} */
	private function translateBatch( BatchBuilder $batch, string $sourceLanguage, string $targetLanguage ): array {
		$results    = array();
		$characters = 0;
		$cacheHits  = 0;
		foreach ( $batch->chunks() as $chunk ) {
			$commonContext = implode( ' | ', array_slice( array_values( array_unique( array_filter( array_column( $chunk, 'context' ) ) ) ), 0, 8 ) );
			$commonContext = mb_substr( $commonContext, 0, 1000 );
			$commonContext = (string) apply_filters( 'oct_translation_context', $commonContext, $chunk, $sourceLanguage, $targetLanguage );
			$first         = reset( $chunk );
			$html          = (bool) $first['html'];
			$options       = $this->options( $sourceLanguage, $html, $commonContext );
			$missing       = array();
			foreach ( $chunk as $key => $item ) {
				$cached = $this->cache->get( $sourceLanguage, $targetLanguage, $item['text'], $options );
				if ( $cached !== null ) {
					$results[ $key ] = $cached;
					++$cacheHits;
				} else {
					$missing[ $key ] = $item;
				}
			}
			if ( ! $missing ) {
				continue;
			}
			$texts      = array();
			$protectors = array();
			foreach ( $missing as $key => $item ) {
				$protectors[ $key ] = new ShortcodeProtector();
				$texts[]            = $protectors[ $key ]->protect( $item['text'], Helpers::settings() );
				$characters        += mb_strlen( $item['text'] );
			}
			$response = $this->deepL()->translateBatch( $texts, $targetLanguage, $options );
			foreach ( array_keys( $missing ) as $index => $key ) {
				$translation     = $response->translations[ $index ];
				$translation     = $protectors[ $key ]->restore( $translation );
				$results[ $key ] = $translation;
				$this->cache->put( $sourceLanguage, $targetLanguage, $missing[ $key ]['text'], $translation, $options );
			}
		}
		$mapped = ( new BatchResultMapper() )->map( $batch->items(), $results );
		return array( $mapped, $characters, $cacheHits );
	}

	private function addTaxonomyBatch( BatchBuilder $batch, array $extracted, \OneClickTranslation\Contracts\LanguageProviderInterface $provider, string $targetLanguage ): void {
		if ( ! Helpers::settings()['translate_taxonomies'] ) {
			return;
		}
		$title = (string) ( $extracted['post_fields']['post_title'] ?? '' );
		$seen  = array();
		foreach ( (array) ( $extracted['taxonomy_hash'] ?? array() ) as $taxonomy => $terms ) {
			if ( ! $provider->isTaxonomyTranslatable( (string) $taxonomy ) ) {
				continue;
			}
			foreach ( (array) $terms as $term ) {
				$this->addTermToBatch( $batch, (int) $term['id'], (string) $taxonomy, $provider, $targetLanguage, $title, $seen );
			}
		}
	}

	/** @param array<int,true> $seen */
	private function addTermToBatch( BatchBuilder $batch, int $termId, string $taxonomy, \OneClickTranslation\Contracts\LanguageProviderInterface $provider, string $targetLanguage, string $context, array &$seen ): void {
		if ( isset( $seen[ $termId ] ) ) {
			return;
		}
		$seen[ $termId ] = true;
		$term            = get_term( $termId, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		if ( (int) $term->parent > 0 ) {
			$this->addTermToBatch( $batch, (int) $term->parent, $taxonomy, $provider, $targetLanguage, $context, $seen );
		}
		$prefix = 'taxonomy.' . \OneClickTranslation\Support\Arr::encodeSegment( $taxonomy ) . '.' . $termId;
		$batch->add( $prefix . '.name', (string) $term->name, false, $context . ' taxonomy ' . $taxonomy );
		if ( '' !== (string) $term->description ) {
			$batch->add( $prefix . '.description', (string) $term->description, true, $context . ' taxonomy description' );
		}
		if ( Helpers::settings()['translate_slug'] && '' !== (string) $term->slug ) {
			$batch->add( $prefix . '.slug', str_replace( '-', ' ', (string) $term->slug ), false, $context . ' taxonomy URL slug' );
		}
	}

	/** @param array<string,mixed> $extracted */
	private function filterTaxonomies( array &$extracted, \OneClickTranslation\Contracts\LanguageProviderInterface $provider ): void {
		foreach ( array_keys( (array) ( $extracted['taxonomy_hash'] ?? array() ) ) as $taxonomy ) {
			if ( ! $provider->isTaxonomyTranslatable( (string) $taxonomy ) ) {
				unset( $extracted['taxonomy_hash'][ $taxonomy ] );
			}
		}
	}

	/** @return array<string,mixed> */
	private function options( string $sourceLanguage, bool $html, string $context ): array {
		$settings = Helpers::settings();
		$options  = array(
			'source_lang'         => Helpers::deeplSourceLanguage( $sourceLanguage ),
			'formality'           => $settings['formality'],
			'preserve_formatting' => (bool) $settings['preserve_formatting'],
			'split_sentences'     => (string) $settings['split_sentences'],
		);
		if ( $html ) {
			$options['tag_handling'] = 'html'; }
		if ( $context !== '' ) {
			$options['context'] = $context; }
		return $options;
	}

	private function deepL(): DeepLClient {
		if ( $this->client ) {
			return $this->client;
		}
		$settings = Helpers::settings();
		return new DeepLClient( Helpers::apiKey(), (string) $settings['api_type'], (int) $settings['request_timeout'], (int) $settings['request_retries'] );
	}
}
