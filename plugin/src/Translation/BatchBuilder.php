<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

final class BatchBuilder {

	/** @var array<string, array{text:string,html:bool,context:string,paths:list<string>}> */
	private array $items = array();
	/** @var array<string, array{text:string,html:bool,context:string,paths:list<string>}>|null */
	private ?array $filteredItems = null;

	public function add( string $path, string $text, bool $html = false, string $context = '' ): void {
		if ( trim( wp_strip_all_tags( $text ) ) === '' ) {
			return;
		}
		$key = hash( 'sha256', ( $html ? 'html:' : 'text:' ) . $text );
		if ( ! isset( $this->items[ $key ] ) ) {
			$this->items[ $key ] = array(
				'text'    => $text,
				'html'    => $html,
				'context' => $context,
				'paths'   => array(),
			);
		}
		if ( ! in_array( $path, $this->items[ $key ]['paths'], true ) ) {
			$this->items[ $key ]['paths'][] = $path;
		}
		$this->filteredItems = null;
	}

	/** @return array<string, array{text:string,html:bool,context:string,paths:list<string>}> */
	public function items(): array {
		if ( null === $this->filteredItems ) {
			$this->filteredItems = (array) apply_filters( 'oct_translation_batch', $this->items );
		}
		return $this->filteredItems;
	}

	/** @return list<array<string, array{text:string,html:bool,context:string,paths:list<string>}>> */
	public function chunks( int $maxBytes = 110000, int $maxTexts = 50 ): array {
		$chunks = array();
		foreach ( array( false, true ) as $html ) {
			$current = array();
			$bytes   = 0;
			foreach ( $this->items() as $key => $item ) {
				if ( $item['html'] !== $html ) {
					continue;
				}
				$size = strlen( $item['text'] ) + strlen( $item['context'] ) + 64;
				if ( $current && ( count( $current ) >= $maxTexts || $bytes + $size > $maxBytes ) ) {
					$chunks[] = $current;
					$current  = array();
					$bytes    = 0;
				}
				$current[ $key ] = $item;
				$bytes          += $size;
			}
			if ( $current ) {
				$chunks[] = $current;
			}
		}
		return $chunks;
	}

	public function count(): int {
		return count( $this->items() ); }
	public function characters(): int {
		return array_sum( array_map( static fn ( array $item ): int => mb_strlen( $item['text'] ), $this->items() ) ); }
}
