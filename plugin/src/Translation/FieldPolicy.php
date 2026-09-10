<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Support\Helpers;

final class FieldPolicy {

	public const TRANSLATE = 'translate';
	public const COPY      = 'copy';
	public const IGNORE    = 'ignore';

	public function __construct( private readonly ?LanguageProviderInterface $provider = null ) {}

	/** @param array<string,mixed>|null $fieldDefinition */
	public function decide( string $fieldName, mixed $value, ?string $acfType = null, bool $creating = true, ?array $fieldDefinition = null ): string {
		$strategy   = $this->configuredStrategy( $fieldName, $creating, $fieldDefinition );
		$strategy ??= $this->fromAcfType( $acfType );
		$strategy ??= $this->infer( $fieldName, $value );
		$strategy   = (string) apply_filters( 'oct_field_strategy', $strategy, $fieldName, $value, $acfType );
		$should     = (bool) apply_filters( 'oct_should_translate_field', $strategy === self::TRANSLATE, $fieldName, $value, $strategy );
		return $strategy === self::TRANSLATE && ! $should ? self::COPY : $strategy;
	}

	/** @param array<string,mixed>|null $fieldDefinition */
	public function configuredStrategy( string $fieldName, bool $creating = true, ?array $fieldDefinition = null ): ?string {
		$strategy = $this->provider ? $this->provider->getCustomFieldStrategy( $fieldName, $creating, $fieldDefinition ) : null;
		$manual   = (array) get_option( 'oct_field_policies', array() );
		if ( $strategy === null && isset( $manual[ $fieldName ] ) && in_array( $manual[ $fieldName ], array( self::TRANSLATE, self::COPY, self::IGNORE ), true ) ) {
			$strategy = (string) $manual[ $fieldName ];
		}
		return $strategy;
	}

	private function fromAcfType( ?string $type ): ?string {
		if ( $type === null ) {
			return null;
		}
		if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
			return self::TRANSLATE;
		}
		if ( in_array( $type, array( 'number', 'range', 'true_false', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'image', 'file', 'gallery', 'relationship', 'post_object', 'user', 'taxonomy' ), true ) ) {
			return self::COPY;
		}
		return in_array( $type, array( 'group', 'repeater', 'flexible_content', 'clone', 'link' ), true ) ? null : self::COPY;
	}

	private function infer( string $fieldName, mixed $value ): string {
		$key = strtolower( $fieldName );
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || $value === null ) {
			return self::COPY;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return self::COPY;
		}
		$text = trim( (string) $value );
		if ( $text === '' ) {
			return self::COPY;
		}
		if ( Helpers::isInternalMeta( $fieldName ) || preg_match( '/(^|_)(secret|token|password|nonce|checksum|hash|internal)(_|$)/i', $key ) ) {
			return self::IGNORE;
		}
		if ( $this->looksTechnical( $text, $key ) ) {
			return self::COPY;
		}
		if ( preg_match( '/(^|_)(id|ids|uuid|guid|url|uri|href|src|path|file|image|gallery|attachment|price|amount|count|quantity|stock|sku|date|time|color|colour|latitude|longitude|lat|lng|coordinates|class|classes|style|layout|template|enabled|disabled|order|priority)(_|$)/i', $key ) ) {
			return self::COPY;
		}
		if ( preg_match( '/(^|_)(title|heading|headline|description|excerpt|content|text|label|caption|message|subtitle|summary|body|quote|question|answer|cta)(_|$)/i', $key ) ) {
			return self::TRANSLATE;
		}
		return preg_match( '/\p{L}{2,}/u', $text ) ? self::TRANSLATE : self::COPY;
	}

	private function looksTechnical( string $value, string $key ): bool {
		return filter_var( $value, FILTER_VALIDATE_URL ) !== false
			|| filter_var( $value, FILTER_VALIDATE_EMAIL ) !== false
			|| (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value )
			|| (bool) preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value )
			|| (bool) preg_match( '/^(?:\.?[a-z][a-z0-9_-]*\s*)+$/i', $value ) && str_contains( $key, 'class' )
			|| (bool) preg_match( '~^(?:[a-zA-Z]:[\\\\/]|/|\.\.?/)[^\r\n]+$~', $value )
			|| (bool) preg_match( '/^-?\d+(?:\.\d+)?$/', $value )
			|| (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value )
			|| (bool) preg_match( '/^-?\d{1,3}\.\d+\s*,\s*-?\d{1,3}\.\d+$/', $value );
	}
}
