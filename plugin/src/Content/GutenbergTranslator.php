<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

use OneClickTranslation\Support\Arr;
use OneClickTranslation\Translation\BatchBuilder;

final class GutenbergTranslator {

	/** @return array{blocks:array<int,mixed>,paths:list<string>} */
	public function extract( string $content, BatchBuilder $batch, string $context = '' ): array {
		$blocks = parse_blocks( $content );
		$paths  = array();
		$this->walk( $blocks, 'content.blocks', $batch, $paths, $context );
		return array(
			'blocks' => $blocks,
			'paths'  => $paths,
		);
	}

	/** @param array<int,mixed> $blocks @param list<string> $paths */
	private function walk( array $blocks, string $prefix, BatchBuilder $batch, array &$paths, string $context ): void {
		$textAttributes = array( 'content', 'text', 'title', 'caption', 'description', 'label', 'placeholder', 'value' );
		foreach ( $blocks as $index => &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $partIndex => $fragment ) {
				if ( ! is_string( $fragment ) || trim( wp_strip_all_tags( $fragment ) ) === '' ) {
					continue;
				}
				$path = "{$prefix}.{$index}.innerContent.{$partIndex}";
				$batch->add( $path, $fragment, true, $context . ' Gutenberg block ' . ( $block['blockName'] ?? 'freeform' ) );
				$paths[] = $path;
			}
			foreach ( (array) ( $block['attrs'] ?? array() ) as $attribute => $value ) {
				if ( ! in_array( (string) $attribute, $textAttributes, true ) || ! is_string( $value ) || trim( $value ) === '' ) {
					continue;
				}
				$path = "{$prefix}.{$index}.attrs." . Arr::encodeSegment( (string) $attribute );
				$batch->add( $path, $value, str_contains( $value, '<' ), $context . ' block attribute ' . $attribute );
				$paths[] = $path;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk( $block['innerBlocks'], "{$prefix}.{$index}.innerBlocks", $batch, $paths, $context );
			}
		}
		unset( $block );
	}

	/** @param array<int,mixed> $blocks @param array<string,string> $translations */
	public function apply( array $blocks, array $translations ): string {
		$root = array( 'content' => array( 'blocks' => $blocks ) );
		foreach ( $translations as $path => $translation ) {
			if ( str_starts_with( $path, 'content.blocks.' ) ) {
				Arr::set( $root, $path, $translation );
			}
		}
		return serialize_blocks( $root['content']['blocks'] );
	}
}
