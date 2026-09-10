<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\DeepL\DeepLClient;
use OneClickTranslation\Providers\ProviderResolver;
use OneClickTranslation\Queue\QueueRepository;
use OneClickTranslation\Support\Helpers;
use OneClickTranslation\Translation\TranslationCache;
use OneClickTranslation\Translation\TranslationStatus;
use Throwable;

final class DashboardPage {

	public function render(): void {
		$resolver      = new ProviderResolver();
		$provider      = null;
		$providerError = '';
		try {
			$provider = $resolver->resolve();
		} catch ( Throwable $error ) {
			$providerError = $error->getMessage(); }
		$usage      = get_transient( 'oct_deepl_usage' );
		$connection = get_transient( 'oct_deepl_connection' );
		$status     = ( new TranslationStatus() )->counts();
		$queue      = ( new QueueRepository() )->counts();
		$cache      = ( new TranslationCache() )->stats();
		$overview   = $provider ? $this->translationOverview( $provider ) : array(
			'translated' => 0,
			'missing'    => 0,
		);
		global $wpdb;
		$lastErrors = $wpdb->get_results( "SELECT created_at,message FROM {$wpdb->prefix}oct_logs WHERE level='error' ORDER BY id DESC LIMIT 5", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OneClickTranslation Dashboard', 'oneclicktranslation' ); ?></h1>
			<?php
			if ( $providerError ) :
				?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $providerError ); ?></p></div><?php endif; ?>
			<div id="poststuff"><div class="meta-box-sortables oct-dashboard-grid">
				<div class="postbox"><h2 class="hndle"><span><?php esc_html_e( 'System status', 'oneclicktranslation' ); ?></span></h2><div class="inside">
					<table class="widefat striped"><tbody>
					<?php $this->row( 'Translation provider', $provider ? $provider->getProviderName() : '—' ); ?>
					<?php $this->row( 'Provider status', $provider ? 'Available' : 'Configuration required' ); ?>
					<?php $this->row( 'DeepL connection status', is_array( $connection ) ? ( $connection['success'] ? 'Connected' : $connection['message'] ) : 'Not tested' ); ?>
					<?php $this->row( 'DeepL API plan', is_array( $usage ) ? (string) ( $usage['api_type'] ?? '—' ) : ucfirst( (string) Helpers::settings()['api_type'] ) ); ?>
					<?php $this->row( 'Character usage', is_array( $usage ) ? number_format_i18n( (int) $usage['character_count'] ) : '—' ); ?>
					<?php $this->row( 'Character limit', is_array( $usage ) ? number_format_i18n( (int) $usage['character_limit'] ) : '—' ); ?>
					<?php $this->row( 'Usage last updated', is_array( $usage ) && ! empty( $usage['last_updated'] ) ? (string) $usage['last_updated'] : '—' ); ?>
					<?php $this->row( 'Default language', $provider ? $provider->getDefaultLanguage() : '—' ); ?>
					<?php $this->row( 'Available languages', $provider ? implode( ', ', array_keys( $provider->getLanguages() ) ) : '—' ); ?>
					</tbody></table>
				</div></div>
				<div class="postbox"><h2 class="hndle"><span><?php esc_html_e( 'Translation summary', 'oneclicktranslation' ); ?></span></h2><div class="inside">
					<table class="widefat striped"><tbody>
					<?php $this->row( 'Translated posts', number_format_i18n( $overview['translated'] ) ); ?>
					<?php $this->row( 'Missing translations', number_format_i18n( $overview['missing'] ) ); ?>
					<?php $this->row( 'Outdated translations', number_format_i18n( (int) ( $status['outdated'] ?? 0 ) ) ); ?>
					<?php $this->row( 'Queued translations', number_format_i18n( (int) ( $queue['pending'] ?? 0 ) ) ); ?>
					<?php $this->row( 'Failed translations', number_format_i18n( (int) ( $queue['failed'] ?? 0 ) ) ); ?>
					<?php $this->row( 'Cache hit ratio', number_format_i18n( $cache['hit_ratio'], 2 ) . '%' ); ?>
					</tbody></table>
				</div></div>
			</div>
			<div class="tablenav top oct-dashboard-toolbar"><div class="alignleft actions oct-actions">
				<?php $this->action( 'oct_test_deepl', 'Test DeepL connection', 'button-primary' ); ?>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=oct-translations&status=missing' ) ); ?>"><?php esc_html_e( 'Translate missing content', 'oneclicktranslation' ); ?></a>
				<?php $this->action( 'oct_run_queue', 'Process queue now' ); ?>
				<?php $this->action( 'oct_clear_completed', 'Clear completed jobs' ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=oct-logs' ) ); ?>"><?php esc_html_e( 'View logs', 'oneclicktranslation' ); ?></a>
			</div><br class="clear"></div>
			<div class="postbox"><h2 class="hndle"><span><?php esc_html_e( 'Last errors', 'oneclicktranslation' ); ?></span></h2><div class="inside">
				<?php
				if ( ! $lastErrors ) :
					?>
					<p><?php esc_html_e( 'No errors recorded.', 'oneclicktranslation' ); ?></p><?php else : ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'oneclicktranslation' ); ?></th><th><?php esc_html_e( 'Message', 'oneclicktranslation' ); ?></th></tr></thead><tbody>
							<?php
							foreach ( $lastErrors as $error ) :
								?>
							<tr><td><?php echo esc_html( get_date_from_gmt( (string) $error['created_at'] ) ); ?></td><td><?php echo esc_html( (string) $error['message'] ); ?></td></tr><?php endforeach; ?>
				</tbody></table><?php endif; ?>
			</div></div></div>
			<?php
			if ( Helpers::debug() ) :
				$availableProviders = implode( ', ', array_keys( $resolver->available() ) );
				$debugProvider      = $provider ? $provider->getProviderName() : 'unresolved';
				?>
				<div class="notice notice-info inline"><p><?php echo esc_html( 'OCT_DEBUG enabled. Provider: ' . $debugProvider . '; detected: ' . ( $availableProviders ? $availableProviders : 'none' ) . '; selected: ' . Helpers::settings()['provider'] . '; PHP ' . PHP_VERSION . '; WordPress ' . get_bloginfo( 'version' ) . '; DB ' . get_option( 'oct_db_version' ) . '. Batch diagnostics are recorded in Logs.' ); ?></p></div><?php endif; ?>
		</div>
		<?php
	}

	private function row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	private function action( string $action, string $label, string $class = 'button-secondary' ): void {
		echo '<form class="oct-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	/** @return array{translated:int,missing:int} */
	private function translationOverview( \OneClickTranslation\Contracts\LanguageProviderInterface $provider ): array {
		$targets   = array_values( array_diff( array_keys( $provider->getLanguages() ), array( $provider->getDefaultLanguage() ) ) );
		$postTypes = array_values( array_filter( \OneClickTranslation\Support\Helpers::publicPostTypes(), static fn ( string $type ): bool => (bool) \OneClickTranslation\Support\Helpers::postTypeConfig( $type )['enabled'] ) );
		$ids       = get_posts(
			array(
				'post_type'        => $postTypes,
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$result    = array(
			'translated' => 0,
			'missing'    => 0,
		);
		foreach ( $ids as $id ) {
			if ( $provider->getPostLanguage( (int) $id ) !== $provider->getDefaultLanguage() ) {
				continue; }
			foreach ( $targets as $language ) {
				if ( $provider->getPostTranslation( (int) $id, $language ) ) {
					++$result['translated'];
				} else {
					++$result['missing']; }
			}
		}
		return $result;
	}
}
