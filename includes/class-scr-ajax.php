<?php
/**
 * AJAX handlers for Simple Comment Ratings.
 *
 * scr_vote       — record a vote; verifies nonce + Turnstile token.
 * scr_get_counts — batch-load counts for all approved comments on a post;
 *                  used when counts_loading = 'ajax'.
 */

defined( 'ABSPATH' ) || exit;

class SCR_Ajax {

	private SCR_Settings $settings;

	public function __construct( SCR_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'wp_ajax_nopriv_scr_vote',       [ $this, 'handle_vote' ] );
		add_action( 'wp_ajax_scr_vote',              [ $this, 'handle_vote' ] );
		add_action( 'wp_ajax_nopriv_scr_get_counts', [ $this, 'handle_get_counts' ] );
		add_action( 'wp_ajax_scr_get_counts',        [ $this, 'handle_get_counts' ] );
	}

	// ---------------------------------------------------------------------------
	// Vote handler
	// ---------------------------------------------------------------------------

	public function handle_vote(): void {
		// Nonce.
		if ( ! check_ajax_referer( 'scr_vote', 'nonce', false ) ) {
			wp_send_json_error( $this->err( 'invalid_nonce', __( 'Invalid security token.', 'simple-comment-ratings' ) ), 403 );
		}

		// Turnstile (skipped if no secret configured).
		$secret = $this->settings->get( 'turnstile_secret' );
		if ( ! empty( $secret ) ) {
			$token = sanitize_text_field( wp_unslash( $_POST['turnstile_token'] ?? '' ) );
			if ( empty( $token ) ) {
				wp_send_json_error( $this->err( 'no_turnstile_token', __( 'Bot verification required.', 'simple-comment-ratings' ) ), 403 );
			}
			if ( ! $this->verify_turnstile( $token, $secret ) ) {
				wp_send_json_error( $this->err( 'turnstile_failed', __( 'Bot verification failed.', 'simple-comment-ratings' ) ), 403 );
			}
		}

		// Validate comment.
		$comment_id = absint( $_POST['comment_id'] ?? 0 );
		$comment    = $comment_id ? get_comment( $comment_id ) : null;

		if ( ! $comment || '1' !== $comment->comment_approved ) {
			wp_send_json_error( $this->err( 'invalid_comment', __( 'Invalid comment.', 'simple-comment-ratings' ) ), 400 );
		}

		// Validate vote direction.
		$vote_input = sanitize_text_field( wp_unslash( $_POST['vote'] ?? '' ) );
		if ( ! in_array( $vote_input, [ 'up', 'down' ], true ) ) {
			wp_send_json_error( $this->err( 'invalid_vote', __( 'Invalid vote value.', 'simple-comment-ratings' ) ), 400 );
		}

		$vote = $vote_input === 'up' ? 1 : -1;
		$ip   = $this->get_ip();

		// Duplicate check.
		$existing = SCR_Database::get_user_vote( $comment_id, $ip );
		if ( null !== $existing ) {
			wp_send_json_error(
				array_merge(
					$this->err( 'already_voted', __( 'You have already voted on this comment.', 'simple-comment-ratings' ) ),
					[ 'user_vote' => $existing ]
				),
				409
			);
		}

		// Record.
		if ( ! SCR_Database::record_vote( $comment_id, $ip, $vote ) ) {
			wp_send_json_error( $this->err( 'db_error', __( 'Failed to record vote.', 'simple-comment-ratings' ) ), 500 );
		}

		$counts = SCR_Database::get_counts( $comment_id );

		wp_send_json_success( [
			'up'        => $counts['up'],
			'down'      => $counts['down'],
			'sum'       => $counts['up'] - $counts['down'],
			'user_vote' => $vote,
		] );
	}

	// ---------------------------------------------------------------------------
	// Count loader (AJAX mode)
	// ---------------------------------------------------------------------------

	/**
	 * Returns vote counts and current user's votes for all approved comments
	 * on a post. Accepts post_id; uses a subquery internally so PHP never builds
	 * a comment ID list.
	 */
	public function handle_get_counts(): void {
		if ( ! check_ajax_referer( 'scr_vote', 'nonce', false ) ) {
			wp_send_json_error( $this->err( 'invalid_nonce', '' ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( $this->err( 'invalid_post', __( 'Invalid post.', 'simple-comment-ratings' ) ), 400 );
		}

		$ip     = $this->get_ip();
		$counts = SCR_Database::get_counts_for_post( $post_id );
		$voted  = SCR_Database::get_user_votes_for_post( $post_id, $ip );

		// Merge into a single response structure.
		$result = [];
		foreach ( $counts as $comment_id => $c ) {
			$result[ $comment_id ] = [
				'up'        => $c['up'],
				'down'      => $c['down'],
				'sum'       => $c['up'] - $c['down'],
				'user_vote' => $voted[ $comment_id ] ?? 0,
			];
		}

		// Include comments that have been voted on by this user but have no
		// counts row yet (edge case: counts table not yet written).
		foreach ( $voted as $comment_id => $v ) {
			if ( ! isset( $result[ $comment_id ] ) ) {
				$result[ $comment_id ] = [
					'up'        => 0,
					'down'      => 0,
					'sum'       => 0,
					'user_vote' => $v,
				];
			}
		}

		wp_send_json_success( $result );
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function verify_turnstile( string $token, string $secret ): bool {
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			[
				'body'    => [
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $this->get_ip(),
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ! empty( $body['success'] );
	}

	private function get_ip(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}

	private function err( string $code, string $message ): array {
		return [ 'code' => $code, 'message' => $message ];
	}
}
