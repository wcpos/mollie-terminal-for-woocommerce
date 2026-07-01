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

	function selectedTerminalId(root) {
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

		return fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
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

	function setActionButtonsDisabled(root, disabled) {
		var selectors = ['.mtfwc-start-payment', '.mtfwc-poll-payment', '.mtfwc-cancel-payment'];
		var buttons;
		var i;
		var j;
		for (i = 0; i < selectors.length; i++) {
			buttons = root.querySelectorAll(selectors[i]);
			for (j = 0; j < buttons.length; j++) {
				buttons[j].disabled = disabled;
			}
		}
	}

	function setButtonDisabled(root, selector, disabled) {
		var button = root.querySelector(selector);
		if (button) {
			button.disabled = disabled;
		}
	}

	// --- Auto-poll loop -------------------------------------------------------

	function stopAutoPoll(root) {
		if (root.mtfwcPoll) {
			clearLater(root.mtfwcPoll.timer);
			root.mtfwcPoll = null;
		}
	}

	function startAutoPoll(root) {
		var data = getData();
		var interval = data.pollIntervalMs || DEFAULT_POLL_INTERVAL_MS;
		var timeout = data.pollTimeoutMs || DEFAULT_POLL_TIMEOUT_MS;
		stopAutoPoll(root);
		root.mtfwcPollSeq = (root.mtfwcPollSeq || 0) + 1;
		root.mtfwcPoll = { deadline: nowMs() + timeout, timer: null, id: root.mtfwcPollSeq };
		setStatus(root, t('waiting', 'Waiting for terminal…'), 'info');
		setButtonDisabled(root, '.mtfwc-start-payment', true);
		setButtonDisabled(root, '.mtfwc-cancel-payment', false);
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
			setStatus(root, t('timedOut', 'Timed out waiting for the terminal. Check the terminal or try again.'), 'error');
			resetToIdle(root);
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
				completeOrder(root);
			} else if ('failed' === outcome) {
				stopAutoPoll(root);
				setStatus(root, t('failed', 'Payment failed. You can try again.'), 'error');
				resetToIdle(root);
			} else if ('idle' === outcome) {
				stopAutoPoll(root);
				resetToIdle(root);
			} else {
				setStatus(root, t('waiting', 'Waiting for terminal…'), 'info');
				schedulePoll(root, interval);
			}
		});
	}

	function resetToIdle(root) {
		setButtonDisabled(root, '.mtfwc-start-payment', false);
		setButtonDisabled(root, '.mtfwc-poll-payment', false);
		setButtonDisabled(root, '.mtfwc-cancel-payment', false);
	}

	// Payment succeeded: finish the order the same way the POS "Process payment"
	// button does — submit the order-pay form (#place_order). Fall back to the
	// POS postMessage bridge when the button is not in this document.
	function completeOrder(root) {
		stopAutoPoll(root);
		setStatus(root, t('completing', 'Payment complete — finishing order…'), 'success');
		appendLog(root, 'success', 'Terminal payment complete; submitting order.');
		if (root.mtfwcCompleted) {
			return;
		}
		root.mtfwcCompleted = true;
		// Re-enable the controls so the UI is not stuck if submitting the order
		// does not navigate away (e.g. the button is not in this document).
		resetToIdle(root);

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
				completeOrder(root);
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
		var wasPolling = !!root.mtfwcPoll;
		root.setAttribute('data-mtfwc-request-pending', 'true');
		stopAutoPoll(root);
		setActionButtonsDisabled(root, true);
		setStatus(root, t('contacting', 'Contacting Mollie Terminal…'), 'info');
		postAction(root, 'mtfwc_cancel_payment').then(function (result) {
			root.setAttribute('data-mtfwc-request-pending', 'false');
			var status = resultStatus(result);
			if ('not_cancelable' === status) {
				setStatus(root, t('notCancelable', 'This payment can no longer be canceled.'), 'warning');
				if (wasPolling) {
					startAutoPoll(root);
				} else {
					resetToIdle(root);
				}
				return;
			}
			var outcome = classify(status);
			if ('paid' === outcome) {
				completeOrder(root);
			} else if ('failed' === outcome) {
				setStatus(root, t('canceled', 'Payment canceled.'), 'info');
				resetToIdle(root);
			} else {
				resetToIdle(root);
			}
		});
	}

	// Manual "Check Status" button: a one-off poll for support/debugging.
	function onManualPoll(root) {
		if (root.getAttribute('data-mtfwc-request-pending') === 'true') {
			appendLog(root, 'warning', 'Ignoring duplicate Check Status while a request is pending.');
			return;
		}
		root.setAttribute('data-mtfwc-request-pending', 'true');
		setButtonDisabled(root, '.mtfwc-poll-payment', true);
		postAction(root, 'mtfwc_poll_payment').then(function (result) {
			root.setAttribute('data-mtfwc-request-pending', 'false');
			setButtonDisabled(root, '.mtfwc-poll-payment', false);
			if (!result || !result.ok || !result.json || !result.json.success) {
				setStatus(root, t('checkFailed', 'Status check failed. Copy logs for support.'), 'error');
				return;
			}
			var status = resultStatus(result);
			setStatus(root, 'Mollie Terminal status: ' + (status || 'ok'), 'info');
			if ('paid' === classify(status)) {
				completeOrder(root);
			}
		});
	}

	// --- Terminal dropdown ----------------------------------------------------

	function loadTerminals(root) {
		var select = root.querySelector('.mtfwc-terminal-select');
		var ctx = orderContext(root);
		if (!select || !ctx.orderId || ctx.orderId === '0') {
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
			select.setAttribute('aria-busy', 'false');
			appendLog(root, 'warning', t('noTerminals', 'No terminals found on this Mollie account.'));
			return;
		}
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
		select.disabled = false;
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
		bindClick(root, '.mtfwc-start-payment', onStart);
		bindClick(root, '.mtfwc-poll-payment', onManualPoll);
		bindClick(root, '.mtfwc-cancel-payment', onCancel);
		loadTerminals(root);
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
		}
		if (document.body && document.body.addEventListener) {
			document.body.addEventListener('updated_checkout', bindAll);
		}
	});
}());
