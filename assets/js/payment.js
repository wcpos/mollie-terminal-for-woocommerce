(function () {
	'use strict';

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

	function appendLog(root, level, message, detail) {
		var textarea = root.querySelector('.mtfwc-payment-log-textarea');
		if (!textarea) {
			return;
		}
		var line = '[' + now() + '] [' + level.toUpperCase() + '] ' + message;
		if (detail) {
			try {
				line += ' ' + JSON.stringify(detail);
			} catch (e) {
				line += ' ' + String(detail);
			}
		}
		textarea.value = textarea.value ? textarea.value + '\n' + line : line;
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
			textarea.value = sessionStorage.getItem(storageKey(root)) || '';
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
		if (!orderId || orderId === '0') {
			appendLog(root, 'error', 'Cannot send Mollie Terminal request because no order ID is available yet.');
			setStatus(root, 'No order ID is available yet.', 'error');
			return Promise.resolve();
		}

		var body = new FormData();
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
					appendLog(root, 'error', action + ' failed.', result.json || result.text || result.status);
					setStatus(root, 'Mollie Terminal request failed. Copy logs for support.', 'error');
					return result;
				}
				appendLog(root, 'success', action + ' succeeded.', result.json.data || {});
				setStatus(root, 'Mollie Terminal status: ' + ((result.json.data && result.json.data.status) || 'ok'), 'success');
				return result;
			})
			.catch(function (error) {
				appendLog(root, 'error', action + ' network error: ' + error.message);
				setStatus(root, 'Network error. Copy logs for support.', 'error');
			});
	}

	function bind(root) {
		restoreLogs(root);
		var toggle = root.querySelector('.mtfwc-toggle-log');
		var content = root.querySelector('.mtfwc-log-content');
		if (toggle && content) {
			toggle.addEventListener('click', function () {
				var expanded = toggle.getAttribute('data-expanded') === 'true';
				toggle.setAttribute('data-expanded', expanded ? 'false' : 'true');
				content.style.display = expanded ? 'none' : 'block';
				toggle.textContent = expanded ? (getData().i18n.logsHidden || 'Show logs') : (getData().i18n.logsShown || 'Hide logs');
			});
		}
		var clear = root.querySelector('.mtfwc-clear-log');
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
		var copy = root.querySelector('.mtfwc-copy-log');
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
		var start = root.querySelector('.mtfwc-start-payment');
		if (start) {
			start.addEventListener('click', function () { sendRequest(root, 'mtfwc_start_payment'); });
		}
		var poll = root.querySelector('.mtfwc-poll-payment');
		if (poll) {
			poll.addEventListener('click', function () { sendRequest(root, 'mtfwc_poll_payment'); });
		}
		var cancel = root.querySelector('.mtfwc-cancel-payment');
		if (cancel) {
			cancel.addEventListener('click', function () { sendRequest(root, 'mtfwc_cancel_payment'); });
		}
	}

	ready(function () {
		var panels = document.querySelectorAll('.mtfwc-payment-interface');
		for (var i = 0; i < panels.length; i++) {
			bind(panels[i]);
		}
	});
}());
