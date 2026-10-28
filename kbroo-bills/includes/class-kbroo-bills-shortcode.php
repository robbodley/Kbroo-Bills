<?php
/**
 * Front-end shortcodes:
 * [kbroo_bills]       the dashboard, for admins and Kbroo Bills Viewers.
 * [kbroo_bills_login] a login form, or a "logged in as" line with a log out link.
 */

defined( 'ABSPATH' ) || exit;

class Kbroo_Bills_Shortcode {

	const VIEW_CAP    = 'view_kbroo_bills';
	const VIEWER_ROLE = 'kbroo_bills_viewer';

	public static function init() {
		add_shortcode( 'kbroo_bills', array( __CLASS__, 'render' ) );
		add_shortcode( 'kbroo_bills_login', array( __CLASS__, 'render_login' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_for_viewers' ) );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
	}

	public static function can_view() {
		return current_user_can( self::VIEW_CAP ) || current_user_can( 'manage_options' );
	}

	private static function enqueue() {
		wp_enqueue_style( 'kbroo-bills', KBROO_BILLS_URL . 'assets/kbroo-bills.css', array(), KBROO_BILLS_VERSION );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return self::render_login();
		}
		if ( ! self::can_view() ) {
			self::enqueue();
			return '<div class="kb-login"><p>' . esc_html__( 'Your account does not have access to the accounts.', 'kbroo-bills' ) . '</p>'
				. '<p><a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '">' . esc_html__( 'Log out', 'kbroo-bills' ) . '</a></p></div>';
		}
		self::enqueue();
		return Kbroo_Bills_Dashboard::render();
	}

	public static function render_login( $atts = array() ) {
		$atts = shortcode_atts( array( 'redirect' => '' ), $atts, 'kbroo_bills_login' );
		$here = get_permalink();
		$to   = $atts['redirect'] ? $atts['redirect'] : $here;

		self::enqueue();

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return '<p class="kb-login-status">'
				. sprintf(
					/* translators: %s: user display name */
					esc_html__( 'Logged in as %s.', 'kbroo-bills' ),
					'<strong>' . esc_html( $user->display_name ) . '</strong>'
				)
				. ' <a href="' . esc_url( wp_logout_url( $here ) ) . '">' . esc_html__( 'Log out', 'kbroo-bills' ) . '</a></p>';
		}

		$failed = isset( $_GET['kb_login'] ) && 'failed' === $_GET['kb_login']; // phpcs:ignore WordPress.Security.NonceVerification

		$form = wp_login_form(
			array(
				'echo'     => false,
				'redirect' => esc_url_raw( $to ),
				'form_id'  => 'kb-login-form',
			)
		);
		// Marks the form so a failed login comes back here instead of wp-login.php.
		$form = str_replace( '</form>', '<input type="hidden" name="kb_login_form" value="1"></form>', $form );

		return '<div class="kb-login">'
			. '<h3>' . esc_html__( 'Log in to view the accounts', 'kbroo-bills' ) . '</h3>'
			. ( $failed ? '<p class="kb-neg">' . esc_html__( 'Wrong username or password. Please try again.', 'kbroo-bills' ) . '</p>' : '' )
			. $form
			. '</div>';
	}

	/**
	 * Sends a failed login from our form back to the page it was on.
	 */
	public static function login_failed() {
		if ( empty( $_POST['kb_login_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$back = wp_get_referer();
		if ( $back ) {
			wp_safe_redirect( add_query_arg( 'kb_login', 'failed', remove_query_arg( 'kb_login', $back ) ) );
			exit;
		}
	}

	/**
	 * Viewers only see the front-end page, so they don't need the admin bar.
	 */
	public static function hide_admin_bar_for_viewers( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) && current_user_can( self::VIEW_CAP ) ) {
			return false;
		}
		return $show;
	}
}
