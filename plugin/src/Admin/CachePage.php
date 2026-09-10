<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Translation\TranslationCache;

final class CachePage {

	public function render(): void {
		$stats = ( new TranslationCache() )->stats();
		?><div class="wrap"><h1><?php esc_html_e( 'Translation Cache', 'oneclicktranslation' ); ?></h1><table class="widefat striped oct-summary-table"><tbody>
		<?php
		foreach ( array(
			'entries'          => 'Entries',
			'hits'             => 'Hits',
			'misses'           => 'Misses',
			'hit_ratio'        => 'Hit ratio',
			'characters_saved' => 'Characters saved',
			'requests_saved'   => 'Estimated requests saved',
		) as $key => $label ) :
			?>
			<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( number_format_i18n( $stats[ $key ], $key === 'hit_ratio' ? 2 : 0 ) . ( $key === 'hit_ratio' ? '%' : '' ) ); ?></td></tr><?php endforeach; ?></tbody></table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="oct_clear_cache"><?php wp_nonce_field( 'oct_clear_cache' ); ?><?php submit_button( 'Clear translation cache', 'delete' ); ?></form></div>
		<?php
	}
}
