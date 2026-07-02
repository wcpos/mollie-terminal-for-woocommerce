# Changelog

All notable changes to Mollie Terminal for WooCommerce will be documented in this file.

## 0.3.1 - 2026-07-02

### Changed

- All plugin diagnostics now go to the WooCommerce status logs (WooCommerce → Status → Logs, source `mollie-terminal-for-woocommerce`) instead of the `wp_options` table (issue #5). Removes option bloat and the non-atomic capped-array event store; WooCommerce's logger is the durable, concurrency-safe sink.
- Replaced the bespoke `Diagnostics` class with a `Logger` matching the WooCommerce POS terminal-gateway convention shared by the Stripe, SumUp, PayArc, and Square terminal plugins (a `Logger` class, a `WC_LOG_FILENAME` source constant, an `mtfwc_logging` filter toggle, and no options-table access). Redaction is kept because this plugin logs Mollie API payloads.
- The gateway settings "Diagnostics" panel now links to WooCommerce → Status → Logs instead of dumping recent events, the last API error, and the last webhook event from options.

### Removed

- Stopped writing the `mtfwc_recent_diagnostic_events`, `mtfwc_last_api_error`, and `mtfwc_last_webhook_event` options. Any existing values are deleted when the settings screen is opened.

## 0.3.0 - 2026-07-02

### Fixed

- Successful terminal payments now redirect straight to the (POS-aware) thank-you page instead of re-submitting the order-pay form, which hit WooCommerce's "this order has already been paid" guard and left the POS checkout stuck in a loop.
- The checkout terminal dropdown no longer stays stuck disabled when an initially empty terminal list later loads successfully.

### Added

- Inactive/disabled terminals are hidden from the checkout dropdown and the settings Default terminal dropdown (Mollie cannot reactivate them).
- New "Enabled terminals" setting: restrict which terminals cashiers can pick at checkout (enforced server-side; the default terminal is always allowed).
- New "Lock terminal selection" setting: cashiers always use the default terminal (enforced server-side).
- Stale-payment cleanup: auto-cancel the open Mollie payment when the auto-poll times out, when the order is paid another way (e.g. cash) or cancelled, and best-effort when the checkout page is closed mid-payment.
- UI polish for the payment panel: status banner with busy spinner, clear button hierarchy, quieter logs section; terminal choice locks while a payment is in flight.
- Hardened the payment panel CSS against theme/WooCommerce style leakage so store themes cannot distort the buttons, dropdown, or status banner.
- CI workflow running lint and the regression suites on every pull request (PHP 7.4 + 8.3).
- Dev-only UI preview harness (`tests/ui-preview/render.php`), including a hostile-theme toggle and a server-rendered locked-terminal variant, to view the payment panel without a WordPress install.

## 0.2.0 - 2026-07-01

### Added

- Auto-completing payment flow: automatic status polling after Start Terminal Payment, with automatic order completion when the terminal confirms.
- Live terminal dropdown at checkout fetched from the Mollie Terminals API, plus a fetched dropdown for the default terminal in settings.
- Test-mode warning: Mollie terminals exist only on live accounts.

### Removed

- The Mollie Profile ID setting (not required for `pointofsale` payments; terminals are listed account-wide).

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
