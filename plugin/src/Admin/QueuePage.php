<?php

declare(strict_types=1);

namespace OneClickTranslation\Admin;

use OneClickTranslation\Queue\QueueRepository;

final class QueuePage {

	public function render(): void {
		$repo   = new QueueRepository();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$rows   = $repo->list( 100, 0, $status ? $status : null );
		$counts = $repo->counts();
		?><div class="wrap"><h1 class="wp-heading-inline"><?php esc_html_e( 'Translation Queue', 'oneclicktranslation' ); ?></h1>
		<form class="oct-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="oct_run_queue"><?php wp_nonce_field( 'oct_run_queue' ); ?><?php submit_button( 'Process queue now', 'primary', 'submit', false ); ?></form>
		<hr class="wp-header-end"><ul class="subsubsub"><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=oct-queue' ) ); ?>">All</a> | </li>
		<?php
		$i = 0; foreach ( $counts as $key => $count ) :
			?>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=oct-queue&status=' . $key ) ); ?>"><?php echo esc_html( ucfirst( $key ) . ' (' . $count . ')' ); ?></a><?php echo ++$i < count( $counts ) ? ' | ' : ''; ?></li><?php endforeach; ?></ul>
		<table class="widefat striped fixed"><thead><tr><th>ID</th><th>Object</th><th>Language</th><th>Provider</th><th>Status</th><th>Priority</th><th>Attempts</th><th>Created</th><th>Error</th><th>Actions</th></tr></thead><tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr><td><?php echo (int) $row['id']; ?></td><td><a href="<?php echo esc_url( get_edit_post_link( (int) $row['source_object_id'] ) ); ?>">
			<?php
			$title = get_the_title( (int) $row['source_object_id'] );
			echo esc_html( $title ? $title : '#' . $row['source_object_id'] );
			?>
			</a></td><td><?php echo esc_html( (string) $row['target_language'] ); ?></td><td><?php echo esc_html( (string) $row['provider'] ); ?></td><td><span class="oct-status oct-status-<?php echo esc_attr( (string) $row['status'] ); ?>"><?php echo esc_html( (string) $row['status'] ); ?></span></td><td><?php echo (int) $row['priority']; ?></td><td><?php echo (int) $row['attempts']; ?></td><td><?php echo esc_html( get_date_from_gmt( (string) $row['created_at'] ) ); ?></td><td><?php echo esc_html( (string) $row['last_error'] ); ?></td><td>
			<?php
			if ( $row['status'] === 'pending' ) :
				?>
			<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oct_cancel_job&job_id=' . (int) $row['id'] ), 'oct_cancel_job' ) ); ?>">Cancel</a><?php endif; ?></td></tr><?php endforeach; ?>
		<?php
		if ( ! $rows ) :
			?>
			<tr><td colspan="10">The queue is empty.</td></tr><?php endif; ?></tbody></table></div>
		<?php
	}
}
