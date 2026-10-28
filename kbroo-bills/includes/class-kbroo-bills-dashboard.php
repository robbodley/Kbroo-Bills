<?php
/**
 * Dashboard HTML, shared by the admin screen and the [kbroo_bills] shortcode.
 */

defined( 'ABSPATH' ) || exit;

class Kbroo_Bills_Dashboard {

	public static function money( $amount, $signed = false ) {
		$s      = Kbroo_Bills_DB::settings();
		$amount = round( (float) $amount, 2 );
		$sign   = $amount < 0 ? '-' : ( $signed && $amount > 0 ? '+' : '' );
		return $sign . $s['currency'] . number_format( abs( $amount ), 2 );
	}

	private static function tone( $amount ) {
		return $amount < 0 ? 'kb-neg' : 'kb-pos';
	}

	public static function render() {
		$calc     = Kbroo_Bills_DB::calculator();
		$settings = Kbroo_Bills_DB::settings();
		$sum      = $calc->annual_summary();
		$fc       = $calc->forecast();
		$today    = current_time( 'Y-m-d' );
		$soon     = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );

		ob_start();
		?>
		<div class="kb-wrap">

			<div class="kb-cards">
				<div class="kb-card">
					<span class="kb-card-label"><?php esc_html_e( 'Income per year (after Stripe fees)', 'kbroo-bills' ); ?></span>
					<strong><?php echo esc_html( self::money( $sum['income_net'] ) ); ?></strong>
					<small>
						<?php
						printf(
							/* translators: 1: gross income, 2: stripe fees */
							esc_html__( '%1$s charged, %2$s Stripe fees', 'kbroo-bills' ),
							esc_html( self::money( $sum['income_gross'] ) ),
							esc_html( self::money( $sum['fees'] ) )
						);
						?>
					</small>
				</div>
				<div class="kb-card">
					<span class="kb-card-label"><?php esc_html_e( 'Bills per year', 'kbroo-bills' ); ?></span>
					<strong><?php echo esc_html( self::money( $sum['bills_total'] ) ); ?></strong>
					<small>
						<?php
						printf(
							/* translators: 1: monthly bills total, 2: yearly bills total */
							esc_html__( '%1$s monthly bills, %2$s yearly bills', 'kbroo-bills' ),
							esc_html( self::money( $sum['bills_monthly'] ) ),
							esc_html( self::money( $sum['bills_yearly'] ) )
						);
						?>
					</small>
				</div>
				<div class="kb-card <?php echo esc_attr( self::tone( $sum['profit'] ) ); ?>">
					<span class="kb-card-label"><?php echo $sum['profit'] < 0 ? esc_html__( 'Loss per year', 'kbroo-bills' ) : esc_html__( 'Profit per year', 'kbroo-bills' ); ?></span>
					<strong><?php echo esc_html( self::money( $sum['profit'] ) ); ?></strong>
					<small>
						<?php
						printf(
							/* translators: %s: profit per month */
							esc_html__( '%s per month on average', 'kbroo-bills' ),
							esc_html( self::money( $sum['profit_month'] ) )
						);
						?>
					</small>
				</div>
				<div class="kb-card <?php echo esc_attr( self::tone( $fc['lowest_balance'] ) ); ?>">
					<span class="kb-card-label"><?php esc_html_e( 'Lowest bank balance', 'kbroo-bills' ); ?></span>
					<strong><?php echo esc_html( self::money( $fc['lowest_balance'] ) ); ?></strong>
					<small>
						<?php
						printf(
							/* translators: %s: date */
							esc_html__( 'on %s', 'kbroo-bills' ),
							esc_html( date_i18n( 'j M Y', strtotime( $fc['lowest_date'] ) ) )
						);
						?>
					</small>
				</div>
			</div>

			<div class="kb-advice">
				<h3><?php esc_html_e( 'What this means', 'kbroo-bills' ); ?></h3>
				<ul>
					<li>
						<?php
						printf(
							/* translators: 1: income per month, 2: bills per month */
							esc_html__( 'On average %1$s a month reaches the bank and bills cost %2$s a month.', 'kbroo-bills' ),
							'<strong>' . esc_html( self::money( $sum['income_net_month'] ) ) . '</strong>',
							'<strong>' . esc_html( self::money( $sum['bills_month'] ) ) . '</strong>'
						);
						?>
					</li>
					<?php if ( $sum['bills_yearly'] > 0 ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: monthly set aside amount, 2: yearly bills total */
								esc_html__( 'Keep %1$s a month aside for yearly bills (%2$s a year), so they never catch you short.', 'kbroo-bills' ),
								'<strong>' . esc_html( self::money( $sum['set_aside_month'] ) ) . '</strong>',
								esc_html( self::money( $sum['bills_yearly'] ) )
							);
							?>
						</li>
					<?php endif; ?>
					<?php if ( $sum['profit'] < 0 ) : ?>
						<li class="kb-neg">
							<?php
							printf(
								/* translators: 1: yearly shortfall, 2: monthly shortfall */
								esc_html__( 'Bills cost more than you earn: you are short by %1$s a year (%2$s a month). Put prices up, add a client, or cut a bill.', 'kbroo-bills' ),
								'<strong>' . esc_html( self::money( -$sum['profit'] ) ) . '</strong>',
								esc_html( self::money( -$sum['profit_month'] ) )
							);
							?>
						</li>
					<?php endif; ?>
					<?php if ( $fc['shortfall'] > 0 ) : ?>
						<li class="kb-neg">
							<?php
							printf(
								/* translators: 1: amount, 2: date */
								esc_html__( 'The account would be overdrawn. Add at least %1$s before %2$s to stay above zero.', 'kbroo-bills' ),
								'<strong>' . esc_html( self::money( $fc['shortfall'] ) ) . '</strong>',
								esc_html( date_i18n( 'j M Y', strtotime( $fc['lowest_date'] ) ) )
							);
							?>
						</li>
					<?php else : ?>
						<li>
							<?php
							printf(
								/* translators: 1: start balance, 2: end balance */
								esc_html__( 'The balance never goes below zero. It starts at %1$s and ends the period at %2$s.', 'kbroo-bills' ),
								esc_html( self::money( $fc['start_balance'] ) ),
								'<strong>' . esc_html( self::money( $fc['end_balance'] ) ) . '</strong>'
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
			</div>

			<h3><?php esc_html_e( 'Month by month', 'kbroo-bills' ); ?></h3>
			<div class="kb-scroll">
				<table class="kb-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Month', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'Start', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'Stripe in', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'Stripe fees', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'Bills out', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'One-offs', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'Change', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'End', 'kbroo-bills' ); ?></th>
							<th class="num"><?php esc_html_e( 'Lowest', 'kbroo-bills' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fc['months'] as $m ) : ?>
							<tr>
								<td><?php echo esc_html( $m['label'] ); ?></td>
								<td class="num"><?php echo esc_html( self::money( $m['opening'] ) ); ?></td>
								<td class="num"><?php echo esc_html( self::money( $m['income'] ) ); ?></td>
								<td class="num kb-muted"><?php echo esc_html( self::money( $m['fees'] ) ); ?></td>
								<td class="num"><?php echo esc_html( self::money( $m['bills'] ) ); ?></td>
								<td class="num"><?php echo esc_html( self::money( $m['oneoffs'], true ) ); ?></td>
								<td class="num <?php echo esc_attr( self::tone( $m['net'] ) ); ?>"><?php echo esc_html( self::money( $m['net'], true ) ); ?></td>
								<td class="num"><strong><?php echo esc_html( self::money( $m['closing'] ) ); ?></strong></td>
								<td class="num <?php echo $m['lowest'] < 0 ? 'kb-neg' : ''; ?>"><?php echo esc_html( self::money( $m['lowest'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php
			$upcoming = array_filter(
				$fc['events'],
				function ( $e ) use ( $today, $soon ) {
					return $e['date'] >= $today && $e['date'] <= $soon;
				}
			);
			?>
			<h3><?php esc_html_e( 'Next 30 days', 'kbroo-bills' ); ?></h3>
			<?php if ( empty( $upcoming ) ) : ?>
				<p class="kb-muted"><?php esc_html_e( 'Nothing due in the next 30 days within the forecast period.', 'kbroo-bills' ); ?></p>
			<?php else : ?>
				<?php self::statement( $upcoming ); ?>
			<?php endif; ?>

			<details class="kb-details">
				<summary><?php esc_html_e( 'Full statement for the period', 'kbroo-bills' ); ?></summary>
				<?php self::statement( $fc['events'] ); ?>
			</details>

			<p class="kb-muted kb-foot">
				<?php
				printf(
					/* translators: 1: percent, 2: fixed fee, 3: days */
					esc_html__( 'Stripe fee used: %1$s%% + %2$s per payment. Stripe pays into the bank %3$d days after a client is charged. Change these under Settings.', 'kbroo-bills' ),
					esc_html( (string) (float) $settings['stripe_percent'] ),
					esc_html( self::money( $settings['stripe_fixed'] ) ),
					(int) $settings['payout_delay']
				);
				?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function statement( array $events ) {
		?>
		<div class="kb-scroll">
			<table class="kb-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'kbroo-bills' ); ?></th>
						<th><?php esc_html_e( 'Description', 'kbroo-bills' ); ?></th>
						<th class="num"><?php esc_html_e( 'In', 'kbroo-bills' ); ?></th>
						<th class="num"><?php esc_html_e( 'Out', 'kbroo-bills' ); ?></th>
						<th class="num"><?php esc_html_e( 'Balance', 'kbroo-bills' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $e ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'D j M', strtotime( $e['date'] ) ) ); ?></td>
							<td>
								<?php echo esc_html( $e['label'] ); ?>
								<?php if ( 'income' === $e['type'] ) : ?>
									<br><small class="kb-muted">
										<?php
										printf(
											/* translators: 1: gross amount, 2: charge date, 3: fee */
											esc_html__( 'Stripe payout: %1$s charged on %2$s, less %3$s fee', 'kbroo-bills' ),
											esc_html( self::money( $e['gross'] ) ),
											esc_html( date_i18n( 'j M', strtotime( $e['charged'] ) ) ),
											esc_html( self::money( $e['fee'] ) )
										);
										?>
									</small>
								<?php elseif ( 'oneoff' === $e['type'] ) : ?>
									<small class="kb-muted">(<?php esc_html_e( 'one-off', 'kbroo-bills' ); ?>)</small>
								<?php endif; ?>
							</td>
							<td class="num kb-pos"><?php echo $e['amount'] > 0 ? esc_html( self::money( $e['amount'] ) ) : ''; ?></td>
							<td class="num"><?php echo $e['amount'] < 0 ? esc_html( self::money( -$e['amount'] ) ) : ''; ?></td>
							<td class="num <?php echo $e['balance'] < 0 ? 'kb-neg' : ''; ?>"><?php echo esc_html( self::money( $e['balance'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
