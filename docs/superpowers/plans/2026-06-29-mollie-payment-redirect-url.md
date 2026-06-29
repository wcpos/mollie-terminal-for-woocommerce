# Mollie Payment Redirect URL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Mollie Terminal payment creation match the documented API-key flow by sending `redirectUrl` and not sending `profileId` in `POST /v2/payments`.

**Architecture:** Keep the change localized to `MolliePaymentService`, where the payment payload is assembled. Use the existing WooCommerce order return URL as `redirectUrl`. Add a regression script that instantiates the service with fake Mollie/terminal dependencies and inspects the captured payload.

**Tech Stack:** PHP 7.4-compatible WordPress/WooCommerce plugin code, Mollie Payments API, Composer script-based regression tests.

---

### Task 1: Regression test for API-key-compatible POS payload

**Files:**
- Create: `tests/regression/payment-payload.php`
- Modify: none
- Test: `composer run test`

- [ ] **Step 1: Write the failing test**

Create `tests/regression/payment-payload.php` with WordPress/WooCommerce stubs, fake Mollie client/terminal service classes, and assertions that the captured payload contains `redirectUrl` and omits `profileId`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/regression/payment-payload.php`
Expected: FAIL because the current payload sends `profileId` and does not send `redirectUrl`.

- [ ] **Step 3: Implement minimal code**

Modify `includes/Services/MolliePaymentService.php` inside `start_payment_for_order()` so the `$payload` includes:

```php
'redirectUrl' => $order->get_checkout_order_received_url(),
```

and remove:

```php
'profileId' => $this->settings->profile_id(),
```

- [ ] **Step 4: Run focused and full tests**

Run: `php tests/regression/payment-payload.php`
Expected: PASS with `payment-payload ok`.

Run: `composer run lint && composer run test`
Expected: PASS with all PHP files syntax-clean and all regression scripts reporting `ok`.

- [ ] **Step 5: Commit**

Run:

```bash
git add docs/superpowers/plans/2026-06-29-mollie-payment-redirect-url.md tests/regression/payment-payload.php includes/Services/MolliePaymentService.php
git commit -m "fix: align Mollie terminal payment payload"
```
