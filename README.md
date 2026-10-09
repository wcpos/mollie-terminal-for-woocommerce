# Mollie Terminal for WooCommerce

Mollie Terminal support for WooCommerce POS. Requires WooCommerce POS Pro 2.0.0 or newer; the
gateway is offered on the POS only (the keypad tile and the POS order-pay page), never as a
payment method on the shop's checkout.

This plugin starts Mollie `pointofsale` payments for in-person checkout and treats Mollie as the source of truth for payment and refund state. Local WooCommerce order meta is only a cache.

## Legacy safety model

- Per-order locks prevent duplicate payment/refund mutations.
- Every webhook, poll, cancel, retry, and refund path fetches authoritative Mollie state before changing an order.
- Webhooks only trust the payment ID from Mollie, then fetch the payment.
- Payment attempts are append-only history.
- Refund retries reconcile by Mollie refund IDs and metadata before creating another refund.
- POS payments are limited to EUR until broader Mollie Terminal currency support is confirmed.

## Setup

1. Install and activate WooCommerce and WooCommerce POS Pro 2.0.0 or newer.
2. Activate this plugin. Without a supported Pro, it registers nothing and shows an admin notice.
3. Go to WooCommerce → Settings → Payments → Mollie Terminal.
4. Choose an **API key source**: enter the Mollie API key matching the selected
   mode here, or
   reuse the key already configured in the official *Mollie Payments for
   WooCommerce* plugin (matched to the selected mode). See the note below about
   test mode.
5. If you accept terminal payments, pick a **default terminal** from the
   dropdown. The list is fetched live from your Mollie account (inactive
   terminals are hidden — Mollie cannot reactivate them), so you no longer
   paste a terminal ID by hand. QR-only setups can leave it empty.
6. Optionally restrict **Enabled terminals** to the devices actually in use —
   only those can be chosen at checkout (empty = all active terminals; the
   default terminal is always available).
7. Optionally enable **Lock terminal selection** so cashiers cannot change the
   terminal at checkout; the default terminal is then always used (enforced
   server-side as well).
8. The **Checkout debug logs** setting (off by default) shows the on-panel log
   tools; leave it off unless gathering logs for support — activity is always
   recorded in WooCommerce → Status → Logs.
9. Save. The webhook URL is set automatically on every payment — no Mollie
   dashboard configuration is required.

You do not need to enter a Mollie **Profile ID**: it is not required for
`pointofsale` payments, and terminals are listed across the whole account.

## WooCommerce POS 2.0 checkout

Enable Mollie Terminal under POS → Settings → Checkout to use its terminal tile; that switch is the
only one (the old WooCommerce → Payments "enable for web checkout" setting is gone).
Cashiers send the selected payment amount to a reader from the tile; terminal tips are recorded as an order fee.
Manage **Default terminal**, **Enabled terminals** and **Lock terminal selection** under WooCommerce → Settings → Payments → Mollie Terminal.
These fields are mirrored into POS reader settings when saved; initial migration preserves existing POS reader choices.
The **Legacy** tab (the POS order-pay page) runs through the POS's shared order-pay panel, so a
Legacy-tab payment is tracked like a keypad payment, unless an on-screen QR method is enabled
(next section): then the plugin's own panel stays, because a QR code has no home in the shared
panel yet. Attempts the old panel left mid-flight when the plugin is updated are folded into the
POS once, so they complete or cancel through the POS.

## The plugin's own order-pay panel (QR carve-out)

With a QR method enabled, at checkout the cashier optionally picks a terminal from the dropdown (defaults
to the configured one) and clicks **Start Terminal Payment**. The plugin then:

- shows `Sending to terminal…` → `Waiting for terminal…`,
- polls Mollie automatically (every 2s by default; filter
  `mtfwc_poll_interval_ms` / `mtfwc_poll_timeout_ms` to tune),
- and, when the terminal confirms, redirects straight to the thank-you page
  (the POS-aware order-received URL, the same one the Stripe/SumUp terminal
  gateways use).

The panel uses a single button that toggles between **Start Terminal Payment**
and **Cancel Terminal Payment** while a payment is in flight. If the checkout is
reloaded mid-payment, the panel resumes polling the open payment automatically.
If the customer changes their mind and another payment method is selected, the
terminal payment is stopped and canceled.

## QR code payments

Merchants in the Netherlands and Belgium can also let customers pay by scanning
an on-screen iDEAL or Bancontact QR code with their banking app. Enable the
methods you accept under **WooCommerce → Settings → Payments → Mollie Terminal
→ QR code payments**. While any is enabled the Legacy tab keeps the plugin's own
panel (the shared POS panel cannot show a QR code yet): the cashier can switch it from
**Terminal** to **QR code**, choose a method when both are enabled, and click
**Show QR code**.

The plugin tracks QR payments through the same automatic Mollie polling and
webhook flow as terminal payments. After paying on their phone, the customer
lands on the WooCommerce order-received page. iDEAL QR can be tried with a
Mollie test API key; Bancontact QR requires live mode. Bancontact caps QR
payments at €1,500.00 per transaction (a scheme limit, not a plugin one); for
larger totals use the terminal.

## Stale payment cleanup (the plugin's own panel)

A Mollie `pointofsale` payment can stay "open" on the Mollie side if the flow
is abandoned. The plugin actively cancels open payments when:

- the auto-poll times out (5 minutes by default) — the cancel command is sent
  automatically instead of leaving the payment behind,
- the order is completed with a **different** payment method (customer changed
  their mind and paid cash) or the order is cancelled in WooCommerce — a
  server-side hook cancels the open Mollie payment and leaves an order note,
- the checkout page is closed mid-payment — a best-effort cancel beacon fires,
- as a server-side backstop, a WP-Cron sweep (every 10 minutes) cancels
  payments left open past a threshold on still-unpaid orders, covering the cases
  where the browser is closed or the network drops before the above can fire
  (threshold filterable via `mtfwc_stale_payment_seconds`).

If Mollie will not cancel an open payment — usually an unresponsive or
powered-off terminal — the attempt is **abandoned locally**: the panel returns
to idle so the cashier can start a fresh payment or pick another method without
creating a new order, and the lingering Mollie payment is reconciled by the
webhook or canceled by the sweep. If the payment already reached the terminal,
the customer cancels on the device itself; these cleanups are safe no-ops in
that case.

## Test mode limitation

Mollie terminals only exist on **live** accounts. The Mollie **test** API key
cannot drive a physical (or iOS/Android) terminal, so terminal payments cannot
be exercised end-to-end in the test environment. This is a Mollie platform
limitation, not a plugin restriction. Use Live mode with your live API key to
take terminal payments. The settings screen shows a warning when test mode is
selected. iDEAL QR payments do work in test mode.

## Development

```sh
composer run lint
composer run test
```

### Provider conformance

Pro's provider conformance suite runs the real adapter over a scripted Mollie under wp-env, with
a sibling checkout of WooCommerce POS Pro (`../woocommerce-pos-pro`, on its `next` branch during
development). CI runs it on PHP 7.4 and 8.3 and compares the committed transcripts in
`tests/conformance/transcripts`; a changed transcript is a re-certification, never a file to
regenerate blindly.

```sh
composer install
composer install --working-dir=../woocommerce-pos-pro
npx wp-env start
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- vendor/bin/phpunit -c phpunit.conformance.xml.dist
```

Recording missing transcripts is an explicit opt-in that must reach the PHPUnit process inside
wp-env (it forwards no host variables):

```sh
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- env WCPOS_RECORD_TRANSCRIPTS=1 vendor/bin/phpunit -c phpunit.conformance.xml.dist
```

## References

- Mollie Create Payment API: https://docs.mollie.com/reference/create-payment
- Mollie Terminal setup: https://docs.mollie.com/docs/setting-up-terminal
