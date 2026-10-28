<?php
/**
 * Admin menu, screens and form handling.
 */

defined( 'ABSPATH' ) || exit;

class Kbroo_Bills_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		foreach ( array( 'save_item', 'delete_item', 'save_oneoff', 'delete_oneoff', 'save_settings', 'import_example' ) as $action ) {
			add_action( 'admin_post_kbroo_bills_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	public function menu() {
		add_menu_page( __( 'Kbroo Bills', 'kbroo-bills' ), __( 'Kbroo Bills', 'kbroo-bills' ), self::CAP, 'kbroo-bills', array( $this, 'page_dashboard' ), 'dashicons-chart-line', 56 );
		add_submenu_page( 'kbroo-bills', __( 'Dashboard', 'kbroo-bills' ), __( 'Dashboard', 'kbroo-bills' ), self::CAP, 'kbroo-bills', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'kbroo-bills', __( 'Clients', 'kbroo-bills' ), __( 'Clients', 'kbroo-bills' ), self::CAP, 'kbroo-bills-clients', array( $this, 'page_clients' ) );
		add_submenu_page( 'kbroo-bills', __( 'Bills', 'kbroo-bills' ), __( 'Bills', 'kbroo-bills' ), self::CAP, 'kbroo-bills-bills', array( $this, 'page_bills' ) );
		add_submenu_page( 'kbroo-bills', __( 'One-off entries', 'kbroo-bills' ), __( 'One-off entries', 'kbroo-bills' ), self::CAP, 'kbroo-bills-oneoffs', array( $this, 'page_oneoffs' ) );
		add_submenu_page( 'kbroo-bills', __( 'Settings', 'kbroo-bills' ), __( 'Settings', 'kbroo-bills' ), self::CAP, 'kbroo-bills-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false !== strpos( $hook, 'kbroo-bills' ) ) {
			wp_enqueue_style( 'kbroo-bills', KBROO_BILLS_URL . 'assets/kbroo-bills.css', array(), KBROO_BILLS_VERSION );
		}
	}

	/* ---------- Helpers ---------- */

	private function check( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'kbroo-bills' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function back( $page, $message ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'kb_msg' => $message ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notice() {
		$messages = array(
			'saved'    => __( 'Saved.', 'kbroo-bills' ),
			'deleted'  => __( 'Deleted.', 'kbroo-bills' ),
			'imported' => __( 'Example data from your spreadsheet has been added. Check the client charge days, as these were a guess.', 'kbroo-bills' ),
		);
		$key = isset( $_GET['kb_msg'] ) ? sanitize_key( wp_unslash( $_GET['kb_msg'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	private function months() {
		$out = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$out[ $m ] = date_i18n( 'F', mktime( 0, 0, 0, $m, 1, 2000 ) );
		}
		return $out;
	}

	private function ordinal( $n ) {
		$n = (int) $n;
		if ( in_array( $n % 100, array( 11, 12, 13 ), true ) ) {
			return $n . 'th';
		}
		$suffix = array( 'th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th' );
		return $n . $suffix[ $n % 10 ];
	}

	/* ---------- Pages ---------- */

	public function page_dashboard() {
		$has_data = (bool) Kbroo_Bills_DB::get_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kbroo Bills', 'kbroo-bills' ); ?></h1>
			<?php $this->notice(); ?>
			<?php if ( ! $has_data ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No clients or bills yet. Add them under Clients and Bills, or start with the figures from your spreadsheet:', 'kbroo-bills' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kbroo_bills_import_example' ); ?>
						<input type="hidden" name="action" value="kbroo_bills_import_example">
						<p><button class="button button-primary"><?php esc_html_e( 'Load my spreadsheet figures', 'kbroo-bills' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>
			<?php echo Kbroo_Bills_Dashboard::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			<p class="kb-muted"><?php esc_html_e( 'Tip: add the shortcode [kbroo_bills] to a page to see this dashboard on the front of your site. Visitors who are not logged in get a login form. Give someone the Kbroo Bills Viewer role to let them view it without changing anything.', 'kbroo-bills' ); ?></p>
		</div>
		<?php
	}

	public function page_clients() {
		$this->items_page( 'client' );
	}

	public function page_bills() {
		$this->items_page( 'bill' );
	}

	private function items_page( $kind ) {
		$is_client = 'client' === $kind;
		$page      = $is_client ? 'kbroo-bills-clients' : 'kbroo-bills-bills';
		$edit_id   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing   = $edit_id ? Kbroo_Bills_DB::get_item( $edit_id ) : null;
		$item      = $editing ? $editing : array(
			'name'         => '',
			'amount'       => '',
			'frequency'    => 'monthly',
			'day_of_month' => 1,
			'month'        => (int) current_time( 'n' ),
			'active'       => 1,
			'notes'        => '',
		);
		$items     = Kbroo_Bills_DB::get_items( $kind );
		$calc      = Kbroo_Bills_DB::calculator();
		$months    = $this->months();
		?>
		<div class="wrap">
			<h1><?php echo $is_client ? esc_html__( 'Clients', 'kbroo-bills' ) : esc_html__( 'Bills', 'kbroo-bills' ); ?></h1>
			<?php $this->notice(); ?>
			<p class="kb-muted">
				<?php
				echo $is_client
					? esc_html__( 'Clients who pay you through Stripe. Enter the amount you charge them; Stripe fees and the payout delay are worked out for you.', 'kbroo-bills' )
					: esc_html__( 'Things you pay for, monthly or once a year.', 'kbroo-bills' );
				?>
			</p>

			<div class="kb-two-col">
				<div class="kb-form-box">
					<h2>
						<?php
						if ( $editing ) {
							esc_html_e( 'Edit', 'kbroo-bills' );
						} else {
							echo $is_client ? esc_html__( 'Add a client', 'kbroo-bills' ) : esc_html__( 'Add a bill', 'kbroo-bills' );
						}
						?>
					</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kbroo_bills_save_item' ); ?>
						<input type="hidden" name="action" value="kbroo_bills_save_item">
						<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
						<input type="hidden" name="id" value="<?php echo esc_attr( $edit_id ); ?>">
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="kb-name"><?php esc_html_e( 'Name', 'kbroo-bills' ); ?></label></th>
								<td><input id="kb-name" name="name" type="text" class="regular-text" required value="<?php echo esc_attr( $item['name'] ); ?>"></td>
							</tr>
							<tr>
								<th><label for="kb-amount"><?php echo $is_client ? esc_html__( 'Amount charged', 'kbroo-bills' ) : esc_html__( 'Amount', 'kbroo-bills' ); ?></label></th>
								<td>
									<input id="kb-amount" name="amount" type="number" step="0.01" min="0" required value="<?php echo esc_attr( $item['amount'] ); ?>">
									<?php if ( $is_client ) : ?>
										<p class="description"><?php esc_html_e( 'What the client pays, before Stripe takes its fee.', 'kbroo-bills' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><label for="kb-frequency"><?php esc_html_e( 'How often', 'kbroo-bills' ); ?></label></th>
								<td>
									<select id="kb-frequency" name="frequency">
										<option value="monthly" <?php selected( $item['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'kbroo-bills' ); ?></option>
										<option value="yearly" <?php selected( $item['frequency'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'kbroo-bills' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="kb-day"><?php echo $is_client ? esc_html__( 'Charged on day', 'kbroo-bills' ) : esc_html__( 'Paid on day', 'kbroo-bills' ); ?></label></th>
								<td>
									<input id="kb-day" name="day_of_month" type="number" min="1" max="31" value="<?php echo esc_attr( $item['day_of_month'] ); ?>">
									<p class="description"><?php esc_html_e( 'Day of the month (1–31). Short months use their last day.', 'kbroo-bills' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="kb-month"><?php esc_html_e( 'Month (yearly only)', 'kbroo-bills' ); ?></label></th>
								<td>
									<select id="kb-month" name="month">
										<?php foreach ( $months as $num => $label ) : ?>
											<option value="<?php echo esc_attr( $num ); ?>" <?php selected( (int) $item['month'], $num ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Active', 'kbroo-bills' ); ?></th>
								<td><label><input name="active" type="checkbox" value="1" <?php checked( (int) $item['active'], 1 ); ?>> <?php esc_html_e( 'Include in totals', 'kbroo-bills' ); ?></label></td>
							</tr>
							<tr>
								<th><label for="kb-notes"><?php esc_html_e( 'Notes', 'kbroo-bills' ); ?></label></th>
								<td><textarea id="kb-notes" name="notes" rows="2" class="large-text"><?php echo esc_textarea( (string) $item['notes'] ); ?></textarea></td>
							</tr>
						</table>
						<p>
							<button class="button button-primary"><?php esc_html_e( 'Save', 'kbroo-bills' ); ?></button>
							<?php if ( $editing ) : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>"><?php esc_html_e( 'Cancel', 'kbroo-bills' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="kb-list-box">
					<table class="widefat striped kb-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'kbroo-bills' ); ?></th>
								<th class="num"><?php esc_html_e( 'Amount', 'kbroo-bills' ); ?></th>
								<?php if ( $is_client ) : ?>
									<th class="num"><?php esc_html_e( 'After fees', 'kbroo-bills' ); ?></th>
								<?php endif; ?>
								<th><?php esc_html_e( 'When', 'kbroo-bills' ); ?></th>
								<th class="num"><?php esc_html_e( 'Per year', 'kbroo-bills' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! $items ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'Nothing added yet.', 'kbroo-bills' ); ?></td></tr>
							<?php endif; ?>
							<?php
							$total_year = 0;
							foreach ( $items as $row ) :
								$times    = 'yearly' === $row['frequency'] ? 1 : 12;
								$per_pay  = $is_client ? (float) $row['amount'] - $calc->stripe_fee( $row['amount'] ) : (float) $row['amount'];
								$per_year = $per_pay * $times;
								if ( $row['active'] ) {
									$total_year += $per_year;
								}
								$when = 'yearly' === $row['frequency']
									/* translators: 1: day ordinal, 2: month name */
									? sprintf( __( 'Yearly, %1$s %2$s', 'kbroo-bills' ), $this->ordinal( $row['day_of_month'] ), $months[ (int) $row['month'] ] )
									/* translators: %s: day ordinal */
									: sprintf( __( 'Monthly, on the %s', 'kbroo-bills' ), $this->ordinal( $row['day_of_month'] ) );
								?>
								<tr class="<?php echo $row['active'] ? '' : 'kb-inactive'; ?>">
									<td>
										<strong><?php echo esc_html( $row['name'] ); ?></strong>
										<?php if ( ! $row['active'] ) : ?>
											<small class="kb-muted">(<?php esc_html_e( 'inactive', 'kbroo-bills' ); ?>)</small>
										<?php endif; ?>
										<?php if ( $row['notes'] ) : ?>
											<br><small class="kb-muted"><?php echo esc_html( $row['notes'] ); ?></small>
										<?php endif; ?>
									</td>
									<td class="num"><?php echo esc_html( Kbroo_Bills_Dashboard::money( $row['amount'] ) ); ?></td>
									<?php if ( $is_client ) : ?>
										<td class="num"><?php echo esc_html( Kbroo_Bills_Dashboard::money( $per_pay ) ); ?></td>
									<?php endif; ?>
									<td><?php echo esc_html( $when ); ?></td>
									<td class="num"><?php echo esc_html( Kbroo_Bills_Dashboard::money( $per_year ) ); ?></td>
									<td class="kb-actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&edit=' . $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'kbroo-bills' ); ?></a>
										|
										<a class="kb-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this?', 'kbroo-bills' ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kbroo_bills_delete_item&kind=' . $kind . '&id=' . $row['id'] ), 'kbroo_bills_delete_item' ) ); ?>"><?php esc_html_e( 'Delete', 'kbroo-bills' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<?php if ( $items ) : ?>
							<tfoot>
								<tr>
									<th colspan="<?php echo $is_client ? 4 : 3; ?>">
										<?php echo $is_client ? esc_html__( 'Total reaching the bank per year', 'kbroo-bills' ) : esc_html__( 'Total per year', 'kbroo-bills' ); ?>
									</th>
									<th class="num"><?php echo esc_html( Kbroo_Bills_Dashboard::money( $total_year ) ); ?></th>
									<th></th>
								</tr>
							</tfoot>
						<?php endif; ?>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	public function page_oneoffs() {
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? Kbroo_Bills_DB::get_oneoff( $edit_id ) : null;
		$entry   = $editing ? $editing : array(
			'entry_date'  => current_time( 'Y-m-d' ),
			'description' => '',
			'amount'      => '',
		);
		$is_out  = (float) $entry['amount'] < 0;
		$entries = Kbroo_Bills_DB::get_oneoffs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'One-off entries', 'kbroo-bills' ); ?></h1>
			<?php $this->notice(); ?>
			<p class="kb-muted"><?php esc_html_e( 'Anything that is not a regular client payment or bill: a transfer in or out, a refund, a one-off purchase, or a correction to match your bank.', 'kbroo-bills' ); ?></p>

			<div class="kb-two-col">
				<div class="kb-form-box">
					<h2><?php echo $editing ? esc_html__( 'Edit entry', 'kbroo-bills' ) : esc_html__( 'Add an entry', 'kbroo-bills' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kbroo_bills_save_oneoff' ); ?>
						<input type="hidden" name="action" value="kbroo_bills_save_oneoff">
						<input type="hidden" name="id" value="<?php echo esc_attr( $edit_id ); ?>">
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="kb-date"><?php esc_html_e( 'Date', 'kbroo-bills' ); ?></label></th>
								<td><input id="kb-date" name="entry_date" type="date" required value="<?php echo esc_attr( $entry['entry_date'] ); ?>"></td>
							</tr>
							<tr>
								<th><label for="kb-desc"><?php esc_html_e( 'Description', 'kbroo-bills' ); ?></label></th>
								<td><input id="kb-desc" name="description" type="text" class="regular-text" required value="<?php echo esc_attr( $entry['description'] ); ?>"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Money', 'kbroo-bills' ); ?></th>
								<td>
									<label><input type="radio" name="direction" value="in" <?php checked( ! $is_out ); ?>> <?php esc_html_e( 'In', 'kbroo-bills' ); ?></label>
									&nbsp;
									<label><input type="radio" name="direction" value="out" <?php checked( $is_out ); ?>> <?php esc_html_e( 'Out', 'kbroo-bills' ); ?></label>
								</td>
							</tr>
							<tr>
								<th><label for="kb-oamount"><?php esc_html_e( 'Amount', 'kbroo-bills' ); ?></label></th>
								<td><input id="kb-oamount" name="amount" type="number" step="0.01" min="0" required value="<?php echo '' === $entry['amount'] ? '' : esc_attr( abs( (float) $entry['amount'] ) ); ?>"></td>
							</tr>
						</table>
						<p>
							<button class="button button-primary"><?php esc_html_e( 'Save', 'kbroo-bills' ); ?></button>
							<?php if ( $editing ) : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=kbroo-bills-oneoffs' ) ); ?>"><?php esc_html_e( 'Cancel', 'kbroo-bills' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="kb-list-box">
					<table class="widefat striped kb-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'kbroo-bills' ); ?></th>
								<th><?php esc_html_e( 'Description', 'kbroo-bills' ); ?></th>
								<th class="num"><?php esc_html_e( 'Amount', 'kbroo-bills' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! $entries ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'No one-off entries.', 'kbroo-bills' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $entries as $row ) : ?>
								<tr>
									<td><?php echo esc_html( date_i18n( 'j M Y', strtotime( $row['entry_date'] ) ) ); ?></td>
									<td><?php echo esc_html( $row['description'] ); ?></td>
									<td class="num <?php echo (float) $row['amount'] < 0 ? 'kb-neg' : 'kb-pos'; ?>"><?php echo esc_html( Kbroo_Bills_Dashboard::money( $row['amount'], true ) ); ?></td>
									<td class="kb-actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=kbroo-bills-oneoffs&edit=' . $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'kbroo-bills' ); ?></a>
										|
										<a class="kb-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this?', 'kbroo-bills' ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kbroo_bills_delete_oneoff&id=' . $row['id'] ), 'kbroo_bills_delete_oneoff' ) ); ?>"><?php esc_html_e( 'Delete', 'kbroo-bills' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	public function page_settings() {
		$s = Kbroo_Bills_DB::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kbroo Bills settings', 'kbroo-bills' ); ?></h1>
			<?php $this->notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'kbroo_bills_save_settings' ); ?>
				<input type="hidden" name="action" value="kbroo_bills_save_settings">
				<h2><?php esc_html_e( 'Starting point', 'kbroo-bills' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="kb-start-date"><?php esc_html_e( 'Start date', 'kbroo-bills' ); ?></label></th>
						<td><input id="kb-start-date" name="start_date" type="date" value="<?php echo esc_attr( $s['start_date'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="kb-start-balance"><?php esc_html_e( 'Bank balance on that date', 'kbroo-bills' ); ?></label></th>
						<td>
							<input id="kb-start-balance" name="start_balance" type="number" step="0.01" value="<?php echo esc_attr( $s['start_balance'] ); ?>">
							<p class="description"><?php esc_html_e( 'To bring the forecast back in line with your bank, set today\'s date and today\'s real balance here.', 'kbroo-bills' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="kb-months"><?php esc_html_e( 'Months to forecast', 'kbroo-bills' ); ?></label></th>
						<td><input id="kb-months" name="forecast_months" type="number" min="1" max="36" value="<?php echo esc_attr( $s['forecast_months'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="kb-currency"><?php esc_html_e( 'Currency symbol', 'kbroo-bills' ); ?></label></th>
						<td><input id="kb-currency" name="currency" type="text" class="small-text" value="<?php echo esc_attr( $s['currency'] ); ?>"></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Stripe', 'kbroo-bills' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="kb-pct"><?php esc_html_e( 'Fee percentage', 'kbroo-bills' ); ?></label></th>
						<td><input id="kb-pct" name="stripe_percent" type="number" step="0.01" min="0" value="<?php echo esc_attr( $s['stripe_percent'] ); ?>"> %</td>
					</tr>
					<tr>
						<th><label for="kb-fixed"><?php esc_html_e( 'Fixed fee per payment', 'kbroo-bills' ); ?></label></th>
						<td>
							<input id="kb-fixed" name="stripe_fixed" type="number" step="0.01" min="0" value="<?php echo esc_attr( $s['stripe_fixed'] ); ?>">
							<p class="description"><?php esc_html_e( 'Stripe UK standard cards are 1.5% + 20p. Check a recent payment in your Stripe dashboard if you\'re not sure.', 'kbroo-bills' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="kb-delay"><?php esc_html_e( 'Days until money reaches the bank', 'kbroo-bills' ); ?></label></th>
						<td><input id="kb-delay" name="payout_delay" type="number" min="0" max="60" value="<?php echo esc_attr( $s['payout_delay'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------- Form handlers ---------- */

	public function handle_save_item() {
		$this->check( 'kbroo_bills_save_item' );
		$data = wp_unslash( $_POST );
		Kbroo_Bills_DB::save_item( $data, isset( $data['id'] ) ? absint( $data['id'] ) : 0 );
		$this->back( 'client' === $data['kind'] ? 'kbroo-bills-clients' : 'kbroo-bills-bills', 'saved' );
	}

	public function handle_delete_item() {
		$this->check( 'kbroo_bills_delete_item' );
		Kbroo_Bills_DB::delete_item( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$kind = isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '';
		$this->back( 'client' === $kind ? 'kbroo-bills-clients' : 'kbroo-bills-bills', 'deleted' );
	}

	public function handle_save_oneoff() {
		$this->check( 'kbroo_bills_save_oneoff' );
		$data = wp_unslash( $_POST );
		Kbroo_Bills_DB::save_oneoff( $data, isset( $data['id'] ) ? absint( $data['id'] ) : 0 );
		$this->back( 'kbroo-bills-oneoffs', 'saved' );
	}

	public function handle_delete_oneoff() {
		$this->check( 'kbroo_bills_delete_oneoff' );
		Kbroo_Bills_DB::delete_oneoff( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$this->back( 'kbroo-bills-oneoffs', 'deleted' );
	}

	public function handle_save_settings() {
		$this->check( 'kbroo_bills_save_settings' );
		Kbroo_Bills_DB::save_settings( wp_unslash( $_POST ) );
		$this->back( 'kbroo-bills-settings', 'saved' );
	}

	public function handle_import_example() {
		$this->check( 'kbroo_bills_import_example' );
		if ( ! Kbroo_Bills_DB::get_items() ) {
			Kbroo_Bills_DB::import_example();
		}
		$this->back( 'kbroo-bills', 'imported' );
	}
}
