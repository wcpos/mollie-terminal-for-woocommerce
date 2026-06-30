# Mollie Diagnostics Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Stripe-style visible diagnostics for Mollie Terminal payment attempts on checkout/order-pay pages and persist safe server-side diagnostic events for support.

**Architecture:** Add a small `Diagnostics` service responsible for redacted recent events and the “last API error” option. Reuse existing gateway rendering and assets to add a checkout/order-pay log panel with browser-side activity logs. Record server-side events from existing AJAX, Mollie API, payment, and webhook paths without changing payment semantics.

**Tech Stack:** PHP 7.4 WordPress/WooCommerce gateway code, WooCommerce logger/options/order meta, plain JavaScript, CSS, Composer regression scripts.

---

## Task 1: Server diagnostics storage

**Files:**
- Create: `tests/regression/diagnostics.php`
- Create: `includes/Diagnostics.php`
- Modify: `includes/Logger.php`

- [ ] **Step 1: Write failing test**

Create a regression script that requires `includes/Diagnostics.php` and expects `Diagnostics::record_api_error()` to update `mtfwc_last_api_error`, append a redacted event to `mtfwc_recent_diagnostic_events`, cap event count, and let `Logger::log()` append a redacted support event.

- [ ] **Step 2: Verify RED**

Run `php tests/regression/diagnostics.php`. Expected failure before implementation: missing `Diagnostics.php` or missing diagnostics methods.

- [ ] **Step 3: Implement diagnostics**

Create `Diagnostics` with `record()`, `record_api_error()`, `recent_events()`, `last_api_error()`, context/message redaction, and max-event truncation. Update `Logger::log()` to call `Diagnostics::record()` after writing to WooCommerce logs.

- [ ] **Step 4: Verify GREEN**

Run `php tests/regression/diagnostics.php`. Expected output: `diagnostics ok`.

## Task 2: Checkout/order-pay log panel markup

**Files:**
- Create: `tests/regression/payment-fields-logs.php`
- Modify: `includes/AjaxHandler.php`
- Modify: `includes/Gateway.php`

- [ ] **Step 1: Write failing test**

Create a regression script that stubs WooCommerce gateway helpers, renders `Gateway::payment_fields()`, and asserts the output includes `mtfwc-payment-interface`, `mtfwc-payment-log-textarea`, show/clear/copy log controls, and an order-pay start button with an order id and order token.

- [ ] **Step 2: Verify RED**

Run `php tests/regression/payment-fields-logs.php`. Expected failure before implementation: `Gateway::payment_fields()` does not render the log panel.

- [ ] **Step 3: Implement markup and shared order token**

Expose `AjaxHandler::order_token()` as a public static helper and use it in `Gateway::payment_fields()`. Render description, order-pay controls when an order id exists, and the collapsible logs panel on both checkout and order-pay pages.

- [ ] **Step 4: Verify GREEN**

Run `php tests/regression/payment-fields-logs.php`. Expected output: `payment-fields-logs ok`.

## Task 3: Diagnostic event instrumentation and browser logs

**Files:**
- Modify: `includes/AjaxHandler.php`
- Modify: `includes/Services/MollieApiClient.php`
- Modify: `includes/Services/MolliePaymentService.php`
- Modify: `includes/WebhookHandler.php`
- Modify: `includes/Gateway.php`
- Modify: `assets/js/payment.js`
- Modify: `assets/css/payment.css`

- [ ] **Step 1: Add server events**

Record safe events for AJAX received/success/failure, Mollie API success/error, payment create/reuse/poll/cancel, and webhook receipt/failure. Ensure API errors update `mtfwc_last_api_error`.

- [ ] **Step 2: Add frontend behavior**

Use localized `mtfwcPaymentData.ajaxUrl` and `defaultTerminalId`. Append timestamped log lines to the textarea, persist browser logs in sessionStorage per order/page, support Show/Hide, Clear, Copy, Start Payment, Check Status, and Cancel Payment buttons.

- [ ] **Step 3: Style panel**

Style a utilitarian dark log panel with accessible buttons and readable monospace log output, matching the diagnostic purpose rather than adding decorative UI.

## Task 4: Version, release notes, and validation

**Files:**
- Modify: `mollie-terminal-for-woocommerce.php`
- Modify: `CHANGELOG.md`
- Create: `docs/releases/0.1.2.md`

- [ ] **Step 1: Bump version**

Update plugin header and `MTFWC_VERSION` from `0.1.1` to `0.1.2`.

- [ ] **Step 2: Add release notes**

Document the checkout/order-pay log panel, persisted redacted diagnostics, and last API error fix in `CHANGELOG.md` and `docs/releases/0.1.2.md`.

- [ ] **Step 3: Run validation**

Run `composer run lint && composer run test`. Expected output: every PHP file has no syntax errors, and all regression scripts print `ok`.
