<?php
/**
 * Settings management for Simple Comment Ratings.
 *
 * All options are stored under a single serialized array in the 'scr_options'
 * WordPress option key. Use SCR_Settings::get_instance()->get( 'key' ) everywhere.
 */

defined( 'ABSPATH' ) || exit;

class SCR_Settings {

	const OPTION_GROUP = 'scr_settings';
	const OPTION_PAGE  = 'simple-comment-ratings';
	const OPTIONS_KEY  = 'scr_options';

	private static ?self $instance = null;

	private array $options;

	private array $defaults = [
		'turnstile_sitekey'   => '',
		'turnstile_secret'    => '',
		'display_position'    => 'bottom-right',
		'display_style'       => 'separate',
		'icon_set'            => 'fontawesome',
		'counts_loading'      => 'server_side',
		'load_font_awesome'   => true,
		'excluded_emails'     => '',
		'excluded_roles'      => [],
		'vote_cutoff_days'    => 30,
	];

	private function __construct() {
		$saved         = get_option( self::OPTIONS_KEY, [] );
		$this->options = wp_parse_args( (array) $saved, $this->defaults );
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get( string $key ) {
		return $this->options[ $key ] ?? $this->defaults[ $key ] ?? null;
	}

	// ---------------------------------------------------------------------------
	// Admin hooks
	// ---------------------------------------------------------------------------

	public function register_admin(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Simple Comment Ratings', 'simple-comment-ratings' ),
			__( '👍 Simple Comment Ratings', 'simple-comment-ratings' ),
			'manage_options',
			self::OPTION_PAGE,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTIONS_KEY,
			[ 'sanitize_callback' => [ $this, 'sanitize' ] ]
		);

		// ── Turnstile section ────────────────────────────────────────────────
		add_settings_section(
			'scr_turnstile',
			__( 'Cloudflare Turnstile', 'simple-comment-ratings' ),
			static function () {
				echo '<p>' . esc_html__( 'Leave blank to disable bot protection.', 'simple-comment-ratings' ) . '</p>';
			},
			self::OPTION_PAGE
		);

		add_settings_field(
			'turnstile_sitekey',
			__( 'Site Key', 'simple-comment-ratings' ),
			[ $this, 'field_text' ],
			self::OPTION_PAGE,
			'scr_turnstile',
			[ 'key' => 'turnstile_sitekey' ]
		);

		add_settings_field(
			'turnstile_secret',
			__( 'Secret Key', 'simple-comment-ratings' ),
			[ $this, 'field_text' ],
			self::OPTION_PAGE,
			'scr_turnstile',
			[ 'key' => 'turnstile_secret', 'type' => 'password' ]
		);

		// ── Display section ──────────────────────────────────────────────────
		add_settings_section(
			'scr_display',
			__( 'Display', 'simple-comment-ratings' ),
			'__return_null',
			self::OPTION_PAGE
		);

		add_settings_field(
			'display_position',
			__( 'Position', 'simple-comment-ratings' ),
			[ $this, 'field_select' ],
			self::OPTION_PAGE,
			'scr_display',
			[
				'key'     => 'display_position',
				'options' => [
					'bottom-right' => __( 'Bottom Right', 'simple-comment-ratings' ),
					'bottom-left'  => __( 'Bottom Left',  'simple-comment-ratings' ),
					'top-right'    => __( 'Top Right',    'simple-comment-ratings' ),
					'top-left'     => __( 'Top Left',     'simple-comment-ratings' ),
				],
			]
		);

		add_settings_field(
			'display_style',
			__( 'Vote Count Style', 'simple-comment-ratings' ),
			[ $this, 'field_select' ],
			self::OPTION_PAGE,
			'scr_display',
			[
				'key'     => 'display_style',
				'options' => [
					'separate' => __( 'Separate — count next to each button', 'simple-comment-ratings' ),
					'sum'      => __( 'Sum — net score between buttons',       'simple-comment-ratings' ),
				],
			]
		);

		add_settings_field(
			'icon_set',
			__( 'Icon Set', 'simple-comment-ratings' ),
			[ $this, 'field_select' ],
			self::OPTION_PAGE,
			'scr_display',
			[
				'key'     => 'icon_set',
				'options' => $this->get_icon_set_options(),
			]
		);

		add_settings_field(
			'counts_loading',
			__( 'Vote Counts Loading', 'simple-comment-ratings' ),
			[ $this, 'field_select' ],
			self::OPTION_PAGE,
			'scr_display',
			[
				'key'         => 'counts_loading',
				'description' => __( 'Server-side is faster and SEO-friendly. AJAX ensures counts are always current if multiple users vote simultaneously.', 'simple-comment-ratings' ),
				'options'     => [
					'server_side' => __( 'Server-side (embedded in HTML)', 'simple-comment-ratings' ),
					'ajax'        => __( 'AJAX (fetched after page render)', 'simple-comment-ratings' ),
				],
			]
		);

		add_settings_field(
			'load_font_awesome',
			__( 'Load Font Awesome', 'simple-comment-ratings' ),
			[ $this, 'field_checkbox' ],
			self::OPTION_PAGE,
			'scr_display',
			[
				'key'         => 'load_font_awesome',
				'label'       => __( 'Load Font Awesome 6 from CDN', 'simple-comment-ratings' ),
				'description' => __( 'Disable if your theme already loads Font Awesome.', 'simple-comment-ratings' ),
			]
		);

		// ── Exclusions section ───────────────────────────────────────────────
		add_settings_section(
			'scr_exclusions',
			__( 'Exclusions', 'simple-comment-ratings' ),
			static function () {
				echo '<p>' . esc_html__( 'Vote buttons will not be shown for comments matching these criteria.', 'simple-comment-ratings' ) . '</p>';
			},
			self::OPTION_PAGE
		);

		add_settings_field(
			'excluded_emails',
			__( 'Exclude by Email', 'simple-comment-ratings' ),
			[ $this, 'field_textarea' ],
			self::OPTION_PAGE,
			'scr_exclusions',
			[
				'key'         => 'excluded_emails',
				'description' => __( 'One email address per line. Case-insensitive.', 'simple-comment-ratings' ),
			]
		);

		add_settings_field(
			'excluded_roles',
			__( 'Exclude by Role', 'simple-comment-ratings' ),
			[ $this, 'field_checkboxes' ],
			self::OPTION_PAGE,
			'scr_exclusions',
			[
				'key'         => 'excluded_roles',
				'options'     => $this->get_role_options(),
				'description' => __( 'Hide vote buttons on comments posted by users with these roles. Does not affect guest comments.', 'simple-comment-ratings' ),
			]
		);

		add_settings_field(
			'vote_cutoff_days',
			__( 'Vote Cutoff (days)', 'simple-comment-ratings' ),
			[ $this, 'field_number' ],
			self::OPTION_PAGE,
			'scr_exclusions',
			[
				'key'         => 'vote_cutoff_days',
				'min'         => 0,
				'description' => __( 'Hide vote buttons on comments older than this many days. Set to 0 to always allow voting.', 'simple-comment-ratings' ),
			]
		);
	}

	// ---------------------------------------------------------------------------
	// Field renderers
	// ---------------------------------------------------------------------------

	public function field_text( array $args ): void {
		$key   = $args['key'];
		$type  = $args['type'] ?? 'text';
		$value = esc_attr( $this->options[ $key ] ?? '' );
		printf(
			'<input type="%s" name="%s[%s]" value="%s" class="regular-text" autocomplete="off">',
			esc_attr( $type ),
			esc_attr( self::OPTIONS_KEY ),
			esc_attr( $key ),
			$value
		);
	}

	public function field_checkbox( array $args ): void {
		$key         = $args['key'];
		$label       = $args['label'] ?? '';
		$description = $args['description'] ?? '';
		$checked     = ! empty( $this->options[ $key ] );

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s> %s</label>',
			esc_attr( self::OPTIONS_KEY ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $label )
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	public function field_textarea( array $args ): void {
		$key         = $args['key'];
		$value       = esc_textarea( $this->options[ $key ] ?? '' );
		$description = $args['description'] ?? '';

		printf(
			'<textarea name="%s[%s]" rows="4" class="large-text code">%s</textarea>',
			esc_attr( self::OPTIONS_KEY ),
			esc_attr( $key ),
			$value
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	public function field_number( array $args ): void {
		$key         = $args['key'];
		$value       = (int) ( $this->options[ $key ] ?? 0 );
		$min         = isset( $args['min'] ) ? (int) $args['min'] : 0;
		$description = $args['description'] ?? '';

		printf(
			'<input type="number" name="%s[%s]" value="%d" min="%d" class="small-text">',
			esc_attr( self::OPTIONS_KEY ),
			esc_attr( $key ),
			$value,
			$min
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	public function field_checkboxes( array $args ): void {
		$key         = $args['key'];
		$options     = $args['options'] ?? [];
		$description = $args['description'] ?? '';
		$current     = (array) ( $this->options[ $key ] ?? [] );

		foreach ( $options as $val => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s[%s][]" value="%s"%s> %s</label>',
				esc_attr( self::OPTIONS_KEY ),
				esc_attr( $key ),
				esc_attr( $val ),
				in_array( $val, $current, true ) ? ' checked' : '',
				esc_html( $label )
			);
		}

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	public function field_select( array $args ): void {
		$key         = $args['key'];
		$current     = $this->options[ $key ] ?? '';
		$opts        = $args['options'] ?? [];
		$description = $args['description'] ?? '';

		printf( '<select name="%s[%s]">', esc_attr( self::OPTIONS_KEY ), esc_attr( $key ) );
		foreach ( $opts as $val => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $current, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	// ---------------------------------------------------------------------------
	// Sanitization
	// ---------------------------------------------------------------------------

	public function sanitize( $input ): array {
		$input = (array) $input;
		$clean = [];

		$clean['turnstile_sitekey'] = sanitize_text_field( $input['turnstile_sitekey'] ?? '' );
		$clean['turnstile_secret']  = sanitize_text_field( $input['turnstile_secret']  ?? '' );

		$clean['display_position'] = in_array(
			$input['display_position'] ?? '',
			[ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ],
			true
		) ? $input['display_position'] : 'bottom-right';

		$clean['display_style'] = in_array(
			$input['display_style'] ?? '',
			[ 'separate', 'sum' ],
			true
		) ? $input['display_style'] : 'separate';

		$clean['icon_set'] = sanitize_key( $input['icon_set'] ?? 'fontawesome' );

		$clean['counts_loading'] = in_array(
			$input['counts_loading'] ?? '',
			[ 'server_side', 'ajax' ],
			true
		) ? $input['counts_loading'] : 'server_side';

		$clean['load_font_awesome'] = ! empty( $input['load_font_awesome'] );

		$clean['excluded_emails'] = sanitize_textarea_field( $input['excluded_emails'] ?? '' );

		$clean['vote_cutoff_days'] = absint( $input['vote_cutoff_days'] ?? 30 );

		$allowed_roles           = array_keys( wp_roles()->roles );
		$submitted_roles         = (array) ( $input['excluded_roles'] ?? [] );
		$clean['excluded_roles'] = array_values(
			array_filter( $submitted_roles, fn( $r ) => in_array( $r, $allowed_roles, true ) )
		);

		return $clean;
	}

	// ---------------------------------------------------------------------------
	// Page renderer
	// ---------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::OPTION_PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Build options array for the role checkboxes from installed WP roles.
	 */
	private function get_role_options(): array {
		$options = [];
		foreach ( wp_roles()->roles as $slug => $role ) {
			$options[ $slug ] = translate_user_role( $role['name'] );
		}
		return $options;
	}

	/**
	 * Build options array for the icon-set dropdown from the scr_icon_sets filter.
	 * Falls back to just Font Awesome if no sets are registered yet.
	 */
	private function get_icon_set_options(): array {
		$sets    = apply_filters( 'scr_icon_sets', [] );
		$options = [];

		foreach ( $sets as $key => $set ) {
			$options[ $key ] = $set['label'] ?? $key;
		}

		return $options ?: [ 'fontawesome' => __( 'Font Awesome (Thumbs)', 'simple-comment-ratings' ) ];
	}
}
