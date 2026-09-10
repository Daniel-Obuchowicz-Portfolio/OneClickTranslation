<?php

declare(strict_types=1);

namespace OneClickTranslation\Queue;

use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Support\Logger;
use OneClickTranslation\Translation\TranslationService;
use OneClickTranslation\Translation\TranslationStatus;
use Throwable;

final class QueueWorker {

	public function __construct(
		private readonly QueueRepository $repository,
		private readonly QueueLock $lock,
		private readonly TranslationService $service,
		private readonly TranslationStatus $status,
		private readonly Logger $logger
	) {}

	/** @return array{processed:int,completed:int,failed:int,locked:bool} */
	public function run( ?int $limit = null ): array {
		$limit ??= max( 1, min( 20, (int) Helpers::settings()['queue_batch_size'] ) );
		$summary = array(
			'processed' => 0,
			'completed' => 0,
			'failed'    => 0,
			'locked'    => false,
		);
		$lockTtl = max( 300, $limit * ( (int) Helpers::settings()['request_timeout'] * ( (int) Helpers::settings()['request_retries'] + 1 ) + 30 ) );
		if ( ! $this->lock->acquire( $lockTtl ) ) {
			$summary['locked'] = true;
			return $summary;
		}
		try {
			for ( $i = 0; $i < $limit; ++$i ) {
				$job = $this->repository->claimNext();
				if ( ! $job ) {
					break; }
				++$summary['processed'];
				try {
					$result = $this->service->translatePost( $job->sourceObjectId, $job->targetLanguage, $job->provider );
					$this->repository->complete( $job->id );
					$this->status->set( $job->sourceObjectId, $job->targetLanguage, TranslationStatus::TRANSLATED, $result->translatedPostId, $result->sourceHash );
					++$summary['completed'];
					do_action( 'oct_queue_job_completed', $job->id, $result );
				} catch ( Throwable $exception ) {
					$state = $this->repository->fail( $job, $exception->getMessage(), (int) Helpers::settings()['queue_max_attempts'] );
					if ( $state === 'failed' ) {
						++$summary['failed'];
						$this->status->set( $job->sourceObjectId, $job->targetLanguage, TranslationStatus::ERROR, null, null, $exception->getMessage() );
					} else {
						$this->status->set( $job->sourceObjectId, $job->targetLanguage, TranslationStatus::QUEUED, null, null, $exception->getMessage() );
					}
					$this->logger->warning(
						'Queue job attempt failed.',
						array(
							'job_id'          => $job->id,
							'object_id'       => $job->sourceObjectId,
							'target_language' => $job->targetLanguage,
							'attempts'        => $job->attempts,
							'queue_state'     => $state,
							'error'           => $exception->getMessage(),
						)
					);
				}
			}
		} finally {
			$this->lock->release();
		}
		return $summary;
	}
}
