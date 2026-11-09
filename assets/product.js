/* Marketplace Sync — product editor tabs. */
jQuery(function ($) {
	'use strict';
	var __ = (window.wp && window.wp.i18n && window.wp.i18n.__) ? window.wp.i18n.__ : function (s) { return s; };
	var $panel = $('#wes_etsy_panel');
	if (!$panel.length || !window.wp || !wp.apiFetch) { return; }
	var id = $panel.data('product');
	var NS = '/woo-etsy-sync/v1';
	var $err = $panel.find('[data-wes-error]');
	var $msg = $panel.find('[data-wes-msg]');
	var $pre = $panel.find('[data-wes-preview]');

	function show($el, text) { $el.text(text).prop('hidden', !text); }
	function busy($btn, on, label) {
		if (on) { $btn.data('label', $btn.text()).prop('disabled', true).text(label || __('Working…', 'woo-etsy-sync')); }
		else { $btn.prop('disabled', false).text($btn.data('label')); }
	}
	function call(path, method, data) {
		return wp.apiFetch({ path: NS + path, method: method || 'GET', data: data });
	}

	$panel.on('click', '[data-wes-action]', function () {
		var $b = $(this);
		var action = $b.data('wes-action');
		show($err, ''); show($msg, '');
		if (action === 'sync') {
			busy($b, true, __('Sending to Etsy…', 'woo-etsy-sync'));
			call('/products/' + id + '/sync-now', 'POST').then(function (r) {
				show($msg, (r.result.created ? __('Listed on Etsy', 'woo-etsy-sync') : __('Updated on Etsy', 'woo-etsy-sync')) + __(' — listing ', 'woo-etsy-sync') + r.result.listing_id + '. Reload the page to refresh the status.');
			}).catch(function (e) { show($err, e.message || __('Sync failed', 'woo-etsy-sync')); }).finally(function () { busy($b, false); });
		}
		if (action === 'preview') {
			busy($b, true, __('Loading…', 'woo-etsy-sync'));
			call('/products/' + id + '/preview').then(function (p) {
				var lines = [];
				if (p.problems && p.problems.length) { lines.push('⚠ ' + p.problems.join('\n⚠ '), ''); }
				var f = p.fields || {};
				lines.push(__('Title: ', 'woo-etsy-sync') + (f.title || ''), __('Price: ', 'woo-etsy-sync') + (f.price || ''), __('Quantity: ', 'woo-etsy-sync') + (f.quantity || ''), __('Tags: ', 'woo-etsy-sync') + (f.tags || []).join(', '), __('Photos: ', 'woo-etsy-sync') + (p.images || 0), '', (f.description || ''));
				var inv = (p.inventory && p.inventory.products) || [];
				if (inv.length > 1) {
					lines.push('', __('Variations:', 'woo-etsy-sync'));
					inv.forEach(function (v) { var o = v.offerings[0]; lines.push(' • ' + v.property_values.map(function (pv) { return pv.values[0]; }).join(' / ') + ' — ' + o.price + ' × ' + (o.is_enabled ? o.quantity : 'off') + (v.sku ? ' (' + v.sku + ')' : '')); });
				}
				$pre.text(lines.join('\n')).prop('hidden', false);
			}).catch(function (e) { show($err, e.message); }).finally(function () { busy($b, false); });
		}
		if (action === 'unlink') {
			if (!window.confirm(__('Stop syncing this product with its Etsy listing? (The listing stays on Etsy.)', 'woo-etsy-sync'))) { return; }
			call('/products/' + id + '/unlink', 'POST', {}).then(function () { show($msg, __('Unlinked. Reload the page to refresh.', 'woo-etsy-sync')); }).catch(function (e) { show($err, e.message); });
		}
		if (action === 'link') {
			var val = $panel.find('[data-wes-link-input]').val();
			busy($b, true, __('Linking…', 'woo-etsy-sync'));
			call('/products/' + id + '/link', 'POST', { listing: val }).then(function () { show($msg, __('Linked! Stock will now sync. Reload the page to refresh.', 'woo-etsy-sync')); }).catch(function (e) { show($err, e.message); }).finally(function () { busy($b, false); });
		}
	});

	// Etsy category search.
	var tax = null, $search = $panel.find('[data-wes-tax-search]'), $results = $panel.find('[data-wes-tax-results]'), t;
	$search.on('focus', function () {
		if (tax) { return; }
		call('/taxonomy').then(function (d) { tax = d; }).catch(function (e) { $results.text(e.message).prop('hidden', false); });
	});
	$search.on('input', function () {
		clearTimeout(t);
		t = setTimeout(function () {
			var q = $search.val().toLowerCase().split(/\s+/).filter(Boolean);
			if (!tax || !q.length) { $results.prop('hidden', true); return; }
			var out = tax.filter(function (n) { var p = n.path.toLowerCase(); return q.every(function (w) { return p.indexOf(w) !== -1; }); }).slice(0, 40);
			$results.empty();
			out.forEach(function (n) {
				var parts = n.path.split(' › '), last = parts.pop();
				$('<div/>').append($('<strong/>').text(last)).append($('<small/>').text(parts.join(' › '))).on('mousedown', function (e) {
					e.preventDefault();
					$('#_wes_taxonomy_id').val(n.id); $('#_wes_taxonomy_label').val(n.path);
					$search.val('').attr('placeholder', n.path); $results.prop('hidden', true);
				}).appendTo($results);
			});
			$results.prop('hidden', !out.length);
		}, 200);
	}).on('blur', function () { setTimeout(function () { $results.prop('hidden', true); }, 150); });
	$panel.on('click', '[data-wes-tax-clear]', function () {
		$('#_wes_taxonomy_id').val(''); $('#_wes_taxonomy_label').val('');
		$search.attr('placeholder', __('Use default (type to search Etsy categories…)', 'woo-etsy-sync')); $(this).remove();
	});
});

/* eBay tab. */
jQuery(function ($) {
	'use strict';
	var $panel = $('#wes_ebay_panel');
	if (!$panel.length || !window.wp || !wp.apiFetch) { return; }
	var id = $panel.data('product');
	var NS = '/woo-etsy-sync/v1/ebay';
	var $err = $panel.find('[data-wes-error]'), $msg = $panel.find('[data-wes-msg]'), $pre = $panel.find('[data-wes-preview]');
	function show($el, text) { $el.text(text).prop('hidden', !text); }
	function busy($b, on, label) { if (on) { $b.data('label', $b.text()).prop('disabled', true).text(label); } else { $b.prop('disabled', false).text($b.data('label')); } }
	function call(path, method, data) { return wp.apiFetch({ path: NS + path, method: method || 'GET', data: data }); }
	$panel.on('click', '[data-wes-action]', function () {
		var $b = $(this), action = $b.data('wes-action');
		show($err, ''); show($msg, '');
		if (action === 'sync') {
			busy($b, true, __('Sending to eBay…', 'woo-etsy-sync'));
			call('/products/' + id + '/sync-now', 'POST').then(function (r) { show($msg, (r.result.created ? __('Listed on eBay', 'woo-etsy-sync') : __('Updated on eBay', 'woo-etsy-sync')) + __(' — item ', 'woo-etsy-sync') + r.result.item_id + '. Reload the page to refresh.'); })
				.catch(function (e) { show($err, e.message || __('Sync failed', 'woo-etsy-sync')); }).finally(function () { busy($b, false); });
		}
		if (action === 'preview') {
			busy($b, true, __('Asking eBay…', 'woo-etsy-sync'));
			call('/products/' + id + '/preview?verify=1').then(function (p) {
				var it = p.item || {}, lines = [];
				if (p.problems && p.problems.length) { lines.push('⚠ ' + p.problems.join('\n⚠ '), ''); }
				else if (p.ebay_ok) { lines.push(__('✓ eBay accepted this listing', 'woo-etsy-sync') + (p.fees ? __(' (estimated fees ', 'woo-etsy-sync') + p.fees + ')' : '') + '.', ''); }
				lines.push(__('Title: ', 'woo-etsy-sync') + (it.title || '') + ' (' + (it.title || '').length + '/80)', __('Category: ', 'woo-etsy-sync') + (it.category || ''), __('Photos: ', 'woo-etsy-sync') + (it.images || 0));
				(it.specifics || []).forEach(function (s) { lines.push(s.Name + ': ' + [].concat(s.Value).join(', ')); });
				if (it.variations && it.variations.length) { lines.push('', it.variations.length + ' variations'); }
				$pre.text(lines.join('\n')).prop('hidden', false);
			}).catch(function (e) { show($err, e.message); }).finally(function () { busy($b, false); });
		}
		if (action === 'unlink') {
			if (!window.confirm(__('Stop syncing this product with its eBay listing? (The listing stays live.)', 'woo-etsy-sync'))) { return; }
			call('/products/' + id + '/unlink', 'POST', {}).then(function () { show($msg, __('Unlinked. Reload the page to refresh.', 'woo-etsy-sync')); }).catch(function (e) { show($err, e.message); });
		}
		if (action === 'link') {
			busy($b, true, __('Linking…', 'woo-etsy-sync'));
			call('/products/' + id + '/link', 'POST', { item: $panel.find('[data-wes-link-input]').val() }).then(function () { show($msg, __('Linked! Stock will now sync. Reload the page to refresh.', 'woo-etsy-sync')); })
				.catch(function (e) { show($err, e.message); }).finally(function () { busy($b, false); });
		}
	});
});
