# Mollie Terminal for WooCommerce

Mollie Terminal support for WooCommerce and WooCommerce POS.

This plugin starts Mollie `pointofsale` payments for in-person checkout and treats Mollie as the source of truth for payment and refund state. Local WooCommerce order meta is only a cache.

## Safety model

- Per-order locks prevent duplicate payment/refund mutations.
- Every webhook, poll, cancel, retry, and refund path fetches authoritative Mollie state before changing an order.
- Webhooks only trust the payment ID from Mollie, then fetch the payment.
- Payment attempts are append-only history.
- Refund retries reconcile by Mollie refund IDs and metadata before creating another refund.
- POS payments are limited to EUR until broader Mollie Terminal currency support is confirmed.

## Setup

1. Install and activate WooCommerce.
2. Activate this plugin.
3. Go to WooCommerce → Settings → Payments → Mollie Terminal.
4. Enter your Mollie **live** API key (see the note below about test mode).
5. Pick a **default terminal** from the dropdown. The list is fetched live from
   your Mollie account, so you no longer paste a terminal ID by hand.
6. Save. The webhook URL is set automatically on every payment — no Mollie
   dashboard configuration is required.

You do not need to enter a Mollie **Profile ID**: it is not required for
`pointofsale` payments, and terminals are listed across the whole account.

## Checkout flow

At checkout the cashier optionally picks a terminal from the dropdown (defaults
to the configured one) and clicks **Start Terminal Payment**. The plugin then:

- shows `Sending to terminal…` → `Waiting for terminal…`,
- polls Mollie automatically (every 2s by default; filter
  `mtfwc_poll_interval_ms` / `mtfwc_poll_timeout_ms` to tune),
- and, when the terminal confirms, shows `Payment complete` and finishes the
  order automatically (the same way the POS "Process payment" button does).

## Test mode limitation

Mollie terminals only exist on **live** accounts. The Mollie **test** API key
cannot drive a physical (or iOS/Android) terminal, so terminal payments cannot
be exercised end-to-end in the test environment. This is a Mollie platform
limitation, not a plugin restriction. Use Live mode with your live API key to
take terminal payments. The settings screen shows a warning when test mode is
selected.

## Development

```sh
composer run lint
composer run test
```

## References

- Mollie Create Payment API: https://docs.mollie.com/reference/create-payment
- Mollie Terminal setup: https://docs.mollie.com/docs/setting-up-terminal
