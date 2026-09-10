<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }

final class LogsListTable extends \WP_List_Table {

	public function get_columns(): array {
		return array(
			'created_at'      => 'Date',
			'level'           => 'Level',
			'object_id'       => 'Object',
			'target_language' => 'Language',
			'message'         => 'Message',
		); }
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
			'level'      => array( 'level', false ),
		); }
	public function no_items(): void {
		esc_html_e( 'No log entries.', 'oneclicktranslation' ); }
	public function column_default( $item, $column_name ): string {
		if ( $column_name === 'object_id' && ! empty( $item[ $column_name ] ) ) {
			return '<a href="' . esc_url( get_edit_post_link( (int) $item[ $column_name ] ) ) . '">#' . (int) $item[ $column_name ] . '</a>';
		}
		if ( $column_name === 'created_at' ) {
			return esc_html( get_date_from_gmt( (string) $item[ $column_name ] ) ); }
		if ( $column_name === 'level' ) {
			return '<span class="oct-status oct-status-' . esc_attr( (string) $item[ $column_name ] ) . '">' . esc_html( (string) $item[ $column_name ] ) . '</span>'; }
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
	protected function get_views(): array {
		$current = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$views   = array();
		foreach ( array(
			''        => 'All',
			'info'    => 'Info',
			'warning' => 'Warnings',
			'error'   => 'Errors',
		) as $key => $label ) {
			$url                          = admin_url( 'admin.php?page=oct-logs' . ( $key ? '&level=' . $key : '' ) );
			$views[ $key ? $key : 'all' ] = '<a class="' . ( $current === $key ? 'current' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return $views;
	}
	public function prepare_items(): void {
		global $wpdb;
		$perPage      = 30;
		$page         = max( 1, $this->get_pagenum() );
		$level        = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$allowedOrder = array( 'created_at', 'level' );
		$orderBy      = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $allowedOrder, true ) ? $_GET['orderby'] : 'created_at';
		$order        = isset( $_GET['order'] ) && strtolower( (string) $_GET['order'] ) === 'asc' ? 'ASC' : 'DESC';
		$table        = $wpdb->prefix . 'oct_logs';
		if ( $level ) {
			$total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level=%s", $level ) );
			$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE level=%s ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d", $level, $perPage, ( $page - 1 ) * $perPage ), ARRAY_A );
		} else {
			$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d", $perPage, ( $page - 1 ) * $perPage ), ARRAY_A );
		}
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $perPage,
			)
		);
	}
}

final class LogsPage {

	public function render(): void {
		$table = new LogsListTable();
		$table->prepare_items();
		?><div class="wrap"><h1><?php esc_html_e( 'OneClickTranslation Logs', 'oneclicktranslation' ); ?></h1><?php $table->views(); ?><form method="get"><input type="hidden" name="page" value="oct-logs"><?php $table->display(); ?></form>
		<p>
		<?php
		foreach ( array(
			'all' => 'Clear logs',
			'7'   => 'Clear older than 7 days',
			'30'  => 'Clear older than 30 days',
		) as $days => $label ) :
			?>
			<form class="oct-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="oct_clear_logs"><input type="hidden" name="days" value="<?php echo esc_attr( $days ); ?>"><?php wp_nonce_field( 'oct_clear_logs' ); ?><?php submit_button( $label, 'delete', 'submit', false ); ?></form> <?php endforeach; ?></p></div>
		<?php
	}
}
