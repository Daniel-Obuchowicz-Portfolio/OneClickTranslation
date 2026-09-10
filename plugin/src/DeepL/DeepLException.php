<?php

declare(strict_types=1);

namespace OneClickTranslation\DeepL;

use RuntimeException;

final class DeepLException extends RuntimeException {

	public function __construct(
		string $message,
		int $code = 0,
		private readonly bool $retryable = false,
		private readonly ?int $retryAfter = null
	) {
		parent::__construct( $message, $code );
	}

	public function isRetryable(): bool {
		return $this->retryable; }
	public function getRetryAfter(): ?int {
		return $this->retryAfter; }
}
