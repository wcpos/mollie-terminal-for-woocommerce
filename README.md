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
4. Enter your Mollie API key and profile ID.
5. Select test or live mode and a default terminal.
6. Configure the displayed webhook URL in Mollie if needed.

## Development

```sh
composer run lint
composer run test
```

## References

- Mollie Create Payment API: https://docs.mollie.com/reference/create-payment
- Mollie Terminal setup: https://docs.mollie.com/docs/setting-up-terminal
