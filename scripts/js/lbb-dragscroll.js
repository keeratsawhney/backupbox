// Scrolling by dragging, for a touchscreen that behaves like a mouse (a drag selects text instead of scrolling).
//
// Loaded in two places, for every page of the box's own screen (the kiosk browser):
//  - by the touch UI (touch.php)
//  - by a small add-on of the kiosk browser (install-kiosk-tweaks.sh) in every page and frame, e.g. the full web interface
// Whoever comes first sets the mark on <html>, so the drag is never handled twice.
//
// A mouse-style drag scrolls the list (or the page) under it, like a finger on a phone: it moves once per frame, keeps
// gliding after a flick and slows down. Real touch input scrolls natively and is left alone. A drag from a button does
// not press it, and no text is selected while dragging.
(function () {
	'use strict';

	const root = document.documentElement;
	if (!root || root.dataset.lbbDragScroll) return;
	root.dataset.lbbDragScroll = '1';

	// The classic pages (bootstrap) set scroll-behavior: smooth: every position the drag sets would then glide to its
	// place a moment later, which feels laggy and jumpy. While dragging, a position has to be set at once.
	const instant = document.createElement('style');
	instant.textContent = 'html, body, * { scroll-behavior: auto !important; }'
		// while dragging nothing is selected, dragged or hovered (hovering a big page on every move makes it slow)
		+ ' html[data-lbb-dragging] * { user-select: none !important; -moz-user-select: none !important; pointer-events: none !important; }';
	(document.head || root).appendChild(instant);

	let drag = null;
	let suppressClick = false;
	let frame = null;
	let glide = null;

	function scroller(node, axis) {
		for (; node && node !== document.documentElement && node !== document.body; node = node.parentElement) {
			const style = getComputedStyle(node);
			if (axis === 'y' && node.scrollHeight > node.clientHeight + 1 && ['auto', 'scroll'].includes(style.overflowY)) return node;
			if (axis === 'x' && node.scrollWidth > node.clientWidth + 1 && ['auto', 'scroll'].includes(style.overflowX)) return node;
		}
		// no list under the finger: the page itself (the full web interface scrolls as a page)
		const page = document.scrollingElement;
		if (page && axis === 'y' && page.scrollHeight > page.clientHeight + 1) return page;
		if (page && axis === 'x' && page.scrollWidth > page.clientWidth + 1) return page;
		return null;
	}

	const position = (node, axis) => (axis === 'x' ? node.scrollLeft : node.scrollTop);
	const place = (node, axis, value) => {
		if (axis === 'x') node.scrollLeft = value;
		else node.scrollTop = value;
	};

	// after the finger lifts the list keeps gliding and slows down, like on a phone
	function stopGlide() {
		if (glide) cancelAnimationFrame(glide.frame);
		glide = null;
	}

	function startGlide(node, axis, speed) {
		stopGlide();
		glide = { last: performance.now(), frame: 0 };
		const step = (now) => {
			if (!glide) return;
			const dt = Math.min(now - glide.last, 50);
			glide.last = now;
			const before = position(node, axis);
			place(node, axis, before + speed * dt);
			speed *= Math.exp(-dt / 320);
			if (Math.abs(speed) < 0.02 || position(node, axis) === before) {
				glide = null;
				return;
			}
			glide.frame = requestAnimationFrame(step);
		};
		glide.frame = requestAnimationFrame(step);
	}

	document.addEventListener('pointerdown', (event) => {
		stopGlide();
		if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('input, textarea, select')) return;
		root.dataset.lbbDragging = '1';
	drag = { target: event.target, x: event.clientX, y: event.clientY, node: null, moved: false, samples: [], target_position: 0 };
	});

	document.addEventListener('pointermove', (event) => {
		if (!drag || !(event.buttons & 1)) return;
		const dx = event.clientX - drag.x;
		const dy = event.clientY - drag.y;
		if (!drag.moved) {
			if (Math.hypot(dx, dy) < 8) return;
			drag.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
			drag.node = scroller(drag.target, drag.axis);
			if (!drag.node) {
				delete root.dataset.lbbDragging;
				drag = null;
				return;
			}
			drag.moved = true;
			drag.start = position(drag.node, drag.axis);
			window.getSelection()?.removeAllRanges();
		}
		drag.target_position = drag.start - (drag.axis === 'x' ? dx : dy);
		drag.samples.push({ t: event.timeStamp, p: drag.target_position });
		if (drag.samples.length > 6) drag.samples.shift();
		// once per frame, not once per event (a screen that sends many events makes it jerky)
		if (!frame) {
			frame = requestAnimationFrame(() => {
				frame = null;
				if (drag && drag.moved) place(drag.node, drag.axis, drag.target_position);
			});
		}
		event.preventDefault();
	});

	const end = (event) => {
		if (drag && drag.moved) {
			place(drag.node, drag.axis, drag.target_position);
			// the click that follows the drag is not a tap on a button
			suppressClick = true;
			setTimeout(() => { suppressClick = false; }, 60);
			// the speed of the last moments: the glide
			const last = drag.samples[drag.samples.length - 1];
			const first = drag.samples.find((sample) => last.t - sample.t <= 100);
			if (event.type === 'pointerup' && first && last.t > first.t && event.timeStamp - last.t < 80) {
				const speed = (last.p - first.p) / (last.t - first.t);
				if (Math.abs(speed) > 0.1) startGlide(drag.node, drag.axis, speed);
			}
		}
		delete root.dataset.lbbDragging;
		drag = null;
	};
	// the browser starts selecting text or dragging a link or picture at the press: stop that (not in fields)
	document.addEventListener('mousedown', (event) => {
		if (event.button === 0 && !event.target.closest('input, textarea, select')) event.preventDefault();
	});
	document.addEventListener('selectstart', (event) => { if (drag) event.preventDefault(); });
	document.addEventListener('dragstart', (event) => event.preventDefault());
	document.addEventListener('pointerup', end);
	document.addEventListener('pointercancel', end);
	document.addEventListener('click', (event) => {
		if (!suppressClick) return;
		event.stopPropagation();
		event.preventDefault();
	}, true);
})();
