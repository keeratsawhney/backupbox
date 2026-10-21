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

// JSON backend of the touchscreen UI (touch.php).
// GET  ?action=status|services|recent|log|sysinfo
// POST action=backup|stop|function|power|mount|umount  (header X-LBB-Touch required)

	// included subs print html (doctype, comments), keep it out of the json
	ob_start();

	$WORKING_DIR	= dirname(__FILE__);
	$config			= parse_ini_file($WORKING_DIR . "/config.cfg", false);
	$constants		= parse_ini_file($WORKING_DIR . "/constants.sh", false);

	include("sub-i18n-loader.php");
	include_once("sub-touch-services.php");
	include_once("sub-dashboard.php");

	function touch_respond($data, $status = 200) {
		while (ob_get_level()) {
			ob_end_clean();
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		exit();
	}

	function touch_fail($message, $status = 400) {
		touch_respond(array('ok' => false, 'error' => $message), $status);
	}

	function touch_bool($value) {
		return(in_array($value, array(true, 1, '1', 'true', 'True', 'on'), true) ? 'True' : 'False');
	}

	function touch_backup_running() {
		exec("pgrep -f " . escapeshellarg("backup\\.py --SourceName"), $pids, $return_code);
		return($return_code == 0);
	}

	function touch_display_lines($constants) {
		# the latest message of the backup process, as shown on the hardware display
		$files	= glob($constants['const_DISPLAY_CONTENT_PATH'] . '/*.txt') ?: array();
		sort($files);
		$files	= array_reverse($files);
		$files[]	= $constants['const_DISPLAY_CONTENT_OLD_FILE'];

		# display.py deletes content files once shown: fall back to the next one
		$raw	= false;
		$file	= '';
		foreach ($files as $file) {
			$raw	= @file($file, FILE_IGNORE_NEW_LINES);
			if ($raw !== false) {
				break;
			}
		}
		if ($raw === false) {
			$raw	= array();
			$file	= '';
		}

		$entries	= array();
		foreach ($raw as $line) {
			$line	= trim($line);
			if ($line === '' or str_starts_with($line, 'set:')) {
				continue;
			}

			# split format prefix (":text", "s=hc:text", ...)
			$format	= '';
			if (preg_match('/^(s=([a-z]*))?:(.*)$/', $line, $matches)) {
				$format	= $matches[2];
				$line	= trim($matches[3]);
			}
			$entries[]	= array('format' => $format, 'text' => $line, 'pgbar' => str_starts_with($line, 'PGBAR='));
		}

		# display.py appends lines of older messages (always as "s=b") to the shown file:
		# the current message ends at its first progress bar or its last non-basic line
		$cut	= -1;
		foreach ($entries as $index => $entry) {
			if ($entry['format'] !== 'b') {
				$cut	= $index;
			}
		}
		foreach ($entries as $index => $entry) {
			if ($entry['pgbar']) {
				$cut	= max($cut, $index);
				break;
			}
		}
		if ($cut >= 0) {
			$entries	= array_slice($entries, 0, $cut + 1);
		}

		$lines		= array();
		$progress	= null;
		$alert		= false;
		foreach ($entries as $entry) {
			if (str_contains($entry['format'], 'a')) {
				$alert	= true;
			}
			if ($entry['pgbar']) {
				$progress	= (float)preg_replace('/[^0-9\.]/', '', '0' . substr($entry['text'], 6));
				$progress	= max(0, min(100, $progress));
			} elseif ($entry['text'] !== '') {
				$lines[]	= $entry['text'];
			}
		}

		return(array(
			'lines'		=> array_slice($lines, 0, 8),
			'progress'	=> $progress,
			'alert'		=> $alert,
			'time'		=> $file !== '' ? @filemtime($file) : null,
		));
	}

	function touch_oled($config, $constants) {
		# mirror of the hardware display, written by display.py
		if ($config['conf_DISP'] != 'display') {
			return(null);
		}
		$image	= $constants['const_DISPLAY_IMAGE_EXPORT_FILE'];
		clearstatcache(true, $image);
		$time	= @filemtime($image);
		return($time === false ? null : array(
			'url'		=> str_replace($constants['const_WEB_ROOT_LBB'], '', $image) . '?t=' . $time,
			'width'		=> (int)$config['conf_DISP_RESOLUTION_X'],
			'height'	=> (int)$config['conf_DISP_RESOLUTION_Y'],
		));
	}

	function touch_storages($constants) {
		# mounted storages with their disk usage, read from /proc/mounts (cheap enough to poll)
		$mountpoints	= array(
			'usb_target'	=> $constants['const_MOUNTPOINT_USB_TARGET'],
			'usb_source'	=> $constants['const_MOUNTPOINT_USB_SOURCE'],
			'nvme_target'	=> $constants['const_MOUNTPOINT_NVME_TARGET'],
			'nvme_source'	=> $constants['const_MOUNTPOINT_NVME_SOURCE'],
			'cloud_target'	=> $constants['const_MOUNTPOINT_CLOUD_TARGET'],
			'cloud_source'	=> $constants['const_MOUNTPOINT_CLOUD_SOURCE'],
		);

		$mounted	= array();
		foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: array() as $mount) {
			$fields	= explode(' ', $mount);
			if (count($fields) >= 3) {
				$mounted[str_replace('\\040', ' ', $fields[1])]	= $fields[0];
			}
		}

		$storages	= array();
		$add	= function($id, $path, $device) use (&$storages) {
			$total	= @disk_total_space($path);
			$free	= @disk_free_space($path);
			$storages[]	= array(
				'id'		=> $id,
				'path'		=> $path,
				'device'	=> $device,
				'total'		=> $total === false ? null : $total,
				'free'		=> $free === false ? null : $free,
			);
		};

		foreach ($mountpoints as $id => $mountpoint) {
			$path	= $constants['const_MEDIA_DIR'] . '/' . $mountpoint;
			if (isset($mounted[$path])) {
				$add($id, $path, $mounted[$path]);
			}
		}

		$add('internal', $constants['const_MEDIA_DIR'] . '/' . $constants['const_INTERNAL_BACKUP_DIR'], '');

		return($storages);
	}

	function touch_cpu_temp() {
		$temp	= @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
		return($temp === false ? null : round((int)$temp / 1000, 1));
	}

	function touch_run_backup($WORKING_DIR, $args) {
		shell_exec("sudo $WORKING_DIR/stop_backup.sh");
		shell_exec("sudo python3 $WORKING_DIR/backup.py $args > /dev/null 2>&1 &");
	}

	function touch_finish_request() {
		# answer the browser first, then do long running work
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
	}

	$action	= isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		# simple cross site request protection: a html form can not set custom headers
		if (!isset($_SERVER['HTTP_X_LBB_TOUCH'])) {
			touch_fail('missing header', 403);
		}
	}

	switch ($action) {

		case 'status':
			$display	= touch_display_lines($constants);
			touch_respond(array(
				'ok'		=> true,
				'running'	=> touch_backup_running(),
				'display'	=> $display,
				'oled'		=> touch_oled($config, $constants),
				'storages'	=> touch_storages($constants),
				'temp'		=> touch_cpu_temp(),
				'time'		=> time(),
			));

		case 'services':
			$services	= touch_get_services($config, $constants, $WORKING_DIR);

			$allowed	= array();
			foreach ($services['sources'] as $source) {
				foreach ($services['targets'] as $target) {
					if (touch_combination_allowed($source['id'], $target['id'])) {
						$allowed[$source['id']][]	= $target['id'];
					}
				}
			}

			touch_respond(array(
				'ok'		=> true,
				'sources'	=> $services['sources'],
				'targets'	=> $services['targets'],
				'allowed'	=> $allowed,
				'nvme'		=> $services['nvme'],
				'defaults'	=> array(
					'source'				=> $config['conf_BACKUP_DEFAULT_SOURCE'],
					'target'				=> $config['conf_BACKUP_DEFAULT_TARGET'],
					'move_files'			=> (bool)$config['conf_BACKUP_MOVE_FILES'],
					'rename_files'			=> (bool)$config['conf_BACKUP_RENAME_FILES'],
					'generate_thumbnails'	=> (bool)$config['conf_BACKUP_GENERATE_THUMBNAILS'],
					'update_exif'			=> (bool)$config['conf_BACKUP_UPDATE_EXIF'],
					'checksum'				=> (bool)$config['conf_BACKUP_CHECKSUM'],
					'power_off'				=> (bool)$config['conf_POWER_OFF'],
				),
			));

		case 'recent':
			$limit		= isset($_GET['limit']) ? max(1, min(48, (int)$_GET['limit'])) : 12;
			$offset		= isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
			$gallery	= lbb_dashboard_gallery($constants, $limit, $offset);

			$items	= array();
			foreach ($gallery['items'] as $item) {
				$ext	= strtoupper((string)$item['File_Type_Extension']);
				$items[]	= array(
					'id'		=> (int)$item['ID'],
					'name'		=> $item['File_Name'],
					'date'		=> substr((string)$item['Create_Date'], 0, 16),
					'rating'	=> (int)$item['LbbRating'],
					'video'		=> in_array($ext, array('MP4', 'MOV', 'AVI', 'MTS', 'M4V', 'MKV', '3GP', 'LRV')),
					'thumb'		=> lbb_dashboard_url_path($gallery['storage_path'] . '/' . $item['Directory'] . '/tims/' . $item['File_Name'] . '.JPG'),
				);
			}

			touch_respond(array(
				'ok'		=> true,
				'medium'	=> $gallery['medium'],
				'total'		=> $gallery['total'],
				'has_db'	=> $gallery['has_db'],
				'offset'	=> $offset,
				'items'		=> $items,
			));

		case 'log':
			$lines	= isset($_GET['lines']) ? max(10, min(1000, (int)$_GET['lines'])) : 200;
			$log	= array();
			if (is_file($constants['const_LOGFILE'])) {
				exec("tail -n " . (int)$lines . " " . escapeshellarg($constants['const_LOGFILE']), $log);
			}
			touch_respond(array('ok' => true, 'lines' => $log));

		case 'sysinfo':
			$ips	= preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null')), -1, PREG_SPLIT_NO_EMPTY);
			$model	= trim(str_replace("\0", '', (string)@file_get_contents('/proc/device-tree/model')));
			$uptime	= (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
			$load	= function_exists('sys_getloadavg') ? sys_getloadavg() : array();

			$meminfo	= array();
			foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: array() as $line) {
				if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
					$meminfo[$matches[1]]	= (int)$matches[2] * 1024;
				}
			}

			touch_respond(array(
				'ok'		=> true,
				'hostname'	=> gethostname(),
				'ips'		=> array_values(array_filter($ips, function($ip) {return(!str_starts_with($ip, 'fe80'));})),
				'model'		=> $model,
				'uptime'	=> (int)$uptime,
				'load'		=> $load,
				'temp'		=> touch_cpu_temp(),
				'mem_total'	=> isset($meminfo['MemTotal']) ? $meminfo['MemTotal'] : null,
				'mem_avail'	=> isset($meminfo['MemAvailable']) ? $meminfo['MemAvailable'] : null,
				'branch'	=> isset($constants['const_SOFTWARE_BRANCH']) ? $constants['const_SOFTWARE_BRANCH'] : '',
				'storages'	=> touch_storages($constants),
			));

		case 'backup':
			$Source	= isset($_POST['source']) ? (string)$_POST['source'] : '';
			$Target	= isset($_POST['target']) ? (string)$_POST['target'] : '';

			# format check only, listing all services is slow; backup.py validates the names itself
			if (!preg_match('/^(anyusb|usb|internal|nvme|camera|ftp|cloud_rsync|cloud:[\w .@-]+)$/', $Source) or !preg_match('/^(usb|internal|nvme|cloud_rsync|cloud:[\w .@-]+|social:[a-z]+)$/', $Target)) {
				touch_fail('unknown source or target');
			}
			if (!touch_combination_allowed($Source, $Target)) {
				touch_fail(touch_text(L::box_backup_invalid_mode_combination_1 . ' ' . L::box_backup_invalid_mode_combination_2));
			}

			$args	= "--SourceName " . escapeshellarg($Source) .
				" --TargetName " . escapeshellarg($Target) .
				" --move-files " . touch_bool($_POST['move_files'] ?? false) .
				" --rename-files " . touch_bool($_POST['rename_files'] ?? false) .
				" --force-sync-database False" .
				" --generate-thumbnails " . touch_bool($_POST['generate_thumbnails'] ?? false) .
				" --update-exif " . touch_bool($_POST['update_exif'] ?? false) .
				" --checksum " . touch_bool($_POST['checksum'] ?? false) .
				" --power-off " . touch_bool($_POST['power_off'] ?? false);

			touch_run_backup($WORKING_DIR, $args);
			touch_respond(array('ok' => true, 'message' => touch_text(L::main_backup_backup . ' ' . touch_service_label($Source) . ' ' . L::main_backup_to . ' ' . touch_service_label($Target) . ' ' . L::main_backup_initiated . '.')));

		case 'function':
			$Function	= isset($_POST['function']) ? (string)$_POST['function'] : '';
			$Target		= isset($_POST['target']) ? (string)$_POST['target'] : '';

			$functions	= array(
				# function => rename, sync database, thumbnails, exif
				'thumbnails'	=> array('False', 'False', 'True', 'False', L::main_function_thumbnails),
				'database'		=> array('False', 'True', 'False', 'False', L::main_function_sync),
				'exif'			=> array('False', 'False', 'False', 'True', L::main_function_exif),
				'rename'		=> array('True', 'False', 'False', 'False', L::main_backup_rename_checkbox_label),
			);
			if (!isset($functions[$Function]) or !in_array($Target, array('usb', 'internal', 'nvme'), true)) {
				touch_fail('unknown function or target');
			}
			[$rename, $sync, $thumbnails, $exif, $label]	= $functions[$Function];

			touch_run_backup($WORKING_DIR, "--SourceName $Function --TargetName $Target --rename-files $rename --force-sync-database $sync --generate-thumbnails $thumbnails --update-exif $exif --power-off False");
			touch_respond(array('ok' => true, 'message' => touch_text($label . ' ' . L::main_backup_on . ' ' . touch_service_label($Target) . '.')));

		case 'stop':
			shell_exec("sudo $WORKING_DIR/stop_backup.sh");
			touch_respond(array('ok' => true, 'message' => touch_text(L::main_stopbackup_m)));

		case 'mount':
		case 'umount':
			$Storage	= isset($_POST['storage']) ? (string)$_POST['storage'] : '';
			$Role		= isset($_POST['role']) ? (string)$_POST['role'] : '';
			if (!in_array($Storage, array('usb', 'nvme'), true) or !in_array($Role, array('source', 'target'), true)) {
				touch_fail('unknown storage');
			}
			$command	= "sudo python3 $WORKING_DIR/lib_storage.py --Action $action --StorageName $Storage --Role $Role" . ($action == 'mount' ? ' --WaitForDevice False' : '');
			shell_exec("python3 $WORKING_DIR/lib_log.py 'execute' '' " . escapeshellarg($command) . " '1'");
			touch_respond(array('ok' => true, 'storages' => touch_storages($constants)));

		case 'power':
			$mode	= isset($_POST['mode']) ? (string)$_POST['mode'] : '';
			if (!in_array($mode, array('reboot', 'poweroff'), true)) {
				touch_fail('unknown power mode');
			}
			ignore_user_abort(true);
			$message	= $mode == 'reboot' ? L::main_reboot_m : L::main_shutdown_m;
			while (ob_get_level()) {
				ob_end_clean();
			}
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('ok' => true, 'message' => touch_text($message)), JSON_UNESCAPED_UNICODE);
			flush();
			touch_finish_request();
			exec("sudo python3 $WORKING_DIR/lib_poweroff.py $mode > /dev/null 2>&1 &");
			exit();

		default:
			touch_fail('unknown action');
	}
?>
