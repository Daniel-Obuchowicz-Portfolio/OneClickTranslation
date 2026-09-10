<?php

declare(strict_types=1);

namespace OneClickTranslation\REST;

use OneClickTranslation\DeepL\DeepLClient;
use OneClickTranslation\Queue\Queue;
use OneClickTranslation\Queue\QueueRepository;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationService;
use OneClickTranslation\Translation\TranslationStatus;

final class Controller {

	public function __construct( private readonly TranslationService $service, private readonly Queue $queue ) {}
	public function register(): void {
		register_rest_route(
			'oneclicktranslation/v1',
			'/queue',
			array(
				'methods'             => 'GET',
				'callback'            => fn()=>rest_ensure_response( ( new QueueRepository() )->counts() ),
				'permission_callback' => fn()=>current_user_can( 'manage_options' ),
			)
		);
		register_rest_route(
			'oneclicktranslation/v1',
			'/translate/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => fn( $request )=>current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => array(
					'language' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'oneclicktranslation/v1',
			'/status/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => fn( $request )=>current_user_can( 'edit_post', (int) $request['id'] ),
			)
		);
		register_rest_route(
			'oneclicktranslation/v1',
			'/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => fn()=>current_user_can( 'manage_options' ),
			)
		);
	}
	public function translate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$result = $this->service->translatePost( (int) $request['id'], (string) $request['language'] );
			return rest_ensure_response(
				array(
					'success'            => true,
					'translated_post_id' => $result->translatedPostId,
					'status'             => $result->status,
					'source_hash'        => $result->sourceHash,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'oct_translation_failed', $e->getMessage(), array( 'status' => 500 ) );}
	}
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'oct_status';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT target_language,status,translated_object_id,source_hash,last_error,translated_at,updated_at FROM {$table} WHERE source_object_id=%d", (int) $request['id'] ), ARRAY_A );
		return rest_ensure_response( $rows );
	}
	public function usage(): \WP_REST_Response|\WP_Error {
		try {
			$s                     = Helpers::settings();
			$usage                 = ( new DeepLClient( Helpers::apiKey(), (string) $s['api_type'], (int) $s['request_timeout'], (int) $s['request_retries'] ) )->getUsage();
			$usage['last_updated'] = current_time( 'mysql' );
			set_transient( 'oct_deepl_usage', $usage, HOUR_IN_SECONDS );
			return rest_ensure_response( $usage );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'oct_deepl_error', $e->getMessage(), array( 'status' => 502 ) );}
	}
}
