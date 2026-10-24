// Little Backup Box - touchscreen UI (touch.php)
// Talks to touch-api.php. No dependencies, works in Firefox ESR kiosk mode.

(function () {
	'use strict';

	const T = window.LBB_TOUCH.strings;
	const API = 'touch-api.php';
	// the box's small screens show one page without scrolling, phones and tablets a scrolling grid
	const PHOTOS_PER_PAGE = window.matchMedia('(min-height: 600px)').matches ? 24 : 8;
	const STATUS_INTERVAL_MS = 2000;

	const $ = (id) => document.getElementById(id);

	const state = {
		page: 'home',
		running: false,
		services: null,
		wizard: { step: 0, source: null, target: null, options: {} },
		// medium: storage picked by the user; empty lets the box choose (attached NVMe/USB first)
		photos: { offset: 0, total: 0, items: [], viewer: -1, medium: '' },
		files: { medium: '', path: '', entries: [], selecting: false, selected: new Set() },
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

	function confirmDialog(text, sub, okLabel, okClass, inputValue) {
		// with inputValue (a string): a text field, the promise gives its value (null when cancelled)
		const withInput = typeof inputValue === 'string';
		return new Promise((resolve) => {
			const input = $('modal-input');
			input.hidden = !withInput;
			input.value = withInput ? inputValue : '';
			$('modal-text').textContent = text;
			$('modal-sub').textContent = sub || '';
			$('modal-sub').hidden = !sub;
			$('modal-cancel').textContent = T.cancel;
			$('modal-ok').textContent = okLabel || T.ok;
			$('modal-ok').className = 'btn ' + (okClass || 'btn-primary');
			$('modal').hidden = false;

			const onBack = () => close(withInput ? null : false);
			const close = (result) => {
				closeOverlay(onBack);
				$('modal').hidden = true;
				$('modal-ok').onclick = null;
				$('modal-cancel').onclick = null;
				resolve(result);
			};
			$('modal-ok').onclick = () => close(withInput ? input.value.trim() : true);
			$('modal-cancel').onclick = () => close(withInput ? null : false);
			input.onkeydown = (event) => { if (event.key === 'Enter') $('modal-ok').click(); };
			openOverlay(onBack);
			if (withInput) {
				input.focus();
				// the name without its extension is selected: typing replaces it
				const dot = inputValue.lastIndexOf('.');
				input.setSelectionRange(0, dot > 0 ? dot : inputValue.length);
			}
		});
	}

	function fill(text, values) {
		return String(text).replace(/\{(\w+)\}/g, (match, key) => (key in values ? values[key] : match));
	}

	// ---------- back button (phones, web views) ----------
	// tabs are history entries; an open viewer or dialog is one more, so "back" closes it first

	// open overlays, innermost last (a dialog over the drive panel): each has its own history entry
	const overlays = [];
	let ignorePop = 0;
	const afterBack = [];

	// run when the history steps of closed overlays are done (switching tabs earlier would be undone)
	function whenSettled(fn) {
		if (ignorePop > 0) afterBack.push(fn);
		else fn();
	}

	function openOverlay(close) {
		overlays.push(close);
		history.pushState({ overlay: true }, '', location.hash);
	}

	function closeOverlay(close) {
		// closed by its own button: drop its history entry (closed by "back": already gone, nothing to do)
		const index = overlays.lastIndexOf(close);
		if (index < 0) return;
		overlays.splice(index, 1);
		if (history.state && history.state.overlay) {
			ignorePop++;
			history.back();
		}
	}

	window.addEventListener('popstate', () => {
		if (ignorePop > 0) {
			ignorePop--;
			if (!ignorePop) afterBack.splice(0).forEach((fn) => fn());
			return;
		}
		if (overlays.length) {
			overlays.pop()();
			return;
		}
		const page = location.hash.slice(1) || 'home';
		if (page !== state.page) showPage(page);
	});

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

	function showPage(page, push) {
		if (!$('page-' + page)) page = 'home';
		const changed = page !== state.page;
		state.page = page;
		document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.page === page));
		document.querySelectorAll('.page').forEach((node) => node.classList.toggle('active', node.id === 'page-' + page));
		if (location.hash !== '#' + page) {
			if (push && changed) history.pushState(null, '', '#' + page);
			else history.replaceState(null, '', '#' + page);
		}
		if (page === 'home') loadHomeRecent();

		if (page === 'backup') loadServices().then(renderWizard);
		if (page === 'photos') loadPhotos(state.photos.offset);
		if (page === 'log') loadLog(true);
		if (page === 'files') loadFiles();
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
		$('status-card').classList.toggle('running', data.running);

		// live copy of the hardware display, else a picture of the current message (logo, IP QR code)
		const oled = $('oled');
		// (message pictures like the IP QR code give way to the backup steps while idle)
		const picture = data.oled ? data.oled.url : (data.running || !data.flow ? display.image : null);
		if (picture) {
			if (oled.dataset.url !== picture) {
				oled.dataset.url = picture;
				oled.src = picture;
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

		if (data.temp !== null && data.temp !== undefined) {
			$('temp').textContent = Math.round(data.temp) + '°C';
			$('temp').classList.toggle('hot', data.temp >= 75);
		}

		// weak power supply: drives disconnect during backups. A pill in the top bar (tap: the full message),
		// a banner would cover the tab bar on phones
		const power = data.power || {};
		$('power-pill').hidden = !(power.undervoltage || power.undervoltage_since_boot);
		$('power-pill').classList.toggle('now', !!power.undervoltage);
		if ($('power-pill').hidden) showPowerNote(false);

		// names of the files being copied, live while a backup runs
		renderCopying(data.running ? data.copying : null);
		if (data.running && !state.copyingTimer) {
			state.copyingTimer = setInterval(() => {
				if (document.hidden || !state.running) return;
				api('copying').then((result) => renderCopying(state.running ? result.copying : null)).catch(() => {});
			}, 500);
		} else if (!data.running && state.copyingTimer) {
			clearInterval(state.copyingTimer);
			state.copyingTimer = null;
		}

		renderFlow(data.flow, data.running);

		// drives plugged in or removed: the possible sources and targets change (e.g. NVMe becomes available)
		const drives = data.flow ? JSON.stringify([data.flow.target.state, data.flow.target.id, data.flow.sources.length, data.flow.candidates.length]) : '';
		if (state.drives !== undefined && drives !== state.drives) {
			loadServices(true).then(() => { if (state.page === 'backup') renderWizard(); }).catch(() => {});
		}
		state.drives = drives;
		// idle: target and source are shown in the flow; while copying the free space matters
		$('storage-strip').hidden = !data.running && !!data.flow;
		renderStorageStrip(data.storages || []);

		// backup just finished: offer to look at the newest files on the target
		if (state.wasRunning && !data.running) {
			state.finished = true;
			loadHomeRecent();
		}
		if (data.running) state.finished = false;
		state.wasRunning = data.running;
		$('btn-check').hidden = !state.finished;
	}

	function storageLabel(id) {
		return (T.storages && T.storages[id]) || id;
	}

	let flowSignature = '';
	function renderFlow(flow, running) {
		// guided home screen of a portable box: 1. choose the backup drive, 2. insert the source, 3. start
		const node = $('flow');
		node.hidden = running || !flow;
		const start = $('btn-default-backup');
		start.hidden = node.hidden || flow.target.state !== 'ready' || !flow.sources.length;
		if (node.hidden) return;

		// only redraw on changes, buttons must not be replaced while they are tapped
		const signature = JSON.stringify([flow, Math.round((flow.target.free || 0) / 1e8)]);
		if (signature === flowSignature) return;
		flowSignature = signature;

		const target = flow.target;
		const row = (ok, waiting, label, text, kind) => el('div', { class: 'flow-row' + (ok ? ' ok' : '') + (waiting ? ' waiting' : '') }, [
			el('span', { class: 'flow-mark' }, ok ? icon('check') : icon(kind)),
			el('span', { class: 'flow-text' }, [el('small', { text: label }), el('b', { text: text })]),
		]);

		const rows = [];
		if (target.state === 'choose') {
			// step 1: which attached drive receives the backup
			rows.push(row(false, !flow.candidates.length, '1 · ' + T.target, flow.candidates.length ? T.choose_target : T.connect_target, 'usb'));
			if (flow.candidates.length) {
				const list = el('div', { class: 'flow-choices' });
				flow.candidates.forEach((drive) => {
					const button = el('button', { class: 'btn' }, [icon(drive.kind), el('span', { text: drive.name + ' · ' + formatBytes(drive.size) })]);
					button.addEventListener('click', () => busy(button, async () => {
						const result = await api('choose_target', { path: drive.path }, 'POST');
						if (!result.storages.some((x) => x.id.endsWith('_target'))) toast(T.mount_failed, true);
						flowSignature = '';
						refreshStatusSoon();
					}));
					list.append(button);
				});
				rows.push(list);
			}
		} else {
			const ready = target.state === 'ready';
			const text = target.name + ' · ' + (ready ? formatBytes(target.free) + ' ' + T.free : T.mounting);
			const targetRow = row(ready, !ready, '1 · ' + T.drive_backup, text, target.id.split('_')[0]);
			const change = el('button', { class: 'btn flow-change', text: T.change });
			change.addEventListener('click', () => busy(change, async () => {
				await api('change_target', {}, 'POST');
				flowSignature = '';
				refreshStatusSoon();
			}));
			targetRow.append(change);
			rows.push(targetRow);

			// step 2: the card or drive to back up
			const sourceText = flow.sources.length ? flow.sources.map((x) => x.name + ' · ' + formatBytes(x.size)).join(', ') : T.insert_source;
			rows.push(row(flow.sources.length > 0, flow.sources.length === 0, '2 · ' + T.drive_source, sourceText, 'usb'));
		}
		node.replaceChildren(...rows);

		// step 3
		if (target.state === 'ready' && flow.sources.length) {
			$('default-label').textContent = T.start;
			$('default-sub').textContent = flow.sources[0].name + ' → ' + target.name;
		}
	}

	function renderCopying(copying) {
		// current file and the ones just before it
		const node = $('status-files');
		node.hidden = !copying || !copying.current;
		if (node.hidden) return;
		const recent = copying.recent.filter((name) => name !== copying.current).slice(-3).reverse();
		node.replaceChildren(
			el('div', { class: 'copying-current' }, [icon('file'), el('span', { text: copying.current })]),
			...recent.map((name) => el('div', { class: 'copying-done', text: name })),
		);
	}

	function storageChip(storage, role) {
		const used = storage.total ? (storage.total - storage.free) / storage.total : 0;
		const meter = el('i', { class: used > 0.9 ? 'full' : '' });
		meter.style.width = Math.round(used * 100) + '%';
		return el('div', { class: 'chip chip-' + role }, [
			el('b', { text: storageLabel(storage.id) }),
			storage.name ? el('small', { text: storage.name }) : null,
			el('div', { class: 'meter' }, meter),
			el('span', { text: formatBytes(storage.free) + ' ' + T.free }),
		]);
	}

	let stripSignature = '';
	function renderStorageStrip(storages) {
		// shown while a backup runs: target first, then source.
		// Only redrawn on changes.
		const signature = JSON.stringify(storages.map((x) => [x.id, x.name, Math.round((x.free || 0) / 1e8)]));
		if (signature === stripSignature) return;
		stripSignature = signature;

		const strip = $('storage-strip');
		strip.replaceChildren();
		storages.filter((x) => x.id.endsWith('_target')).forEach((x) => strip.append(storageChip(x, 'target')));
		storages.filter((x) => x.id.endsWith('_source')).forEach((x) => strip.append(storageChip(x, 'source')));
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
		return state.services;
	}

	function serviceById(list, id) {
		return (list || []).find((service) => service.id === id);
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
			const data = await api('recent', { limit: PHOTOS_PER_PAGE, offset: Math.max(0, offset), medium: state.photos.medium, refresh: state.photos.refresh ? 1 : '' });
			state.photos.refresh = false;
			state.photos.offset = data.offset;
			state.photos.total = data.total;
			state.photos.items = data.items;
			renderMedia(data);
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
			let preview = typeTile(item);
			if (item.thumb) {
				// a preview that can not be made (unknown format) falls back to the file type
				preview = el('img', { src: item.thumb, alt: item.name, loading: 'lazy', draggable: 'false' });
				preview.addEventListener('error', () => preview.replaceWith(typeTile(item)), { once: true });
			}
			grid.append(el('button', { class: 'photo', onclick: () => openViewer(index) }, [
				preview,
				item.video ? el('span', { class: 'badge' }, icon('play')) : (item.rating > 0 ? el('span', { class: 'badge', text: '★'.repeat(item.rating) }) : null),
			]));
		});

		const pages = Math.max(1, Math.ceil(p.total / PHOTOS_PER_PAGE));
		const current = Math.floor(p.offset / PHOTOS_PER_PAGE) + 1;
		$('photos-info').textContent = p.total ? current + ' / ' + pages + '  ·  ' + p.total : '';
		$('photos-prev').disabled = p.offset <= 0;
		$('photos-next').disabled = p.offset + PHOTOS_PER_PAGE >= p.total;
	}

	function renderMedia(data) {
		// a tab for every open drive (backup drive, card / source drive, drives opened read only)
		const row = $('photo-media');
		row.replaceChildren();
		const media = data.media.length ? data.media : [{ id: data.medium, label: data.medium_label, count: data.total }];
		media.forEach((medium) => {
			const button = el('button', { class: 'btn' + (medium.id === data.medium ? ' selected' : '') }, [
				el('span', { text: medium.label }),
				el('small', { class: 'media-count', text: medium.count + ' ' + T.files_count }),
			]);
			button.addEventListener('click', () => {
				state.photos.medium = medium.id;
				loadPhotos(0);
			});
			row.append(button);
		});
		// every other plugged-in drive, by its name: opened read only, several at once, backup roles unchanged
		(data.openable || []).forEach((drive) => {
			const button = el('button', { class: 'btn btn-add' }, [icon('plus'), el('span', { text: drive.name })]);
			button.addEventListener('click', () => busy(button, async () => {
				const result = await api('open_drive', { path: drive.path }, 'POST');
				state.photos.medium = result.medium;
				state.photos.refresh = true;
				await loadPhotos(0);
			}));
			row.append(button);
		});
	}

	function typeTile(item) {
		return el('span', { class: 'nothumb' }, [el('b', { text: item.ext }), el('span', { text: item.name })]);
	}

	function openViewer(index) {
		// items of the photos page, or (state.viewerFiles) the photos and videos of a folder
		const p = state.photos;
		const list = state.viewerFiles || p.items;
		const item = list[index];
		if (!item) return;
		p.viewer = index;
		if ($('viewer').hidden) openOverlay(hideViewer);
		$('viewer-download').hidden = !item.url;
		if (item.url) {
			$('viewer-download').href = item.url;
			$('viewer-download').setAttribute('download', item.name);
		}
		const caption = item.name + (item.date ? '  ·  ' + formatDate(item.date) : '');
		const img = $('viewer-img');
		const video = $('viewer-video');
		stopVideo();
		if (item.video && item.file) {
			// play right away; fall back to the low resolution copy, then explain
			img.hidden = true;
			video.hidden = false;
			$('viewer').classList.add('playing');
			video.poster = item.thumb || '';
			let fallback = item.preview;
			video.onerror = () => {
				if (fallback) {
					video.src = fallback;
					fallback = null;
					video.play().catch(() => {});
				} else {
					video.hidden = true;
					$('viewer').classList.remove('playing');
					img.hidden = !item.thumb;
					$('viewer-caption').textContent = caption + '  ·  ' + T.video_unsupported;
				}
			};
			video.src = item.file;
			video.play().catch(() => {});
		} else {
			img.hidden = !item.thumb;
			if (item.thumb) {
				img.onerror = () => { img.hidden = true; };
				img.src = item.thumb;
			}
		}
		$('viewer-caption').textContent = caption;
		$('viewer').hidden = false;
		if (state.viewerFiles) {
			$('viewer-prev').disabled = index <= 0;
			$('viewer-next').disabled = index >= list.length - 1;
		} else {
			$('viewer-prev').disabled = p.offset + index <= 0;
			$('viewer-next').disabled = p.offset + index >= p.total - 1;
		}
	}

	function stopVideo() {
		const video = $('viewer-video');
		video.onerror = null;
		video.pause();
		video.removeAttribute('src');
		video.load();
		video.hidden = true;
		$('viewer').classList.remove('playing');
	}

	function showPowerNote(open) {
		$('power-note').hidden = !open;
		$('power-pill').setAttribute('aria-expanded', String(!!open));
	}

	function hideViewer() {
		stopVideo();
		$('viewer').hidden = true;
		state.viewerFiles = null;
	}

	// ---------- newest files on the home page (phones, tablets: screens with room for them) ----------

	const roomForRecent = window.matchMedia('(min-height: 600px)');
	let homeRecentTimer;

	async function loadHomeRecent() {
		clearTimeout(homeRecentTimer);
		if (state.page !== 'home' || !roomForRecent.matches) return;
		homeRecentTimer = setTimeout(loadHomeRecent, 20000);
		if (document.hidden || !$('viewer').hidden) return;
		let data;
		try {
			data = await api('recent', { limit: 15 });
		} catch (e) {
			return;
		}
		const items = data.items || [];
		$('home-recent').hidden = !items.length;
		$('home-recent-title').textContent = T.latest_files + (data.medium_label ? ' · ' + data.medium_label : '');
		const grid = $('home-recent-grid');
		grid.replaceChildren();
		items.forEach((item, index) => {
			let preview = typeTile(item);
			if (item.thumb) {
				preview = el('img', { src: item.thumb, alt: item.name, loading: 'lazy', draggable: 'false' });
				preview.addEventListener('error', () => preview.replaceWith(typeTile(item)), { once: true });
			}
			grid.append(el('button', { class: 'photo', onclick: () => {
				state.viewerFiles = items;
				openViewer(index);
			} }, [preview, item.video ? el('span', { class: 'badge' }, icon('play')) : null]));
		});
	}

	async function stepViewer(delta) {
		const p = state.photos;
		let index = p.viewer + delta;
		if (state.viewerFiles) {
			if (index >= 0 && index < state.viewerFiles.length) openViewer(index);
			return;
		}
		if (index < 0 || index >= p.items.length) {
			const newOffset = p.offset + (delta > 0 ? PHOTOS_PER_PAGE : -PHOTOS_PER_PAGE);
			if (newOffset < 0 || newOffset >= p.total) return;
			await loadPhotos(newOffset);
			index = delta > 0 ? 0 : p.items.length - 1;
		}
		openViewer(index);
	}

	// ---------- files ----------

	async function loadFiles() {
		// browse the folders of the mounted drives (hidden files are left out by the box)
		let data;
		try {
			data = await api('files', { medium: state.files.medium, path: state.files.path });
		} catch (e) {
			toast(e.message, true);
			return;
		}
		state.files.medium = data.medium || '';
		state.files.path = data.path;

		// every attached drive; tapping one that is not mounted opens it (read only, no backup role;
		// the backup drive chosen on the home screen is opened as target)
		const drives = $('files-drives');
		drives.replaceChildren();
		data.drives.forEach((drive) => {
			const text = [drive.name, drive.label || formatBytes(drive.size)].filter(Boolean).join(' · ');
			const button = el('button', { class: 'btn' + (drive.id === data.medium ? ' selected' : '') + (drive.mounted ? '' : ' unmounted'), text: text });
			button.addEventListener('click', () => busy(button, async () => {
				if (!drive.mounted) {
					const result = await api('open_drive', { path: drive.path }, 'POST');
					drive.id = result.medium;
				}
				state.files.medium = drive.id;
				state.files.path = '';
				await loadFiles();
			}));
			drives.append(button);
		});

		const current = data.drives.find((x) => x.id === data.medium) || {};
		const label = current.name || current.label || '';
		$('files-crumb').textContent = data.path ? label + ' / ' + data.path.split('/').join(' / ') : label;
		$('files-up').disabled = !data.path;

		state.files.entries = data.entries || [];
		endSelection();

		const list = $('files-list');
		list.replaceChildren();
		list.scrollTop = 0;
		if (!data.drives.length) {
			list.append(el('div', { class: 'empty' }, [icon('folder'), el('span', { text: T.no_drive })]));
			return;
		}
		if (!data.medium) {
			list.append(el('div', { class: 'empty' }, [icon('folder'), el('span', { text: T.open_drive })]));
			return;
		}
		if (!data.entries.length) {
			list.append(el('div', { class: 'empty' }, [icon('folder'), el('span', { text: T.empty_folder })]));
			return;
		}

		const media = data.entries.filter((x) => x.type === 'file' && x.media);
		data.entries.forEach((entry) => {
			let preview;
			if (entry.type === 'dir') {
				preview = el('span', { class: 'file-icon folder' }, icon('folder'));
			} else if (entry.thumb) {
				preview = el('img', { class: 'file-thumb', src: entry.thumb, alt: '', loading: 'lazy' });
				preview.addEventListener('error', () => preview.replaceWith(el('span', { class: 'file-icon' }, el('b', { text: entry.ext }))), { once: true });
			} else {
				preview = el('span', { class: 'file-icon' }, el('b', { text: entry.ext || '' }));
			}
			const sub = entry.type === 'dir' ? '' : formatBytes(entry.size) + '  ·  ' + formatDate(entry.date);
			const row = el('button', { class: 'file-row', 'data-path': entry.path }, [
				el('span', { class: 'file-check' }, icon('check')),
				preview,
				el('span', { class: 'file-text' }, [el('b', { text: entry.name }), sub ? el('small', { text: sub }) : null]),
				entry.type === 'dir' ? icon('right') : null,
			]);
			onLongPress(row, () => startSelection(entry.path));
			row.addEventListener('click', () => {
				if (row.dataset.longPressed) {
					delete row.dataset.longPressed;
					return;
				}
				if (state.files.selecting) {
					toggleSelected(entry.path);
					return;
				}
				if (entry.type === 'dir') {
					state.files.path = entry.path;
					loadFiles();
				} else if (entry.media) {
					state.viewerFiles = media;
					openViewer(media.indexOf(entry));
				} else {
					// other files: download
					const link = el('a', { href: entry.url, download: entry.name });
					document.body.append(link);
					link.click();
					link.remove();
				}
			});
			list.append(row);
		});
	}

	// long press (touch, mouse, resistive stylus) and the context menu of a long tap start the selection
	function onLongPress(node, callback) {
		let timer = null;
		let start = null;
		const cancel = () => { clearTimeout(timer); timer = null; };
		node.addEventListener('pointerdown', (event) => {
			start = [event.clientX, event.clientY];
			cancel();
			timer = setTimeout(() => {
				timer = null;
				node.dataset.longPressed = '1';
				callback();
			}, 550);
		});
		node.addEventListener('pointermove', (event) => {
			if (timer && start && Math.hypot(event.clientX - start[0], event.clientY - start[1]) > 10) cancel();
		});
		['pointerup', 'pointercancel', 'pointerleave'].forEach((type) => node.addEventListener(type, cancel));
		node.addEventListener('contextmenu', (event) => {
			event.preventDefault();
			if (!node.dataset.longPressed && !state.files.selecting) {
				cancel();
				node.dataset.longPressed = '1';
				callback();
			}
		});
	}

	function startSelection(path) {
		if (!state.files.selecting) {
			state.files.selecting = true;
			state.files.selected = new Set();
		}
		if (!state.files.selected.has(path)) toggleSelected(path);
	}

	function toggleSelected(path) {
		const selected = state.files.selected;
		if (selected.has(path)) selected.delete(path);
		else selected.add(path);
		if (!selected.size) {
			endSelection();
			return;
		}
		renderSelection();
	}

	function endSelection() {
		state.files.selecting = false;
		state.files.selected = new Set();
		renderSelection();
	}

	function renderSelection() {
		const f = state.files;
		$('files-list').classList.toggle('selecting', f.selecting);
		document.querySelectorAll('#files-list .file-row').forEach((row) => row.classList.toggle('selected', f.selected.has(row.dataset.path)));
		$('files-select').hidden = !f.selecting;
		$('files-bar').hidden = f.selecting;
		$('sel-count').textContent = fill(T.selected, { n: f.selected.size });
		$('sel-rename').disabled = f.selected.size !== 1;
	}

	async function fileOperation(op, extra) {
		const f = state.files;
		const params = Object.assign({ op: op, medium: f.medium, paths: JSON.stringify([...f.selected]) }, extra || {});
		await api('file_op', params, 'POST');
		endSelection();
		await loadFiles();
	}

	function driveName() {
		const button = document.querySelector('#files-drives .btn.selected');
		return button ? button.textContent : '';
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
		// the address to type in a browser: IPv4 (the IPv6 addresses many routers add only when there is no IPv4)
		const ipv4 = data.ips.filter((ip) => !ip.includes(':'));
		const ips = ipv4.length ? ipv4 : data.ips;
		add(T.ip, ips.length ? ips.join(', ') : T.no_network);
		add(T.temperature, data.temp !== null ? data.temp + ' °C' : '–');
		add(T.uptime, formatUptime(data.uptime));
		if (data.mem_total) add(T.memory, formatBytes(data.mem_total - data.mem_avail) + ' / ' + formatBytes(data.mem_total));
		if (data.model) add(T.model, data.model);
		if (data.branch && data.branch !== 'main') add('Branch', data.branch);

		loadDrives();
	}

	// ---------- drives (system page) ----------

	const fsChoices = ['exfat', 'vfat', 'ext4', 'ntfs'];
	let taskTimer = null;

	function roleText(drive) {
		if (drive.role_label) return drive.role_label;
		if (drive.role === 'browse') return T.role_browse;
		if (drive.role === 'other') return T.role_other;
		return drive.chosen ? T.chosen_backup : T.role_none;
	}

	async function loadDrives() {
		let data;
		try {
			data = await api('drives');
		} catch (e) {
			toast(e.message, true);
			return;
		}
		state.drives = data;
		const list = $('drive-list');
		list.replaceChildren();
		if (!data.drives.length) list.append(el('div', { class: 'empty' }, [icon('usb'), el('span', { text: T.no_drive })]));
		data.drives.forEach((drive) => {
			const usage = drive.total ? formatBytes(drive.free) + ' ' + T.free + ' / ' + formatBytes(drive.total) : formatBytes(drive.size);
			const row = el('button', { class: 'row drive-row' + (drive.mounted ? ' mounted' : '') }, [
				el('span', { class: 'drive-icon' }, icon(drive.kind === 'nvme' ? 'nvme' : 'usb')),
				el('div', { class: 'row-label' }, [
					el('b', { text: drive.name }),
					el('small', { text: [roleText(drive), usage, drive.fstype].filter(Boolean).join('  ·  ') }),
				]),
				icon('right'),
			]);
			row.addEventListener('click', () => openDriveSheet(drive));
			list.append(row);
		});
		// a task started earlier (check, repair, format) is shown again
		if (data.task && data.task.running && $('drive-sheet').hidden) {
			const drive = data.drives.find((x) => x.path === data.task.device);
			if (drive) openDriveSheet(drive);
		}
	}

	function closeDriveSheet(fromBack) {
		clearTimeout(taskTimer);
		taskTimer = null;
		$('drive-sheet').hidden = true;
		if (!fromBack) closeOverlay(sheetBack);
		if (state.page === 'system') loadDrives();
	}

	function sheetBack() {
		closeDriveSheet(true);
	}

	function sheetButton(iconName, text, cls, onClick) {
		const button = el('button', { class: 'btn ' + (cls || '') }, [icon(iconName), el('span', { text: text })]);
		button.addEventListener('click', () => busy(button, onClick));
		return button;
	}

	function openDriveSheet(drive) {
		$('sheet-title').textContent = drive.name;
		$('sheet-sub').textContent = [roleText(drive), formatBytes(drive.size), drive.fstype, drive.path].filter(Boolean).join('  ·  ');
		const body = $('sheet-body');
		body.replaceChildren();
		const task = state.drives && state.drives.task;
		// a task still running on this drive (a finished one was shown while it ran)
		if (task && task.device === drive.path && task.running) {
			showTask(task);
		} else {
			renderDriveActions(drive);
		}
		if ($('drive-sheet').hidden) {
			$('drive-sheet').hidden = false;
			openOverlay(sheetBack);
		}
	}

	function renderDriveActions(drive) {
		const body = $('sheet-body');
		const busyNow = state.drives && state.drives.running;
		if (busyNow) body.append(el('p', { class: 'sheet-note warn', text: T.running }));

		const use = el('div', { class: 'btn-row' });
		if (!drive.role.startsWith('target_')) {
			use.append(sheetButton('check', T.use_as_backup, 'btn-primary', async () => {
				await api('choose_target', { path: drive.path }, 'POST');
				toast(T.use_as_backup + ': ' + drive.name);
				closeDriveSheet();
				refreshStatusSoon();
			}));
		}
		use.append(sheetButton('folder', T.open_in_files, '', async () => {
			let medium = drive.medium;
			if (!medium) medium = (await api('open_drive', { path: drive.path }, 'POST')).medium;
			state.files.medium = medium;
			state.files.path = '';
			closeDriveSheet();
			whenSettled(() => showPage('files', true));
		}));
		if (drive.mounted) {
			use.append(sheetButton('close', T.unmount_drive, '', async () => {
				const result = await api('drive_action', { path: drive.path, do: 'unmount' }, 'POST');
				toast(result.message);
				closeDriveSheet();
				refreshStatusSoon();
			}));
		}
		body.append(use);

		body.append(el('h4', { text: T.photo_tasks }));
		const photos = el('div', { class: 'btn-row' });
		Object.keys(T.functions).forEach((func) => {
			photos.append(sheetButton('photos', T.functions[func], '', async () => {
				if (!(await confirmDialog(T.functions[func] + ': ' + drive.name + '?', '', T.ok))) return;
				const result = await api('drive_action', { path: drive.path, do: func }, 'POST');
				toast(result.message);
				closeDriveSheet();
				whenSettled(() => showPage('home', true));
				refreshStatusSoon();
			}));
		});
		body.append(photos);

		body.append(el('h4', { text: T.disk_tasks }));
		const disk = el('div', { class: 'btn-row' });
		if (drive.check) {
			disk.append(sheetButton('check', T.check, '', () => startDiskTask(drive, { do: 'check' })));
			disk.append(sheetButton('tool', T.repair, 'btn-warn', () => startDiskTask(drive, { do: 'repair' })));
		}
		disk.append(sheetButton('trash', T.format, 'btn-danger', () => chooseFormat(drive)));
		body.append(disk);
		if (drive.check) body.append(el('p', { class: 'sheet-note', text: T.check_hint }));
	}

	function chooseFormat(drive) {
		// 1. the file system, 2. what will be erased, 3. really
		const body = $('sheet-body');
		body.replaceChildren(el('h4', { text: T.format_choose }), el('p', { class: 'sheet-note warn', text: fill(T.format_warning, { name: drive.name, size: formatBytes(drive.size) }) }));
		const list = el('div', { class: 'list' });
		fsChoices.forEach((fs) => {
			list.append(sheetButton('trash', T['fs_' + fs], 'btn-wide', async () => {
				if (!(await confirmDialog(fill(T.format_warning, { name: drive.name, size: formatBytes(drive.size) }), T['fs_' + fs], T.format, 'btn-danger'))) return;
				if (!(await confirmDialog(fill(T.format_really, { name: drive.name }), '', T.format, 'btn-danger'))) return;
				await startDiskTask(drive, { do: 'format', fs: fs, confirm: drive.name });
			}));
		});
		body.append(list, sheetButton('left', T.back, '', async () => {
			body.replaceChildren();
			renderDriveActions(drive);
		}));
	}

	async function startDiskTask(drive, params) {
		const result = await api('drive_action', Object.assign({ path: drive.path }, params), 'POST');
		showTask(result.task);
	}

	function showTask(task) {
		const body = $('sheet-body');
		const done = !task.running;
		const ok = task.exit === 0;
		const status = done ? (ok ? T.task_done : fill(T.task_failed, { code: task.exit === null ? '?' : task.exit })) : T.task_running;
		const log = el('pre', { class: 'task-log', text: (task.log || []).join('\n') });
		body.replaceChildren(
			el('h4', { text: task.title }),
			el('p', { class: 'sheet-note' + (done ? (ok ? ' ok' : ' warn') : ''), text: status }),
			log,
			done ? sheetButton('check', T.close, 'btn-primary', async () => closeDriveSheet()) : null,
		);
		log.scrollTop = log.scrollHeight;
		clearTimeout(taskTimer);
		if (!done) {
			taskTimer = setTimeout(async () => {
				if ($('drive-sheet').hidden) return;
				try {
					showTask((await api('disk_task')).task);
				} catch (e) {
					taskTimer = setTimeout(() => showTask(task), 2000);
				}
			}, 1000);
		}
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
		const tabLabels = { home: T.home, backup: T.backup, photos: T.photos, log: T.log, files: T.files, system: T.system };
		document.querySelectorAll('.tab').forEach((tab) => {
			tab.querySelector('span').textContent = tabLabels[tab.dataset.page];
			tab.addEventListener('click', () => {
				if (tab.dataset.page === 'backup' && state.page === 'backup') resetWizard();
				showPage(tab.dataset.page, true);
			});
		});
		$('new-backup-label').textContent = T.new_backup;
		$('stop-label').textContent = T.stop;
		$('check-label').textContent = T.check_files;
		$('home-recent-all').textContent = T.photos + ' →';
		$('wizard-start-label').textContent = T.start;
		$('log-refresh-label').textContent = T.refresh;
		$('log-clear-label').textContent = T.clear_log;
		$('h-storage').textContent = T.drives;
		[['sel-all', T.select_all], ['sel-rename', T.rename], ['sel-delete', T.delete], ['sel-cancel', T.cancel], ['sheet-close', T.close]].forEach(([id, label]) => {
			$(id).setAttribute('aria-label', label);
			$(id).title = label;
		});
		$('h-settings').textContent = T.settings;
		$('h-power').textContent = T.power;
		$('power-pill-text').textContent = T.power_short;
		$('power-note-text').textContent = T.power_warning;
		$('power-note-close').setAttribute('aria-label', T.cancel);
		$('power-pill').title = T.power_warning;
		$('classic-label').textContent = T.classic_ui;
		$('reboot-label').textContent = T.reboot;
		$('shutdown-label').textContent = T.shutdown;
	}

	function initEvents() {
		// the full power message: opened from the pill, closed with x, the pill or a tap anywhere else
		// Files: selection actions
		$('sel-cancel').addEventListener('click', () => endSelection());
		$('sel-all').addEventListener('click', () => {
			const f = state.files;
			f.selected = f.selected.size === f.entries.length ? new Set() : new Set(f.entries.map((x) => x.path));
			if (!f.selected.size) endSelection();
			else renderSelection();
		});
		$('sel-rename').addEventListener('click', (event) => busy(event.currentTarget, async () => {
			const path = [...state.files.selected][0];
			const current = path.split('/').pop();
			const name = await confirmDialog(T.rename, '', T.rename, 'btn-primary', current);
			if (!name || name === current) return;
			await fileOperation('rename', { name: name });
		}));
		$('sel-delete').addEventListener('click', (event) => busy(event.currentTarget, async () => {
			const f = state.files;
			const names = [...f.selected].map((x) => x.split('/').pop());
			const sub = fill(T.delete_sub, { drive: driveName() }) + '\n' + names.slice(0, 5).join(', ') + (names.length > 5 ? ' …' : '');
			if (!(await confirmDialog(fill(T.delete_confirm, { n: names.length }), sub, T.delete, 'btn-danger'))) return;
			await fileOperation('delete');
		}));

		$('sheet-close').addEventListener('click', () => closeDriveSheet());

		$('power-pill').addEventListener('click', (event) => {
			event.stopPropagation();
			showPowerNote($('power-note').hidden);
		});
		$('power-note-close').addEventListener('click', () => showPowerNote(false));
		$('power-note').addEventListener('click', (event) => event.stopPropagation());
		document.addEventListener('click', () => showPowerNote(false));

		$('btn-new-backup').addEventListener('click', () => {
			resetWizard();
			showPage('backup', true);
		});

		$('btn-default-backup').addEventListener('click', (event) => busy(event.currentTarget, async () => {
			const result = await api('start', {}, 'POST');
			toast(result.message);
			state.running = true;
			refreshStatusSoon();
		}));

		$('btn-check').addEventListener('click', () => {
			// newest files of the backup drive, listed again so the files just copied are included
			state.finished = false;
			$('btn-check').hidden = true;
			state.photos.medium = '';
			state.photos.refresh = true;
			showPage('photos', true);
		});

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

		$('home-recent-all').addEventListener('click', () => {
			state.photos.medium = '';
			showPage('photos', true);
		});
		roomForRecent.addEventListener('change', loadHomeRecent);

		$('photos-prev').addEventListener('click', () => loadPhotos(state.photos.offset - PHOTOS_PER_PAGE));
		$('photos-next').addEventListener('click', () => loadPhotos(state.photos.offset + PHOTOS_PER_PAGE));
		$('viewer-close').addEventListener('click', () => {
			hideViewer();
			closeOverlay(hideViewer);
		});
		$('files-up').addEventListener('click', () => {
			state.files.path = state.files.path.split('/').slice(0, -1).join('/');
			loadFiles();
		});
		$('viewer-prev').addEventListener('click', () => stepViewer(-1));
		$('viewer-next').addEventListener('click', () => stepViewer(1));

		const log = $('log');
		$('log-up').addEventListener('click', () => { log.scrollTop -= log.clientHeight * 0.8; });
		$('log-down').addEventListener('click', () => { log.scrollTop += log.clientHeight * 0.8; });
		$('log-refresh').addEventListener('click', () => loadLog(true));
		$('log-clear').addEventListener('click', (event) => busy(event.currentTarget, async () => {
			if (!(await confirmDialog(T.clear_log_confirm, '', T.clear_log, 'btn-danger'))) return;
			await api('clearlog', {}, 'POST');
			await loadLog(true);
		}));

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

	function formatTime(hours, minutes) {
		// 12 hour clock: 7:05 PM
		return ((hours % 12) || 12) + ':' + String(minutes).padStart(2, '0') + ' ' + (hours < 12 ? 'AM' : 'PM');
	}

	function formatDate(text) {
		// "2026-10-21 19:05" -> "2026-10-21 7:05 PM"
		const match = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/.exec(text || '');
		return match ? match[1] + ' ' + formatTime(Number(match[2]), Number(match[3])) : (text || '');
	}

	function updateClock() {
		const now = new Date();
		$('clock').textContent = formatTime(now.getHours(), now.getMinutes());
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
