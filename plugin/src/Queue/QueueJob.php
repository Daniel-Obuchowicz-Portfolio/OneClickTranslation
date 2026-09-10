<?php

declare(strict_types=1);

namespace OneClickTranslation\Queue;

final class QueueJob {

	public function __construct(
		public readonly int $id,
		public readonly int $sourceObjectId,
		public readonly string $objectType,
		public readonly string $targetLanguage,
		public readonly string $provider,
		public readonly string $status,
		public readonly int $priority,
		public readonly int $attempts,
		public readonly array $payload,
		public readonly ?string $lastError = null
	) {}

	public static function fromRow( array $row ): self {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		return new self(
			(int) $row['id'],
			(int) $row['source_object_id'],
			(string) $row['object_type'],
			(string) $row['target_language'],
			(string) $row['provider'],
			(string) $row['status'],
			(int) $row['priority'],
			(int) $row['attempts'],
			is_array( $payload ) ? $payload : array(),
			isset( $row['last_error'] ) ? (string) $row['last_error'] : null
		);
	}
}
