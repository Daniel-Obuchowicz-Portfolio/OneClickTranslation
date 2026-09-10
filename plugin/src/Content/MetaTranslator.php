<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

use OneClickTranslation\Support\Arr;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\BatchBuilder;
use OneClickTranslation\Translation\FieldPolicy;

final class MetaTranslator {

	public function __construct( private readonly FieldPolicy $policy ) {}

	/** @return array<string,array{values:list<mixed>,formats:list<string>}> */
	public function extract( int $postId, BatchBuilder $batch, string $context, bool $creating = true ): array {
		$settings = Helpers::settings();
		if ( ! $settings['translate_custom_fields'] ) {
			return array();
		}
		$all     = get_post_meta( $postId );
		$acfKeys = $this->acfMetaKeys( $postId );
		$result  = array();
		foreach ( $all as $key => $rawValues ) {
			if ( ( ! $settings['show_internal_fields'] && Helpers::isInternalMeta( (string) $key ) ) || isset( $acfKeys[ $key ] ) || str_starts_with( (string) $key, '_oct_' ) ) {
				continue;
			}
			if ( ! $settings['copy_attachments'] && preg_match( '/(^|_)(attachment|attachments|image|images|gallery|media|file|files)(_|$)/i', (string) $key ) ) {
				continue;
			}
			$configuredStrategy = $this->policy->configuredStrategy( (string) $key, $creating );
			if ( FieldPolicy::IGNORE === $configuredStrategy ) {
				continue;
			}
			$values  = array();
			$formats = array();
			foreach ( (array) $rawValues as $valueIndex => $rawValue ) {
				[$decoded, $format] = $this->decode( (string) $rawValue );
				$values[]           = $decoded;
				$formats[]          = $format;
				$leaves             = is_array( $decoded ) || is_object( $decoded ) ? Arr::flatten( $decoded, '' ) : array( '' => $decoded );
				foreach ( $leaves as $leafPath => $leafValue ) {
					$fieldPath = 'meta.' . Arr::encodeSegment( (string) $key ) . '.' . $valueIndex . ( $leafPath !== '' ? '.' . $leafPath : '' );
					$leafName  = $leafPath !== '' ? (string) substr( strrchr( '.' . $leafPath, '.' ), 1 ) : (string) $key;
					$policyKey = FieldPolicy::TRANSLATE === $configuredStrategy ? (string) $key : ( $leafName ? $leafName : (string) $key );
					$strategy  = FieldPolicy::COPY === $configuredStrategy ? FieldPolicy::COPY : $this->policy->decide( $policyKey, $leafValue, null, $creating );
					if ( $strategy === FieldPolicy::TRANSLATE && is_string( $leafValue ) ) {
						$batch->add( $fieldPath, $leafValue, str_contains( $leafValue, '<' ), $context . ' custom field ' . $key );
					}
				}
			}
			$topStrategy = $configuredStrategy ?? $this->policy->decide( (string) $key, count( $values ) === 1 ? $values[0] : $values, null, $creating );
			if ( $topStrategy !== FieldPolicy::IGNORE ) {
				$result[ (string) $key ] = array(
					'values'  => $values,
					'formats' => $formats,
				);
			}
		}
		return $result;
	}

	/** @param array<string,array{values:list<mixed>,formats:list<string>}> $template @param array<string,string> $translations */
	public function save( int $targetPostId, array $template, array $translations ): void {
		$root = array( 'meta' => array() );
		foreach ( $template as $key => $definition ) {
			$root['meta'][ $key ] = $definition['values'];
		}
		foreach ( $translations as $path => $value ) {
			if ( str_starts_with( $path, 'meta.' ) ) {
				Arr::set( $root, $path, $value );
			}
		}
		foreach ( $template as $key => $definition ) {
			delete_post_meta( $targetPostId, $key );
			$values = $root['meta'][ $key ] ?? array();
			foreach ( (array) $values as $index => $value ) {
				add_post_meta( $targetPostId, $key, $this->encode( $value, $definition['formats'][ $index ] ?? 'scalar' ) );
			}
		}
	}

	/** @return array{0:mixed,1:string} */
	public function decode( string $value ): array {
		if ( is_serialized( $value ) ) {
			return array( maybe_unserialize( $value ), 'serialized' );
		}
		$decoded = json_decode( $value, true );
		if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
			return array( $decoded, 'json' );
		}
		return array( $value, 'scalar' );
	}

	private function encode( mixed $value, string $format ): mixed {
		return match ( $format ) {
			'serialized' => $value,
			'json' => wp_slash( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			default => is_string( $value ) ? wp_slash( $value ) : $value,
		};
	}

	/** @return array<string,true> */
	private function acfMetaKeys( int $postId ): array {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return array();
		}
		$keys = array();
		foreach ( (array) get_field_objects( $postId, false ) as $field ) {
			if ( is_array( $field ) && ! empty( $field['name'] ) ) {
				$keys[ (string) $field['name'] ]       = true;
				$keys[ '_' . (string) $field['name'] ] = true;
			}
		}
		foreach ( get_post_meta( $postId ) as $metaKey => $values ) {
			if ( ! str_starts_with( (string) $metaKey, '_' ) ) {
				continue;
			}
			foreach ( (array) $values as $reference ) {
				if ( is_string( $reference ) && str_starts_with( $reference, 'field_' ) ) {
					$keys[ (string) $metaKey ]              = true;
					$keys[ substr( (string) $metaKey, 1 ) ] = true;
					break;
				}
			}
		}
		return $keys;
	}
}
