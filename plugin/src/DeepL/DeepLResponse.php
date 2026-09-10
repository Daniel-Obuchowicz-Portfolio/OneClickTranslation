<?php

declare(strict_types=1);

namespace OneClickTranslation\DeepL;

final class DeepLResponse {

	/** @param list<string> $translations */
	public function __construct(
		public readonly array $translations,
		public readonly ?string $detectedSourceLanguage = null,
		public readonly array $raw = array()
	) {}

	public function first(): string {
		return $this->translations[0] ?? ''; }
}
