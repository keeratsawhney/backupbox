// Little Backup Box - touchscreen UI (touch.php)
// Talks to touch-api.php. No dependencies, works in Firefox ESR kiosk mode.

(function () {
	'use strict';

	const T = window.LBB_TOUCH.strings;
	const API = 'touch-api.php';
	const PHOTOS_PER_PAGE = 8;
	const STATUS_INTERVAL_MS = 2000;

	const $ = (id) => document.getElementById(id);

	const state = {
		page: 'home',
		running: false,
		services: null,
		wizard: { step: 0, source: null, target: null, options: {} },
		photos: { offset: 0, total: 0, items: [], viewer: -1 },
		failures: 0,
		powering: false,
	};

	// ---------- helpers ----------

	function el(tag, attrs, children) {
		const node = document.createElement(tag);
		for (const [key, value] of Object.entries(attrs || {})) {
			if (key === 'class') node.className = value;
			else if (key === 'text') node.textContent = value;
			else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
			else if (value !== false && value !== null && value !== undefined) node.setAttribute(key, value === true ? '' : value);
		}
		for (const child of [].concat(children || [])) {
			if (child) node.append(child);
		}
		return node;
	}

	function icon(name) {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
		use.setAttribute('href', '#i-' + name);
		svg.append(use);
		return svg;
	}

	function formatBytes(bytes) {
		if (bytes === null || bytes === undefined) return '?';
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		let i = 0;
		while (bytes >= 1000 && i < units.length - 1) {
			bytes /= 1000;
			i++;
		}
		return (bytes >= 100 || i === 0 ? Math.round(bytes) : bytes.toFixed(1)) + ' ' + units[i];
	}

	function formatUptime(seconds) {
		const d = Math.floor(seconds / 86400);
		const h = Math.floor((seconds % 86400) / 3600);
		const m = Math.floor((seconds % 3600) / 60);
		return (d ? d + 'd ' : '') + h + 'h ' + m + 'm';
	}

	async function api(action, params, method) {
		const options = { method: method || 'GET', cache: 'no-store', headers: {} };
		let url = API + '?action=' + encodeURIComponent(action);

		if (options.method === 'POST') {
			const body = new URLSearchParams(params || {});
			body.set('action', action);
			options.body = body;
			options.headers['X-LBB-Touch'] = '1';
		} else if (params) {
			url += '&' + new URLSearchParams(params).toString();
		}

		const response = await fetch(url, options);
		let data;
		try {
			data = await response.json();
		} catch (e) {
			throw new Error('HTTP ' + response.status);
		}
		if (!data.ok) throw new Error(data.error || ('HTTP ' + response.status));
		return data;
	}

	let toastTimer = null;
	function toast(message, isError) {
		const node = $('toast');
		node.textContent = message;
		node.classList.toggle('error', !!isError);
		node.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => { node.hidden = true; }, isError ? 5000 : 3000);
	}

	function confirmDialog(text, sub, okLabel, okClass) {
		return new Promise((resolve) => {
			$('modal-text').textContent = text;
			$('modal-sub').textContent = sub || '';
			$('modal-sub').hidden = !sub;
			$('modal-cancel').textContent = T.cancel;
			$('modal-ok').textContent = okLabel || T.ok;
			$('modal-ok').className = 'btn ' + (okClass || 'btn-primary');
			$('modal').hidden = false;

			const close = (result) => {
				$('modal').hidden = true;
				$('modal-ok').onclick = null;
				$('modal-cancel').onclick = null;
				resolve(result);
			};
			$('modal-ok').onclick = () => close(true);
			$('modal-cancel').onclick = () => close(false);
		});
	}

	// buttons must not fire twice while a request is running (resistive screens bounce)
	async function busy(button, task) {
		if (button.disabled) return;
		button.disabled = true;
		button.classList.add('loading');
		try {
			await task();
		} catch (e) {
			toast(e.message, true);
		} finally {
			button.disabled = false;
			button.classList.remove('loading');
		}
	}

	// ---------- navigation ----------

	function showPage(page) {
		if (!$('page-' + page)) page = 'home';
		state.page = page;
		document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.page === page));
		document.querySelectorAll('.page').forEach((node) => node.classList.toggle('active', node.id === 'page-' + page));
		if (location.hash !== '#' + page) history.replaceState(null, '', '#' + page);

		if (page === 'backup') loadServices().then(renderWizard);
		if (page === 'photos') loadPhotos(state.photos.offset);
		if (page === 'log') loadLog(true);
		if (page === 'system') loadSystem();
	}

	// ---------- status (home) ----------

	function renderStatus(data) {
		state.running = data.running;
		const display = data.display || {};
		const lines = display.lines || [];
		const progress = display.progress;

		const pill = $('state-pill');
		pill.classList.toggle('running', data.running);
		pill.classList.remove('offline');
		$('state-text').textContent = data.running ? T.running : T.idle;

		$('status-title').textContent = data.running ? (lines[0] || T.running) : T.idle;
		$('status-percent').textContent = data.running && progress !== null && progress !== undefined ? Math.round(progress) + '%' : '';

		const bar = $('progress');
		bar.hidden = !data.running;
		bar.classList.toggle('indeterminate', data.running && (progress === null || progress === undefined));
		$('progress-bar').style.width = progress !== null && progress !== undefined ? progress + '%' : '';

		$('status-card').classList.toggle('alert', !!display.alert);

		const oled = $('oled');
		if (data.oled) {
			if (oled.dataset.url !== data.oled.url) {
				oled.dataset.url = data.oled.url;
				oled.src = data.oled.url;
			}
			oled.hidden = false;
		} else {
			oled.hidden = true;
		}

		const linesNode = $('status-lines');
		linesNode.replaceChildren();
		if (data.running) {
			lines.slice(1).forEach((line) => linesNode.append(el('div', { text: line })));
		} else if (lines.length) {
			// idle: the message was split into short lines for the hardware display
			linesNode.append(el('div', { text: T.last_message + ': ' + lines.join(' ') }));
		}

		$('btn-stop').hidden = !data.running;
		$('btn-new-backup').hidden = data.running;
		$('btn-default-backup').hidden = data.running || !hasDefaultBackup();

		if (data.temp !== null && data.temp !== undefined) {
			$('temp').textContent = Math.round(data.temp) + '°C';
			$('temp').classList.toggle('hot', data.temp >= 75);
		}

		renderStorageStrip(data.storages || []);
	}

	function storageLabel(id) {
		return (T.storages && T.storages[id]) || id;
	}

	function renderStorageStrip(storages) {
		const strip = $('storage-strip');
		strip.replaceChildren();
		storages.forEach((storage) => {
			const used = storage.total ? (storage.total - storage.free) / storage.total : 0;
			const meter = el('i', { class: used > 0.9 ? 'full' : '' });
			meter.style.width = Math.round(used * 100) + '%';
			strip.append(el('div', { class: 'chip' }, [
				el('b', { text: storageLabel(storage.id) }),
				el('div', { class: 'meter' }, meter),
				el('span', { text: formatBytes(storage.free) + ' ' + T.free }),
			]));
		});
	}

	let statusTimer = null;
	async function pollStatus() {
		clearTimeout(statusTimer);
		if (state.powering) return;
		try {
			if (!document.hidden) {
				renderStatus(await api('status'));
			}
			state.failures = 0;
			$('offline').hidden = true;
		} catch (e) {
			state.failures++;
			if (state.failures >= 2) {
				$('offline').textContent = T.connection_lost;
				$('offline').hidden = false;
				$('state-pill').classList.add('offline');
			}
		}
		statusTimer = setTimeout(pollStatus, state.failures ? Math.min(10000, STATUS_INTERVAL_MS * (state.failures + 1)) : STATUS_INTERVAL_MS);
	}

	function refreshStatusSoon() {
		setTimeout(pollStatus, 700);
	}

	// ---------- services / backup ----------

	async function loadServices(force) {
		if (state.services && !force) return state.services;
		state.services = await api('services');
		state.wizard.options = Object.assign({}, state.services.defaults);
		delete state.wizard.options.source;
		delete state.wizard.options.target;
		updateDefaultButton();
		return state.services;
	}

	function serviceById(list, id) {
		return (list || []).find((service) => service.id === id);
	}

	function hasDefaultBackup() {
		const s = state.services;
		if (!s) return false;
		return !!(serviceById(s.sources, s.defaults.source) && serviceById(s.targets, s.defaults.target) &&
			(s.allowed[s.defaults.source] || []).includes(s.defaults.target));
	}

	function updateDefaultButton() {
		if (!hasDefaultBackup()) {
			$('btn-default-backup').hidden = true;
			return;
		}
		const s = state.services;
		$('default-label').textContent = T.start_default;
		$('default-sub').textContent = serviceById(s.sources, s.defaults.source).label + ' → ' + serviceById(s.targets, s.defaults.target).label;
		$('btn-default-backup').hidden = state.running;
	}

	async function startBackup(source, target, options) {
		if (state.running) {
			const ok = await confirmDialog(T.start + '?', T.busy_warning, T.start);
			if (!ok) return;
		}
		const params = { source: source, target: target };
		for (const [key, value] of Object.entries(options)) {
			params[key] = value ? '1' : '0';
		}
		const result = await api('backup', params, 'POST');
		toast(result.message);
		state.running = true;
		resetWizard();
		showPage('home');
		refreshStatusSoon();
	}

	function resetWizard() {
		state.wizard.step = 0;
		state.wizard.source = null;
		state.wizard.target = null;
		if (state.services) {
			state.wizard.options = Object.assign({}, state.services.defaults);
			delete state.wizard.options.source;
			delete state.wizard.options.target;
		}
	}

	function renderWizard() {
		const s = state.services;
		const w = state.wizard;
		const body = $('wizard-body');
		body.replaceChildren();
		body.scrollTop = 0;

		document.querySelectorAll('.step').forEach((node) => node.classList.toggle('done', Number(node.dataset.step) <= w.step));
		$('wizard-back').disabled = w.step === 0;
		$('wizard-foot').hidden = w.step !== 2;

		if (w.step === 0) {
			$('wizard-title').textContent = T.choose_source;
			const grid = el('div', { class: 'tile-grid' });
			s.sources.forEach((service) => {
				grid.append(el('button', {
					class: 'tile' + (w.source === service.id ? ' selected' : ''),
					disabled: !(s.allowed[service.id] || []).length,
					onclick: () => { w.source = service.id; w.target = null; w.step = 1; renderWizard(); },
				}, [icon(service.kind), el('span', { text: service.label })]));
			});
			body.append(grid);
		}

		if (w.step === 1) {
			$('wizard-title').textContent = T.choose_target;
			body.append(el('div', { class: 'summary' }, [icon(serviceById(s.sources, w.source).kind), el('span', { text: serviceById(s.sources, w.source).label + ' →' })]));
			const grid = el('div', { class: 'tile-grid' });
			const allowed = s.allowed[w.source] || [];
			s.targets.forEach((service) => {
				grid.append(el('button', {
					class: 'tile' + (w.target === service.id ? ' selected' : ''),
					disabled: !allowed.includes(service.id),
					onclick: () => { w.target = service.id; w.step = 2; renderWizard(); },
				}, [icon(service.kind), el('span', { text: service.label })]));
			});
			body.append(grid);
		}

		if (w.step === 2) {
			$('wizard-title').textContent = T.options;
			body.append(el('div', { class: 'summary' }, [
				icon(serviceById(s.sources, w.source).kind),
				el('span', { text: serviceById(s.sources, w.source).label + ' → ' + serviceById(s.targets, w.target).label }),
			]));
			Object.keys(T.option_labels).forEach((key) => {
				const toggle = el('button', { class: 'toggle', role: 'switch', 'aria-checked': String(!!w.options[key]) }, [
					el('span', { class: 'switch' }),
					el('span', { text: T.option_labels[key] }),
				]);
				toggle.addEventListener('click', () => {
					w.options[key] = !w.options[key];
					toggle.setAttribute('aria-checked', String(w.options[key]));
				});
				body.append(toggle);
			});
		}
	}

	// ---------- photos ----------

	async function loadPhotos(offset) {
		const grid = $('photo-grid');
		try {
			const data = await api('recent', { limit: PHOTOS_PER_PAGE, offset: Math.max(0, offset) });
			state.photos.offset = data.offset;
			state.photos.total = data.total;
			state.photos.items = data.items;
		} catch (e) {
			toast(e.message, true);
			return;
		}

		grid.replaceChildren();
		const p = state.photos;
		if (!p.items.length) {
			grid.append(el('div', { class: 'empty' }, [icon('photos'), el('span', { text: T.no_photos })]));
		}
		p.items.forEach((item, index) => {
			const img = el('img', { src: item.thumb, alt: item.name, loading: 'lazy', draggable: 'false' });
			img.addEventListener('error', () => { img.src = 'img/unknown.JPG'; }, { once: true });
			grid.append(el('button', { class: 'photo', onclick: () => openViewer(index) }, [
				img,
				item.video ? el('span', { class: 'badge' }, icon('play')) : (item.rating > 0 ? el('span', { class: 'badge', text: '★'.repeat(item.rating) }) : null),
			]));
		});

		const pages = Math.max(1, Math.ceil(p.total / PHOTOS_PER_PAGE));
		const current = Math.floor(p.offset / PHOTOS_PER_PAGE) + 1;
		$('photos-info').textContent = p.total ? current + ' / ' + pages + '  ·  ' + p.total : '';
		$('photos-prev').disabled = p.offset <= 0;
		$('photos-next').disabled = p.offset + PHOTOS_PER_PAGE >= p.total;
	}

	function openViewer(index) {
		const p = state.photos;
		const item = p.items[index];
		if (!item) return;
		p.viewer = index;
		const img = $('viewer-img');
		img.onerror = () => { img.onerror = null; img.src = 'img/unknown.JPG'; };
		img.src = item.thumb;
		$('viewer-caption').textContent = item.name + (item.date ? '  ·  ' + item.date : '');
		$('viewer').hidden = false;
		$('viewer-prev').disabled = p.offset + index <= 0;
		$('viewer-next').disabled = p.offset + index >= p.total - 1;
	}

	async function stepViewer(delta) {
		const p = state.photos;
		let index = p.viewer + delta;
		if (index < 0 || index >= p.items.length) {
			const newOffset = p.offset + (delta > 0 ? PHOTOS_PER_PAGE : -PHOTOS_PER_PAGE);
			if (newOffset < 0 || newOffset >= p.total) return;
			await loadPhotos(newOffset);
			index = delta > 0 ? 0 : p.items.length - 1;
		}
		openViewer(index);
	}

	// ---------- log ----------

	async function loadLog(scrollToEnd) {
		const node = $('log');
		const atEnd = node.scrollTop + node.clientHeight >= node.scrollHeight - 20;
		try {
			const data = await api('log', { lines: 300 });
			node.textContent = data.lines.join('\n');
		} catch (e) {
			toast(e.message, true);
			return;
		}
		if (scrollToEnd || atEnd) node.scrollTop = node.scrollHeight;
	}

	// ---------- system ----------

	async function loadSystem() {
		let data;
		try {
			data = await api('sysinfo');
		} catch (e) {
			toast(e.message, true);
			return;
		}

		const info = $('sysinfo');
		info.replaceChildren();
		const add = (label, value) => info.append(el('div', { class: 'info' }, [el('small', { text: label }), el('b', { text: value })]));
		add(T.ip, data.ips.length ? data.ips.join(', ') : T.no_network);
		add(T.temperature, data.temp !== null ? data.temp + ' °C' : '–');
		add(T.uptime, formatUptime(data.uptime));
		if (data.mem_total) add(T.memory, formatBytes(data.mem_total - data.mem_avail) + ' / ' + formatBytes(data.mem_total));
		if (data.model) add(T.model, data.model);
		if (data.branch && data.branch !== 'main') add('Branch', data.branch);

		renderMounts(data.storages);
		renderFunctions();
	}

	function renderMounts(storages) {
		const list = $('mount-list');
		list.replaceChildren();
		const mounted = {};
		storages.forEach((storage) => { mounted[storage.id] = storage; });

		const devices = ['usb_target', 'usb_source'];
		if (state.services && state.services.nvme) devices.push('nvme_target', 'nvme_source');

		devices.forEach((id) => {
			const [storage, role] = id.split('_');
			const isMounted = !!mounted[id];
			const sub = isMounted ? formatBytes(mounted[id].free) + ' ' + T.free + ' / ' + formatBytes(mounted[id].total) : '–';
			const button = el('button', { class: 'btn' + (isMounted ? '' : ' btn-primary'), text: isMounted ? T.umount : T.mount });
			button.addEventListener('click', () => busy(button, async () => {
				button.textContent = T.loading;
				const result = await api(isMounted ? 'umount' : 'mount', { storage: storage, role: role }, 'POST');
				const nowMounted = result.storages.some((s) => s.id === id);
				if (nowMounted === isMounted) toast(isMounted ? T.umount_failed : T.mount_failed, true);
				renderMounts(result.storages);
			}));
			list.append(el('div', { class: 'row' }, [
				el('div', { class: 'row-label' }, [el('b', { text: storageLabel(id) }), el('small', { text: sub })]),
				button,
			]));
		});

		if (mounted.internal) {
			list.append(el('div', { class: 'row' }, [
				el('div', { class: 'row-label' }, [
					el('b', { text: storageLabel('internal') }),
					el('small', { text: formatBytes(mounted.internal.free) + ' ' + T.free + ' / ' + formatBytes(mounted.internal.total) }),
				]),
			]));
		}
	}

	function renderFunctions() {
		const grid = $('func-grid');
		grid.replaceChildren();
		const targets = ['usb', 'internal'];
		if (state.services && state.services.nvme) targets.push('nvme');

		Object.keys(T.functions).forEach((func) => {
			grid.append(el('span', { class: 'func-name', text: T.functions[func] }));
			const row = el('div', { class: 'btn-row' });
			targets.forEach((target) => {
				const button = el('button', { class: 'btn', text: storageLabel(target) });
				button.addEventListener('click', () => busy(button, async () => {
					const ok = await confirmDialog(T.functions[func] + ' → ' + storageLabel(target) + '?', state.running ? T.busy_warning : '', T.ok);
					if (!ok) return;
					const result = await api('function', { function: func, target: target }, 'POST');
					toast(result.message);
					refreshStatusSoon();
				}));
				row.append(button);
			});
			grid.append(row);
		});
	}

	async function power(mode) {
		const ok = await confirmDialog(mode === 'reboot' ? T.reboot_confirm : T.shutdown_confirm, state.running ? T.busy_warning : '', mode === 'reboot' ? T.reboot : T.shutdown, mode === 'reboot' ? 'btn-warn' : 'btn-danger');
		if (!ok) return;
		state.powering = true;
		clearTimeout(statusTimer);
		let message = mode === 'reboot' ? T.reboot : T.shutdown;
		try {
			message = (await api('power', { mode: mode }, 'POST')).message;
		} catch (e) {
			// the box may already be going down
		}
		$('overlay-text').textContent = message;
		$('overlay').hidden = false;

		if (mode === 'reboot') {
			// wait until the box answers again, then reload
			const waitForBox = async () => {
				try {
					await api('status');
					location.reload();
				} catch (e) {
					setTimeout(waitForBox, 5000);
				}
			};
			setTimeout(waitForBox, 20000);
		}
	}

	function applyTheme(theme, remember) {
		document.documentElement.dataset.theme = theme;
		$('theme-label').textContent = T.theme + ': ' + (T.themes[theme] || theme);
		if (!remember) return;
		// only a theme picked on this screen overrides the configured one
		try {
			localStorage.setItem('lbb-touch-theme', theme);
		} catch (e) {
			// storage not available (private window), theme lasts for this session only
		}
	}

	// ---------- init ----------

	function initLabels() {
		const tabLabels = { home: T.home, backup: T.backup, photos: T.photos, log: T.log, system: T.system };
		document.querySelectorAll('.tab').forEach((tab) => {
			tab.querySelector('span').textContent = tabLabels[tab.dataset.page];
			tab.addEventListener('click', () => {
				if (tab.dataset.page === 'backup' && state.page === 'backup') resetWizard();
				showPage(tab.dataset.page);
			});
		});
		$('new-backup-label').textContent = T.new_backup;
		$('stop-label').textContent = T.stop;
		$('wizard-start-label').textContent = T.start;
		$('log-refresh-label').textContent = T.refresh;
		$('h-storage').textContent = T.storage;
		$('h-maintenance').textContent = T.maintenance;
		$('h-settings').textContent = T.settings;
		$('h-power').textContent = T.power;
		$('classic-label').textContent = T.classic_ui;
		$('reboot-label').textContent = T.reboot;
		$('shutdown-label').textContent = T.shutdown;
	}

	function initEvents() {
		$('btn-new-backup').addEventListener('click', () => {
			resetWizard();
			showPage('backup');
		});

		$('btn-default-backup').addEventListener('click', (event) => busy(event.currentTarget, async () => {
			const s = await loadServices();
			const options = Object.assign({}, s.defaults);
			delete options.source;
			delete options.target;
			await startBackup(s.defaults.source, s.defaults.target, options);
		}));

		$('btn-stop').addEventListener('click', (event) => busy(event.currentTarget, async () => {
			if (!(await confirmDialog(T.stop_confirm, '', T.stop, 'btn-danger'))) return;
			const result = await api('stop', {}, 'POST');
			toast(result.message);
			refreshStatusSoon();
		}));

		$('wizard-back').addEventListener('click', () => {
			if (state.wizard.step > 0) {
				state.wizard.step--;
				renderWizard();
			}
		});

		$('wizard-start').addEventListener('click', (event) => busy(event.currentTarget, () =>
			startBackup(state.wizard.source, state.wizard.target, state.wizard.options)));

		$('photos-prev').addEventListener('click', () => loadPhotos(state.photos.offset - PHOTOS_PER_PAGE));
		$('photos-next').addEventListener('click', () => loadPhotos(state.photos.offset + PHOTOS_PER_PAGE));
		$('viewer-close').addEventListener('click', () => { $('viewer').hidden = true; });
		$('viewer-prev').addEventListener('click', () => stepViewer(-1));
		$('viewer-next').addEventListener('click', () => stepViewer(1));

		const log = $('log');
		$('log-up').addEventListener('click', () => { log.scrollTop -= log.clientHeight * 0.8; });
		$('log-down').addEventListener('click', () => { log.scrollTop += log.clientHeight * 0.8; });
		$('log-refresh').addEventListener('click', () => loadLog(true));

		$('btn-theme').addEventListener('click', () => {
			const themes = Object.keys(T.themes);
			applyTheme(themes[(themes.indexOf(document.documentElement.dataset.theme) + 1) % themes.length], true);
		});
		$('btn-reboot').addEventListener('click', () => power('reboot'));
		$('btn-shutdown').addEventListener('click', () => power('poweroff'));

		// long press on touchscreens opens the context menu, which is useless in a kiosk
		document.addEventListener('contextmenu', (event) => event.preventDefault());

		document.addEventListener('visibilitychange', () => { if (!document.hidden) pollStatus(); });

		// keep the log page live while it is visible
		setInterval(() => { if (state.page === 'log' && !document.hidden) loadLog(false); }, 3000);

		setInterval(updateClock, 10000);
	}

	function updateClock() {
		const now = new Date();
		$('clock').textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
	}

	let savedTheme = null;
	try {
		savedTheme = localStorage.getItem('lbb-touch-theme');
	} catch (e) {
		savedTheme = null;
	}

	initLabels();
	initEvents();
	applyTheme(savedTheme && T.themes[savedTheme] ? savedTheme : window.LBB_TOUCH.theme);
	updateClock();
	pollStatus();
	loadServices().catch((e) => toast(e.message, true));
	showPage(location.hash.slice(1) || 'home');
})();
