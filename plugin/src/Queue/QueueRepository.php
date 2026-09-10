<?php

declare(strict_types=1);

namespace OneClickTranslation\Queue;

final class QueueRepository {

	public function enqueue( int $sourceId, string $targetLanguage, string $provider, array $payload = array(), int $priority = 10 ): int {
		global $wpdb;
		$table    = $wpdb->prefix . 'oct_queue';
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_object_id=%d AND object_type='post' AND target_language=%s AND status IN ('pending','processing') LIMIT 1",
				$sourceId,
				$targetLanguage
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return $existing;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'source_object_id' => $sourceId,
				'object_type'      => 'post',
				'target_language'  => sanitize_key( $targetLanguage ),
				'provider'         => sanitize_key( $provider ),
				'status'           => 'pending',
				'priority'         => $priority,
				'attempts'         => 0,
				'payload'          => wp_json_encode( $payload ),
				'created_at'       => $now,
				'available_at'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function claimNext(): ?QueueJob {
		global $wpdb;
		$table = $wpdb->prefix . 'oct_queue';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='pending', started_at=NULL, available_at=%s, last_error=%s WHERE status='processing' AND started_at < %s",
				current_time( 'mysql', true ),
				'Worker interrupted; job recovered.',
				gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status='pending' AND available_at <= %s ORDER BY priority ASC, id ASC LIMIT 1 FOR UPDATE",
				current_time( 'mysql', true )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			$wpdb->query( 'COMMIT' );
			return null;
		}
		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'processing',
				'started_at' => current_time( 'mysql', true ),
				'attempts'   => (int) $row['attempts'] + 1,
			),
			array(
				'id'     => (int) $row['id'],
				'status' => 'pending',
			),
			array( '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);
		if ( $updated !== 1 ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}
		$wpdb->query( 'COMMIT' );
		$row['status']   = 'processing';
		$row['attempts'] = (int) $row['attempts'] + 1;
		return QueueJob::fromRow( $row );
	}

	public function complete( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'oct_queue',
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
				'last_error'   => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function fail( QueueJob $job, string $error, int $maxAttempts ): string {
		global $wpdb;
		$retry     = $job->attempts < $maxAttempts;
		$status    = $retry ? 'pending' : 'failed';
		$delay     = min( 3600, 60 * ( 2 ** max( 0, $job->attempts - 1 ) ) );
		$available = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$wpdb->update(
			$wpdb->prefix . 'oct_queue',
			array(
				'status'       => $status,
				'last_error'   => mb_substr( wp_strip_all_tags( $error ), 0, 65535 ),
				'available_at' => $available,
				'completed_at' => $retry ? null : current_time( 'mysql', true ),
			),
			array( 'id' => $job->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return $status;
	}

	/** @return array<string,int> */
	public function counts(): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'oct_queue';
		$rows   = $wpdb->get_results( "SELECT status,COUNT(*) count FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'cancelled'  => 0,
		);
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['count']; }
		return $counts;
	}

	/** @return list<array<string,mixed>> */
	public function list( int $limit = 50, int $offset = 0, ?string $status = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'oct_queue';
		if ( $status ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status=%s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function clearCompleted(): int {
		global $wpdb;
		return (int) $wpdb->delete( $wpdb->prefix . 'oct_queue', array( 'status' => 'completed' ), array( '%s' ) );
	}

	public function cancel( int $id ): bool {
		global $wpdb;
		return $wpdb->update(
			$wpdb->prefix . 'oct_queue',
			array( 'status' => 'cancelled' ),
			array(
				'id'     => $id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		) === 1;
	}
}
