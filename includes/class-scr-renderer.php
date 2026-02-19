<?php
/**
 * HTML renderer for the vote widget.
 *
 * The first time render() is called for a post, all vote counts and the
 * current user's votes for that post are loaded in two queries (one for
 * counts, one for the user's IP). Subsequent calls for the same post hit
 * an in-memory static cache — zero additional DB queries.
 *
 * In 'ajax' counts_loading mode, the HTML is rendered with empty count
 * placeholders; JS fills them in after page load.
 */

defined( 'ABSPATH' ) || exit;

class SCR_Renderer {

	private SCR_Settings $settings;

	/** @var array<int, array{up: int, down: int}>  counts cache keyed by post_id */
	private static array $counts_cache = [];

	/** @var array<int, array<int, int>>  user-vote cache keyed by post_id */
	private static array $user_vote_cache = [];

	/** @var int[]  post IDs whose cache has been populated */
	private static array $loaded_posts = [];

	public function __construct( SCR_Settings $settings ) {
		$this->settings = $settings;
	}

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Generate the vote widget HTML for a comment.
	 * Appended to comment text via the comment_text filter.
	 */
	public function render( int $comment_id, int $post_id ): string {
		$loading = $this->settings->get( 'counts_loading' );
		$style   = $this->settings->get( 'display_style' );
		$pos     = $this->settings->get( 'display_position' );

		$up_count   = 0;
		$down_count = 0;
		$user_vote  = 0;

		if ( 'server_side' === $loading ) {
			$this->maybe_load_cache( $post_id );

			$counts    = self::$counts_cache[ $post_id ][ $comment_id ]    ?? [ 'up' => 0, 'down' => 0 ];
			$up_count  = $counts['up'];
			$down_count = $counts['down'];
			$user_vote = self::$user_vote_cache[ $post_id ][ $comment_id ] ?? 0;
		}

		$has_voted   = $user_vote !== 0;
		$pos_class   = 'scr-pos-' . sanitize_html_class( $pos );
		$voted_class = $user_vote === 1 ? 'scr-voted-up' : ( $user_vote === -1 ? 'scr-voted-down' : '' );
		$ajax_class  = 'ajax' === $loading ? 'scr-ajax-counts' : '';
		$disabled    = $has_voted ? ' disabled' : '';
		$up_active   = $user_vote === 1  ? ' scr-active' : '';
		$dn_active   = $user_vote === -1 ? ' scr-active' : '';

		$icon_up   = $this->get_icon( 'up' );
		$icon_down = $this->get_icon( 'down' );

		if ( 'ajax' === $loading ) {
			$up_html  = '<span class="scr-count-up" aria-live="polite"></span>';
			$dn_html  = '<span class="scr-count-down" aria-live="polite"></span>';
			$sum_html = '<span class="scr-count-sum" aria-live="polite"></span>';
		} else {
			$up_html  = '<span class="scr-count-up" aria-live="polite">'  . absint( $up_count )   . '</span>';
			$dn_html  = '<span class="scr-count-down" aria-live="polite">' . absint( $down_count ) . '</span>';
			$sum_html = '<span class="scr-count-sum" aria-live="polite">'  . intval( $up_count - $down_count ) . '</span>';
		}

		$inner = $this->build_buttons( $style, $icon_up, $icon_down, $up_html, $dn_html, $sum_html, $up_active, $dn_active, $disabled );

		return sprintf(
			'<div class="scr-vote-container %s %s %s" data-comment-id="%d" data-post-id="%d" data-style="%s">
				<div class="scr-votes-wrapper" role="group" aria-label="%s">%s</div>
			</div>',
			esc_attr( trim( "$pos_class $voted_class $ajax_class" ) ),
			'',
			'',
			$comment_id,
			$post_id,
			esc_attr( $style ),
			esc_attr__( 'Comment votes', 'simple-comment-ratings' ),
			$inner
		);
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	private function build_buttons(
		string $style,
		string $icon_up,
		string $icon_down,
		string $up_count_html,
		string $dn_count_html,
		string $sum_html,
		string $up_active,
		string $dn_active,
		string $disabled
	): string {
		$label_up   = esc_attr__( 'Vote up',   'simple-comment-ratings' );
		$label_down = esc_attr__( 'Vote down', 'simple-comment-ratings' );

		if ( 'separate' === $style ) {
			return sprintf(
				'<button class="scr-vote-btn scr-vote-up%s"   data-vote="up"   aria-label="%s"%s>%s%s</button>'
				. '<button class="scr-vote-btn scr-vote-down%s" data-vote="down" aria-label="%s"%s>%s%s</button>',
				$up_active, $label_up,   $disabled, $icon_up,   $up_count_html,
				$dn_active, $label_down, $disabled, $dn_count_html, $icon_down
			);
		}

		// sum style: icon | score | icon
		return sprintf(
			'<button class="scr-vote-btn scr-vote-up%s"   data-vote="up"   aria-label="%s"%s>%s</button>'
			. '%s'
			. '<button class="scr-vote-btn scr-vote-down%s" data-vote="down" aria-label="%s"%s>%s</button>',
			$up_active, $label_up,   $disabled, $icon_up,
			$sum_html,
			$dn_active, $label_down, $disabled, $icon_down
		);
	}

	/**
	 * Get icon HTML for a given direction from the active icon set.
	 *
	 * Falls back to Font Awesome if the selected set isn't registered.
	 */
	private function get_icon( string $direction ): string {
		$icon_set = $this->settings->get( 'icon_set' );
		$sets     = apply_filters( 'scr_icon_sets', [] );

		if ( isset( $sets[ $icon_set ][ $direction ] ) ) {
			return $sets[ $icon_set ][ $direction ];
		}

		// Hard fallback (Font Awesome 6 Free).
		$fa = [
			'up'   => '<i class="fa-solid fa-thumbs-up" aria-hidden="true"></i>',
			'down' => '<i class="fa-solid fa-thumbs-down" aria-hidden="true"></i>',
		];

		return $fa[ $direction ] ?? '';
	}

	/**
	 * Populate the static cache for a post if not already done.
	 * Runs at most once per post per request.
	 */
	private function maybe_load_cache( int $post_id ): void {
		if ( in_array( $post_id, self::$loaded_posts, true ) ) {
			return;
		}

		$ip = self::get_ip();

		self::$counts_cache[ $post_id ]    = SCR_Database::get_counts_for_post( $post_id );
		self::$user_vote_cache[ $post_id ] = SCR_Database::get_user_votes_for_post( $post_id, $ip );
		self::$loaded_posts[]              = $post_id;
	}

	public static function get_ip(): string {
		return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	}
}
