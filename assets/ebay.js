/* Marketplace Sync — eBay screens (plugs into admin.js). */
(function () {
	'use strict';
	var __ = (window.wp && window.wp.i18n && window.wp.i18n.__) ? window.wp.i18n.__ : function (s) { return s; };
	var UI = window.WES_UI;
	var BOOT = window.WES_EBAY_BOOT || {};
	if (!UI) { return; }
	var h = UI.h, icon = UI.icon, call = UI.call, toast = UI.toast, mount = UI.mount, clear = UI.clear, append = UI.append;
	var esc = UI.esc, busy = UI.busy, alertBox = UI.alertBox, field = UI.field, toggle = UI.toggle, select = UI.select;
	var S = BOOT.state || {};
	var policiesCache = null;

	function setState(s) { S = s; UI.renderChrome(); }
	function refresh() { return call('/ebay/state').then(function (s) { setState(s); return s; }); }
	function go(r) { UI.go(r); }
	function loadPolicies(force) {
		if (policiesCache && !force) { return Promise.resolve(policiesCache); }
		return call('/ebay/policies' + (force ? '?refresh=1' : '')).then(function (p) { policiesCache = p; return p; });
	}
	function pageHead(t, sub, actions) { return UI.pageHead(t, sub, actions); }
	function card(title, sub, body, actions) {
		return h('div', { class: 'wes-card' },
			h('div', { class: 'wes-card__head' }, h('div', null, h('h2', { text: title }), sub ? h('p', { html: sub }) : null), actions ? h('div', { class: 'wes-card__head-actions' }, actions) : null),
			h('div', { class: 'wes-card__body' }, body));
	}
	function pillFor(row) {
		var st = row.status, ls = row.listing_state;
		if (st === 'error') { return h('span', { class: 'wes-pill wes-pill--error' }, __('Error', 'woo-etsy-sync')); }
		if (st === 'queued') { return h('span', { class: 'wes-pill wes-pill--info' }, __('Queued', 'woo-etsy-sync')); }
		if (st === 'excluded') { return h('span', { class: 'wes-pill wes-pill--muted' }, __('Excluded', 'woo-etsy-sync')); }
		if (!row.listing_id) { return h('span', { class: 'wes-pill wes-pill--muted' }, __('Not on eBay', 'woo-etsy-sync')); }
		if (ls === 'ended' || ls === 'completed') { return h('span', { class: 'wes-pill wes-pill--warn' }, __('Ended', 'woo-etsy-sync')); }
		return h('span', { class: 'wes-pill wes-pill--ok' }, __('Live', 'woo-etsy-sync'));
	}
	function pending() {
		var p = (UI.etsyState().stats || {}).pending || {};
		return { sync: p.ebay_sync || 0, stock: p.ebay_stock || 0, link: p.ebay_link || 0 };
	}
	function banner() {
		var p = pending();
		if (!p.sync && !p.stock) { return null; }
		UI.scheduleTick(800);
		var parts = [];
		if (p.sync) { parts.push(p.sync + ' product' + (p.sync === 1 ? '' : 's') + ' to sync'); }
		if (p.stock) { parts.push(p.stock + __(' stock update', 'woo-etsy-sync') + (p.stock === 1 ? '' : 's')); }
		return h('div', { class: 'wes-progress' }, h('span', { class: 'wes-spinner' }), h('div', { class: 'wes-progress__text' }, h('strong', { text: __('Syncing with eBay…', 'woo-etsy-sync') }), h('span', { text: parts.join(' · ') + __(' remaining. You can leave this page.', 'woo-etsy-sync') })));
	}
	function wplWarning() {
		if (!S.wplister_active) { return null; }
		return alertBox('warn', 'WP-Lister for eBay is still active',
			__('While WP-Lister is running, both plugins would change your eBay stock, so <strong>eBay → WooCommerce stock is paused</strong> here. Once you’ve linked your listings below, deactivate WP-Lister in Plugins and this plugin takes over.', 'woo-etsy-sync'));
	}

	/* ------------------------------------------------------------------
	 * eBay category picker (live search).
	 * ---------------------------------------------------------------- */
	function categoryPicker(value, onChange, placeholder) {
		var wrap = h('div', { class: 'wes-picker' });
		var current = value && value.id ? value : null;
		var input = h('input', { type: 'search', placeholder: __('Describe the item, e.g. "gecko hide" or "slate coaster"…', 'woo-etsy-sync'), autocomplete: 'off', class: 'wes-hidden' });
		var list = h('div', { class: 'wes-picker__list wes-hidden' });
		var display = h('div', { class: 'wes-picker__value', tabindex: '0' });
		var results = [], hl = 0, t;
		function drawValue() {
			mount(display, [h('span', { text: current ? current.label + ' (' + current.id + ')' : (placeholder || __('Choose an eBay category…', 'woo-etsy-sync')) }),
				current ? h('button', { type: 'button', class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function (e) { e.stopPropagation(); current = null; onChange(null); drawValue(); } }, __('Clear', 'woo-etsy-sync')) : h('span', { class: 'wes-muted' }, icon('search'))]);
			display.classList.toggle('is-empty', !current);
		}
		function open() { display.classList.add('wes-hidden'); input.classList.remove('wes-hidden'); input.value = ''; input.focus(); list.classList.remove('wes-hidden'); mount(list, h('div', { class: 'wes-picker__empty', text: __('Type at least 2 letters to search eBay’s categories.', 'woo-etsy-sync') })); }
		function close() { setTimeout(function () { list.classList.add('wes-hidden'); input.classList.add('wes-hidden'); display.classList.remove('wes-hidden'); }, 150); }
		function drawList() {
			clear(list);
			if (!results.length) { list.appendChild(h('div', { class: 'wes-picker__empty', text: __('No suggestions. Try different words.', 'woo-etsy-sync') })); return; }
			results.forEach(function (r, i) {
				var parts = r.path.split(' › '), last = parts.pop();
				list.appendChild(h('div', { class: 'wes-picker__item' + (i === hl ? ' is-hl' : ''), onmousedown: function (e) { e.preventDefault(); pick(r); } },
					h('b', { text: last }), h('small', { text: parts.join(' › ') + __(' · ID ', 'woo-etsy-sync') + r.id })));
			});
		}
		function pick(r) { current = { id: r.id, label: r.path }; onChange(current); drawValue(); input.blur(); }
		input.addEventListener('input', function () {
			clearTimeout(t);
			var q = input.value.trim();
			if (q.length < 2) { return; }
			t = setTimeout(function () {
				mount(list, h('div', { class: 'wes-picker__empty' }, h('span', { class: 'wes-spinner' })));
				call('/ebay/categories?q=' + encodeURIComponent(q)).then(function (r) { results = r; hl = 0; drawList(); }).catch(function (e) { mount(list, h('div', { class: 'wes-picker__empty', text: e.message })); });
			}, 350);
		});
		input.addEventListener('blur', close);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); hl = Math.min(results.length - 1, hl + 1); drawList(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); hl = Math.max(0, hl - 1); drawList(); }
			else if (e.key === 'Enter') { e.preventDefault(); if (results[hl]) { pick(results[hl]); } }
		});
		display.addEventListener('click', open);
		display.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
		drawValue();
		append(wrap, [display, input, list]);
		return wrap;
	}

	/* ------------------------------------------------------------------
	 * Overview (with setup guide when not connected).
	 * ---------------------------------------------------------------- */
	function viewOverview(el) {
		var flash = BOOT.flash || {};
		var out = [];
		if (flash.connected) { out.push(alertBox('ok', __('Connected to eBay', 'woo-etsy-sync'), __('Signed in as <strong>', 'woo-etsy-sync') + esc(S.user_id) + '</strong>.')); flash.connected = false; }
		if (flash.error) { out.push(alertBox('error', __('eBay connection failed', 'woo-etsy-sync'), esc(flash.error))); flash.error = ''; }
		if (S.needs_reconnect) { out.push(alertBox('error', __('eBay needs you to reconnect', 'woo-etsy-sync'), __('Your saved eBay login stopped working.', 'woo-etsy-sync'), h('a', { class: 'wes-btn wes-btn--primary wes-btn--sm', href: '#/ebay/settings/connection' }, __('Reconnect', 'woo-etsy-sync')))); }
		out.push(wplWarning());

		if (!S.connected) {
			append(el, [pageHead(__('eBay', 'woo-etsy-sync'), __('Connect your eBay account to list products and keep stock in sync.', 'woo-etsy-sync')), out, setupGuide()]);
			return;
		}
		var s = S.stats || {};
		var pct = s.products ? Math.round(s.linked / s.products * 100) : 0;
		var feed = h('ul', { class: 'wes-feed' }, h('li', null, h('div', { class: 'wes-skel', style: 'width:60%' })));
		var checks = [
			[S.connected && !S.needs_reconnect, __('eBay account connected', 'woo-etsy-sync'), S.user_id + ' · ' + siteLabel(), 'ebay/settings/connection'],
			[!!S.settings.default_category, __('Default eBay category', 'woo-etsy-sync'), S.settings.default_category_label || __('Needed to list new products', 'woo-etsy-sync'), 'ebay/settings/listing'],
			[!!(S.settings.shipping_policy && S.settings.return_policy && S.settings.payment_policy), __('Postage, returns & payment policies', 'woo-etsy-sync'), __('Needed to list new products', 'woo-etsy-sync'), 'ebay/settings/policies'],
			[!!(S.settings.postcode || S.settings.location), __('Item location', 'woo-etsy-sync'), S.settings.postcode || S.settings.location || __('Needed to list new products', 'woo-etsy-sync'), 'ebay/settings/policies'],
			[S.oos_control, __('Sold-out listings kept alive', 'woo-etsy-sync'), S.oos_control ? __('eBay hides them at 0 instead of ending them', 'woo-etsy-sync') : __('Switch on so listings aren’t ended when they sell out', 'woo-etsy-sync'), 'ebay/settings/stock']
		];
		if (S.wplister && !S.wplister.done && S.wplister.listings) {
			out.push(alertBox('info', __('Coming from WP-Lister?', 'woo-etsy-sync'), __('We found <strong>', 'woo-etsy-sync') + S.wplister.listings + '</strong> eBay listings in WP-Lister. Import their links in one click on the Link listings tab.', h('a', { class: 'wes-btn wes-btn--primary wes-btn--sm', href: '#/ebay/link' }, __('Review & import', 'woo-etsy-sync'))));
		}
		var last = S.last_poll;
		append(el, [
			pageHead(__('eBay', 'woo-etsy-sync'), __('Everything between your store and <strong>', 'woo-etsy-sync') + esc(S.user_id) + '</strong> on ' + esc(siteLabel()) + '.'),
			out, banner(),
			h('div', { class: 'wes-grid wes-grid--4', style: 'margin-bottom:16px' },
				UI.statTile(__('On eBay', 'woo-etsy-sync'), h('span', null, s.linked, h('small', { text: ' / ' + s.products })), pct + __('% of published products', 'woo-etsy-sync'), pct),
				UI.statTile(__('Need attention', 'woo-etsy-sync'), s.errors, s.errors ? h('a', { href: '#/ebay/products/error' }, __('View errors →', 'woo-etsy-sync')) : __('No sync errors', 'woo-etsy-sync'), null, s.errors ? 'wes-dot--err' : 'wes-dot--ok'),
				UI.statTile(__('Last 24 hours', 'woo-etsy-sync'), (s.log24h && s.log24h.success) || 0, __('successful eBay updates', 'woo-etsy-sync')),
				UI.statTile(__('eBay orders checked', 'woo-etsy-sync'), last && last.at ? (last.blocked ? 'paused' : UI.ago(last.at)) : '—', last && last.blocked ? 'WP-Lister is active' : (S.settings.sync_stock_from_ebay ? 'every ' + S.settings.poll_minutes + ' min' : __('eBay → Woo stock is off', 'woo-etsy-sync')))
			),
			h('div', { class: 'wes-grid wes-grid--main' },
				h('div', { class: 'wes-card' }, h('div', { class: 'wes-card__head' }, h('h2', { text: __('Recent eBay activity', 'woo-etsy-sync') }), h('div', { class: 'wes-card__head-actions' }, h('a', { href: '#/ebay/activity', class: 'wes-btn wes-btn--ghost wes-btn--sm' }, __('View all', 'woo-etsy-sync')))), h('div', { class: 'wes-card__body--flush' }, feed)),
				h('div', { class: 'wes-stack' },
					h('div', { class: 'wes-card' }, h('div', { class: 'wes-card__head' }, h('h2', { text: __('Quick actions', 'woo-etsy-sync') })), h('div', { class: 'wes-card__body' }, h('div', { class: 'wes-quick' },
						UI.quickBtn('link', __('Link existing eBay listings', 'woo-etsy-sync'), __('Import from WP-Lister or match by SKU.', 'woo-etsy-sync'), function () { go('ebay/link'); }),
						UI.quickBtn('upload', __('Sync products to eBay', 'woo-etsy-sync'), __('List new products or update existing listings.', 'woo-etsy-sync'), function () { go('ebay/products'); }),
						actionBtn('stock', __('Send all stock to eBay', 'woo-etsy-sync'), __('Push WooCommerce stock to every linked listing.', 'woo-etsy-sync'), '/ebay/stock/push-all', function (r) { toast(__('Queued stock for ', 'woo-etsy-sync') + r.queued + ' products'); UI.etsyState().stats.pending = r.pending; UI.renderChrome(); UI.scheduleTick(400); }),
						actionBtn('cart', __('Check eBay orders now', 'woo-etsy-sync'), __('Take new eBay sales off WooCommerce stock.', 'woo-etsy-sync'), '/ebay/stock/poll-now', function (r) { toast(r.summary.lines ? r.summary.adjusted + __(' stock adjustment(s) from ', 'woo-etsy-sync') + r.summary.lines + __(' sale(s)', 'woo-etsy-sync') : __('No new eBay sales', 'woo-etsy-sync')); refresh().then(function () { UI.render(); }); }, !S.settings.sync_stock_from_ebay || S.wplister_active)
					))),
					h('div', { class: 'wes-card' }, h('div', { class: 'wes-card__head' }, h('h2', { text: __('Health check', 'woo-etsy-sync') })), h('div', { class: 'wes-card__body', style: 'padding-top:6px;padding-bottom:6px' }, h('ul', { class: 'wes-checklist' }, checks.map(function (c) {
						return h('li', null, h('span', { class: 'wes-checklist__icon wes-checklist__icon--' + (c[0] ? 'ok' : 'todo') }, icon(c[0] ? 'check' : 'alert')), h('div', { class: 'wes-checklist__text' }, h('strong', { text: c[1] }), h('span', { text: c[2] })), c[0] ? null : h('a', { href: '#/' + c[3], class: 'wes-btn wes-btn--sm' }, __('Fix', 'woo-etsy-sync')));
					}))))
				)
			)
		]);
		call('/log?page=1&channel=ebay').then(function (r) {
			mount(feed, r.rows.length ? r.rows.slice(0, 8).map(UI.feedItem) : h('li', null, h('div', { class: 'wes-empty', style: 'padding:24px;width:100%' }, h('strong', { text: __('Nothing yet', 'woo-etsy-sync') }), __('eBay activity will show up here.', 'woo-etsy-sync'))));
		});
		return UI.onEvent('wes:idle', function () { if (UI.route() === 'ebay') { refresh().then(UI.render); } });
	}

	function actionBtn(ic, title, desc, path, done, disabled) {
		var b = UI.quickBtn(ic, title, desc, function () {
			b.disabled = true;
			call(path, { method: 'POST' }).then(done).catch(function (e) { toast(e.message, 'error'); }).finally(function () { b.disabled = false; });
		}, disabled);
		return b;
	}

	function siteHost() {
		var m = (S.settings || {}).marketplace;
		var s = (S.sites || []).filter(function (x) { return x.id === m; })[0];
		return s && s.host ? s.host : 'www.ebay.com';
	}

	function siteLabel() {
		var m = (S.settings || {}).marketplace;
		var s = (S.sites || []).filter(function (x) { return x.id === m; })[0];
		return s ? s.label : __('eBay', 'woo-etsy-sync');
	}

	function setupGuide() {
		var idIn = h('input', { type: 'text', value: S.client_id || '', placeholder: __('e.g. YourName-MyShopSy-PRD-a1b2c3d4e-5f6a7b8c', 'woo-etsy-sync'), spellcheck: 'false' });
		var secIn = h('input', { type: 'password', placeholder: S.has_secret ? __('•••••••• (saved — leave blank to keep)', 'woo-etsy-sync') : 'PRD-…', autocomplete: 'new-password' });
		var ruIn = h('input', { type: 'text', value: S.runame || '', placeholder: __('e.g. Your_Name-YourName-MyShop-abcdefgh', 'woo-etsy-sync'), spellcheck: 'false' });
		var sandbox = !!S.sandbox;
		var err = h('div');
		var save = h('button', { class: 'wes-btn wes-btn--primary', onclick: function () {
			clear(err); busy(save, true, __('Checking with eBay…', 'woo-etsy-sync'));
			call('/ebay/credentials', { method: 'POST', data: { client_id: idIn.value, client_secret: secIn.value, runame: ruIn.value, sandbox: sandbox } })
				.then(function (r) { setState(r.state); toast(__('eBay accepted your keys', 'woo-etsy-sync')); UI.render(); })
				.catch(function (e) { busy(save, false); mount(err, alertBox('error', __('That didn’t work', 'woo-etsy-sync'), esc(e.message))); });
		} }, __('Save & test keys', 'woo-etsy-sync'));
		var connect = h('button', { class: 'wes-btn wes-btn--primary wes-btn--lg', disabled: !S.has_credentials, onclick: function () {
			busy(connect, true, __('Opening eBay…', 'woo-etsy-sync'));
			call('/ebay/connect', { method: 'POST' }).then(function (r) { location.href = r.url; }).catch(function (e) { busy(connect, false); toast(e.message, 'error'); });
		} }, icon('plug'), __('Connect my eBay account', 'woo-etsy-sync'));

		return h('div', { class: 'wes-wizard', style: 'max-width:860px' }, h('div', { class: 'wes-card' }, h('div', { class: 'wes-card__body', style: 'padding:28px' },
			h('div', { class: 'wes-hero' },
				h('div', { class: 'wes-hero__art' }, h('div', { class: 'wes-hero__tile wes-hero__tile--woo' }, __('Woo', 'woo-etsy-sync')), h('span', { class: 'wes-hero__sync' }, icon('sync')), h('div', { class: 'wes-hero__tile', style: 'background:linear-gradient(135deg,#3b82f6,#1d4ed8)' }, __('eBay', 'woo-etsy-sync'))),
				h('h1', { text: __('Connect your eBay account', 'woo-etsy-sync') }),
				h('p', { text: __('Like Etsy, eBay needs your own free developer keys. It takes about 10 minutes, once.', 'woo-etsy-sync') })
			),
			!S.site_https ? alertBox('warn', __('Your site isn’t using HTTPS', 'woo-etsy-sync'), __('eBay only accepts secure (https://) addresses for sign-in and photos. Switch your site to HTTPS first.', 'woo-etsy-sync')) : null,
			h('ol', { class: 'wes-steplist', style: 'margin-top:20px' },
				h('li', null, h('div', null, h('strong', null, __('Create a free eBay developer account and production keys', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small' }, __('Sign up with any email, then open “Application Keys” and click “Create a keyset” under Production.', 'woo-etsy-sync')),
					h('div', { style: 'margin-top:8px' }, h('a', { class: 'wes-btn wes-btn--sm', href: 'https://developer.ebay.com/my/keys', target: '_blank', rel: 'noopener' }, __('Open eBay Application Keys', 'woo-etsy-sync'), icon('external'))))),
				h('li', null, h('div', { style: 'min-width:0' }, h('strong', null, __('Tell eBay where to send account-deletion notices', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small' }, __('eBay asks for this before it switches the keys on. Click “Alerts & Notifications” next to your production keyset, choose “Marketplace Account Deletion”, and paste these two values. This plugin answers eBay’s check automatically.', 'woo-etsy-sync')),
					h('div', { class: 'wes-small', style: 'margin-top:8px' }, __('Notification endpoint', 'woo-etsy-sync')), UI.copyRow(S.notification_url),
					h('div', { class: 'wes-small', style: 'margin-top:8px' }, __('Verification token', 'woo-etsy-sync')), UI.copyRow(S.verification_token))),
				h('li', null, h('div', { style: 'min-width:0' }, h('strong', null, __('Add a sign-in redirect (RuName)', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small', html: __('Open “User Tokens” for the production keyset → “Get a Token from eBay via Your Application” → “Add eBay Redirect URL”. Fill in the three addresses below, tick <em>OAuth enabled</em>, save, then copy the <strong>RuName</strong> (eBay Redirect URL name) it shows.', 'woo-etsy-sync') }),
					h('div', { class: 'wes-small', style: 'margin-top:8px' }, __('Your auth accepted URL — and the declined URL', 'woo-etsy-sync')), UI.copyRow(S.accept_url),
					h('div', { class: 'wes-small', style: 'margin-top:8px' }, __('Your privacy policy URL', 'woo-etsy-sync')), UI.copyRow(S.privacy_url))),
				h('li', null, h('div', null, h('strong', null, __('Paste your keys', 'woo-etsy-sync')), h('div', { class: 'wes-muted wes-small' }, __('App ID is also called Client ID; Cert ID is also called Client Secret.', 'woo-etsy-sync'))))
			),
			h('div', { class: 'wes-row' }, field(__('App ID (Client ID)', 'woo-etsy-sync'), idIn), field(__('Cert ID (Client Secret)', 'woo-etsy-sync'), secIn)),
			field(__('RuName (eBay redirect URL name)', 'woo-etsy-sync'), ruIn, __('A short name like <code>YourName-YourName-WooSyn-abcdef</code> – not a web address. If you paste eBay’s long “Test sign-in” link instead, the RuName is picked out of it automatically.', 'woo-etsy-sync')),
			toggle(sandbox, 'These are Sandbox keys (testing only)', 'Leave off for your real shop.', function (v) { sandbox = v; }),
			err,
			h('div', { class: 'wes-inline', style: 'margin-top:14px' }, save),
			h('div', { class: 'wes-divider' }),
			h('div', { style: 'text-align:center' }, connect,
				h('p', { class: 'wes-muted wes-small', style: 'margin-top:10px' }, S.has_credentials ? __('You’ll sign in on eBay and approve access, then come straight back here.', 'woo-etsy-sync') : __('Save your keys first.', 'woo-etsy-sync')))
		)));
	}

	/* ------------------------------------------------------------------
	 * Products.
	 * ---------------------------------------------------------------- */
	function viewProducts(el, args) {
		var q = { page: 1, search: '', status: args[0] || '' };
		var data = { rows: [], total: 0, pages: 0 }, loading = true, selected = {};
		var bannerBox = h('div'), cardEl = h('div', { class: 'wes-card' });
		var searchIn = h('input', { type: 'search', placeholder: __('Search name or SKU…', 'woo-etsy-sync') }), t;
		searchIn.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { q.search = searchIn.value; q.page = 1; load(); }, 300); });
		append(el, [pageHead(__('eBay products', 'woo-etsy-sync'), __('List products on eBay, update them, or see what went wrong.', 'woo-etsy-sync')), wplWarning(), !S.connected ? alertBox('warn', __('Connect eBay first', 'woo-etsy-sync'), '') : null, bannerBox, cardEl]);

		function load() {
			loading = true; draw();
			call('/ebay/products?page=' + q.page + '&status=' + q.status + '&search=' + encodeURIComponent(q.search)).then(function (r) { data = r; loading = false; draw(); }).catch(function (e) { data = { rows: [], total: 0, pages: 0, error: e.message }; loading = false; draw(); });
		}
		function ids() { return Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number); }
		function queue(list, all, btn) {
			busy(btn, true, __('Queuing…', 'woo-etsy-sync'));
			call('/ebay/products/sync', { method: 'POST', data: all ? { all: true, status: q.status, search: q.search } : { ids: list } }).then(function (r) {
				toast(r.queued + ' product' + (r.queued === 1 ? '' : 's') + __(' queued for eBay', 'woo-etsy-sync'));
				UI.etsyState().stats.pending = r.pending; selected = {}; UI.renderChrome(); mount(bannerBox, banner()); UI.scheduleTick(300); load();
			}).catch(function (e) { toast(e.message, 'error'); busy(btn, false); });
		}
		function replace(row) { data.rows = data.rows.map(function (r) { return r.id === row.id ? row : r; }); draw(); }
		function draw() {
			var sel = ids();
			var tabs = [['', __('All', 'woo-etsy-sync')], ['linked', __('On eBay', 'woo-etsy-sync')], ['not_synced', __('Not on eBay', 'woo-etsy-sync')], ['error', __('Errors', 'woo-etsy-sync')], ['excluded', __('Excluded', 'woo-etsy-sync')]];
			var body = h('tbody');
			if (loading && !data.rows.length) { for (var i = 0; i < 5; i++) { body.appendChild(h('tr', null, h('td', { colspan: 7 }, h('div', { class: 'wes-skel', style: 'width:' + (40 + i * 10) + '%' })))); } }
			else if (!data.rows.length) { body.appendChild(h('tr', null, h('td', { colspan: 7 }, h('div', { class: 'wes-empty' }, icon('box'), h('strong', { text: data.error ? __('Couldn’t load products', 'woo-etsy-sync') : __('No products here', 'woo-etsy-sync') }), data.error || __('Nothing matches this filter.', 'woo-etsy-sync'))))); }
			data.rows.forEach(function (r) {
				var syncBtn = h('button', { class: 'wes-btn wes-btn--sm', disabled: !S.connected || r.status === 'excluded', onclick: function () {
					busy(syncBtn, true, r.listing_id ? __('Updating…', 'woo-etsy-sync') : __('Listing…', 'woo-etsy-sync'));
					call('/ebay/products/' + r.id + '/sync-now', { method: 'POST' }).then(function (res) { toast(res.result.created ? __('Listed on eBay', 'woo-etsy-sync') : __('Updated on eBay', 'woo-etsy-sync')); replace(res.row); }).catch(function (e) { toast(e.message, 'error'); load(); });
				} }, r.listing_id ? __('Update', 'woo-etsy-sync') : __('List on eBay', 'woo-etsy-sync'));
				body.appendChild(h('tr', null,
					h('td', { class: 'wes-check' }, h('input', { type: 'checkbox', checked: !!selected[r.id], disabled: r.status === 'excluded', onchange: function (e) { selected[r.id] = e.target.checked; draw(); } })),
					h('td', null, h('div', { class: 'wes-prod' }, h('span', { class: 'wes-prod__img', style: r.thumb ? 'background-image:url(' + JSON.stringify(r.thumb) + ')' : '' }),
						h('div', null, h('a', { class: 'wes-prod__name', href: r.edit_url, text: r.name }), h('div', { class: 'wes-prod__meta' }, [r.sku ? 'SKU ' + r.sku : __('No SKU', 'woo-etsy-sync'), r.type === 'variable' ? r.variations + ' variations' : r.type].join(' · ')), r.error ? h('div', { class: 'wes-err-text', text: r.error }) : null))),
					h('td', { class: 'wes-num' }, r.price), h('td', { class: 'wes-num' }, r.stock),
					h('td', null, pillFor(r), r.listing_url ? h('a', { href: r.listing_url, target: '_blank', rel: 'noopener', title: __('Open on eBay', 'woo-etsy-sync'), style: 'margin-left:6px;vertical-align:middle;display:inline-flex;color:var(--wes-text-3)' }, icon('external')) : null),
					h('td', { class: 'wes-muted wes-small' }, r.synced_at ? UI.ago(r.synced_at) : '—'),
					h('td', { class: 'wes-actions-cell' }, h('span', { class: 'wes-inline', style: 'justify-content:flex-end' }, syncBtn, h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', title: __('More', 'woo-etsy-sync'), onclick: function () { more(r); } }, '•••')))
				));
			});
			var syncSel = h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function () { queue(sel, false, syncSel); } }, icon('upload'), __('Sync ', 'woo-etsy-sync') + sel.length + ' selected');
			var syncAll = h('button', { class: 'wes-btn wes-btn--sm', disabled: !S.connected || !data.total, onclick: function () {
				UI.confirmBox(__('Sync ', 'woo-etsy-sync') + data.total + __(' products to eBay?', 'woo-etsy-sync'), __('New eBay listings are created for products that aren’t on eBay yet, and linked listings are updated. eBay may charge insertion fees for new listings.', 'woo-etsy-sync'), __('Sync them', 'woo-etsy-sync')).then(function (ok) { if (ok) { queue([], true, syncAll); } });
			} }, icon('upload'), __('Sync all', 'woo-etsy-sync') + (q.status || q.search ? ' in view' : ''));
			mount(cardEl, [
				h('div', { class: 'wes-toolbar' }, h('div', { class: 'wes-seg' }, tabs.map(function (tb) { return h('button', { class: q.status === tb[0] ? 'is-active' : '', onclick: function () { q.status = tb[0]; q.page = 1; selected = {}; load(); } }, tb[1]); })), h('span', { class: 'wes-toolbar__spacer' }), searchIn, syncAll),
				sel.length ? h('div', { class: 'wes-bulkbar' }, sel.length + ' selected', syncSel, h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function () { selected = {}; draw(); } }, __('Clear', 'woo-etsy-sync'))) : null,
				h('div', { class: 'wes-table-wrap' }, h('table', { class: 'wes-table' }, h('thead', null, h('tr', null, h('th', { class: 'wes-check' }), h('th', null, __('Product', 'woo-etsy-sync')), h('th', null, __('Price', 'woo-etsy-sync')), h('th', null, __('Stock', 'woo-etsy-sync')), h('th', null, __('eBay', 'woo-etsy-sync')), h('th', null, __('Last sync', 'woo-etsy-sync')), h('th'))), body)),
				data.pages > 1 ? h('div', { class: 'wes-pager' }, h('span', null, data.total + ' products'), h('span', { class: 'wes-pager__spacer' }),
					h('button', { class: 'wes-btn wes-btn--sm', disabled: q.page <= 1, onclick: function () { q.page--; load(); } }, __('← Prev', 'woo-etsy-sync')), h('span', null, __('Page ', 'woo-etsy-sync') + q.page + ' of ' + data.pages),
					h('button', { class: 'wes-btn wes-btn--sm', disabled: q.page >= data.pages, onclick: function () { q.page++; load(); } }, __('Next →', 'woo-etsy-sync'))) : null
			]);
		}
		function more(r) {
			var body = h('div', { class: 'wes-quick' });
			var m;
			append(body, [
				UI.quickBtn('eye', __('Check with eBay', 'woo-etsy-sync'), __('eBay validates the listing (and shows fees) without listing it.', 'woo-etsy-sync'), function () { m.close(); previewModal(r.id); }),
				r.listing_url ? UI.quickBtn('external', __('Open on eBay', 'woo-etsy-sync'), __('View the listing.', 'woo-etsy-sync'), function () { window.open(r.listing_url, '_blank'); }) : null,
				!r.listing_id ? UI.quickBtn('link', __('Link to an existing eBay listing', 'woo-etsy-sync'), __('Already on eBay? Paste its link or item number.', 'woo-etsy-sync'), function () { m.close(); linkModal(r); }) : null,
				r.listing_id ? UI.quickBtn('link', __('Unlink from eBay', 'woo-etsy-sync'), __('Stop syncing. The eBay listing stays live.', 'woo-etsy-sync'), function () { m.close(); unlink(r, false); }) : null,
				r.listing_id ? UI.quickBtn('trash', __('End the eBay listing', 'woo-etsy-sync'), __('End the listing on eBay and unlink.', 'woo-etsy-sync'), function () { m.close(); unlink(r, true); }) : null,
				UI.quickBtn(r.status === 'excluded' ? 'check' : 'x', r.status === 'excluded' ? __('Allow syncing to eBay', 'woo-etsy-sync') : __('Never sync to eBay', 'woo-etsy-sync'), __('Skip it in bulk syncs and stock updates.', 'woo-etsy-sync'), function () { m.close(); call('/ebay/products/' + r.id + '/exclude', { method: 'POST', data: { exclude: r.status !== 'excluded' } }).then(replace); }),
				UI.quickBtn('pen', __('Edit product & eBay overrides', 'woo-etsy-sync'), __('Title, category, item specifics (see the “eBay” tab).', 'woo-etsy-sync'), function () { location.href = r.edit_url; })
			]);
			m = UI.modal({ title: r.name, body: body, actions: [{ label: __('Close', 'woo-etsy-sync') }] });
		}
		function unlink(r, end) {
			UI.confirmBox(end ? __('End this eBay listing?', 'woo-etsy-sync') : __('Unlink from eBay?', 'woo-etsy-sync'), end ? __('The listing will be ended on eBay. You can relist it on eBay later.', 'woo-etsy-sync') : __('The product stops syncing; the eBay listing stays as it is.', 'woo-etsy-sync'), end ? __('End listing', 'woo-etsy-sync') : __('Unlink', 'woo-etsy-sync'), end).then(function (ok) {
				if (ok) { call('/ebay/products/' + r.id + '/unlink', { method: 'POST', data: { end_listing: end } }).then(function (row) { toast(end ? __('Listing ended', 'woo-etsy-sync') : __('Unlinked', 'woo-etsy-sync')); replace(row); }).catch(function (e) { toast(e.message, 'error'); }); }
			});
		}
		function linkModal(r) {
			var input = h('input', { type: 'text', placeholder: 'https://' + siteHost() + '/itm/123456789012' });
			UI.modal({ title: __('Link to an eBay listing', 'woo-etsy-sync'), body: field(__('eBay listing link or item number', 'woo-etsy-sync'), input), actions: [{ label: __('Cancel', 'woo-etsy-sync') }, { label: __('Link', 'woo-etsy-sync'), primary: true, onClick: function (close) {
				call('/ebay/products/' + r.id + '/link', { method: 'POST', data: { item: input.value } }).then(function (row) { toast(__('Linked', 'woo-etsy-sync')); replace(row); close(); }).catch(function (e) { toast(e.message, 'error'); });
				return false;
			} }] });
		}
		mount(bannerBox, banner());
		load();
		var a = UI.onEvent('wes:tick', function () { mount(bannerBox, banner()); });
		var b = UI.onEvent('wes:idle', function () { mount(bannerBox, banner()); load(); });
		return function () { a(); b(); };
	}

	function previewModal(id) {
		var body = h('div', null, h('span', { class: 'wes-spinner' }), __(' Asking eBay…', 'woo-etsy-sync'));
		UI.modal({ title: __('Check with eBay', 'woo-etsy-sync'), icon: 'eye', body: body, actions: [{ label: __('Close', 'woo-etsy-sync'), primary: true }] });
		call('/ebay/products/' + id + '/preview?verify=1').then(function (p) {
			var it = p.item || {};
			var dl = h('dl', { class: 'wes-kv' });
			function row(k, v) { if (v === undefined || v === null || v === '') { return; } append(dl, [h('dt', { text: k }), h('dd', null, v)]); }
			row(__('Title', 'woo-etsy-sync'), it.title + ' (' + (it.title || '').length + '/80)');
			row(__('Category ID', 'woo-etsy-sync'), it.category ? String(it.category) : 'missing');
			row(__('Price', 'woo-etsy-sync'), it.price != null ? String(it.price) : (it.variations && it.variations.length ? __('per variation', 'woo-etsy-sync') : ''));
			row(__('Quantity', 'woo-etsy-sync'), it.quantity != null ? String(it.quantity) : '');
			row(__('Photos', 'woo-etsy-sync'), String(it.images || 0));
			row(__('Item specifics', 'woo-etsy-sync'), (it.specifics || []).map(function (s) { return s.Name + ': ' + [].concat(s.Value).join(', '); }).join('\n') || 'none');
			row(__('Description', 'woo-etsy-sync'), it.description ? h('div', { class: 'wes-desc-preview', text: it.description }) : '');
			mount(body, [
				p.problems && p.problems.length ? alertBox('warn', __('Not ready for eBay yet', 'woo-etsy-sync'), p.problems.map(esc).join('<br>')) :
					alertBox('ok', p.ebay_ok ? __('eBay accepted this listing', 'woo-etsy-sync') : __('Ready', 'woo-etsy-sync'), (p.ebay_ok ? __('eBay checked it and it would list fine', 'woo-etsy-sync') + (p.fees ? __(' (estimated fees ', 'woo-etsy-sync') + p.fees + ')' : '') + '.' : '') + (p.ebay_note ? __('<br>Note: ', 'woo-etsy-sync') + esc(p.ebay_note) : '')),
				dl,
				it.variations && it.variations.length ? h('div', { style: 'margin-top:14px' }, h('div', { class: 'wes-section-title', text: it.variations.length + ' variations' }),
					h('div', { class: 'wes-table-wrap', style: 'max-height:200px;overflow:auto;border:1px solid var(--wes-border);border-radius:8px' }, h('table', { class: 'wes-table' },
						h('thead', null, h('tr', null, h('th', null, __('Options', 'woo-etsy-sync')), h('th', null, 'SKU'), h('th', null, __('Price', 'woo-etsy-sync')), h('th', null, __('Qty', 'woo-etsy-sync')))),
						h('tbody', null, it.variations.map(function (v) { return h('tr', null, h('td', null, v.options), h('td', null, v.sku), h('td', { class: 'wes-num' }, String(v.price)), h('td', { class: 'wes-num' }, String(v.quantity))); }))))) : null
			]);
		}).catch(function (e) { mount(body, alertBox('error', __('Check failed', 'woo-etsy-sync'), esc(e.message))); });
	}

	/* ------------------------------------------------------------------
	 * Link existing listings.
	 * ---------------------------------------------------------------- */
	function viewLink(el) {
		var matchTitles = true;
		var box = h('div');
		append(el, [pageHead(__('Link existing eBay listings', 'woo-etsy-sync'), __('Connect listings that are already live on eBay to your products so stock stays in sync. <strong>Nothing on eBay is changed</strong> by this step.', 'woo-etsy-sync')), wplWarning(), box]);
		function draw() {
			var r = S.link_report, running = r && r.status === 'running';
			var w = S.wplister;
			var wplCard = null;
			if (w && w.listings) {
				var ib = h('button', { class: 'wes-btn wes-btn--primary', disabled: !S.connected, onclick: function () {
					busy(ib, true, __('Importing…', 'woo-etsy-sync'));
					call('/ebay/wplister/import', { method: 'POST' }).then(function (res) { setState(res.state); toast(__('Imported ', 'woo-etsy-sync') + res.result.linked + __(' listing links from WP-Lister', 'woo-etsy-sync')); draw(); }).catch(function (e) { busy(ib, false); toast(e.message, 'error'); });
				} }, icon('download'), w.done ? __('Import again', 'woo-etsy-sync') : __('Import from WP-Lister', 'woo-etsy-sync'));
				wplCard = card(__('Step 1 · Import from WP-Lister', 'woo-etsy-sync'), 'WP-Lister already knows which product each of your <strong>' + w.listings + '</strong> eBay listings belongs to. Import those links first — it’s instant and exact.', [ib, w.done ? h('span', { class: 'wes-muted wes-small', style: 'margin-left:10px' }, __('Imported already', 'woo-etsy-sync')) : null]);
			}
			var start = h('button', { class: 'wes-btn wes-btn--primary', disabled: !S.connected || running, onclick: function () {
				busy(start, true, __('Starting…', 'woo-etsy-sync'));
				call('/ebay/link/start', { method: 'POST', data: { match_titles: matchTitles } }).then(function (rep) { S.link_report = rep; draw(); UI.scheduleTick(300); }).catch(function (e) { busy(start, false); toast(e.message, 'error'); });
			} }, icon('link'), r ? __('Scan again', 'woo-etsy-sync') : __('Find matches', 'woo-etsy-sync'));
			var scanCard = card((wplCard ? __('Step 2 · ', 'woo-etsy-sync') : '') + __('Match the rest by SKU', 'woo-etsy-sync'), __('Scans your active eBay listings and matches their SKUs (including variation SKUs) to WooCommerce products.', 'woo-etsy-sync'), [
				toggle(matchTitles, 'Also match by exact title', 'Uses the product name or your old WP-Lister eBay title when a listing has no SKU.', function (v) { matchTitles = v; }),
				h('div', { style: 'margin-top:12px' }, start)
			]);
			var result = null;
			if (r) {
				var linked = r.linked || [], un = r.unmatched || [], co = r.conflicts || [];
				result = h('div', { class: 'wes-stack' },
					running ? h('div', { class: 'wes-progress' }, h('span', { class: 'wes-spinner' }), h('div', { class: 'wes-progress__text' }, h('strong', { text: __('Scanning your eBay listings…', 'woo-etsy-sync') }), h('span', { text: r.scanned + ' checked so far' }))) : null,
					r.status === 'error' ? alertBox('error', __('Scan stopped', 'woo-etsy-sync'), esc(r.error)) : null,
					h('div', { class: 'wes-grid wes-grid--4' }, UI.statTile(__('Listings checked', 'woo-etsy-sync'), r.scanned, r.finished_at ? 'finished ' + UI.ago(r.finished_at) : 'in progress'), UI.statTile(__('Newly linked', 'woo-etsy-sync'), linked.length, __('stock will sync', 'woo-etsy-sync'), null, 'wes-dot--ok'), UI.statTile(__('Already linked', 'woo-etsy-sync'), r.already || 0, 'skipped'), UI.statTile(__('No match', 'woo-etsy-sync'), un.length, co.length + ' conflicts', null, un.length ? 'wes-dot--warn' : 'wes-dot--ok')),
					list(__('Linked', 'woo-etsy-sync'), linked, function (x) { return [h('div', null, h('a', { href: UI.boot.adminUrl + 'post.php?action=edit&post=' + x.product_id, text: x.product }), h('div', { class: 'wes-muted wes-small', text: __('eBay: ', 'woo-etsy-sync') + x.title })), h('span', { class: 'wes-pill wes-pill--ok' }, 'by ' + x.via), itemLink(x.item_id)]; }),
					list(__('Not matched', 'woo-etsy-sync'), un, function (x) { return [h('span', { text: x.title }), h('span', { class: 'wes-muted wes-small', text: x.sku ? 'SKU ' + x.sku : __('no SKU', 'woo-etsy-sync') }), itemLink(x.item_id)]; }, __('Give the product the same SKU as on eBay, or link it by hand from the product’s eBay tab.', 'woo-etsy-sync')),
					list(__('Conflicts', 'woo-etsy-sync'), co, function (x) { return [h('span', { text: x.title }), h('span', { class: 'wes-muted wes-small', text: x.reason }), itemLink(x.item_id)]; })
				);
			}
			var next = (S.stats && S.stats.linked) ? alertBox('info', __('Next: line up the stock', 'woo-etsy-sync'), __('When you’re happy with the links (and WP-Lister is switched off), send WooCommerce stock to eBay so both sides agree.', 'woo-etsy-sync'),
				h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: function (e) { var b = e.currentTarget; busy(b, true, __('Queuing…', 'woo-etsy-sync')); call('/ebay/stock/push-all', { method: 'POST' }).then(function (res) { toast(__('Queued stock for ', 'woo-etsy-sync') + res.queued + ' products'); UI.etsyState().stats.pending = res.pending; UI.renderChrome(); UI.scheduleTick(300); busy(b, false); }); } }, __('Send WooCommerce stock to eBay', 'woo-etsy-sync'))) : null;
			mount(box, h('div', { class: 'wes-stack' }, wplCard, scanCard, result, next));
		}
		function itemLink(id) { return h('a', { class: 'wes-btn wes-btn--ghost wes-btn--sm', href: 'https://' + siteHost() + '/itm/' + id, target: '_blank', rel: 'noopener' }, __('eBay', 'woo-etsy-sync'), icon('external')); }
		function list(title, items, cells, tip) {
			if (!items.length) { return null; }
			return h('div', { class: 'wes-card' }, h('div', { class: 'wes-card__head' }, h('h3', null, title + ' ', h('span', { class: 'wes-badge wes-badge--muted' }, items.length)), tip ? h('p', { style: 'margin-left:auto', text: tip }) : null),
				h('div', { class: 'wes-table-wrap', style: 'max-height:360px;overflow:auto' }, h('table', { class: 'wes-table' }, h('tbody', null, items.map(function (x) { return h('tr', null, cells(x).map(function (c) { return h('td', null, c); })); })))));
		}
		draw();
		return UI.onEvent('wes:tick', function () { draw(); });
	}

	/* ------------------------------------------------------------------
	 * Settings.
	 * ---------------------------------------------------------------- */
	function viewSettings(el, args) {
		var sections = [['connection', __('Connection', 'woo-etsy-sync'), 'plug'], ['listing', __('Listing defaults', 'woo-etsy-sync'), 'file'], ['categories', __('Category mapping', 'woo-etsy-sync'), 'layers'], ['policies', __('Policies & location', 'woo-etsy-sync'), 'truck'], ['specifics', __('Item specifics & safety', 'woo-etsy-sync'), 'tag'], ['pricing', __('Pricing', 'woo-etsy-sync'), 'dollar'], ['stock', __('Stock sync', 'woo-etsy-sync'), 'stock'], ['behaviour', __('Automatic updates', 'woo-etsy-sync'), 'sync']];
		var current = args[0] || (S.connected ? 'listing' : 'connection');
		var draft = JSON.parse(JSON.stringify(S.settings)), dirty = false;
		var panel = h('div', { class: 'wes-stack' }), subnav = h('nav', { class: 'wes-subnav' });
		var savebar = h('div', { class: 'wes-savebar' }, h('span', { text: __('You have unsaved eBay changes', 'woo-etsy-sync') }),
			h('button', { class: 'wes-btn wes-btn--ghost wes-btn--sm', onclick: function () { draft = JSON.parse(JSON.stringify(S.settings)); setDirty(false); drawPanel(); } }, __('Discard', 'woo-etsy-sync')),
			h('button', { class: 'wes-btn wes-btn--primary wes-btn--sm', onclick: save }, __('Save changes', 'woo-etsy-sync')));
		function setDirty(v) { dirty = v; savebar.classList.toggle('is-visible', v); }
		function set(k, v) { draft[k] = v; setDirty(true); }
		function save(e) {
			var b = e && e.currentTarget; busy(b, true, __('Saving…', 'woo-etsy-sync'));
			call('/ebay/settings', { method: 'POST', data: draft }).then(function (s) { setState(s); draft = JSON.parse(JSON.stringify(S.settings)); setDirty(false); toast(__('eBay settings saved', 'woo-etsy-sync')); busy(b, false); }).catch(function (er) { toast(er.message, 'error'); busy(b, false); });
		}
		function drawNav() {
			mount(subnav, sections.map(function (s) { return h('a', { href: '#/ebay/settings/' + s[0], class: current === s[0] ? 'is-active' : '', onclick: function (e) { e.preventDefault(); current = s[0]; history.replaceState(null, '', '#/ebay/settings/' + s[0]); drawNav(); drawPanel(); } }, icon(s[2]), s[1]); }));
		}
		function needConn() { return alertBox('warn', __('Connect eBay first', 'woo-etsy-sync'), __('These options come from your eBay account.', 'woo-etsy-sync'), h('a', { class: 'wes-btn wes-btn--sm', href: '#/ebay/settings/connection', onclick: function () { current = 'connection'; drawNav(); drawPanel(); } }, __('Go to Connection', 'woo-etsy-sync'))); }
		function num(k, min, max) { return h('input', { type: 'number', min: min, max: max, value: draft[k], oninput: function (e) { set(k, +e.target.value); } }); }
		function txt(k, ph) { return h('input', { type: 'text', value: draft[k], placeholder: ph || '', oninput: function (e) { set(k, e.target.value); } }); }

		var P = {};
		P.connection = function () {
			if (!S.connected) { return [setupGuide()]; }
			var rec = h('button', { class: 'wes-btn', onclick: function () { busy(rec, true, __('Opening eBay…', 'woo-etsy-sync')); call('/ebay/connect', { method: 'POST' }).then(function (r) { location.href = r.url; }); } }, icon('refresh'), __('Reconnect', 'woo-etsy-sync'));
			var dis = h('button', { class: 'wes-btn wes-btn--danger', onclick: function () { UI.confirmBox(__('Disconnect eBay?', 'woo-etsy-sync'), __('eBay syncing stops until you connect again. Product links are kept.', 'woo-etsy-sync'), __('Disconnect', 'woo-etsy-sync'), true).then(function (ok) { if (ok) { call('/ebay/disconnect', { method: 'POST' }).then(function (s) { setState(s); drawPanel(); }); } }); } }, __('Disconnect', 'woo-etsy-sync'));
			var days = S.refresh_expires_at ? Math.round((S.refresh_expires_at * 1000 - Date.now()) / 86400000) : null;
			return [
				card(__('eBay account', 'woo-etsy-sync'), null, h('div', { class: 'wes-inline', style: 'gap:14px' },
					h('div', { class: 'wes-hero__tile', style: 'width:48px;height:48px;border-radius:12px;font-size:13px;background:linear-gradient(135deg,#3b82f6,#1d4ed8)' }, __('eBay', 'woo-etsy-sync')),
					h('div', { style: 'flex:1' }, h('div', { style: 'font-weight:650;font-size:16px' }, S.user_id, ' ', S.needs_reconnect ? h('span', { class: 'wes-pill wes-pill--error' }, __('Needs reconnect', 'woo-etsy-sync')) : h('span', { class: 'wes-pill wes-pill--ok' }, __('Connected', 'woo-etsy-sync'))),
						h('div', { class: 'wes-muted wes-small' }, (S.sandbox ? __('SANDBOX · ', 'woo-etsy-sync') : '') + __('Connected ', 'woo-etsy-sync') + UI.ago(S.connected_at) + (days !== null ? __(' · login renews automatically; must be re-approved within ', 'woo-etsy-sync') + days + ' days if unused' : '')))
				), [rec, dis]),
				card(__('Developer keys & notifications', 'woo-etsy-sync'), __('Only needed if you change your eBay keyset.', 'woo-etsy-sync'), [
					field(__('Notification endpoint', 'woo-etsy-sync'), UI.copyRow(S.notification_url)), field(__('Verification token', 'woo-etsy-sync'), UI.copyRow(S.verification_token)), field(__('Auth accepted / declined URL', 'woo-etsy-sync'), UI.copyRow(S.accept_url))
				])
			];
		};
		P.listing = function () {
			if (!S.connected) { return [needConn()]; }
			return [
				card(__('eBay site', 'woo-etsy-sync'), null, [field(__('Sell on', 'woo-etsy-sync'), select(S.sites, draft.marketplace, function (v) { set('marketplace', v); })), h('p', { class: 'wes-help', text: __('Prices are sent in that site’s currency. Your store uses ', 'woo-etsy-sync') + S.store_currency + '.' })]),
				card(__('Default category', 'woo-etsy-sync'), __('Used when a product’s category isn’t mapped and it has no eBay category of its own (old WP-Lister categories are reused automatically).', 'woo-etsy-sync'),
					categoryPicker(draft.default_category ? { id: draft.default_category, label: draft.default_category_label } : null, function (v) { set('default_category', v ? v.id : 0); set('default_category_label', v ? v.label : ''); })),
				card(__('Item details', 'woo-etsy-sync'), null, [
					field(__('Condition', 'woo-etsy-sync'), select([{ id: 1000, label: __('New', 'woo-etsy-sync') }, { id: 1500, label: __('New other (see details)', 'woo-etsy-sync') }, { id: 1750, label: __('New with defects', 'woo-etsy-sync') }, { id: 2750, label: __('Like new', 'woo-etsy-sync') }, { id: 3000, label: __('Used', 'woo-etsy-sync') }], draft.condition_id, function (v) { set('condition_id', +v); })),
					field(__('Title template', 'woo-etsy-sync'), txt('title_template'), __('eBay allows <strong>80 characters</strong>. Use <code>{title}</code>, <code>{sku}</code>, <code>{category}</code>. A product’s own eBay title (or old WP-Lister title) always wins.', 'woo-etsy-sync')),
					field(__('Description', 'woo-etsy-sync'), UI.choiceCards([{ value: 'description', label: __('Full description', 'woo-etsy-sync') }, { value: 'short', label: __('Short description', 'woo-etsy-sync') }, { value: 'both', label: __('Both', 'woo-etsy-sync') }], draft.description_source, function (v) { set('description_source', v); }), __('Links and scripts are removed automatically — eBay doesn’t allow them.', 'woo-etsy-sync')),
					field(__('Add to the end of every description', 'woo-etsy-sync'), h('textarea', { rows: 3, value: draft.description_footer, oninput: function (e) { set('description_footer', e.target.value); } })),
					field(__('Photos per listing', 'woo-etsy-sync'), h('div', { style: 'max-width:160px' }, num('max_images', 1, 24))),
					toggle(draft.variation_images, 'Send variation photos', 'Shows the right photo when a buyer picks an option.', function (v) { set('variation_images', v); })
				])
			];
		};
		P.categories = function () {
			if (!S.connected) { return [needConn()]; }
			var holder = h('div', null, h('div', { class: 'wes-skel', style: 'width:50%' }));
			UI.loadTerms().then(function (terms) {
				var byParent = {}, ordered = [];
				terms.categories.forEach(function (c) { (byParent[c.parent] = byParent[c.parent] || []).push(c); });
				(function walk(pid, d) { (byParent[pid] || []).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (c) { c.depth = d; ordered.push(c); walk(c.id, d + 1); }); })(0, 0);
				var cm = draft.category_map && !Array.isArray(draft.category_map) ? draft.category_map : {};
				draft.category_map = cm;
				mount(holder, h('div', { class: 'wes-map' }, ordered.map(function (c) {
					return h('div', { class: 'wes-map__row' }, h('div', { class: 'wes-map__from', style: 'padding-left:' + c.depth * 16 + 'px' }, (c.depth ? '└ ' : '') + c.name, ' ', h('small', { text: '(' + c.count + ')' })), h('div', { class: 'wes-map__arrow' }, icon('arrow')),
						categoryPicker(cm[c.id] || null, function (v) { if (v) { cm[c.id] = v; } else { delete cm[c.id]; } set('category_map', cm); }, c.depth ? __('Same as parent / default', 'woo-etsy-sync') : __('Use default category', 'woo-etsy-sync')));
				})));
			});
			return [card(__('WooCommerce category → eBay category', 'woo-etsy-sync'), __('Search eBay’s categories by describing the item.', 'woo-etsy-sync'), holder)];
		};
		P.policies = function () {
			if (!S.connected) { return [needConn()]; }
			var holder = h('div', null, h('div', { class: 'wes-skel', style: 'width:50%' }));
			loadPolicies(false).then(function (p) {
				mount(holder, [
					!p.shipping.length ? alertBox('warn', __('No business policies found', 'woo-etsy-sync'), __('eBay listings need postage, returns and payment policies. Create them in eBay (<a href="https://', 'woo-etsy-sync') + siteHost() + '/sh/ovw" target="_blank" rel="noopener">Seller Hub</a> → Account → Business policies), then refresh.') : null,
					h('div', { class: 'wes-row--3 wes-row' },
						field(__('Postage policy', 'woo-etsy-sync'), select(p.shipping, draft.shipping_policy, function (v) { set('shipping_policy', v); }, __('Choose…', 'woo-etsy-sync'))),
						field(__('Returns policy', 'woo-etsy-sync'), select(p.returns, draft.return_policy, function (v) { set('return_policy', v); }, __('Choose…', 'woo-etsy-sync'))),
						field(__('Payment policy', 'woo-etsy-sync'), select(p.payment, draft.payment_policy, function (v) { set('payment_policy', v); }, __('Choose…', 'woo-etsy-sync')))
					)
				]);
			}).catch(function (e) { mount(holder, alertBox('error', __('Couldn’t load your eBay policies', 'woo-etsy-sync'), esc(e.message))); });
			return [
				card(__('Business policies', 'woo-etsy-sync'), __('eBay’s postage, returns and payment rules for new listings.', 'woo-etsy-sync'), holder, h('button', { class: 'wes-btn wes-btn--sm', onclick: function () { policiesCache = null; loadPolicies(true).then(drawPanel); } }, icon('refresh'), __('Refresh', 'woo-etsy-sync'))),
				card(__('Item location', 'woo-etsy-sync'), __('Where you post from — shown to buyers.', 'woo-etsy-sync'), h('div', { class: 'wes-row--3 wes-row' }, field(__('Postcode', 'woo-etsy-sync'), txt('postcode')), field(__('Town / area', 'woo-etsy-sync'), txt('location')), field(__('Country code', 'woo-etsy-sync'), txt('country', S.store_country || ''))))
			];
		};
		P.specifics = function () {
			return [
				card(__('Item specifics', 'woo-etsy-sync'), __('eBay requires some specifics (like Brand) in most categories. These are added to every listing; products can add their own on their eBay tab, and “Check with eBay” tells you if anything is missing.', 'woo-etsy-sync'), [
					h('div', { class: 'wes-row' }, field(__('Brand', 'woo-etsy-sync'), txt('brand', 'e.g. ' + (UI.boot.siteName || __('Your shop', 'woo-etsy-sync')) + __(' or Unbranded', 'woo-etsy-sync'))), field('MPN', txt('mpn', __('Does Not Apply', 'woo-etsy-sync')))),
					field(__('Extra specifics for every listing', 'woo-etsy-sync'), h('textarea', { rows: 4, value: draft.custom_specifics, placeholder: __('Material: Cotton\nCountry/Region of Manufacture: ', 'woo-etsy-sync') + (S.store_country || 'US'), oninput: function (e) { set('custom_specifics', e.target.value); } }), __('One per line as <code>Name: Value</code>. Use <code>|</code> for several values.', 'woo-etsy-sync')),
					toggle(draft.use_attributes, 'Use WooCommerce attributes as item specifics', 'Attributes that aren’t used for variations (e.g. Material) are sent too.', function (v) { set('use_attributes', v); })
				]),
				card(__('Product safety (GPSR)', 'woo-etsy-sync'), __('Buyers in the EU must see who made the product. Fill this in if you sell to EU buyers on eBay.', 'woo-etsy-sync'), [
					toggle(draft.gpsr_enabled, 'Add manufacturer details to new listings', null, function (v) { set('gpsr_enabled', v); }),
					h('div', { class: 'wes-row' }, field(__('Company name', 'woo-etsy-sync'), txt('gpsr_company')), field(__('Street', 'woo-etsy-sync'), txt('gpsr_street'))),
					h('div', { class: 'wes-row--3 wes-row' }, field(__('Town', 'woo-etsy-sync'), txt('gpsr_city')), field(__('Postcode', 'woo-etsy-sync'), txt('gpsr_postcode')), field(__('Country code', 'woo-etsy-sync'), txt('gpsr_country', S.store_country || ''))),
					h('div', { class: 'wes-row' }, field(__('Email', 'woo-etsy-sync'), txt('gpsr_email')), field(__('Phone', 'woo-etsy-sync'), txt('gpsr_phone')))
				])
			];
		};
		P.pricing = function () {
			return [card(__('Price on eBay', 'woo-etsy-sync'), __('eBay fees are higher than your own shop’s, so many sellers add a little on top.', 'woo-etsy-sync'), [
				toggle(draft.use_sale_price, 'Use sale prices when a sale is running', null, function (v) { set('use_sale_price', v); }),
				h('div', { class: 'wes-row', style: 'margin-top:12px' },
					field(__('Adjust prices', 'woo-etsy-sync'), select([{ id: 'none', label: __('Same as WooCommerce', 'woo-etsy-sync') }, { id: 'percent', label: __('Add a percentage', 'woo-etsy-sync') }, { id: 'fixed', label: __('Add a fixed amount', 'woo-etsy-sync') }], draft.price_adjust_type, function (v) { set('price_adjust_type', v); drawPanel(); })),
					draft.price_adjust_type !== 'none' ? field(__('Amount (minus to lower)', 'woo-etsy-sync'), h('input', { type: 'number', step: '0.01', value: draft.price_adjust_value, oninput: function (e) { set('price_adjust_value', e.target.value); } })) : h('div')),
				field(__('Round prices', 'woo-etsy-sync'), select([{ id: 'none', label: __('Don’t round', 'woo-etsy-sync') }, { id: '99', label: __('Up to .99', 'woo-etsy-sync') }, { id: 'whole', label: __('To whole numbers', 'woo-etsy-sync') }], draft.price_rounding, function (v) { set('price_rounding', v); }))
			])];
		};
		P.stock = function () {
			var oos = h('button', { class: 'wes-btn wes-btn--sm', disabled: !S.connected, onclick: function () { busy(oos, true, __('Switching on…', 'woo-etsy-sync')); call('/ebay/oos-control', { method: 'POST' }).then(function (r) { toast(r.ok ? __('Switched on in your eBay account', 'woo-etsy-sync') : __('eBay refused – see Activity', 'woo-etsy-sync'), r.ok ? 'ok' : 'error'); refresh().then(drawPanel); }); } }, S.oos_control ? __('Switched on ✓', 'woo-etsy-sync') : __('Switch on now', 'woo-etsy-sync'));
			return [
				card(__('WooCommerce → eBay', 'woo-etsy-sync'), null, [
					toggle(draft.sync_stock_to_ebay, 'Update eBay when stock changes here', 'Website and Etsy sales, refunds and edits update eBay quantities.', function (v) { set('sync_stock_to_ebay', v); }),
					toggle(draft.out_of_stock_control, 'Keep sold-out listings alive', 'Uses eBay’s “out of stock” option so a listing at 0 is hidden, not ended (you keep its sales history and don’t pay to relist).', function (v) { set('out_of_stock_control', v); }),
					h('div', { style: 'margin:6px 0 10px' }, oos),
					h('div', { class: 'wes-row' }, field(__('Quantity for products without stock tracking', 'woo-etsy-sync'), num('untracked_stock_qty', 1, 999)), field(__('Never show more than', 'woo-etsy-sync'), num('max_qty', 1, 9999)))
				]),
				card(__('eBay → WooCommerce', 'woo-etsy-sync'), null, [
					S.wplister_active ? alertBox('warn', __('Paused while WP-Lister is active', 'woo-etsy-sync'), 'WP-Lister may already be taking eBay sales off your stock. Deactivate it first so sales aren’t counted twice.') : null,
					toggle(draft.sync_stock_from_ebay, 'Reduce WooCommerce stock when something sells on eBay', 'eBay orders are checked regularly; Etsy is then updated too.', function (v) { set('sync_stock_from_ebay', v); }),
					field(__('Check eBay for new orders every', 'woo-etsy-sync'), select([{ id: 5, label: '5 minutes' }, { id: 10, label: '10 minutes' }, { id: 15, label: '15 minutes' }, { id: 30, label: '30 minutes' }, { id: 60, label: 'hour' }], draft.poll_minutes, function (v) { set('poll_minutes', +v); }))
				])
			];
		};
		P.behaviour = function () {
			var uf = draft.update_fields;
			function u(k, t, d) { return toggle(uf[k], t, d, function (v) { uf[k] = v; set('update_fields', uf); }); }
			return [
				card(__('When I edit a product', 'woo-etsy-sync'), null, toggle(draft.auto_sync_on_save, 'Update its eBay listing automatically', 'Saving a linked product updates eBay in the background.', function (v) { set('auto_sync_on_save', v); })),
				card(__('What to update on existing listings', 'woo-etsy-sync'), __('Turn things off if you edit them directly on eBay. These also apply to listings linked from WP-Lister.', 'woo-etsy-sync'), [u('title', __('Title', 'woo-etsy-sync')), u('description', __('Description', 'woo-etsy-sync')), u('images', __('Photos', 'woo-etsy-sync')), u('specifics', __('Item specifics', 'woo-etsy-sync')), u('price', __('Prices & variations', 'woo-etsy-sync'), __('Also sends stock.', 'woo-etsy-sync'))]),
				card(__('When a product is deleted', 'woo-etsy-sync'), null, select([{ id: 'end', label: __('End the eBay listing', 'woo-etsy-sync') }, { id: 'nothing', label: __('Leave it alone', 'woo-etsy-sync') }], draft.on_trash, function (v) { set('on_trash', v); }))
			];
		};
		function drawPanel() { mount(panel, (P[current] || P.listing)()); }
		append(el, [pageHead(__('eBay settings', 'woo-etsy-sync'), __('These only affect eBay. Etsy has its own settings.', 'woo-etsy-sync')), h('div', { class: 'wes-settings' }, subnav, panel), savebar]);
		drawNav(); drawPanel();
		var bu = function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
		window.addEventListener('beforeunload', bu);
		return function () { window.removeEventListener('beforeunload', bu); savebar.remove(); };
	}

	/* ------------------------------------------------------------------
	 * Register.
	 * ---------------------------------------------------------------- */
	UI.registerChannel('ebay', {
		label: __('eBay', 'woo-etsy-sync'),
		inUse: function () { return !!(S.connected || S.has_credentials); },
		summary: function () { return { connected: !!S.connected && !S.needs_reconnect, started: !!S.has_credentials, name: S.user_id, linked: (S.stats || {}).linked || 0, errors: (S.stats || {}).errors || 0 }; },
		connected: function () { return !!S.connected && !S.needs_reconnect; },
		linkRunning: function () { return S.link_report && S.link_report.status === 'running'; },
		onTick: function (r) { if (r.ebay_stats) { S.stats = Object.assign({}, S.stats, r.ebay_stats); } if (r.ebay_link_report !== undefined) { S.link_report = r.ebay_link_report; } },
		chip: function () {
			return S.connected
				? h('button', { class: 'wes-shopchip', onclick: function () { go('ebay/settings/connection'); } }, h('span', { class: 'wes-dot ' + (S.needs_reconnect ? 'wes-dot--err' : 'wes-dot--ok') }), S.user_id || __('eBay', 'woo-etsy-sync'))
				: h('button', { class: 'wes-shopchip', onclick: function () { go('ebay'); } }, h('span', { class: 'wes-dot wes-dot--warn' }), __('eBay not connected', 'woo-etsy-sync'));
		},
		tabs: function () {
			var e = (S.stats || {}).errors;
			return [['ebay', __('Overview', 'woo-etsy-sync')], ['ebay/products', __('Products', 'woo-etsy-sync'), e ? h('span', { class: 'wes-badge' }, e) : null], ['ebay/link', __('Link existing listings', 'woo-etsy-sync')], ['ebay/settings', __('Settings', 'woo-etsy-sync')], ['ebay/activity', __('Activity', 'woo-etsy-sync')]];
		},
		render: function (el, args) {
			var v = args[0] || 'overview';
			if (v === 'products') { return viewProducts(el, args.slice(1)); }
			if (v === 'link') { return viewLink(el); }
			if (v === 'settings') { return viewSettings(el, args.slice(1)); }
			if (v === 'activity') { return UI.viewActivity(el, [], 'ebay'); }
			return viewOverview(el);
		}
	});
	if ((BOOT.flash && (BOOT.flash.connected || BOOT.flash.error)) && location.hash.indexOf('#/ebay') !== 0) { location.hash = '#/ebay'; }
	if (/[?&]wes_ebay_(connected|error)=/.test(location.search)) {
		var clean = location.search.replace(/[?&]wes_ebay_(connected|error)=[^&]*/g, '').replace(/^&/, '?');
		history.replaceState(null, '', location.pathname + (clean === '?' ? '' : clean) + location.hash);
	}
})();
