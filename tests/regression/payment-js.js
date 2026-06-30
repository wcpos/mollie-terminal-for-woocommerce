const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

function makeNodeList(items) {
	const list = { length: items.length };
	items.forEach((item, index) => { list[index] = item; });
	return list;
}

class FakeElement {
	constructor(classes = []) {
		this.classNames = new Set(classes);
		this.attributes = {};
		this.children = [];
		this.listeners = {};
		this.style = {};
		this.value = '';
		this.textContent = '';
		this.className = classes.join(' ');
		this.disabled = false;
		this.scrollHeight = 0;
		this.scrollTop = 0;
	}
	append(child) { this.children.push(child); return child; }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
	addEventListener(type, callback) { (this.listeners[type] = this.listeners[type] || []).push(callback); }
	click() { (this.listeners.click || []).forEach((callback) => callback({ target: this, preventDefault() {} })); }
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	querySelectorAll(selector) {
		const className = selector.replace(/^\./, '');
		const results = [];
		const walk = (node) => {
			node.children.forEach((child) => {
				if (child.classNames.has(className)) {
					results.push(child);
				}
				walk(child);
			});
		};
		walk(this);
		return makeNodeList(results);
	}
}

function makeButton(className, label) {
	const button = new FakeElement([className]);
	button.textContent = label;
	return button;
}

function makePanel(orderId) {
	const root = new FakeElement(['mtfwc-payment-interface']);
	root.setAttribute('data-order-id', orderId);
	root.setAttribute('data-order-token', 'token-' + orderId);
	root.setAttribute('data-default-terminal-id', 'term_default');
	root.append(makeButton('mtfwc-toggle-log', 'Show logs')).setAttribute('data-expanded', 'false');
	root.append(makeButton('mtfwc-clear-log', 'Clear logs'));
	root.append(makeButton('mtfwc-copy-log', 'Copy logs'));
	root.append(makeButton('mtfwc-start-payment', 'Start payment'));
	root.append(makeButton('mtfwc-poll-payment', 'Check status'));
	root.append(makeButton('mtfwc-cancel-payment', 'Cancel payment'));
	root.append(new FakeElement(['mtfwc-payment-status']));
	const content = root.append(new FakeElement(['mtfwc-log-content']));
	content.style.display = 'none';
	root.append(new FakeElement(['mtfwc-payment-log-textarea']));
	return root;
}

async function flush() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve();
	}
}

(async () => {
	const panel = makePanel('123');
	const panels = [panel];
	const fetchCalls = [];
	const pendingFetches = [];
	const jqueryHandlers = {};
	const nativeBody = new FakeElement([]);
	const storage = {};

	class FakeFormData {
		constructor() { this.fields = {}; }
		append(key, value) { this.fields[key] = String(value); }
	}

	const context = {
		console,
		Promise,
		FormData: FakeFormData,
		fetch(url, options) {
			fetchCalls.push({ url, options });
			return new Promise((resolve) => { pendingFetches.push(resolve); });
		},
		document: {
			readyState: 'complete',
			body: nativeBody,
			addEventListener() {},
			querySelectorAll(selector) { return '.mtfwc-payment-interface' === selector ? panels.slice() : []; },
		},
		sessionStorage: {
			getItem(key) { return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null; },
			setItem(key, value) { storage[key] = String(value); },
			removeItem(key) { delete storage[key]; },
		},
		navigator: { clipboard: { writeText() { return Promise.resolve(); } } },
	};
	context.window = {
		mtfwcPaymentData: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			defaultTerminalId: 'term_default',
			i18n: { logsHidden: 'Show logs', logsShown: 'Hide logs', copied: 'Copied', copyFailed: 'Copy failed' },
		},
		jQuery(target) { return { on(event, callback) { jqueryHandlers[event] = callback; } }; },
	};
	context.jQuery = context.window.jQuery;

	function resolveNext(data) {
		const resolve = pendingFetches.shift();
		assert(resolve, 'expected a pending fetch request');
		resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify({ success: true, data })) });
		return flush();
	}

	vm.createContext(context);
	vm.runInContext(fs.readFileSync('assets/js/payment.js', 'utf8'), context);

	const start = panel.querySelector('.mtfwc-start-payment');
	const poll = panel.querySelector('.mtfwc-poll-payment');
	const cancel = panel.querySelector('.mtfwc-cancel-payment');
	const textarea = panel.querySelector('.mtfwc-payment-log-textarea');

	start.click();
	start.click();
	assert.strictEqual(fetchCalls.length, 1, 'payment action buttons should ignore duplicate clicks while a request is pending');
	assert.strictEqual(start.disabled && poll.disabled && cancel.disabled, true, 'all payment action buttons should be disabled while a request is pending');

	await resolveNext({ status: 'created', payment: { id: 'tr_123', status: 'open', metadata: { email: 'customer@example.com', api_key: 'live_abcdefghijklmnopqrstuvwxyz123456' } } });
	assert.strictEqual(start.disabled || poll.disabled || cancel.disabled, false, 'payment action buttons should be re-enabled after a request settles');
	assert(textarea.value.includes('tr_123'), 'browser logs should include the safe Mollie payment id');
	assert(textarea.value.includes('open'), 'browser logs should include the safe Mollie payment status');
	assert(!textarea.value.includes('customer@example.com'), 'browser logs should not include customer metadata from the raw Mollie payment payload');
	assert(!textarea.value.includes('live_abcdefghijklmnopqrstuvwxyz123456'), 'browser logs should not include API-like secrets from response payloads');

	for (let i = 0; i < 60; i++) {
		poll.click();
		await resolveNext({ status: 'poll-' + i });
	}
	assert(textarea.value.split('\n').length <= 50, 'browser logs should keep a bounded number of lines');

	const refreshedPanel = makePanel('456');
	panels.push(refreshedPanel);
	assert(jqueryHandlers.updated_checkout, 'payment.js should listen for WooCommerce checkout refreshes');
	jqueryHandlers.updated_checkout();
	jqueryHandlers.updated_checkout();
	refreshedPanel.querySelector('.mtfwc-toggle-log').click();
	assert.strictEqual(refreshedPanel.querySelector('.mtfwc-toggle-log').getAttribute('data-expanded'), 'true', 'checkout refresh binding should attach handlers once to new panels');

	console.log('payment-js ok');
})().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
