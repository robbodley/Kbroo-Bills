<?php
/**
 * Pure calculation logic: no WordPress functions are used here so it can be
 * tested on its own.
 *
 * Items are arrays with keys: kind ('client'|'bill'), name, amount,
 * frequency ('monthly'|'yearly'), day_of_month, month, active.
 * One-off entries are arrays with keys: entry_date (Y-m-d), description,
 * amount (positive = money in, negative = money out).
 */

defined( 'ABSPATH' ) || exit;

class Kbroo_Bills_Calculator {

	/** @var array */
	private $items;

	/** @var array */
	private $oneoffs;

	/** @var array */
	private $settings;

	public function __construct( array $items, array $oneoffs, array $settings ) {
		$this->items    = array_values( array_filter( $items, array( $this, 'is_active' ) ) );
		$this->oneoffs  = $oneoffs;
		$this->settings = $settings;
	}

	private function is_active( $item ) {
		return ! empty( $item['active'] ) && (float) $item['amount'] > 0;
	}

	/**
	 * Stripe fee for a single payment of $gross.
	 */
	public function stripe_fee( $gross ) {
		$gross = (float) $gross;
		if ( $gross <= 0 ) {
			return 0.0;
		}
		$fee = $gross * (float) $this->settings['stripe_percent'] / 100 + (float) $this->settings['stripe_fixed'];
		return round( min( $fee, $gross ), 2 );
	}

	/**
	 * Year-at-a-glance totals, independent of the forecast window.
	 */
	public function annual_summary() {
		$s = array(
			'income_gross'  => 0.0,
			'fees'          => 0.0,
			'income_net'    => 0.0,
			'bills_monthly' => 0.0,
			'bills_yearly'  => 0.0,
		);

		foreach ( $this->items as $item ) {
			$times  = 'yearly' === $item['frequency'] ? 1 : 12;
			$amount = (float) $item['amount'];
			if ( 'client' === $item['kind'] ) {
				$s['income_gross'] += $amount * $times;
				$s['fees']         += $this->stripe_fee( $amount ) * $times;
			} elseif ( 'yearly' === $item['frequency'] ) {
				$s['bills_yearly'] += $amount;
			} else {
				$s['bills_monthly'] += $amount * 12;
			}
		}

		$s['income_net']       = $s['income_gross'] - $s['fees'];
		$s['bills_total']      = $s['bills_monthly'] + $s['bills_yearly'];
		$s['profit']           = $s['income_net'] - $s['bills_total'];
		$s['profit_month']     = $s['profit'] / 12;
		$s['income_net_month'] = $s['income_net'] / 12;
		$s['bills_month']      = $s['bills_total'] / 12;
		// Putting this aside every month means yearly bills are always covered.
		$s['set_aside_month'] = $s['bills_yearly'] / 12;

		return array_map(
			function ( $v ) {
				return round( $v, 2 );
			},
			$s
		);
	}

	/**
	 * First and last day of the forecast window.
	 *
	 * @return DateTimeImmutable[]
	 */
	public function window() {
		$start = new DateTimeImmutable( $this->settings['start_date'] );
		$end   = $start->modify( '+' . (int) $this->settings['forecast_months'] . ' months' )->modify( '-1 day' );
		return array( $start, $end );
	}

	/**
	 * Every money movement inside the forecast window, oldest first.
	 */
	public function events() {
		list( $start, $end ) = $this->window();
		$delay               = max( 0, (int) $this->settings['payout_delay'] );
		$from                = $start->format( 'Y-m-d' );
		$to                  = $end->format( 'Y-m-d' );
		$events              = array();

		// Start a month early so charges made just before the window whose
		// Stripe payout lands inside it are still counted.
		$cursor = $start->modify( 'first day of this month' )->modify( '-1 month' );
		while ( $cursor <= $end ) {
			$month         = (int) $cursor->format( 'n' );
			$days_in_month = (int) $cursor->format( 't' );

			foreach ( $this->items as $item ) {
				if ( 'yearly' === $item['frequency'] && (int) $item['month'] !== $month ) {
					continue;
				}
				$day  = min( max( 1, (int) $item['day_of_month'] ), $days_in_month );
				$date = $cursor->setDate( (int) $cursor->format( 'Y' ), $month, $day );

				if ( 'client' === $item['kind'] ) {
					$gross  = (float) $item['amount'];
					$fee    = $this->stripe_fee( $gross );
					$payout = $date->modify( '+' . $delay . ' days' )->format( 'Y-m-d' );
					if ( $payout >= $from && $payout <= $to ) {
						$events[] = array(
							'date'    => $payout,
							'type'    => 'income',
							'label'   => $item['name'],
							'charged' => $date->format( 'Y-m-d' ),
							'gross'   => $gross,
							'fee'     => $fee,
							'amount'  => round( $gross - $fee, 2 ),
						);
					}
				} else {
					$d = $date->format( 'Y-m-d' );
					if ( $d >= $from && $d <= $to ) {
						$events[] = array(
							'date'   => $d,
							'type'   => 'bill',
							'label'  => $item['name'],
							'amount' => -1 * (float) $item['amount'],
						);
					}
				}
			}
			$cursor = $cursor->modify( '+1 month' );
		}

		foreach ( $this->oneoffs as $entry ) {
			$d = $entry['entry_date'];
			if ( $d >= $from && $d <= $to ) {
				$events[] = array(
					'date'   => $d,
					'type'   => 'oneoff',
					'label'  => $entry['description'],
					'amount' => (float) $entry['amount'],
				);
			}
		}

		// On the same day, money out is counted before money in, so the
		// lowest balance is never shown better than it might really be.
		usort(
			$events,
			function ( $a, $b ) {
				if ( $a['date'] !== $b['date'] ) {
					return strcmp( $a['date'], $b['date'] );
				}
				return ( $a['amount'] >= 0 ) <=> ( $b['amount'] >= 0 );
			}
		);

		return $events;
	}

	/**
	 * Running balance through the forecast window with a month-by-month rollup.
	 */
	public function forecast() {
		list( $start, $end ) = $this->window();
		$balance             = (float) $this->settings['start_balance'];

		$months = array();
		for ( $c = $start->modify( 'first day of this month' ); $c <= $end; $c = $c->modify( '+1 month' ) ) {
			$months[ $c->format( 'Y-m' ) ] = array(
				'label'    => $c->format( 'F Y' ),
				'opening'  => 0.0,
				'income'   => 0.0,
				'fees'     => 0.0,
				'bills'    => 0.0,
				'oneoffs'  => 0.0,
				'closing'  => 0.0,
				'lowest'   => 0.0,
			);
		}

		$lowest      = $balance;
		$lowest_date = $start->format( 'Y-m-d' );
		$events      = $this->events();
		$by_month    = array();

		foreach ( $events as $i => $event ) {
			$balance                = round( $balance + $event['amount'], 2 );
			$events[ $i ]['balance'] = $balance;
			$by_month[ substr( $event['date'], 0, 7 ) ][] = $events[ $i ];
			if ( $balance < $lowest ) {
				$lowest      = $balance;
				$lowest_date = $event['date'];
			}
		}

		$running = (float) $this->settings['start_balance'];
		foreach ( $months as $key => &$m ) {
			$m['opening'] = $running;
			$m['lowest']  = $running;
			foreach ( isset( $by_month[ $key ] ) ? $by_month[ $key ] : array() as $event ) {
				if ( 'income' === $event['type'] ) {
					$m['income'] += $event['amount'];
					$m['fees']   += $event['fee'];
				} elseif ( 'bill' === $event['type'] ) {
					$m['bills'] += -$event['amount'];
				} else {
					$m['oneoffs'] += $event['amount'];
				}
				$m['lowest'] = min( $m['lowest'], $event['balance'] );
				$running     = $event['balance'];
			}
			$m['closing'] = $running;
			$m['net']     = round( $m['closing'] - $m['opening'], 2 );
		}
		unset( $m );

		return array(
			'events'         => $events,
			'months'         => $months,
			'start_balance'  => (float) $this->settings['start_balance'],
			'end_balance'    => $balance,
			'lowest_balance' => $lowest,
			'lowest_date'    => $lowest_date,
			'shortfall'      => $lowest < 0 ? round( -$lowest, 2 ) : 0.0,
		);
	}
}
