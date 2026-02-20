<?php
/**
 * Core plugin class — registers all WordPress hooks.
 */

defined( 'ABSPATH' ) || exit;

class SCR_Plugin {

	private static ?self $instance = null;

	private SCR_Settings $settings;
	private SCR_Renderer $renderer;
	private SCR_Ajax     $ajax;

	private function __construct() {
		$this->settings = SCR_Settings::get_instance();
		$this->renderer = new SCR_Renderer( $this->settings );
		$this->ajax     = new SCR_Ajax( $this->settings );
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		// i18n — must run on 'init'.
		add_action( 'init', static function () {
			load_plugin_textdomain( 'simple-comment-ratings', false, dirname( SCR_BASENAME ) . '/languages' );
		} );

		// Register built-in icon sets before anything else reads the filter.
		add_filter( 'scr_icon_sets', [ $this, 'register_default_icon_sets' ], 1 );

		// Admin.
		if ( is_admin() ) {
			$this->settings->register_admin();
			add_filter( 'plugin_action_links_' . SCR_BASENAME, [ $this, 'add_settings_link' ] );
		}

		// Front-end.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'comment_text',       [ $this, 'append_vote_ui' ], 10, 2 );
		add_action( 'comment_form',        [ $this, 'render_turnstile_container' ] );

		// AJAX (both admin-ajax and front-end).
		$this->ajax->register();
	}

	// ---------------------------------------------------------------------------
	// Asset enqueueing
	// ---------------------------------------------------------------------------

	public function enqueue_assets(): void {
		if ( ! is_singular() || ! comments_open() ) {
			return;
		}

		wp_enqueue_style(
			'simple-comment-ratings',
			SCR_URL . 'assets/css/simple-comment-ratings.css',
			[],
			SCR_VERSION
		);

		// Font Awesome — only when the fontawesome icon set is active and the option is on.
		if ( 'fontawesome' === $this->settings->get( 'icon_set' ) && $this->settings->get( 'load_font_awesome' ) ) {
			$fa_url = apply_filters(
				'scr_font_awesome_url',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css'
			);
			wp_enqueue_style( 'font-awesome-6', $fa_url, [], false );
		}

		wp_enqueue_script(
			'simple-comment-ratings',
			SCR_URL . 'assets/js/simple-comment-ratings.js',
			[],
			SCR_VERSION,
			true  // load in footer
		);

		wp_localize_script( 'simple-comment-ratings', 'scrData', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'scr_vote' ),
			'sitekey'       => $this->settings->get( 'turnstile_sitekey' ),
			'countsLoading' => $this->settings->get( 'counts_loading' ),
			'postId'        => (string) get_queried_object_id(),
			'i18n'          => [
				'voteError'   => __( 'Vote failed. Please try again.', 'simple-comment-ratings' ),
				'alreadyVoted' => __( 'You have already voted on this comment.', 'simple-comment-ratings' ),
			],
		] );

		// Turnstile script — loaded only when a sitekey is configured.
		// render=explicit disables the Intersection Observer-based implicit
		// rendering so the challenge starts immediately when the script loads,
		// not only when the widget scrolls into the viewport.
		// onload= fires our JS as soon as Turnstile is ready.
		$sitekey = $this->settings->get( 'turnstile_sitekey' );
		if ( ! empty( $sitekey ) ) {
			wp_enqueue_script(
				'cf-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=scrOnTurnstileLoad',
				[],
				null,  // null = no ?ver= appended; false would add the WP version
				true   // footer
			);
		}
	}

	// ---------------------------------------------------------------------------
	// Comment text filter
	// ---------------------------------------------------------------------------

	public function append_vote_ui( string $text, $comment ): string {
		if ( is_admin() || ! is_singular() || ! ( $comment instanceof WP_Comment ) ) {
			return $text;
		}

		$comment_id = (int) $comment->comment_ID;
		$post_id    = (int) $comment->comment_post_ID;

		if ( ! $comment_id || ! $post_id ) {
			return $text;
		}

		if ( $this->is_commenter_excluded( $comment ) ) {
			return $text;
		}

		if ( $this->is_comment_past_cutoff( $comment ) ) {
			return $text;
		}

		return $text . $this->renderer->render( $comment_id, $post_id );
	}

	/**
	 * Returns true if vote buttons should be suppressed for this comment.
	 *
	 * Checks the excluded_emails list (case-insensitive) and the
	 * excluded_roles list (registered users only; guests are never role-excluded).
	 */
	private function is_commenter_excluded( WP_Comment $comment ): bool {
		// Email exclusions.
		$raw_emails = $this->settings->get( 'excluded_emails' );
		if ( ! empty( $raw_emails ) ) {
			$email = strtolower( trim( $comment->comment_author_email ) );
			if ( $email ) {
				$list = array_filter( array_map( 'trim', explode( "\n", strtolower( $raw_emails ) ) ) );
				if ( in_array( $email, $list, true ) ) {
					return true;
				}
			}
		}

		// Role exclusions — only meaningful for registered users.
		$excluded_roles = $this->settings->get( 'excluded_roles' );
		if ( ! empty( $excluded_roles ) && $comment->user_id > 0 ) {
			$user = get_userdata( (int) $comment->user_id );
			if ( $user && array_intersect( $user->roles, $excluded_roles ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true if the comment is older than the configured cutoff.
	 * Always returns false when vote_cutoff_days is 0 (feature disabled).
	 */
	private function is_comment_past_cutoff( WP_Comment $comment ): bool {
		$days = (int) $this->settings->get( 'vote_cutoff_days' );
		if ( $days <= 0 ) {
			return false;
		}
		return strtotime( $comment->comment_date_gmt ) < ( time() - $days * DAY_IN_SECONDS );
	}

	// ---------------------------------------------------------------------------
	// Turnstile widget (inside comment form)
	// ---------------------------------------------------------------------------

	/**
	 * Appended to the comment <form> body via the comment_form action.
	 *
	 * Placing it here means the widget lives inside the form where it is
	 * semantically associated with user interaction. The comment_form action
	 * only fires when a form is actually rendered, so no is_singular() guard
	 * is needed.
	 */
	public function render_turnstile_container(): void {
		$sitekey = $this->settings->get( 'turnstile_sitekey' );
		if ( empty( $sitekey ) ) {
			return;
		}

		// Plain div — no cf-turnstile class. With render=explicit the script
		// does not auto-scan; turnstile.render() is called from scrOnTurnstileLoad.
		echo '<div id="scr-turnstile-container"></div>' . "\n";
	}

	// ---------------------------------------------------------------------------
	// Icon sets
	// ---------------------------------------------------------------------------

	/**
	 * Register built-in icon sets via the scr_icon_sets filter.
	 *
	 * Third parties add sets the same way:
	 *   add_filter( 'scr_icon_sets', function( $sets ) {
	 *       $sets['arrows'] = [
	 *           'label' => 'Arrows',
	 *           'up'    => '<i class="fa-solid fa-arrow-up" aria-hidden="true"></i>',
	 *           'down'  => '<i class="fa-solid fa-arrow-down" aria-hidden="true"></i>',
	 *       ];
	 *       return $sets;
	 *   } );
	 */
	public function register_default_icon_sets( array $sets ): array {
		$sets['fontawesome'] = [
			'label' => __( 'Font Awesome — Thumbs', 'simple-comment-ratings' ),
			'up'    => '<i class="fa-solid fa-thumbs-up" aria-hidden="true"></i>',
			'down'  => '<i class="fa-solid fa-thumbs-down" aria-hidden="true"></i>',
		];
		return $sets;
	}

	// ---------------------------------------------------------------------------
	// Admin links
	// ---------------------------------------------------------------------------

	public function add_settings_link( array $links ): array {
		$url  = admin_url( 'options-general.php?page=' . SCR_Settings::OPTION_PAGE );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'simple-comment-ratings' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
}
