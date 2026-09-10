<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

final class BatchResultMapper {

	/**
	 * @param array<string, array{text:string,html:bool,context:string,paths:list<string>}> $items
	 * @param array<string, string>                                                         $translations Hash-keyed translations.
	 * @return array<string, string> Field path keyed translations.
	 */
	public function map( array $items, array $translations ): array {
		$mapped = array();
		foreach ( $items as $key => $item ) {
			if ( ! array_key_exists( $key, $translations ) ) {
				continue;
			}
			foreach ( $item['paths'] as $path ) {
				$mapped[ $path ] = $translations[ $key ];
			}
		}
		return $mapped;
	}
}
