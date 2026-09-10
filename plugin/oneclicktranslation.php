<?php
/**
 * Plugin Name: OneClickTranslation
 * Plugin URI:  https://example.test/oneclicktranslation
 * Description: One-click DeepL translations for WPML and Polylang.
 * Version:     1.0.0
 * Author:      OneClickTranslation Contributors
 * License:     GPL-2.0-or-later
 * Text Domain: oneclicktranslation
 * Requires at least: 6.4
 * Requires PHP: 8.2
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OCT_VERSION', '1.0.0' );
define( 'OCT_FILE', __FILE__ );
define( 'OCT_PATH', plugin_dir_path( __FILE__ ) );
define( 'OCT_URL', plugin_dir_url( __FILE__ ) );

$octAutoload = OCT_PATH . 'vendor/autoload.php';
if ( is_readable( $octAutoload ) ) {
	require_once $octAutoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'OneClickTranslation\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$file = OCT_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( OneClickTranslation\Database\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( OneClickTranslation\Database\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		OneClickTranslation\Plugin::instance()->boot();
	}
);
