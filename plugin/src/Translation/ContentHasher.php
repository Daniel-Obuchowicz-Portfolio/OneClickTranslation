<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

use OneClickTranslation\Support\Arr;

final class ContentHasher {

	public function hash( array $content ): string {
		return hash( 'sha256', (string) wp_json_encode( Arr::canonicalize( $content ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	public function hasChanged( string $storedHash, array $content ): bool {
		return ! hash_equals( $storedHash, $this->hash( $content ) );
	}
}
