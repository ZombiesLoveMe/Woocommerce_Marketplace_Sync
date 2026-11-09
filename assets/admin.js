/* Marketplace Sync — admin app (no build step, vanilla JS). */
(function () {
	'use strict';
	var __ = (window.wp && window.wp.i18n && window.wp.i18n.__) ? window.wp.i18n.__ : function (s) { return s; };

	var BOOT = window.WES_BOOT || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	var NS = '/woo-etsy-sync/v1';
	var state = BOOT.state;
	var root = document.getElementById('wes-app');
	if (!root || !apiFetch) { return; }

	/* ------------------------------------------------------------------
	 * Tiny DOM helpers.
	 * ---------------------------------------------------------------- */

	function h(tag, props) {
		var el = document.createElement(tag);
		props = props || {};
		Object.keys(props).forEach(function (k) {
			var v = props[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { el.className = v; }
			else if (k === 'html') { el.innerHTML = v; }
			else if (k === 'text') { el.textContent = v; }
			else if (k === 'style') { el.setAttribute('style', v); }
			else if (k.indexOf('on') === 0 && typeof v === 'function') { el.addEventListener(k.slice(2).toLowerCase(), v); }
			else if (k === 'value') { el.value = v; }
			else if (k === 'checked' || k === 'disabled' || k === 'selected') { el[k] = !!v; }
			else { el.setAttribute(k, v === true ? '' : v); }
		});
		for (var i = 2; i < arguments.length; i++) { append(el, arguments[i]); }
		return el;
	}
	function append(el, child) {
		if (child === null || child === undefined || child === false) { return; }
		if (Array.isArray(child)) { child.forEach(function (c) { append(el, c); }); return; }
		el.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
	}
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }
	function mount(el, child) { clear(el); append(el, child); return el; }

	var ICONS = {
		home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
		box: 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
		link: 'M10 14a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5M14 10a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5',
		settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
		activity: 'M22 12h-4l-3 9L9 3l-3 9H2',
		refresh: 'M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6',
		check: 'M20 6 9 17l-5-5',
		alert: 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
		info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
		external: 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3',
		upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
		download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
		plug: 'M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4',
		tag: 'M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01',
		truck: 'M1 3h15v13H1zM16 8h4l3 3v5h-7zM5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
		dollar: 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
		layers: 'M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
		stock: 'M3 3v18h18M7 14l4-4 4 4 5-5',
		eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
		x: 'M18 6 6 18M6 6l12 12',
		arrow: 'M5 12h14M12 5l7 7-7 7',
		sync: 'M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3',
		pen: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
		cart: 'M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM20 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6',
		trash: 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6',
		key: 'M21 2l-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4',
		search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
		file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8'
	};
	function icon(name) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '2');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		var p = document.createElementNS(ns, 'path');
		p.setAttribute('d', ICONS[name] || ICONS.info);
		svg.appendChild(p);
		return svg;
	}
	function brandMark() {
		var s = h('span', { class: 'wes-brand__mark' });
		s.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4"/><path d="M3 11V10a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>';
		return s;
	}

	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	function ago(ts) {
		if (!ts) { return 'never'; }
		var t = typeof ts === 'number' ? ts * 1000 : Date.parse(ts);
		var s = Math.max(0, Math.round((Date.now() - t) / 1000));
		if (s < 45) { return __('just now', 'woo-etsy-sync'); }
		if (s < 3600) { return Math.round(s / 60) + __(' min ago', 'woo-etsy-sync'); }
		if (s < 86400) { return Math.round(s / 3600) + ' h ago'; }
		var d = Math.round(s / 86400);
		return d === 1 ? 'yesterday' : d + __(' days ago', 'woo-etsy-sync');
	}
	function fullDate(ts) {
		var t = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
		return t.toLocaleString();
	}

	/* ------------------------------------------------------------------
	 * API, toasts, modals.
	 * ---------------------------------------------------------------- */

	function call(path, opts) {
		opts = opts || {};
		var req = { path: NS + path, method: opts.method || 'GET' };
		if (opts.data !== undefined) { req.data = opts.data; }
		return apiFetch(req).catch(function (err) {
			var msg = (err && err.message) ? err.message : __('Something went wrong. Please try again.', 'woo-etsy-sync');
			var e = new Error(msg);
			e.raw = err;
			throw e;
		});
	}

	var toastBox = null;
	function toast(msg, type) {
		if (!toastBox) { toastBox = h('div', { class: 'wes-root wes-toasts', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(toastBox); }
		var t = h('div', { class: 'wes-toast wes-toast--' + (type || 'ok') }, msg);
		toastBox.appendChild(t);
		setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; }, type === 'error' ? 7000 : 3500);
		setTimeout(function () { t.remove(); }, type === 'error' ? 7400 : 3900);
	}

	function modal(opts) {
		var backdrop = h('div', { class: 'wes-root wes-modal-backdrop' });
		var foot = h('div', { class: 'wes-modal__foot' });
		var box = h('div', { class: 'wes-modal', role: 'dialog', 'aria-modal': 'true' },
			h('div', { class: 'wes-modal__head' }, opts.icon ? icon(opts.icon) : null, h('h3', { text: opts.title }), h('span', { style: 'flex:1' }),
				h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', 'aria-label': __('Close', 'woo-etsy-sync'), onclick: function () { close(); } }, icon('x'))),
			h('div', { class: 'wes-modal__body' }, opts.body),
			foot
		);
		function close() { backdrop.remove(); document.removeEventListener('keydown', onKey); }
		function onKey(e) { if (e.key === 'Escape') { close(); } }
		(opts.actions || [{ label: __('Close', 'woo-etsy-sync'), primary: true }]).forEach(function (a) {
			foot.appendChild(h('button', {
				class: 'wes-btn' + (a.primary ? ' wes-btn--primary' : '') + (a.danger ? ' wes-btn--danger' : ''),
				onclick: function () { if (a.onClick) { if (a.onClick(close) === false) { return; } } close(); }
			}, a.label));
		});
		backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop) { close(); } });
		document.addEventListener('keydown', onKey);
		backdrop.appendChild(box);
		document.body.appendChild(backdrop);
		var first = foot.querySelector('.wes-btn--primary');
		if (first) { first.focus(); }
		return { close: close, el: box };
	}

	function confirmBox(title, text, okLabel, danger) {
		return new Promise(function (resolve) {
			var answered = false;
			modal({
				title: title,
				body: h('p', { text: text }),
				actions: [
					{ label: __('Cancel', 'woo-etsy-sync'), onClick: function () { answered = true; resolve(false); } },
					{ label: okLabel || 'OK', primary: !danger, danger: danger, onClick: function () { answered = true; resolve(true); } }
				]
			});
			setTimeout(function () { if (!answered) { /* closed via X/Esc resolves false lazily */ } }, 0);
		});
	}

	function busy(btn, on, label) {
		if (!btn) { return; }
		if (on) {
			btn.dataset.label = btn.innerHTML;
			btn.disabled = true;
			btn.classList.add('is-busy');
			btn.innerHTML = __('<span class="wes-spinner"></span> ', 'woo-etsy-sync') + esc(label || __('Working…', 'woo-etsy-sync'));
		} else {
			btn.disabled = false;
			btn.classList.remove('is-busy');
			if (btn.dataset.label) { btn.innerHTML = btn.dataset.label; }
		}
	}

	function alertBox(type, title, text, extra) {
		var ic = { info: 'info', warn: 'alert', error: 'alert', ok: 'check' }[type];
		return h('div', { class: 'wes-alert wes-alert--' + type }, icon(ic), h('div', { class: 'wes-alert__body' }, title ? h('strong', { text: title }) : null, text ? h('div', { html: text }) : null, extra || null));
	}

	function pill(status, listingState) {
		var map = {
			synced: ['On Etsy', 'ok'], linked: ['Linked', 'ok'], error: ['Error', 'error'], queued: ['Queued', 'info'],
			not_synced: ['Not on Etsy', 'muted'], excluded: ['Excluded', 'muted']
		};
		var states = { active: ['Live', 'ok'], draft: ['Draft', 'info'], inactive: ['Inactive', 'warn'], sold_out: ['Sold out', 'warn'], expired: ['Expired', 'warn'] };
		var m = map[status] || ['—', 'muted'];
		if ((status === 'synced' || status === 'linked') && states[listingState]) { m = states[listingState]; }
		return h('span', { class: 'wes-pill wes-pill--' + m[1] }, m[0]);
	}

	/* ------------------------------------------------------------------
	 * Shared data caches.
	 * ---------------------------------------------------------------- */

	var cache = { shop: null, taxonomy: null, terms: null };
	function loadShopData(refresh) {
		if (cache.shop && !refresh) { return Promise.resolve(cache.shop); }
		return call('/shop-data' + (refresh ? '?refresh=1' : '')).then(function (d) { cache.shop = d; return d; });
	}
	function loadTaxonomy() {
		if (cache.taxonomy) { return Promise.resolve(cache.taxonomy); }
		return call('/taxonomy').then(function (d) { cache.taxonomy = d; return d; });
	}
	function loadTerms() {
		if (cache.terms) { return Promise.resolve(cache.terms); }
		return call('/wc-terms').then(function (d) { cache.terms = d; return d; });
	}

	function refreshState() {
		return call('/state').then(function (s) { state = s; renderChrome(); return s; });
	}

	/* ------------------------------------------------------------------
	 * Form controls.
	 * ---------------------------------------------------------------- */

	function field(label, control, help) {
		return h('div', { class: 'wes-field' }, label ? h('label', { text: label }) : null, control, help ? h('div', { class: 'wes-help', html: help }) : null);
	}
	function toggle(checked, title, desc, onChange) {
		var input = h('input', { type: 'checkbox', checked: checked, onchange: function () { onChange(input.checked); } });
		return h('label', { class: 'wes-toggle' }, input, h('span', { class: 'wes-toggle__track' }), h('span', { class: 'wes-toggle__text' }, h('strong', { text: title }), desc ? h('span', { html: desc }) : null));
	}
	function select(options, value, onChange, placeholder) {
		var s = h('select', { onchange: function () { onChange(s.value); } });
		if (placeholder !== undefined) { s.appendChild(h('option', { value: '', text: placeholder })); }
		options.forEach(function (o) { s.appendChild(h('option', { value: String(o.id), text: o.label, selected: String(o.id) === String(value) })); });
		return s;
	}
	function choiceCards(options, value, onChange) {
		var wrap = h('div', { class: 'wes-choices' });
		function draw() {
			clear(wrap);
			options.forEach(function (o) {
				wrap.appendChild(h('label', { class: 'wes-choice' + (o.value === value ? ' is-active' : ''), onclick: function () { value = o.value; onChange(o.value); draw(); } },
					h('strong', { text: o.label }), o.desc ? h('span', { text: o.desc }) : null));
			});
		}
		draw();
		return wrap;
	}

	/**
	 * Searchable Etsy category picker.
	 */
	function taxonomyPicker(value, onChange, placeholder) {
		var wrap = h('div', { class: 'wes-picker' });
		var current = value && value.id ? value : null;
		var input = h('input', { type: 'search', placeholder: __('Type to search Etsy categories (e.g. "earrings", "candles")…', 'woo-etsy-sync'), autocomplete: 'off' });
		var list = h('div', { class: 'wes-picker__list wes-hidden', role: 'listbox' });
		var display = h('div', { class: 'wes-picker__value', tabindex: '0', onclick: open, onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } } });
		var hl = 0, results = [];

		function drawValue() {
			mount(display, [h('span', { text: current ? current.label : (placeholder || __('Choose an Etsy category…', 'woo-etsy-sync')) }),
				current ? h('button', { type: 'button', class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function (e) { e.stopPropagation(); current = null; onChange(null); drawValue(); } }, __('Clear', 'woo-etsy-sync')) : h('span', { class: 'wes-muted' }, icon('search'))]);
			display.classList.toggle('is-empty', !current);
		}
		function open() {
			display.classList.add('wes-hidden');
			input.classList.remove('wes-hidden');
			input.value = '';
			input.focus();
			list.classList.remove('wes-hidden');
			mount(list, h('div', { class: 'wes-picker__empty' }, h('span', { class: 'wes-spinner' })));
			loadTaxonomy().then(function () { search(); }).catch(function (e) { mount(list, h('div', { class: 'wes-picker__empty', text: e.message })); });
		}
		function closeList() {
			setTimeout(function () {
				list.classList.add('wes-hidden');
				input.classList.add('wes-hidden');
				display.classList.remove('wes-hidden');
			}, 150);
		}
		function search() {
			var q = input.value.trim().toLowerCase();
			var words = q.split(/\s+/).filter(Boolean);
			var all = cache.taxonomy || [];
			results = [];
			for (var i = 0; i < all.length && results.length < 60; i++) {
				var p = all[i].path.toLowerCase();
				var ok = words.every(function (w) { return p.indexOf(w) !== -1; });
				if (ok && (words.length || all[i].path.indexOf('›') === -1)) { results.push(all[i]); }
			}
			// Prefer leaf matches whose last segment matches.
			if (words.length) {
				results.sort(function (a, b) {
					var la = a.path.split(' › ').pop().toLowerCase(), lb = b.path.split(' › ').pop().toLowerCase();
					var sa = (la.indexOf(words[0]) !== -1 ? 0 : 1) + (a.leaf ? 0 : 0.5), sb = (lb.indexOf(words[0]) !== -1 ? 0 : 1) + (b.leaf ? 0 : 0.5);
					return sa - sb || a.path.length - b.path.length;
				});
			}
			hl = 0;
			drawList();
		}
		function drawList() {
			clear(list);
			if (!results.length) { list.appendChild(h('div', { class: 'wes-picker__empty', text: __('No categories match. Try a simpler word.', 'woo-etsy-sync') })); return; }
			results.forEach(function (r, i) {
				var parts = r.path.split(' › ');
				var last = parts.pop();
				list.appendChild(h('div', {
					class: 'wes-picker__item' + (i === hl ? ' is-hl' : ''), role: 'option',
					onmousedown: function (e) { e.preventDefault(); pick(r); }
				}, h('b', { text: last }), parts.length ? h('small', { text: parts.join(' › ') }) : null));
			});
		}
		function pick(r) {
			current = { id: r.id, label: r.path };
			onChange(current);
			drawValue();
			input.blur();
		}
		input.classList.add('wes-hidden');
		input.addEventListener('input', search);
		input.addEventListener('blur', closeList);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); hl = Math.min(results.length - 1, hl + 1); drawList(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); hl = Math.max(0, hl - 1); drawList(); }
			else if (e.key === 'Enter') { e.preventDefault(); if (results[hl]) { pick(results[hl]); } }
			else if (e.key === 'Escape') { input.blur(); }
		});
		drawValue();
		append(wrap, [display, input, list]);
		return wrap;
	}

	/* ------------------------------------------------------------------
	 * Background job ticker (keeps queues moving while this screen is open).
	 * ---------------------------------------------------------------- */

	var ticking = false;
	var tickTimer = null;
	function pendingTotal() {
		var p = state.stats && state.stats.pending ? state.stats.pending : {};
		var t = 0;
		Object.keys(p).forEach(function (k) { t += +p[k] || 0; });
		return t;
	}
	function scheduleTick(delay) {
		clearTimeout(tickTimer);
		tickTimer = setTimeout(tick, delay === undefined ? 1500 : delay);
	}
	function linkRunningAny() {
		if (state.link_report && state.link_report.status === 'running') { return true; }
		return Object.keys(EXT).some(function (k) { return EXT[k].linkRunning && EXT[k].linkRunning(); });
	}
	function tick() {
		var linkRunning = linkRunningAny();
		if (ticking || (!pendingTotal() && !linkRunning)) { return; }
		ticking = true;
		call('/tick', { method: 'POST' }).then(function (r) {
			state.stats = r.stats;
			state.link_report = r.link_report;
			Object.keys(EXT).forEach(function (k) { if (EXT[k].onTick) { EXT[k].onTick(r); } });
			renderChrome();
			document.dispatchEvent(new CustomEvent('wes:tick'));
		}).catch(function () { /* ignore; next tick will retry */ }).finally(function () {
			ticking = false;
			var again = pendingTotal() || linkRunningAny();
			if (again) { scheduleTick(2500); } else { document.dispatchEvent(new CustomEvent('wes:idle')); }
		});
	}

	/* ------------------------------------------------------------------
	 * Router + chrome.
	 * ---------------------------------------------------------------- */

	var header = h('div', { class: 'wes-header' });
	var main = h('div', { class: 'wes-main' });
	var currentRoute = '';
	var viewCleanup = null;

	function route() {
		var r = (location.hash || '').replace(/^#\/?/, '');
		return r || 'platforms';
	}
	function go(r) { location.hash = '#/' + r; }

	function needsWizard() {
		return !state.settings.setup_complete;
	}

	var EXT = {};
	function channel() { var s0 = route().split('/')[0]; return EXT[s0] ? s0 : 'etsy'; }

	function renderChrome() {
		var r = route();
		var section = r.split('/')[0];
		if (EXT[section]) {
			var X = EXT[section];
			var sub = r.split('/')[1] || 'overview';
			var tabs0 = X.tabs ? X.tabs() : [];
			mount(header, h('div', { class: 'wes-header__inner' },
				h('div', { class: 'wes-header__top' }, brandBlock(), channelSwitch(section), h('div', { class: 'wes-header__spacer' }), syncingPill(), X.chip ? X.chip() : null),
				tabs0.length ? h('nav', { class: 'wes-nav' }, tabs0.map(function (t) {
					return h('a', { href: '#/' + t[0], class: (t[0] === section ? sub === 'overview' : t[0].indexOf(section + '/' + sub) === 0) ? 'is-active' : '' }, t[1], t[2] || null);
				})) : null
			));
			return;
		}
		var errs = state.stats ? state.stats.errors : 0;
		var shop = state.connected
			? h('button', { class: 'wes-shopchip', onclick: function () { go('settings/connection'); }, title: __('Connection settings', 'woo-etsy-sync') }, h('span', { class: 'wes-dot ' + (state.needs_reconnect ? 'wes-dot--err' : 'wes-dot--ok') }), state.shop.name || __('Etsy shop', 'woo-etsy-sync'))
			: h('button', { class: 'wes-shopchip', onclick: function () { go(needsWizard() ? 'setup' : 'settings/connection'); } }, h('span', { class: 'wes-dot wes-dot--warn' }), __('Not connected', 'woo-etsy-sync'));
		var tabs = [
			['dashboard', __('Dashboard', 'woo-etsy-sync'), 'home'],
			['products', __('Products', 'woo-etsy-sync'), 'box', errs ? h('span', { class: 'wes-badge', title: errs + __(' with errors', 'woo-etsy-sync') }, errs) : null],
			['link', __('Link existing listings', 'woo-etsy-sync'), 'link'],
			['settings', __('Settings', 'woo-etsy-sync'), 'settings'],
			['activity', __('Activity', 'woo-etsy-sync'), 'activity']
		];
		mount(header, h('div', { class: 'wes-header__inner' },
			h('div', { class: 'wes-header__top' }, brandBlock(), section === 'setup' ? null : channelSwitch('etsy'), h('div', { class: 'wes-header__spacer' }), syncingPill(), shop),
			section === 'setup' ? null : h('nav', { class: 'wes-nav' }, tabs.map(function (t) {
				return h('a', { href: '#/' + t[0], class: section === t[0] ? 'is-active' : '' }, t[1], t[3] || null);
			}))
		));
	}

	function brandBlock() {
		return h('div', { class: 'wes-brand' }, brandMark(), h('span', null, __('Marketplace Sync', 'woo-etsy-sync'), h('small', { text: __(' for WooCommerce', 'woo-etsy-sync') })));
	}
	function syncingPill() {
		return pendingTotal() ? h('span', { class: 'wes-pill wes-pill--accent' }, h('span', { class: 'wes-spinner', style: 'width:12px;height:12px;border-width:2px' }), __(' Syncing ', 'woo-etsy-sync') + pendingTotal()) : null;
	}
	function channelSwitch(active) {
		var dot = function (ok) { return h('span', { class: 'wes-dot ' + (ok ? 'wes-dot--ok' : 'wes-dot--warn') }); };
		var items = [];
		if (EXT.platforms) { items.push(h('button', { class: active === 'platforms' ? 'is-active' : '', onclick: function () { go('platforms'); } }, icon('layers'), __('All platforms', 'woo-etsy-sync'))); }
		if (etsyInUse()) { items.push(h('button', { class: active === 'etsy' ? 'is-active' : '', onclick: function () { go('dashboard'); } }, dot(state.connected && !state.needs_reconnect), __('Etsy', 'woo-etsy-sync'))); }
		Object.keys(EXT).forEach(function (k) {
			var X = EXT[k];
			if (k === 'platforms' || (X.inUse && !X.inUse() && active !== k)) { return; }
			items.push(h('button', { class: active === k ? 'is-active' : '', onclick: function () { go(k); } }, dot(X.connected()), X.label));
		});
		return h('div', { class: 'wes-seg wes-channels', role: 'tablist' }, items);
	}
	function etsyInUse() { return state.connected || state.has_credentials || state.settings.setup_complete || channel() === 'etsy'; }

	function render() {
		var r = route();
		if (EXT[r.split('/')[0]]) {
			if (viewCleanup) { try { viewCleanup(); } catch (e) { /* noop */ } viewCleanup = null; }
			currentRoute = r;
			renderChrome();
			clear(main);
			viewCleanup = EXT[r.split('/')[0]].render(main, r.split('/').slice(1)) || null;
			window.scrollTo(0, 0);
			return;
		}
		if (needsWizard() && r.indexOf('setup') !== 0 && !sessionStorageGet('wes_skip_wizard')) {
			go('setup');
			return;
		}
		if (viewCleanup) { try { viewCleanup(); } catch (e) { /* noop */ } viewCleanup = null; }
		currentRoute = r;
		renderChrome();
		clear(main);
		var section = r.split('/')[0];
		var views = { dashboard: viewDashboard, products: viewProducts, link: viewLink, settings: viewSettings, activity: viewActivity, setup: viewWizard };
		var fn = views[section] || viewDashboard;
		viewCleanup = fn(main, r.split('/').slice(1)) || null;
		window.scrollTo(0, 0);
	}

	function sessionStorageGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
	function sessionStorageSet(k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* noop */ } }

	function pageHead(title, sub, actions) {
		return h('div', { class: 'wes-page-head' }, h('div', null, h('h1', { text: title }), sub ? h('p', { html: sub }) : null), actions ? h('div', { class: 'wes-page-head__actions' }, actions) : null);
	}

	function onEvent(name, fn) {
		document.addEventListener(name, fn);
		return function () { document.removeEventListener(name, fn); };
	}

	/* ------------------------------------------------------------------
	 * Setup wizard.
	 * ---------------------------------------------------------------- */

	function viewWizard(el) {
		var steps = [__('Welcome', 'woo-etsy-sync'), __('Etsy app', 'woo-etsy-sync'), __('Connect shop', 'woo-etsy-sync'), __('Defaults', 'woo-etsy-sync'), __('Done', 'woo-etsy-sync')];
		var step = state.connected ? 3 : (state.has_credentials ? 2 : 0);
		if (BOOT.flash && BOOT.flash.connected) { step = 3; }
		var draft = JSON.parse(JSON.stringify(state.settings));
		var wrap = h('div', { class: 'wes-wizard' });
		append(el, wrap);

		function nav(back, nextBtn) {
			return h('div', { class: 'wes-wizard__nav' }, back ? h('button', { class: 'wes-btn wes-btn--ghost', onclick: function () { step--; draw(); } }, __('← Back', 'woo-etsy-sync')) : h('span'), nextBtn || null);
		}

		function draw() {
			clear(wrap);
			wrap.appendChild(h('ol', { class: 'wes-steps' }, steps.map(function (s, i) {
				return h('li', { class: i < step ? 'is-done' : (i === step ? 'is-current' : '') }, s);
			})));
			var card = h('div', { class: 'wes-card' });
			var body = h('div', { class: 'wes-card__body', style: 'padding:28px' });
			card.appendChild(body);
			wrap.appendChild(card);
			[stepWelcome, stepApp, stepConnect, stepDefaults, stepDone][step](body);
			if (step < 4) {
				wrap.appendChild(h('p', { class: 'wes-footer' }, h('a', { href: '#', onclick: function (e) { e.preventDefault(); sessionStorageSet('wes_skip_wizard', '1'); go('dashboard'); } }, __('Skip setup for now', 'woo-etsy-sync'))));
			}
		}

		function stepWelcome(b) {
			append(b, [
				h('div', { class: 'wes-hero' },
					h('div', { class: 'wes-hero__art' }, h('div', { class: 'wes-hero__tile wes-hero__tile--woo' }, __('Woo', 'woo-etsy-sync')), h('span', { class: 'wes-hero__sync' }, icon('sync')), h('div', { class: 'wes-hero__tile wes-hero__tile--etsy' }, __('Etsy', 'woo-etsy-sync'))),
					h('h1', { text: __('Let’s connect your Etsy shop', 'woo-etsy-sync') }),
					h('p', { text: __('This takes about 5 minutes. Your store talks to Etsy directly with your own free Etsy developer key — no middle-man servers that can disappear on you.', 'woo-etsy-sync') })
				),
				h('div', { class: 'wes-features' },
					h('div', { class: 'wes-feature' }, h('strong', { text: __('List products', 'woo-etsy-sync') }), h('span', { text: __('Titles, descriptions, prices, photos, tags and variations.', 'woo-etsy-sync') })),
					h('div', { class: 'wes-feature' }, h('strong', { text: __('Two-way stock', 'woo-etsy-sync') }), h('span', { text: __('A sale on either side updates stock on the other.', 'woo-etsy-sync') })),
					h('div', { class: 'wes-feature' }, h('strong', { text: __('Link what you have', 'woo-etsy-sync') }), h('span', { text: __('Match listings already on Etsy to products by SKU.', 'woo-etsy-sync') }))
				),
				h('div', { class: 'wes-divider' }),
				nav(false, h('button', { class: 'wes-btn wes-btn--primary wes-btn--lg', onclick: function () { step = 1; draw(); } }, __('Get started', 'woo-etsy-sync'), icon('arrow')))
			]);
		}

		function stepApp(b) {
			var keyIn = h('input', { type: 'text', value: state.keystring || '', placeholder: 'e.g. 1aa2bb33c44d5ee6f7gg8hh9', autocomplete: 'off', spellcheck: 'false' });
			var secIn = h('input', { type: 'password', value: '', placeholder: state.has_secret ? __('•••••••• (saved — leave blank to keep)', 'woo-etsy-sync') : __('Paste your shared secret', 'woo-etsy-sync'), autocomplete: 'new-password' });
			var err = h('div');
			var saveBtn = h('button', { class: 'wes-btn wes-btn--primary', onclick: function () {
				clear(err);
				busy(saveBtn, true, __('Checking with Etsy…', 'woo-etsy-sync'));
				call('/credentials', { method: 'POST', data: { keystring: keyIn.value, shared_secret: secIn.value } }).then(function (r) {
					state = r.state;
					toast(__('Etsy accepted your keys', 'woo-etsy-sync'));
					step = 2; draw();
				}).catch(function (e) { busy(saveBtn, false); mount(err, alertBox('error', __('That didn’t work', 'woo-etsy-sync'), esc(e.message))); });
			} }, __('Save & test keys', 'woo-etsy-sync'));

			append(b, [
				h('h2', { style: 'font-size:20px;margin-bottom:6px', text: __('Create your free Etsy app', 'woo-etsy-sync') }),
				h('p', { class: 'wes-muted', style: 'margin-bottom:20px', text: __('Etsy needs every store to use its own “app” key. You only do this once.', 'woo-etsy-sync') }),
				!state.site_https ? alertBox('warn', __('Your site isn’t using HTTPS', 'woo-etsy-sync'), __('Etsy only allows secure (https://) callback addresses. Switch your site to HTTPS first (most hosts offer a free SSL certificate), then come back.', 'woo-etsy-sync')) : null,
				h('ol', { class: 'wes-steplist' },
					h('li', null, h('div', null, h('strong', null, __('Open Etsy’s app page', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small' }, __('Sign in with the account that owns your shop.', 'woo-etsy-sync')),
						h('div', { style: 'margin-top:8px' }, h('a', { class: 'wes-btn wes-btn--sm', href: 'https://www.etsy.com/developers/register', target: '_blank', rel: 'noopener' }, __('Create an app on Etsy', 'woo-etsy-sync'), icon('external'))))),
					h('li', null, h('div', null, h('strong', null, __('Fill in the form', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small', html: __('Name: anything (e.g. “', 'woo-etsy-sync') + esc((state.shop && state.shop.name) || __('My Shop', 'woo-etsy-sync')) + __(' Sync”). Describe it as syncing your own WooCommerce store. Choose <em>“Seller tools”</em> / that it’s for your own shop.', 'woo-etsy-sync') }))),
					h('li', null, h('div', { style: 'min-width:0' }, h('strong', null, __('Add this callback URL', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small' }, __('After creating the app, open it and add this exact address under “Callback URLs”:', 'woo-etsy-sync')),
						copyRow(state.redirect_uri))),
					h('li', null, h('div', null, h('strong', null, __('Copy your keys here', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small' }, __('Etsy shows a “Keystring” and a “Shared secret” for your app.', 'woo-etsy-sync'))))
				),
				h('div', { class: 'wes-row' }, field(__('Keystring', 'woo-etsy-sync'), keyIn), field(__('Shared secret', 'woo-etsy-sync'), secIn)),
				err,
				h('div', { class: 'wes-divider' }),
				nav(true, saveBtn)
			]);
		}

		function stepConnect(b) {
			var err = h('div');
			if (BOOT.flash && BOOT.flash.error) { append(err, alertBox('error', __('Etsy connection failed', 'woo-etsy-sync'), esc(BOOT.flash.error))); BOOT.flash.error = ''; }
			var btn = h('button', { class: 'wes-btn wes-btn--primary wes-btn--lg', onclick: function () {
				busy(btn, true, __('Opening Etsy…', 'woo-etsy-sync'));
				call('/connect', { method: 'POST' }).then(function (r) { window.location.href = r.url; }).catch(function (e) { busy(btn, false); mount(err, alertBox('error', __('Couldn’t start the connection', 'woo-etsy-sync'), esc(e.message))); });
			} }, icon('plug'), __('Connect my Etsy shop', 'woo-etsy-sync'));
			append(b, [
				h('div', { class: 'wes-hero' },
					h('div', { class: 'wes-hero__art' }, h('div', { class: 'wes-hero__tile wes-hero__tile--etsy' }, icon('key'))),
					h('h1', { text: __('Give your store permission', 'woo-etsy-sync') }),
					h('p', { text: __('You’ll be sent to Etsy to approve access, then brought straight back here.', 'woo-etsy-sync') })
				),
				h('div', { style: 'text-align:center;margin:22px 0 6px' }, btn),
				h('p', { class: 'wes-muted wes-small', style: 'text-align:center' }, __('If Etsy says the app isn’t approved yet, wait a little — new apps can take a short while to activate.', 'woo-etsy-sync')),
				err,
				h('div', { class: 'wes-divider' }),
				nav(true, null)
			]);
		}

		function stepDefaults(b) {
			var body = h('div', null, h('div', { class: 'wes-skel', style: 'width:60%' }), h('div', { class: 'wes-skel', style: 'width:40%;margin-top:10px' }));
			append(b, [
				h('h2', { style: 'font-size:20px;margin-bottom:6px' }, __('Connected to ', 'woo-etsy-sync'), h('span', { style: 'color:var(--wes-accent)', text: state.shop.name || __('your shop', 'woo-etsy-sync') }), ' 🎉'),
				h('p', { class: 'wes-muted', style: 'margin-bottom:22px', text: __('Pick a few defaults Etsy needs for every listing. You can fine-tune everything later in Settings.', 'woo-etsy-sync') }),
				body
			]);
			loadShopData(true).then(function (shop) {
				var readinessSel;
				function readinessField() {
					readinessSel = select(shop.readiness, draft.default_readiness_state, function (v) { draft.default_readiness_state = +v; }, __('Choose…', 'woo-etsy-sync'));
					return field(__('Processing time', 'woo-etsy-sync'), h('div', { class: 'wes-input-group' }, readinessSel, h('button', { class: 'wes-btn', type: 'button', onclick: function () {
						createReadiness(function (id, list) { shop.readiness = list; draft.default_readiness_state = id; var n = readinessField(); rf.replaceWith(n); rf = n; });
					} }, __('+ New', 'woo-etsy-sync'))), __('How long you take to dispatch (Etsy calls these “processing profiles”).', 'woo-etsy-sync'));
				}
				var rf = readinessField();
				var finish = h('button', { class: 'wes-btn wes-btn--primary', onclick: function () {
					if (!draft.default_taxonomy_id || !draft.default_shipping_profile || !draft.default_readiness_state) {
						toast(__('Please choose a category, shipping profile and processing time', 'woo-etsy-sync'), 'error'); return;
					}
					busy(finish, true, __('Saving…', 'woo-etsy-sync'));
					draft.setup_complete = true;
					call('/settings', { method: 'POST', data: draft }).then(function (s) { state = s; step = 4; draw(); }).catch(function (e) { busy(finish, false); toast(e.message, 'error'); });
				} }, __('Save & continue', 'woo-etsy-sync'));
				mount(body, [
					!shop.shipping.length ? alertBox('warn', __('No shipping profiles found on Etsy', 'woo-etsy-sync'), __('Create one in <a href="https://www.etsy.com/your/shops/me/tools/shipping-profiles" target="_blank" rel="noopener">Etsy → Shipping settings</a>, then click refresh.', 'woo-etsy-sync')) : null,
					field(__('Default Etsy category', 'woo-etsy-sync'), taxonomyPicker(draft.default_taxonomy_id ? { id: draft.default_taxonomy_id, label: draft.default_taxonomy_label } : null, function (v) { draft.default_taxonomy_id = v ? v.id : 0; draft.default_taxonomy_label = v ? v.label : ''; }), __('Used when a product’s category isn’t mapped. You can map WooCommerce categories later.', 'woo-etsy-sync')),
					h('div', { class: 'wes-row' },
						field(__('Shipping profile', 'woo-etsy-sync'), h('div', { class: 'wes-input-group' }, select(shop.shipping, draft.default_shipping_profile, function (v) { draft.default_shipping_profile = +v; }, __('Choose…', 'woo-etsy-sync')), h('button', { class: 'wes-btn', title: __('Refresh from Etsy', 'woo-etsy-sync'), onclick: function () { cache.shop = null; draw(); } }, icon('refresh')))),
						rf
					),
					h('div', { class: 'wes-row' },
						field(__('Who made your products?', 'woo-etsy-sync'), select([{ id: 'i_did', label: __('I did', 'woo-etsy-sync') }, { id: 'collective', label: __('A member of my shop', 'woo-etsy-sync') }, { id: 'someone_else', label: __('Another company or person', 'woo-etsy-sync') }], draft.who_made, function (v) { draft.who_made = v; })),
						field(__('When were they made?', 'woo-etsy-sync'), select(state.when_made.map(function (w) { return { id: w, label: w === 'made_to_order' ? __('Made to order', 'woo-etsy-sync') : w.replace(/_/g, '–').replace('before–', 'Before ') }; }), draft.when_made, function (v) { draft.when_made = v; }))
					),
					field(__('New listings should be…', 'woo-etsy-sync'), choiceCards([
						{ value: 'draft', label: __('Drafts (recommended)', 'woo-etsy-sync'), desc: __('Review on Etsy, then publish. No listing fees until you publish.', 'woo-etsy-sync') },
						{ value: 'active', label: __('Published straight away', 'woo-etsy-sync'), desc: __('Goes live immediately. Etsy charges its normal listing fee.', 'woo-etsy-sync') }
					], draft.listing_state, function (v) { draft.listing_state = v; })),
					h('div', { class: 'wes-divider' }),
					nav(false, finish)
				]);
			}).catch(function (e) { mount(body, alertBox('error', __('Couldn’t load your shop details', 'woo-etsy-sync'), esc(e.message))); });
		}

		function stepDone(b) {
			append(b, [
				h('div', { class: 'wes-hero' },
					h('div', { class: 'wes-hero__art' }, h('div', { class: 'wes-hero__tile', style: 'background:linear-gradient(135deg,#22c55e,#15803d)' }, icon('check'))),
					h('h1', { text: __('You’re all set!', 'woo-etsy-sync') }),
					h('p', { text: __('What would you like to do first?', 'woo-etsy-sync') })
				),
				h('div', { class: 'wes-quick', style: 'margin-top:22px' },
					quickBtn('link', __('Link my existing Etsy listings', 'woo-etsy-sync'), __('Already selling on Etsy? Match listings to products by SKU so stock stays in sync.', 'woo-etsy-sync'), function () { go('link'); }),
					quickBtn('upload', __('Put products on Etsy', 'woo-etsy-sync'), __('Choose products and send them to Etsy as new listings.', 'woo-etsy-sync'), function () { go('products'); }),
					quickBtn('home', __('Go to the dashboard', 'woo-etsy-sync'), __('See everything at a glance.', 'woo-etsy-sync'), function () { go('dashboard'); })
				)
			]);
		}

		draw();
	}

	function copyRow(text) {
		var btn = h('button', { class: 'wes-btn wes-btn--sm', type: 'button', onclick: function () {
			(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast(__('Copied', 'woo-etsy-sync')); }).catch(function () {
				var ta = h('textarea', { value: text }); document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); toast(__('Copied', 'woo-etsy-sync')); } catch (e) { /* noop */ } ta.remove();
			});
		} }, __('Copy', 'woo-etsy-sync'));
		return h('div', { class: 'wes-copy' }, h('code', { text: text }), btn);
	}

	function quickBtn(ic, title, desc, onClick, disabled) {
		return h('button', { type: 'button', onclick: onClick, disabled: disabled }, h('span', { class: 'wes-quick__icon' }, icon(ic)), h('span', { style: 'flex:1' }, h('strong', { text: title }), h('span', { text: desc })), h('span', { class: 'wes-muted' }, icon('arrow')));
	}

	function createReadiness(done) {
		var d = { readiness_state: 'ready_to_ship', min: 1, max: 3, unit: 'days' };
		var body = h('div', null,
			field(__('Type', 'woo-etsy-sync'), select([{ id: 'ready_to_ship', label: __('Ready to ship', 'woo-etsy-sync') }, { id: 'made_to_order', label: __('Made to order', 'woo-etsy-sync') }], d.readiness_state, function (v) { d.readiness_state = v; })),
			h('div', { class: 'wes-row--3 wes-row' },
				field(__('From', 'woo-etsy-sync'), h('input', { type: 'number', min: 1, value: d.min, oninput: function (e) { d.min = +e.target.value; } })),
				field(__('To', 'woo-etsy-sync'), h('input', { type: 'number', min: 1, value: d.max, oninput: function (e) { d.max = +e.target.value; } })),
				field(__('Unit', 'woo-etsy-sync'), select([{ id: 'days', label: __('Business days', 'woo-etsy-sync') }, { id: 'weeks', label: __('Weeks', 'woo-etsy-sync') }], d.unit, function (v) { d.unit = v; }))
			)
		);
		modal({
			title: __('New processing profile', 'woo-etsy-sync'), body: body, actions: [
				{ label: __('Cancel', 'woo-etsy-sync') },
				{ label: __('Create on Etsy', 'woo-etsy-sync'), primary: true, onClick: function (close) {
					call('/readiness', { method: 'POST', data: d }).then(function (r) {
						cache.shop = cache.shop || {}; cache.shop.readiness = r.readiness;
						toast(__('Processing profile created', 'woo-etsy-sync')); close(); done(r.id, r.readiness);
					}).catch(function (e) { toast(e.message, 'error'); });
					return false;
				} }
			]
		});
	}

	/* ------------------------------------------------------------------
	 * Dashboard.
	 * ---------------------------------------------------------------- */

	function viewDashboard(el) {
		var s = state.stats;
		var pct = s.products ? Math.round((s.linked / s.products) * 100) : 0;
		var last = state.last_poll;
		var feed = h('ul', { class: 'wes-feed' }, h('li', null, h('div', { class: 'wes-skel', style: 'width:70%' })));

		var alerts = [];
		if (BOOT.flash && BOOT.flash.connected) { alerts.push(alertBox('ok', __('Connected to Etsy', 'woo-etsy-sync'), __('Your shop <strong>', 'woo-etsy-sync') + esc(state.shop.name) + '</strong> is connected.')); BOOT.flash.connected = false; }
		if (!state.connected) {
			alerts.push(alertBox('warn', __('Your Etsy shop isn’t connected', 'woo-etsy-sync'), __('Nothing will sync until you connect.', 'woo-etsy-sync'), h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function () { go(state.settings.setup_complete ? 'settings/connection' : 'setup'); } }, __('Connect now', 'woo-etsy-sync'))));
		} else if (state.needs_reconnect) {
			alerts.push(alertBox('error', __('Etsy needs you to reconnect', 'woo-etsy-sync'), __('Your saved Etsy login stopped working (Etsy logins last 90 days without use, or access was revoked).', 'woo-etsy-sync'), h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function () { go('settings/connection'); } }, __('Reconnect', 'woo-etsy-sync'))));
		}

		if (state.connected && state.platy && !state.platy.done) {
			alerts.push(alertBox('info', __('Coming from Platy Syncer?', 'woo-etsy-sync'), __('We found ', 'woo-etsy-sync') + state.platy.links + __(' linked products from the old plugin. You can import them in one click.', 'woo-etsy-sync'), h('a', { class: 'wes-btn wes-btn--primary wes-btn--sm', href: '#/link' }, __('Review & import', 'woo-etsy-sync'))));
		}

		var checks = [
			[state.connected && !state.needs_reconnect, __('Etsy shop connected', 'woo-etsy-sync'), state.connected ? (state.shop.name + (state.shop.currency ? ' · ' + state.shop.currency : '')) : __('Connect under Settings → Connection', 'woo-etsy-sync'), 'settings/connection'],
			[!!state.settings.default_taxonomy_id, __('Default Etsy category', 'woo-etsy-sync'), state.settings.default_taxonomy_label || __('Required to create listings', 'woo-etsy-sync'), 'settings/listing'],
			[!!state.settings.default_shipping_profile && !!state.settings.default_readiness_state, __('Shipping & processing defaults', 'woo-etsy-sync'), __('Required to create listings', 'woo-etsy-sync'), 'settings/shipping'],
			[!state.shop.currency || state.shop.currency === state.store_currency, __('Currencies match', 'woo-etsy-sync'), state.shop.currency && state.shop.currency !== state.store_currency ? (__('Etsy uses ', 'woo-etsy-sync') + state.shop.currency + __(' but your store uses ', 'woo-etsy-sync') + state.store_currency + '. Use a price adjustment to convert.') : __('Prices are sent as-is', 'woo-etsy-sync'), 'settings/pricing']
		];

		append(el, [
			pageHead(__('Dashboard', 'woo-etsy-sync'), state.connected ? __('Everything between your store and <strong>', 'woo-etsy-sync') + esc(state.shop.name) + '</strong> on Etsy.' : __('Connect Etsy to get started.', 'woo-etsy-sync')),
			alerts,
			progressBanner(),
			h('div', { class: 'wes-grid wes-grid--4', style: 'margin-bottom:16px' },
				statTile(__('On Etsy', 'woo-etsy-sync'), h('span', null, s.linked, h('small', { text: ' / ' + s.products })), pct + __('% of published products', 'woo-etsy-sync'), pct),
				statTile(__('Need attention', 'woo-etsy-sync'), s.errors, s.errors ? h('a', { href: '#/products/error' }, __('View errors →', 'woo-etsy-sync')) : __('No sync errors', 'woo-etsy-sync'), null, s.errors ? 'wes-dot--err' : 'wes-dot--ok'),
				statTile(__('Last 24 hours', 'woo-etsy-sync'), (s.log24h.success || 0), __('successful syncs & stock updates', 'woo-etsy-sync')),
				statTile(__('Etsy orders checked', 'woo-etsy-sync'), last && last.at ? ago(last.at) : '—', state.settings.sync_stock_from_etsy ? ('every ' + state.settings.poll_minutes + ' min') : __('Etsy → Woo stock is off', 'woo-etsy-sync'))
			),
			h('div', { class: 'wes-grid wes-grid--main' },
				h('div', { class: 'wes-stack' },
					h('div', { class: 'wes-card' },
						h('div', { class: 'wes-card__head' }, h('h2', { text: __('Recent activity', 'woo-etsy-sync') }), h('div', { class: 'wes-card__head-actions' }, h('a', { href: '#/activity', class: 'wes-btn wes-btn--ghost wes-btn--sm' }, __('View all', 'woo-etsy-sync')))),
						h('div', { class: 'wes-card__body--flush' }, feed)
					)
				),
				h('div', { class: 'wes-stack' },
					h('div', { class: 'wes-card' },
						h('div', { class: 'wes-card__head' }, h('h2', { text: __('Quick actions', 'woo-etsy-sync') })),
						h('div', { class: 'wes-card__body' }, h('div', { class: 'wes-quick' },
							quickBtn('upload', __('Sync products to Etsy', 'woo-etsy-sync'), __('Choose which products to list or update.', 'woo-etsy-sync'), function () { go('products'); }, !state.connected),
							quickBtn('link', __('Link existing listings', 'woo-etsy-sync'), __('Match Etsy listings to products by SKU.', 'woo-etsy-sync'), function () { go('link'); }, !state.connected),
							actionBtn('stock', __('Send all stock to Etsy', 'woo-etsy-sync'), __('Push WooCommerce stock to every linked listing.', 'woo-etsy-sync'), '/stock/push-all', function (r) { toast(__('Queued stock updates for ', 'woo-etsy-sync') + r.queued + ' products'); state.stats.pending = r.pending; renderChrome(); scheduleTick(500); }, !state.connected),
							actionBtn('cart', __('Check Etsy orders now', 'woo-etsy-sync'), __('Deduct new Etsy sales from WooCommerce stock.', 'woo-etsy-sync'), '/stock/poll-now', function (r) { toast(r.summary.transactions ? (r.summary.adjusted + __(' stock adjustment(s) from ', 'woo-etsy-sync') + r.summary.transactions + __(' new sale(s)', 'woo-etsy-sync')) : __('No new Etsy sales', 'woo-etsy-sync')); state.last_poll = r.last_poll; render(); }, !state.connected || !state.settings.sync_stock_from_etsy)
						))
					),
					h('div', { class: 'wes-card' },
						h('div', { class: 'wes-card__head' }, h('h2', { text: __('Health check', 'woo-etsy-sync') })),
						h('div', { class: 'wes-card__body', style: 'padding-top:6px;padding-bottom:6px' }, h('ul', { class: 'wes-checklist' }, checks.map(function (c) {
							return h('li', null, h('span', { class: 'wes-checklist__icon wes-checklist__icon--' + (c[0] ? 'ok' : 'todo') }, icon(c[0] ? 'check' : 'alert')),
								h('div', { class: 'wes-checklist__text' }, h('strong', { text: c[1] }), h('span', { text: c[2] })),
								c[0] ? null : h('a', { href: '#/' + c[3], class: 'wes-btn wes-btn--sm' }, __('Fix', 'woo-etsy-sync')));
						})))
					)
				)
			),
			h('p', { class: 'wes-footer', text: __('Marketplace Sync v', 'woo-etsy-sync') + BOOT.version })
		]);

		function loadFeed() {
			call('/log?page=1').then(function (r) {
				var rows = r.rows.slice(0, 8);
				mount(feed, rows.length ? rows.map(feedItem) : h('li', null, h('div', { class: 'wes-empty', style: 'padding:24px;width:100%' }, h('strong', { text: __('Nothing yet', 'woo-etsy-sync') }), __('Activity will show up here as things sync.', 'woo-etsy-sync'))));
			}).catch(function () { mount(feed, h('li', null, __('Could not load activity.', 'woo-etsy-sync'))); });
		}
		loadFeed();
		return onEvent('wes:idle', function () { if (route() === 'dashboard') { render(); } });
	}

	function statTile(label, value, sub, meter, dot) {
		return h('div', { class: 'wes-card wes-stat' },
			h('div', { class: 'wes-stat__label' }, dot ? h('span', { class: 'wes-dot ' + dot }) : null, label),
			h('div', { class: 'wes-stat__value' }, value),
			h('div', { class: 'wes-stat__sub' }, sub),
			meter !== null && meter !== undefined ? h('div', { class: 'wes-meter' }, h('span', { style: 'width:' + meter + '%' })) : null
		);
	}

	function actionBtn(ic, title, desc, path, onDone, disabled) {
		var b = quickBtn(ic, title, desc, function () {
			b.disabled = true;
			var iconEl = b.querySelector('.wes-quick__icon');
			var old = iconEl.innerHTML;
			iconEl.innerHTML = '<span class="wes-spinner" style="width:16px;height:16px;border-width:2px"></span>';
			call(path, { method: 'POST' }).then(onDone).catch(function (e) { toast(e.message, 'error'); }).finally(function () { b.disabled = false; iconEl.innerHTML = old; });
		}, disabled);
		return b;
	}

	function feedItem(row) {
		return h('li', null, h('span', { class: 'wes-feed__dot lvl-' + row.level }),
			h('div', { class: 'wes-feed__body' },
				h('div', { class: 'wes-feed__msg' }, row.product ? h('strong', { text: row.product + ': ' }) : null, row.message),
				h('div', { class: 'wes-feed__meta', title: fullDate(row.created_at) }, ago(row.created_at), row.listing_id ? [' · ', h('a', { href: 'https://www.etsy.com/listing/' + row.listing_id, target: '_blank', rel: 'noopener' }, __('Etsy listing', 'woo-etsy-sync'))] : null)
			));
	}

	function progressBanner() {
		var p = state.stats.pending || {};
		var total = (p.sync || 0) + (p.stock || 0);
		if (!total) { return null; }
		scheduleTick(800);
		var parts = [];
		if (p.sync) { parts.push(p.sync + ' product' + (p.sync === 1 ? '' : 's') + ' to sync'); }
		if (p.stock) { parts.push(p.stock + __(' stock update', 'woo-etsy-sync') + (p.stock === 1 ? '' : 's')); }
		return h('div', { class: 'wes-progress' }, h('span', { class: 'wes-spinner' }), h('div', { class: 'wes-progress__text' }, h('strong', { text: __('Syncing with Etsy…', 'woo-etsy-sync') }), h('span', { text: parts.join(' · ') + __(' remaining. You can leave this page — it carries on in the background.', 'woo-etsy-sync') })));
	}

	/* ------------------------------------------------------------------
	 * Products.
	 * ---------------------------------------------------------------- */

	function viewProducts(el, args) {
		var q = { page: 1, search: '', status: args[0] || '', per_page: 20 };
		var selected = {};
		var data = { rows: [], total: 0, pages: 0 };
		var loading = true;

		var banner = h('div');
		var card = h('div', { class: 'wes-card' });
		append(el, [
			pageHead(__('Products', 'woo-etsy-sync'), __('Send products to Etsy, update them, or check what went wrong.', 'woo-etsy-sync'), [
				h('a', { class: 'wes-btn', href: BOOT.productsUrl }, __('Open WooCommerce products', 'woo-etsy-sync')),
			]),
			!state.connected ? alertBox('warn', __('Connect Etsy first', 'woo-etsy-sync'), __('You can browse products, but syncing needs a connected shop.', 'woo-etsy-sync')) : null,
			banner, card
		]);

		var searchIn = h('input', { type: 'search', placeholder: __('Search name or SKU…', 'woo-etsy-sync'), value: q.search });
		var tmr;
		searchIn.addEventListener('input', function () { clearTimeout(tmr); tmr = setTimeout(function () { q.search = searchIn.value; q.page = 1; load(); }, 300); });

		function statusTabs() {
			var tabs = [['', __('All', 'woo-etsy-sync')], ['linked', __('On Etsy', 'woo-etsy-sync')], ['not_synced', __('Not on Etsy', 'woo-etsy-sync')], ['error', __('Errors', 'woo-etsy-sync')], ['excluded', __('Excluded', 'woo-etsy-sync')]];
			return h('div', { class: 'wes-seg' }, tabs.map(function (t) {
				return h('button', { class: q.status === t[0] ? 'is-active' : '', onclick: function () { q.status = t[0]; q.page = 1; selected = {}; history.replaceState(null, '', '#/products' + (t[0] ? '/' + t[0] : '')); load(); } }, t[1], t[0] === 'error' && state.stats.errors ? h('span', { class: 'wes-badge' }, state.stats.errors) : null);
			}));
		}

		function load() {
			loading = true; draw();
			var qs = '?page=' + q.page + '&per_page=' + q.per_page + '&status=' + encodeURIComponent(q.status) + '&search=' + encodeURIComponent(q.search);
			call('/products' + qs).then(function (r) { data = r; loading = false; draw(); }).catch(function (e) { loading = false; data = { rows: [], total: 0, pages: 0, error: e.message }; draw(); });
		}

		function selectedIds() { return Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number); }

		function queue(ids, all, btn) {
			busy(btn, true, __('Queuing…', 'woo-etsy-sync'));
			call('/products/sync', { method: 'POST', data: all ? { all: true, status: q.status, search: q.search } : { ids: ids } }).then(function (r) {
				toast(r.queued + ' product' + (r.queued === 1 ? '' : 's') + __(' queued for Etsy', 'woo-etsy-sync'));
				state.stats.pending = r.pending; selected = {};
				renderChrome(); drawBanner(); scheduleTick(300); load();
			}).catch(function (e) { toast(e.message, 'error'); busy(btn, false); });
		}

		function drawBanner() { mount(banner, progressBanner()); }

		function draw() {
			var ids = selectedIds();
			var allOnPage = data.rows.length && data.rows.every(function (r) { return selected[r.id]; });
			var head = h('thead', null, h('tr', null,
				h('th', { class: 'wes-check' }, h('input', { type: 'checkbox', 'aria-label': __('Select all', 'woo-etsy-sync'), checked: allOnPage, onchange: function (e) { data.rows.forEach(function (r) { selected[r.id] = e.target.checked; }); draw(); } })),
				h('th', null, __('Product', 'woo-etsy-sync')), h('th', null, __('Price', 'woo-etsy-sync')), h('th', null, __('Stock', 'woo-etsy-sync')), h('th', null, __('Etsy', 'woo-etsy-sync')), h('th', null, __('Last sync', 'woo-etsy-sync')), h('th', null, '')
			));
			var body = h('tbody');
			if (loading && !data.rows.length) {
				for (var i = 0; i < 6; i++) { body.appendChild(h('tr', null, h('td', { colspan: 7 }, h('div', { class: 'wes-skel', style: 'width:' + (40 + (i * 9) % 50) + '%' })))); }
			} else if (!data.rows.length) {
				body.appendChild(h('tr', null, h('td', { colspan: 7 }, h('div', { class: 'wes-empty' }, icon('box'), h('strong', { text: data.error ? __('Couldn’t load products', 'woo-etsy-sync') : __('No products here', 'woo-etsy-sync') }), data.error || (q.search ? __('Try a different search.', 'woo-etsy-sync') : __('Nothing matches this filter.', 'woo-etsy-sync'))))));
			}
			data.rows.forEach(function (r) {
				body.appendChild(h('tr', null,
					h('td', { class: 'wes-check' }, h('input', { type: 'checkbox', checked: !!selected[r.id], disabled: r.status === 'excluded', onchange: function (e) { selected[r.id] = e.target.checked; draw(); } })),
					h('td', null, h('div', { class: 'wes-prod' },
						h('span', { class: 'wes-prod__img', style: r.thumb ? 'background-image:url(' + JSON.stringify(r.thumb) + ')' : '' }),
						h('div', null,
							h('a', { class: 'wes-prod__name', href: r.edit_url, text: r.name }),
							h('div', { class: 'wes-prod__meta' }, [r.sku ? 'SKU ' + r.sku : __('No SKU', 'woo-etsy-sync'), r.type === 'variable' ? r.variations + ' variations' : r.type].join(' · ')),
							r.error ? h('div', { class: 'wes-err-text', text: r.error }) : null
						))),
					h('td', { class: 'wes-num' }, r.price),
					h('td', { class: 'wes-num' }, r.stock),
					h('td', null, pill(r.status, r.listing_state), r.listing_url ? h('a', { href: r.listing_url, target: '_blank', rel: 'noopener', title: __('Open on Etsy', 'woo-etsy-sync'), style: 'margin-left:6px;vertical-align:middle;display:inline-flex;color:var(--wes-text-3)' }, icon('external')) : null),
					h('td', { class: 'wes-muted wes-small', title: r.synced_at ? fullDate(r.synced_at) : '' }, r.synced_at ? ago(r.synced_at) : '—'),
					h('td', { class: 'wes-actions-cell' }, rowActions(r))
				));
			});

			var pager = data.pages > 1 ? h('div', { class: 'wes-pager' }, h('span', null, data.total + ' products'), h('span', { class: 'wes-pager__spacer' }),
				h('button', { class: 'wes-btn wes-btn--sm', disabled: q.page <= 1, onclick: function () { q.page--; load(); } }, __('← Prev', 'woo-etsy-sync')),
				h('span', null, __('Page ', 'woo-etsy-sync') + q.page + ' of ' + data.pages),
				h('button', { class: 'wes-btn wes-btn--sm', disabled: q.page >= data.pages, onclick: function () { q.page++; load(); } }, __('Next →', 'woo-etsy-sync'))
			) : (data.total ? h('div', { class: 'wes-pager' }, h('span', null, data.total + ' product' + (data.total === 1 ? '' : 's'))) : null);

			var syncSel = h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function () { queue(ids, false, syncSel); } }, icon('upload'), __('Sync ', 'woo-etsy-sync') + ids.length + ' selected');
			var syncAll = h('button', { class: 'wes-btn wes-btn--sm', disabled: !state.connected || !data.total, onclick: function () {
				confirmBox(__('Sync ', 'woo-etsy-sync') + data.total + __(' products?', 'woo-etsy-sync'), __('This will create or update Etsy listings for every published product in this view', 'woo-etsy-sync') + (q.search ? __(' matching “', 'woo-etsy-sync') + q.search + '”' : '') + '. Products set to “Don’t sync” are skipped.', __('Sync them', 'woo-etsy-sync')).then(function (ok) { if (ok) { queue([], true, syncAll); } });
			} }, icon('upload'), __('Sync all ', 'woo-etsy-sync') + (q.status || q.search ? 'in view' : ''));

			mount(card, [
				h('div', { class: 'wes-toolbar' }, statusTabs(), h('span', { class: 'wes-toolbar__spacer' }), searchIn, syncAll),
				ids.length ? h('div', { class: 'wes-bulkbar' }, ids.length + ' selected', syncSel,
					h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function () { selected = {}; draw(); } }, __('Clear', 'woo-etsy-sync'))) : null,
				h('div', { class: 'wes-table-wrap' }, h('table', { class: 'wes-table' }, head, body)),
				pager
			]);
			if (document.activeElement !== searchIn && q.search) { /* keep */ }
		}

		function rowActions(r) {
			var syncBtn = h('button', { class: 'wes-btn wes-btn--sm', disabled: !state.connected || r.status === 'excluded', title: r.listing_id ? __('Update the Etsy listing now', 'woo-etsy-sync') : __('Create an Etsy listing now', 'woo-etsy-sync'), onclick: function () {
				busy(syncBtn, true, r.listing_id ? __('Updating…', 'woo-etsy-sync') : __('Listing…', 'woo-etsy-sync'));
				call('/products/' + r.id + '/sync-now', { method: 'POST' }).then(function (res) {
					toast(res.result.created ? __('Listed on Etsy', 'woo-etsy-sync') : __('Updated on Etsy', 'woo-etsy-sync'));
					replaceRow(res.row);
				}).catch(function (e) { toast(e.message, 'error'); load(); });
			} }, r.listing_id ? __('Update', 'woo-etsy-sync') : __('List on Etsy', 'woo-etsy-sync'));
			var more = h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', title: __('More', 'woo-etsy-sync'), onclick: function () { moreMenu(r); } }, '•••');
			return h('span', { class: 'wes-inline', style: 'justify-content:flex-end' }, syncBtn, more);
		}

		function replaceRow(row) {
			data.rows = data.rows.map(function (r) { return r.id === row.id ? row : r; });
			draw();
		}

		function moreMenu(r) {
			var actions = [];
			var body = h('div', { class: 'wes-quick' });
			actions.push(quickBtn('eye', __('Preview what will be sent', 'woo-etsy-sync'), __('See the title, price, tags and variations Etsy will get.', 'woo-etsy-sync'), function () { m.close(); previewModal(r.id); }));
			if (r.listing_url) { actions.push(quickBtn('external', __('Open on Etsy', 'woo-etsy-sync'), __('View the listing on etsy.com.', 'woo-etsy-sync'), function () { window.open(r.listing_url, '_blank'); })); }
			if (r.listing_id && r.listing_state !== 'active') { actions.push(quickBtn('upload', __('Publish on Etsy', 'woo-etsy-sync'), __('Make the listing live (Etsy may charge a listing fee).', 'woo-etsy-sync'), function () { m.close(); setState(r, 'active'); })); }
			if (r.listing_id && r.listing_state === 'active') { actions.push(quickBtn('eye', __('Deactivate on Etsy', 'woo-etsy-sync'), __('Hide the listing on Etsy without deleting it.', 'woo-etsy-sync'), function () { m.close(); setState(r, 'inactive'); })); }
			if (!r.listing_id) { actions.push(quickBtn('link', __('Link to an existing Etsy listing', 'woo-etsy-sync'), __('Already on Etsy? Paste its link.', 'woo-etsy-sync'), function () { m.close(); linkModal(r); })); }
			if (r.listing_id) { actions.push(quickBtn('link', __('Unlink from Etsy', 'woo-etsy-sync'), __('Stop syncing this product. The Etsy listing is kept.', 'woo-etsy-sync'), function () { m.close(); unlink(r, false); })); }
			if (r.listing_id) { actions.push(quickBtn('trash', __('Delete the Etsy listing', 'woo-etsy-sync'), __('Permanently delete the listing on Etsy and unlink.', 'woo-etsy-sync'), function () { m.close(); unlink(r, true); })); }
			actions.push(quickBtn(r.status === 'excluded' ? 'check' : 'x', r.status === 'excluded' ? __('Allow syncing to Etsy', 'woo-etsy-sync') : __('Never sync this product', 'woo-etsy-sync'), r.status === 'excluded' ? __('Include this product again.', 'woo-etsy-sync') : __('Skip it in bulk syncs and stock updates.', 'woo-etsy-sync'), function () {
				m.close(); call('/products/' + r.id + '/exclude', { method: 'POST', data: { exclude: r.status !== 'excluded' } }).then(replaceRow).catch(function (e) { toast(e.message, 'error'); });
			}));
			actions.push(quickBtn('pen', __('Edit product & Etsy overrides', 'woo-etsy-sync'), __('Open the WooCommerce editor (see the “Etsy” tab).', 'woo-etsy-sync'), function () { location.href = r.edit_url; }));
			append(body, actions);
			var m = modal({ title: r.name, body: body, actions: [{ label: __('Close', 'woo-etsy-sync') }] });
		}

		function setState(r, st) {
			call('/products/' + r.id + '/state', { method: 'POST', data: { state: st } }).then(function (row) { toast(st === 'active' ? __('Published on Etsy', 'woo-etsy-sync') : __('Deactivated on Etsy', 'woo-etsy-sync')); replaceRow(row); }).catch(function (e) { toast(e.message, 'error'); });
		}

		function unlink(r, del) {
			confirmBox(del ? __('Delete this Etsy listing?', 'woo-etsy-sync') : __('Unlink from Etsy?', 'woo-etsy-sync'), del ? __('The listing will be permanently deleted from Etsy. This can’t be undone.', 'woo-etsy-sync') : __('The product stops syncing. The Etsy listing stays as it is.', 'woo-etsy-sync'), del ? __('Delete listing', 'woo-etsy-sync') : __('Unlink', 'woo-etsy-sync'), del).then(function (ok) {
				if (!ok) { return; }
				call('/products/' + r.id + '/unlink', { method: 'POST', data: { delete_listing: del } }).then(function (row) { toast(del ? __('Listing deleted', 'woo-etsy-sync') : __('Unlinked', 'woo-etsy-sync')); replaceRow(row); }).catch(function (e) { toast(e.message, 'error'); });
			});
		}

		function linkModal(r) {
			var input = h('input', { type: 'text', placeholder: 'https://www.etsy.com/listing/1234567890/…' });
			modal({
				title: __('Link to an Etsy listing', 'woo-etsy-sync'), body: h('div', null, field(__('Etsy listing link or number', 'woo-etsy-sync'), input, __('Stock will then sync between this product and that listing.', 'woo-etsy-sync'))),
				actions: [{ label: __('Cancel', 'woo-etsy-sync') }, { label: __('Link', 'woo-etsy-sync'), primary: true, onClick: function (close) {
					call('/products/' + r.id + '/link', { method: 'POST', data: { listing: input.value } }).then(function (row) { toast(__('Linked', 'woo-etsy-sync')); replaceRow(row); close(); }).catch(function (e) { toast(e.message, 'error'); });
					return false;
				} }]
			});
			setTimeout(function () { input.focus(); }, 50);
		}

		var offTick = onEvent('wes:tick', function () { drawBanner(); });
		var offIdle = onEvent('wes:idle', function () { drawBanner(); load(); });
		drawBanner();
		load();
		return function () { offTick(); offIdle(); };
	}

	function previewModal(id) {
		var body = h('div', null, h('span', { class: 'wes-spinner' }));
		modal({ title: __('Preview for Etsy', 'woo-etsy-sync'), icon: 'eye', body: body, actions: [{ label: __('Close', 'woo-etsy-sync'), primary: true }] });
		call('/products/' + id + '/preview').then(function (p) {
			var f = p.fields || {};
			var inv = p.inventory || { products: [] };
			var variants = inv.products.filter(function (x) { return x.property_values && x.property_values.length; });
			var dl = h('dl', { class: 'wes-kv' });
			function row(k, v) { if (v === undefined || v === null || v === '') { return; } append(dl, [h('dt', { text: k }), h('dd', null, v)]); }
			row(__('Title', 'woo-etsy-sync'), f.title);
			row(__('Price', 'woo-etsy-sync'), f.price !== undefined ? String(f.price) + (state.shop.currency ? ' ' + state.shop.currency : '') + (variants.length ? __(' (lowest)', 'woo-etsy-sync') : '') : '');
			row(__('Quantity', 'woo-etsy-sync'), f.quantity !== undefined ? String(f.quantity) : '');
			row(__('Tags', 'woo-etsy-sync'), (f.tags || []).length ? (f.tags || []).join(', ') : 'none');
			row(__('Materials', 'woo-etsy-sync'), (f.materials || []).join(', '));
			row(__('Photos', 'woo-etsy-sync'), String(p.images || 0));
			row(__('Category id', 'woo-etsy-sync'), f.taxonomy_id ? String(f.taxonomy_id) : 'missing');
			row(__('Who / when', 'woo-etsy-sync'), (f.who_made || '') + ' · ' + (f.when_made || ''));
			row(__('Description', 'woo-etsy-sync'), f.description ? h('div', { class: 'wes-desc-preview', text: f.description }) : '');
			mount(body, [
				p.problems && p.problems.length ? alertBox('warn', __('This product can’t sync yet', 'woo-etsy-sync'), p.problems.map(esc).join('<br>')) : alertBox('ok', __('Ready to sync', 'woo-etsy-sync'), p.listing_id ? __('This will update listing ', 'woo-etsy-sync') + p.listing_id + '.' : __('This will create a new ', 'woo-etsy-sync') + (state.settings.listing_state === 'active' ? __('live listing', 'woo-etsy-sync') : __('draft listing', 'woo-etsy-sync')) + '.'),
				dl,
				variants.length ? h('div', { style: 'margin-top:16px' }, h('div', { class: 'wes-section-title', text: variants.length + ' variations' }),
					h('div', { class: 'wes-table-wrap', style: 'max-height:220px;overflow:auto;border:1px solid var(--wes-border);border-radius:8px' }, h('table', { class: 'wes-table' },
						h('thead', null, h('tr', null, h('th', null, __('Options', 'woo-etsy-sync')), h('th', null, 'SKU'), h('th', null, __('Price', 'woo-etsy-sync')), h('th', null, __('Qty', 'woo-etsy-sync')))),
						h('tbody', null, variants.map(function (v) {
							var o = v.offerings[0];
							return h('tr', { style: o.is_enabled ? '' : 'opacity:.5' }, h('td', null, v.property_values.map(function (pv) { return pv.property_name + ': ' + pv.values[0]; }).join(' · ')), h('td', null, v.sku || '—'), h('td', { class: 'wes-num' }, String(o.price)), h('td', { class: 'wes-num' }, o.is_enabled ? String(o.quantity) : 'off'));
						}))
					))) : null
			]);
		}).catch(function (e) { mount(body, alertBox('error', __('Preview failed', 'woo-etsy-sync'), esc(e.message))); });
	}

	/* ------------------------------------------------------------------
	 * Link existing listings.
	 * ---------------------------------------------------------------- */

	function viewLink(el) {
		var matchTitles = true;
		var box = h('div');
		append(el, [
			pageHead(__('Link existing listings', 'woo-etsy-sync'), __('Already have listings on Etsy? Match them to your WooCommerce products so stock stays in sync. <strong>Nothing on Etsy is changed</strong> by this step.', 'woo-etsy-sync')),
			!state.connected ? alertBox('warn', __('Connect Etsy first', 'woo-etsy-sync'), '') : null,
			box
		]);

		function platyCard() {
			var pl = state.platy;
			if (!pl || pl.done || !state.connected) { return null; }
			var b = h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function () {
				busy(b, true, __('Importing…', 'woo-etsy-sync'));
				call('/migrate', { method: 'POST' }).then(function (res) {
					state = res.state; renderChrome();
					toast(__('Imported ', 'woo-etsy-sync') + res.result.linked + __(' product links and ', 'woo-etsy-sync') + res.result.mappings + ' mappings');
					draw();
				}).catch(function (e) { busy(b, false); toast(e.message, 'error'); });
			} }, __('Import from Platy Syncer', 'woo-etsy-sync'));
			return alertBox('info', __('We found your old Platy Syncer data', 'woo-etsy-sync'), __('It has <strong>', 'woo-etsy-sync') + pl.links + '</strong> product' + (pl.links === 1 ? '' : 's') + __(' linked to Etsy listings and <strong>', 'woo-etsy-sync') + pl.mappings + '</strong> category/shipping mapping' + (pl.mappings === 1 ? '' : 's') + '. Import them to carry on where you left off — nothing on Etsy is changed.', b);
		}

		function draw() {
			var r = state.link_report;
			var running = r && r.status === 'running';
			var startBtn = h('button', { class: 'wes-btn wes-btn--primary', disabled: !state.connected || running, onclick: function () {
				busy(startBtn, true, __('Starting…', 'woo-etsy-sync'));
				call('/link/start', { method: 'POST', data: { match_titles: matchTitles } }).then(function (rep) { state.link_report = rep; draw(); scheduleTick(300); }).catch(function (e) { toast(e.message, 'error'); busy(startBtn, false); });
			} }, icon('link'), r ? __('Scan again', 'woo-etsy-sync') : __('Find matches', 'woo-etsy-sync'));

			var intro = h('div', { class: 'wes-card' },
				h('div', { class: 'wes-card__body' },
					h('div', { class: 'wes-grid wes-grid--2' },
						h('div', null,
							h('div', { class: 'wes-section-title', text: __('How matching works', 'woo-etsy-sync') }),
							h('ul', { class: 'wes-checklist' },
								h('li', null, h('span', { class: 'wes-checklist__icon wes-checklist__icon--ok' }, icon('check')), h('div', { class: 'wes-checklist__text' }, h('strong', null, __('SKU match (best)', 'woo-etsy-sync')), h('span', null, __('An Etsy listing’s SKUs are looked up in WooCommerce — including variation SKUs.', 'woo-etsy-sync')))),
								h('li', null, h('span', { class: 'wes-checklist__icon wes-checklist__icon--ok' }, icon('check')), h('div', { class: 'wes-checklist__text' }, h('strong', null, __('Exact title match (optional)', 'woo-etsy-sync')), h('span', null, __('For listings with no SKU, an identical product name is used.', 'woo-etsy-sync'))))
							)
						),
						h('div', null,
							h('div', { class: 'wes-section-title', text: __('Options', 'woo-etsy-sync') }),
							toggle(matchTitles, 'Also match by exact title', 'Only when the title is identical and unique.', function (v) { matchTitles = v; }),
							h('div', { style: 'margin-top:14px' }, startBtn)
						)
					)
				)
			);

			var result = null;
			if (r) {
				var linked = r.linked || [], unmatched = r.unmatched || [], conflicts = r.conflicts || [];
				result = h('div', { class: 'wes-stack', style: 'margin-top:16px' },
					running ? h('div', { class: 'wes-progress' }, h('span', { class: 'wes-spinner' }), h('div', { class: 'wes-progress__text' }, h('strong', { text: __('Scanning your Etsy listings…', 'woo-etsy-sync') }), h('span', { text: r.scanned + ' checked so far' }))) : null,
					r.status === 'error' ? alertBox('error', __('Scan stopped', 'woo-etsy-sync'), esc(r.error)) : null,
					h('div', { class: 'wes-grid wes-grid--4' },
						statTile(__('Listings checked', 'woo-etsy-sync'), r.scanned, r.finished_at ? 'finished ' + ago(r.finished_at) : 'in progress'),
						statTile(__('Newly linked', 'woo-etsy-sync'), linked.length, __('stock will sync for these', 'woo-etsy-sync'), null, 'wes-dot--ok'),
						statTile(__('Already linked', 'woo-etsy-sync'), r.already || 0, 'skipped'),
						statTile(__('No match', 'woo-etsy-sync'), unmatched.length, conflicts.length ? conflicts.length + ' conflicts' : __('see list below', 'woo-etsy-sync'), null, unmatched.length ? 'wes-dot--warn' : 'wes-dot--ok')
					),
					linked.length && !running ? alertBox('info', __('Next step: line up the stock', 'woo-etsy-sync'), __('Your newly linked listings still show Etsy’s own quantities. Send WooCommerce stock to them now so both sides agree.', 'woo-etsy-sync'),
						h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function (e) { var b = e.currentTarget; busy(b, true, __('Queuing…', 'woo-etsy-sync')); call('/stock/push-all', { method: 'POST' }).then(function (res) { toast(__('Queued stock for ', 'woo-etsy-sync') + res.queued + ' products'); state.stats.pending = res.pending; renderChrome(); scheduleTick(300); busy(b, false); }).catch(function (er) { toast(er.message, 'error'); busy(b, false); }); } }, __('Send WooCommerce stock to Etsy', 'woo-etsy-sync'))) : null,
					listCard(__('Linked', 'woo-etsy-sync'), linked, function (x) { return [h('div', null, h('a', { href: BOOT.adminUrl + 'post.php?action=edit&post=' + x.product_id, text: x.product || (__('Product #', 'woo-etsy-sync') + x.product_id) }), h('div', { class: 'wes-muted wes-small', text: __('Etsy: ', 'woo-etsy-sync') + x.title })), h('span', { class: 'wes-pill wes-pill--ok' }, 'by ' + x.via), etsyLink(x.listing_id)]; }),
					listCard(__('Not matched', 'woo-etsy-sync'), unmatched, function (x) { return [h('span', { text: x.title }), h('span', { class: 'wes-muted wes-small', text: x.sku ? 'SKU ' + x.sku : __('no SKU', 'woo-etsy-sync') }), h('span', { class: 'wes-pill wes-pill--muted', text: x.state }), etsyLink(x.listing_id)]; }, __('Tip: give the WooCommerce product the same SKU as on Etsy, or link it by hand from the product’s “Etsy” tab.', 'woo-etsy-sync')),
					listCard(__('Conflicts', 'woo-etsy-sync'), conflicts, function (x) { return [h('span', { text: x.title }), h('span', { class: 'wes-muted wes-small', text: x.reason }), etsyLink(x.listing_id)]; })
				);
			}
			mount(box, [platyCard(), intro, result]);
		}

		function etsyLink(id) { return h('a', { href: 'https://www.etsy.com/listing/' + id, target: '_blank', rel: 'noopener', class: 'wes-btn wes-btn--ghost wes-btn--sm' }, __('Etsy', 'woo-etsy-sync'), icon('external')); }

		function listCard(title, items, cells, tip) {
			if (!items.length) { return null; }
			return h('div', { class: 'wes-card' },
				h('div', { class: 'wes-card__head' }, h('h3', null, title + ' ', h('span', { class: 'wes-badge wes-badge--muted' }, items.length)), tip ? h('p', { style: 'margin-left:auto', text: tip }) : null),
				h('div', { class: 'wes-table-wrap', style: 'max-height:360px;overflow:auto' }, h('table', { class: 'wes-table' }, h('tbody', null, items.map(function (x) {
					return h('tr', null, cells(x).map(function (c) { return h('td', null, c); }));
				}))))
			);
		}

		draw();
		if (state.link_report && state.link_report.status === 'running') { scheduleTick(300); }
		return onEvent('wes:tick', draw);
	}

	/* ------------------------------------------------------------------
	 * Settings.
	 * ---------------------------------------------------------------- */

	function viewSettings(el, args) {
		var sections = [
			['listing', __('Listing defaults', 'woo-etsy-sync'), 'file'],
			['categories', __('Category mapping', 'woo-etsy-sync'), 'layers'],
			['shipping', __('Shipping & processing', 'woo-etsy-sync'), 'truck'],
			['content', __('Titles, tags & photos', 'woo-etsy-sync'), 'tag'],
			['pricing', __('Pricing', 'woo-etsy-sync'), 'dollar'],
			['stock', __('Stock sync', 'woo-etsy-sync'), 'stock'],
			['behaviour', __('Automatic updates', 'woo-etsy-sync'), 'sync'],
			['connection', __('Connection', 'woo-etsy-sync'), 'plug']
		];
		var current = args[0] || 'listing';
		var draft = JSON.parse(JSON.stringify(state.settings));
		var dirty = false;
		var savebar = h('div', { class: 'wes-savebar' }, h('span', { text: __('You have unsaved changes', 'woo-etsy-sync') }),
			h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function () { draft = JSON.parse(JSON.stringify(state.settings)); setDirty(false); drawPanel(); } }, __('Discard', 'woo-etsy-sync')),
			h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: save }, __('Save changes', 'woo-etsy-sync')));
		var panel = h('div', { class: 'wes-stack' });
		var subnav = h('nav', { class: 'wes-subnav' });

		function setDirty(v) { dirty = v; savebar.classList.toggle('is-visible', v); }
		function set(k, v) { draft[k] = v; setDirty(true); }
		function save(e) {
			var b = e && e.currentTarget;
			busy(b, true, __('Saving…', 'woo-etsy-sync'));
			call('/settings', { method: 'POST', data: draft }).then(function (s) {
				state = s; draft = JSON.parse(JSON.stringify(state.settings)); setDirty(false); toast(__('Settings saved', 'woo-etsy-sync')); renderChrome(); busy(b, false);
			}).catch(function (er) { toast(er.message, 'error'); busy(b, false); });
		}

		function drawNav() {
			mount(subnav, sections.map(function (s) {
				return h('a', { href: '#/settings/' + s[0], class: current === s[0] ? 'is-active' : '', onclick: function (e) { e.preventDefault(); current = s[0]; history.replaceState(null, '', '#/settings/' + s[0]); drawNav(); drawPanel(); } }, icon(s[2]), s[1]);
			}));
		}

		function card(title, sub, children, headActions) {
			return h('div', { class: 'wes-card' },
				h('div', { class: 'wes-card__head' }, h('div', null, h('h2', { text: title }), sub ? h('p', { html: sub }) : null), headActions ? h('div', { class: 'wes-card__head-actions' }, headActions) : null),
				h('div', { class: 'wes-card__body' }, children));
		}

		function needsShop(fn) {
			if (!state.connected) { return alertBox('warn', __('Connect Etsy first', 'woo-etsy-sync'), __('These options come from your Etsy shop.', 'woo-etsy-sync'), h('a', { class: 'wes-btn wes-btn--sm', href: '#/settings/connection', onclick: function () { current = 'connection'; drawNav(); drawPanel(); } }, __('Go to Connection', 'woo-etsy-sync'))); }
			var holder = h('div', null, h('div', { class: 'wes-skel', style: 'width:50%' }));
			loadShopData(false).then(function (shop) { mount(holder, fn(shop)); }).catch(function (e) { mount(holder, alertBox('error', __('Couldn’t load from Etsy', 'woo-etsy-sync'), esc(e.message))); });
			return holder;
		}

		var P = {};
		P.listing = function () {
			return [
				card(__('Default category', 'woo-etsy-sync'), __('Etsy needs a category for every listing. This one is used unless a product or its WooCommerce category says otherwise.', 'woo-etsy-sync'),
					state.connected ? taxonomyPicker(draft.default_taxonomy_id ? { id: draft.default_taxonomy_id, label: draft.default_taxonomy_label } : null, function (v) { set('default_taxonomy_id', v ? v.id : 0); set('default_taxonomy_label', v ? v.label : ''); }) : needsShop()),
				card(__('About your items', 'woo-etsy-sync'), null, [
					h('div', { class: 'wes-row' },
						field(__('Who made them?', 'woo-etsy-sync'), select([{ id: 'i_did', label: __('I did', 'woo-etsy-sync') }, { id: 'collective', label: __('A member of my shop', 'woo-etsy-sync') }, { id: 'someone_else', label: __('Another company or person', 'woo-etsy-sync') }], draft.who_made, function (v) { set('who_made', v); })),
						field(__('When were they made?', 'woo-etsy-sync'), select(state.when_made.map(function (w) { return { id: w, label: w === 'made_to_order' ? __('Made to order', 'woo-etsy-sync') : w.replace(/_/g, '–').replace('before–', 'Before ') }; }), draft.when_made, function (v) { set('when_made', v); }))
					),
					toggle(draft.is_supply, 'These are craft supplies or tools', 'Etsy files supplies under a different heading from finished products.', function (v) { set('is_supply', v); })
				]),
				card(__('New listings', 'woo-etsy-sync'), __('What happens when a product is sent to Etsy for the first time.', 'woo-etsy-sync'), [
					choiceCards([
						{ value: 'draft', label: __('Create as drafts (recommended)', 'woo-etsy-sync'), desc: __('Check them on Etsy, then publish. No fee until published.', 'woo-etsy-sync') },
						{ value: 'active', label: __('Publish immediately', 'woo-etsy-sync'), desc: __('Goes live straight away (Etsy charges its normal listing fee).', 'woo-etsy-sync') }
					], draft.listing_state, function (v) { set('listing_state', v); })
				])
			];
		};
		P.categories = function () {
			var holder = h('div', null, h('div', { class: 'wes-skel', style: 'width:50%' }));
			Promise.all([loadTerms(), state.connected ? loadShopData(false) : Promise.resolve({ sections: [] })]).then(function (res) {
				var terms = res[0], shop = res[1];
				var cats = terms.categories.slice();
				// Order as a tree.
				var byParent = {};
				cats.forEach(function (c) { (byParent[c.parent] = byParent[c.parent] || []).push(c); });
				var ordered = [];
				(function walk(pid, depth) { (byParent[pid] || []).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (c) { c.depth = depth; ordered.push(c); walk(c.id, depth + 1); }); })(0, 0);
				var cm = draft.category_map && !Array.isArray(draft.category_map) ? draft.category_map : {};
				var sm = draft.section_map && !Array.isArray(draft.section_map) ? draft.section_map : {};
				draft.category_map = cm; draft.section_map = sm;
				if (!ordered.length) { mount(holder, h('div', { class: 'wes-empty' }, h('strong', { text: __('No WooCommerce categories yet', 'woo-etsy-sync') }))); return; }
				mount(holder, h('div', { class: 'wes-map' }, ordered.map(function (c) {
					return h('div', { class: 'wes-map__row' },
						h('div', { class: 'wes-map__from', style: 'padding-left:' + (c.depth * 16) + 'px' }, (c.depth ? '└ ' : '') + c.name, ' ', h('small', { text: '(' + c.count + ')' })),
						h('div', { class: 'wes-map__arrow' }, icon('arrow')),
						h('div', { class: 'wes-stack', style: 'gap:6px' },
							state.connected ? taxonomyPicker(cm[c.id] ? cm[c.id] : null, function (v) { if (v) { cm[c.id] = v; } else { delete cm[c.id]; } set('category_map', cm); }, c.depth ? __('Same as parent / default', 'woo-etsy-sync') : __('Use default category', 'woo-etsy-sync')) : null,
							shop.sections && shop.sections.length ? select(shop.sections, sm[c.id] || '', function (v) { if (+v) { sm[c.id] = +v; } else { delete sm[c.id]; } set('section_map', sm); }, __('No shop section', 'woo-etsy-sync')) : null
						)
					);
				})));
			}).catch(function (e) { mount(holder, alertBox('error', __('Couldn’t load categories', 'woo-etsy-sync'), esc(e.message))); });
			return [card(__('WooCommerce category → Etsy category', 'woo-etsy-sync'), __('Products use the most specific mapped category. Sub-categories inherit from their parent. Optionally put them in an Etsy shop section too.', 'woo-etsy-sync'), holder)];
		};
		P.shipping = function () {
			return [
				card(__('Shipping', 'woo-etsy-sync'), __('Etsy shipping profiles hold your postage prices. Create or edit them on Etsy.', 'woo-etsy-sync'), needsShop(function (shop) {
					return [
						field(__('Default shipping profile', 'woo-etsy-sync'), h('div', { class: 'wes-input-group' }, select(shop.shipping, draft.default_shipping_profile, function (v) { set('default_shipping_profile', +v); }, __('Choose…', 'woo-etsy-sync')),
							h('button', { class: 'wes-btn', title: __('Refresh from Etsy', 'woo-etsy-sync'), onclick: function () { loadShopData(true).then(drawPanel); } }, icon('refresh'))),
							__('Don’t see the right one? <a href="https://www.etsy.com/your/shops/me/tools/shipping-profiles" target="_blank" rel="noopener">Manage shipping profiles on Etsy</a>.', 'woo-etsy-sync')),
						shippingClassMap(shop)
					];
				}), null),
				card(__('Processing time', 'woo-etsy-sync'), __('How long you take before dispatching. Etsy calls these processing profiles.', 'woo-etsy-sync'), needsShop(function (shop) {
					var sel = select(shop.readiness, draft.default_readiness_state, function (v) { set('default_readiness_state', +v); }, __('Choose…', 'woo-etsy-sync'));
					return field(__('Default processing profile', 'woo-etsy-sync'), h('div', { class: 'wes-input-group' }, sel, h('button', { class: 'wes-btn', onclick: function () { createReadiness(function (id) { set('default_readiness_state', id); drawPanel(); }); } }, __('+ New', 'woo-etsy-sync'))));
				})),
				card(__('Returns', 'woo-etsy-sync'), null, needsShop(function (shop) {
					return field(__('Return policy', 'woo-etsy-sync'), select(shop.returns, draft.default_return_policy, function (v) { set('default_return_policy', +v); }, __('Etsy shop default', 'woo-etsy-sync')), __('Optional. Leave as default to use your shop’s standard policy.', 'woo-etsy-sync'));
				}))
			];
		};
		function shippingClassMap(shop) {
			var holder = h('div', { style: 'margin-top:8px' });
			loadTerms().then(function (t) {
				if (!t.shipping_classes.length) { mount(holder, h('p', { class: 'wes-help', text: __('Tip: if you use WooCommerce shipping classes, you can map each one to a different Etsy shipping profile here.', 'woo-etsy-sync') })); return; }
				var map = draft.shipping_class_map && !Array.isArray(draft.shipping_class_map) ? draft.shipping_class_map : {};
				draft.shipping_class_map = map;
				mount(holder, [h('div', { class: 'wes-section-title', style: 'margin-top:18px', text: __('By WooCommerce shipping class', 'woo-etsy-sync') }), h('div', { class: 'wes-map' }, t.shipping_classes.map(function (c) {
					return h('div', { class: 'wes-map__row' }, h('div', { class: 'wes-map__from' }, c.name), h('div', { class: 'wes-map__arrow' }, icon('arrow')),
						select(shop.shipping, map[c.id] || '', function (v) { if (+v) { map[c.id] = +v; } else { delete map[c.id]; } set('shipping_class_map', map); }, __('Use default profile', 'woo-etsy-sync')));
				}))]);
			});
			return holder;
		}
		P.content = function () {
			return [
				card(__('Titles', 'woo-etsy-sync'), null, [
					field(__('Title template', 'woo-etsy-sync'), h('input', { type: 'text', value: draft.title_template, oninput: function (e) { set('title_template', e.target.value); } }), __('Use <code>{title}</code>, <code>{sku}</code> and <code>{category}</code>. Example: <code>{title} | Handmade</code>. Etsy allows 140 characters.', 'woo-etsy-sync'))
				]),
				card(__('Description', 'woo-etsy-sync'), null, [
					field(__('Use this text', 'woo-etsy-sync'), choiceCards([
						{ value: 'description', label: __('Full description', 'woo-etsy-sync'), desc: __('The main product description.', 'woo-etsy-sync') },
						{ value: 'short', label: __('Short description', 'woo-etsy-sync'), desc: __('The summary shown near the price.', 'woo-etsy-sync') },
						{ value: 'both', label: __('Both', 'woo-etsy-sync'), desc: __('Short description, then full description.', 'woo-etsy-sync') }
					], draft.description_source, function (v) { set('description_source', v); })),
					field(__('Add to the end of every description', 'woo-etsy-sync'), h('textarea', { rows: 3, value: draft.description_footer, placeholder: __('e.g. Thanks for shopping with us! Free gift wrapping on request.', 'woo-etsy-sync'), oninput: function (e) { set('description_footer', e.target.value); } }), __('Formatting is converted to plain text, which is what Etsy shows.', 'woo-etsy-sync'))
				]),
				card(__('Tags & materials', 'woo-etsy-sync'), __('Etsy allows 13 tags of up to 20 characters. Extras are dropped automatically.', 'woo-etsy-sync'), [
					toggle(draft.use_product_tags, 'Use WooCommerce product tags', null, function (v) { set('use_product_tags', v); }),
					toggle(draft.use_categories_as_tags, 'Also use category names as tags', null, function (v) { set('use_categories_as_tags', v); }),
					h('div', { style: 'height:12px' }),
					h('div', { class: 'wes-row' },
						field(__('Tags to add to every listing', 'woo-etsy-sync'), h('input', { type: 'text', value: draft.extra_tags, placeholder: __('handmade, gift for her', 'woo-etsy-sync'), oninput: function (e) { set('extra_tags', e.target.value); } }), __('Comma separated.', 'woo-etsy-sync')),
						field(__('Default materials', 'woo-etsy-sync'), h('input', { type: 'text', value: draft.default_materials, placeholder: __('cotton, recycled paper', 'woo-etsy-sync'), oninput: function (e) { set('default_materials', e.target.value); } }), __('Comma separated. Products can override this.', 'woo-etsy-sync'))
					)
				]),
				card(__('Photos & details', 'woo-etsy-sync'), null, [
					field(__('Photos per listing', 'woo-etsy-sync'), h('div', { class: 'wes-input-affix', style: 'max-width:160px' }, h('input', { type: 'number', min: 1, max: 20, value: draft.max_images, oninput: function (e) { set('max_images', +e.target.value); } }), h('span', null, 'max 20')), __('Main image first, then the gallery.', 'woo-etsy-sync')),
					toggle(draft.sync_variation_images, 'Send variation photos', 'Shows the right photo on Etsy when a buyer picks an option (uses the first option, e.g. Colour).', function (v) { set('sync_variation_images', v); }),
					toggle(draft.send_dimensions, 'Send weight & dimensions', 'Uses the product’s shipping weight and size (' + esc(state.woo_units.weight) + ' / ' + esc(state.woo_units.dimension) + ').', function (v) { set('send_dimensions', v); })
				])
			];
		};
		P.pricing = function () {
			var example = h('div', { class: 'wes-alert wes-alert--info', style: 'margin:16px 0 0' });
			function updateExample() {
				var p = 20;
				if (draft.price_adjust_type === 'percent') { p = p * (1 + (+draft.price_adjust_value || 0) / 100); }
				if (draft.price_adjust_type === 'fixed') { p = p + (+draft.price_adjust_value || 0); }
				if (draft.price_rounding === '99') { p = Math.max(0.99, Math.ceil(p) - 0.01); }
				if (draft.price_rounding === 'whole') { p = Math.max(1, Math.round(p)); }
				mount(example, [icon('info'), h('div', null, __('Example: a product priced ', 'woo-etsy-sync'), h('b', null, '20.00'), __(' in WooCommerce will be ', 'woo-etsy-sync'), h('b', null, p.toFixed(2)), __(' on Etsy.', 'woo-etsy-sync'))]);
			}
			var valIn = h('input', { type: 'number', step: '0.01', value: draft.price_adjust_value, oninput: function (e) { set('price_adjust_value', e.target.value); updateExample(); } });
			var valWrap = h('div', { class: 'wes-input-affix' }, valIn, h('span', { text: draft.price_adjust_type === 'percent' ? '%' : (state.shop.currency || '') }));
			var r = [
				state.shop.currency && state.shop.currency !== state.store_currency ? alertBox('warn', __('Different currencies', 'woo-etsy-sync'), __('Your Etsy shop uses <strong>', 'woo-etsy-sync') + esc(state.shop.currency) + '</strong> but WooCommerce uses <strong>' + esc(state.store_currency) + '</strong>. Prices are sent as numbers, so use a percentage adjustment to convert (e.g. +10%).') : null,
				card(__('Price on Etsy', 'woo-etsy-sync'), __('Etsy fees are higher than your own shop’s, so many sellers add a little on top.', 'woo-etsy-sync'), [
					toggle(draft.use_sale_price, 'Use sale prices when a sale is running', 'Otherwise the regular price is always used.', function (v) { set('use_sale_price', v); }),
					h('div', { style: 'height:14px' }),
					h('div', { class: 'wes-row' },
						field(__('Adjust prices', 'woo-etsy-sync'), select([{ id: 'none', label: __('Same as WooCommerce', 'woo-etsy-sync') }, { id: 'percent', label: __('Add a percentage', 'woo-etsy-sync') }, { id: 'fixed', label: __('Add a fixed amount', 'woo-etsy-sync') }], draft.price_adjust_type, function (v) { set('price_adjust_type', v); drawPanel(); })),
						draft.price_adjust_type !== 'none' ? field(draft.price_adjust_type === 'percent' ? __('Percentage (use minus to lower)', 'woo-etsy-sync') : __('Amount (use minus to lower)', 'woo-etsy-sync'), valWrap) : h('div')
					),
					field(__('Round prices', 'woo-etsy-sync'), select([{ id: 'none', label: __('Don’t round', 'woo-etsy-sync') }, { id: '99', label: __('Up to .99 (e.g. 23.40 → 23.99)', 'woo-etsy-sync') }, { id: 'whole', label: __('To whole numbers', 'woo-etsy-sync') }], draft.price_rounding, function (v) { set('price_rounding', v); updateExample(); })),
					example
				])
			];
			updateExample();
			return r;
		};
		P.stock = function () {
			return [
				card(__('WooCommerce → Etsy', 'woo-etsy-sync'), null, [
					toggle(draft.sync_stock_to_etsy, 'Update Etsy when stock changes here', 'Website orders, refunds and manual edits update the Etsy quantity within a minute or two.', function (v) { set('sync_stock_to_etsy', v); }),
					toggle(draft.deactivate_out_of_stock, 'Deactivate Etsy listings that run out', 'They’re reactivated automatically when stock comes back (reactivating a sold-out listing can incur Etsy’s renewal fee).', function (v) { set('deactivate_out_of_stock', v); }),
					h('div', { style: 'height:12px' }),
					h('div', { class: 'wes-row' },
						field(__('Quantity for products without stock tracking', 'woo-etsy-sync'), h('input', { type: 'number', min: 1, max: 999, value: draft.untracked_stock_qty, oninput: function (e) { set('untracked_stock_qty', +e.target.value); } }), __('Used when “Manage stock” is off but the product is in stock.', 'woo-etsy-sync')),
						field(__('Never show more than', 'woo-etsy-sync'), h('input', { type: 'number', min: 1, max: 999, value: draft.max_qty, oninput: function (e) { set('max_qty', +e.target.value); } }), __('Etsy’s maximum is 999 per option.', 'woo-etsy-sync'))
					)
				]),
				card(__('Etsy → WooCommerce', 'woo-etsy-sync'), null, [
					toggle(draft.sync_stock_from_etsy, 'Reduce WooCommerce stock when something sells on Etsy', 'Your Etsy orders are checked regularly and the sold quantity is taken off WooCommerce stock.', function (v) { set('sync_stock_from_etsy', v); }),
					h('div', { style: 'height:12px' }),
					field(__('Check Etsy for new orders every', 'woo-etsy-sync'), select([{ id: 5, label: '5 minutes' }, { id: 10, label: '10 minutes' }, { id: 15, label: '15 minutes' }, { id: 30, label: '30 minutes' }, { id: 60, label: 'hour' }], draft.poll_minutes, function (v) { set('poll_minutes', +v); })),
					state.last_poll && state.last_poll.at ? h('p', { class: 'wes-help', text: __('Last checked ', 'woo-etsy-sync') + ago(state.last_poll.at) + '.' }) : null
				])
			];
		};
		P.behaviour = function () {
			var uf = draft.update_fields;
			function ufToggle(k, title, desc) { return toggle(uf[k], title, desc, function (v) { uf[k] = v; set('update_fields', uf); }); }
			return [
				card(__('When I edit a product', 'woo-etsy-sync'), null, [
					toggle(draft.auto_sync_on_save, 'Update its Etsy listing automatically', 'Saving a linked product in WooCommerce updates Etsy in the background.', function (v) { set('auto_sync_on_save', v); })
				]),
				card(__('What to update on existing listings', 'woo-etsy-sync'), __('Turn things off here if you edit them directly on Etsy and don’t want them overwritten.', 'woo-etsy-sync'), [
					ufToggle('title', __('Title', 'woo-etsy-sync')), ufToggle('description', __('Description', 'woo-etsy-sync')), ufToggle('tags', __('Tags & materials', 'woo-etsy-sync')),
					ufToggle('images', __('Photos', 'woo-etsy-sync'), __('Replaces Etsy photos with the product’s photos.', 'woo-etsy-sync')),
					ufToggle('price', __('Prices, variations & SKUs', 'woo-etsy-sync'), __('Also sends stock.', 'woo-etsy-sync')),
					ufToggle('stock', __('Stock', 'woo-etsy-sync'), __('Quantities only, keeping Etsy’s prices (used when prices are off).', 'woo-etsy-sync'))
				]),
				card(__('When a product is deleted', 'woo-etsy-sync'), null, [
					select([{ id: 'deactivate', label: __('Deactivate the Etsy listing', 'woo-etsy-sync') }, { id: 'nothing', label: __('Leave the Etsy listing alone', 'woo-etsy-sync') }], draft.on_trash, function (v) { set('on_trash', v); })
				])
			];
		};
		P.connection = function () {
			var out = [];
			if (BOOT.flash && BOOT.flash.error) { out.push(alertBox('error', __('Etsy connection failed', 'woo-etsy-sync'), esc(BOOT.flash.error))); BOOT.flash.error = ''; }
			if (state.connected) {
				var dis = h('button', { class: 'wes-btn wes-btn--danger', onclick: function () {
					confirmBox(__('Disconnect Etsy?', 'woo-etsy-sync'), __('Syncing stops until you connect again. Links between products and listings are kept.', 'woo-etsy-sync'), __('Disconnect', 'woo-etsy-sync'), true).then(function (ok) {
						if (!ok) { return; } call('/disconnect', { method: 'POST' }).then(function (s) { state = s; cache.shop = null; renderChrome(); drawPanel(); toast(__('Disconnected', 'woo-etsy-sync')); });
					});
				} }, __('Disconnect', 'woo-etsy-sync'));
				var rec = h('button', { class: 'wes-btn', onclick: function () { busy(rec, true, __('Opening Etsy…', 'woo-etsy-sync')); call('/connect', { method: 'POST' }).then(function (r) { location.href = r.url; }).catch(function (e) { busy(rec, false); toast(e.message, 'error'); }); } }, icon('refresh'), __('Reconnect', 'woo-etsy-sync'));
				out.push(card(__('Etsy shop', 'woo-etsy-sync'), null, [
					h('div', { class: 'wes-inline', style: 'gap:14px' },
						h('div', { class: 'wes-hero__tile wes-hero__tile--etsy', style: 'width:48px;height:48px;border-radius:12px;font-size:13px' }, __('Etsy', 'woo-etsy-sync')),
						h('div', { style: 'flex:1' }, h('div', { style: 'font-weight:650;font-size:16px' }, state.shop.name, ' ', state.needs_reconnect ? h('span', { class: 'wes-pill wes-pill--error' }, __('Needs reconnect', 'woo-etsy-sync')) : h('span', { class: 'wes-pill wes-pill--ok' }, __('Connected', 'woo-etsy-sync'))),
							h('div', { class: 'wes-muted wes-small' }, __('Shop #', 'woo-etsy-sync') + state.shop.id + (state.shop.currency ? ' · ' + state.shop.currency : '') + (state.shop.connected_at ? __(' · connected ', 'woo-etsy-sync') + ago(state.shop.connected_at) : ''))),
						state.shop.url ? h('a', { class: 'wes-btn', href: state.shop.url, target: '_blank', rel: 'noopener' }, __('View shop', 'woo-etsy-sync'), icon('external')) : null
					)
				], [rec, dis]));
			} else if (state.has_credentials) {
				var con = h('button', { class: 'wes-btn wes-btn--primary', onclick: function () { busy(con, true, __('Opening Etsy…', 'woo-etsy-sync')); call('/connect', { method: 'POST' }).then(function (r) { location.href = r.url; }).catch(function (e) { busy(con, false); toast(e.message, 'error'); }); } }, icon('plug'), __('Connect my Etsy shop', 'woo-etsy-sync'));
				out.push(card(__('Etsy shop', 'woo-etsy-sync'), __('Your app keys are saved. Now approve access on Etsy.', 'woo-etsy-sync'), con));
			}
			var keyIn = h('input', { type: 'text', value: state.keystring || '', autocomplete: 'off', spellcheck: 'false' });
			var secIn = h('input', { type: 'password', value: '', placeholder: state.has_secret ? __('•••••••• (saved — leave blank to keep)', 'woo-etsy-sync') : __('Paste your shared secret', 'woo-etsy-sync'), autocomplete: 'new-password' });
			var saveKeys = h('button', { class: 'wes-btn', onclick: function () {
				busy(saveKeys, true, __('Checking…', 'woo-etsy-sync'));
				call('/credentials', { method: 'POST', data: { keystring: keyIn.value, shared_secret: secIn.value } }).then(function (r) { state = r.state; toast(__('Keys saved and accepted by Etsy', 'woo-etsy-sync')); renderChrome(); drawPanel(); }).catch(function (e) { busy(saveKeys, false); toast(e.message, 'error'); });
			} }, __('Save & test keys', 'woo-etsy-sync'));
			out.push(card(__('Your Etsy app', 'woo-etsy-sync'), __('From <a href="https://www.etsy.com/developers/your-apps" target="_blank" rel="noopener">etsy.com/developers/your-apps</a>.', 'woo-etsy-sync'), [
				h('div', { class: 'wes-row' }, field(__('Keystring', 'woo-etsy-sync'), keyIn), field(__('Shared secret', 'woo-etsy-sync'), secIn)),
				field(__('Callback URL (add this to your Etsy app)', 'woo-etsy-sync'), copyRow(state.redirect_uri), !state.site_https ? '<strong style="color:var(--wes-err)">Etsy requires HTTPS.</strong> Switch your site to https:// first.' : null),
				saveKeys
			]));
			return out;
		};

		function drawPanel() {
			mount(panel, (P[current] || P.listing)());
		}

		append(el, [
			pageHead(__('Settings', 'woo-etsy-sync'), __('Changes apply to future syncs. Saved settings never change Etsy until a product is synced.', 'woo-etsy-sync')),
			h('div', { class: 'wes-settings' }, subnav, panel),
			savebar
		]);
		drawNav();
		drawPanel();
		var beforeUnload = function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
		window.addEventListener('beforeunload', beforeUnload);
		return function () { window.removeEventListener('beforeunload', beforeUnload); savebar.remove(); };
	}

	/* ------------------------------------------------------------------
	 * Activity log.
	 * ---------------------------------------------------------------- */

	function viewActivity(el, args, ch) {
		var q = { page: 1, level: '', search: '', channel: ch || 'etsy' };
		var card = h('div', { class: 'wes-card' });
		var data = null;
		var searchIn = h('input', { type: 'search', placeholder: __('Search messages…', 'woo-etsy-sync') });
		var t;
		searchIn.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { q.search = searchIn.value; q.page = 1; load(); }, 300); });
		var clearBtn = h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function () {
			confirmBox(__('Clear the activity log?', 'woo-etsy-sync'), __('All log entries will be removed. Syncing isn’t affected.', 'woo-etsy-sync'), __('Clear log', 'woo-etsy-sync'), true).then(function (ok) { if (ok) { call('/log', { method: 'DELETE' }).then(function () { toast(__('Log cleared', 'woo-etsy-sync')); load(); }); } });
		} }, icon('trash'), __('Clear log', 'woo-etsy-sync'));
		append(el, [pageHead(q.channel === 'ebay' ? __('eBay activity', 'woo-etsy-sync') : __('Etsy activity', 'woo-etsy-sync'), __('Everything that happened in the last 30 days.', 'woo-etsy-sync'), [clearBtn]), card]);

		function load() {
			call('/log?page=' + q.page + '&channel=' + q.channel + '&level=' + q.level + '&search=' + encodeURIComponent(q.search)).then(function (r) { data = r; draw(); }).catch(function (e) { data = { rows: [], total: 0, pages: 0 }; draw(); toast(e.message, 'error'); });
		}
		function draw() {
			var levels = [['', __('All', 'woo-etsy-sync')], ['error', __('Errors', 'woo-etsy-sync')], ['warning', __('Warnings', 'woo-etsy-sync')], ['success', __('Success', 'woo-etsy-sync')], ['info', __('Info', 'woo-etsy-sync')]];
			mount(card, [
				h('div', { class: 'wes-toolbar' }, h('div', { class: 'wes-seg' }, levels.map(function (l) { return h('button', { class: q.level === l[0] ? 'is-active' : '', onclick: function () { q.level = l[0]; q.page = 1; load(); } }, l[1]); })), h('span', { class: 'wes-toolbar__spacer' }), searchIn),
				!data ? h('div', { class: 'wes-card__body' }, h('div', { class: 'wes-skel' })) :
					(data.rows.length ? h('ul', { class: 'wes-feed' }, data.rows.map(function (row) {
						var li = feedItem(row);
						if (row.product_id) { li.querySelector('.wes-feed__meta').appendChild(h('span', null, ' · ', h('a', { href: BOOT.adminUrl + 'post.php?action=edit&post=' + row.product_id }, __('Edit product', 'woo-etsy-sync')))); }
						return li;
					})) : h('div', { class: 'wes-empty' }, icon('activity'), h('strong', { text: __('Nothing to show', 'woo-etsy-sync') }), __('No activity matches.', 'woo-etsy-sync'))),
				data && data.pages > 1 ? h('div', { class: 'wes-pager' }, h('span', null, data.total + ' entries'), h('span', { class: 'wes-pager__spacer' }),
					h('button', { class: 'wes-btn wes-btn--sm', disabled: q.page <= 1, onclick: function () { q.page--; load(); } }, __('← Newer', 'woo-etsy-sync')),
					h('span', null, q.page + ' / ' + data.pages),
					h('button', { class: 'wes-btn wes-btn--sm', disabled: q.page >= data.pages, onclick: function () { q.page++; load(); } }, __('Older →', 'woo-etsy-sync'))) : null
			]);
		}
		draw();
		load();
	}

	/* ------------------------------------------------------------------
	 * Boot.
	 * ---------------------------------------------------------------- */

	window.WES_UI = {
		h: h, append: append, clear: clear, mount: mount, icon: icon, esc: esc, ago: ago, fullDate: fullDate,
		call: call, toast: toast, modal: modal, confirmBox: confirmBox, busy: busy, alertBox: alertBox, pill: pill,
		field: field, toggle: toggle, select: select, choiceCards: choiceCards, copyRow: copyRow, quickBtn: quickBtn,
		statTile: statTile, feedItem: feedItem, pageHead: pageHead, onEvent: onEvent, progressBanner: progressBanner,
		scheduleTick: scheduleTick, renderChrome: renderChrome, render: render, go: go, route: route, loadTerms: loadTerms,
		viewActivity: viewActivity, boot: BOOT,
		etsyState: function () { return state; },
		etsyInUse: function () { return etsyInUse(); },
		registerChannel: function (name, ext) { EXT[name] = ext; },
		ext: function (name) { return EXT[name]; }
	};

	function boot() {
		mount(root, [header, main]);
		window.addEventListener('hashchange', render);
		if (BOOT.flash && BOOT.flash.error && !location.hash) { location.hash = state.settings.setup_complete ? '#/settings/connection' : '#/setup'; }
		render();
		if (pendingTotal() || linkRunningAny()) { scheduleTick(500); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { setTimeout(boot, 0); }
	// Remove ?wes_connected / ?wes_error from the address bar.
	if (/[?&]wes_(connected|error)=/.test(location.search)) {
		var clean = location.search.replace(/[?&]wes_(connected|error)=[^&]*/g, '').replace(/^&/, '?');
		history.replaceState(null, '', location.pathname + (clean === '?' ? '' : clean) + location.hash);
	}
})();
