# Beta feedback triage — 2026-07-08 (Selah Therapie)

Tester feedback from live use of v0.3.x, organised into an actionable plan.
Source: direct tester messages + 3 screenshots (payment panel, POS address
modal, premature-submit notice).

## Confirmed working — no action

- Successful terminal payment now processes the order and lands on the
  invoice/thank-you screen.
- Cancelling on the terminal itself marks the Mollie payment canceled, and
  re-pressing **Start Terminal Payment** starts a fresh session for the same
  order number.
- The default terminal is always selectable even when excluded from the
  "Enabled terminals" list — intended behaviour (`Settings::enabled_terminal_ids()`),
  but needs labelling in the settings UI (see P2-8).

---

## P0 — Bugs / recovery paths

### 1. Cashier is trapped when the terminal is off or unresponsive

**Report:** terminal was selected but switched off → no way to cancel the
attempt. Refreshing the page was not enough; the tester had to create a whole
new order before the terminal would respond again.

**Code context:** `MolliePaymentService::cancel_order_payment()` returns
`not_cancelable` when Mollie reports the payment cannot be canceled
(`includes/Services/MolliePaymentService.php:101`); the JS then *resumes
polling* (`onCancel`, `assets/js/payment.js:501-508`), so the cashier stays
locked in the waiting state until the 5-minute poll timeout.

**Actions:**
- [ ] Reproduce with live evidence (WC status logs + Mollie dashboard):
      confirm that a payment created for an offline terminal sits `open` with
      `isCancelable: false`.
- [ ] Add a **local abandon** path: when Mollie refuses the cancel, let the
      cashier abandon the attempt anyway — stop polling, unlock the panel and
      terminal select, allow a fresh Start (new Mollie payment, possibly on a
      different terminal). The reconciler/webhook already handles the
      "abandoned payment later succeeds" race (`conflict` → paid).
- [ ] Investigate why a *new order* was required — likely the stored
      `PaymentAttempt` / `PaymentLock` kept the stale payment pinned to the
      order. Abandon must clear/supersede the attempt server-side.

### 2. Stale `open` payments linger on the Mollie account

**Report:** an order sat `open` in the Mollie dashboard for 20+ minutes after
a browser refresh mid-payment (probably the same unresponsive-terminal case).
Tester asks: can WCPOS cancel these via the API?

**Code context:** the `pagehide` beacon (`assets/js/payment.js:632`) is
best-effort and clearly missed; `PaymentCleanup` only fires on an order
status change, and the auto-poll timeout cancel only runs while the page is
still open.

**Actions:**
- [ ] **Resume on reload:** when the panel renders and the order has a
      non-final payment attempt, resume the poll loop (showing "Waiting for
      terminal…") instead of rendering idle. Fixes both the stale payment and
      the lost-control-after-refresh complaint.
- [ ] **Cron sweep:** WP-Cron task that cancels non-final attempts older than
      the poll timeout on orders still `pending` — the server-side backstop
      the tester is asking for ("the backend should give the cancel to Mollie
      anyhow after a time-out").

---

## P1 — Payment panel UX redesign (tester's proposal — endorsed)

### 3. Remove the "Check Status" button; auto-check on panel open
Fire one status poll automatically when the panel is shown and display the
result ("Mollie Terminal status: idle") in the status banner. The auto-poll
loop already covers in-flight checking, so the manual button adds nothing.
(Pressing it or Cancel before Start "has no extra value" — confirmed.)

### 4. Merge Start/Cancel into one toggling button
"Start Terminal Payment" becomes "Cancel terminal payment" while an attempt
is in flight, then reverts. Removes a whole button row; the standalone Cancel
button disappears. Depends on P0-1's local-abandon so the cancel action always
returns control.

### 5. Stop polling when the cashier switches payment method
There is currently no listener on payment-method change — if the customer
changes their mind and the cashier picks Cash, the poll loop keeps running
and the Mollie payment stays open. Hook the checkout payment-method change:
stop the loop and cancel/abandon the attempt.

### 6. Restyle the premature-submit notice
Pressing "Betaling verwerken" before the terminal confirms shows the plain
WooCommerce error notice ("No completed Mollie Terminal payment was found…",
`includes/Gateway.php:314`). Message is correct but should be styled/placed so
it is clear what is happening. Consider also disabling the place-order button
while a terminal payment is in flight.

---

## P2 — Settings & polish

### 7. Setting to hide the checkout log tools
Add a gateway setting (default: hidden) for the LOGS section
(Show logs / Copy / Clear) rendered in `Gateway::payment_fields()`. Merchants
enable it only when support asks for logs.

### 8. Label the default-terminal behaviour in settings
On the "Enabled terminals" field description (`includes/Gateway.php:89`),
state that the default terminal is *always* selectable at checkout even when
not ticked.

### 9. i18n + Dutch translation
All PHP strings use the `mollie-terminal-for-woocommerce` text domain and the
JS reads localized strings, but:
- [ ] no `load_plugin_textdomain()` call / `languages/` directory exists;
- [ ] no `.pot` file is generated (`wp i18n make-pot`);
- [ ] one hardcoded JS string: `'Mollie Terminal status: '`
      (`assets/js/payment.js:538`).
Tester has offered to review the Dutch (`nl_NL`) translation — take him up
on it once the POT exists.

---

## P3 — Backlog / watch

### 10. Reuse the API key from the main Mollie plugin (opt-in)
`mollie-payments-for-woocommerce` stores its keys in `wp_options`
(`mollie-payments-for-woocommerce_live_api_key` / `_test_api_key`), readable
by any plugin. Add an opt-in "Use the Mollie plugin's API key" toggle so keys
have a single entry point. Note: this is a *convenience*, not a security
improvement — our key lives in `wp_options` (gateway settings) too, which is
standard WP practice.

### 11. QR-code payments
Mollie has confirmed to the tester that QR-initiated terminal payments are
not yet exposed in their Payments API (currently iOS-app only). Useful for
customers with NFC disabled. **Blocked on Mollie** — watch item; revisit when
their API supports it.

---

## Out of scope for this plugin

### 12. Tab order in the POS "Bewerk klantadres" modal
Tab lands on the field *label* between inputs, so every field needs two Tab
presses. That UI belongs to the WCPOS client (`woocommerce-pos`), not this
plugin — file there. (Likely fix: labels should not be in the tab order /
`tabIndex` cleanup in the address edit form.)

---

## Suggested release slicing

- **v0.3.2 (bugfix):** P0-1, P0-2 — recovery paths; nothing visual changes.
- **v0.4.0 (panel redesign):** P1-3/4/5/6 + P2-7/8 — one coherent UI change
  set for the tester to re-test in one go.
- **v0.4.x:** P2-9 i18n/Dutch, P3-10 key reuse.
