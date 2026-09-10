<?php

declare(strict_types=1);

namespace OneClickTranslation\Queue;

use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Translation\TranslationStatus;

final class Queue {

	public function __construct(
		private readonly QueueRepository $repository,
		private readonly ProviderResolver $resolver,
		private readonly TranslationStatus $status
	) {}

	public function add( int $sourceId, string $targetLanguage, array $payload = array(), int $priority = 10 ): int {
		$provider = $this->resolver->resolve();
		$id       = $this->repository->enqueue( $sourceId, $targetLanguage, strtolower( $provider->getProviderName() ), $payload, $priority );
		$this->status->set( $sourceId, $targetLanguage, TranslationStatus::QUEUED, $provider->getPostTranslation( $sourceId, $targetLanguage ) );
		do_action( 'oct_queue_job_created', $id, $sourceId, $targetLanguage );
		return $id;
	}
}
