# Changelog

All notable changes to Mollie Terminal for WooCommerce will be documented in this file.

## 0.1.2 - 2026-06-30

### Added

- Added a Mollie Terminal log panel to checkout and order-pay payment fields.
- Added browser-side payment activity logs with show, copy, and clear controls.
- Added redacted recent diagnostic events for AJAX, Mollie API, payment lifecycle, and webhook activity.

### Fixed

- Updated the Last API error diagnostic so Mollie API failures are actually persisted for support.

## 0.1.1 - 2026-06-30

### Fixed

- Added the WooCommerce order received URL as Mollie `redirectUrl` for terminal payment creation.
- Removed `profileId` from the Mollie create-payment payload when using API-key authentication.
- Added regression coverage for the documented `pointofsale`/`terminalId` payment payload.

## 0.1.0 - 2026-06-16

### Added

- Initial Mollie Terminal payment gateway for WooCommerce and WooCommerce POS.
- Mollie `pointofsale` payment creation for configured terminal IDs.
- Test/live mode settings with Mollie API key, profile ID, default terminal ID, and webhook URL diagnostics.
- Terminal validation before dispatching a payment, including profile, mode, and active-status checks.
- Safe payment lifecycle handling with per-order locks, append-only payment attempts, and remote Mollie state reconciliation before order updates.
- Webhook handling that uses the incoming Mollie payment ID only to fetch authoritative payment state before completing or updating an order.
- POS payment polling and cancellation flows that fetch current Mollie state before reporting status or mutating order meta.
- WooCommerce refund support with refund locks, Mollie refund reconciliation, duplicate-refund prevention, and over-refund protection.
- EUR-only money formatting and comparison helpers for documented Mollie point-of-sale currency support.
- Redacted logging for payment, webhook, cancellation, refund, and API diagnostic events.
- Regression tests for money safety, payment attempt history, and payment locking.
- Automated release workflow that packages `mollie-terminal-for-woocommerce.zip` when the plugin version changes.

### Notes

- Mollie point-of-sale payments are limited to EUR until broader Mollie Terminal currency support is confirmed.
- Mollie webhook payloads are not trusted as payment evidence; the plugin fetches the payment from Mollie before reconciling WooCommerce orders.
- Test-mode terminal pairing is not automated because Mollie's terminal pairing-code endpoints do not currently support test mode.
