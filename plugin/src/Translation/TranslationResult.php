<?php

declare(strict_types=1);

namespace OneClickTranslation\Translation;

final class TranslationResult {

	public function __construct(
		public readonly int $sourcePostId,
		public readonly int $translatedPostId,
		public readonly string $targetLanguage,
		public readonly string $status,
		public readonly string $sourceHash,
		public readonly int $characters,
		public readonly int $cacheHits,
		public readonly bool $created
	) {}
}
