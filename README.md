# Kbroo Bills

A small WordPress plugin for keeping the Kbroo accounts in balance. It replaces the
yearly bills tracking spreadsheet.

You enter your **clients** (who pay through Stripe), your **bills**, and any
**one-off** money in or out. The plugin then works out:

- yearly income after Stripe fees, yearly bills (monthly and yearly), and profit or loss
- how much to put aside each month so yearly bills (domains, Elementor…) never catch you short
- a day-by-day forecast of the bank balance, with Stripe payouts arriving a few days
  after each client is charged, less Stripe's fee
- a month-by-month table, the lowest balance in the year, and a warning if the account
  would go overdrawn

## Install

1. Zip the `kbroo-bills` folder (or download `kbroo-bills.zip` if one was provided).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip and activate it.
3. Open **Kbroo Bills** in the admin menu. On a fresh install you can click
   **Load my spreadsheet figures** to start from the existing spreadsheet.

To build the zip from this repository:

```bash
zip -r kbroo-bills.zip kbroo-bills
```

## Using it

| Screen | What it's for |
| --- | --- |
| Dashboard | Totals, advice, month-by-month table, next 30 days and the full statement |
| Clients | Who pays you, how much (before Stripe fees), monthly or yearly, and on which day |
| Bills | What you pay, monthly or yearly, and on which day |
| One-off entries | Transfers, refunds, one-off purchases or corrections to match the bank |
| Settings | Start date and bank balance, Stripe fee (default 1.5% + 20p), payout delay in days |

When the forecast drifts away from what your bank shows, go to **Settings**,
set the start date to today and the start balance to your real balance.

## Viewing it on the website

Put the shortcode `[kbroo_bills]` on a page. Visitors who aren't logged in see a
login form, and once logged in they see the dashboard (view only).

Who can see it:

- **Administrators** can always see it, and are the only ones who can change anything.
- **Kbroo Bills Viewer** is a role added by the plugin. Create a user with
  **Users → Add New** and pick this role. They can log in and view the page, but can't
  get into the Kbroo Bills admin screens or edit anything, and don't see the admin bar.

There's also a separate `[kbroo_bills_login]` shortcode if you want a login box
somewhere else, for example in a sidebar or on another page. When logged in it shows
"Logged in as …" with a log out link. Add `redirect` to send people to the accounts page
after logging in:

```
[kbroo_bills_login redirect="https://example.com/accounts/"]
```

## Tests

The calculations live in `kbroo-bills/includes/class-kbroo-bills-calculator.php` and
don't need WordPress. Run the checks with:

```bash
php tests/calculator-test.php
```
