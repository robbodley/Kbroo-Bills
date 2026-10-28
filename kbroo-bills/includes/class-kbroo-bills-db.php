<?php
/**
 * Database tables, settings and data access.
 */

defined( 'ABSPATH' ) || exit;

class Kbroo_Bills_DB {

	const DB_VERSION   = '2';
	const SETTINGS_KEY = 'kbroo_bills_settings';

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'kbroo_items';
	}

	public static function oneoffs_table() {
		global $wpdb;
		return $wpdb->prefix . 'kbroo_oneoffs';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$items   = self::items_table();
		$oneoffs = self::oneoffs_table();

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL DEFAULT 'bill',
				name varchar(190) NOT NULL DEFAULT '',
				amount decimal(10,2) NOT NULL DEFAULT 0,
				frequency varchar(10) NOT NULL DEFAULT 'monthly',
				day_of_month tinyint(2) unsigned NOT NULL DEFAULT 1,
				month tinyint(2) unsigned NOT NULL DEFAULT 1,
				active tinyint(1) NOT NULL DEFAULT 1,
				notes text NULL,
				PRIMARY KEY  (id),
				KEY kind (kind)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$oneoffs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entry_date date NOT NULL,
				description varchar(190) NOT NULL DEFAULT '',
				amount decimal(10,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY entry_date (entry_date)
			) {$charset};"
		);

		add_option( self::SETTINGS_KEY, self::default_settings() );
		self::add_roles();
		update_option( 'kbroo_bills_db_version', self::DB_VERSION );
	}

	/**
	 * A low-level role that can log in and view the [kbroo_bills] page but
	 * cannot change anything.
	 */
	public static function add_roles() {
		add_role(
			Kbroo_Bills_Shortcode::VIEWER_ROLE,
			__( 'Kbroo Bills Viewer', 'kbroo-bills' ),
			array(
				'read'                          => true,
				Kbroo_Bills_Shortcode::VIEW_CAP => true,
			)
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( Kbroo_Bills_Shortcode::VIEW_CAP );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'kbroo_bills_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function default_settings() {
		return array(
			'start_date'      => gmdate( 'Y' ) . '-01-01',
			'start_balance'   => 0,
			'forecast_months' => 12,
			'stripe_percent'  => 1.5,
			'stripe_fixed'    => 0.20,
			'payout_delay'    => 3,
			'currency'        => '£',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::SETTINGS_KEY, array() ), self::default_settings() );
	}

	public static function save_settings( array $raw ) {
		$s = self::settings();

		$date = isset( $raw['start_date'] ) ? sanitize_text_field( $raw['start_date'] ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$s['start_date'] = $date;
		}
		$s['start_balance']   = isset( $raw['start_balance'] ) ? round( (float) $raw['start_balance'], 2 ) : 0;
		$s['forecast_months'] = isset( $raw['forecast_months'] ) ? min( 36, max( 1, (int) $raw['forecast_months'] ) ) : 12;
		$s['stripe_percent']  = isset( $raw['stripe_percent'] ) ? max( 0, (float) $raw['stripe_percent'] ) : 0;
		$s['stripe_fixed']    = isset( $raw['stripe_fixed'] ) ? max( 0, (float) $raw['stripe_fixed'] ) : 0;
		$s['payout_delay']    = isset( $raw['payout_delay'] ) ? min( 60, max( 0, (int) $raw['payout_delay'] ) ) : 0;
		$s['currency']        = isset( $raw['currency'] ) ? sanitize_text_field( $raw['currency'] ) : '£';

		update_option( self::SETTINGS_KEY, $s );
	}

	/* ---------- Clients and bills ---------- */

	public static function get_items( $kind = null ) {
		global $wpdb;
		$table = self::items_table();
		if ( $kind ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s ORDER BY active DESC, frequency ASC, day_of_month ASC, name ASC", $kind ),
				ARRAY_A
			);
		}
		return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
	}

	public static function get_item( $id ) {
		global $wpdb;
		$table = self::items_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public static function save_item( array $raw, $id = 0 ) {
		global $wpdb;
		$data = array(
			'kind'         => ( isset( $raw['kind'] ) && 'client' === $raw['kind'] ) ? 'client' : 'bill',
			'name'         => isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '',
			'amount'       => isset( $raw['amount'] ) ? round( abs( (float) $raw['amount'] ), 2 ) : 0,
			'frequency'    => ( isset( $raw['frequency'] ) && 'yearly' === $raw['frequency'] ) ? 'yearly' : 'monthly',
			'day_of_month' => isset( $raw['day_of_month'] ) ? min( 31, max( 1, (int) $raw['day_of_month'] ) ) : 1,
			'month'        => isset( $raw['month'] ) ? min( 12, max( 1, (int) $raw['month'] ) ) : 1,
			'active'       => empty( $raw['active'] ) ? 0 : 1,
			'notes'        => isset( $raw['notes'] ) ? sanitize_textarea_field( $raw['notes'] ) : '',
		);
		$formats = array( '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%s' );

		if ( $id ) {
			$wpdb->update( self::items_table(), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );
			return (int) $id;
		}
		$wpdb->insert( self::items_table(), $data, $formats );
		return (int) $wpdb->insert_id;
	}

	public static function delete_item( $id ) {
		global $wpdb;
		$wpdb->delete( self::items_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* ---------- One-off entries ---------- */

	public static function get_oneoffs() {
		global $wpdb;
		$table = self::oneoffs_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY entry_date ASC, id ASC", ARRAY_A );
	}

	public static function get_oneoff( $id ) {
		global $wpdb;
		$table = self::oneoffs_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public static function save_oneoff( array $raw, $id = 0 ) {
		global $wpdb;
		$date = isset( $raw['entry_date'] ) ? sanitize_text_field( $raw['entry_date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		$amount = isset( $raw['amount'] ) ? round( abs( (float) $raw['amount'] ), 2 ) : 0;
		if ( isset( $raw['direction'] ) && 'out' === $raw['direction'] ) {
			$amount = -$amount;
		}
		$data    = array(
			'entry_date'  => $date,
			'description' => isset( $raw['description'] ) ? sanitize_text_field( $raw['description'] ) : '',
			'amount'      => $amount,
		);
		$formats = array( '%s', '%s', '%f' );

		if ( $id ) {
			$wpdb->update( self::oneoffs_table(), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );
			return (int) $id;
		}
		$wpdb->insert( self::oneoffs_table(), $data, $formats );
		return (int) $wpdb->insert_id;
	}

	public static function delete_oneoff( $id ) {
		global $wpdb;
		$wpdb->delete( self::oneoffs_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* ---------- Helpers ---------- */

	public static function calculator() {
		return new Kbroo_Bills_Calculator( self::get_items(), self::get_oneoffs(), self::settings() );
	}

	/**
	 * Loads the clients and bills from the original Kbroo spreadsheet so there
	 * is something to start from. Charge days for clients are a guess.
	 */
	public static function import_example() {
		$year = (int) gmdate( 'Y' );

		$clients = array(
			array( 'J Kilburn', 30, 'monthly', 1, 1 ),
			array( 'Morgan', 12, 'monthly', 1, 1 ),
			array( 'Arctic Smart', 12, 'monthly', 1, 1 ),
			array( 'Mphigh Class', 12, 'monthly', 1, 1 ),
			array( 'Julia Tolley', 12, 'monthly', 1, 1 ),
			array( 'Rob', 45, 'monthly', 1, 1 ),
			array( 'Paul & Helen', 144, 'yearly', 5, 3 ),
		);
		$bills   = array(
			array( 'Anthropic', 18, 'monthly', 2, 1 ),
			array( '1Password', 4.5, 'monthly', 4, 1 ),
			array( 'Adobe Photography', 20, 'monthly', 14, 1 ),
			array( 'Account charges', 10, 'monthly', 19, 1 ),
			array( '20i', 18, 'monthly', 26, 1 ),
			array( '20i', 48, 'monthly', 29, 1 ),
			array( 'Kbroo domain', 16, 'yearly', 18, 5 ),
			array( 'Elementor', 110, 'yearly', 14, 8 ),
			array( 'Kbroo domain', 16, 'yearly', 20, 9 ),
		);

		foreach ( array( 'client' => $clients, 'bill' => $bills ) as $kind => $rows ) {
			foreach ( $rows as $r ) {
				self::save_item(
					array(
						'kind'         => $kind,
						'name'         => $r[0],
						'amount'       => $r[1],
						'frequency'    => $r[2],
						'day_of_month' => $r[3],
						'month'        => $r[4],
						'active'       => 1,
					)
				);
			}
		}

		self::save_oneoff(
			array(
				'entry_date'  => $year . '-10-30',
				'description' => 'Adjustment',
				'amount'      => 100,
				'direction'   => 'out',
			)
		);

		$s = self::settings();
		self::save_settings(
			array_merge(
				$s,
				array(
					'start_date'    => $year . '-01-01',
					'start_balance' => 50,
				)
			)
		);
	}
}
