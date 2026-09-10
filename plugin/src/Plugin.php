<?php

declare(strict_types=1);

namespace OneClickTranslation;

use OneClickTranslation\Admin\AdminMenu;
use OneClickTranslation\Admin\AdminNotices;
use OneClickTranslation\Admin\BulkActions;
use OneClickTranslation\Admin\PostListColumns;
use OneClickTranslation\Admin\TranslationMetaBox;
use OneClickTranslation\CLI\Commands;
use OneClickTranslation\Content\ACFTranslator;
use OneClickTranslation\Content\GutenbergTranslator;
use OneClickTranslation\Content\MetaTranslator;
use OneClickTranslation\Database\Migrator;
use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Queue\Queue;
use OneClickTranslation\Queue\QueueLock;
use OneClickTranslation\Queue\QueueRepository;
use OneClickTranslation\Queue\QueueWorker;
use OneClickTranslation\REST\Controller;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Support\Logger;
use OneClickTranslation\Translation\BatchBuilder;
use OneClickTranslation\Translation\ContentExtractor;
use OneClickTranslation\Translation\ContentHasher;
use OneClickTranslation\Translation\FieldPolicy;
use OneClickTranslation\Translation\TranslationCache;
use OneClickTranslation\Translation\TranslationService;
use OneClickTranslation\Translation\TranslationStatus;
use Throwable;

final class Plugin {

	private static ?self $instance = null;
	private bool $booted           = false;
	public static function instance(): self {
		return self::$instance ??= new self();}
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}$this->booted = true;
		load_plugin_textdomain( 'oneclicktranslation', false, dirname( plugin_basename( OCT_FILE ) ) . '/languages' );
		( new Migrator() )->maybeMigrate();
		$resolver = new ProviderResolver();
		$cache    = new TranslationCache();
		$status   = new TranslationStatus();
		$logger   = new Logger();
		$service  = new TranslationService( $resolver, $cache, $status, $logger );
		$repo     = new QueueRepository();
		$queue    = new Queue( $repo, $resolver, $status );
		$worker   = new QueueWorker( $repo, new QueueLock(), $service, $status, $logger );
		$bulk     = new BulkActions( $queue );
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['oct_minutely'] = array(
					'interval' => 60,
					'display'  => 'Every minute',
				);
				return $schedules;
			}
		);
		add_action( 'oct_process_queue', static fn()=>$worker->run() );
		add_action( 'rest_api_init', array( new Controller( $service, $queue ), 'register' ) );
		add_action( 'save_post', fn( int $postId, \WP_Post $post, bool $update )=>$this->onSave( $postId, $post, $update, $resolver, $queue, $status ), 20, 3 );
		add_action( 'oct_recheck_source', fn( int $postId )=>$this->recheckSource( $postId, $resolver, $queue, $status ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $metaHook ) {
			add_action( $metaHook, fn( mixed $metaId, int $postId, string $metaKey )=>$this->onMetaChange( $metaId, $postId, $metaKey ), 10, 3 );
		}
		add_action( 'set_object_terms', fn( int $postId )=>$this->scheduleRecheck( $postId ), 10, 1 );
		add_action( 'edited_term', fn( int $termId, int $termTaxonomyId, string $taxonomy )=>$this->onTermChange( $termId, $termTaxonomyId, $taxonomy ), 10, 3 );
		add_action( 'deleted_term_relationships', fn( int $postId )=>$this->scheduleRecheck( $postId ), 10, 1 );
		if ( is_admin() ) {
			$menu = new AdminMenu( $service, $worker, $bulk );
			add_action( 'admin_menu', array( $menu, 'register' ) );
			$menu->registerActions();
			add_action( 'admin_notices', array( new AdminNotices(), 'render' ) );
			add_action( 'add_meta_boxes', array( new TranslationMetaBox(), 'register' ) );
			( new PostListColumns() )->register();
			$bulk->registerListActions();
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'oct', new Commands( $service, $queue, $worker ) );}
		if ( Helpers::debug() ) {
			add_action( 'init', array( $this, 'registerDevelopmentPostType' ) );}
	}
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'oct' ) && ! str_contains( $hook, 'oneclicktranslation' ) && ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}wp_enqueue_style( 'oct-admin', OCT_URL . 'assets/admin.css', array(), OCT_VERSION );
		wp_enqueue_script( 'oct-admin', OCT_URL . 'assets/admin.js', array(), OCT_VERSION, true );
		$usage = get_transient( 'oct_deepl_usage' );
		wp_localize_script(
			'oct-admin',
			'octAdmin',
			array(
				'availableQuota' => is_array( $usage ) ? max( 0, (int) ( $usage['character_limit'] ?? 0 ) - (int) ( $usage['character_count'] ?? 0 ) ) : null,
			)
		);
	}
	private function onSave( int $postId, \WP_Post $post, bool $update, ProviderResolver $resolver, Queue $queue, TranslationStatus $status ): void {
		unset( $update );
		if ( ! empty( $GLOBALS['oct_translating'] ) || wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) || $post->post_type === 'attachment' || get_post_meta( $postId, '_oct_source_post_id', true ) ) {
			return;}
		try {
			$provider = $resolver->resolve();
			if ( ! Helpers::postTypeConfig( $post->post_type )['enabled'] || ! $provider->isPostTypeTranslatable( $post->post_type ) || $provider->getPostLanguage( $postId ) !== $provider->getDefaultLanguage() ) {
				return;
			}$settings = Helpers::settings();
			$allowed   = $post->post_status === 'publish' || ( ! $settings['only_published'] && ( ( $post->post_status === 'draft' && $settings['translate_drafts'] ) || ( $post->post_status === 'future' && $settings['translate_scheduled'] ) ) );
			if ( ! $allowed ) {
				return;
			}$policy   = new FieldPolicy( $provider );
			$batch     = new BatchBuilder();
			$extractor = new ContentExtractor( new MetaTranslator( $policy ), new ACFTranslator( $policy, $provider ), new GutenbergTranslator() );
			$extracted = $extractor->extract( $postId, $batch, false );
			foreach ( array_keys( (array) $extracted['taxonomy_hash'] ) as $taxonomy ) {
				if ( ! $provider->isTaxonomyTranslatable( (string) $taxonomy ) ) {
					unset( $extracted['taxonomy_hash'][ $taxonomy ] );
				}
			}
			$hash = ( new ContentHasher() )->hash( $extractor->hashPayload( $extracted ) );
			global $wpdb;
			$table    = $wpdb->prefix . 'oct_status';
			$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT target_language,source_hash,status FROM {$table} WHERE source_object_id=%d AND object_type='post'", $postId ), ARRAY_A );
			$existing = array();
			foreach ( (array) $rows as $row ) {
				$existing[ (string) $row['target_language'] ] = $row;
				if ( ! empty( $row['source_hash'] ) && ! hash_equals( (string) $row['source_hash'], $hash ) ) {
					$status->set( $postId, (string) $row['target_language'], 'outdated', $provider->getPostTranslation( $postId, (string) $row['target_language'] ), $hash );
					if ( $settings['auto_update'] ) {
						$queue->add( $postId, (string) $row['target_language'], array( 'reason' => 'source_updated' ) );
					}
				}
			}if ( $settings['auto_new'] || Helpers::postTypeConfig( $post->post_type )['auto_translate'] ) {
				foreach ( (array) $settings['target_languages'] as $language ) {
					if ( $language !== $provider->getDefaultLanguage() && ! isset( $existing[ $language ] ) ) {
						$queue->add( $postId, (string) $language, array( 'reason' => 'new_content' ) );}
				}
			}
		} catch ( Throwable $e ) {
			( new Logger() )->debug(
				'Automatic translation scheduling skipped.',
				array(
					'object_id' => $postId,
					'error'     => $e->getMessage(),
				)
			);}
	}
	private function onMetaChange( mixed $metaId, int $postId, string $metaKey ): void {
		unset( $metaId );
		if ( str_starts_with( $metaKey, '_oct_' ) ) {
			return;
		}
		$this->scheduleRecheck( $postId );
	}
	private function scheduleRecheck( int $postId ): void {
		if ( ! empty( $GLOBALS['oct_translating'] ) || wp_is_post_revision( $postId ) || ! get_post( $postId ) ) {
			return;
		}
		$args = array( $postId );
		if ( ! wp_next_scheduled( 'oct_recheck_source', $args ) ) {
			wp_schedule_single_event( time() + 5, 'oct_recheck_source', $args );
		}
	}
	private function onTermChange( int $termId, int $termTaxonomyId, string $taxonomy ): void {
		unset( $termTaxonomyId );
		if ( ! empty( $GLOBALS['oct_translating'] ) ) {
			return;
		}
		$postIds = get_objects_in_term( $termId, $taxonomy );
		if ( is_wp_error( $postIds ) ) {
			return;
		}
		foreach ( $postIds as $postId ) {
			$this->scheduleRecheck( (int) $postId );
		}
	}
	private function recheckSource( int $postId, ProviderResolver $resolver, Queue $queue, TranslationStatus $status ): void {
		$post = get_post( $postId );
		if ( $post instanceof \WP_Post ) {
			$this->onSave( $postId, $post, true, $resolver, $queue, $status );
		}
	}
	public function registerDevelopmentPostType(): void {
		register_post_type(
			'portfolio',
			array(
				'label'        => 'Portfolio',
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'portfolio' ),
			)
		);
	}
}
