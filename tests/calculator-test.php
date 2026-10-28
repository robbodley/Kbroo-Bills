<?php
/**
 * Standalone checks for the calculator, using the figures from the original
 * spreadsheet. Run with: php tests/calculator-test.php
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../kbroo-bills/includes/class-kbroo-bills-calculator.php';

$failures = 0;
function check( $label, $expected, $actual ) {
	global $failures;
	$ok = abs( (float) $expected - (float) $actual ) < 0.005;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}: expected {$expected}, got {$actual}\n";
	$failures += $ok ? 0 : 1;
}

function item( $kind, $name, $amount, $freq, $day, $month = 1, $active = 1 ) {
	return array(
		'kind'         => $kind,
		'name'         => $name,
		'amount'       => $amount,
		'frequency'    => $freq,
		'day_of_month' => $day,
		'month'        => $month,
		'active'       => $active,
	);
}

$items = array(
	item( 'client', 'J Kilburn', 30, 'monthly', 1 ),
	item( 'client', 'Morgan', 12, 'monthly', 1 ),
	item( 'client', 'Arctic Smart', 12, 'monthly', 1 ),
	item( 'client', 'Mphigh Class', 12, 'monthly', 1 ),
	item( 'client', 'Julia Tolley', 12, 'monthly', 1 ),
	item( 'client', 'Rob', 45, 'monthly', 1 ),
	item( 'client', 'Sally', 0, 'monthly', 1 ),
	item( 'client', 'Paul & Helen', 144, 'yearly', 5, 3 ),
	item( 'bill', 'Anthropic', 18, 'monthly', 2 ),
	item( 'bill', '1Password', 4.5, 'monthly', 4 ),
	item( 'bill', 'Adobe', 20, 'monthly', 14 ),
	item( 'bill', 'Account charges', 10, 'monthly', 19 ),
	item( 'bill', '20i', 18, 'monthly', 26 ),
	item( 'bill', '20i', 48, 'monthly', 29 ),
	item( 'bill', 'Kbroo domain', 16, 'yearly', 18, 5 ),
	item( 'bill', 'Elementor', 110, 'yearly', 14, 8 ),
	item( 'bill', 'Kbroo domain', 16, 'yearly', 20, 9 ),
	item( 'bill', 'Old thing', 99, 'monthly', 1, 1, 0 ),
);
$oneoffs  = array(
	array( 'entry_date' => '2026-10-30', 'description' => 'Adjustment', 'amount' => -100 ),
);
$settings = array(
	'start_date'      => '2026-01-01',
	'start_balance'   => 50,
	'forecast_months' => 12,
	'stripe_percent'  => 1.5,
	'stripe_fixed'    => 0.20,
	'payout_delay'    => 3,
);

$calc = new Kbroo_Bills_Calculator( $items, $oneoffs, $settings );

// Stripe fee.
check( 'fee on £12', 0.38, $calc->stripe_fee( 12 ) );
check( 'fee on £0', 0, $calc->stripe_fee( 0 ) );

// Yearly totals match the spreadsheet's Bills/Year (1422) and Total Bills/Year (1564).
$s = $calc->annual_summary();
check( 'monthly bills per year', 1422, $s['bills_monthly'] );
check( 'yearly bills', 142, $s['bills_yearly'] );
check( 'total bills', 1564, $s['bills_total'] );
check( 'gross income', 123 * 12 + 144, $s['income_gross'] );
$fees = 12 * ( 0.65 + 0.38 * 4 + 0.88 ) + 2.36;
check( 'stripe fees', $fees, $s['fees'] );
check( 'profit', 123 * 12 + 144 - $fees - 1564, $s['profit'] );
check( 'set aside per month', 142 / 12, $s['set_aside_month'] );

// Forecast.
$f = $calc->forecast();
check( 'months in forecast', 12, count( $f['months'] ) );
$first = $f['events'][0];
check( 'first event is Anthropic on 2 Jan (bills before income on the same day)', -18, $first['amount'] );
$dec_payout = array_filter( $f['events'], function ( $e ) { return 'income' === $e['type'] && '2026-01-04' === $e['date']; } );
check( 'Jan payouts land on 4 Jan (charged 1 Jan + 3 days)', 6, count( $dec_payout ) );
$march = $f['months']['2026-03'];
check( 'March income includes Paul & Helen yearly', ( 123 - 0.65 - 1.52 - 0.88 ) + 144 - 2.36, $march['income'] );
check( 'October one-off', -100, $f['months']['2026-10']['oneoffs'] );
$net = 0;
foreach ( $f['events'] as $e ) {
	$net += $e['amount'];
}
check( 'end balance = start + all movements', 50 + $net, $f['end_balance'] );
check( 'months chain together', $f['months']['2026-02']['closing'], $f['months']['2026-03']['opening'] );

// A charge late in the previous month is paid out inside the window.
$late = new Kbroo_Bills_Calculator(
	array( item( 'client', 'Late', 10, 'monthly', 30 ) ),
	array(),
	array_merge( $settings, array( 'forecast_months' => 1 ) )
);
$ev   = $late->events();
check( 'Dec 30 charge paid out 2 Jan', 1, count( array_filter( $ev, function ( $e ) { return '2026-01-02' === $e['date']; } ) ) );

// Day 31 in February uses the last day.
$short = new Kbroo_Bills_Calculator(
	array( item( 'bill', 'End', 5, 'monthly', 31 ) ),
	array(),
	array_merge( $settings, array( 'start_date' => '2026-02-01', 'forecast_months' => 1 ) )
);
$ev    = $short->events();
check( 'day 31 in Feb falls on the 28th', 1, (int) ( '2026-02-28' === $ev[0]['date'] ) );

// Overdraft warning.
$broke = new Kbroo_Bills_Calculator(
	array( item( 'bill', 'Big', 80, 'monthly', 1 ) ),
	array(),
	$settings
);
$bf    = $broke->forecast();
check( 'lowest balance', 50 - 80 * 12, $bf['lowest_balance'] );
check( 'shortfall', 80 * 12 - 50, $bf['shortfall'] );

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit( $failures ? 1 : 0 );
