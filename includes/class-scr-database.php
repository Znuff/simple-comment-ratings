<?php
/**
 * Database layer for Simple Comment Ratings.
 *
 * Two-table design:
 *   - wp_scr        : audit log; enforces one-vote-per-IP via unique key.
 *   - wp_scr_counts : denormalized summary; PK on comment_id; updated
 *                     atomically with INSERT … ON DUPLICATE KEY UPDATE.
 *
 * All count reads are PK lookups against the counts table — no GROUP BY aggregation
 * at read time, regardless of vote volume.
 */

defined( 'ABSPATH' ) || exit;

class SCR_Database {

	// ---------------------------------------------------------------------------
	// Schema
	// ---------------------------------------------------------------------------

	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$ratings_table = $wpdb->prefix . 'scr';
		$counts_table  = $wpdb->prefix . 'scr_counts';

		$sql = "
			CREATE TABLE {$ratings_table} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				comment_id BIGINT UNSIGNED NOT NULL,
				ip_address VARCHAR(45)     NOT NULL,
				vote       TINYINT(1)      NOT NULL COMMENT '1=up, -1=down',
				created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY comment_ip (comment_id, ip_address)
			) {$charset};

			CREATE TABLE {$counts_table} (
				comment_id BIGINT UNSIGNED NOT NULL,
				up_count   INT UNSIGNED    NOT NULL DEFAULT 0,
				down_count INT UNSIGNED    NOT NULL DEFAULT 0,
				PRIMARY KEY (comment_id)
			) {$charset};
		";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'scr_db_version', SCR_VERSION );
	}

	// ---------------------------------------------------------------------------
	// Reads
	// ---------------------------------------------------------------------------

	/**
	 * Load all vote counts for approved comments on a post in one query.
	 *
	 * Uses a subquery against wp_comments so PHP never needs to build an ID list.
	 *
	 * @return array<int, array{up: int, down: int}>  Keyed by comment_id.
	 */
	public static function get_counts_for_post( int $post_id ): array {
		global $wpdb;

		$counts_table   = $wpdb->prefix . 'scr_counts';
		$comments_table = $wpdb->prefix . 'comments';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT crc.comment_id, crc.up_count, crc.down_count
				 FROM   {$counts_table} crc
				 WHERE  crc.comment_id IN (
				     SELECT comment_ID
				     FROM   {$comments_table}
				     WHERE  comment_post_ID = %d
				       AND  comment_approved = '1'
				 )",
				$post_id
			),
			ARRAY_A
		);

		$data = [];
		foreach ( $rows as $row ) {
			$data[ (int) $row['comment_id'] ] = [
				'up'   => (int) $row['up_count'],
				'down' => (int) $row['down_count'],
			];
		}

		return $data;
	}

	/**
	 * Load this IP's existing votes for all approved comments on a post.
	 *
	 * @return array<int, int>  comment_id => vote (1 or -1).
	 */
	public static function get_user_votes_for_post( int $post_id, string $ip ): array {
		global $wpdb;

		$ratings_table  = $wpdb->prefix . 'scr';
		$comments_table = $wpdb->prefix . 'comments';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_id, vote
				 FROM   {$ratings_table}
				 WHERE  ip_address = %s
				   AND  comment_id IN (
				       SELECT comment_ID
				       FROM   {$comments_table}
				       WHERE  comment_post_ID = %d
				         AND  comment_approved = '1'
				   )",
				$ip,
				$post_id
			),
			ARRAY_A
		);

		$data = [];
		foreach ( $rows as $row ) {
			$data[ (int) $row['comment_id'] ] = (int) $row['vote'];
		}

		return $data;
	}

	/**
	 * Check whether an IP has already voted on a specific comment.
	 *
	 * Point lookup on the composite unique key — O(log n).
	 *
	 * @return int|null  The existing vote (1 or -1), or null if not voted.
	 */
	public static function get_user_vote( int $comment_id, string $ip ): ?int {
		global $wpdb;

		$ratings_table = $wpdb->prefix . 'scr';

		$vote = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT vote FROM {$ratings_table}
				 WHERE comment_id = %d AND ip_address = %s",
				$comment_id,
				$ip
			)
		);

		return null !== $vote ? (int) $vote : null;
	}

	/**
	 * Get current counts for a single comment (used after recording a vote).
	 *
	 * PK lookup on the counts table.
	 *
	 * @return array{up: int, down: int}
	 */
	public static function get_counts( int $comment_id ): array {
		global $wpdb;

		$counts_table = $wpdb->prefix . 'scr_counts';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT up_count, down_count FROM {$counts_table} WHERE comment_id = %d",
				$comment_id
			),
			ARRAY_A
		);

		return [
			'up'   => $row ? (int) $row['up_count']   : 0,
			'down' => $row ? (int) $row['down_count'] : 0,
		];
	}

	// ---------------------------------------------------------------------------
	// Writes
	// ---------------------------------------------------------------------------

	/**
	 * Record a vote.
	 *
	 * Inserts into the audit log (fails silently on duplicate — caller should
	 * pre-check with get_user_vote). On success, atomically increments the
	 * appropriate counter in the counts table.
	 *
	 * @param  int    $comment_id
	 * @param  string $ip
	 * @param  int    $vote  1 for up, -1 for down.
	 * @return bool   False if the INSERT failed (e.g. already voted).
	 */
	public static function record_vote( int $comment_id, string $ip, int $vote ): bool {
		global $wpdb;

		$ratings_table = $wpdb->prefix . 'scr';
		$counts_table  = $wpdb->prefix . 'scr_counts';

		// Attempt to insert into audit log.
		$inserted = $wpdb->insert(
			$ratings_table,
			[
				'comment_id' => $comment_id,
				'ip_address' => $ip,
				'vote'       => $vote,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			return false;
		}

		// Atomically update the denormalized summary.
		$up_delta   = $vote === 1  ? 1 : 0;
		$down_delta = $vote === -1 ? 1 : 0;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$counts_table} (comment_id, up_count, down_count)
				 VALUES (%d, %d, %d)
				 ON DUPLICATE KEY UPDATE
				     up_count   = up_count   + VALUES(up_count),
				     down_count = down_count + VALUES(down_count)",
				$comment_id,
				$up_delta,
				$down_delta
			)
		);

		return true;
	}
}
