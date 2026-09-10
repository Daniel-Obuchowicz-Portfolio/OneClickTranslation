<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Contracts\LanguageProviderInterface;
use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationStatus;
use Throwable;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }

final class TranslationsListTable extends \WP_List_Table {

	/** @var list<string> */ private array $targets;
	public function __construct( private readonly LanguageProviderInterface $provider ) {
		parent::__construct(
			array(
				'singular' => 'translation',
				'plural'   => 'translations',
				'ajax'     => false,
			)
		);
		$this->targets = array_values( array_diff( array_keys( $provider->getLanguages() ), array( $provider->getDefaultLanguage() ) ) );
	}
	public function get_columns(): array {
		return array(
			'cb'               => '<input type="checkbox">',
			'title'            => 'Title',
			'post_type'        => 'Post Type',
			'source_language'  => 'Source Language',
			'target_languages' => 'Target Languages',
			'status'           => 'Status',
			'last_translation' => 'Last Translation',
			'updated'          => 'Updated',
			'actions'          => 'Actions',
		); }
	protected function get_bulk_actions(): array {
		return array(
			'oct_bulk_translate'   => 'Translate',
			'oct_bulk_retranslate' => 'Retranslate',
			'oct_bulk_outdated'    => 'Update outdated',
			'oct_bulk_queue'       => 'Queue translation',
		); }
	public function no_items(): void {
		esc_html_e( 'No source content found.', 'oneclicktranslation' ); }
	public function column_cb( $item ): string {
		$chars = mb_strlen( (string) $item->post_title . (string) $item->post_content . (string) $item->post_excerpt );
		return '<input type="checkbox" name="post_ids[]" value="' . (int) $item->ID . '" data-characters="' . $chars . '">'; }
	public function column_title( $item ): string {
		$title = get_the_title( (int) $item->ID );
		return '<strong><a href="' . esc_url( get_edit_post_link( (int) $item->ID ) ) . '">' . esc_html( $title ? $title : '(no title)' ) . '</a></strong>'; }
	public function column_default( $item, $column_name ): string {
		$id     = (int) $item->ID;
		$states = $this->states( $id );
		return match ( $column_name ) {
			'post_type'=>esc_html( $item->post_type ),
			'source_language'=>esc_html( $this->provider->getPostLanguage( $id ) ),
			'target_languages'=>$this->languageStatuses( $id, $states ),
			'status'=>esc_html( ucfirst( $this->aggregate( $states ) ) ),
			'last_translation'=>esc_html( $this->last( $states, 'translated_at' ) ),
			'updated'=>esc_html( get_the_modified_date( '', $id ) ),
			'actions'=>$this->actions( $id, $states ),
			default=>'',
		};
	}
	protected function get_views(): array {
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$views   = array();
		foreach ( array(
			'all'        => 'All',
			'missing'    => 'Missing',
			'outdated'   => 'Outdated',
			'translated' => 'Translated',
			'queued'     => 'Queued',
			'error'      => 'Errors',
		) as $key => $label ) {
			$views[ $key ] = '<a class="' . ( $current === $key ? 'current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=oct-translations' . ( $key === 'all' ? '' : '&status=' . $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $views;
	}
	public function prepare_items(): void {
		$perPage               = 20;
		$page                  = max( 1, $this->get_pagenum() );
		$status                = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$postTypes             = array_values( array_filter( Helpers::publicPostTypes(), static fn ( string $type ): bool => (bool) Helpers::postTypeConfig( $type )['enabled'] ) );
		$query                 = new \WP_Query(
			array(
				'post_type'        => $postTypes,
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'   => -1,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'fields'           => 'all',
			)
		);
		$items                 = array_values(
			array_filter(
				$query->posts,
				function ( $post ) use ( $status ): bool {
					if ( $post->post_type === 'attachment' || $this->provider->getPostLanguage( (int) $post->ID ) !== $this->provider->getDefaultLanguage() ) {
						return false; }
					return $status === 'all' || $this->aggregate( $this->states( (int) $post->ID ) ) === $status;
				}
			)
		);
		$total                 = count( $items );
		$this->items           = array_slice( $items, ( $page - 1 ) * $perPage, $perPage );
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(
				'title'   => array( 'title', false ),
				'updated' => array( 'updated', true ),
			),
		);
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $perPage,
			)
		);
	}
	/** @return array<string,array<string,mixed>> */
	private function states( int $id ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'oct_status';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_object_id=%d AND object_type='post'", $id ), ARRAY_A );
		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[ (string) $row['target_language'] ] = $row;}
		foreach ( $this->targets as $language ) {
			if ( ! isset( $result[ $language ] ) ) {
				$translated          = $this->provider->getPostTranslation( $id, $language );
				$result[ $language ] = array(
					'status'               => $translated ? 'translated' : 'missing',
					'translated_object_id' => $translated,
					'translated_at'        => '',
				);}
		}
		return $result;
	}
	private function aggregate( array $states ): string {
		$order  = array( 'error', 'processing', 'queued', 'outdated', 'missing', 'translated' );
		$values = array_column( $states, 'status' );
		foreach ( $order as $status ) {
			if ( in_array( $status, $values, true ) ) {
				return $status;
			}
		} return 'missing';
	}
	private function languageStatuses( int $id, array $states ): string {
		$icons = array(
			'translated' => '✓',
			'missing'    => '—',
			'outdated'   => '!',
			'error'      => '×',
			'queued'     => '…',
			'processing' => '↻',
		);
		$html  = array();
		foreach ( $states as $language => $state ) {
			$target = (int) ( $state['translated_object_id'] ?? 0 );
			$status = (string) $state['status'];
			$url    = $target ? get_edit_post_link( $target ) : wp_nonce_url( admin_url( 'admin-post.php?action=oct_translate&post_id=' . $id . '&language=' . $language ), 'oct_translate_' . $id . '_' . $language );
			$html[] = '<a class="oct-translation-link oct-status-' . esc_attr( $status ) . '" title="' . esc_attr( ucfirst( $status ) ) . '" href="' . esc_url( (string) $url ) . '">' . esc_html( strtoupper( $language ) . ' ' . ( $icons[ $status ] ?? '?' ) ) . '</a>';}
		return implode( ' ', $html );
	}
	private function actions( int $id, array $states ): string {
		$links = array();
		foreach ( $states as $language => $state ) {
			$status = (string) $state['status'];
			if ( $status === 'translated' ) {
				continue;
			}$label  = $status === 'outdated' ? 'Update' : 'Translate';
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=oct_translate&post_id=' . $id . '&language=' . $language ), 'oct_translate_' . $id . '_' . $language );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label . ' ' . strtoupper( $language ) ) . '</a>';}
		$html = implode( ' | ', $links );
		return $html ? $html : '—';
	}
	private function last( array $states, string $key ): string {
		$values = array_filter( array_column( $states, $key ) );
		rsort( $values );
		return $values ? get_date_from_gmt( (string) $values[0] ) : '—';
	}
}

final class TranslationsPage {

	public function render(): void {
		try {
			$provider = ( new ProviderResolver() )->resolve();
		} catch ( Throwable $error ) {
			echo '<div class="wrap"><h1>Translations</h1><div class="notice notice-warning inline"><p>' . esc_html( $error->getMessage() ) . '</p></div></div>';
			return;}
		$table = new TranslationsListTable( $provider );
		$table->prepare_items();
		$targets = array_values( array_diff( array_keys( $provider->getLanguages() ), array( $provider->getDefaultLanguage() ) ) );
		?><div class="wrap"><h1 class="wp-heading-inline"><?php esc_html_e( 'Translations', 'oneclicktranslation' ); ?></h1><hr class="wp-header-end"><?php $table->views(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oct-bulk-form" data-confirm-threshold="100000"><?php wp_nonce_field( 'bulk-translations' ); ?>
		<div class="tablenav top"><div class="alignleft actions"><label class="screen-reader-text" for="oct-target-language">Target language</label><select name="target_language" id="oct-target-language" required><option value="">Target language…</option>
		<?php
		foreach ( $targets as $language ) :
			?>
			<option value="<?php echo esc_attr( $language ); ?>"><?php echo esc_html( strtoupper( $language ) ); ?></option><?php endforeach; ?></select></div></div>
		<?php $table->display(); ?><div class="oct-estimate notice notice-info inline" hidden><p></p></div></form></div>
		<?php
	}
}
