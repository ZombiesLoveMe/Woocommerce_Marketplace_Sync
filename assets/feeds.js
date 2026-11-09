/* Marketplace Sync — "All platforms" hub + shopping/social product feeds. */
(function () {
	'use strict';
	var __ = (window.wp && window.wp.i18n && window.wp.i18n.__) ? window.wp.i18n.__ : function (s) { return s; };
	var UI = window.WES_UI;
	if (!UI) { return; }
	var h = UI.h, icon = UI.icon, call = UI.call, toast = UI.toast, mount = UI.mount, append = UI.append, esc = UI.esc;
	var busy = UI.busy, alertBox = UI.alertBox, field = UI.field, toggle = UI.toggle, select = UI.select;
	var F = (window.WES_FEEDS_BOOT || {}).state || { platforms: [], settings: {}, conflicts: {} };

	function setF(s) { F = s; UI.renderChrome(); }
	function plat(key) { return F.platforms.filter(function (p) { return p.key === key; })[0]; }
	function card(title, sub, body, actions) {
		return h('div', { class: 'wes-card' },
			h('div', { class: 'wes-card__head' }, h('div', null, h('h2', { text: title }), sub ? h('p', { html: sub }) : null), actions ? h('div', { class: 'wes-card__head-actions' }, actions) : null),
			h('div', { class: 'wes-card__body' }, body));
	}
	function tile(key, color, size) {
		var glyphs = { etsy: 'E', ebay: 'e', google: 'G', meta: 'f', pinterest: 'P', tiktok: '♪', microsoft: 'B', other: '+' };
		var initials = glyphs[key] || String(key).charAt(0).toUpperCase();
		return h('span', { class: 'wes-ptile', 'aria-hidden': 'true', style: 'background:' + color + (size ? ';width:' + size + 'px;height:' + size + 'px;font-size:' + Math.round(size / 3) + 'px' : '') }, initials);
	}
	function feedStatus(p) {
		if (!p.enabled) { return { text: __('Not set up', 'woo-etsy-sync'), cls: 'muted' }; }
		if (p.fetched_by_platform) { return { text: __('Collected ', 'woo-etsy-sync') + UI.ago(p.last_fetch), cls: 'ok' }; }
		return { text: __('Waiting for ', 'woo-etsy-sync') + p.label.split(' ')[0] + ' to collect it', cls: 'warn' };
	}

	/* ------------------------------------------------------------------
	 * All platforms hub.
	 * ---------------------------------------------------------------- */
	function viewHub(el) {
		var etsy = UI.etsyState();
		var eb = UI.ext('ebay') && UI.ext('ebay').summary ? UI.ext('ebay').summary() : null;
		var all = [];
		all.push({
			key: 'etsy', label: __('Etsy', 'woo-etsy-sync'), color: '#f1641e', kind: 'sync', short: __('List products and keep stock in step both ways.', 'woo-etsy-sync'),
			started: etsy.connected || etsy.has_credentials, connected: etsy.connected && !etsy.needs_reconnect,
			status: etsy.connected ? (etsy.stats.linked + __(' products linked', 'woo-etsy-sync') + (etsy.stats.errors ? ' · ' + etsy.stats.errors + ' errors' : '')) : (etsy.has_credentials ? __('Keys saved – finish connecting', 'woo-etsy-sync') : ''),
			go: etsy.connected || etsy.settings.setup_complete ? 'dashboard' : 'setup', time: __('≈ 10 min · your own free Etsy key', 'woo-etsy-sync')
		});
		if (eb) {
			all.push({ key: 'ebay', label: __('eBay', 'woo-etsy-sync'), color: '#3665f3', kind: 'sync', short: __('List products and keep stock in step both ways. Takes over from WP-Lister.', 'woo-etsy-sync'),
				started: eb.started, connected: eb.connected, status: eb.connected ? (eb.linked + __(' products linked', 'woo-etsy-sync') + (eb.errors ? ' · ' + eb.errors + ' errors' : '')) : (eb.started ? __('Keys saved – finish connecting', 'woo-etsy-sync') : ''),
				go: 'ebay', time: __('≈ 10 min · your own free eBay keys', 'woo-etsy-sync') });
		}
		F.platforms.forEach(function (p) {
			var st = feedStatus(p);
			all.push({ key: p.key, label: p.label, color: p.color, kind: 'feed', short: p.short, started: p.enabled, connected: p.enabled && p.fetched_by_platform,
				status: p.enabled ? st.text : '', go: 'feeds/' + p.key, time: __('≈ 2 min · copy one link', 'woo-etsy-sync') });
		});
		var mine = all.filter(function (x) { return x.started; });
		var rest = all.filter(function (x) { return !x.started; });

		function bigCard(x) {
			return h('a', { class: 'wes-pcard', href: '#/' + x.go },
				h('div', { class: 'wes-pcard__top' }, tile(x.key, x.color), h('div', { style: 'flex:1;min-width:0' }, h('div', { class: 'wes-pcard__name', text: x.label }),
					h('div', { class: 'wes-pcard__status' }, h('span', { class: 'wes-dot ' + (x.connected ? 'wes-dot--ok' : 'wes-dot--warn') }), x.status || (x.connected ? __('Connected', 'woo-etsy-sync') : __('Set-up not finished', 'woo-etsy-sync')))), icon('arrow')),
				h('div', { class: 'wes-pcard__kind' }, x.kind === 'sync' ? __('Two-way stock sync', 'woo-etsy-sync') : __('Product feed', 'woo-etsy-sync')));
		}
		function addCard(x) {
			return h('div', { class: 'wes-pcard wes-pcard--add' },
				h('div', { class: 'wes-pcard__top' }, tile(x.key, x.color), h('div', { style: 'flex:1;min-width:0' }, h('div', { class: 'wes-pcard__name', text: x.label }), h('div', { class: 'wes-pcard__short', text: x.short }))),
				h('div', { class: 'wes-pcard__foot' }, h('span', { class: 'wes-muted wes-small', text: x.time }), h('a', { class: 'wes-btn wes-btn--primary wes-btn--sm', href: '#/' + x.go }, __('Connect', 'woo-etsy-sync'))));
		}
		var sync = rest.filter(function (x) { return x.kind === 'sync'; });
		var feeds = rest.filter(function (x) { return x.kind === 'feed'; });
		append(el, [
			UI.pageHead(__('Your selling platforms', 'woo-etsy-sync'), __('Connect WooCommerce to everywhere you sell. Your website stays in charge of products and stock.', 'woo-etsy-sync')),
			mine.length ? h('div', null, h('div', { class: 'wes-section-title', text: __('Connected', 'woo-etsy-sync') }), h('div', { class: 'wes-pgrid' }, mine.map(bigCard))) : null,
			h('div', { style: 'margin-top:26px' }, h('div', { class: 'wes-section-title', text: mine.length ? __('Add another platform', 'woo-etsy-sync') : __('Add a platform', 'woo-etsy-sync') })),
			sync.length ? h('div', { class: 'wes-subhead' }, h('strong', null, __('Marketplaces', 'woo-etsy-sync')), h('span', { class: 'wes-muted wes-small' }, __(' – people buy on the marketplace, so stock syncs both ways.', 'woo-etsy-sync'))) : null,
			sync.length ? h('div', { class: 'wes-pgrid' }, sync.map(addCard)) : null,
			feeds.length ? h('div', { class: 'wes-subhead' }, h('strong', null, __('Shopping & social', 'woo-etsy-sync')), h('span', { class: 'wes-muted wes-small' }, __(' – your products show there, and buyers check out on your website.', 'woo-etsy-sync'))) : null,
			feeds.length ? h('div', { class: 'wes-pgrid' }, feeds.map(addCard)) : null,
			h('p', { class: 'wes-footer', html: __('Don’t see yours? Most shopping and comparison sites accept a product feed – use <a href="#/feeds/other">Any other site</a>. Marketplaces like Amazon, OnBuy and TikTok Shop need their own connector.', 'woo-etsy-sync') })
		]);
	}

	/* ------------------------------------------------------------------
	 * Feeds: overview, platform page, settings.
	 * ---------------------------------------------------------------- */
	function summaryCard() {
		var s = F.summary;
		var rebuild = h('button', { class: 'wes-btn wes-btn--sm', onclick: function () { busy(rebuild, true, __('Rebuilding…', 'woo-etsy-sync')); call('/feeds/rebuild', { method: 'POST' }).then(function (st) { setF(st); toast(__('Feed rebuilt', 'woo-etsy-sync')); UI.render(); }).catch(function (e) { busy(rebuild, false); toast(e.message, 'error'); }); } }, icon('refresh'), __('Rebuild now', 'woo-etsy-sync'));
		var prev = h('button', { class: 'wes-btn wes-btn--sm', onclick: previewModal }, icon('eye'), __('Preview products', 'woo-etsy-sync'));
		return card(__('What the platforms see', 'woo-etsy-sync'), s ? __('Rebuilt ', 'woo-etsy-sync') + UI.ago(s.at) + __(' – it updates by itself within a couple of minutes of any product or stock change, and every hour.', 'woo-etsy-sync') : __('The feed is built the first time you turn a platform on.', 'woo-etsy-sync'), [
			s ? h('div', { class: 'wes-grid wes-grid--3' },
				UI.statTile(__('Items in the feed', 'woo-etsy-sync'), s.items, s.items !== s.products ? 'from ' + s.products + __(' products (each variation counts)', 'woo-etsy-sync') : 'products'),
				UI.statTile(__('Left out', 'woo-etsy-sync'), s.skipped, __('hidden, private or excluded', 'woo-etsy-sync')),
				UI.statTile(__('Warnings', 'woo-etsy-sync'), (s.warnings || []).length, (s.warnings || []).length ? __('see below', 'woo-etsy-sync') : __('all good', 'woo-etsy-sync'), null, (s.warnings || []).length ? 'wes-dot--warn' : 'wes-dot--ok')) : null,
			s && s.error ? alertBox('warn', __('Feeds are working, but slowly', 'woo-etsy-sync'), esc(s.error)) : null,
			s && s.warnings && s.warnings.length ? h('ul', { class: 'wes-checklist', style: 'margin-top:12px' }, s.warnings.slice(0, 8).map(function (w) {
				return h('li', null, h('span', { class: 'wes-checklist__icon wes-checklist__icon--todo' }, icon('alert')), h('div', { class: 'wes-checklist__text' }, h('strong', { text: w.name }), h('span', { text: w.msg })), h('a', { class: 'wes-btn wes-btn--sm', href: UI.boot.adminUrl + 'post.php?action=edit&post=' + w.id }, __('Fix', 'woo-etsy-sync')));
			})) : null
		], [prev, rebuild]);
	}

	function previewModal() {
		var body = h('div', null, h('span', { class: 'wes-spinner' }));
		UI.modal({ title: __('Feed preview', 'woo-etsy-sync'), icon: 'eye', body: body, actions: [{ label: __('Close', 'woo-etsy-sync'), primary: true }] });
		call('/feeds/preview').then(function (p) {
			mount(body, [
				h('p', { class: 'wes-muted', style: 'margin-bottom:10px', text: __('First ', 'woo-etsy-sync') + p.items.length + ' of ' + p.total + __(' items, exactly as platforms receive them.', 'woo-etsy-sync') }),
				h('div', { class: 'wes-table-wrap', style: 'max-height:420px;overflow:auto;border:1px solid var(--wes-border);border-radius:8px' }, h('table', { class: 'wes-table' },
					h('thead', null, h('tr', null, h('th'), h('th', null, __('Title', 'woo-etsy-sync')), h('th', null, __('Price', 'woo-etsy-sync')), h('th', null, __('Availability', 'woo-etsy-sync')), h('th', null, 'ID'))),
					h('tbody', null, p.items.map(function (i) {
						return h('tr', null, h('td', null, h('span', { class: 'wes-prod__img', style: i.image_link ? 'background-image:url(' + JSON.stringify(i.image_link) + ')' : '' })),
							h('td', null, h('div', { style: 'font-weight:600', text: i.title }), h('div', { class: 'wes-muted wes-small', text: [i.product_type, i.color, i.size].filter(Boolean).join(' · ') })),
							h('td', { class: 'wes-num' }, i.sale_price ? h('span', null, h('s', { class: 'wes-muted', text: i.price }), ' ', i.sale_price) : i.price),
							h('td', null, h('span', { class: 'wes-pill wes-pill--' + (i.availability === 'in_stock' ? 'ok' : 'warn') }, i.availability.replace('_', ' '))),
							h('td', { class: 'wes-small wes-muted', text: i.id }));
					}))))
			]);
		}).catch(function (e) { mount(body, alertBox('error', __('Preview failed', 'woo-etsy-sync'), esc(e.message))); });
	}

	function viewFeedsOverview(el) {
		var on = F.platforms.filter(function (p) { return p.enabled; });
		append(el, [
			UI.pageHead(__('Shopping & social feeds', 'woo-etsy-sync'), __('Your products on Google, Facebook, Instagram, Pinterest and more – updated automatically.', 'woo-etsy-sync')),
			!F.https ? alertBox('warn', __('Your site isn’t using HTTPS', 'woo-etsy-sync'), __('Most platforms only accept feeds and photos on https:// addresses.', 'woo-etsy-sync')) : null,
			on.length ? card(__('Your feeds', 'woo-etsy-sync'), null, h('div', { class: 'wes-map' }, on.map(function (p) {
				var st = feedStatus(p);
				return h('div', { class: 'wes-feedrow' }, tile(p.key, p.color, 36), h('div', { style: 'flex:1' }, h('strong', { text: p.label }), h('div', { class: 'wes-small' }, h('span', { class: 'wes-dot wes-dot--' + (st.cls === 'ok' ? 'ok' : 'warn') }), ' ', st.text)), h('a', { class: 'wes-btn wes-btn--sm', href: '#/feeds/' + p.key }, __('Open', 'woo-etsy-sync')));
			}))) : alertBox('info', __('No feeds yet', 'woo-etsy-sync'), __('Pick a platform on the <a href="#/platforms">All platforms</a> page to get started.', 'woo-etsy-sync')),
			on.length ? h('div', { style: 'margin-top:16px' }, summaryCard()) : null,
			h('div', { style: 'margin-top:16px' }, card(__('Add another', 'woo-etsy-sync'), null, h('div', { class: 'wes-inline' }, F.platforms.filter(function (p) { return !p.enabled; }).map(function (p) {
				return h('a', { class: 'wes-btn', href: '#/feeds/' + p.key }, tile(p.key, p.color, 20), p.label);
			}))))
		]);
	}

	function viewPlatform(el, key) {
		var p = plat(key);
		if (!p) { UI.go('feeds'); return; }
		var conflict = F.conflicts && F.conflicts[key];
		var st = feedStatus(p);
		var enableBtn = h('button', { class: 'wes-btn wes-btn--primary wes-btn--lg', onclick: function () {
			busy(enableBtn, true, __('Creating your feed…', 'woo-etsy-sync'));
			call('/feeds/' + key + '/enable', { method: 'POST' }).then(function (s) { setF(s); toast(p.label + ' feed is ready'); UI.render(); }).catch(function (e) { busy(enableBtn, false); toast(e.message, 'error'); });
		} }, __('Create my ', 'woo-etsy-sync') + p.label + ' feed');

		function stepRow(n, done, title, body) {
			return h('div', { class: 'wes-bigstep' + (done ? ' is-done' : '') }, h('div', { class: 'wes-bigstep__n' }, done ? icon('check') : String(n)), h('div', { class: 'wes-bigstep__body' }, h('h3', { text: title }), body));
		}
		var steps = [
			stepRow(1, p.enabled, p.enabled ? __('Feed created', 'woo-etsy-sync') : __('Create your feed', 'woo-etsy-sync'), p.enabled ? h('p', { class: 'wes-muted', text: __('Your products (with live prices and stock) are published at a private link.', 'woo-etsy-sync') }) : h('div', null, h('p', { class: 'wes-muted', style: 'margin-bottom:12px', text: __('One click. Nothing is sent anywhere until you paste the link into ', 'woo-etsy-sync') + p.label + '.' }), enableBtn)),
			stepRow(2, p.fetched_by_platform, __('Copy your private feed link', 'woo-etsy-sync'), p.enabled ? h('div', null, UI.copyRow(p.url), key === 'other' ? h('div', { style: 'margin-top:8px' }, h('div', { class: 'wes-small wes-muted', text: __('Spreadsheet (CSV) version, if the site asks for one:', 'woo-etsy-sync') }), UI.copyRow(p.csv_url)) : null) : h('p', { class: 'wes-muted', text: __('Appears here after step 1.', 'woo-etsy-sync') })),
			stepRow(3, p.fetched_by_platform, __('Paste it into ', 'woo-etsy-sync') + p.label, h('div', null,
				h('ol', { class: 'wes-simple-steps' }, p.steps.map(function (t) { return h('li', { html: t }); })),
				p.open ? h('a', { class: 'wes-btn', href: p.open, target: '_blank', rel: 'noopener', style: 'margin-top:10px' }, __('Open ', 'woo-etsy-sync') + p.label, icon('external')) : null)),
			stepRow(4, p.fetched_by_platform, __('Done – it keeps itself up to date', 'woo-etsy-sync'), h('p', { class: 'wes-muted', html: p.enabled ? (p.fetched_by_platform ? '✓ ' + esc(p.label) + __(' collected your feed ', 'woo-etsy-sync') + UI.ago(p.last_fetch) + ' (' + p.fetches + ' time' + (p.fetches === 1 ? '' : 's') + ').' : __('This turns green once ', 'woo-etsy-sync') + esc(p.label) + __(' has collected the feed for the first time – usually within a few hours of step 3.', 'woo-etsy-sync')) + __(' Price and stock changes go out on the next collection (', 'woo-etsy-sync') + esc(p.refresh.toLowerCase()) + ').' : __('Your website is the only place you manage products and stock.', 'woo-etsy-sync') }))
		];
		var priceIn = h('input', { type: 'number', step: '0.5', value: p.price_adjust, style: 'max-width:120px' });
		var opts = p.enabled ? card(__('Options for ', 'woo-etsy-sync') + p.label, null, [
			field(__('Price adjustment for this platform (%)', 'woo-etsy-sync'), h('div', { class: 'wes-input-group', style: 'max-width:320px' }, priceIn, h('button', { class: 'wes-btn', onclick: function (e) {
				var b = e.currentTarget; busy(b, true, __('Saving…', 'woo-etsy-sync'));
				var po = {}; po[key] = { price_adjust: priceIn.value };
				call('/feeds/settings', { method: 'POST', data: { platform_options: po } }).then(function (s) { setF(s); toast(__('Saved – applies on the next rebuild', 'woo-etsy-sync')); busy(b, false); });
			} }, __('Save', 'woo-etsy-sync'))), __('Leave at 0 to use your website prices (recommended – Google checks they match your site).', 'woo-etsy-sync')),
			h('div', { class: 'wes-inline', style: 'margin-top:8px' },
				h('button', { class: 'wes-btn wes-btn--sm', onclick: function () { UI.confirmBox(__('Make a new link?', 'woo-etsy-sync'), __('The old link stops working straight away, so you’ll need to paste the new one into ', 'woo-etsy-sync') + p.label + '.', __('Make new link', 'woo-etsy-sync')).then(function (ok) { if (ok) { call('/feeds/' + key + '/new-link', { method: 'POST' }).then(function (s) { setF(s); UI.render(); }); } }); } }, icon('refresh'), __('New private link', 'woo-etsy-sync')),
				h('button', { class: 'wes-btn wes-btn--sm wes-btn--danger', onclick: function () { UI.confirmBox(__('Turn off this feed?', 'woo-etsy-sync'), p.label + __(' will stop receiving updates (and will eventually remove the products).', 'woo-etsy-sync'), __('Turn off', 'woo-etsy-sync'), true).then(function (ok) { if (ok) { call('/feeds/' + key + '/disable', { method: 'POST' }).then(function (s) { setF(s); UI.render(); }); } }); } }, __('Turn off', 'woo-etsy-sync')))
		]) : null;

		append(el, [
			h('div', { class: 'wes-page-head' }, tile(p.key, p.color, 48), h('div', null, h('h1', { text: p.label }), h('p', { text: p.short })), h('div', { class: 'wes-page-head__actions' }, h('span', { class: 'wes-pill wes-pill--' + st.cls }, st.text))),
			conflict ? alertBox('warn', __('You already have a plugin for this', 'woo-etsy-sync'), esc(conflict)) : null,
			!F.https ? alertBox('warn', __('Your site isn’t using HTTPS', 'woo-etsy-sync'), esc(p.label) + __(' needs https:// links for your feed and photos.', 'woo-etsy-sync')) : null,
			h('div', { class: 'wes-card' }, h('div', { class: 'wes-card__body' }, steps)),
			p.enabled ? h('div', { style: 'margin-top:16px' }, summaryCard()) : null,
			opts ? h('div', { style: 'margin-top:16px' }, opts) : null
		]);
	}

	function viewSettings(el) {
		var d = JSON.parse(JSON.stringify(F.settings));
		if (Array.isArray(d.category_map)) { d.category_map = {}; }
		var holder = h('div', null, h('div', { class: 'wes-skel', style: 'width:50%' }));
		var suggestions = h('datalist', { id: 'wes-gcats' }, [
			'Apparel & Accessories', 'Apparel & Accessories > Clothing', 'Apparel & Accessories > Jewelry', 'Apparel & Accessories > Shoes', 'Apparel & Accessories > Handbags, Wallets & Cases',
			'Animals & Pet Supplies > Pet Supplies', 'Arts & Entertainment > Hobbies & Creative Arts', 'Arts & Entertainment > Hobbies & Creative Arts > Arts & Crafts',
			'Baby & Toddler', 'Business & Industrial', 'Cameras & Optics', 'Electronics', 'Electronics > Computers', 'Electronics > Communications > Telephony > Mobile Phone Accessories',
			'Food, Beverages & Tobacco > Food Items', 'Food, Beverages & Tobacco > Beverages', 'Furniture', 'Hardware > Tools', 'Health & Beauty > Personal Care > Cosmetics', 'Health & Beauty > Health Care',
			'Home & Garden > Decor', 'Home & Garden > Kitchen & Dining', 'Home & Garden > Lawn & Garden', 'Home & Garden > Lighting', 'Home & Garden > Linens & Bedding',
			'Luggage & Bags', 'Media > Books', 'Media > Music & Sound Recordings', 'Office Supplies', 'Religious & Ceremonial', 'Software', 'Sporting Goods', 'Sporting Goods > Outdoor Recreation',
			'Toys & Games', 'Toys & Games > Toys', 'Toys & Games > Games', 'Vehicles & Parts > Vehicle Parts & Accessories'
		].map(function (c) { return h('option', { value: c }); }));
		var save = h('button', { class: 'wes-btn wes-btn--primary', onclick: function () {
			busy(save, true, __('Saving…', 'woo-etsy-sync'));
			call('/feeds/settings', { method: 'POST', data: d }).then(function (s) { setF(s); toast(__('Feed settings saved – rebuilding shortly', 'woo-etsy-sync')); busy(save, false); }).catch(function (e) { busy(save, false); toast(e.message, 'error'); });
		} }, __('Save feed settings', 'woo-etsy-sync'));
		UI.loadTerms().then(function (t) {
			var byParent = {}, ordered = [];
			t.categories.forEach(function (c) { (byParent[c.parent] = byParent[c.parent] || []).push(c); });
			(function walk(pid, dp) { (byParent[pid] || []).sort(function (a, b) { return a.name.localeCompare(b.name); }).forEach(function (c) { c.depth = dp; ordered.push(c); walk(c.id, dp + 1); }); })(0, 0);
			var ex = {}; (d.exclude_categories || []).forEach(function (i) { ex[i] = true; });
			mount(holder, h('div', { class: 'wes-map' }, ordered.map(function (c) {
				var inp = h('input', { type: 'text', list: 'wes-gcats', value: d.category_map[c.id] || '', placeholder: c.depth ? __('Same as parent', 'woo-etsy-sync') : __('Use the default', 'woo-etsy-sync'), oninput: function () { if (inp.value) { d.category_map[c.id] = inp.value; } else { delete d.category_map[c.id]; } } });
				var cb = h('input', { type: 'checkbox', checked: !!ex[c.id], onchange: function () { ex[c.id] = cb.checked; d.exclude_categories = Object.keys(ex).filter(function (k) { return ex[k]; }).map(Number); } });
				return h('div', { class: 'wes-map__row' }, h('div', { class: 'wes-map__from', style: 'padding-left:' + c.depth * 16 + 'px' }, (c.depth ? '└ ' : '') + c.name, ' ', h('small', { text: '(' + c.count + ')' })), h('div', { class: 'wes-map__arrow' }, icon('arrow')),
					h('div', { class: 'wes-stack', style: 'gap:6px' }, inp, h('label', { class: 'wes-small wes-muted' }, cb, __(' Leave this category out of feeds', 'woo-etsy-sync'))));
			})));
		});
		append(el, [
			UI.pageHead(__('Feed settings', 'woo-etsy-sync'), __('Shared by every shopping and social platform.', 'woo-etsy-sync')),
			suggestions,
			h('div', { class: 'wes-stack' },
				card(__('Product details', 'woo-etsy-sync'), null, [
					h('div', { class: 'wes-row' }, field(__('Brand', 'woo-etsy-sync'), h('input', { type: 'text', value: d.brand, oninput: function (e) { d.brand = e.target.value; } }), __('Your shop or brand name. Handmade items without barcodes are sent as “no identifier”, which is correct.', 'woo-etsy-sync')),
						field(__('Condition', 'woo-etsy-sync'), select([{ id: 'new', label: __('New', 'woo-etsy-sync') }, { id: 'refurbished', label: __('Refurbished', 'woo-etsy-sync') }, { id: 'used', label: __('Used', 'woo-etsy-sync') }], d.condition, function (v) { d.condition = v; }))),
					field(__('Variations', 'woo-etsy-sync'), UI.choiceCards([{ value: 'each', label: __('Each variation is its own item (recommended)', 'woo-etsy-sync'), desc: __('Exact price, photo and stock for every colour/size.', 'woo-etsy-sync') }, { value: 'parent', label: __('One item per product', 'woo-etsy-sync'), desc: __('Smaller feed; shows the lowest price.', 'woo-etsy-sync') }], d.variations, function (v) { d.variations = v; })),
					field(__('Description', 'woo-etsy-sync'), UI.choiceCards([{ value: 'description', label: __('Full description', 'woo-etsy-sync') }, { value: 'short', label: __('Short description', 'woo-etsy-sync') }], d.description_source, function (v) { d.description_source = v; }), __('Formatting and links are removed automatically.', 'woo-etsy-sync')),
					toggle(d.include_out_of_stock, 'Include out-of-stock items', 'They show as “out of stock” instead of disappearing, so they come back instantly when restocked.', function (v) { d.include_out_of_stock = v; })
				]),
				card(__('Google product categories', 'woo-etsy-sync'), __('Optional but helps Google and Meta show your products in the right places. Start typing for suggestions.', 'woo-etsy-sync'), [
					field(__('Default category', 'woo-etsy-sync'), h('input', { type: 'text', list: 'wes-gcats', value: d.google_category, placeholder: __('e.g. Home & Garden > Decor', 'woo-etsy-sync'), oninput: function (e) { d.google_category = e.target.value; } })),
					h('div', { class: 'wes-section-title', style: 'margin-top:16px', text: __('By WooCommerce category', 'woo-etsy-sync') }), holder
				]),
				h('div', null, save)
			)
		]);
	}

	/* ------------------------------------------------------------------ */
	UI.registerChannel('platforms', {
		label: __('All platforms', 'woo-etsy-sync'),
		connected: function () { return true; },
		render: function (el) { return viewHub(el); }
	});
	UI.registerChannel('feeds', {
		label: __('Shopping feeds', 'woo-etsy-sync'),
		inUse: function () { return F.platforms.some(function (p) { return p.enabled; }); },
		connected: function () { var on = F.platforms.filter(function (p) { return p.enabled; }); return on.length > 0 && on.every(function (p) { return p.fetched_by_platform; }); },
		chip: function () {
			var on = F.platforms.filter(function (p) { return p.enabled; }).length;
			return h('a', { class: 'wes-shopchip', href: '#/platforms' }, h('span', { class: 'wes-dot ' + (on ? 'wes-dot--ok' : 'wes-dot--warn') }), on + ' feed' + (on === 1 ? '' : 's') + ' on');
		},
		tabs: function () { return [['feeds', __('Overview', 'woo-etsy-sync')], ['feeds/settings', __('Feed settings', 'woo-etsy-sync')], ['feeds/activity', __('Activity', 'woo-etsy-sync')]]; },
		render: function (el, args) {
			var v = args[0] || 'overview';
			if (v === 'settings') { return viewSettings(el); }
			if (v === 'activity') { return UI.viewActivity(el, [], 'feeds'); }
			if (v !== 'overview') { return viewPlatform(el, v); }
			return viewFeedsOverview(el);
		}
	});
})();
