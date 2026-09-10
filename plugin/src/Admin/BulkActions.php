<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Queue\Queue;
use OneClickTranslation\Queue\QueueRepository;
use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Translation\TranslationStatus;

final class BulkActions {

	public function __construct( private readonly Queue $queue ) {}

	/** @param list<int> $postIds */
	public function enqueue( array $postIds, string $language, string $mode ): int {
		$count     = 0;
		$status    = new TranslationStatus();
		$provider  = ( new ProviderResolver() )->resolve();
		$languages = $provider->getLanguages();
		foreach ( array_unique( array_map( 'absint', $postIds ) ) as $postId ) {
			if ( ! $postId || ! current_user_can( 'edit_post', $postId ) ) {
				continue;
			}
			$post = get_post( $postId );
			if ( ! $post instanceof \WP_Post || ! \OneClickTranslation\Support\Helpers::postTypeConfig( $post->post_type )['enabled'] || ! $provider->isPostTypeTranslatable( $post->post_type ) || ! isset( $languages[ $language ] ) || $provider->getPostLanguage( $postId ) === $language ) {
				continue;
			}
			$record = $status->get( $postId, $language );
			$state  = (string) ( $record['status'] ?? ( $provider->getPostTranslation( $postId, $language ) ? 'translated' : 'missing' ) );
			if ( $mode === 'outdated' && $state !== 'outdated' ) {
				continue;
			}if ( $mode === 'missing' && $state !== 'missing' ) {
				continue;
			}$this->queue->add(
				$postId,
				$language,
				array(
					'requested_by' => get_current_user_id(),
					'mode'         => $mode,
				)
			);
			++$count;
		}
		return $count;
	}

	public function registerListActions(): void {
		foreach ( \OneClickTranslation\Support\Helpers::publicPostTypes() as $type ) {
			if ( ! \OneClickTranslation\Support\Helpers::postTypeConfig( $type )['enabled'] ) {
				continue;
			}
			add_filter(
				"bulk_actions-edit-{$type}",
				function ( array $actions ): array {
					$actions['oct_queue_all'] = __( 'Queue translations', 'oneclicktranslation' );
					return $actions;
				}
			);
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handlePostList' ), 10, 3 );}
	}

	public function handlePostList( string $redirect, string $action, array $postIds ): string {
		if ( $action !== 'oct_queue_all' ) {
			return $redirect;
		}
		check_admin_referer( 'bulk-posts' );
		$settings = \OneClickTranslation\Support\Helpers::settings();
		$count    = 0;
		foreach ( (array) $settings['target_languages'] as $language ) {
			$count += $this->enqueue( $postIds, (string) $language, 'all' );
		}return add_query_arg( 'oct_queued', $count, $redirect );
	}
}
