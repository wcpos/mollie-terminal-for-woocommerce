(function () {
	'use strict';

	var MAX_LOG_LINES = 50;
	var SENSITIVE_KEY_PATTERN = /(key|token|secret|authorization|password|bearer|metadata|customer|email)/i;
	var DEFAULT_POLL_INTERVAL_MS = 2000;
	var DEFAULT_POLL_TIMEOUT_MS = 300000;

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
		} else {
			callback();
		}
	}

	function now() {
		return new Date().toISOString();
	}

	function nowMs() {
		return (typeof Date !== 'undefined' && Date.now) ? Date.now() : 0;
	}

	function later(fn, ms) {
		return ('function' === typeof setTimeout) ? setTimeout(fn, ms) : null;
	}

	function clearLater(id) {
		if (id && 'function' === typeof clearTimeout) {
			clearTimeout(id);
		}
	}

	function getData() {
		return window.mtfwcPaymentData || { ajaxUrl: '/wp-admin/admin-ajax.php', defaultTerminalId: '', i18n: {} };
	}

	function t(key, fallback) {
		var i18n = getData().i18n || {};
		return i18n[key] || fallback;
	}

	function storageKey(root) {
		return 'mtfwc_payment_logs_' + (root.getAttribute('data-order-id') || 'checkout');
	}

	function trimLogLines(value) {
		var lines = value ? String(value).split('\n') : [];
		if (lines.length > MAX_LOG_LINES) {
			return lines.slice(-MAX_LOG_LINES).join('\n');
		}
		return value || '';
	}

	function redactString(value) {
		return String(value)
			.replace(/Bearer\s+[A-Za-z0-9._-]+/ig, 'Bearer ***')
			.replace(/(test|live)_[A-Za-z0-9]{20,}/g, '$1_***');
	}

	function redactDetail(detail) {
		var safe = {};
		var key;
		if (null === detail || undefined === detail) {
			return detail;
		}
		if ('string' === typeof detail) {
			return redactString(detail);
		}
		if ('number' === typeof detail || 'boolean' === typeof detail) {
			return detail;
		}
		if (Array.isArray(detail)) {
			return detail.map(redactDetail);
		}
		if ('object' === typeof detail) {
			for (key in detail) {
				if (!Object.prototype.hasOwnProperty.call(detail, key)) {
					continue;
				}
				safe[key] = SENSITIVE_KEY_PATTERN.test(key) ? '***' : redactDetail(detail[key]);
			}
			return safe;
		}
		return String(detail);
	}

	function responseSummary(data) {
		var payment;
		var summary = {};
		if (!data || 'object' !== typeof data) {
			return redactDetail(data);
		}
		payment = data.payment && 'object' === typeof data.payment ? data.payment : {};
		if (data.status) {
			summary.status = data.status;
		}
		if (data.payment_status || payment.status) {
			summary.payment_status = data.payment_status || payment.status;
		}
		if (data.payment_id || payment.id) {
			summary.payment_id = data.payment_id || payment.id;
		}
		if (data.terminal_id || data.terminalId || payment.terminalId || payment.terminal_id) {
			summary.terminal_id = data.terminal_id || data.terminalId || payment.terminalId || payment.terminal_id;
		}
		if (Object.prototype.hasOwnProperty.call(data, 'reused')) {
			summary.reused = data.reused;
		}
		if (data.message) {
			summary.message = data.message;
		}
		return redactDetail(summary);
	}

	function appendLog(root, level, message, detail) {
		var textarea = root.querySelector('.mtfwc-payment-log-textarea');
		var line;
		if (!textarea) {
			return;
		}
		line = '[' + now() + '] [' + level.toUpperCase() + '] ' + message;
		if (detail) {
			try {
				line += ' ' + JSON.stringify(redactDetail(detail));
			} catch (e) {
				line += ' ' + redactString(detail);
			}
		}
		textarea.value = trimLogLines(textarea.value ? textarea.value + '\n' + line : line);
		textarea.scrollTop = textarea.scrollHeight;
		try {
			sessionStorage.setItem(storageKey(root), textarea.value);
		} catch (e) {}
	}

	function setStatus(root, message, level) {
		var status = root.querySelector('.mtfwc-payment-status');
		if (!status) {
			return;
		}
		status.textContent = message || '';
		status.className = 'mtfwc-payment-status' + (level ? ' mtfwc-status-' + level : '');
	}

	function restoreLogs(root) {
		var textarea = root.querySelector('.mtfwc-payment-log-textarea');
		if (!textarea) {
			return;
		}
		try {
			textarea.value = trimLogLines(sessionStorage.getItem(storageKey(root)) || '');
			sessionStorage.setItem(storageKey(root), textarea.value);
		} catch (e) {}
		if (!textarea.value) {
			appendLog(root, 'info', 'Mollie Terminal log panel ready.');
		}
	}

	function orderContext(root) {
		return {
			orderId: root.getAttribute('data-order-id') || '',
			orderToken: root.getAttribute('data-order-token') || ''
		};
	}

	function isLocked(root) {
		return root.getAttribute('data-lock-terminal') === '1';
	}

	function selectedTerminalId(root) {
		if (isLocked(root)) {
			// Selection is locked: the default terminal is always used (the
			// server enforces this too).
			return root.getAttribute('data-default-terminal-id') || getData().defaultTerminalId || '';
		}
		var select = root.querySelector('.mtfwc-terminal-select');
		if (select && select.value) {
			return select.value;
		}
		return root.getAttribute('data-default-terminal-id') || getData().defaultTerminalId || '';
	}

	// Low-level AJAX call: logs the request/response but does not touch the
	// status line or button state, so it is safe to reuse from the poll loop.
	function postAction(root, action, extra) {
		var data = getData();
		var ctx = orderContext(root);
		var body;
		var key;
		if (!ctx.orderId || ctx.orderId === '0') {
			appendLog(root, 'error', 'Cannot send Mollie Terminal request because no order ID is available yet.');
			return Promise.resolve({ ok: false, json: null, error: 'no_order' });
		}

		body = new FormData();
		body.append('action', action);
		body.append('order_id', ctx.orderId);
		body.append('order_token', ctx.orderToken);
		if (extra) {
			for (key in extra) {
				if (Object.prototype.hasOwnProperty.call(extra, key)) {
					body.append(key, extra[key]);
				}
			}
		}

		appendLog(root, 'info', 'Sending ' + action + ' request.', { order_id: ctx.orderId });

		var headers = {};
		if (root.getAttribute('data-pos') === '1') {
			// Lets woocommerce_pos_request() identify this AJAX call as coming
			// from the POS, so paid responses carry the POS thank-you URL.
			headers['X-WCPOS'] = '1';
		}

		return fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: headers, body: body })
			.then(function (response) {
				return response.text().then(function (text) {
					var json = null;
					try { json = JSON.parse(text); } catch (e) {}
					return { status: response.status, ok: response.ok, text: text, json: json };
				});
			})
			.then(function (result) {
				if (!result.ok || !result.json || !result.json.success) {
					appendLog(root, 'error', action + ' failed.', redactDetail(result.json || result.text || result.status));
				} else {
					appendLog(root, 'success', action + ' succeeded.', responseSummary(result.json.data || {}));
				}
				return result;
			})
			.catch(function (error) {
				appendLog(root, 'error', action + ' network error: ' + error.message);
				return { ok: false, json: null, error: error.message };
			});
	}

	function resultStatus(result) {
		if (result && result.json && result.json.data && result.json.data.status) {
			return String(result.json.data.status);
		}
		return '';
	}

	function resultRedirect(result) {
		if (result && result.json && result.json.data && result.json.data.redirect_url) {
			return String(result.json.data.redirect_url);
		}
		return '';
	}

	// Map a raw Mollie/reconciler status onto the flow's outcome buckets.
	function classify(status) {
		if ('paid' === status || 'already_paid' === status || 'conflict' === status) {
			return 'paid';
		}
		if ('failed' === status || 'canceled' === status || 'expired' === status || 'verification_failed' === status) {
			return 'failed';
		}
		if ('idle' === status) {
			return 'idle';
		}
		return 'pending';
	}

	// The panel has a single primary button that toggles between starting and
	// canceling the terminal payment.
	function setActionMode(root, mode) {
		var button = root.querySelector('.mtfwc-primary-action');
		if (!button) {
			return;
		}
		button.setAttribute('data-mtfwc-mode', mode);
		button.textContent = 'cancel' === mode
			? t('cancelAction', 'Cancel Terminal Payment')
			: t('startAction', 'Start Terminal Payment');
	}

	function actionMode(root) {
		var button = root.querySelector('.mtfwc-primary-action');
		return button ? (button.getAttribute('data-mtfwc-mode') || 'start') : 'start';
	}

	function setActionButtonsDisabled(root, disabled) {
		setButtonDisabled(root, '.mtfwc-primary-action', disabled);
	}

	function setButtonDisabled(root, selector, disabled) {
		var button = root.querySelector(selector);
		if (button) {
			button.disabled = disabled;
		}
	}

	// Drives the spinner on the status banner while a request/payment is in flight.
	function setBusy(root, busy) {
		root.setAttribute('data-mtfwc-busy', busy ? 'true' : 'false');
	}

	// The terminal choice is fixed once a payment is in flight. Re-enabling is
	// skipped for locked panels and for selects that are unavailable (no
	// terminals) or still loading.
	function setSelectDisabled(root, disabled) {
		var select = root.querySelector('.mtfwc-terminal-select');
		if (!select) {
			return;
		}
		if (disabled) {
			select.disabled = true;
			return;
		}
		if (isLocked(root) || select.getAttribute('data-mtfwc-unavailable') === '1' || select.getAttribute('aria-busy') === 'true') {
			return;
		}
		select.disabled = false;
	}

	function showIdle(root) {
		setStatus(root, t('idle', 'Mollie Terminal status: idle'), 'info');
	}

	// --- Auto-poll loop -------------------------------------------------------

	function stopAutoPoll(root) {
		if (root.mtfwcPoll) {
			clearLater(root.mtfwcPoll.timer);
			root.mtfwcPoll = null;
		}
	}

	// Enter the in-flight state: spinner on, terminal choice frozen, and the
	// primary button flipped to "Cancel". Used both after a fresh Start and when
	// resuming an already-open payment on page load.
	function startAutoPoll(root) {
		var data = getData();
		var interval = data.pollIntervalMs || DEFAULT_POLL_INTERVAL_MS;
		var timeout = data.pollTimeoutMs || DEFAULT_POLL_TIMEOUT_MS;
		stopAutoPoll(root);
		root.mtfwcPollSeq = (root.mtfwcPollSeq || 0) + 1;
		root.mtfwcPoll = { deadline: nowMs() + timeout, timer: null, id: root.mtfwcPollSeq };
		setBusy(root, true);
		setSelectDisabled(root, true);
		setStatus(root, t('waiting', 'Waiting for terminal…'), 'info');
		setActionMode(root, 'cancel');
		setButtonDisabled(root, '.mtfwc-primary-action', false);
		schedulePoll(root, interval);
	}

	function schedulePoll(root, interval) {
		if (!root.mtfwcPoll) {
			return;
		}
		root.mtfwcPoll.timer = later(function () {
			runPollTick(root, interval);
		}, interval);
	}

	function runPollTick(root, interval) {
		if (!root.mtfwcPoll) {
			return;
		}
		var session = root.mtfwcPoll.id;
		if (nowMs() > root.mtfwcPoll.deadline) {
			stopAutoPoll(root);
			// Don't leave the payment lingering "open" on the Mollie side —
			// after the timeout, actively cancel it.
			var seqAtTimeout = root.mtfwcPollSeq;
			setStatus(root, t('timedOutCanceling', 'Timed out waiting for the terminal — canceling the payment…'), 'error');
			// Freeze the button while the auto-cancel is in flight so a stray click
			// can't fire a second request; it re-enables in start mode on resolve.
			setActionButtonsDisabled(root, true);
			appendLog(root, 'warning', 'Auto-poll timed out; sending cancel to Mollie.');
			postAction(root, 'mtfwc_cancel_payment').then(function (result) {
				// A new attempt may have started while this cancel was in
				// flight — leave the new attempt's UI alone.
				if (root.mtfwcPoll || root.mtfwcCompleted || root.mtfwcPollSeq !== seqAtTimeout || root.getAttribute('data-mtfwc-request-pending') === 'true') {
					return;
				}
				if ('paid' === classify(resultStatus(result))) {
					// The customer paid at the very last moment — complete instead.
					completeOrder(root, resultRedirect(result));
					return;
				}
				setStatus(root, t('timedOut', 'Timed out waiting for the terminal. Check the terminal or try again.'), 'error');
				resetToIdle(root);
			});
			return;
		}
		postAction(root, 'mtfwc_poll_payment').then(function (result) {
			// Ignore a response that arrives after this poll session was stopped
			// or superseded (e.g. a cancel/retry started a new session).
			if (!root.mtfwcPoll || root.mtfwcPoll.id !== session) {
				return;
			}
			var outcome = classify(resultStatus(result));
			if ('paid' === outcome) {
				completeOrder(root, resultRedirect(result));
			} else if ('failed' === outcome) {
				stopAutoPoll(root);
				setStatus(root, t('failed', 'Payment failed. You can try again.'), 'error');
				resetToIdle(root);
			} else if ('idle' === outcome) {
				stopAutoPoll(root);
				showIdle(root);
				resetToIdle(root);
			} else {
				setStatus(root, t('waiting', 'Waiting for terminal…'), 'info');
				schedulePoll(root, interval);
			}
		});
	}

	// Return the panel to its idle state: spinner off, terminal choice unlocked,
	// and the primary button flipped back to "Start". Callers set their own
	// status message (e.g. "canceled", "failed") around this as appropriate.
	function resetToIdle(root) {
		setBusy(root, false);
		setSelectDisabled(root, false);
		setActionMode(root, 'start');
		setButtonDisabled(root, '.mtfwc-primary-action', false);
	}

	// Payment succeeded. The order is already reconciled and paid server-side,
	// so re-submitting the order-pay form would only hit WooCommerce's
	// "already paid" guard — navigate straight to the thank-you page instead
	// (a POS-aware URL supplied by the server, like Stripe/SumUp do). The
	// form-submit chain remains as a fallback when no redirect URL is known.
	function completeOrder(root, redirectUrl) {
		stopAutoPoll(root);
		setStatus(root, t('completing', 'Payment complete — finishing order…'), 'success');
		appendLog(root, 'success', 'Terminal payment complete; finishing order.', { redirect: !!redirectUrl });
		if (root.mtfwcCompleted) {
			return;
		}
		root.mtfwcCompleted = true;
		// Re-enable the controls (without overwriting the "finishing order…"
		// status) so the UI is not stuck if finishing the order does not
		// navigate away.
		setBusy(root, false);
		setSelectDisabled(root, false);
		setActionMode(root, 'start');
		setButtonDisabled(root, '.mtfwc-primary-action', false);

		if (redirectUrl && window.location) {
			window.location.href = redirectUrl;
			return;
		}
		var placeOrder = ('function' === typeof document.getElementById) ? document.getElementById('place_order') : null;
		if (placeOrder && 'function' === typeof placeOrder.click) {
			placeOrder.click();
			return;
		}
		var orderReview = ('function' === typeof document.getElementById) ? document.getElementById('order_review') : null;
		if (orderReview && 'function' === typeof orderReview.submit) {
			orderReview.submit();
			return;
		}
		if (window.top && 'function' === typeof window.top.postMessage) {
			window.top.postMessage({ action: 'wcpos-process-payment' }, '*');
		}
	}

	// --- User actions ---------------------------------------------------------

	// The primary button dispatches to Start or Cancel based on its current mode.
	function onPrimaryAction(root) {
		if ('cancel' === actionMode(root)) {
			onCancel(root);
		} else {
			onStart(root);
		}
	}

	function onStart(root) {
		if (root.getAttribute('data-mtfwc-request-pending') === 'true') {
			appendLog(root, 'warning', 'Ignoring duplicate Start while a request is pending.');
			return;
		}
		var terminalId = selectedTerminalId(root);
		if (!terminalId) {
			appendLog(root, 'warning', 'Start blocked because no terminal is selected yet.');
			setStatus(root, t('selectTerminal', 'Select a terminal first.'), 'warning');
			return;
		}
		root.mtfwcCompleted = false;
		root.setAttribute('data-mtfwc-request-pending', 'true');
		stopAutoPoll(root);
		setActionButtonsDisabled(root, true);
		setSelectDisabled(root, true);
		setBusy(root, true);
		setStatus(root, t('sending', 'Sending to terminal…'), 'info');
		postAction(root, 'mtfwc_start_payment', { terminal_id: terminalId }).then(function (result) {
			root.setAttribute('data-mtfwc-request-pending', 'false');
			if (!result || !result.ok || !result.json || !result.json.success) {
				setStatus(root, t('failed', 'Payment failed. You can try again.'), 'error');
				resetToIdle(root);
				return;
			}
			var outcome = classify(resultStatus(result));
			if ('paid' === outcome) {
				completeOrder(root, resultRedirect(result));
			} else if ('failed' === outcome) {
				setStatus(root, t('failed', 'Payment failed. You can try again.'), 'error');
				resetToIdle(root);
			} else {
				startAutoPoll(root);
			}
		});
	}

	function onCancel(root) {
		if (root.getAttribute('data-mtfwc-request-pending') === 'true') {
			appendLog(root, 'warning', 'Ignoring duplicate Cancel while a request is pending.');
			return;
		}
		root.setAttribute('data-mtfwc-request-pending', 'true');
		stopAutoPoll(root);
		setActionButtonsDisabled(root, true);
		setBusy(root, true);
		setStatus(root, t('contacting', 'Contacting Mollie Terminal…'), 'info');
		postAction(root, 'mtfwc_cancel_payment').then(function (result) {
			root.setAttribute('data-mtfwc-request-pending', 'false');
			if (!result || !result.ok || !result.json || !result.json.success) {
				setStatus(root, t('requestFailed', 'Mollie Terminal request failed. Copy logs for support.'), 'error');
				resetToIdle(root);
				return;
			}
			var status = resultStatus(result);
			if ('paid' === classify(status)) {
				completeOrder(root, resultRedirect(result));
				return;
			}
			if ('abandoned' === status) {
				// The terminal never responded and Mollie would not cancel; the
				// server detached the attempt so a fresh Start works. Free the UI.
				setStatus(root, t('abandoned', 'The terminal did not respond, so the payment was set aside. Start a new payment or choose another method.'), 'warning');
				resetToIdle(root);
				return;
			}
			setStatus(root, t('canceled', 'Payment canceled.'), 'info');
			resetToIdle(root);
		});
	}

	// Stop and cancel an in-flight terminal payment when the order is switched to
	// another payment method (e.g. the customer decides to pay cash), so the poll
	// loop stops and the Mollie payment does not linger open.
	function currentPaymentMethod() {
		if (!document.querySelector) {
			return null;
		}
		var checked = document.querySelector('input[name="payment_method"]:checked');
		return checked ? checked.value : null;
	}

	function onPaymentMethodChange() {
		var method = currentPaymentMethod();
		if (!method) {
			// Can't tell what is selected — do nothing rather than risk canceling
			// a live payment on a spurious event.
			return;
		}
		var panels = document.querySelectorAll('.mtfwc-payment-interface');
		for (var i = 0; i < panels.length; i++) {
			var root = panels[i];
			var gateway = root.getAttribute('data-gateway-id');
			if (!root.mtfwcPoll || root.mtfwcCompleted) {
				continue;
			}
			if (gateway && method === gateway) {
				// Still our method — keep polling.
				continue;
			}
			stopAutoPoll(root);
			appendLog(root, 'info', 'Payment method changed away from Mollie Terminal; canceling the terminal payment.');
			postAction(root, 'mtfwc_cancel_payment');
			resetToIdle(root);
			setStatus(root, t('canceled', 'Payment canceled.'), 'info');
		}
	}

	// --- Terminal dropdown ----------------------------------------------------

	function loadTerminals(root) {
		var select = root.querySelector('.mtfwc-terminal-select');
		var ctx = orderContext(root);
		if (!select || !ctx.orderId || ctx.orderId === '0') {
			return;
		}
		if (isLocked(root)) {
			// Selection is locked to the default terminal; nothing to fetch.
			return;
		}
		postAction(root, 'mtfwc_list_terminals').then(function (result) {
			if (!result || !result.ok || !result.json || !result.json.success) {
				appendLog(root, 'warning', t('terminalsFailed', 'Could not load terminals.'));
				select.disabled = false;
				select.setAttribute('aria-busy', 'false');
				return;
			}
			populateTerminals(root, select, result.json.data || {});
		});
	}

	function populateTerminals(root, select, data) {
		var terminals = (data && data.terminals) || [];
		var preferred = root.getAttribute('data-default-terminal-id') || data.default_terminal_id || '';
		var hasPreferred = !!preferred && terminals.some(function (item) { return item.id === preferred; });
		var i;
		var terminal;
		var label;
		clearOptions(select);
		if (!terminals.length) {
			select.appendChild(createOption('', t('noTerminals', 'No terminals found on this Mollie account.')));
			select.disabled = true;
			select.setAttribute('data-mtfwc-unavailable', '1');
			select.setAttribute('aria-busy', 'false');
			appendLog(root, 'warning', t('noTerminals', 'No terminals found on this Mollie account.'));
			return;
		}
		// Terminals are available again — clear any stale "unavailable" marker so
		// resetToIdle() can re-enable the select.
		select.removeAttribute('data-mtfwc-unavailable');
		// If the saved default is not in the list (paginated/removed/filtered),
		// force an explicit choice rather than silently dispatching to the
		// first terminal, which could be the wrong physical device.
		if (!hasPreferred) {
			select.appendChild(createOption('', t('selectTerminalOption', '— Select a terminal —')));
		}
		for (i = 0; i < terminals.length; i++) {
			terminal = terminals[i];
			label = terminal.label || terminal.id;
			if (terminal.status && 'active' !== terminal.status) {
				label += ' (' + terminal.status + ')';
			}
			select.appendChild(createOption(terminal.id, label));
		}
		select.value = hasPreferred ? preferred : '';
		// Keep the choice frozen while a payment is in flight (e.g. resumed on load).
		select.disabled = !!root.mtfwcPoll;
		select.setAttribute('aria-busy', 'false');
	}

	function clearOptions(select) {
		if ('function' === typeof select.replaceChildren) {
			select.replaceChildren();
			return;
		}
		while (select.firstChild) {
			select.removeChild(select.firstChild);
		}
	}

	function createOption(value, label) {
		var option = document.createElement('option');
		option.value = value;
		option.textContent = label;
		return option;
	}

	// --- Binding --------------------------------------------------------------

	// Best-effort cancel when the page is closed while a payment is still in
	// flight (e.g. the POS checkout modal is dismissed): a beacon fires the
	// cancel action so the payment does not linger open on the Mollie side.
	// If the payment already reached the terminal or was paid, the server
	// treats the cancel as a no-op, and the successful-payment redirect never
	// triggers this (the poll session is stopped and the panel marked complete
	// before navigating). The server-side stale-payment sweep is the backstop
	// for when even this beacon is lost (browser killed, network dropped).
	function bindCancelOnLeave(root) {
		if ('function' !== typeof window.addEventListener || !window.navigator || 'function' !== typeof window.navigator.sendBeacon) {
			return;
		}
		window.addEventListener('pagehide', function () {
			if (!root.mtfwcPoll || root.mtfwcCompleted) {
				return;
			}
			var ctx = orderContext(root);
			if (!ctx.orderId || ctx.orderId === '0') {
				return;
			}
			var body = new FormData();
			body.append('action', 'mtfwc_cancel_payment');
			body.append('order_id', ctx.orderId);
			body.append('order_token', ctx.orderToken);
			try {
				window.navigator.sendBeacon(getData().ajaxUrl, body);
			} catch (e) {}
		});
	}

	function bindClick(root, selector, handler) {
		var button = root.querySelector(selector);
		if (button) {
			button.addEventListener('click', function () { handler(root); });
		}
	}

	function bind(root) {
		var toggle;
		var content;
		var clear;
		var copy;
		if (root.getAttribute('data-mtfwc-bound') === 'true') {
			return;
		}
		root.setAttribute('data-mtfwc-bound', 'true');
		restoreLogs(root);
		toggle = root.querySelector('.mtfwc-toggle-log');
		content = root.querySelector('.mtfwc-log-content');
		if (toggle && content) {
			toggle.addEventListener('click', function () {
				var expanded = toggle.getAttribute('data-expanded') === 'true';
				toggle.setAttribute('data-expanded', expanded ? 'false' : 'true');
				content.style.display = expanded ? 'none' : 'block';
				toggle.textContent = expanded ? t('logsHidden', 'Show logs') : t('logsShown', 'Hide logs');
			});
		}
		clear = root.querySelector('.mtfwc-clear-log');
		if (clear) {
			clear.addEventListener('click', function () {
				var textarea = root.querySelector('.mtfwc-payment-log-textarea');
				if (textarea) {
					textarea.value = '';
				}
				try { sessionStorage.removeItem(storageKey(root)); } catch (e) {}
				appendLog(root, 'info', 'Log cleared.');
			});
		}
		copy = root.querySelector('.mtfwc-copy-log');
		if (copy) {
			copy.addEventListener('click', function () {
				var textarea = root.querySelector('.mtfwc-payment-log-textarea');
				if (!textarea) {
					return;
				}
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(textarea.value).then(function () {
						appendLog(root, 'success', t('copied', 'Logs copied to clipboard.'));
					}).catch(function () {
						appendLog(root, 'warning', t('copyFailed', 'Unable to copy logs automatically.'));
					});
				} else {
					textarea.focus();
					textarea.select();
					appendLog(root, 'warning', t('copyFailed', 'Unable to copy logs automatically.'));
				}
			});
		}
		bindClick(root, '.mtfwc-primary-action', onPrimaryAction);
		bindCancelOnLeave(root);
		loadTerminals(root);
		// Resume an already-open payment on reload, otherwise show a static idle
		// status so the cashier can see the terminal is ready before pressing Start.
		if (root.getAttribute('data-order-id') && root.getAttribute('data-order-id') !== '0') {
			if (root.getAttribute('data-resume') === '1') {
				root.mtfwcCompleted = false;
				appendLog(root, 'info', 'Resuming an open Mollie Terminal payment after page load.');
				startAutoPoll(root);
			} else {
				showIdle(root);
			}
		}
	}

	function bindAll() {
		var panels = document.querySelectorAll('.mtfwc-payment-interface');
		for (var i = 0; i < panels.length; i++) {
			bind(panels[i]);
		}
	}

	ready(function () {
		var jq = window.jQuery || ('undefined' !== typeof jQuery ? jQuery : null);
		bindAll();
		if (document.body && jq) {
			jq(document.body).on('updated_checkout', bindAll);
			jq(document.body).on('payment_method_selected', onPaymentMethodChange);
		}
		if (document.body && document.body.addEventListener) {
			document.body.addEventListener('updated_checkout', bindAll);
			document.body.addEventListener('change', function (event) {
				var target = event && event.target;
				if (target && target.name === 'payment_method') {
					onPaymentMethodChange();
				}
			});
		}
	});
}());
