<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Providers\ProviderResolver;
use Throwable;

final class LanguagesPage {

	public function render(): void {
		try {
			$provider  = ( new ProviderResolver() )->resolve();
			$languages = $provider->getLanguages();
			$error     = ''; } catch ( Throwable $exception ) {
			$provider  = null;
			$languages = array();
			$error     = $exception->getMessage(); }
			?><div class="wrap"><h1><?php esc_html_e( 'Languages', 'oneclicktranslation' ); ?></h1>
		<?php
		if ( $error ) :
			?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
		<p><?php echo esc_html( $provider ? 'Languages are managed by ' . $provider->getProviderName() . '. OneClickTranslation does not maintain a separate language list.' : 'Activate and configure WPML or Polylang.' ); ?></p>
		<table class="widefat striped"><thead><tr><th>Code</th><th>Name</th><th>Native name</th><th>Locale</th><th>Default</th><th>Current</th></tr></thead><tbody>
		<?php
		foreach ( $languages as $code => $language ) :
			?>
			<tr><td><code><?php echo esc_html( $code ); ?></code></td><td><?php echo esc_html( (string) $language['name'] ); ?></td><td><?php echo esc_html( (string) $language['native_name'] ); ?></td><td><?php echo esc_html( (string) $language['locale'] ); ?></td><td><?php echo $provider->getDefaultLanguage() === $code ? '✓' : ''; ?></td><td><?php echo $provider->getCurrentLanguage() === $code ? '✓' : ''; ?></td></tr><?php endforeach; ?>
		<?php
		if ( ! $languages ) :
			?>
			<tr><td colspan="6">No languages available.</td></tr><?php endif; ?></tbody></table></div>
		<?php
	}
}
