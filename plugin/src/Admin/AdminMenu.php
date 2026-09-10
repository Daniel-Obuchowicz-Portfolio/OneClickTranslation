<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\DeepL\DeepLClient;
use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Queue\QueueRepository;
use OneClickTranslation\Queue\QueueWorker;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationCache;
use OneClickTranslation\Translation\TranslationService;
use Throwable;

final class AdminMenu {

	public function __construct( private readonly TranslationService $service, private readonly QueueWorker $worker, private readonly BulkActions $bulk ) {}
	public function register(): void {
		add_menu_page( 'OneClickTranslation', 'OneClickTranslation', 'manage_options', 'oneclicktranslation', array( new DashboardPage(), 'render' ), 'dashicons-translation', 58 );
		add_submenu_page( 'oneclicktranslation', 'Dashboard', 'Dashboard', 'manage_options', 'oneclicktranslation' );
		add_submenu_page( 'oneclicktranslation', 'Translations', 'Translations', 'edit_posts', 'oct-translations', array( new TranslationsPage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Content Types', 'Content Types', 'manage_options', 'oct-content-types', array( new ContentTypesPage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Fields', 'Fields', 'manage_options', 'oct-fields', array( new FieldsPage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Languages', 'Languages', 'manage_options', 'oct-languages', array( new LanguagesPage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Translation Queue', 'Translation Queue', 'manage_options', 'oct-queue', array( new QueuePage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Cache', 'Cache', 'manage_options', 'oct-cache', array( new CachePage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Logs', 'Logs', 'manage_options', 'oct-logs', array( new LogsPage(), 'render' ) );
		add_submenu_page( 'oneclicktranslation', 'Settings', 'Settings', 'manage_options', 'oct-settings', array( new SettingsPage(), 'render' ) );
	}
	public function registerActions(): void {
		foreach ( array( 'oct_translate', 'oct_translate_all', 'oct_test_deepl', 'oct_run_queue', 'oct_clear_completed', 'oct_clear_cache', 'oct_clear_logs', 'oct_cancel_job', 'oct_save_settings', 'oct_save_content_types', 'oct_save_fields', 'oct_bulk_translate', 'oct_bulk_retranslate', 'oct_bulk_outdated', 'oct_bulk_queue' ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, $action ) );}
	}
	public function oct_translate(): void {
		$postId   = absint( $_GET['post_id'] ?? 0 );
		$language = sanitize_key( wp_unslash( $_GET['language'] ?? '' ) );
		check_admin_referer( 'oct_translate_' . $postId . '_' . $language );
		if ( ! $postId || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( 'Permission denied.', 403 );
		}try {
			$result = $this->service->translatePost( $postId, $language );
			AdminNotices::add( 'Translation completed: #' . $result->translatedPostId );
		} catch ( Throwable $e ) {
			AdminNotices::add( $e->getMessage(), 'error' );
		}
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = get_edit_post_link( $postId, '' );
		}
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}
	public function oct_translate_all(): void {
		$postId = absint( $_GET['post_id'] ?? 0 );
		$mode   = sanitize_key( wp_unslash( $_GET['mode'] ?? 'all' ) );
		check_admin_referer( 'oct_translate_all_' . $postId );
		if ( ! $postId || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( 'Permission denied.', 403 );
		}try {
			$provider = ( new ProviderResolver() )->resolve();
			$source   = $provider->getPostLanguage( $postId );
			$count    = 0;
			foreach ( array_keys( $provider->getLanguages() ) as $language ) {
				if ( $language === $source ) {
						continue;
				}$count += $this->bulk->enqueue( array( $postId ), $language, $mode );
			}AdminNotices::add( $count . ' translation job(s) queued.' );
		} catch ( Throwable $e ) {
			AdminNotices::add( $e->getMessage(), 'error' );
		}
		$redirect = get_edit_post_link( $postId, '' );
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}
	public function oct_test_deepl(): void {
		check_admin_referer( 'oct_test_deepl' );
		$this->requireManage();
		$s      = Helpers::settings();
		$client = new DeepLClient( Helpers::apiKey(), (string) $s['api_type'], (int) $s['request_timeout'], (int) $s['request_retries'] );
		$result = $client->testConnection();
		set_transient( 'oct_deepl_connection', $result, HOUR_IN_SECONDS );
		if ( ! empty( $result['usage'] ) ) {
			$result['usage']['last_updated'] = current_time( 'mysql' );
			set_transient( 'oct_deepl_usage', $result['usage'], HOUR_IN_SECONDS );
		}AdminNotices::add( (string) $result['message'], $result['success'] ? 'success' : 'error' );
		$this->back( 'oct-settings&tab=deepl' );
	}
	public function oct_run_queue(): void {
		check_admin_referer( 'oct_run_queue' );
		$this->requireManage();
		$r = $this->worker->run();
		AdminNotices::add( $r['locked'] ? 'Queue worker is already running.' : sprintf( 'Queue processed: %d; completed: %d; failed: %d.', $r['processed'], $r['completed'], $r['failed'] ), $r['failed'] ? 'warning' : 'success' );
		$this->back( 'oct-queue' );}
	public function oct_clear_completed(): void {
		check_admin_referer( 'oct_clear_completed' );
		$this->requireManage();
		$n = ( new QueueRepository() )->clearCompleted();
		AdminNotices::add( $n . ' completed job(s) cleared.' );
		$this->back( 'oct-queue' );}
	public function oct_clear_cache(): void {
		check_admin_referer( 'oct_clear_cache' );
		$this->requireManage();
		( new TranslationCache() )->clear();
		AdminNotices::add( 'Translation cache cleared.' );
		$this->back( 'oct-cache' );}
	public function oct_clear_logs(): void {
		check_admin_referer( 'oct_clear_logs' );
		$this->requireManage();
		global $wpdb;
		$days  = sanitize_key( wp_unslash( $_POST['days'] ?? 'all' ) );
		$table = $wpdb->prefix . 'oct_logs';
		if ( $days === 'all' ) {
			$n = $wpdb->query( "TRUNCATE TABLE {$table}" );
		} else {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, absint( $days ) ) );
			$n      = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
		}AdminNotices::add( (int) $n . ' log record(s) cleared.' );
		$this->back( 'oct-logs' );
	}
	public function oct_cancel_job(): void {
		check_admin_referer( 'oct_cancel_job' );
		$this->requireManage();
		( new QueueRepository() )->cancel( absint( $_GET['job_id'] ?? 0 ) );
		AdminNotices::add( 'Queue job cancelled.' );
		$this->back( 'oct-queue' );}
	public function oct_save_settings(): void {
		check_admin_referer( 'oct_save_settings' );
		$this->requireManage();
		$input    = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();
		$current  = Helpers::settings();
		$tab      = sanitize_key( wp_unslash( $_POST['tab'] ?? 'general' ) );
		$bools    = array( 'preserve_formatting', 'translate_title', 'translate_content', 'translate_excerpt', 'translate_slug', 'translate_taxonomies', 'translate_custom_fields', 'translate_acf', 'translate_gutenberg', 'copy_featured_image', 'copy_attachments', 'preserve_html', 'preserve_shortcodes', 'preserve_urls', 'preserve_emails', 'preserve_code_blocks', 'preserve_placeholders', 'auto_new', 'auto_update', 'only_published', 'translate_drafts', 'translate_scheduled', 'show_internal_fields' );
		$tabBools = array(
			'deepl'       => array( 'preserve_formatting' ),
			'translation' => array_slice( $bools, 1, 16 ),
			'automation'  => array( 'auto_new', 'auto_update', 'only_published', 'translate_drafts', 'translate_scheduled' ),
			'advanced'    => array( 'show_internal_fields' ),
		);
		foreach ( $tabBools[ $tab ] ?? array() as $key ) {
			$current[ $key ] = ! empty( $input[ $key ] );
		}foreach ( array( 'provider', 'api_type', 'formality', 'split_sentences' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current[ $key ] = sanitize_key( (string) $input[ $key ] );
			}
		}$allowed = array(
			'provider'        => array( 'auto', 'wpml', 'polylang' ),
			'api_type'        => array( 'free', 'pro' ),
			'formality'       => array( 'default', 'more', 'less', 'prefer_more', 'prefer_less' ),
			'split_sentences' => array( '0', '1', 'nonewlines' ),
		);
		foreach ( $allowed as $key => $values ) {
			if ( ! in_array( (string) $current[ $key ], $values, true ) ) {
				$current[ $key ] = Helpers::settings()[ $key ];
			}
		}if ( ! empty( $input['api_key'] ) && ! defined( 'OCT_DEEPL_API_KEY' ) ) {
			$current['api_key'] = sanitize_text_field( (string) $input['api_key'] );
		}if ( isset( $input['target_languages'] ) ) {
			try {
				$valid = array_keys( ( new ProviderResolver() )->resolve()->getLanguages() );
			} catch ( Throwable ) {
				$valid = array();
			}$current['target_languages'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['target_languages'] ), $valid ) );
		} elseif ( $tab === 'automation' ) {
			$current['target_languages'] = array();
		}foreach ( array(
			'queue_batch_size'   => array( 1, 20 ),
			'queue_max_attempts' => array( 1, 10 ),
			'request_timeout'    => array( 5, 120 ),
			'request_retries'    => array( 0, 5 ),
		) as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$current[ $key ] = max( $range[0], min( $range[1], absint( $input[ $key ] ) ) );
			}
		}update_option( 'oct_settings', $current, false );
		if ( $tab === 'advanced' ) {
			update_option( 'oct_delete_data_on_uninstall', ! empty( $input['delete_data_on_uninstall'] ), false );
		}AdminNotices::add( 'Settings saved.' );
		$this->back( 'oct-settings&tab=' . $tab );
	}
	public function oct_save_content_types(): void {
		check_admin_referer( 'oct_save_content_types' );
		$this->requireManage();
		$input = isset( $_POST['types'] ) ? (array) wp_unslash( $_POST['types'] ) : array();
		$saved = array();
		foreach ( Helpers::publicPostTypes() as $type ) {
			foreach ( array( 'enabled', 'title', 'content', 'excerpt', 'slug', 'taxonomies', 'custom_fields', 'auto_translate' ) as $field ) {
				$saved[ $type ][ $field ] = ! empty( $input[ $type ][ $field ] );
			}
		}update_option( 'oct_content_types', $saved, false );
		AdminNotices::add( 'Content type settings saved.' );
		$this->back( 'oct-content-types' );}
	public function oct_save_fields(): void {
		check_admin_referer( 'oct_save_fields' );
		$this->requireManage();
		$input = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array();
		$saved = array();
		foreach ( $input as $key => $strategy ) {
			$key      = sanitize_text_field( (string) $key );
			$strategy = sanitize_key( (string) $strategy );
			if ( in_array( $strategy, array( 'translate', 'copy', 'ignore' ), true ) ) {
				$saved[ $key ] = $strategy;
			}
		}update_option( 'oct_field_policies', $saved, false );
		AdminNotices::add( 'Field strategies saved.' );
		$this->back( 'oct-fields' );}
	public function oct_bulk_translate(): void {
		$this->bulkRequest( 'missing' );
	}public function oct_bulk_retranslate(): void {
		$this->bulkRequest( 'all' );
	}public function oct_bulk_outdated(): void {
		$this->bulkRequest( 'outdated' );
	}public function oct_bulk_queue(): void {
		$this->bulkRequest( 'all' );}
	private function bulkRequest( string $mode ): void {
		check_admin_referer( 'bulk-translations' );
		$language = sanitize_key( wp_unslash( $_POST['target_language'] ?? '' ) );
		$ids      = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
		if ( ! $language ) {
			AdminNotices::add( 'Choose a target language.', 'error' );
			$this->back( 'oct-translations' );
		}$count = $this->bulk->enqueue( $ids, $language, $mode );
		AdminNotices::add( $count . ' translation job(s) queued.' );
		$this->back( 'oct-translations' );}
	private function requireManage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.', 403 );}}
	private function back( string $page ): never {
		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url( 'admin.php?page=' . $page ) );
		exit;}
}
