<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationStatus;
use Throwable;

final class TranslationMetaBox {

	public function register(): void {
		foreach ( Helpers::publicPostTypes() as $type ) {
			if ( ! Helpers::postTypeConfig( $type )['enabled'] ) {
				continue;
			}
			add_meta_box( 'oct-translation', 'OneClickTranslation', array( $this, 'render' ), $type, 'side', 'high' );
		}
	}

	public function render( \WP_Post $post ): void {
		try {
			$provider = ( new ProviderResolver() )->resolve();
		} catch ( Throwable $error ) {
			echo '<p>' . esc_html( $error->getMessage() ) . '</p>';
			return;}
		$sourceLanguage = $provider->getPostLanguage( $post->ID );
		$languages      = $provider->getLanguages();
		$statuses       = new TranslationStatus();
		echo '<table class="widefat striped"><tbody>';
		foreach ( $languages as $code => $language ) {
			if ( $code === $sourceLanguage ) {
				echo '<tr><th>' . esc_html( (string) $language['name'] ) . '</th><td>' . esc_html__( 'Original', 'oneclicktranslation' ) . '</td></tr>';
				continue;}
			$targetId = $provider->getPostTranslation( $post->ID, $code );
			$state    = $statuses->get( $post->ID, $code );
			$status   = (string) ( $state['status'] ?? ( $targetId ? 'translated' : 'missing' ) );
			echo '<tr><th>' . esc_html( (string) $language['name'] ) . '</th><td><span class="oct-status oct-status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span><br>';
			if ( $targetId ) {
				echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( $targetId ) ) . '">' . esc_html__( 'Edit', 'oneclicktranslation' ) . '</a> ';}
			$label = $status === 'outdated' ? __( 'Update', 'oneclicktranslation' ) : ( $targetId ? __( 'Retranslate', 'oneclicktranslation' ) : __( 'Translate', 'oneclicktranslation' ) );
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oct_translate&post_id=' . $post->ID . '&language=' . $code ), 'oct_translate_' . $post->ID . '_' . $code ) ) . '">' . esc_html( $label ) . '</a></td></tr>';
		}
		echo '</tbody></table><p class="oct-meta-actions">';
		foreach ( array(
			'missing'  => 'Translate missing',
			'outdated' => 'Update outdated',
			'all'      => 'Translate to all',
		) as $mode => $label ) {
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oct_translate_all&post_id=' . $post->ID . '&mode=' . $mode ), 'oct_translate_all_' . $post->ID ) ) . '">' . esc_html( $label ) . '</a> ';}
		echo '</p>';
	}
}
