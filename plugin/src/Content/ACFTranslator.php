<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Support\Arr;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\BatchBuilder;
use OneClickTranslation\Translation\FieldPolicy;

final class ACFTranslator {

	public function __construct( private readonly FieldPolicy $policy, private readonly ?LanguageProviderInterface $provider = null ) {}

	/** @return array<string,array{key:string,name:string,value:mixed,definition:array<string,mixed>}> */
	public function extract( int $postId, BatchBuilder $batch, string $context, bool $creating = true ): array {
		if ( ! Helpers::settings()['translate_acf'] || ! function_exists( 'get_field_objects' ) ) {
			return array();
		}
		$result = array();
		foreach ( (array) get_field_objects( $postId, true, true ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['name'] ) ) {
				continue;
			}
			$name = (string) $field['name'];
			$type = (string) ( $field['type'] ?? '' );
			if ( ! Helpers::settings()['copy_attachments'] && in_array( $type, array( 'image', 'file', 'gallery' ), true ) ) {
				continue;
			}
			$configured = $this->policy->configuredStrategy( $name, $creating, $field );
			if ( FieldPolicy::IGNORE === $configured ) {
				continue;
			}
			$value           = $this->loadValue( $field, $postId );
			$result[ $name ] = array(
				'key'        => (string) $field['key'],
				'name'       => $name,
				'value'      => $value,
				'definition' => $field,
			);
			if ( FieldPolicy::COPY !== $configured ) {
				$this->walk( $value, $field, 'acf.' . Arr::encodeSegment( $name ) . '.value', $batch, $context, $creating );
			}
		}
		return $result;
	}

	private function walk( mixed $value, array $field, string $path, BatchBuilder $batch, string $context, bool $creating ): void {
		$type = (string) ( $field['type'] ?? '' );
		if ( $type === 'link' && is_array( $value ) ) {
			if ( isset( $value['title'] ) && is_string( $value['title'] ) && trim( $value['title'] ) !== '' ) {
				$batch->add( $path . '.title', $value['title'], false, $context . ' ACF link title' );
			}
			return;
		}
		if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
			foreach ( (array) ( $field['sub_fields'] ?? array() ) as $subField ) {
				$name = (string) ( $subField['name'] ?? '' );
				if ( $name !== '' && array_key_exists( $name, $value ) ) {
					$this->walk( $value[ $name ], $subField, $path . '.' . Arr::encodeSegment( $name ), $batch, $context, $creating );
				}
			}
			return;
		}
		if ( $type === 'repeater' && is_array( $value ) ) {
			foreach ( $value as $rowIndex => $row ) {
				if ( ! is_array( $row ) ) {
					continue; }
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $subField ) {
					$name = (string) ( $subField['name'] ?? '' );
					if ( $name !== '' && array_key_exists( $name, $row ) ) {
						$this->walk( $row[ $name ], $subField, $path . '.' . $rowIndex . '.' . Arr::encodeSegment( $name ), $batch, $context, $creating );
					}
				}
			}
			return;
		}
		if ( $type === 'flexible_content' && is_array( $value ) ) {
			$layouts = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				if ( is_array( $layout ) && isset( $layout['name'] ) ) {
					$layouts[ (string) $layout['name'] ] = $layout; }
			}
			foreach ( $value as $rowIndex => $row ) {
				if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) || ! isset( $layouts[ $row['acf_fc_layout'] ] ) ) {
					continue; }
				foreach ( (array) ( $layouts[ $row['acf_fc_layout'] ]['sub_fields'] ?? array() ) as $subField ) {
					$name = (string) ( $subField['name'] ?? '' );
					if ( $name !== '' && array_key_exists( $name, $row ) ) {
						$this->walk( $row[ $name ], $subField, $path . '.' . $rowIndex . '.' . Arr::encodeSegment( $name ), $batch, $context, $creating );
					}
				}
			}
			return;
		}
		$name = (string) ( $field['name'] ?? 'acf_field' );
		if ( $this->policy->decide( $name, $value, $type, $creating, $field ) === FieldPolicy::TRANSLATE && is_string( $value ) && trim( $value ) !== '' ) {
			$batch->add( $path, $value, $type === 'wysiwyg' || str_contains( $value, '<' ), $context . ' ACF field ' . $name );
		}
	}

	/** @param array<string,array{key:string,name:string,value:mixed,definition:array<string,mixed>}> $template @param array<string,string> $translations */
	public function save( int $targetPostId, array $template, array $translations, string $targetLanguage = '' ): void {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}
		$root = array( 'acf' => array() );
		foreach ( $template as $name => $field ) {
			$root['acf'][ $name ] = array( 'value' => $field['value'] );
		}
		foreach ( $translations as $path => $value ) {
			if ( str_starts_with( $path, 'acf.' ) ) {
				Arr::set( $root, $path, $value );
			}
		}
		foreach ( $template as $name => $field ) {
			$value = $root['acf'][ $name ]['value'] ?? $field['value'];
			update_field( $field['key'], $this->mapTaxonomyRelations( $value, $field['definition'], $targetLanguage ), $targetPostId );
		}
	}

	private function mapTaxonomyRelations( mixed $value, array $field, string $targetLanguage ): mixed {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'taxonomy' === $type && $this->provider && '' !== $targetLanguage ) {
			$multiple = is_array( $value );
			$ids      = $multiple ? $value : array( $value );
			$mapped   = array();
			foreach ( $ids as $term ) {
				$termId       = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;
				$translations = $termId ? $this->provider->getTermTranslations( $termId ) : array();
				$mapped[]     = isset( $translations[ $targetLanguage ] ) ? (int) $translations[ $targetLanguage ] : $term;
			}
			return $multiple ? $mapped : ( $mapped[0] ?? $value );
		}
		if ( in_array( $type, array( 'group', 'clone', 'repeater' ), true ) && is_array( $value ) ) {
			$rows = 'repeater' === $type ? $value : array( $value );
			foreach ( $rows as $rowIndex => $row ) {
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $subField ) {
					$name = (string) ( $subField['name'] ?? '' );
					if ( is_array( $row ) && '' !== $name && array_key_exists( $name, $row ) ) {
						$mapped = $this->mapTaxonomyRelations( $row[ $name ], $subField, $targetLanguage );
						if ( 'repeater' === $type ) {
							$value[ $rowIndex ][ $name ] = $mapped;
						} else {
							$value[ $name ] = $mapped; }
					}
				}
			}
		}
		if ( 'flexible_content' === $type && is_array( $value ) ) {
			$layouts = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				if ( is_array( $layout ) && isset( $layout['name'] ) ) {
					$layouts[ (string) $layout['name'] ] = $layout; }
			}
			foreach ( $value as $rowIndex => $row ) {
				$layout = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : '';
				foreach ( (array) ( $layouts[ $layout ]['sub_fields'] ?? array() ) as $subField ) {
					$name = (string) ( $subField['name'] ?? '' );
					if ( is_array( $row ) && '' !== $name && array_key_exists( $name, $row ) ) {
						$value[ $rowIndex ][ $name ] = $this->mapTaxonomyRelations( $row[ $name ], $subField, $targetLanguage ); }
				}
			}
		}
		return $value;
	}

	private function loadValue( array $field, int $postId ): mixed {
		if ( ! function_exists( 'get_field' ) ) {
			return $field['value'] ?? null;
		}
		$type      = (string) ( $field['type'] ?? '' );
		$formatted = in_array( $type, array( 'group', 'repeater', 'flexible_content', 'clone', 'link' ), true );
		return get_field( (string) $field['name'], $postId, $formatted );
	}
}
