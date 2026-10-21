/* Little Backup Box - gallery of the web UI
 * License: GPLv3 https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * Photos and videos of the mounted backup drives and sources, read from the drives through
 * touch-api.php (the same data as the photos page of the touch UI: just copied files show at once).
 *
 * <section class="lbb-gallery" data-mode="full|compact" data-limit="48"> ... </section>
 * full:    drive tabs, type filter, search, order, page size, grouped by day, pager
 * compact: drive tabs and the newest files (backup page)
 * Strings: window.LBB_GALLERY_TEXT
 */

(function () {
	'use strict';

	const API = '/touch-api.php';
	const T = window.LBB_GALLERY_TEXT || {};
	const WEB_IMAGES = ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP'];
	const STORE = 'lbb-gallery';

	function el(tag, attrs, children) {
		const node = document.createElement(tag);
		Object.entries(attrs || {}).forEach(([key, value]) => {
			if (value === null || value === undefined || value === false) return;
			if (key === 'text') node.textContent = value;
			else if (key === 'class') node.className = value;
			else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
			else node.setAttribute(key, value === true ? '' : value);
		});
		[].concat(children || []).forEach((child) => {
			if (child !== null && child !== undefined) node.append(child);
		});
		return node;
	}

	function svg(id, size) {
		const ns = 'http://www.w3.org/2000/svg';
		const node = document.createElementNS(ns, 'svg');
		node.setAttribute('width', size || 18);
		node.setAttribute('height', size || 18);
		node.setAttribute('aria-hidden', 'true');
		const use = document.createElementNS(ns, 'use');
		use.setAttribute('href', '#lbbg-' + id);
		node.append(use);
		return node;
	}

	function load(key, fallback) {
		try {
			const value = JSON.parse(localStorage.getItem(STORE) || '{}')[key];
			return value === undefined ? fallback : value;
		} catch (e) {
			return fallback;
		}
	}

	function save(key, value) {
		try {
			const all = JSON.parse(localStorage.getItem(STORE) || '{}');
			all[key] = value;
			localStorage.setItem(STORE, JSON.stringify(all));
		} catch (e) { /* private window: settings are not remembered */ }
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

	function parseDate(text) {
		// "2026-10-21 17:46" (local time of the box)
		const m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(text || '');
		return m ? new Date(+m[1], m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null;
	}

	function formatDay(date) {
		return date.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
	}

	function formatTime(date) {
		return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
	}

	function formatShort(date) {
		return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ', ' + formatTime(date);
	}

	function formatSize(bytes) {
		if (!bytes) return '';
		const units = ['B', 'KB', 'MB', 'GB'];
		let i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return (i ? bytes.toFixed(1) : bytes) + ' ' + units[i];
	}

	function typeTile(item) {
		return el('span', { class: 'lbb-g-type', text: item.ext || '?' });
	}

	/* ---------------------------------------------------------------- gallery */

	class Gallery {
		constructor(root) {
			this.root = root;
			this.full = root.dataset.mode !== 'compact';
			this.state = {
				medium: load('medium', ''),
				type: this.full ? load('type', '') : '',
				sort: this.full ? load('sort', '') : '',
				limit: this.full ? +load('limit', +root.dataset.limit || 48) : (+root.dataset.limit || 12),
				q: '',
				offset: 0,
				total: 0,
				items: [],
			};
			this.build();
			this.viewer = new Viewer(this);
			this.reload(0);

			// keep the newest files current while the page is open (a backup may be copying)
			setInterval(() => {
				if (!document.hidden && this.viewer.closed && this.state.offset === 0 && !this.loading) this.reload(0, true);
			}, 20000);
		}

		build() {
			const s = this.state;
			this.media = el('div', { class: 'lbb-g-media', role: 'tablist' });
			this.refreshButton = el('button', { type: 'button', class: 'lbb-g-icon', title: T.refresh, 'aria-label': T.refresh, onclick: () => this.reload(s.offset, false, true) }, svg('refresh'));
			this.info = el('div', { class: 'lbb-g-info', 'aria-live': 'polite' });
			const head = el('div', { class: 'lbb-g-head' }, [
				this.full ? this.info : el('h2', { text: T.latest }),
				this.full ? null : el('a', { class: 'lbb-g-open', href: '/gallery.php', text: T.open + ' →' }),
				this.refreshButton,
			]);
			this.grid = el('div', { class: 'lbb-g-grid' + (this.full ? '' : ' lbb-g-grid-compact') });
			this.root.replaceChildren(head, this.media);

			if (this.full) {
				this.types = el('div', { class: 'lbb-seg', role: 'group' }, [['', T.all], ['photo', T.photos], ['raw', T.raw], ['video', T.videos]].map(([value, label]) =>
					el('button', { type: 'button', 'data-value': value, 'aria-pressed': String(s.type === value), text: label, onclick: () => {
						s.type = value;
						save('type', value);
						this.reload(0);
					} })
				));
				this.search = el('input', { type: 'search', class: 'lbb-g-search', placeholder: T.search, 'aria-label': T.search });
				let timer;
				this.search.addEventListener('input', () => {
					clearTimeout(timer);
					timer = setTimeout(() => {
						s.q = this.search.value.trim();
						this.reload(0);
					}, 300);
				});
				const sort = el('select', { 'aria-label': T.sort_new, onchange: (e) => {
					s.sort = e.target.value;
					save('sort', s.sort);
					this.reload(0);
				} }, [['', T.sort_new], ['old', T.sort_old], ['name', T.sort_name]].map(([value, label]) => el('option', { value: value, text: label, selected: s.sort === value })));
				const limit = el('select', { 'aria-label': T.per_page, onchange: (e) => {
					s.limit = +e.target.value;
					save('limit', s.limit);
					this.reload(0);
				} }, [24, 48, 96].map((n) => el('option', { value: n, text: n + ' ' + T.per_page, selected: s.limit === n })));
				this.root.append(el('div', { class: 'lbb-g-filters' }, [this.types, this.search, sort, limit]));
			}

			if (!this.full) this.root.append(this.info);
			this.root.append(this.grid);

			if (this.full) {
				this.pager = el('nav', { class: 'lbb-g-pager', 'aria-label': T.page });
				this.root.append(this.pager);
			}
		}

		async reload(offset, quiet, refresh) {
			const s = this.state;
			// only the answer to the latest request is shown (filters can change faster than the box answers)
			const request = (this.request = (this.request || 0) + 1);
			this.loading = true;
			if (!quiet) this.root.classList.add('is-loading');
			try {
				const params = { limit: s.limit, offset: Math.max(0, offset), medium: s.medium };
				if (s.type) params.type = s.type;
				if (s.q) params.q = s.q;
				if (s.sort) params.sort = s.sort;
				if (refresh) params.refresh = 1;
				const data = await api('recent', params);
				if (request !== this.request) return;
				s.medium = data.medium || '';
				s.offset = data.offset;
				s.total = data.total;
				s.all = data.all;
				s.items = data.items;
				this.data = data;
				this.render();
				if (!quiet && this.full && offset !== undefined && this.started) this.root.scrollIntoView({ block: 'start', behavior: 'smooth' });
				this.started = true;
			} catch (e) {
				this.info.textContent = e.message;
			} finally {
				if (request === this.request) {
					this.loading = false;
					this.root.classList.remove('is-loading');
				}
			}
		}

		render() {
			const s = this.state;
			const data = this.data;

			// drive tabs
			this.media.replaceChildren();
			data.media.forEach((medium) => {
				this.media.append(el('button', { type: 'button', role: 'tab', class: 'lbb-chip', 'aria-selected': String(medium.id === data.medium), onclick: () => {
					s.medium = medium.id;
					save('medium', medium.id);
					this.reload(0);
				} }, [el('span', { text: medium.label }), el('span', { class: 'lbb-chip-count', text: medium.count })]));
			});
			if (data.can_mount_source) {
				const button = el('button', { type: 'button', class: 'lbb-chip lbb-chip-add', text: '+ ' + T.source });
				button.addEventListener('click', async () => {
					button.disabled = true;
					try {
						const result = await api('mount', { storage: 'usb', role: 'source' }, 'POST');
						if (!result.storages.some((x) => x.id === 'usb_source')) throw new Error(T.mount_failed);
						s.medium = 'source_usb';
						await this.reload(0, false, true);
					} catch (e) {
						this.info.textContent = e.message;
					} finally {
						button.disabled = false;
					}
				});
				this.media.append(button);
			}

			if (this.types) {
				this.types.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.value === s.type)));
			}

			// files
			this.grid.replaceChildren();
			if (!data.media.length) {
				this.grid.append(this.empty(T.no_drive));
			} else if (!s.items.length) {
				this.grid.append(this.empty(s.all ? T.no_match : T.no_photos));
			}

			const grouped = this.full && s.sort !== 'name';
			let day = '';
			s.items.forEach((item, index) => {
				const date = parseDate(item.date);
				if (grouped && date) {
					const key = date.toDateString();
					if (key !== day) {
						day = key;
						const count = s.items.filter((x) => { const d = parseDate(x.date); return d && d.toDateString() === key; }).length;
						this.grid.append(el('h3', { class: 'lbb-g-day' }, [el('span', { text: formatDay(date) }), el('small', { text: count })]));
					}
				}
				this.grid.append(this.tile(item, index, date));
			});

			// info and pager
			const pages = Math.max(1, Math.ceil(s.total / s.limit));
			const page = Math.floor(s.offset / s.limit) + 1;
			this.info.textContent = s.total ? (s.total + ' ' + T.files + (s.total !== s.all ? ' / ' + s.all : '') + (this.full && pages > 1 ? '  ·  ' + T.page + ' ' + page + ' / ' + pages : '')) : '';

			if (this.pager) {
				const go = (p) => this.reload((p - 1) * s.limit);
				this.pager.hidden = pages <= 1;
				this.pager.replaceChildren(
					el('button', { type: 'button', class: 'lbb-g-icon', title: T.first, 'aria-label': T.first, disabled: page <= 1, onclick: () => go(1) }, svg('first')),
					el('button', { type: 'button', title: T.previous, disabled: page <= 1, onclick: () => go(page - 1) }, [svg('prev'), el('span', { text: T.previous })]),
					el('span', { class: 'lbb-g-page', text: page + ' / ' + pages }),
					el('button', { type: 'button', title: T.next, disabled: page >= pages, onclick: () => go(page + 1) }, [el('span', { text: T.next }), svg('next')]),
					el('button', { type: 'button', class: 'lbb-g-icon', title: T.last, 'aria-label': T.last, disabled: page >= pages, onclick: () => go(pages) }, svg('last'))
				);
			}
		}

		empty(text) {
			return el('div', { class: 'lbb-g-empty' }, [svg('photo', 40), el('p', { text: text })]);
		}

		tile(item, index, date) {
			let preview = typeTile(item);
			if (item.thumb) {
				// a preview that can not be made (unknown format) falls back to the file type
				preview = el('img', { src: item.thumb, alt: '', loading: 'lazy', draggable: 'false' });
				preview.addEventListener('error', () => preview.replaceWith(typeTile(item)), { once: true });
			}
			const badges = el('span', { class: 'lbb-g-badges' }, [
				item.video ? el('span', { class: 'lbb-g-badge lbb-g-play' }, svg('play', 14)) : null,
				!item.video && !WEB_IMAGES.includes(item.ext) ? el('span', { class: 'lbb-g-badge', text: item.ext }) : null,
				item.rating > 0 ? el('span', { class: 'lbb-g-badge lbb-g-rating', text: '★'.repeat(item.rating) }) : null,
			]);
			return el('button', { type: 'button', class: 'lbb-g-tile', title: item.path || item.name, onclick: () => this.viewer.open(this.state.offset + index) }, [
				el('span', { class: 'lbb-g-thumb' }, [preview, badges]),
				el('span', { class: 'lbb-g-caption' }, [
					el('span', { class: 'lbb-g-name', text: item.name }),
					el('span', { class: 'lbb-g-meta', text: date ? (this.full && this.state.sort !== 'name' ? formatTime(date) : formatShort(date)) + (item.size ? '  ·  ' + formatSize(item.size) : '') : '' }),
				]),
			]);
		}

		// item by its position in the whole (filtered) list; loads the page it is on
		async itemAt(position) {
			const s = this.state;
			if (position < 0 || position >= s.total) return null;
			if (position < s.offset || position >= s.offset + s.items.length) {
				await this.reload(Math.floor(position / s.limit) * s.limit, true);
			}
			return s.items[position - s.offset] || null;
		}
	}

	/* ---------------------------------------------------------------- viewer */

	class Viewer {
		constructor(gallery) {
			this.gallery = gallery;
			this.closed = true;
			this.img = el('img', { class: 'lbb-v-img', alt: '' });
			this.video = el('video', { class: 'lbb-v-video', controls: true, playsinline: true, preload: 'metadata' });
			this.note = el('p', { class: 'lbb-v-note' });
			this.type = el('span', { class: 'lbb-v-type', hidden: true });
			this.title = el('h2', { class: 'lbb-v-name' });
			this.details = el('dl', { class: 'lbb-v-details' });
			this.download = el('a', { class: 'lbb-v-btn', download: '' }, [svg('download'), el('span', { text: T.download })]);
			this.counter = el('span', { class: 'lbb-v-counter' });
			this.prev = el('button', { type: 'button', class: 'lbb-v-nav lbb-v-prev', title: T.previous, 'aria-label': T.previous, onclick: () => this.step(-1) }, svg('prev', 28));
			this.next = el('button', { type: 'button', class: 'lbb-v-nav lbb-v-next', title: T.next, 'aria-label': T.next, onclick: () => this.step(1) }, svg('next', 28));
			this.stage = el('div', { class: 'lbb-v-stage' }, [this.img, this.video, this.type, this.note]);
			this.root = el('div', { class: 'lbb-viewer', role: 'dialog', 'aria-modal': 'true', hidden: true }, [
				el('div', { class: 'lbb-v-top' }, [
					this.counter,
					el('button', { type: 'button', class: 'lbb-v-btn lbb-v-close', title: T.close, 'aria-label': T.close, onclick: () => this.close() }, svg('close', 26)),
				]),
				this.stage,
				this.prev,
				this.next,
				el('aside', { class: 'lbb-v-panel' }, [this.title, this.details, el('div', { class: 'lbb-v-actions' }, [this.download])]),
			]);
			document.body.append(this.root);

			document.addEventListener('keydown', (e) => {
				if (this.closed) return;
				if (e.key === 'Escape') this.close();
				else if (e.key === 'ArrowLeft') this.step(-1);
				else if (e.key === 'ArrowRight') this.step(1);
			});

			// swipe on touch screens
			let x0 = null;
			this.stage.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
			this.stage.addEventListener('touchend', (e) => {
				if (x0 === null) return;
				const dx = e.changedTouches[0].clientX - x0;
				x0 = null;
				if (Math.abs(dx) > 50) this.step(dx < 0 ? 1 : -1);
			});
			this.stage.addEventListener('click', (e) => {
				if (e.target === this.stage) this.close();
			});
		}

		async open(position) {
			const item = await this.gallery.itemAt(position);
			if (!item) return;
			this.position = position;
			this.closed = false;
			this.root.hidden = false;
			document.documentElement.classList.add('lbb-v-open');
			this.show(item);
		}

		async step(delta) {
			const position = this.position + delta;
			if (position < 0 || position >= this.gallery.state.total) return;
			const item = await this.gallery.itemAt(position);
			if (item) {
				this.position = position;
				this.show(item);
			}
		}

		show(item) {
			const total = this.gallery.state.total;
			this.counter.textContent = (this.position + 1) + ' / ' + total;
			this.prev.disabled = this.position <= 0;
			this.next.disabled = this.position >= total - 1;
			this.stopVideo();
			this.note.hidden = true;
			this.type.hidden = true;
			this.img.hidden = true;
			this.video.hidden = true;

			if (item.video && item.file) {
				// play right away; fall back to the low resolution copy (GoPro LRV), then explain
				this.video.hidden = false;
				this.video.poster = item.thumb || '';
				let fallback = item.preview;
				this.video.onerror = () => {
					if (fallback) {
						this.video.src = fallback;
						fallback = null;
						this.video.play().catch(() => {});
					} else {
						this.video.hidden = true;
						this.showImage(item.thumb);
						this.note.textContent = T.video_unsupported;
						this.note.hidden = false;
					}
				};
				this.video.src = item.file;
				this.video.play().catch(() => {});
			} else {
				// preview at once, the full picture when the browser can show it
				this.showImage(item.thumb, WEB_IMAGES.includes(item.ext) ? item.url : null, item.ext);
			}

			this.title.textContent = item.name;
			const date = parseDate(item.date);
			const folder = item.path && item.path.includes('/') ? item.path.slice(0, item.path.lastIndexOf('/')) : '/';
			const rows = [
				[T.date, date ? formatDay(date) + ', ' + formatTime(date) : ''],
				[T.size, [formatSize(item.size), item.ext].filter(Boolean).join('  ·  ')],
				[T.folder, folder],
			];
			this.details.replaceChildren(...rows.filter((r) => r[1]).flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })]));
			this.download.hidden = !item.url;
			if (item.url) {
				this.download.href = item.url;
				this.download.setAttribute('download', item.name);
			}
		}

		showImage(thumb, full, ext) {
			const img = this.img;
			const token = (this.token = (this.token || 0) + 1);
			const none = () => {
				img.hidden = true;
				if (ext) {
					this.type.textContent = ext;
					this.type.hidden = false;
				}
			};
			this.type.hidden = true;
			img.onerror = () => { if (token === this.token) none(); };
			if (!thumb && !full) none();
			if (thumb) {
				img.src = thumb;
				img.hidden = false;
			}
			if (full) {
				const big = new Image();
				big.onload = () => {
					if (token !== this.token) return;
					img.src = full;
					img.hidden = false;
				};
				big.src = full;
			}
		}

		stopVideo() {
			this.video.onerror = null;
			this.video.pause();
			this.video.removeAttribute('src');
			this.video.removeAttribute('poster');
			this.video.load();
		}

		close() {
			this.stopVideo();
			this.token = (this.token || 0) + 1;
			this.root.hidden = true;
			this.closed = true;
			document.documentElement.classList.remove('lbb-v-open');
		}
	}

	function start() {
		document.querySelectorAll('.lbb-gallery').forEach((root) => new Gallery(root));
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();
