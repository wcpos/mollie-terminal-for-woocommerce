# QR-on-screen payments (iDEAL / Bancontact)

**Status:** approved design, 2026-09-03.
**Origin:** beta-feedback item 11 (`docs/feedback/2026-07-08-beta-feedback-plan.md`). The
tester wanted QR for customers with NFC disabled. Mollie still does not expose a QR on the
terminal screen through the Payments API, but it does return a scannable QR for iDEAL and
Bancontact payments created with `include=details.qrCode`. This feature ships that variant:
the QR is rendered in the POS checkout panel and the customer scans it with their banking app.

## Goals

- A merchant can enable iDEAL and/or Bancontact QR in the gateway settings.
- At checkout the cashier chooses **Terminal** or **QR code**, starts the payment, and the QR
  appears in the panel. Payment state is tracked by the existing poll / webhook / sweep code.
- Merchants who do not enable QR see no change at checkout.

## Non-goals

- Bank transfer QR (no real-time confirmation).
- Payment-link QR (different product, long-lived links).
- QR displayed on the terminal's own screen (not in Mollie's API).
- Pre-validating Bancontact's €1500 QR cap; Mollie's own error message is surfaced.
- A customer-facing display. The cashier shows the screen to the customer.

## Mollie facts this design relies on

- `POST /v2/payments?include=details.qrCode` with `method` = `ideal` or `bancontact` returns
  `details.qrCode = { height, width, src }`. `src` is either a `data:image/...` URI or an
  `https://` URL; both must be supported.
- `GET /v2/payments/{id}?include=details.qrCode` returns the same object while the payment is
  `open`. It is absent once the payment leaves `open`.
- iDEAL QR is returned only when no issuer is preselected (we never set one).
- `redirectUrl` is required for these methods. The customer's phone lands on it after paying.
- iDEAL QR works with a test API key; Bancontact QR does not.

## Settings

New gateway option `qr_methods`, a `multiselect` (`wc-enhanced-select`) titled
**QR code payments** with options `ideal` → "iDEAL" and `bancontact` → "Bancontact".
Default: empty (feature off). Description (desc_tip): "Let cashiers show a QR code on screen
that the customer scans with their banking app. The method must be active on your Mollie
profile. iDEAL QR also works in test mode, so it can be tried without a terminal."

`Settings::qr_methods(): array` returns the saved values filtered to the two valid keys, in
the fixed order iDEAL, Bancontact. A string value (WooCommerce sometimes stores one) is
normalised the same way `enabled_terminal_ids()` does.

The test-mode notice in `admin_options()` gains one sentence: "iDEAL QR payments do work in
test mode."

## Checkout panel (`Gateway::payment_fields()`)

When `qr_methods()` is empty the markup is unchanged.

When non-empty, the root element gets `data-qr-methods="ideal,bancontact"` (comma-joined),
and the panel gains, in this order:

1. A channel toggle above the terminal field:
   ```html
   <div class="mtfwc-channel-toggle" role="radiogroup" aria-label="Payment channel">
     <button type="button" class="mtfwc-channel" data-channel="terminal" aria-pressed="true">Terminal</button>
     <button type="button" class="mtfwc-channel" data-channel="qr" aria-pressed="false">QR code</button>
   </div>
   ```
2. A QR field, hidden by default, placed after the terminal field:
   ```html
   <div class="mtfwc-qr-field" hidden>
     <label class="mtfwc-terminal-label" for="mtfwc-qr-method-select">Payment method</label>
     <select id="mtfwc-qr-method-select" class="mtfwc-qr-method-select">
       <option value="ideal">iDEAL</option>
       <option value="bancontact">Bancontact</option>
     </select>
   </div>
   ```
   The select is rendered only when two methods are enabled. With one method the field holds
   a plain `<span class="mtfwc-qr-method-single" data-method="ideal">iDEAL</span>` instead.
3. A QR display, hidden by default, placed between the actions row and the status line:
   ```html
   <div class="mtfwc-qr-code" hidden>
     <img class="mtfwc-qr-image" alt="Payment QR code">
     <p class="mtfwc-payment-help">Ask the customer to scan this with their banking app.</p>
   </div>
   ```

The help text at the top of the panel becomes: "Send this order to a Mollie terminal, or
show a QR code for the customer to scan. The payment completes automatically once Mollie
confirms." (only when QR is enabled; otherwise unchanged).

Resume: when the page loads with an open attempt whose recorded method is a QR method, the
root gets `data-resume-channel="qr"` (else `terminal`) so the JS restores the right mode.

## AJAX contract

`mtfwc_start_payment` accepts two new POST fields:

- `channel`: `terminal` (default) or `qr`.
- `qr_method`: `ideal` or `bancontact`. Required when `channel=qr`.

Server rules in `AjaxHandler::mtfwc_start_payment()`:

- `channel=qr` with a `qr_method` not in `Settings::qr_methods()` → `wp_send_json_error(
  'QR code payments are not enabled for this method.', 400 )`.
- `lock_terminal` only affects `channel=terminal`.
- `channel=qr` calls `MolliePaymentService::start_qr_payment_for_order( $order, $qr_method )`.

Responses of `mtfwc_start_payment` and `mtfwc_poll_payment` gain an optional top-level
`qr_code` key: `{ "src": string, "width": int, "height": int }`. It is present only when the
attempt is a QR payment, the payment status is `open`, and `src` starts with `data:image/` or
`https://`. Everything else about the responses is unchanged.

## Payment service

`MolliePaymentService`:

- Split the body of `start_payment_for_order()` into a shared private `start_attempt( $order,
  string $method, string $terminal_id, callable $build_payload )` (or equivalent) so the
  already-paid guard, the reuse-open-attempt logic, lock handling, `record_new`, and logging
  are written once. Keep `start_payment_for_order( $order, string $terminal_id = '' )` as the
  terminal entry point with the same behaviour and payload as today.
- Add `start_qr_payment_for_order( $order, string $method ): array`. Payload:
  ```php
  array(
    'amount'      => array( 'currency' => ..., 'value' => ... ),
    'description' => sprintf( 'Order #%s', $order->get_order_number() ),
    'method'      => $method,                // 'ideal' | 'bancontact'
    'redirectUrl' => $order->get_checkout_order_received_url(),
    'webhookUrl'  => $this->settings->webhook_url(),
    'metadata'    => array( 'order_id' => (string) $order->get_id(), 'channel' => 'qr' ),
  )
  ```
  No `terminalId`, no terminal validation. Created via
  `$this->client->create_payment( $payload, array( 'details.qrCode' ) )`.
- The reuse path (an open attempt already exists) returns the existing attempt regardless of
  which channel the cashier now picked; the response's `qr_code` (if the attempt is QR) lets
  the UI show the right thing. This mirrors today's behaviour for terminals.
- `poll_order()`: when the current attempt's method is a QR method, fetch with
  `get_payment( $id, array( 'details.qrCode' ) )` and attach `qr_code` to the result when
  present.
- Add `public static function qr_code_from_payment( array $payment ): ?array` implementing the
  filtering rule above (status `open`, src prefix check, width/height cast to int). The
  AJAX layer does not inspect the raw payment.
- Cancel / abandon / abandoned-sweep code is unchanged. (A QR payment is always cancelable
  while open; the abandon path is simply never reached.)

`MollieApiClient`:

- `create_payment( array $payload, array $include = array() )` and
  `get_payment( string $payment_id, array $include = array() )`. When `$include` is non-empty
  append `?include=` + comma-joined, rawurlencoded values.

## Payment attempt

`PaymentAttempt`:

- New meta `_mtfwc_current_payment_method` (`META_CURRENT_PAYMENT_METHOD`).
- `record_new( $order, array $payment, string $terminal_id, string $mode, string $method =
  'pointofsale' )` stores it on the current attempt and in the history entry.
- `current()` returns `'method' => string` (empty for attempts recorded before this change).
- `abandon_current()` deletes the key with the other current-attempt keys.
- Add `public static function is_qr_method( string $method ): bool` → `ideal` or `bancontact`.

## Reconciler

`PaymentReconciler::verify_payment()` replaces the hard `pointofsale` check with:

- Look up the method this shop recorded for `$payment['id']`: the current attempt first,
  then the attempt history (abandoned attempts keep their history entry), then the
  abandoned-ID list.
- Unknown payment ID → `payment is not known for this order`. Every payment the plugin
  creates is written to history, so an unknown ID cannot pay this order even when its
  `metadata.order_id` collides (shops sharing one Mollie profile).
- Recorded method present → the payment's method must equal it (`payment method mismatch`).
  This holds even if the merchant has since disabled that QR method.
- Known ID without a recorded method (attempts from before 0.5.0) → the method must be
  `pointofsale` or one of `Settings::qr_methods()` (`payment method is not supported`).

The start response (created or reused) also carries `channel` (`terminal`|`qr`) and `method`,
so the panel can follow a reused attempt that lives on the other channel.

## JavaScript (`assets/js/payment.js`)

- `selectedChannel(root)`: `qr` when the toggle's pressed button is `qr`, else `terminal`.
  Panels without a toggle are always `terminal`.
- `selectedQrMethod(root)`: value of `.mtfwc-qr-method-select`, or the `data-method` of
  `.mtfwc-qr-method-single`.
- Toggle click: set `aria-pressed`, show/hide `.mtfwc-terminal-field` and `.mtfwc-qr-field`,
  update the primary button label (`startAction` vs `startQrAction`). The toggle is disabled
  while a payment is in flight, exactly like the terminal select.
- Start: in QR mode post `channel=qr&qr_method=…` and skip the "select a terminal first"
  guard. Status text `sendingQr` ("Creating QR code…") instead of `sending`.
- On any start/poll response that carries `qr_code`, call `showQr(root, qr)`: set the image
  `src`, `width`, `height`, unhide `.mtfwc-qr-code`. Status text `waitingQr` ("Waiting for the
  customer to scan…") while polling in QR mode.
- `hideQr(root)` clears `src` and hides the block. Call it from `resetToIdle`, `showIdle`,
  `completeOrder`, and after cancel / abandon / method-switch / timeout.
- Resume: `data-resume-channel="qr"` selects the QR toggle before `startAutoPoll`.
- Cancel button label in QR mode: `cancelQrAction` ("Cancel QR payment").
- The existing `responseSummary()` log redaction must not log the QR `src` (it can be a large
  data URI): log `qr_code: true/false` only.

New `i18n` keys in `enqueue_payment_scripts()`: `startQrAction` "Show QR code",
`cancelQrAction` "Cancel QR payment", `sendingQr` "Creating QR code…", `waitingQr` "Waiting
for the customer to scan…", `qrUnavailable` "Mollie did not return a QR code. Try again or
use the terminal.".

## CSS (`assets/css/payment.css`)

- `.mtfwc-channel-toggle`: flex row, buttons share the existing `.button` look;
  `[aria-pressed="true"]` uses the primary colour already used by `.button-primary`.
- `.mtfwc-qr-code`: centred; `.mtfwc-qr-image` max-width 240px, white padding, light border.

## Other user-facing text

- `Gateway::process_payment()` failure notice becomes: "This order has not been paid yet.
  Start the payment above and wait for Mollie to confirm — the order finishes on its own. If
  the customer has already paid, give it a few seconds and try again."

## Tests (plain PHP scripts + the Node harness, run by `composer test`)

- `tests/regression/qr-payment-payload.php`: `start_qr_payment_for_order()` sends
  `method=ideal`, no `terminalId`, `metadata.channel=qr`, and the client is called with the
  `details.qrCode` include; the result contains a `qr_code` for an `open` payment.
- `tests/regression/qr-code-filter.php`: `qr_code_from_payment()` returns null for non-open
  status, missing details, `http://` or `javascript:` src; returns the trimmed array for
  `data:image/png;base64,…` and `https://` src.
- `tests/regression/payment-reconciler-method.php`: recorded `ideal` + Mollie `ideal` passes;
  recorded `ideal` + Mollie `pointofsale` fails with `payment method mismatch`; no recorded
  method + `bancontact` passes; no recorded method + `creditcard` fails.
- `tests/regression/settings-qr-methods.php`: normalisation of string / array / invalid values.
- `tests/regression/payment-js.js`: add cases for toggle switching labels and fields, start in
  QR mode posting `channel` and `qr_method`, `qr_code` in the response showing the image,
  and a final status hiding it.
- Existing `payment-payload.php` must keep passing unchanged (terminal payload unaffected).

## i18n

Add every new string to `languages/mollie-terminal-for-woocommerce.pot` and to the `nl_NL`
`.po`, then rebuild the `.mo` with `msgfmt`. Suggested Dutch: "QR-code" / "Terminal" /
"Toon QR-code" / "QR-betaling annuleren" / "QR-code wordt aangemaakt…" / "Wachten tot de
klant scant…" / "Laat de klant deze scannen met de bank-app." / "QR-codebetalingen".

## Docs

- README: new "QR code payments" section (what it is, NL/BE, enable in settings, iDEAL QR
  testable in test mode, customer lands on the order-received page).
- CHANGELOG: `0.5.0` entry.
- `docs/releases/0.5.0.md` in the style of `0.4.0.md`.
- `docs/feedback/2026-07-08-beta-feedback-plan.md` item 11: note that on-screen QR shipped in
  0.5.0 and the terminal-screen variant remains blocked on Mollie.
- Plugin header / `MTFWC_VERSION` bump to `0.5.0`.
