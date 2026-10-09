# Changelog

All notable changes to Mollie Terminal for WooCommerce will be documented in this file.

## Unreleased

The POS order-pay page (the Legacy tab) runs through WooCommerce POS's shared order-pay panel, so a Legacy-tab payment is a ledger row like a keypad payment: its polling, cancel, deadline, settlement and refunds are the POS's. While an on-screen QR method (iDEAL, Bancontact) is enabled the plugin's own panel stays, because a QR code has no home in the shared panel yet. On update, terminal attempts the old panel left mid-flight are folded into the POS once (25 orders per request, under the POS order lock) and complete or cancel through it; the old panel's webhook and sweep leave those attempts alone while the POS's leg is live (the cleanup still cancels one when the order is paid another way), a browser tab still showing the old panel is told to reload, and no old-panel payment can be started while the shared panel is in use. Once the POS's leg has ended without money the old panel and its webhook act on the payment as before. The shared panel adopts an open old attempt before it renders, so the pass need not have reached the order first. With a QR method enabled nothing is adopted: the old panel owns its attempts. Refunds of a payment the shared panel took go through the POS; a payment the old panel took refunds as before, and on an order carrying both the old-panel payment is refunded when the POS cannot allocate the amount. A payment the shared panel takes carries its Mollie payment id as the order's transaction id, as the old panel did, whether a poll or Mollie's webhook settled it.

Pro's provider conformance suite now runs against the real Mollie adapter in CI (a scripted Mollie behind WordPress's HTTP layer, the sibling Pro checkout under wp-env), with its transcripts committed. Found on the way: a payment creation, status read or refund Mollie did not answer (a transport failure, a 5xx, a rate limit) was reported to the POS as a refusal, so the leg was dropped and a retry could charge twice; it is now indeterminate, the leg stays pending and the retry carries the same `Idempotency-Key`, so Mollie hands back the payment the lost response made. A webhook for an attempt the POS adopted from the old panel settles that attempt; a refund of a payment the old panel took, made through the POS, reaches Mollie.

Mollie Terminal requires WooCommerce POS Pro 2.0.0 or newer: the plugin registers nothing and shows an admin notice on older or missing Pro. Web checkout is removed: Mollie Terminal is no longer offered on the shop's checkout, and the "Enable Mollie Terminal for web checkout" setting is gone; the POS keypad tile and the POS order-pay page (the Legacy tab) are the only surfaces, and POS → Settings → Checkout is the only switch. The keypad's terminal tile: reader settings are mirrored from the existing gateway fields, tile payment creation sends an `Idempotency-Key` so retries within Mollie’s one-hour window reuse the same payment, and tips added on the terminal are recorded as an order fee.

### Fixed

- **A paid Mollie payment could leave its order unpaid until the ten-minute cleanup, while the POS checkout had already moved on to the receipt.** Since 0.5.7, when Mollie's webhook and the checkout's status poll both saw the payment paid, the one that did not get to complete the order told the checkout the payment was paid, and the checkout stopped checking. If the request completing the order then died before finishing, or the plugin could not record which request was completing it (a database error), nothing finished the order: the customer had been charged, but the order, stock and emails stayed incomplete until something else reconciled the payment: the stale-payment cleanup, when the attempt was still recorded as in progress (and on stores without a working WP-Cron that might never happen), or nothing at all, when the dying request had already recorded the attempt as paid, because the cleanup only acts on attempts still in progress. The checkout now reports the payment as paid only once the order is actually completed, shows "Payment received — finishing order…" meanwhile, and keeps checking, so it finishes the order itself if the other request does not within two minutes. This also covers a request that died after recording the payment as paid but before completing the order, which previously sent the checkout straight to the receipt; the checkout finishes such an order when it is reopened, and the stale-payment cleanup now finishes it even when nobody reopens it (next entry). Pressing Cancel or switching payment method at that moment no longer reports a paid payment as canceled; switching method puts the order back on Mollie Terminal so a second payment cannot be taken while the order is finished.
- **The stale-payment cleanup now finishes a paid order whose completion was interrupted, and no longer re-checks a payment that failed its checks every ten minutes.** If the request completing a paid terminal payment died after recording the payment as paid but before completing the order, the cleanup skipped it, because it only looked at payments still in progress: the customer had been charged, but the order, stock and emails stayed incomplete until a cashier reopened the checkout. The cleanup now asks Mollie about such a payment and completes the order once. The same holds for a set-aside payment that turned out paid: it stays on the cleanup's list until its order is completed. A payment Mollie reports paid that does not match the order (for example, the order total changed after the payment started) is now recorded as unverified instead of paid, so the cleanup leaves it alone rather than adding a "payment verification failed" note every ten minutes; reopening the checkout or pressing Start still checks it with Mollie again. A payment that an earlier version recorded as paid after it failed those checks gets one more "payment verification failed" note from the first cleanup after updating, and is left alone after that. Orders the cleanup leaves alone no longer hold up the rest: each run now looks through up to 200 of the oldest orders with a Mollie payment, instead of only the oldest 25, and still acts on at most 25 orders with an in-progress payment and 25 with a set-aside one.
- **A set-aside terminal payment could drop off the cleanup's list while still open at Mollie.** When the cashier cancelled a payment the terminal would not release, the plugin set it aside on the order for the cleanup to cancel later. If, at that same moment, another request was finishing an earlier set-aside payment on the same order (completing the order, or recording it as canceled, failed or expired), that request saved the order's list of set-aside payments as it had read it before, and the newer payment was dropped from it. Nothing then cancelled it at Mollie, so a customer could still pay it on the terminal after paying the order another way. Each change to the list now takes a short per-order turn, re-reads the list from the database and changes it in place, so one request at a time adds or removes its own payment. If the turn cannot be taken, the payment is not set aside: it stays the order's current payment, where the cleanup still sees it, and the cashier is asked to cancel again in a moment; the checkout panel keeps its Cancel button and keeps watching the payment instead of offering Start. When the order was already completed another way and that automatic cancel could not finish, it is retried over the next fifteen minutes and the order gets a note if it still fails.
- **The stale-payment cleanup could mark a paid terminal payment as abandoned again.** When the cleanup resolved two set-aside payments on one order in the same run, one paid and one canceled, failed or expired, saving the second one wrote back the order's old payment history, so the paid payment was listed as abandoned and its history entry lost the paid status. The order itself was still completed correctly; the cleanup now reads the order afresh before each payment.

## 0.5.9 - 2026-10-02

### Fixed

- **A paid Mollie payment could still be refused on stores with HPOS order data caching and a persistent object cache.** Before completing an order, the plugin re-reads it and asks WooCommerce to drop its cached copy. WooCommerce drops the cached order details only when it also manages to drop the cached order row; when the row entry had already expired from the cache, the old details stayed. If the cashier had started a new terminal payment after those details were cached, the paid payment was refused with the order note "payment verification failed: payment is not known for this order", and the order stayed unpaid until something else changed the order's details or the cache dropped the entry, which WooCommerce does not set to expire. The plugin now drops the cached order details itself. Stock was not affected: the order's paid status was always read fresh, so an order could not be completed twice.

## 0.5.8 - 2026-10-01

### Fixed

- **On some stores 0.5.7 could still complete a Mollie order twice, or refuse a payment that had been paid.** 0.5.7 re-reads the order before completing it, but on stores using High-Performance Order Storage with its order data cache turned on, WooCommerce kept serving the copy of the order it had cached earlier in the same request. A request that had loaded the order while it was unpaid could therefore still complete it a second time and reduce stock again. The re-read also took the order's Mollie payment details from a cache, so when the cashier had started a new terminal payment after the request loaded the order, a paid payment was refused with the order note "payment verification failed: payment is not known for this order" and the order stayed unpaid until the next check. The re-read now clears both caches and reads the order and its payment details from the database.

## 0.5.7 - 2026-10-01

### Fixed

- **A Mollie payment could complete its order twice and reduce stock twice.** Mollie's webhook and the POS checkout's status poll both check the payment, and either one completes the order when the payment is paid. When both arrived within about a second of each other, each had already loaded the order while it was still unpaid, both went on to mark it paid, and WooCommerce reduced stock for the whole order a second time. Completing an order is now claimed once per order with a single atomic database insert. The request that wins the claim re-reads the order from the database before deciding, and completes it only if it is still unpaid. A request that arrives while another is completing the order leaves the order alone and reports the payment as paid, so the POS checkout moves on to the receipt and a cancel at that moment never reports a paid payment as canceled. The per-order lock that guards refunds, payment starts and cancellations used the same check-then-write pattern, so two requests could both get it; it now uses the same atomic claim.

## 0.5.6 - 2026-09-23

### Fixed

- **A critical error every ten minutes from the stale-payment cleanup on stores using classic order storage.** The cleanup cron asked WooCommerce for the orders that carry a Mollie payment attempt, but stores that keep orders in the posts table ignore that filter (WooCommerce only logs a debug notice). The cron therefore loaded the store's oldest orders of any status, refunds included, and crashed on the first refund it treated as an order ("Call to undefined method OrderRefund::is_paid()"). The same dropped filter meant abandoned terminal payments were only ever chased on the store's very oldest orders. The query now uses a filter both storage modes honour and asks for orders only, so the cron scans exactly the orders with a Mollie attempt and refunds never reach it. Stores on High-Performance Order Storage were not affected.

## 0.5.5 - 2026-09-08

### Fixed

- **POS orders paid with Mollie ignored the per-gateway order status.** WooCommerce POS picks the status for a paid order from the gateway recorded on the order, but a Mollie payment is created and completed over AJAX or the webhook, never through the WooCommerce pay form that records the chosen gateway. The order kept an empty or default (cash) payment method, so the POS fell back to "Completed" whatever was configured for Mollie Terminal under POS → Settings → Checkout. The gateway is now recorded on the order just before it is marked paid, so the configured status is honoured on the poll, the webhook and the stale-payment sweep, and WooCommerce routes refunds for these orders to this gateway. It is deliberately not recorded when a payment merely starts: an abandoned terminal attempt must not leave Mollie on an order that is then paid another way.

## 0.5.4 - 2026-09-05

### Fixed

- Refunds now reuse the refund record WooCommerce just created instead of creating a duplicate that double-counted refunded totals. If no matching refund exists, the request is rejected without contacting Mollie.

## 0.5.3 - 2026-09-05

### Fixed

- **POS payments were refused when the WooCommerce → Payments checkbox was off.** The 0.5.1 guard treated that checkbox as the only gateway switch, but WooCommerce POS enables gateways from POS → Settings → Checkout and ignores the WooCommerce one, so every POS-only setup (the recommended configuration) got "Mollie Terminal is disabled." when starting a payment or listing terminals. The guard now honours either switch: a gateway enabled in WooCommerce or in WooCommerce POS can take payments; one switched off in both places is still refused.
- The Enable/Disable checkbox is relabelled "Enable Mollie Terminal for web checkout (not necessary for WooCommerce POS)" with a description explaining where the POS switch lives, matching the other WCPOS terminal plugins. The old "for checkout/POS" label implied the POS needed it.

## 0.5.2 - 2026-09-03

### Fixed

- The on-screen QR code rendered as a thin strip beside its caption in the POS checkout: theme and WooCommerce rules for payment-method icons (inline, floated, height-capped `img`) overrode the image size. The QR is now pinned as a 240px square block with its own centred caption, regardless of theme CSS.

## 0.5.1 - 2026-09-03

### Fixed

- Starting a payment and listing terminals now refuse to act when the gateway is switched off in WooCommerce → Payments. Previously a cashier, or anyone holding a still-valid order token, could start a Mollie payment for an unpaid order after the merchant disabled the gateway (#12). Poll, cancel and the webhook are unchanged, so a payment already in flight still settles and can still be canceled from the panel.

## 0.5.0 - 2026-09-03

### Added

- Optional on-screen QR payments for iDEAL and Bancontact. Merchants choose the enabled methods in the gateway settings; cashiers switch between **Terminal** and **QR code** in the checkout panel.
- QR payment creation requests Mollie's `details.qrCode`, displays the returned image, and uses the existing poll, webhook, reconciliation, cancellation, and payment-lock flow.
- iDEAL QR can be tested with a Mollie test API key even when no physical terminal is available.

### Changed

- Payment attempts now record their Mollie method so reconciliation can verify terminal, iDEAL, and Bancontact payments against the method that was started.
- The premature-submit notice now applies equally to terminal and QR payments.

## 0.4.0 - 2026-07-09

### Fixed

- Cashiers are no longer trapped when a terminal is off or unresponsive. If Mollie will not cancel the open payment, the attempt is now abandoned locally: the panel returns to idle so a fresh payment (on the same or a different terminal) can be started without creating a new order. Previously the panel kept polling and the stuck payment was reused on the next Start.
- Refreshing the checkout mid-payment no longer drops the cashier back to an idle panel — the panel now resumes polling the open payment on page load.
- Added a server-side stale-payment sweep (WP-Cron, every 10 minutes) that cancels payments left open past a threshold on still-payable orders — the backstop for when the browser is closed or the network drops before the auto-cancel or cancel-beacon can fire. Threshold is filterable via `mtfwc_stale_payment_seconds`.
- A payment abandoned locally (terminal unresponsive) is no longer invisible to the stale-payment sweep. Its ID is kept on the order, so the sweep keeps retrying the cancel until Mollie accepts it — or completes the order if the terminal turns out to have taken the payment. Previously such a payment could stay open at Mollie indefinitely.
- Switching payment method at the exact moment the terminal approves the payment no longer strands a paid order on another method. The panel now waits for the cancel response and finishes the order when the server reports it as paid, instead of reporting "Payment canceled".

### Changed

- Payment panel redesign: the standalone **Check Status** button is gone (the panel shows a static "idle" status when ready and polls automatically once started), and **Start Terminal Payment** now toggles to **Cancel Terminal Payment** while a payment is in flight — one button instead of three.
- Switching the order to another payment method (e.g. cash) now stops the terminal poll loop and cancels the open payment, instead of leaving it polling and open at Mollie.
- The premature "not paid yet" message shown when the order is submitted before the terminal confirms is now a friendlier, non-error notice explaining what to do.

### Added

- New **Checkout debug logs** setting (off by default): hides the on-panel log tools (Show logs / Copy / Clear). Payment activity is still recorded in WooCommerce → Status → Logs regardless.
- New **API key source** setting: optionally reuse the API key already configured in the official *Mollie Payments for WooCommerce* plugin (matched to the selected mode), so keys are managed in one place. Falls back to this plugin's own key when the Mollie plugin has none.
- The **Enabled terminals** setting now states that the default terminal is always available at checkout, even when not selected.
- Internationalisation: the plugin now loads translations from `/languages`, ships a `.pot` template, and includes an initial Dutch (`nl_NL`) translation.

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
