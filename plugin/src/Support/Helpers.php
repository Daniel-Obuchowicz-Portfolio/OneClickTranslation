<?php

declare(strict_types=1);

namespace OneClickTranslation\Support;

final class Helpers {

	/** @return array<string, mixed> */
	public static function settings(): array {
		$defaults = array(
			'provider'                => 'auto',
			'api_type'                => 'free',
			'api_key'                 => '',
			'formality'               => 'default',
			'preserve_formatting'     => true,
			'split_sentences'         => '1',
			'translate_title'         => true,
			'translate_content'       => true,
			'translate_excerpt'       => true,
			'translate_slug'          => false,
			'translate_taxonomies'    => true,
			'translate_custom_fields' => true,
			'translate_acf'           => true,
			'translate_gutenberg'     => true,
			'copy_featured_image'     => true,
			'copy_attachments'        => true,
			'preserve_html'           => true,
			'preserve_shortcodes'     => true,
			'preserve_urls'           => true,
			'preserve_emails'         => true,
			'preserve_code_blocks'    => true,
			'preserve_placeholders'   => true,
			'auto_new'                => false,
			'auto_update'             => false,
			'only_published'          => true,
			'translate_drafts'        => false,
			'translate_scheduled'     => false,
			'target_languages'        => array(),
			'show_internal_fields'    => false,
			'queue_batch_size'        => 3,
			'queue_max_attempts'      => 3,
			'request_timeout'         => 30,
			'request_retries'         => 2,
		);
		return array_replace( $defaults, (array) get_option( 'oct_settings', array() ) );
	}

	public static function apiKey(): string {
		if ( defined( 'OCT_DEEPL_API_KEY' ) && trim( (string) OCT_DEEPL_API_KEY ) !== '' ) {
			return trim( (string) OCT_DEEPL_API_KEY );
		}
		return trim( (string) ( self::settings()['api_key'] ?? '' ) );
	}

	public static function debug(): bool {
		return defined( 'OCT_DEBUG' ) && OCT_DEBUG === true;
	}

	public static function maskSecret( string $value ): string {
		$length = strlen( $value );
		if ( $length < 8 ) {
			return $length ? str_repeat( '•', $length ) : '';
		}
		return substr( $value, 0, 3 ) . str_repeat( '•', max( 4, $length - 7 ) ) . substr( $value, -4 );
	}

	public static function normalizeLanguage( string $code ): string {
		return strtolower( str_replace( '_', '-', trim( $code ) ) );
	}

	public static function deeplLanguage( string $code ): string {
		$code    = strtoupper( str_replace( '_', '-', trim( $code ) ) );
		$aliases = array(
			'EN' => 'EN-US',
			'PT' => 'PT-PT',
			'ZH' => 'ZH-HANS',
			'NB' => 'NB',
		);
		return $aliases[ $code ] ?? $code;
	}

	public static function deeplSourceLanguage( string $code ): string {
		$code = strtoupper( str_replace( '_', '-', trim( $code ) ) );
		return explode( '-', $code )[0];
	}

	public static function isInternalMeta( string $key ): bool {
		// The page template is user-facing configuration and must follow the page.
		if ( '_wp_page_template' === $key ) {
			return false;
		}
		if ( ! str_starts_with( $key, '_' ) ) {
			return false;
		}
		return (bool) preg_match( '/^(_edit_|_wp_|_oembed_|_encloseme|_pingme|_thumbnail_id|_menu_item_|_wpml_|_pll_|_oct_|_acf_)/', $key );
	}

	public static function postTypeConfig( string $postType ): array {
		$all      = (array) get_option( 'oct_content_types', array() );
		$defaults = array(
			'enabled'        => true,
			'title'          => true,
			'content'        => true,
			'excerpt'        => true,
			'slug'           => false,
			'taxonomies'     => true,
			'custom_fields'  => true,
			'auto_translate' => false,
		);
		return array_replace( $defaults, isset( $all[ $postType ] ) && is_array( $all[ $postType ] ) ? $all[ $postType ] : array() );
	}

	/** @return list<string> */
	public static function publicPostTypes(): array {
		$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$types = array_values( array_diff( $types, array( 'attachment' ) ) );
		return array_values( apply_filters( 'oct_supported_post_types', $types ) );
	}
}
