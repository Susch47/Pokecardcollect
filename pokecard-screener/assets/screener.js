/* PokéCard Screener – Frontend (ohne Abhängigkeiten) */
(function () {
	'use strict';

	var CFG = window.PCS_CONFIG || {};
	var root = document.getElementById('pcs-app');
	if (!root) return;

	var CONDITIONS = [
		{ key: 'NM', label: 'Near Mint (NM)', factor: 1.0 },
		{ key: 'EX', label: 'Excellent (EX)', factor: 0.85 },
		{ key: 'GD', label: 'Good (GD)', factor: 0.7 },
		{ key: 'LP', label: 'Light Played (LP)', factor: 0.55 },
		{ key: 'PL', label: 'Played (PL)', factor: 0.4 },
		{ key: 'PO', label: 'Poor (PO)', factor: 0.25 }
	];

	var PLATFORMS = {
		kleinanzeigen: { label: 'Kleinanzeigen.de', currency: 'EUR', symbol: '€', rate: 1, url: 'https://www.kleinanzeigen.de/' },
		tutti: { label: 'tutti.ch', currency: 'CHF', symbol: 'CHF', rate: CFG.eurToChf || 1, url: 'https://www.tutti.ch/de' }
	};

	var state = {
		sets: [],
		setKey: '',
		cards: [],
		collection: {},
		filters: { q: '', rarity: '', type: '', owned: 'all', min: '', max: '', sort: 'number' }
	};

	// ---------- Helpers ----------
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') node.textContent = v;
			else if (k === 'class') node.className = v;
			else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), v);
			else if (k in node && typeof v !== 'string') node[k] = v;
			else node.setAttribute(k, v === true ? '' : v);
		});
		(children || []).forEach(function (c) {
			if (c === null || c === undefined) return;
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function api(path, opts) {
		opts = opts || {};
		return fetch(CFG.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) {
			return r.json().then(function (data) {
				if (!r.ok) throw new Error((data && data.message) || 'Fehler ' + r.status);
				return data;
			});
		});
	}

	function money(v, symbol) {
		if (v === null || v === undefined) return '–';
		return v.toFixed(2).replace('.', ',') + ' ' + (symbol || '€');
	}

	function qty(id) { return state.collection[id] || 0; }

	function trend(card) {
		var p = card.cardmarket || {};
		return p.trend != null ? p.trend : (p.avg30 != null ? p.avg30 : p.avg);
	}

	// ---------- Layout ----------
	var ui = {};

	function buildLayout() {
		root.innerHTML = '';

		ui.setSelect = el('select', { 'aria-label': 'Set', onchange: function () { loadSet(this.value); } });
		ui.search = el('input', { type: 'search', placeholder: 'Name oder Nummer …', oninput: function () { state.filters.q = this.value; render(); } });
		ui.rarity = el('select', { 'aria-label': 'Seltenheit', onchange: function () { state.filters.rarity = this.value; render(); } });
		ui.type = el('select', { 'aria-label': 'Typ', onchange: function () { state.filters.type = this.value; render(); } });
		ui.owned = el('select', { 'aria-label': 'Besitz', onchange: function () { state.filters.owned = this.value; render(); } }, [
			el('option', { value: 'all', text: 'Alle Karten' }),
			el('option', { value: 'owned', text: 'Nur in Sammlung' }),
			el('option', { value: 'missing', text: 'Nur fehlende' }),
			el('option', { value: 'dupes', text: 'Doppelte (zum Verkauf)' })
		]);
		ui.min = el('input', { type: 'number', min: '0', step: '0.5', placeholder: 'Preis min €', oninput: function () { state.filters.min = this.value; render(); } });
		ui.max = el('input', { type: 'number', min: '0', step: '0.5', placeholder: 'Preis max €', oninput: function () { state.filters.max = this.value; render(); } });
		ui.sort = el('select', { 'aria-label': 'Sortierung', onchange: function () { state.filters.sort = this.value; render(); } }, [
			el('option', { value: 'number', text: 'Sortieren: Nummer' }),
			el('option', { value: 'price-desc', text: 'Preis absteigend' }),
			el('option', { value: 'price-asc', text: 'Preis aufsteigend' }),
			el('option', { value: 'name', text: 'Name A–Z' })
		]);

		ui.stats = el('div', { class: 'pcs-stats' });
		ui.status = el('div', { class: 'pcs-status', role: 'status' });
		ui.grid = el('div', { class: 'pcs-grid' });

		root.appendChild(el('div', { class: 'pcs-head' }, [
			el('div', { class: 'pcs-set' }, [el('label', { text: 'Set ' }, [ui.setSelect])]),
			el('div', { class: 'pcs-user' }, [
				'Eingeloggt als ' + (CFG.userName || '') + ' · ',
				el('a', { href: CFG.logoutUrl, text: 'Abmelden' })
			])
		]));
		root.appendChild(el('div', { class: 'pcs-filters' }, [ui.search, ui.rarity, ui.type, ui.owned, ui.min, ui.max, ui.sort]));
		root.appendChild(ui.stats);
		root.appendChild(ui.status);
		root.appendChild(ui.grid);
		root.appendChild(el('p', { class: 'pcs-foot', text: 'Kartendaten & Cardmarket-Preise (EUR) via pokemontcg.io. Preise sind Richtwerte ohne Gewähr.' }));
	}

	function fillSelect(select, values, allLabel) {
		select.innerHTML = '';
		select.appendChild(el('option', { value: '', text: allLabel }));
		values.forEach(function (v) { select.appendChild(el('option', { value: v, text: v })); });
	}

	// ---------- Data ----------
	function init() {
		buildLayout();
		ui.status.textContent = 'Lade Sets …';
		Promise.all([api('/sets'), api('/collection')]).then(function (res) {
			state.sets = res[0];
			state.collection = res[1] || {};
			state.sets.forEach(function (s) { ui.setSelect.appendChild(el('option', { value: s.key, text: s.label })); });
			if (state.sets.length) loadSet(state.sets[0].key);
			else ui.status.textContent = 'Keine Sets konfiguriert.';
		}).catch(showError);
	}

	function loadSet(key) {
		state.setKey = key;
		ui.setSelect.value = key;
		state.cards = [];
		ui.grid.innerHTML = '';
		ui.stats.innerHTML = '';
		ui.status.classList.remove('is-error');
		ui.status.textContent = 'Lade Karten …';
		api('/sets/' + encodeURIComponent(key) + '/cards').then(function (cards) {
			if (state.setKey !== key) return;
			state.cards = cards;
			var rarities = {}, types = {};
			cards.forEach(function (c) {
				rarities[c.rarity] = 1;
				(c.types.length ? c.types : [c.supertype]).forEach(function (t) { if (t) types[t] = 1; });
			});
			fillSelect(ui.rarity, Object.keys(rarities).sort(), 'Alle Seltenheiten');
			fillSelect(ui.type, Object.keys(types).sort(), 'Alle Typen');
			state.filters.rarity = state.filters.type = '';
			ui.status.textContent = '';
			render();
		}).catch(showError);
	}

	function setQty(card, n) {
		var prev = qty(card.id);
		n = Math.max(0, Math.min(999, n));
		if (n) state.collection[card.id] = n; else delete state.collection[card.id];
		render();
		api('/collection', { method: 'POST', body: { cardId: card.id, qty: n } }).then(function (data) {
			state.collection = data || {};
		}).catch(function (e) {
			if (prev) state.collection[card.id] = prev; else delete state.collection[card.id];
			render();
			showError(e);
		});
	}

	function showError(e) {
		ui.status.textContent = '⚠ ' + (e && e.message ? e.message : e);
		ui.status.classList.add('is-error');
	}

	// ---------- Rendering ----------
	function filtered() {
		var f = state.filters, q = f.q.trim().toLowerCase();
		var min = f.min === '' ? null : parseFloat(f.min);
		var max = f.max === '' ? null : parseFloat(f.max);
		var list = state.cards.filter(function (c) {
			var n = qty(c.id), p = trend(c);
			if (q && c.name.toLowerCase().indexOf(q) === -1 && c.number.toLowerCase() !== q) return false;
			if (f.rarity && c.rarity !== f.rarity) return false;
			if (f.type && c.types.indexOf(f.type) === -1 && c.supertype !== f.type) return false;
			if (f.owned === 'owned' && !n) return false;
			if (f.owned === 'missing' && n) return false;
			if (f.owned === 'dupes' && n < 2) return false;
			if (min !== null && (p == null || p < min)) return false;
			if (max !== null && (p == null || p > max)) return false;
			return true;
		});
		var byPrice = function (c) { var p = trend(c); return p == null ? -1 : p; };
		var sorters = {
			'number': function (a, b) { return a.numberSort - b.numberSort || a.number.localeCompare(b.number); },
			'price-desc': function (a, b) { return byPrice(b) - byPrice(a); },
			'price-asc': function (a, b) { return byPrice(a) - byPrice(b); },
			'name': function (a, b) { return a.name.localeCompare(b.name, 'de'); }
		};
		return list.sort(sorters[f.sort] || sorters.number);
	}

	function render() {
		renderStats();
		var list = filtered();
		ui.grid.innerHTML = '';
		if (!list.length && state.cards.length) {
			ui.grid.appendChild(el('p', { class: 'pcs-empty', text: 'Keine Karten für diese Filter.' }));
			return;
		}
		var frag = document.createDocumentFragment();
		list.forEach(function (c) { frag.appendChild(cardTile(c)); });
		ui.grid.appendChild(frag);
	}

	function renderStats() {
		var total = state.cards.length, owned = 0, value = 0, missingValue = 0;
		state.cards.forEach(function (c) {
			var n = qty(c.id), p = trend(c) || 0;
			if (n) { owned++; value += p * n; } else { missingValue += p; }
		});
		var pct = total ? Math.round(owned / total * 100) : 0;
		ui.stats.innerHTML = '';
		[
			['Gesammelt', owned + ' / ' + total + ' (' + pct + ' %)'],
			['Wert Sammlung', money(value)],
			['Wert fehlende Karten', money(missingValue)]
		].forEach(function (s) {
			ui.stats.appendChild(el('div', { class: 'pcs-stat' }, [el('span', { text: s[0] }), el('strong', { text: s[1] })]));
		});
		var bar = el('div', { class: 'pcs-progress', role: 'progressbar', 'aria-valuenow': String(pct), 'aria-valuemin': '0', 'aria-valuemax': '100' }, [el('span')]);
		bar.firstChild.style.width = pct + '%';
		ui.stats.appendChild(bar);
	}

	function cardTile(c) {
		var n = qty(c.id), p = c.cardmarket || {};
		return el('article', { class: 'pcs-card' + (n ? ' is-owned' : '') }, [
			el('button', { class: 'pcs-img', type: 'button', title: 'Grösser anzeigen', onclick: function () { openImage(c); } }, [
				el('img', { src: c.image, alt: c.name, loading: 'lazy', width: '245', height: '342' })
			]),
			el('div', { class: 'pcs-meta' }, [
				el('h3', { text: c.name }),
				el('div', { class: 'pcs-sub', text: '#' + c.number + (c.printed ? '/' + c.printed : '') + ' · ' + c.rarity }),
				el('div', { class: 'pcs-price' }, [
					p.url ? el('a', { href: p.url, target: '_blank', rel: 'noopener', text: 'Trend ' + money(trend(c)) }) : el('span', { text: 'Trend ' + money(trend(c)) })
				]),
				el('div', { class: 'pcs-qty' }, [
					el('button', { type: 'button', 'aria-label': 'Eine weniger', disabled: !n, onclick: function () { setQty(c, n - 1); }, text: '−' }),
					el('span', { text: n ? n + '×' : 'fehlt' }),
					el('button', { type: 'button', 'aria-label': 'Eine mehr', onclick: function () { setQty(c, n + 1); }, text: '+' })
				]),
				el('button', { type: 'button', class: 'pcs-sell', onclick: function () { openListing(c); }, text: 'Anzeige erstellen' })
			])
		]);
	}

	// ---------- Modals ----------
	function modal(content, wide) {
		var close = function () { overlay.remove(); document.removeEventListener('keydown', onKey); };
		var onKey = function (e) { if (e.key === 'Escape') close(); };
		var overlay = el('div', { class: 'pcs-overlay', onclick: function (e) { if (e.target === overlay) close(); } }, [
			el('div', { class: 'pcs-modal' + (wide ? ' is-wide' : ''), role: 'dialog', 'aria-modal': 'true' }, [
				el('button', { type: 'button', class: 'pcs-close', 'aria-label': 'Schliessen', onclick: close, text: '×' }),
				content
			])
		]);
		document.addEventListener('keydown', onKey);
		document.body.appendChild(overlay);
		return close;
	}

	function openImage(c) {
		modal(el('img', { src: c.imageLarge || c.image, alt: c.name, class: 'pcs-large' }));
	}

	function suggestPrice(c, basis, condKey, platform, reverse) {
		var p = c.cardmarket || {};
		var base = reverse && p.reverse != null ? p.reverse : (p[basis] != null ? p[basis] : trend(c));
		if (base == null) return null;
		var cond = CONDITIONS.filter(function (x) { return x.key === condKey; })[0] || CONDITIONS[0];
		var v = base * cond.factor * PLATFORMS[platform].rate;
		// Auf 0,50 runden, mindestens 1.–
		return Math.max(1, Math.round(v * 2) / 2);
	}

	function openListing(c) {
		var f = {
			platform: el('select', {}, Object.keys(PLATFORMS).map(function (k) { return el('option', { value: k, text: PLATFORMS[k].label }); })),
			condition: el('select', {}, CONDITIONS.map(function (x) { return el('option', { value: x.key, text: x.label }); })),
			basis: el('select', {}, [
				el('option', { value: 'trend', text: 'Cardmarket Trend' }),
				el('option', { value: 'avg30', text: 'Ø 30 Tage' }),
				el('option', { value: 'avg', text: 'Ø Verkaufspreis' }),
				el('option', { value: 'low', text: 'Niedrigster Preis' })
			]),
			reverse: el('input', { type: 'checkbox' }),
			extra: el('input', { type: 'text', placeholder: 'z. B. 1. Edition, Shadowless, Deutsch …' }),
			price: el('input', { type: 'number', min: '0', step: '0.5' }),
			title: el('input', { type: 'text', maxlength: '65' }),
			text: el('textarea', { rows: '10' })
		};
		var priceInfo = el('p', { class: 'pcs-hint' });
		var priceTouched = false;
		f.price.addEventListener('input', function () { priceTouched = true; update(); });

		function update() {
			var plat = PLATFORMS[f.platform.value];
			var cond = f.condition.value;
			var sug = suggestPrice(c, f.basis.value, cond, f.platform.value, f.reverse.checked);
			if (!priceTouched) f.price.value = sug == null ? '' : sug.toFixed(2);
			var p = c.cardmarket || {};
			priceInfo.textContent = 'Preisvorschlag: ' + (sug == null ? 'keine Cardmarket-Daten' : money(sug, plat.symbol)) +
				' (Trend ' + money(p.trend) + ', Ø30 ' + money(p.avg30) + ', ab ' + money(p.low) + ', Zustand ' + cond + ')';

			var extras = [f.reverse.checked ? 'Reverse Holo' : '', f.extra.value.trim()].filter(Boolean).join(', ');
			f.title.value = ('Pokémon ' + c.name + ' ' + c.number + (c.printed ? '/' + c.printed : '') + ' ' + c.setName + (extras ? ' ' + extras : '') + ' ' + cond).slice(0, 65);
			var priceTxt = f.price.value ? money(parseFloat(f.price.value), plat.symbol) : 'auf Anfrage';
			f.text.value = [
				'Verkaufe hier die Pokémon-Karte ' + c.name + ' aus dem Set ' + c.setName + '.',
				'',
				'• Nummer: ' + c.number + (c.printed ? '/' + c.printed : ''),
				'• Seltenheit: ' + c.rarity,
				extras ? '• Variante/Sprache: ' + extras : null,
				'• Zustand: ' + f.condition.options[f.condition.selectedIndex].text,
				'',
				'Preis: ' + priceTxt + ' (VB). Orientiert am aktuellen Cardmarket-Preis.',
				'',
				CFG.sellerNote || ''
			].filter(function (l) { return l !== null; }).join('\n').trim();
		}
		[f.platform, f.condition, f.basis, f.reverse].forEach(function (i) { i.addEventListener('change', function () { priceTouched = false; update(); }); });
		f.extra.addEventListener('input', update);

		function copyBtn(input, label) {
			return el('button', { type: 'button', class: 'pcs-copy', text: label, onclick: function () {
				var b = this;
				navigator.clipboard.writeText(input.value).then(function () { b.textContent = 'Kopiert ✓'; setTimeout(function () { b.textContent = label; }, 1500); });
			} });
		}
		function row(label, input, extra) { return el('label', { class: 'pcs-row' }, [el('span', { text: label }), input, extra || null]); }

		modal(el('div', { class: 'pcs-listing' }, [
			el('img', { src: c.image, alt: c.name, class: 'pcs-listing-img' }),
			el('div', {}, [
				el('h2', { text: 'Anzeige erstellen' }),
				row('Plattform', f.platform),
				row('Zustand', f.condition),
				row('Preisbasis', f.basis),
				c.cardmarket && c.cardmarket.reverse != null ? row('Reverse Holo', f.reverse) : null,
				row('Zusatz', f.extra),
				row('Preis', f.price),
				priceInfo,
				row('Titel', f.title, copyBtn(f.title, 'Titel kopieren')),
				row('Beschreibung', f.text, copyBtn(f.text, 'Text kopieren')),
				el('p', { class: 'pcs-hint' }, [
					'Fotos der echten Karte (Vorder- und Rückseite) beim Inserieren hochladen. ',
					el('a', { href: '#', onclick: function (e) { e.preventDefault(); window.open(PLATFORMS[f.platform.value].url, '_blank', 'noopener'); }, text: 'Zur Plattform →' })
				])
			])
		]), true);
		update();
	}

	init();
})();
