<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Support\Helpers;

final class ContentTypesPage {

	public function render(): void {
		?><div class="wrap"><h1><?php esc_html_e( 'Content Types', 'oneclicktranslation' ); ?></h1><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="oct_save_content_types"><?php wp_nonce_field( 'oct_save_content_types' ); ?>
		<table class="widefat striped fixed"><thead><tr><th>Content Type</th><th>Enabled</th><th>Title</th><th>Content</th><th>Excerpt</th><th>Slug</th><th>Taxonomies</th><th>Custom Fields</th><th>Auto Translate</th></tr></thead><tbody>
		<?php
		foreach ( Helpers::publicPostTypes() as $postType ) :
			$object = get_post_type_object( $postType );
			$config = Helpers::postTypeConfig( $postType );
			?>
		<tr><th scope="row"><?php echo esc_html( ( $object->labels->name ?? $postType ) . ' (' . $postType . ')' ); ?></th>
			<?php
			foreach ( array( 'enabled', 'title', 'content', 'excerpt', 'slug', 'taxonomies', 'custom_fields', 'auto_translate' ) as $field ) :
				?>
				<td><label><span class="screen-reader-text"><?php echo esc_html( $field ); ?></span><input type="checkbox" name="types[<?php echo esc_attr( $postType ); ?>][<?php echo esc_attr( $field ); ?>]" value="1" <?php checked( (bool) $config[ $field ] ); ?>></label></td><?php endforeach; ?></tr>
		<?php endforeach; ?></tbody></table><?php submit_button(); ?></form></div>
		<?php
	}
}
