<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Support\Helpers;
use Throwable;

final class SettingsPage {

	/** @var array<string,string> */
	private array $tabs = array(
		'general'     => 'General',
		'deepl'       => 'DeepL API',
		'translation' => 'Translation',
		'automation'  => 'Automation',
		'advanced'    => 'Advanced',
	);

	public function render(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $this->tabs[ $tab ] ) ) {
			$tab = 'general'; }
		$settings = Helpers::settings();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'OneClickTranslation Settings', 'oneclicktranslation' ); ?></h1>
		<nav class="nav-tab-wrapper">
		<?php
		foreach ( $this->tabs as $key => $label ) :
			?>
			<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=oct-settings&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
		</nav>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oct_save_settings"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'oct_save_settings' ); ?>
			<table class="form-table" role="presentation"><tbody><?php $this->{'render' . ucfirst( $tab )}( $settings ); ?></tbody></table>
			<?php submit_button(); ?>
		</form>
		<?php if ( $tab === 'deepl' ) : ?>
			<form class="oct-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="oct_test_deepl"><?php wp_nonce_field( 'oct_test_deepl' ); ?><?php submit_button( 'Test connection and show API usage', 'secondary', 'submit', false ); ?></form>
		<?php endif; ?></div>
		<?php
	}

	private function renderGeneral( array $s ): void {
		$available = ( new ProviderResolver() )->available();
		$this->select(
			'provider',
			'Provider',
			(string) $s['provider'],
			array(
				'auto'     => 'Auto detect',
				'wpml'     => 'WPML',
				'polylang' => 'Polylang',
			),
			'If both providers are active, select one explicitly. Available: ' . ( implode( ', ', array_keys( $available ) ) ? implode( ', ', array_keys( $available ) ) : 'none' )
		);
	}

	private function renderDeepl( array $s ): void {
		$constant = defined( 'OCT_DEEPL_API_KEY' ) && (string) OCT_DEEPL_API_KEY !== '';
		echo '<tr><th scope="row"><label for="api_key">API Key</label></th><td><input class="regular-text" type="password" id="api_key" name="settings[api_key]" value="" autocomplete="new-password" placeholder="' . esc_attr( Helpers::maskSecret( Helpers::apiKey() ) ) . '" ' . ( $constant ? 'disabled' : '' ) . '><p class="description">' . esc_html( $constant ? 'OCT_DEEPL_API_KEY is defined and takes precedence.' : 'Leave blank to keep the stored key.' ) . '</p></td></tr>';
		$this->select(
			'api_type',
			'API Type',
			(string) $s['api_type'],
			array(
				'free' => 'Free',
				'pro'  => 'Pro',
			)
		);
		$this->select(
			'formality',
			'Formality',
			(string) $s['formality'],
			array(
				'default'     => 'Default',
				'more'        => 'More formal',
				'less'        => 'Less formal',
				'prefer_more' => 'Prefer more',
				'prefer_less' => 'Prefer less',
			)
		);
		$this->checkbox( 'preserve_formatting', 'Preserve formatting', (bool) $s['preserve_formatting'] );
		$this->select(
			'split_sentences',
			'Split sentences',
			(string) $s['split_sentences'],
			array(
				'0'          => 'No splitting',
				'1'          => 'Default',
				'nonewlines' => 'No newlines',
			)
		);
	}

	private function renderTranslation( array $s ): void {
		$labels = array(
			'translate_title'         => 'Translate title',
			'translate_content'       => 'Translate content',
			'translate_excerpt'       => 'Translate excerpt',
			'translate_slug'          => 'Translate slug',
			'translate_taxonomies'    => 'Translate taxonomies',
			'translate_custom_fields' => 'Translate custom fields',
			'translate_acf'           => 'Translate ACF',
			'translate_gutenberg'     => 'Translate Gutenberg',
			'copy_featured_image'     => 'Copy featured image',
			'copy_attachments'        => 'Copy attachments',
			'preserve_html'           => 'Preserve HTML',
			'preserve_shortcodes'     => 'Preserve shortcodes',
			'preserve_urls'           => 'Preserve URLs',
			'preserve_emails'         => 'Preserve emails',
			'preserve_code_blocks'    => 'Preserve code blocks',
			'preserve_placeholders'   => 'Preserve placeholders',
		);
		foreach ( $labels as $key => $label ) {
			$this->checkbox( $key, $label, (bool) $s[ $key ] ); }
	}

	private function renderAutomation( array $s ): void {
		foreach ( array(
			'auto_new'            => 'Automatically translate new content',
			'auto_update'         => 'Automatically update translations',
			'only_published'      => 'Translate only published content',
			'translate_drafts'    => 'Translate drafts',
			'translate_scheduled' => 'Translate scheduled posts',
		) as $key => $label ) {
			$this->checkbox( $key, $label, (bool) $s[ $key ] );
		}
		$languages = array();
		try {
			$languages = ( new ProviderResolver() )->resolve()->getLanguages(); } catch ( Throwable ) {
			}
			echo '<tr><th scope="row">Target languages</th><td><fieldset>';
			foreach ( $languages as $code => $language ) {
				printf( '<label><input type="checkbox" name="settings[target_languages][]" value="%s" %s> %s (%s)</label><br>', esc_attr( $code ), checked( in_array( $code, (array) $s['target_languages'], true ), true, false ), esc_html( (string) $language['name'] ), esc_html( $code ) );
			}
			if ( ! $languages ) {
				echo '<p class="description">Configure languages in WPML or Polylang first.</p>'; }
			echo '</fieldset></td></tr>';
	}

	private function renderAdvanced( array $s ): void {
		$this->checkbox( 'show_internal_fields', 'Show internal fields', (bool) $s['show_internal_fields'] );
		$this->number( 'queue_batch_size', 'Jobs per queue run', (int) $s['queue_batch_size'], 1, 20 );
		$this->number( 'queue_max_attempts', 'Maximum queue attempts', (int) $s['queue_max_attempts'], 1, 10 );
		$this->number( 'request_timeout', 'DeepL timeout (seconds)', (int) $s['request_timeout'], 5, 120 );
		$this->number( 'request_retries', 'DeepL request retries', (int) $s['request_retries'], 0, 5 );
		$this->checkbox( 'delete_data_on_uninstall', 'Delete all plugin data on uninstall', (bool) get_option( 'oct_delete_data_on_uninstall', false ) );
	}

	private function checkbox( string $name, string $label, bool $checked ): void {
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="settings[%s]" value="1" %s> %s</label></td></tr>', esc_html( $label ), esc_attr( $name ), checked( $checked, true, false ), esc_html( $label ) );
	}

	private function select( string $name, string $label, string $selected, array $options, string $description = '' ): void {
		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td><select id="%s" name="settings[%s]">', esc_attr( $name ), esc_html( $label ), esc_attr( $name ), esc_attr( $name ) );
		foreach ( $options as $value => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $value ), selected( $selected, $value, false ), esc_html( (string) $text ) ); }
		echo '</select>' . ( $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '' ) . '</td></tr>';
	}

	private function number( string $name, string $label, int $value, int $min, int $max ): void {
		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td><input class="small-text" type="number" id="%s" name="settings[%s]" value="%d" min="%d" max="%d"></td></tr>', esc_attr( $name ), esc_html( $label ), esc_attr( $name ), esc_attr( $name ), $value, $min, $max );
	}
}
