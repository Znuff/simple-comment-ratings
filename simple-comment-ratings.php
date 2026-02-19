<?php
/**
 * Plugin Name: Simple Comment Ratings
 * Description: Allow visitors to rate comments with thumbs up/down. Includes Cloudflare Turnstile bot protection.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: simple-comment-ratings
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SCR_VERSION',  '1.0.0' );
define( 'SCR_PATH',     plugin_dir_path( __FILE__ ) );
define( 'SCR_URL',      plugin_dir_url( __FILE__ ) );
define( 'SCR_BASENAME', plugin_basename( __FILE__ ) );

require_once SCR_PATH . 'includes/class-scr-database.php';
require_once SCR_PATH . 'includes/class-scr-settings.php';
require_once SCR_PATH . 'includes/class-scr-renderer.php';
require_once SCR_PATH . 'includes/class-scr-ajax.php';
require_once SCR_PATH . 'includes/class-scr-plugin.php';

register_activation_hook( __FILE__, [ 'SCR_Database', 'create_tables' ] );

add_action( 'plugins_loaded', [ 'SCR_Plugin', 'get_instance' ] );
