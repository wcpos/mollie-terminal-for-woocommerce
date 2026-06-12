# Revised Mollie Terminal Implementation Plan

Core principle: local WooCommerce meta is a cache. Mollie fetched payment/refund state is authoritative.

Required components: `PaymentLock`, `PaymentAttempt`, `PaymentReconciler`, `RefundReconciler`.

Tasks: scaffold plugin; settings and API client; money utility; payment lock; payment attempt model; terminal/profile services; pairing-code admin UI; safe payment service; reconciler; AJAX start/poll/cancel; webhook handler; refund reconciler; gateway refund integration; POS/admin UI polish; full test matrix; docs and packaging; adversarial review before PR.

Safety requirements: all creation, webhook, polling, cancellation, and refunds route through lock + fetch + reconcile flows; webhooks trust only payment ID; retries never create duplicates while remote state is open/pending/paid; refunds are idempotent by Woo refund meta and Mollie refund metadata.
