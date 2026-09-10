<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationStatus;
use Throwable;

final class PostListColumns {

	public function register(): void {
		foreach ( Helpers::publicPostTypes() as $type ) {
			if ( ! Helpers::postTypeConfig( $type )['enabled'] ) {
				continue;
			}
			add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render' ), 10, 2 );}
	}
	public function columns( array $columns ): array {
		$columns['oct_translations'] = __( 'Translations', 'oneclicktranslation' );
		return $columns; }
	public function render( string $column, int $postId ): void {
		if ( $column !== 'oct_translations' ) {
			return;
		}try {
			$provider = ( new ProviderResolver() )->resolve();
		} catch ( Throwable ) {
			echo '—';
			return;
		}$source    = $provider->getPostLanguage( $postId );
		$statusRepo = new TranslationStatus();
		foreach ( $provider->getLanguages() as $code => $language ) {
			if ( $code === $source ) {
				echo '<strong title="Original">' . esc_html( strtoupper( $code ) ) . '</strong> ';
				continue;
			}$target = $provider->getPostTranslation( $postId, $code );
			$record  = $statusRepo->get( $postId, $code );
			$status  = (string) ( $record['status'] ?? ( $target ? 'translated' : 'missing' ) );
			$icon    = array(
				'translated' => '✓',
				'missing'    => '—',
				'outdated'   => '!',
				'error'      => '×',
				'queued'     => '…',
				'processing' => '↻',
			)[ $status ] ?? '?';
			$url     = $target ? get_edit_post_link( $target ) : wp_nonce_url( admin_url( 'admin-post.php?action=oct_translate&post_id=' . $postId . '&language=' . $code ), 'oct_translate_' . $postId . '_' . $code );
			echo '<a title="' . esc_attr( ucfirst( $status ) ) . '" class="oct-status-' . esc_attr( $status ) . '" href="' . esc_url( (string) $url ) . '">' . esc_html( strtoupper( $code ) . ' ' . $icon ) . '</a> ';
		}
	}
}
