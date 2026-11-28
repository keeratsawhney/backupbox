<?php
// The on-screen keyboard of the box's own screen (the kiosk browser): only for a browser on the box itself,
// and only while it is not switched off in the settings. Included by the head of the pages.
if (in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
	$kiosk_keyboard_on		= !isset($config['conf_VIRTUAL_KEYBOARD_ENABLED']) || in_array(strtolower((string)$config['conf_VIRTUAL_KEYBOARD_ENABLED']), array('1', 'true'), true);
	if ($kiosk_keyboard_on) {
		$kiosk_keyboard_layout	= in_array($config['conf_LANGUAGE'] ?? 'en', array('de', 'en', 'fr', 'es'), true) ? $config['conf_LANGUAGE'] : 'en';
		echo '<link rel="stylesheet" href="js/vendor/kioskboard/kioskboard.min.css">' . "\n";
		echo '<link rel="stylesheet" href="js/vendor/kioskboard/lbb-kioskboard.css">' . "\n";
		echo '<script>window.LBB_KEYBOARD = {layout: ' . json_encode($kiosk_keyboard_layout) . '};</script>' . "\n";
		echo '<script src="js/vendor/kioskboard/kioskboard-aio.min.js"></script>' . "\n";
		echo '<script src="js/lbb-keyboard.js"></script>' . "\n";
	}
}
