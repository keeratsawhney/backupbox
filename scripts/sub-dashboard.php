<?php
	# Mounted drives with photos for the gallery (sub-gallery.php, touch UI) and view.php:
	# which drives are mounted, the one to show first, labels and web paths.
	# Read-only: it never changes configuration or the image database.

	function lbb_dashboard_media($constants) {
		# All media that can hold backups, in the order view.php prefers them,
		# with mount state and the number of files in their image database.
		$mountpoints	= array(
			'target_usb'	=> $constants['const_MOUNTPOINT_USB_TARGET'],
			'source_usb'	=> $constants['const_MOUNTPOINT_USB_SOURCE'],
			'target_nvme'	=> $constants['const_MOUNTPOINT_NVME_TARGET'],
			'source_nvme'	=> $constants['const_MOUNTPOINT_NVME_SOURCE'],
		);

		$mounted	= array();
		foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: array() as $mount) {
			$fields	= explode(' ', $mount);
			if (count($fields) >= 2) {
				$mounted[str_replace('\\040', ' ', $fields[1])]	= true;
			}
		}

		$media	= array();
		foreach ($mountpoints as $key => $mountpoint) {
			$path	= $constants['const_MEDIA_DIR'] . '/' . $mountpoint;
			$media[$key]	= array('path' => $path, 'mounted' => isset($mounted[$path]));
		}
		# internal storage is a folder on the system disk, always available
		$media['internal']	= array('path' => $constants['const_MEDIA_DIR'] . '/' . $constants['const_INTERNAL_BACKUP_DIR'], 'mounted' => true);

		foreach ($media as $key => $medium) {
			$database_file	= $medium['path'] . '/' . $constants['const_IMAGE_DATABASE_FILENAME'];
			$media[$key]['has_db']	= $medium['mounted'] && file_exists($database_file);
			$media[$key]['count']	= 0;
			if ($media[$key]['has_db'] && class_exists('SQLite3')) {
				try {
					$db	= new SQLite3($database_file, SQLITE3_OPEN_READONLY);
					$db->busyTimeout(2000);
					$media[$key]['count']	= (int)$db->querySingle("SELECT count(*) FROM EXIF_DATA WHERE File_Name NOT LIKE '.%';");
					$db->close();
				} catch (Exception $e) {
					$media[$key]['count']	= 0;
				}
			}
		}

		return $media;
	}

	function lbb_dashboard_backup_running($constants) {
		# backup.py process (started by the web UI, the display menu or autorun); read from /proc, no external tools needed
		# compare whole arguments: a shell command line that only mentions backup.py is one argument
		foreach (glob('/proc/[0-9]*/cmdline') ?: array() as $cmdline_file) {
			$args	= explode("\0", (string)@file_get_contents($cmdline_file));
			foreach ($args as $index => $arg) {
				if (basename($arg) === 'backup.py' and in_array('--SourceName', array_slice($args, $index + 1), true)) {
					return true;
				}
			}
		}

		# fallback: backup.py keeps task files while working; trust them only while its messages are fresh
		$tasks	= glob($constants['const_TASKS_PATH'] . '/*.txt') ?: array();
		$recent	= max((int)@filemtime($constants['const_DISPLAY_CONTENT_OLD_FILE']), (int)@filemtime($constants['const_DISPLAY_CONTENT_PATH']));
		return count($tasks) > 0 && time() - $recent < 60;
	}

	function lbb_dashboard_automount($constants, $media) {
		# Backups unmount all storage when they finish. Mount an attached NVMe SSD / USB drive again,
		# so its photos are shown instead of the internal storage. Returns true if anything was mounted.
		# never interfere with a running backup, it manages the mounts itself
		if (lbb_dashboard_backup_running($constants)) {
			return false;
		}

		$WORKING_DIR	= dirname(__FILE__);
		$mounted_any	= false;
		foreach (lbb_dashboard_automount_storages() as $storage) {
			if ($media['target_' . $storage]['mounted'] or $media['source_' . $storage]['mounted']) {
				continue;
			}

			$partitions	= array();
			exec("sudo python3 $WORKING_DIR/lib_storage.py --Action get_available_partitions --StorageName $storage --skipMounted True 2>/dev/null", $partitions);
			if (count(array_filter($partitions, 'trim')) == 0) {
				continue;
			}

			exec("sudo python3 $WORKING_DIR/lib_storage.py --Action mount --StorageName $storage --Role target --WaitForDevice False > /dev/null 2>&1");
			$mounted_any	= true;
		}

		return $mounted_any;
	}

	function lbb_dashboard_automount_storages() {
		# Only the drive type backups go to: with a USB target and a camera card attached,
		# mounting "a USB drive" as target could pick the card. An NVMe SSD is never a camera card.
		$config	= @parse_ini_file(dirname(__FILE__) . '/config.cfg', false) ?: array();
		$default	= isset($config['conf_BACKUP_DEFAULT_TARGET']) ? $config['conf_BACKUP_DEFAULT_TARGET'] : '';
		return $default == 'usb' ? array() : array('nvme');
	}

	function lbb_dashboard_storage_order() {
		# the default backup target holds the backups: check it first
		$config	= @parse_ini_file(dirname(__FILE__) . '/config.cfg', false) ?: array();
		$default	= isset($config['conf_BACKUP_DEFAULT_TARGET']) ? $config['conf_BACKUP_DEFAULT_TARGET'] : '';
		return $default == 'usb' ? array('usb', 'nvme') : array('nvme', 'usb');
	}

	function lbb_dashboard_detect_medium($constants, $media = null) {
		# Returns array(medium_key, storage_path) of the medium to preview:
		# the first mounted medium with backed-up files, else the first mounted one, else internal storage.
		$media	= $media === null ? lbb_dashboard_media($constants) : $media;

		# default backup target first, then the order of view.php
		$order	= array();
		foreach (lbb_dashboard_storage_order() as $storage) {
			$order[]	= 'target_' . $storage;
		}
		$order	= array_unique(array_merge($order, array_keys($media)));

		foreach ($order as $key) {
			if ($media[$key]['count'] > 0) {
				return array($key, $media[$key]['path']);
			}
		}
		foreach ($order as $key) {
			if ($media[$key]['mounted'] && $key != 'internal') {
				return array($key, $media[$key]['path']);
			}
		}

		return array('internal', $media['internal']['path']);
	}

	function lbb_dashboard_medium_label($medium) {
		# e.g. "Local NVMe target", same wording as the view filter
		$constant	= 'L::view_filter_medium_' . $medium;
		return defined($constant) ? constant($constant) : $medium;
	}

	function lbb_dashboard_url_path($path) {
		# URL-encode a filesystem path while keeping the slashes readable.
		return str_replace('%2F', '/', rawurlencode($path));
	}
?>
