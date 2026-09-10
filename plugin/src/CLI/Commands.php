<?php

declare(strict_types=1);

namespace OneClickTranslation\CLI;

use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Queue\Queue;
use OneClickTranslation\Queue\QueueRepository;
use OneClickTranslation\Queue\QueueWorker;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationCache;
use OneClickTranslation\Translation\TranslationService;
use OneClickTranslation\Translation\TranslationStatus;

final class Commands {

	public function __construct( private readonly TranslationService $service, private readonly Queue $queue, private readonly QueueWorker $worker ) {}
	/** Show provider, language, queue, and translation status. */
	public function status(): void {
		try {
			$provider = ( new ProviderResolver() )->resolve();
			$data     = array(
				'provider'         => $provider->getProviderName(),
				'default_language' => $provider->getDefaultLanguage(),
				'languages'        => implode( ',', array_keys( $provider->getLanguages() ) ),
			);
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}$data += array_map( fn( $v )=> (string) $v, ( new QueueRepository() )->counts() );
		$data  += array_map( fn( $v )=> (string) $v, ( new TranslationStatus() )->counts() );
		\WP_CLI\Utils\format_items( 'table', array( $data ), array_keys( $data ) );
	}
	/** Translate one post. ## OPTIONS <id> --to=<languages> */
	public function translate( array $args, array $assoc ): void {
		$id        = absint( $args[0] ?? 0 );
		$languages = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $assoc['to'] ?? '' ) ) ) );
		if ( ! $id || ! $languages ) {
			\WP_CLI::error( 'Usage: wp oct translate <id> --to=en,de' );
			return;
		}foreach ( $languages as $language ) {
			try {
				$r = $this->service->translatePost( $id, $language );
					\WP_CLI::success( "{$language}: translated post #{$r->translatedPostId}" );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( "{$language}: {$e->getMessage()}" );}
		}
	}
	/** Queue missing translations. ## OPTIONS --to=<language> */
	public function translate_missing( array $args, array $assoc ): void {
		unset( $args );
		$requested = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $assoc['to'] ?? '' ) ) ) ) );
		if ( ! $requested ) {
			\WP_CLI::error( 'Usage: wp oct translate-missing --to=en[,de]' );
			return;
		}
		try {
			$provider = ( new ProviderResolver() )->resolve();
		} catch ( \Throwable $exception ) {
			\WP_CLI::error( $exception->getMessage() );
			return;
		}
		$languages = $provider->getLanguages();
		foreach ( $requested as $language ) {
			if ( ! isset( $languages[ $language ] ) ) {
				\WP_CLI::error( "Language '{$language}' is not configured in {$provider->getProviderName()}." );
				return;
			}
		}
		$postTypes = array_values( array_filter( Helpers::publicPostTypes(), static fn ( string $type ): bool => (bool) Helpers::postTypeConfig( $type )['enabled'] ) );
		$postIds   = get_posts(
			array(
				'post_type'              => $postTypes,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$count     = 0;
		foreach ( $postIds as $postId ) {
			$postId = (int) $postId;
			if ( $provider->getPostLanguage( $postId ) !== $provider->getDefaultLanguage() ) {
				continue;
			}
			foreach ( $requested as $language ) {
				if ( $language !== $provider->getDefaultLanguage() && ! $provider->getPostTranslation( $postId, $language ) ) {
					$this->queue->add(
						$postId,
						$language,
						array(
							'requested_by' => 'wp-cli',
							'mode'         => 'missing',
						)
					);
					++$count;
				}
			}
		}
		\WP_CLI::success( "Queued {$count} missing translation(s)." );
	}
	/** Queue all outdated translations. */
	public function update_outdated(): void {
		$this->queueByStatus( 'outdated', '' );}
	/** Manage the translation queue. ## OPTIONS <run|status> */
	public function queue( array $args ): void {
		$sub = $args[0] ?? 'status';
		if ( $sub === 'run' ) {
			$r = $this->worker->run();
			\WP_CLI::success( wp_json_encode( $r ) );
			return;
		}\WP_CLI\Utils\format_items( 'table', array( ( new QueueRepository() )->counts() ), array( 'pending', 'processing', 'completed', 'failed', 'cancelled' ) );
	}
	/** Manage cache. ## OPTIONS <clear> */
	public function cache( array $args ): void {
		if ( ( $args[0] ?? '' ) !== 'clear' ) {
			\WP_CLI::error( 'Usage: wp oct cache clear' );
			return;
		}( new TranslationCache() )->clear();
		\WP_CLI::success( 'Translation cache cleared.' );}
	/** Manage logs. ## OPTIONS <clear> */
	public function logs( array $args ): void {
		if ( ( $args[0] ?? '' ) !== 'clear' ) {
			\WP_CLI::error( 'Usage: wp oct logs clear' );
			return;
		}global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'oct_logs`' );
		\WP_CLI::success( 'Logs cleared.' );}
	private function queueByStatus( string $status, string $language ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'oct_status';
		$where = $language ? $wpdb->prepare( 'status=%s AND target_language=%s', $status, sanitize_key( $language ) ) : $wpdb->prepare( 'status=%s', $status );
		$rows  = $wpdb->get_results( "SELECT source_object_id,target_language FROM {$table} WHERE {$where}", ARRAY_A );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$this->queue->add( (int) $row['source_object_id'], (string) $row['target_language'] );
			++$count;
		}\WP_CLI::success( "Queued {$count} translation(s)." );
	}
}
