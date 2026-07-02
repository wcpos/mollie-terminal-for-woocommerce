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
   your Mollie account (inactive terminals are hidden — Mollie cannot
   reactivate them), so you no longer paste a terminal ID by hand.
6. Optionally restrict **Enabled terminals** to the devices actually in use —
   only those can be chosen at checkout (empty = all active terminals).
7. Optionally enable **Lock terminal selection** so cashiers cannot change the
   terminal at checkout; the default terminal is then always used (enforced
   server-side as well).
8. Save. The webhook URL is set automatically on every payment — no Mollie
   dashboard configuration is required.

You do not need to enter a Mollie **Profile ID**: it is not required for
`pointofsale` payments, and terminals are listed across the whole account.

## Checkout flow

At checkout the cashier optionally picks a terminal from the dropdown (defaults
to the configured one) and clicks **Start Terminal Payment**. The plugin then:

- shows `Sending to terminal…` → `Waiting for terminal…`,
- polls Mollie automatically (every 2s by default; filter
  `mtfwc_poll_interval_ms` / `mtfwc_poll_timeout_ms` to tune),
- and, when the terminal confirms, redirects straight to the thank-you page
  (the POS-aware order-received URL, the same one the Stripe/SumUp terminal
  gateways use).

## Stale payment cleanup

A Mollie `pointofsale` payment can stay "open" on the Mollie side if the flow
is abandoned. The plugin actively cancels open payments when:

- the auto-poll times out (5 minutes by default) — the cancel command is sent
  automatically instead of leaving the payment behind,
- the order is completed with a **different** payment method (customer changed
  their mind and paid cash) or the order is cancelled in WooCommerce — a
  server-side hook cancels the open Mollie payment and leaves an order note,
- the checkout page is closed mid-payment — a best-effort cancel beacon fires.

If the payment already reached the terminal, Mollie reports it as not
cancelable and the cashier cancels on the device itself — these cleanups are
safe no-ops in that case.

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
