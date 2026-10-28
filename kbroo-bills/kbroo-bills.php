<?php
/**
 * Plugin Name:       Kbroo Bills
 * Description:       Track client subscriptions paid through Stripe, monthly and yearly bills, and forecast your business bank balance for the year.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Kbroo
 * License:           GPL-2.0-or-later
 * Text Domain:       kbroo-bills
 */

defined( 'ABSPATH' ) || exit;

define( 'KBROO_BILLS_VERSION', '1.0.1' );
define( 'KBROO_BILLS_FILE', __FILE__ );
define( 'KBROO_BILLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'KBROO_BILLS_URL', plugin_dir_url( __FILE__ ) );

require_once KBROO_BILLS_DIR . 'includes/class-kbroo-bills-calculator.php';
require_once KBROO_BILLS_DIR . 'includes/class-kbroo-bills-db.php';
require_once KBROO_BILLS_DIR . 'includes/class-kbroo-bills-dashboard.php';
require_once KBROO_BILLS_DIR . 'includes/class-kbroo-bills-admin.php';
require_once KBROO_BILLS_DIR . 'includes/class-kbroo-bills-shortcode.php';

register_activation_hook( __FILE__, array( 'Kbroo_Bills_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		Kbroo_Bills_DB::maybe_upgrade();
		Kbroo_Bills_Shortcode::init();
		if ( is_admin() ) {
			new Kbroo_Bills_Admin();
		}
	}
);
