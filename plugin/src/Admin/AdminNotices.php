<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

final class AdminNotices {

	public static function add( string $message, string $type = 'success' ): void {
		$type = in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info';
		set_transient(
			'oct_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
	}

	public function render(): void {
		if ( isset( $_GET['oct_queued'] ) ) {
			$count = absint( $_GET['oct_queued'] );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( '%d translation job(s) queued.', $count ) ) );
		}
		$key    = 'oct_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return; }
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( (string) $notice['type'] ), esc_html( (string) $notice['message'] ) );
	}
}
