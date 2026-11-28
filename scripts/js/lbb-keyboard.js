// On-screen keyboard for the box's own screen (the kiosk browser): KioskBoard on every text field, also the ones that
// appear later (the Wi-Fi password, a new folder name). Loaded from the pages themselves (sub-kiosk-keyboard.php) for
// the box's own browser only; it does not depend on a browser add-on. The add-on of the kiosk uses the same marks, so
// a field never gets two keyboards.
(function () {
	'use strict';

	if (typeof KioskBoard === 'undefined') return;
	const root = document.documentElement;
	if (!root || root.dataset.lbbPageKeyboard) return;
	root.dataset.lbbPageKeyboard = '1';

	const layouts = { de: 'german', en: 'english', fr: 'french', es: 'spanish' };
	const config = window.LBB_KEYBOARD || {};
	const layout = layouts[config.layout] ? config.layout : 'en';

	const SELECTOR = [
		'textarea', 'input:not([type])', 'input[type="text"]', 'input[type="search"]', 'input[type="email"]',
		'input[type="url"]', 'input[type="tel"]', 'input[type="number"]', 'input[type="password"]',
	].join(',');

	const options = {
		keysArrayOfObjects: null,
		keysJsonUrl: 'js/vendor/kioskboard/keys/kioskboard-keys-' + layouts[layout] + '.json',
		keysSpecialCharsArrayOfStrings: [
			'@', '.', '-', '_', '/', ':', ';', '?', '!', '#', '+', '*', '=', '&',
			'ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', 'é', 'è', 'ê', 'ë', 'ç',
			'á', 'à', 'â', 'í', 'ì', 'î', 'ó', 'ò', 'ô', 'ú', 'ù', 'û', 'ñ', '¿', '¡',
		],
		keysNumpadArrayOfNumbers: null,
		language: layout,
		theme: root.dataset.theme === 'light' || root.dataset.theme === 'sage' ? 'light' : 'dark',
		autoScroll: true,
		capsLockActive: false,
		allowRealKeyboard: true,
		allowMobileKeyboard: false,
		cssAnimations: false,
		keysAllowSpacebar: true,
		keysSpacebarText: 'Space',
		keysEnterText: 'Enter',
		keysFontFamily: 'sans-serif',
		keysFontSize: '18px',
		keysFontWeight: 'normal',
	};

	function usable(node) {
		return node instanceof HTMLElement && node.matches(SELECTOR) && !node.readOnly && !node.disabled && !node.closest('[data-lbb-kioskboard-ignore]');
	}

	function prepare(node) {
		if (!usable(node) || node.dataset.lbbKioskboardInitialized === '1') return;
		node.classList.add('js-lbb-kioskboard-input');
		node.dataset.kioskboardType = node.dataset.kioskboardType || (node.tagName === 'TEXTAREA' ? 'all' : ((node.getAttribute('type') || '').toLowerCase() === 'number' ? 'numpad' : 'all'));
		node.dataset.kioskboardPlacement = node.dataset.kioskboardPlacement || 'bottom';
		node.dataset.kioskboardSpecialcharacters = node.dataset.kioskboardSpecialcharacters || 'true';
		try {
			KioskBoard.run(node, options);
			node.dataset.lbbKioskboardInitialized = '1';
		} catch (e) {
			console.warn('keyboard:', e);
		}
	}

	function tree(node) {
		if (!node) return;
		if (usable(node)) prepare(node);
		if (node.querySelectorAll) node.querySelectorAll(SELECTOR).forEach(prepare);
	}

	tree(document);
	document.addEventListener('focusin', (event) => prepare(event.target), true);
	new MutationObserver((mutations) => {
		mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => { if (node instanceof HTMLElement) tree(node); }));
	}).observe(root, { childList: true, subtree: true });
})();
