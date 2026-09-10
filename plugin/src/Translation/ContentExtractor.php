<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

use OneClickTranslation\Content\ACFTranslator;
use OneClickTranslation\Content\GutenbergTranslator;
use OneClickTranslation\Content\MetaTranslator;
use OneClickTranslation\Support\Helpers;
use RuntimeException;

final class ContentExtractor {

	public function __construct(
		private readonly MetaTranslator $meta,
		private readonly ACFTranslator $acf,
		private readonly GutenbergTranslator $gutenberg
	) {}

	/** @return array<string,mixed> */
	public function extract( int $postId, BatchBuilder $batch, bool $creating ): array {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Source post does not exist.' );
		}
		$settings = Helpers::settings();
		$type     = Helpers::postTypeConfig( $post->post_type );
		if ( ! $type['enabled'] ) {
			throw new RuntimeException( 'This post type is disabled in OneClickTranslation.' );
		}
		$postTypeObject = get_post_type_object( $post->post_type );
		$typeLabel      = $postTypeObject && isset( $postTypeObject->labels->singular_name ) ? (string) $postTypeObject->labels->singular_name : $post->post_type;
		$context        = trim( $post->post_title . ' — ' . $typeLabel );
		$data           = array(
			'post'          => $post,
			'post_fields'   => array(
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_name'    => $post->post_name,
			),
			'gutenberg'     => null,
			'meta'          => array(),
			'acf'           => array(),
			'taxonomy_hash' => array(),
		);
		if ( $settings['translate_title'] && $type['title'] ) {
			$batch->add( 'post.post_title', $post->post_title, false, $context . ' title' );
		}
		if ( $settings['translate_excerpt'] && $type['excerpt'] && trim( $post->post_excerpt ) !== '' ) {
			$batch->add( 'post.post_excerpt', $post->post_excerpt, str_contains( $post->post_excerpt, '<' ), $context . ' excerpt' );
		}
		if ( $settings['translate_slug'] && $type['slug'] && $post->post_name !== '' ) {
			$batch->add( 'post.post_name', str_replace( '-', ' ', $post->post_name ), false, $context . ' URL slug' );
		}
		if ( $settings['translate_content'] && $type['content'] && trim( $post->post_content ) !== '' ) {
			if ( $settings['translate_gutenberg'] && has_blocks( $post->post_content ) ) {
				$data['gutenberg'] = $this->gutenberg->extract( $post->post_content, $batch, $context );
			} else {
				$batch->add( 'post.post_content', $post->post_content, (bool) $settings['preserve_html'], $context . ' body' );
			}
		}
		if ( $type['custom_fields'] ) {
			$data['meta'] = $this->meta->extract( $postId, $batch, $context, $creating );
			$data['acf']  = $this->acf->extract( $postId, $batch, $context, $creating );
		}
		if ( $type['taxonomies'] ) {
			foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
				$terms = wp_get_object_terms( $postId, (string) $taxonomy );
				if ( ! is_wp_error( $terms ) ) {
					$data['taxonomy_hash'][ $taxonomy ] = array_map(
						static fn ( $term ): array => array(
							'id'          => (int) $term->term_id,
							'name'        => (string) $term->name,
							'description' => (string) $term->description,
							'slug'        => (string) $term->slug,
						),
						$terms
					);
				}
			}
		}
		return $data;
	}

	/** @param array<string,mixed> $extracted */
	public function hashPayload( array $extracted ): array {
		$meta = array();
		foreach ( (array) $extracted['meta'] as $key => $definition ) {
			$meta[ $key ] = $definition['values'];
		}
		$acf = array();
		foreach ( (array) $extracted['acf'] as $key => $definition ) {
			$acf[ $key ] = $definition['value'];
		}
		return array(
			'title'      => $extracted['post_fields']['post_title'],
			'content'    => $extracted['post_fields']['post_content'],
			'excerpt'    => $extracted['post_fields']['post_excerpt'],
			'slug'       => $extracted['post_fields']['post_name'],
			'meta'       => $meta,
			'acf'        => $acf,
			'taxonomies' => $extracted['taxonomy_hash'],
		);
	}
}
