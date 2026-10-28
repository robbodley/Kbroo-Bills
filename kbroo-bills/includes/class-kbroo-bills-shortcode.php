<?php
/**
 * [kbroo_bills] shortcode: shows the dashboard on any page, to admins only.
 */

defined( 'ABSPATH' ) || exit;

class Kbroo_Bills_Shortcode {

	public static function init() {
		add_shortcode( 'kbroo_bills', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'Please log in as an administrator to see the accounts.', 'kbroo-bills' ) . '</p>';
		}
		wp_enqueue_style( 'kbroo-bills', KBROO_BILLS_URL . 'assets/kbroo-bills.css', array(), KBROO_BILLS_VERSION );
		return Kbroo_Bills_Dashboard::render();
	}
}
