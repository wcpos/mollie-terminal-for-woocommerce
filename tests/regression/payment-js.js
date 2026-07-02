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
		this.clickCount = 0;
	}
	append(child) { this.children.push(child); return child; }
	appendChild(child) { this.children.push(child); return child; }
	removeChild(child) { const i = this.children.indexOf(child); if (i >= 0) { this.children.splice(i, 1); } return child; }
	replaceChildren() { this.children = []; }
	get firstChild() { return this.children[0] || null; }
	// Mirror the real DOM: HTMLSelectElement.options is a read-only collection.
	// Defining it as a getter with no setter means any `select.options = ...`
	// throws in strict mode, exactly as a browser would (regression guard).
	get options() { return makeNodeList(this.children.filter((c) => 'option' === c.tagName)); }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	removeAttribute(name) { delete this.attributes[name]; }
	getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
	addEventListener(type, callback) { (this.listeners[type] = this.listeners[type] || []).push(callback); }
	click() { this.clickCount++; (this.listeners.click || []).forEach((callback) => callback({ target: this, preventDefault() {} })); }
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

function makePanel(orderId, attrs = {}) {
	const root = new FakeElement(['mtfwc-payment-interface']);
	root.setAttribute('data-order-id', orderId);
	root.setAttribute('data-order-token', 'token-' + orderId);
	root.setAttribute('data-default-terminal-id', 'term_default');
	Object.keys(attrs).forEach((name) => root.setAttribute(name, attrs[name]));
	root.append(makeButton('mtfwc-toggle-log', 'Show logs')).setAttribute('data-expanded', 'false');
	root.append(makeButton('mtfwc-clear-log', 'Clear logs'));
	root.append(makeButton('mtfwc-copy-log', 'Copy logs'));
	root.append(new FakeElement(['mtfwc-terminal-select']));
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
	const panel = makePanel('123', { 'data-pos': '1' });
	const panels = [panel];
	const fetchCalls = [];
	const pendingFetches = [];
	const jqueryHandlers = {};
	const windowListeners = {};
	const beacons = [];
	const nativeBody = new FakeElement([]);
	const storage = {};
	const placeOrderButton = new FakeElement([]);

	// Controllable fake timers (payment.js uses setTimeout for the poll loop).
	let timerSeq = 0;
	const timers = {};

	class FakeFormData {
		constructor() { this.fields = {}; }
		append(key, value) { this.fields[key] = String(value); }
	}

	const context = {
		console,
		Promise,
		Date,
		FormData: FakeFormData,
		setTimeout(fn) { const id = ++timerSeq; timers[id] = fn; return id; },
		clearTimeout(id) { delete timers[id]; },
		fetch(url, options) {
			fetchCalls.push({ url, options });
			return new Promise((resolve) => { pendingFetches.push(resolve); });
		},
		document: {
			readyState: 'complete',
			body: nativeBody,
			addEventListener() {},
			createElement(tag) { const el = new FakeElement([]); el.tagName = tag; return el; },
			getElementById(id) { return 'place_order' === id ? placeOrderButton : null; },
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
			pollIntervalMs: 2000,
			pollTimeoutMs: 300000,
			i18n: { logsHidden: 'Show logs', logsShown: 'Hide logs', copied: 'Copied', copyFailed: 'Copy failed' },
		},
		location: { href: 'https://example.test/wcpos-checkout/order-pay/123/' },
		addEventListener(type, callback) { (windowListeners[type] = windowListeners[type] || []).push(callback); },
		navigator: { sendBeacon(url, body) { beacons.push({ url, body }); return true; } },
		jQuery(target) { return { on(event, callback) { jqueryHandlers[event] = callback; } }; },
	};
	context.jQuery = context.window.jQuery;
	function firePagehide() { (windowListeners.pagehide || []).forEach((callback) => callback()); }

	function lastAction() {
		const call = fetchCalls[fetchCalls.length - 1];
		return call && call.options && call.options.body ? call.options.body.fields.action : null;
	}

	function resolveNext(data) {
		const resolve = pendingFetches.shift();
		assert(resolve, 'expected a pending fetch request');
		resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify({ success: true, data })) });
		return flush();
	}

	function resolveError() {
		const resolve = pendingFetches.shift();
		assert(resolve, 'expected a pending fetch request');
		resolve({ ok: false, status: 500, text: () => Promise.resolve('{"success":false}') });
		return flush();
	}

	async function fireTimers() {
		const due = Object.keys(timers);
		due.forEach((id) => { const fn = timers[id]; delete timers[id]; fn(); });
		await flush();
	}

	vm.createContext(context);
	vm.runInContext(fs.readFileSync('assets/js/payment.js', 'utf8'), context);

	const start = panel.querySelector('.mtfwc-start-payment');
	const poll = panel.querySelector('.mtfwc-poll-payment');
	const cancel = panel.querySelector('.mtfwc-cancel-payment');
	const select = panel.querySelector('.mtfwc-terminal-select');
	const textarea = panel.querySelector('.mtfwc-payment-log-textarea');

	// On bind the panel fetches the terminal list to populate the dropdown.
	assert.strictEqual(fetchCalls.length, 1, 'binding a panel should request the terminal list');
	assert.strictEqual(lastAction(), 'mtfwc_list_terminals', 'first request should be mtfwc_list_terminals');
	// POS panels identify themselves so the server can build the POS thank-you URL.
	assert.strictEqual(fetchCalls[0].options.headers['X-WCPOS'], '1', 'POS panels should send the X-WCPOS header');
	await resolveNext({
		terminals: [
			{ id: 'term_A', label: 'Front desk', status: 'active' },
			{ id: 'term_default', label: 'Back office', status: 'active' },
		],
		default_terminal_id: 'term_default',
	});
	assert.strictEqual(select.value, 'term_default', 'the configured default terminal should be preselected');
	assert.strictEqual(select.options.length, 2, 'the dropdown should list the fetched terminals');
	assert.strictEqual(select.getAttribute('data-mtfwc-unavailable'), null, 'a populated dropdown must not carry the unavailable marker');

	// Duplicate Start clicks should be ignored while the request is pending.
	start.click();
	start.click();
	assert.strictEqual(fetchCalls.length, 2, 'duplicate Start clicks should not queue a second request');
	assert.strictEqual(lastAction(), 'mtfwc_start_payment', 'Start should send mtfwc_start_payment');
	assert.strictEqual(context.window.mtfwcPaymentData ? true : false, true);
	assert.strictEqual(start.disabled && poll.disabled && cancel.disabled, true, 'action buttons should be disabled while starting');
	// The selected terminal id must ride along with the start request.
	assert.strictEqual(fetchCalls[fetchCalls.length - 1].options.body.fields.terminal_id, 'term_default', 'start should send the selected terminal id');

	await resolveNext({ status: 'created', payment: { id: 'tr_123', status: 'open', metadata: { email: 'customer@example.com', api_key: 'live_abcdefghijklmnopqrstuvwxyz123456' } } });
	assert(textarea.value.includes('tr_123'), 'browser logs should include the safe Mollie payment id');
	assert(textarea.value.includes('open'), 'browser logs should include the safe Mollie payment status');
	assert(!textarea.value.includes('customer@example.com'), 'browser logs should not include customer metadata');
	assert(!textarea.value.includes('live_abcdefghijklmnopqrstuvwxyz123456'), 'browser logs should not include API-like secrets');

	// After a successful start the flow enters auto-poll: Start stays disabled,
	// Cancel is available, and no immediate poll fetch is issued (it is scheduled).
	assert.strictEqual(start.disabled, true, 'Start stays disabled while waiting for the terminal');
	assert.strictEqual(cancel.disabled, false, 'Cancel is available while waiting for the terminal');
	assert.strictEqual(fetchCalls.length, 2, 'auto-poll should be scheduled, not fired immediately');

	// First scheduled poll returns pending -> should schedule another.
	await fireTimers();
	assert.strictEqual(lastAction(), 'mtfwc_poll_payment', 'the scheduled tick should poll payment status');
	await resolveNext({ status: 'pending' });

	// Manual "Check Status" polls should stay bounded in the log.
	for (let i = 0; i < 60; i++) {
		poll.click();
		await resolveNext({ status: 'poll-' + i });
	}
	assert(textarea.value.split('\n').length <= 50, 'browser logs should keep a bounded number of lines');

	// Next scheduled poll returns paid with a redirect URL -> navigate straight
	// to the thank-you page (the order is already paid server-side; submitting
	// the order-pay form would hit WooCommerce's "already paid" guard).
	const thankYouUrl = 'https://example.test/wcpos-checkout/order-received/123?key=wc_order_test';
	await fireTimers();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });
	assert.strictEqual(context.window.location.href, thankYouUrl, 'a paid poll should redirect to the thank-you page');
	assert.strictEqual(placeOrderButton.clickCount, 0, 'the order-pay form must not be re-submitted when a redirect URL is available');

	// A second paid signal must not double-complete the order.
	context.window.location.href = 'sentinel';
	poll.click();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });
	assert.strictEqual(context.window.location.href, 'sentinel', 'order completion should be idempotent');
	assert.strictEqual(placeOrderButton.clickCount, 0, 'idempotent completion should not fall back to #place_order');

	// A failed cancel request must surface an error, not silently reset with a stale status.
	cancel.click();
	await resolveError();
	const statusEl = panel.querySelector('.mtfwc-payment-status');
	assert(/failed/i.test(statusEl.textContent), 'a failed cancel should show an error status');
	assert.strictEqual(cancel.disabled, false, 'buttons re-enable after a failed cancel');

	// Checkout refresh should bind new panels exactly once.
	const refreshedPanel = makePanel('456');
	panels.push(refreshedPanel);
	assert(jqueryHandlers.updated_checkout, 'payment.js should listen for WooCommerce checkout refreshes');
	jqueryHandlers.updated_checkout();
	jqueryHandlers.updated_checkout();
	refreshedPanel.querySelector('.mtfwc-toggle-log').click();
	assert.strictEqual(refreshedPanel.querySelector('.mtfwc-toggle-log').getAttribute('data-expanded'), 'true', 'checkout refresh binding should attach handlers once to new panels');

	// Resolve the refreshed panel's terminal-list fetch.
	await resolveNext({
		terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }],
		default_terminal_id: 'term_default',
	});

	// Without a redirect URL the completion falls back to submitting #place_order.
	refreshedPanel.querySelector('.mtfwc-poll-payment').click();
	await resolveNext({ status: 'paid' });
	assert.strictEqual(placeOrderButton.clickCount, 1, 'completion falls back to #place_order when no redirect URL is provided');

	// A timed-out auto-poll sends a cancel to Mollie instead of leaving the payment open.
	const start456 = refreshedPanel.querySelector('.mtfwc-start-payment');
	start456.click();
	await resolveNext({ status: 'created' });
	assert(refreshedPanel.mtfwcPoll, 'auto-poll should be armed after a successful start');
	refreshedPanel.mtfwcPoll.deadline = 0; // force the timeout branch on the next tick
	await fireTimers();
	assert.strictEqual(lastAction(), 'mtfwc_cancel_payment', 'a timed-out auto-poll should auto-cancel the payment');
	await resolveNext({ status: 'canceled' });
	assert(/timed out/i.test(refreshedPanel.querySelector('.mtfwc-payment-status').textContent), 'the timeout message should be shown after auto-cancel');
	assert.strictEqual(start456.disabled, false, 'Start re-enables after a timed-out payment is canceled');

	// A retry started while the timeout-cancel is still in flight must not have
	// its UI clobbered by the stale cancel response.
	start456.click();
	await resolveNext({ status: 'created' });
	refreshedPanel.mtfwcPoll.deadline = 0;
	await fireTimers(); // timeout branch fires the cancel (request now pending)
	start456.click(); // cashier retries immediately; start request queues behind the cancel
	await resolveNext({ status: 'canceled' }); // stale cancel response arrives first
	assert(/sending/i.test(refreshedPanel.querySelector('.mtfwc-payment-status').textContent), 'a stale timeout-cancel response must not clobber the new attempt');
	await resolveNext({ status: 'created' }); // the retry's start response
	assert(/waiting/i.test(refreshedPanel.querySelector('.mtfwc-payment-status').textContent), 'the retry should proceed to the waiting state');
	// Wind the retry down so later pagehide assertions see no active poll here.
	await fireTimers();
	await resolveNext({ status: 'canceled' });

	// Locked panels never fetch the terminal list and always use the default terminal.
	const lockedPanel = makePanel('789', { 'data-lock-terminal': '1' });
	panels.push(lockedPanel);
	const fetchCountBefore = fetchCalls.length;
	jqueryHandlers.updated_checkout();
	assert.strictEqual(fetchCalls.length, fetchCountBefore, 'locked panels must not fetch the terminal list');
	lockedPanel.querySelector('.mtfwc-start-payment').click();
	assert.strictEqual(lastAction(), 'mtfwc_start_payment', 'locked panel Start should fire');
	assert.strictEqual(fetchCalls[fetchCalls.length - 1].options.body.fields.terminal_id, 'term_default', 'locked panels always send the default terminal');
	await resolveNext({ status: 'created' });

	// An empty terminal list disables the select and marks it unavailable, so
	// resetToIdle() leaves it disabled (the non-empty path clears the marker,
	// asserted on the populated panel above).
	const emptyPanel = makePanel('999');
	panels.push(emptyPanel);
	jqueryHandlers.updated_checkout();
	const emptySelect = emptyPanel.querySelector('.mtfwc-terminal-select');
	await resolveNext({ terminals: [], default_terminal_id: '' });
	assert.strictEqual(emptySelect.disabled, true, 'an empty terminal list should disable the select');
	assert.strictEqual(emptySelect.getAttribute('data-mtfwc-unavailable'), '1', 'an empty terminal list should mark the select unavailable');

	// Closing the page while a payment is in flight fires one best-effort cancel
	// beacon (completed/idle panels stay silent).
	firePagehide();
	assert.strictEqual(beacons.length, 1, 'exactly one cancel beacon should fire for the in-flight payment');
	assert.strictEqual(beacons[0].body.fields.action, 'mtfwc_cancel_payment', 'the beacon should cancel the payment');
	assert.strictEqual(beacons[0].body.fields.order_id, '789', 'the beacon should target the in-flight order');

	console.log('payment-js ok');
})().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
