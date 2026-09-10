<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\FieldPolicy;
use Throwable;

final class FieldsPage {
	/** @var array<string,true>|null */
	private ?array $acfFieldNames = null;

	public function render(): void {
		global $wpdb;
		$showInternal = (bool) Helpers::settings()['show_internal_fields'];
		$limit        = 500;
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_key,p.post_type,COUNT(*) occurrences,MAX(pm.meta_value) sample
             FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE p.post_type NOT IN ('revision','attachment') AND pm.meta_key NOT LIKE %s
             GROUP BY pm.meta_key,p.post_type ORDER BY pm.meta_key ASC LIMIT %d",
				$showInternal ? 'oct_never_match_%' : '\\_%',
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$manual       = (array) get_option( 'oct_field_policies', array() );
		try {
			$provider = ( new ProviderResolver() )->resolve();
		} catch ( Throwable ) {
			$provider = null;
		}
		$policy = new FieldPolicy( $provider );
		?><div class="wrap"><h1><?php esc_html_e( 'Fields', 'oneclicktranslation' ); ?></h1><p><?php esc_html_e( 'Strategies apply to postmeta. WPML custom-field preferences take precedence; ACF field types are handled recursively.', 'oneclicktranslation' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="oct_save_fields"><?php wp_nonce_field( 'oct_save_fields' ); ?>
		<table class="widefat striped fixed"><thead><tr><th>Meta Key</th><th>Post Type</th><th>Detected Type</th><th>Strategy</th><th>Source</th></tr></thead><tbody>
		<?php
		foreach ( (array) $rows as $row ) :
			$key              = (string) $row['meta_key'];
			$sample           = maybe_unserialize( (string) $row['sample'] );
			$type             = $this->type( $sample );
			$auto             = $policy->decide( $key, $sample );
			$providerStrategy = $provider ? $provider->getCustomFieldStrategy( $key, true ) : null;
			$selected         = $providerStrategy ?? ( $manual[ $key ] ?? $auto );
			$source           = null !== $providerStrategy ? $provider->getProviderName() : ( isset( $manual[ $key ] ) ? 'Manual' : ( $this->isAcfField( $key ) ? 'ACF' : 'Automatic' ) );
			?>
			<tr><th scope="row"><code><?php echo esc_html( $key ); ?></code></th><td><?php echo esc_html( (string) $row['post_type'] ); ?></td><td><?php echo esc_html( $type ); ?></td><td><select name="fields[<?php echo esc_attr( $key ); ?>]">
			<?php
			foreach ( array(
				'translate' => 'Translate',
				'copy'      => 'Copy',
				'ignore'    => 'Ignore',
			) as $value => $label ) :
				?>
	<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td><td><?php echo esc_html( $source ); ?></td></tr>
		<?php endforeach; ?>
		<?php
		if ( ! $rows ) :
			?>
			<tr><td colspan="5">No custom fields detected.</td></tr><?php endif; ?></tbody></table>
		<p class="description"><?php echo esc_html( sprintf( 'Showing up to %d detected field/post-type combinations. Internal fields are %s.', $limit, $showInternal ? 'visible' : 'hidden' ) ); ?></p><?php submit_button(); ?></form></div>
		<?php
	}

	private function type( mixed $value ): string {
		if ( is_array( $value ) ) {
			return 'array'; }
		if ( is_object( $value ) ) {
			return 'object'; }
		if ( is_numeric( $value ) ) {
			return 'number'; }
		if ( is_bool( $value ) ) {
			return 'boolean'; }
		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return 'URL'; }
		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return 'email'; }
		return gettype( $value );
	}

	private function isAcfField( string $key ): bool {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return false;
		}
		if ( null === $this->acfFieldNames ) {
			global $wpdb;
			$this->acfFieldNames = array();
			$references          = $wpdb->get_results(
				"SELECT DISTINCT meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_%' AND meta_value LIKE 'field\\_%'",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $references as $reference ) {
				$name     = substr( (string) $reference['meta_key'], 1 );
				$fieldKey = (string) $reference['meta_value'];
				if ( '' !== $name && acf_get_field( $fieldKey ) ) {
					$this->acfFieldNames[ $name ] = true;
				}
			}
		}
		return isset( $this->acfFieldNames[ $key ] );
	}
}
