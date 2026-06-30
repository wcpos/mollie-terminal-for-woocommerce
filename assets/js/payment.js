(function () {
	'use strict';

	var MAX_LOG_LINES = 50;
	var SENSITIVE_KEY_PATTERN = /(key|token|secret|authorization|password|bearer|metadata|customer|email)/i;

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

	function getData() {
		return window.mtfwcPaymentData || { ajaxUrl: '/wp-admin/admin-ajax.php', defaultTerminalId: '', i18n: {} };
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

	function sendRequest(root, action) {
		var data = getData();
		var orderId = root.getAttribute('data-order-id') || '';
		var orderToken = root.getAttribute('data-order-token') || '';
		var terminalId = root.getAttribute('data-default-terminal-id') || data.defaultTerminalId || '';
		var body;
		if (!orderId || orderId === '0') {
			appendLog(root, 'error', 'Cannot send Mollie Terminal request because no order ID is available yet.');
			setStatus(root, 'No order ID is available yet.', 'error');
			return Promise.resolve();
		}

		body = new FormData();
		body.append('action', action);
		body.append('order_id', orderId);
		body.append('order_token', orderToken);
		if (action === 'mtfwc_start_payment') {
			body.append('terminal_id', terminalId);
		}

		appendLog(root, 'info', 'Sending ' + action + ' request.', { order_id: orderId, terminal_id: terminalId });
		setStatus(root, 'Contacting Mollie Terminal...', 'info');

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
					setStatus(root, 'Mollie Terminal request failed. Copy logs for support.', 'error');
					return result;
				}
				appendLog(root, 'success', action + ' succeeded.', responseSummary(result.json.data || {}));
				setStatus(root, 'Mollie Terminal status: ' + ((result.json.data && result.json.data.status) || 'ok'), 'success');
				return result;
			})
			.catch(function (error) {
				appendLog(root, 'error', action + ' network error: ' + error.message);
				setStatus(root, 'Network error. Copy logs for support.', 'error');
			});
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

	function runAction(root, action) {
		var request;
		function unlock(result) {
			root.setAttribute('data-mtfwc-request-pending', 'false');
			setActionButtonsDisabled(root, false);
			return result;
		}
		if (root.getAttribute('data-mtfwc-request-pending') === 'true') {
			appendLog(root, 'warning', 'Ignoring duplicate Mollie Terminal action while a request is pending.');
			return Promise.resolve();
		}
		root.setAttribute('data-mtfwc-request-pending', 'true');
		setActionButtonsDisabled(root, true);
		request = sendRequest(root, action);
		if (request && 'function' === typeof request.then) {
			return request.then(unlock, function (error) {
				unlock();
				throw error;
			});
		}
		return Promise.resolve(unlock(request));
	}

	function bindAction(root, selector, action) {
		var button = root.querySelector(selector);
		if (button) {
			button.addEventListener('click', function () { runAction(root, action); });
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
				toggle.textContent = expanded ? (getData().i18n.logsHidden || 'Show logs') : (getData().i18n.logsShown || 'Hide logs');
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
						appendLog(root, 'success', getData().i18n.copied || 'Logs copied to clipboard.');
					}).catch(function () {
						appendLog(root, 'warning', getData().i18n.copyFailed || 'Unable to copy logs automatically.');
					});
				} else {
					textarea.focus();
					textarea.select();
					appendLog(root, 'warning', getData().i18n.copyFailed || 'Unable to copy logs automatically.');
				}
			});
		}
		bindAction(root, '.mtfwc-start-payment', 'mtfwc_start_payment');
		bindAction(root, '.mtfwc-poll-payment', 'mtfwc_poll_payment');
		bindAction(root, '.mtfwc-cancel-payment', 'mtfwc_cancel_payment');
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
