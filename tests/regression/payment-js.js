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
	get options() { return makeNodeList(this.children.filter((c) => 'option' === c.tagName)); }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	removeAttribute(name) { delete this.attributes[name]; }
	getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
	addEventListener(type, callback) { (this.listeners[type] = this.listeners[type] || []).push(callback); }
	fire(type, event) { (this.listeners[type] || []).forEach((cb) => cb(event || { target: this, preventDefault() {} })); }
	// A disabled control emits no click, mirroring the real DOM.
	click() { if (this.disabled) { return; } this.clickCount++; this.fire('click', { target: this, preventDefault() {} }); }
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
	root.setAttribute('data-gateway-id', 'mollie_terminal_for_woocommerce');
	root.setAttribute('data-resume', '0');
	Object.keys(attrs).forEach((name) => root.setAttribute(name, attrs[name]));
	root.append(makeButton('mtfwc-toggle-log', 'Show logs')).setAttribute('data-expanded', 'false');
	root.append(makeButton('mtfwc-clear-log', 'Clear logs'));
	root.append(makeButton('mtfwc-copy-log', 'Copy logs'));
	root.append(new FakeElement(['mtfwc-terminal-select']));
	const primary = makeButton('mtfwc-primary-action', 'Start Terminal Payment');
	primary.setAttribute('data-mtfwc-mode', 'start');
	root.append(primary);
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
	let checkedPaymentMethod = 'mollie_terminal_for_woocommerce';

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
			querySelector(selector) {
				if ('input[name="payment_method"]:checked' === selector) {
					return null === checkedPaymentMethod ? null : { value: checkedPaymentMethod };
				}
				return null;
			},
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
			i18n: {
				logsHidden: 'Show logs', logsShown: 'Hide logs', copied: 'Copied', copyFailed: 'Copy failed',
				startAction: 'Start Terminal Payment', cancelAction: 'Cancel Terminal Payment',
				idle: 'Mollie Terminal status: idle',
			},
		},
		location: { href: 'https://example.test/wcpos-checkout/order-pay/123/' },
		addEventListener(type, callback) { (windowListeners[type] = windowListeners[type] || []).push(callback); },
		navigator: { sendBeacon(url, body) { beacons.push({ url, body }); return true; } },
		jQuery(target) { return { on(event, callback) { jqueryHandlers[event] = callback; return this; } }; },
	};
	context.jQuery = context.window.jQuery;
	function firePagehide() { (windowListeners.pagehide || []).forEach((callback) => callback()); }
	function firePaymentMethodChange() { nativeBody.fire('change', { target: { name: 'payment_method' } }); }

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

	const action = panel.querySelector('.mtfwc-primary-action');
	const select = panel.querySelector('.mtfwc-terminal-select');
	const textarea = panel.querySelector('.mtfwc-payment-log-textarea');
	const statusEl = panel.querySelector('.mtfwc-payment-status');

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
	// An idle panel shows a static "idle" status so the cashier sees the terminal is ready.
	assert(/idle/i.test(statusEl.textContent), 'an idle panel should show an idle status without polling');

	// The single primary button starts in "start" mode; duplicate clicks are deduped.
	assert.strictEqual(action.getAttribute('data-mtfwc-mode'), 'start', 'the primary button starts in start mode');
	action.click();
	action.click();
	assert.strictEqual(fetchCalls.length, 2, 'duplicate Start clicks should not queue a second request');
	assert.strictEqual(lastAction(), 'mtfwc_start_payment', 'Start should send mtfwc_start_payment');
	assert.strictEqual(action.disabled, true, 'the primary button should be disabled while starting');
	assert.strictEqual(fetchCalls[fetchCalls.length - 1].options.body.fields.terminal_id, 'term_default', 'start should send the selected terminal id');

	await resolveNext({ status: 'created', payment: { id: 'tr_123', status: 'open', metadata: { email: 'customer@example.com', api_key: 'live_abcdefghijklmnopqrstuvwxyz123456' } } });
	assert(textarea.value.includes('tr_123'), 'browser logs should include the safe Mollie payment id');
	assert(textarea.value.includes('open'), 'browser logs should include the safe Mollie payment status');
	assert(!textarea.value.includes('customer@example.com'), 'browser logs should not include customer metadata');
	assert(!textarea.value.includes('live_abcdefghijklmnopqrstuvwxyz123456'), 'browser logs should not include API-like secrets');

	// After a successful start the flow enters auto-poll: the button flips to
	// Cancel and stays enabled; the terminal select is frozen; no immediate poll.
	assert.strictEqual(action.getAttribute('data-mtfwc-mode'), 'cancel', 'the button flips to cancel mode while waiting');
	assert.strictEqual(action.disabled, false, 'the cancel button is available while waiting for the terminal');
	assert.strictEqual(select.disabled, true, 'the terminal choice is frozen while a payment is in flight');
	assert.strictEqual(fetchCalls.length, 2, 'auto-poll should be scheduled, not fired immediately');

	// Drive many poll ticks (all pending) to prove the log stays bounded.
	for (let i = 0; i < 60; i++) {
		await fireTimers();
		assert.strictEqual(lastAction(), 'mtfwc_poll_payment', 'scheduled ticks should poll payment status');
		await resolveNext({ status: 'pending' });
	}
	assert(textarea.value.split('\n').length <= 50, 'browser logs should keep a bounded number of lines');

	// Next scheduled poll returns paid with a redirect URL -> navigate straight
	// to the thank-you page (the order is already paid server-side).
	const thankYouUrl = 'https://example.test/wcpos-checkout/order-received/123?key=wc_order_test';
	await fireTimers();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });
	assert.strictEqual(context.window.location.href, thankYouUrl, 'a paid poll should redirect to the thank-you page');
	assert.strictEqual(placeOrderButton.clickCount, 0, 'the order-pay form must not be re-submitted when a redirect URL is available');
	assert.strictEqual(panel.mtfwcCompleted, true, 'the panel should be marked complete after a paid poll');
	assert.strictEqual(action.getAttribute('data-mtfwc-mode'), 'start', 'the button returns to start mode after completion');

	// Resume: a panel rendered with data-resume="1" picks the poll loop back up
	// on load (no Start click needed) with the button already in cancel mode.
	const resumePanel = makePanel('321', { 'data-resume': '1' });
	panels.push(resumePanel);
	jqueryHandlers.updated_checkout();
	// bind() fetches the terminal list, then resumes polling.
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	assert(resumePanel.mtfwcPoll, 'a resuming panel should arm the poll loop on load');
	assert.strictEqual(resumePanel.querySelector('.mtfwc-primary-action').getAttribute('data-mtfwc-mode'), 'cancel', 'a resuming panel shows the cancel button');
	// Let the resumed poll settle as paid so it does not interfere with later panels.
	await fireTimers();
	await resolveNext({ status: 'paid', redirect_url: thankYouUrl });

	// A failed cancel request must surface an error, not silently reset.
	const cancelPanel = makePanel('654');
	panels.push(cancelPanel);
	jqueryHandlers.updated_checkout();
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	const cancelAction = cancelPanel.querySelector('.mtfwc-primary-action');
	cancelAction.click();
	await resolveNext({ status: 'created' });
	assert.strictEqual(cancelAction.getAttribute('data-mtfwc-mode'), 'cancel', 'cancel panel is in cancel mode after start');
	cancelAction.click(); // now in cancel mode -> cancel request
	assert.strictEqual(lastAction(), 'mtfwc_cancel_payment', 'clicking the button in cancel mode cancels the payment');
	await resolveError();
	assert(/failed/i.test(cancelPanel.querySelector('.mtfwc-payment-status').textContent), 'a failed cancel should show an error status');
	assert.strictEqual(cancelAction.disabled, false, 'button re-enables after a failed cancel');
	assert.strictEqual(cancelAction.getAttribute('data-mtfwc-mode'), 'start', 'button returns to start mode after a failed cancel');

	// Abandon path: when the server cannot cancel (terminal off) it returns
	// "abandoned"; the panel frees up so a fresh Start works.
	cancelAction.click();
	await resolveNext({ status: 'created' });
	cancelAction.click();
	await resolveNext({ status: 'abandoned', message: 'set aside' });
	assert.strictEqual(cancelAction.getAttribute('data-mtfwc-mode'), 'start', 'an abandoned payment returns the panel to start mode');
	assert.strictEqual(cancelAction.disabled, false, 'the button is usable again after abandon');
	assert(!cancelPanel.mtfwcPoll, 'the poll loop stops after abandon');

	// Switching payment method away from Mollie Terminal stops and cancels an
	// in-flight payment so it does not linger open.
	const methodPanel = makePanel('987');
	panels.push(methodPanel);
	jqueryHandlers.updated_checkout();
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });
	methodPanel.querySelector('.mtfwc-primary-action').click();
	await resolveNext({ status: 'created' });
	assert(methodPanel.mtfwcPoll, 'method panel is polling after start');
	const fetchesBeforeSwitch = fetchCalls.length;
	checkedPaymentMethod = 'cod'; // customer decides to pay cash
	firePaymentMethodChange();
	await flush();
	assert(!methodPanel.mtfwcPoll, 'switching payment method stops the poll loop');
	assert.strictEqual(fetchCalls.length, fetchesBeforeSwitch + 1, 'switching payment method fires a cancel request');
	assert.strictEqual(lastAction(), 'mtfwc_cancel_payment', 'switching payment method cancels the terminal payment');
	await resolveNext({ status: 'canceled' });
	checkedPaymentMethod = 'mollie_terminal_for_woocommerce';

	// Checkout refresh binds new panels exactly once.
	const refreshedPanel = makePanel('456');
	panels.push(refreshedPanel);
	assert(jqueryHandlers.updated_checkout, 'payment.js should listen for WooCommerce checkout refreshes');
	jqueryHandlers.updated_checkout();
	jqueryHandlers.updated_checkout();
	refreshedPanel.querySelector('.mtfwc-toggle-log').click();
	assert.strictEqual(refreshedPanel.querySelector('.mtfwc-toggle-log').getAttribute('data-expanded'), 'true', 'checkout refresh binding should attach handlers once to new panels');
	await resolveNext({ terminals: [{ id: 'term_default', label: 'Back office', status: 'active' }], default_terminal_id: 'term_default' });

	// Without a redirect URL, completion falls back to submitting #place_order.
	const refreshedAction = refreshedPanel.querySelector('.mtfwc-primary-action');
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	await fireTimers();
	await resolveNext({ status: 'paid' });
	assert.strictEqual(placeOrderButton.clickCount, 1, 'completion falls back to #place_order when no redirect URL is provided');

	// A timed-out auto-poll sends a cancel to Mollie instead of leaving it open.
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	assert(refreshedPanel.mtfwcPoll, 'auto-poll should be armed after a successful start');
	refreshedPanel.mtfwcPoll.deadline = 0; // force the timeout branch on the next tick
	await fireTimers();
	assert.strictEqual(lastAction(), 'mtfwc_cancel_payment', 'a timed-out auto-poll should auto-cancel the payment');
	await resolveNext({ status: 'canceled' });
	assert(/timed out/i.test(refreshedPanel.querySelector('.mtfwc-payment-status').textContent), 'the timeout message should be shown after auto-cancel');
	assert.strictEqual(refreshedAction.disabled, false, 'the button re-enables after a timed-out payment is canceled');
	assert.strictEqual(refreshedAction.getAttribute('data-mtfwc-mode'), 'start', 'the button returns to start mode after timeout');

	// While the timeout auto-cancel is in flight the button is frozen (a click
	// can't fire a stray request); once it resolves the cashier can retry.
	refreshedAction.click();
	await resolveNext({ status: 'created' });
	refreshedPanel.mtfwcPoll.deadline = 0;
	await fireTimers(); // timeout branch fires the cancel
	assert.strictEqual(refreshedAction.disabled, true, 'the button is frozen while the timeout-cancel is in flight');
	refreshedAction.click(); // ignored: button disabled
	assert.strictEqual(lastAction(), 'mtfwc_cancel_payment', 'a click during the frozen window queues no new request');
	await resolveNext({ status: 'canceled' });
	assert.strictEqual(refreshedAction.disabled, false, 'the button re-enables after the timeout-cancel resolves');
	// Retry after the timeout resolved: a fresh Start proceeds to waiting.
	refreshedAction.click();
	assert.strictEqual(lastAction(), 'mtfwc_start_payment', 'a retry after timeout starts a fresh payment');
	assert(/sending/i.test(refreshedPanel.querySelector('.mtfwc-payment-status').textContent), 'the retry shows the sending state');
	await resolveNext({ status: 'created' });
	assert(/waiting/i.test(refreshedPanel.querySelector('.mtfwc-payment-status').textContent), 'the retry should proceed to the waiting state');
	// Wind the retry's poll loop down so it does not fire a pagehide beacon later.
	refreshedPanel.mtfwcPoll.deadline = 0;
	await fireTimers();
	await resolveNext({ status: 'canceled' });

	// Locked panels never fetch the terminal list and always use the default terminal.
	const lockedPanel = makePanel('789', { 'data-lock-terminal': '1' });
	panels.push(lockedPanel);
	const fetchCountBefore = fetchCalls.length;
	jqueryHandlers.updated_checkout();
	assert.strictEqual(fetchCalls.length, fetchCountBefore, 'locked panels must not fetch the terminal list');
	lockedPanel.querySelector('.mtfwc-primary-action').click();
	assert.strictEqual(lastAction(), 'mtfwc_start_payment', 'locked panel Start should fire');
	assert.strictEqual(fetchCalls[fetchCalls.length - 1].options.body.fields.terminal_id, 'term_default', 'locked panels always send the default terminal');
	await resolveNext({ status: 'created' });

	// An empty terminal list disables the select and marks it unavailable.
	const emptyPanel = makePanel('999');
	panels.push(emptyPanel);
	jqueryHandlers.updated_checkout();
	const emptySelect = emptyPanel.querySelector('.mtfwc-terminal-select');
	await resolveNext({ terminals: [], default_terminal_id: '' });
	assert.strictEqual(emptySelect.disabled, true, 'an empty terminal list should disable the select');
	assert.strictEqual(emptySelect.getAttribute('data-mtfwc-unavailable'), '1', 'an empty terminal list should mark the select unavailable');

	// Closing the page while a payment is in flight fires one best-effort cancel
	// beacon (the locked panel is still polling).
	firePagehide();
	assert.strictEqual(beacons.length, 1, 'exactly one cancel beacon should fire for the in-flight payment');
	assert.strictEqual(beacons[0].body.fields.action, 'mtfwc_cancel_payment', 'the beacon should cancel the payment');
	assert.strictEqual(beacons[0].body.fields.order_id, '789', 'the beacon should target the in-flight order');

	console.log('payment-js ok');
})().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
