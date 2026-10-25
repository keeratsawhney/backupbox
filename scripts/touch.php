<?php
/*
# Author: Stefan Saam, github@saams.de

#######################################################################
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.

# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.

# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#######################################################################*/

// Touchscreen UI for small displays attached to the box (e.g. 3.5" 480x320 SPI screens).
// All data is loaded from touch-api.php.

	$WORKING_DIR	= dirname(__FILE__);
	$config			= parse_ini_file($WORKING_DIR . "/config.cfg", false);
	$constants		= parse_ini_file($WORKING_DIR . "/constants.sh", false);

	include("sub-i18n-loader.php");
	include_once("sub-touch-services.php");

	$theme	= in_array($config['conf_THEME'], array('light', 'dark', 'sepia', 'sage')) ? $config['conf_THEME'] : 'dark';

	$strings	= array();
	foreach ((new ReflectionClass('L'))->getConstants() as $key => $value) {
		if (str_starts_with($key, 'touch_')) {
			$strings[substr($key, 6)]	= $value;
		}
	}
	$strings['reboot']		= touch_text(L::main_reboot_button);
	$strings['shutdown']	= touch_text(L::main_shutdown_button);
	$strings['finished']	= touch_text(L::box_finished);
	$strings['functions']	= array(
		'thumbnails'	=> touch_text(L::main_thumbnails_button),
		'database'		=> touch_text(L::main_database_button),
		'exif'			=> touch_text(L::main_exif_button),
		'rename'		=> touch_text(L::box_backup_mode_rename),
	);
	$strings['option_labels']	= array(
		'move_files'			=> touch_text(L::config_backup_move_files_label),
		'rename_files'			=> touch_text(L::main_backup_rename_checkbox_label),
		'generate_thumbnails'	=> touch_text(L::main_backup_generate_thumbnails_checkbox_label),
		'update_exif'			=> touch_text(L::main_backup_update_exif_checkbox_label),
		'checksum'				=> touch_text(L::main_backup_checksum_checkbox_label),
		'power_off'				=> touch_text(L::main_backup_power_off_checkbox_label),
	);
	$strings['storages']	= array(
		'usb_target'	=> touch_text(L::view_filter_medium_target_usb),
		'usb_source'	=> touch_text(L::view_filter_medium_source_usb),
		'nvme_target'	=> touch_text(L::view_filter_medium_target_nvme),
		'nvme_source'	=> touch_text(L::view_filter_medium_source_nvme),
		'cloud_target'	=> touch_text(L::box_backup_mode_cloud) . ' ' . touch_text(L::touch_target),
		'cloud_source'	=> touch_text(L::box_backup_mode_cloud) . ' ' . touch_text(L::touch_source),
		'internal'		=> touch_text(L::view_filter_medium_internal),
		'usb'			=> touch_text(L::box_backup_mode_usb),
		'nvme'			=> touch_text(L::box_backup_mode_nvme),
	);
	$strings['themes']	= array(
		'light'	=> touch_text(L::config_view_theme_light),
		'dark'	=> touch_text(L::config_view_theme_dark),
		'sepia'	=> touch_text(L::config_view_theme_sepia),
		'sage'	=> touch_text(L::config_view_theme_sage),
	);
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($config['conf_LANGUAGE'] ?: 'en'); ?>" data-theme="<?php echo $theme; ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
	<meta name="theme-color" content="#1a1f26">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="Backup Box">
	<link rel="manifest" href="manifest.json" crossorigin="use-credentials">
	<link rel="apple-touch-icon" href="img/icons/favicon-180.png">
	<title>Little Backup Box</title>
	<link rel="icon" href="favicon.ico" type="image/x-icon">
	<link rel="stylesheet" href="css/lbb-touch.css">
</head>
<body>
	<svg width="0" height="0" style="position:absolute" aria-hidden="true">
		<symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/></symbol>
		<symbol id="i-backup" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></symbol>
		<symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
		<symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></symbol>
		<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></symbol>
		<symbol id="i-photos" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></symbol>
		<symbol id="i-log" viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h6"/></symbol>
		<symbol id="i-system" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/></symbol>
		<symbol id="i-usb" viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="7" rx="1"/><path d="M5 9h14v9a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4z"/></symbol>
		<symbol id="i-internal" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 15h.01M11 15h6"/></symbol>
		<symbol id="i-nvme" viewBox="0 0 24 24"><rect x="2" y="8" width="20" height="8" rx="1"/><path d="M6 12h4M14 12h.01M17 12h.01"/></symbol>
		<symbol id="i-camera" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
		<symbol id="i-anyusb" viewBox="0 0 24 24"><path d="M12 2v14M8 6l4-4 4 4M5 12v2a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3v-2"/><circle cx="12" cy="19" r="2"/></symbol>
		<symbol id="i-cloud" viewBox="0 0 24 24"><path d="M18 10h-1.3A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></symbol>
		<symbol id="i-social" viewBox="0 0 24 24"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></symbol>
		<symbol id="i-stop" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></symbol>
		<symbol id="i-play" viewBox="0 0 24 24"><path d="M7 4v16l13-8z"/></symbol>
		<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
		<symbol id="i-left" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>
		<symbol id="i-right" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></symbol>
		<symbol id="i-up" viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></symbol>
		<symbol id="i-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
		<symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></symbol>
		<symbol id="i-edit" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
		<symbol id="i-checks" viewBox="0 0 24 24"><path d="M3 12l4 4 8-8M11 16l1 1 9-9"/></symbol>
		<symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></symbol>
		<symbol id="i-close" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
		<symbol id="i-power" viewBox="0 0 24 24"><path d="M18.4 6.6a9 9 0 1 1-12.8 0M12 2v10"/></symbol>
		<symbol id="i-reboot" viewBox="0 0 24 24"><path d="M1 4v6h6"/><path d="M3.5 15a9 9 0 1 0 2.1-9.4L1 10"/></symbol>
		<symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2 8.8a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01"/></symbol>
		<symbol id="i-web" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></symbol>
		<symbol id="i-theme" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></symbol>
		<symbol id="i-tool" viewBox="0 0 24 24"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-.7-.7-2.5z"/></symbol>
		<symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>
	</svg>

	<div class="app">
		<header class="topbar">
			<span class="brand"><img src="img/icons/favicon-180.png" alt="Your Wedding Saga"><span class="brand-name">Backup Box</span></span>
			<button class="pill power-pill" id="power-pill" aria-expanded="false" aria-controls="power-note" hidden><svg><use href="#i-bolt"/></svg><span id="power-pill-text"></span></button>
			<span class="pill" id="state-pill"><span class="dot"></span><span id="state-text">…</span></span>
			<span class="temp" id="temp"></span>
			<span class="clock" id="clock"></span>
		</header>

		<nav class="tabs" id="tabs">
			<button class="tab" data-page="home"><svg><use href="#i-home"/></svg><span></span></button>
			<button class="tab" data-page="backup"><svg><use href="#i-backup"/></svg><span></span></button>
			<button class="tab" data-page="photos"><svg><use href="#i-photos"/></svg><span></span></button>
			<button class="tab" data-page="log"><svg><use href="#i-log"/></svg><span></span></button>
			<button class="tab" data-page="files"><svg><use href="#i-folder"/></svg><span></span></button>
			<button class="tab" data-page="system"><svg><use href="#i-system"/></svg><span></span></button>
		</nav>

		<main class="pages">
			<!-- HOME -->
			<section class="page" id="page-home">
				<div class="status-card" id="status-card">
					<div class="status-main">
						<div class="status-head">
							<span class="status-title" id="status-title"></span>
							<span class="status-percent" id="status-percent"></span>
						</div>
						<div class="progress" id="progress"><div class="progress-bar" id="progress-bar"></div></div>
						<div class="flow" id="flow"></div>
						<div class="status-files" id="status-files" hidden></div>
						<div class="status-lines" id="status-lines"></div>
					</div>
					<img class="oled" id="oled" alt="" hidden>
				</div>
				<div class="actions" id="home-actions">
					<button class="btn btn-primary btn-big" id="btn-default-backup" hidden><svg><use href="#i-play"/></svg><span class="btn-text"><span id="default-label"></span><small id="default-sub"></small></span></button>
					<button class="btn btn-big" id="btn-new-backup"><svg><use href="#i-plus"/></svg><span class="btn-text" id="new-backup-label"></span></button>
					<button class="btn btn-big" id="btn-check" hidden><svg><use href="#i-photos"/></svg><span class="btn-text" id="check-label"></span></button>
					<button class="btn btn-danger btn-big" id="btn-stop" hidden><svg><use href="#i-stop"/></svg><span class="btn-text" id="stop-label"></span></button>
				</div>
				<div class="storage-strip" id="storage-strip"></div>
				<div class="home-recent" id="home-recent" hidden>
					<div class="home-recent-head"><span id="home-recent-title"></span><button class="btn" id="home-recent-all"></button></div>
					<div class="home-recent-grid" id="home-recent-grid"></div>
				</div>
			</section>

			<!-- BACKUP -->
			<section class="page" id="page-backup">
				<div class="wizard-head">
					<button class="btn btn-icon" id="wizard-back" aria-label="back"><svg><use href="#i-left"/></svg></button>
					<div class="wizard-title" id="wizard-title"></div>
					<div class="steps"><span class="step" data-step="0"></span><span class="step" data-step="1"></span><span class="step" data-step="2"></span></div>
				</div>
				<div class="wizard-body" id="wizard-body"></div>
				<div class="wizard-foot" id="wizard-foot" hidden>
					<button class="btn btn-primary btn-wide" id="wizard-start"><svg><use href="#i-play"/></svg><span id="wizard-start-label"></span></button>
				</div>
			</section>

			<!-- PHOTOS -->
			<section class="page" id="page-photos">
				<div class="photo-media" id="photo-media"></div>
				<div class="photo-grid" id="photo-grid"></div>
				<div class="pager">
					<button class="btn btn-icon" id="photos-prev"><svg><use href="#i-left"/></svg></button>
					<span class="pager-info" id="photos-info"></span>
					<button class="btn btn-icon" id="photos-next"><svg><use href="#i-right"/></svg></button>
				</div>
			</section>

			<!-- LOG -->
			<section class="page" id="page-log">
				<pre class="log" id="log"></pre>
				<div class="log-controls">
					<button class="btn btn-icon" id="log-up"><svg><use href="#i-up"/></svg></button>
					<button class="btn btn-icon" id="log-down"><svg><use href="#i-down"/></svg></button>
					<button class="btn" id="log-refresh"><svg><use href="#i-backup"/></svg><span id="log-refresh-label"></span></button>
					<button class="btn" id="log-clear"><svg><use href="#i-close"/></svg><span id="log-clear-label"></span></button>
				</div>
			</section>

			<!-- SYSTEM -->
			<!-- FILES -->
			<section class="page" id="page-files">
				<div class="photo-media" id="files-drives"></div>
				<div class="files-bar" id="files-bar">
					<button class="btn btn-icon" id="files-up" aria-label="up"><svg><use href="#i-left"/></svg></button>
					<span class="files-crumb" id="files-crumb"></span>
				</div>
				<!-- long-press a file or folder: selection with its actions -->
				<div class="files-bar files-select" id="files-select" hidden>
					<button class="btn btn-icon" id="sel-cancel" aria-label="cancel"><svg><use href="#i-close"/></svg></button>
					<span class="files-crumb" id="sel-count"></span>
					<button class="btn btn-icon" id="sel-all"><svg><use href="#i-checks"/></svg></button>
					<button class="btn btn-icon" id="sel-rename"><svg><use href="#i-edit"/></svg></button>
					<button class="btn btn-icon btn-danger" id="sel-delete"><svg><use href="#i-trash"/></svg></button>
				</div>
				<div class="files-list scroll" id="files-list"></div>
			</section>

			<section class="page" id="page-system">
				<div class="scroll" id="system-scroll">
					<div class="info-grid" id="sysinfo"></div>

					<h3 id="h-storage"></h3>
					<div class="list" id="drive-list"></div>

					<h3 id="h-settings"></h3>
					<div class="btn-row">
						<button class="btn" id="btn-theme"><svg><use href="#i-theme"/></svg><span id="theme-label"></span></button>
						<a class="btn" href="index.php" id="btn-classic"><svg><use href="#i-web"/></svg><span id="classic-label"></span></a>
						<button class="btn" id="btn-wifi" hidden><svg><use href="#i-wifi"/></svg><span id="wifi-label"></span></button>
					</div>

					<h3 id="h-power"></h3>
					<div class="btn-row">
						<button class="btn btn-warn" id="btn-reboot"><svg><use href="#i-reboot"/></svg><span id="reboot-label"></span></button>
						<button class="btn btn-danger" id="btn-shutdown"><svg><use href="#i-power"/></svg><span id="shutdown-label"></span></button>
					</div>
				</div>
			</section>
		</main>
	</div>

	<!-- photo viewer -->
	<div class="viewer" id="viewer" hidden>
		<img id="viewer-img" alt="">
		<video id="viewer-video" controls playsinline preload="metadata" hidden></video>
		<div class="viewer-caption" id="viewer-caption"></div>
		<button class="btn btn-icon viewer-close" id="viewer-close"><svg><use href="#i-close"/></svg></button>
		<button class="btn btn-icon viewer-prev" id="viewer-prev"><svg><use href="#i-left"/></svg></button>
		<button class="btn btn-icon viewer-next" id="viewer-next"><svg><use href="#i-right"/></svg></button>
		<a class="btn btn-icon viewer-download" id="viewer-download" download hidden aria-label="download"><svg><use href="#i-download"/></svg></a>
	</div>

	<!-- confirm dialog -->
	<div class="modal" id="modal" hidden>
		<div class="modal-box">
			<p id="modal-text"></p>
			<p class="modal-sub" id="modal-sub"></p>
			<input class="modal-input" id="modal-input" type="text" autocomplete="off" spellcheck="false" hidden>
			<div class="modal-buttons">
				<button class="btn" id="modal-cancel"></button>
				<button class="btn btn-primary" id="modal-ok"></button>
			</div>
		</div>
	</div>

	<!-- one drive: what it is used for and what can be done with it -->
	<div class="sheet" id="drive-sheet" role="dialog" hidden>
		<div class="sheet-box">
			<div class="sheet-head">
				<span class="row-label"><b id="sheet-title"></b><small id="sheet-sub"></small></span>
				<button class="btn btn-icon" id="sheet-close"><svg><use href="#i-close"/></svg></button>
			</div>
			<div class="sheet-body" id="sheet-body"></div>
		</div>
	</div>

	<!-- comitup: connect the box to a Wi-Fi (only while its hotspot is on) -->
	<div class="sheet" id="wifi-sheet" role="dialog" hidden>
		<div class="sheet-box sheet-frame">
			<div class="sheet-head">
				<span class="row-label"><b id="wifi-title"></b><small id="wifi-sub"></small></span>
				<button class="btn btn-icon" id="wifi-close"><svg><use href="#i-close"/></svg></button>
			</div>
		</div>
	</div>

	<!-- full screen message (power off, reboot) -->
	<div class="overlay" id="overlay" hidden><p id="overlay-text"></p></div>

	<div class="power-note" id="power-note" role="status" hidden><span id="power-note-text"></span><button class="btn btn-icon" id="power-note-close"><svg><use href="#i-close"/></svg></button></div>
	<div class="toast" id="toast" hidden></div>
	<div class="offline" id="offline" hidden></div>

	<script>
		window.LBB_TOUCH = {
			strings: <?php echo json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
			theme: <?php echo json_encode($theme); ?>
		};
	</script>
	<script src="js/lbb-touch.js"></script>
</body>
</html>
