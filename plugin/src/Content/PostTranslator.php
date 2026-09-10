<?php

declare(strict_types=1);

namespace OneClickTranslation\Content;

use RuntimeException;

final class PostTranslator {

	/** @param array<string,mixed> $fields */
	public function save( ?int $targetPostId, \WP_Post $source, array $fields ): int {
		$postarr = array(
			'post_title'     => (string) ( $fields['post_title'] ?? $source->post_title ),
			'post_content'   => (string) ( $fields['post_content'] ?? $source->post_content ),
			'post_excerpt'   => (string) ( $fields['post_excerpt'] ?? $source->post_excerpt ),
			'post_status'    => $source->post_status,
			'post_type'      => $source->post_type,
			'post_author'    => $source->post_author,
			'post_password'  => $source->post_password,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_parent'    => (int) ( $fields['post_parent'] ?? 0 ),
		);
		if ( ! empty( $fields['post_name'] ) ) {
			$postarr['post_name'] = wp_unique_post_slug(
				sanitize_title( (string) $fields['post_name'] ),
				$targetPostId ? $targetPostId : 0,
				(string) $source->post_status,
				(string) $source->post_type,
				(int) $postarr['post_parent']
			);
		}
		if ( $targetPostId ) {
			$postarr['ID'] = $targetPostId;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'Could not save translated post: ' . $result->get_error_message() );
		}
		return (int) $result;
	}
}
