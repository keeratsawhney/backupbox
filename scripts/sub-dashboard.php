<?php
	# Dashboard for the start page:
	#   - quick action tiles for the main features
	#   - a "recent backups" media grid so backed-up files are shown first
	# Read-only: it never changes configuration or the image database.

	function lbb_dashboard_detect_medium($constants) {
		# Returns array(medium_key, storage_path) of the medium to preview.
		# Mirrors the auto-detection used by view.php, falling back to internal storage.
		$candidates	= array(
			'target_usb'	=> $constants['const_MOUNTPOINT_USB_TARGET'],
			'source_usb'	=> $constants['const_MOUNTPOINT_USB_SOURCE'],
			'target_nvme'	=> $constants['const_MOUNTPOINT_NVME_TARGET'],
			'source_nvme'	=> $constants['const_MOUNTPOINT_NVME_SOURCE'],
		);

		$mounted_devices_array	= array();
		exec('df -h', $mounted_devices_array);
		$mounted_devices	= implode('|', $mounted_devices_array);

		foreach ($candidates as $key => $mountpoint) {
			if (strpos($mounted_devices, $constants['const_MEDIA_DIR'] . '/' . $mountpoint)) {
				return array($key, $constants['const_MEDIA_DIR'] . '/' . $mountpoint);
			}
		}

		return array('internal', $constants['const_MEDIA_DIR'] . '/' . $constants['const_INTERNAL_BACKUP_DIR']);
	}

	function lbb_dashboard_url_path($path) {
		# URL-encode a filesystem path while keeping the slashes readable.
		return str_replace('%2F', '/', rawurlencode($path));
	}

	function lbb_dashboard_gallery($constants, $limit = 12) {
		list($medium, $storage_path)	= lbb_dashboard_detect_medium($constants);
		$database_file	= $storage_path . '/' . $constants['const_IMAGE_DATABASE_FILENAME'];

		$items		= array();
		$total		= 0;

		if (file_exists($database_file) && class_exists('SQLite3')) {
			try {
				$db	= new SQLite3($database_file, SQLITE3_OPEN_READONLY);
				$db->busyTimeout(2000);

				$countResult	= $db->querySingle('SELECT count(*) FROM EXIF_DATA;');
				$total			= $countResult === null ? 0 : (int)$countResult;

				$statement	= $db->prepare(
					'SELECT ID, Directory, File_Name, LbbRating, Create_Date, Comment, File_Type_Extension
					 FROM EXIF_DATA ORDER BY Create_Date DESC LIMIT :limit;'
				);
				$statement->bindValue(':limit', (int)$limit, SQLITE3_INTEGER);
				$result	= $statement->execute();
				while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
					$items[]	= $row;
				}
				$db->close();
			} catch (Exception $e) {
				$items	= array();
			}
		}

		return array(
			'medium'		=> $medium,
			'storage_path'	=> $storage_path,
			'items'			=> $items,
			'total'			=> $total,
			'has_db'		=> file_exists($database_file),
		);
	}

	function lbb_dashboard_tile($href, $label, $icon_id, $accent, $onclick = '') {
		?>
		<a class="lbb-tile lbb-tile-<?php echo $accent; ?>" href="<?php echo $href; ?>"<?php echo $onclick ? ' onclick="' . $onclick . '"' : ''; ?>>
			<span class="lbb-tile-ic">
				<svg width="26" height="26" aria-hidden="true"><use href="#lbbic-<?php echo $icon_id; ?>"></use></svg>
			</span>
			<span class="lbb-tile-label"><?php echo $label; ?></span>
		</a>
		<?php
	}

	function render_dashboard($constants, $config, $WORKING_DIR) {
		$gallery	= lbb_dashboard_gallery($constants, 12);
		$view_all	= '/view.php?filter_medium=' . $gallery['medium'];
		?>

		<svg width="0" height="0" style="position:absolute" aria-hidden="true">
			<symbol id="lbbic-backup" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></symbol>
			<symbol id="lbbic-files" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/></symbol>
			<symbol id="lbbic-gallery" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></symbol>
			<symbol id="lbbic-tools" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-.7-.7-2.5z"/></symbol>
			<symbol id="lbbic-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 6 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H2a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 6l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9.5A1.7 1.7 0 0 0 10.6 2V2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H22a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></symbol>
			<symbol id="lbbic-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></symbol>
			<symbol id="lbbic-empty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 16l-5-5L5 20"/></symbol>
		</svg>

		<section class="lbb-actions" aria-label="Quick actions">
			<?php
				lbb_dashboard_tile('#backup', L::mainmenue_main, 'backup', 'accent',
					"var d=document.getElementById('backup');if(d){d.open=true;d.scrollIntoView({behavior:'smooth'});}return false;");
				lbb_dashboard_tile($view_all, L::mainmenue_view, 'gallery', 'cloud');
				lbb_dashboard_tile('/frame.php?page=files', L::mainmenue_filebrowser, 'files', 'usb');
				lbb_dashboard_tile('/tools.php', L::mainmenue_tools, 'tools', 'camera');
				lbb_dashboard_tile('/setup.php', L::mainmenue_config, 'settings', 'social');
			?>
		</section>

		<section class="lbb-recent card">
			<div class="lbb-recent-head">
				<h2><?php echo L::mainmenue_view; ?></h2>
				<?php if (count($gallery['items']) > 0) { ?>
					<a class="lbb-viewall" href="<?php echo $view_all; ?>">
						<?php echo L::mainmenue_view; ?> &rarr;
						<span class="lbb-count"><?php echo $gallery['total']; ?></span>
					</a>
				<?php } ?>
			</div>

			<?php if (count($gallery['items']) > 0) { ?>
				<div class="lbb-grid">
					<?php foreach ($gallery['items'] as $item) {
						$thumb	= $gallery['storage_path'] . '/' . $item['Directory'] . '/tims/' . $item['File_Name'] . '.JPG';
						$single	= '/view.php?filter_medium=' . $gallery['medium'] . '&view_mode=single&ID=' . (int)$item['ID'];
						$ext	= strtoupper((string)$item['File_Type_Extension']);
						$is_video	= in_array($ext, array('MP4', 'MOV', 'AVI', 'MTS', 'M4V', 'MKV', '3GP'));
						?>
						<a class="lbb-card-item" href="<?php echo $single; ?>" title="<?php echo htmlspecialchars($item['File_Name']); ?>">
							<div class="lbb-thumb">
								<img loading="lazy" src="<?php echo lbb_dashboard_url_path($thumb); ?>" alt="<?php echo htmlspecialchars($item['File_Name']); ?>" onerror="this.src='/img/unknown.JPG';">
								<?php if ($is_video) { ?>
									<span class="lbb-badge-play"><svg width="16" height="16"><use href="#lbbic-play"></use></svg></span>
								<?php } ?>
								<?php if ((int)$item['LbbRating'] > 0) { ?>
									<span class="lbb-badge-rating"><?php echo str_repeat('&#9733;', (int)$item['LbbRating']); ?></span>
								<?php } ?>
							</div>
							<div class="lbb-card-meta">
								<span class="lbb-fname"><?php echo htmlspecialchars($item['File_Name']); ?></span>
								<span class="lbb-fdate"><?php echo htmlspecialchars(substr((string)$item['Create_Date'], 0, 10)); ?></span>
							</div>
						</a>
					<?php } ?>
				</div>
			<?php } else { ?>
				<div class="lbb-empty">
					<svg width="46" height="46" aria-hidden="true"><use href="#lbbic-empty"></use></svg>
					<p class="lbb-empty-title"><?php echo L::mainmenue_view; ?></p>
					<p class="lbb-empty-sub">
						<?php
							if ($gallery['has_db']) {
								echo L::main_thumbnails_header;
							} else {
								echo L::mainmenue_main . ' &rarr; ' . L::mainmenue_view;
							}
						?>
					</p>
					<a class="lbb-empty-cta" href="#backup" onclick="var d=document.getElementById('backup');if(d){d.open=true;d.scrollIntoView({behavior:'smooth'});}return false;">
						<?php echo L::mainmenue_main; ?>
					</a>
				</div>
			<?php } ?>
		</section>
		<?php
	}
?>
