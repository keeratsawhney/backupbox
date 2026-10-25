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
// GET  ?action=status|services|recent|log|sysinfo|wifi
// POST action=backup|stop|function|power|mount|umount|wifi_connect|wifi_forget  (header X-LBB-Touch required)

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
		global $constants;
		return(lbb_dashboard_backup_running($constants));
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
			$entries[]	= array('format' => $format, 'text' => $line, 'pgbar' => str_starts_with($line, 'PGBAR='), 'image' => str_starts_with($line, 'IMAGE='));
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
			if ($entry['pgbar'] or $entry['image']) {
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
		$image		= null;
		foreach ($entries as $entry) {
			if (str_contains($entry['format'], 'a')) {
				$alert	= true;
			}
			if ($entry['image']) {
				# only pictures inside the web root can be shown in the browser
				$path	= trim(substr($entry['text'], 6));
				if ($image === null and str_starts_with($path, $constants['const_WEB_ROOT_LBB'] . '/') and is_file($path)) {
					$image	= substr($path, strlen($constants['const_WEB_ROOT_LBB'])) . '?t=' . @filemtime($path);
				}
			} elseif ($entry['pgbar']) {
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
			'image'		=> $image,
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

	function touch_drive_name($device) {
		# name of the physical drive, so users can tell source and target apart:
		# the file system label, else the drive model (read from sysfs, cheap enough to poll)
		if (!str_starts_with($device, '/dev/')) {
			# cloud mounts ("remote:") and internal storage
			return(rtrim($device, ':'));
		}

		foreach (glob('/dev/disk/by-label/*') ?: array() as $link) {
			if (realpath($link) === realpath($device)) {
				# udev escapes special characters, e.g. "\x20" for a space
				return(preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', function($m) {return(chr(hexdec($m[1])));}, basename($link)));
			}
		}

		$disk	= preg_match('#^/dev/(nvme\d+n\d+|mmcblk\d+)#', $device, $matches) ? $matches[1] : preg_replace('#^/dev/([a-z]+)\d*$#', '$1', $device);
		$model	= trim((string)@file_get_contents("/sys/block/$disk/device/model"));
		return($model !== '' ? $model : basename($device));
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
				'name'		=> touch_drive_name($device),
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

		return($storages);
	}

	function touch_power($constants) {
		# under-voltage (weak power supply): USB drives disconnect, backups stop half way.
		# vcgencmd get_throttled: bit 0 = under-voltage now, bit 16 = since boot. Cached, it is polled.
		$cache	= $constants['const_WEB_ROOT_LBB'] . '/tmp/touch-throttled.txt';
		if (time() - (int)@filemtime($cache) > 30) {
			$output	= (string)shell_exec('sudo -n /usr/bin/vcgencmd get_throttled 2>/dev/null');
			@file_put_contents($cache, $output);
		} else {
			$output	= (string)@file_get_contents($cache);
		}
		if (!preg_match('/throttled=0x([0-9a-fA-F]+)/', $output, $matches)) {
			return(null);
		}
		$flags	= hexdec($matches[1]);
		return(array('undervoltage' => (bool)($flags & 0x1), 'undervoltage_since_boot' => (bool)($flags & 0x10000)));
	}

	function touch_background($constants) {
		# thumbnails and database made after a backup (background.py): files done of all, or null
		$done	= 0;
		$total	= 0;
		foreach (glob($constants['const_WEB_ROOT_LBB'] . '/tmp/background/*.json') ?: array() as $file) {
			$data	= @json_decode((string)@file_get_contents($file), true);
			# a job ended by a power off leaves its file behind
			if (!is_array($data) or !isset($data['pid']) or !file_exists('/proc/' . (int)$data['pid'])) {
				continue;
			}
			$done	+= (int)$data['done'];
			$total	+= (int)$data['total'];
		}
		return($total > 0 ? array('done' => $done, 'total' => $total) : null);
	}

	function touch_copying($constants) {
		# names of the files being copied (written by lib_backup.progressmonitor while copying)
		$file	= $constants['const_WEB_ROOT_LBB'] . '/tmp/backup-files.json';
		clearstatcache(true, $file);
		if (!is_file($file) or time() - (int)@filemtime($file) > 60) {
			return(null);
		}
		$data	= @json_decode((string)@file_get_contents($file), true);
		return(is_array($data) ? $data : null);
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

	function touch_parse_drives($blockdevices) {
		# partitions of attached drives (lsblk -J output), without the system disk and
		# without the EFI partition many USB drives carry next to the data partition
		$drives	= array();
		foreach ($blockdevices as $disk) {
			if (!preg_match('#^/dev/(sd[a-z]+|nvme\\d+n\\d+)$#', (string)$disk['path'])) {
				continue;
			}
			$partitions	= isset($disk['children']) ? $disk['children'] : array($disk);
			$system		= false;
			foreach ($partitions as $partition) {
				if (in_array($partition['mountpoint'], array('/', '/boot', '/boot/firmware', '[SWAP]'), true)) {
					$system	= true;
				}
			}
			if ($system) {
				continue;
			}
			foreach ($partitions as $partition) {
				$size	= (int)$partition['size'];
				if (empty($partition['fstype']) or $partition['fstype'] == 'swap') {
					continue;
				}
				# EFI system partition (drives formatted on a Mac, many SSDs), not a data partition
				if (strtoupper((string)$partition['label']) == 'EFI' or in_array(strtolower((string)($partition['parttype'] ?? '')), array('c12a7328-f81f-11d2-ba4b-00a0c93ec93b', '0xef'), true)) {
					continue;
				}
				$drives[]	= array(
					'path'			=> $partition['path'],
					'disk'			=> $disk['path'],
					'uuid'			=> (string)($partition['uuid'] ?? ''),
					'kind'			=> str_starts_with($disk['path'], '/dev/nvme') ? 'nvme' : 'usb',
					'name'			=> trim((string)($partition['label'] ?: ($disk['model'] ?: basename($partition['path'])))),
					'size'			=> $size,
					'mountpoint'	=> $partition['mountpoint'],
					'fstype'		=> $partition['fstype'],
					'label'			=> (string)($partition['label'] ?? ''),
				);
			}
		}
		return($drives);
	}

	function touch_attached_drives() {
		$json	= json_decode((string)shell_exec('lsblk -J -b -o NAME,PATH,SIZE,FSTYPE,LABEL,UUID,PARTTYPE,MOUNTPOINT,TYPE,MODEL 2>/dev/null'), true);
		return(touch_parse_drives(is_array($json) && isset($json['blockdevices']) ? $json['blockdevices'] : array()));
	}

	function touch_target_choice($constants, $choice = false) {
		# the drive picked as backup target on the home screen; kept until it is unplugged
		# (backups unmount all drives when they finish, the chosen one is mounted again)
		$file	= $constants['const_WEB_ROOT_LBB'] . '/tmp/touch-target.json';
		if ($choice === false) {
			$saved	= @json_decode((string)@file_get_contents($file), true);
			return(is_array($saved) ? $saved : null);
		}
		if ($choice === null) {
			@unlink($file);
		} else {
			@file_put_contents($file, json_encode($choice));
		}
		return($choice);
	}

	function touch_mount_target($WORKING_DIR, $drive, $background = false) {
		$command	= "sudo python3 $WORKING_DIR/lib_storage.py --Action mount --StorageName " . escapeshellarg($drive['kind']) . " --Role target --DeviceIdentifierPresetThis " . escapeshellarg($drive['path']) . " --WaitForDevice False > /dev/null 2>&1";
		shell_exec($background ? "$command &" : $command);
	}

	function touch_flow($config, $constants, $storages, $running) {
		# what the home screen guides through: 1. choose the backup drive, 2. insert the source
		global $WORKING_DIR;
		$drives		= touch_attached_drives();
		$choice		= touch_target_choice($constants);
		$mounted	= array();
		foreach ($storages as $storage) {
			$mounted[$storage['id']]	= $storage;
		}

		$find	= function($key, $value) use ($drives) {
			foreach ($drives as $drive) {
				if ($value !== '' and $drive[$key] === $value) {
					return($drive);
				}
			}
			return(null);
		};

		# target: a mounted target drive, else the remembered one
		$target	= null;
		foreach (array('usb_target', 'nvme_target') as $id) {
			if (isset($mounted[$id])) {
				# match by mount point (as lsblk reports it), device names are only the fallback
				$drive	= $find('mountpoint', $mounted[$id]['path']) ?: $find('path', (string)realpath($mounted[$id]['device'])) ?: $find('path', $mounted[$id]['device']);
				$target	= array(
					'state'	=> 'ready',
					'id'	=> $id,
					'name'	=> $drive ? $drive['name'] : $mounted[$id]['name'],
					'free'	=> $mounted[$id]['free'],
					'total'	=> $mounted[$id]['total'],
					'disk'	=> $drive ? $drive['disk'] : '',
					'path'	=> $drive ? $drive['path'] : $mounted[$id]['device'],
				);
				if ($drive and (!$choice or $choice['id'] !== ($drive['uuid'] ?: $drive['path']))) {
					$choice	= touch_target_choice($constants, array('id' => $drive['uuid'] ?: $drive['path'], 'kind' => $drive['kind'], 'name' => $drive['name']));
				}
				break;
			}
		}

		if (!$target and $choice) {
			$drive	= $find('uuid', $choice['id']) ?: $find('path', $choice['id']);
			if ($drive) {
				$target	= array('state' => 'mounting', 'id' => $drive['kind'] . '_target', 'name' => $drive['name'], 'free' => null, 'total' => $drive['size'], 'disk' => $drive['disk'], 'path' => $drive['path']);
				# mounted again in the background (throttled, never during a backup)
				$stamp	= $constants['const_WEB_ROOT_LBB'] . '/tmp/touch-automount.stamp';
				if (!$running and !touch_disk_task_running($constants) and empty($drive['mountpoint']) and time() - (int)@filemtime($stamp) > 20) {
					@touch($stamp);
					touch_mount_target($WORKING_DIR, $drive, true);
				}
			} else {
				# unplugged: ask again
				touch_target_choice($constants, null);
			}
		}

		# no target: the attached drives to choose from
		$source_mountpoints	= array(
			$constants['const_MEDIA_DIR'] . '/' . $constants['const_MOUNTPOINT_USB_SOURCE'],
			$constants['const_MEDIA_DIR'] . '/' . $constants['const_MOUNTPOINT_NVME_SOURCE'],
		);
		$candidates	= array();
		if (!$target) {
			foreach ($drives as $drive) {
				if (empty($drive['mountpoint']) or in_array($drive['mountpoint'], $source_mountpoints, true) or str_starts_with($drive['mountpoint'], touch_browse_dir($constants) . '/')) {
					$candidates[]	= array('path' => $drive['path'], 'name' => $drive['name'], 'size' => $drive['size'], 'kind' => $drive['kind']);
				}
			}
		}

		# sources: cards and USB drives that are not the target
		$sources	= array();
		foreach ($drives as $drive) {
			if ($drive['kind'] == 'usb' and !($target and $drive['disk'] === $target['disk'])) {
				$sources[]	= array('path' => $drive['path'], 'name' => $drive['name'], 'size' => $drive['size']);
			}
		}

		return(array(
			'target'		=> $target ?: array('state' => 'choose', 'id' => '', 'name' => '', 'path' => ''),
			'candidates'	=> $candidates,
			'sources'		=> $target ? $sources : array(),
		));
	}

	function touch_media_extensions($constants) {
		# file types shown in the photo views (same lists as backups and view.php)
		$extensions	= array();
		foreach (array('WEB_IMAGES', 'HEIC', 'RAW', 'TIF', 'VIDEO') as $list) {
			$extensions	= array_merge($extensions, explode(';', $constants['const_FILE_EXTENSIONS_LIST_' . $list]));
		}
		return(array_values(array_unique(array_filter(array_map('strtolower', $extensions)))));
	}

	function touch_medium_role_label($media_key) {
		# what a mounted drive is for, in plain words: the backup drive or the card / drive copied from
		if (str_starts_with($media_key, 'target_')) {
			return(touch_text(L::touch_drive_backup));
		}
		if (str_starts_with($media_key, 'source_')) {
			return(touch_text(L::touch_drive_source));
		}
		return(touch_text(lbb_dashboard_medium_label($media_key)));
	}

	function touch_find_drive($path) {
		foreach (touch_attached_drives() as $drive) {
			if ($drive['path'] === $path) {
				return($drive);
			}
		}
		return(null);
	}

	function touch_drive_role($constants, $drive) {
		# what the box uses a drive for: target_usb / source_usb / target_nvme / source_nvme, browse (read only), other (mounted by
		# something else), '' (not mounted)
		$mountpoint	= (string)$drive['mountpoint'];
		if ($mountpoint === '') {
			return('');
		}
		foreach (array('target_usb' => 'const_MOUNTPOINT_USB_TARGET', 'source_usb' => 'const_MOUNTPOINT_USB_SOURCE', 'target_nvme' => 'const_MOUNTPOINT_NVME_TARGET', 'source_nvme' => 'const_MOUNTPOINT_NVME_SOURCE') as $role => $constant) {
			if ($mountpoint === $constants['const_MEDIA_DIR'] . '/' . $constants[$constant]) {
				return($role);
			}
		}
		return(str_starts_with($mountpoint, touch_browse_dir($constants) . '/') ? 'browse' : 'other');
	}

	function touch_release_drive($constants, $drive, $forget = true) {
		# unmount a drive whatever mounted it. $forget: the chosen backup drive is forgotten, else the home screen mounts it
		# again (a check or repair keeps it: the home screen waits until the task is finished)
		global $WORKING_DIR;
		$choice	= touch_target_choice($constants);
		if ($forget and $choice and $choice['id'] === ($drive['uuid'] ?: $drive['path'])) {
			touch_target_choice($constants, null);
		}
		# a drive can be busy for a moment (previews still being read): a few tries
		for ($try = 0; $try < 6; $try++) {
			$current	= touch_find_drive($drive['path']);
			if (!$current or empty($current['mountpoint'])) {
				return(true);
			}
			$role	= touch_drive_role($constants, $current);
			if (in_array($role, array('target_usb', 'source_usb', 'target_nvme', 'source_nvme'), true)) {
				[$r, $k]	= explode('_', $role);
				shell_exec("sudo python3 $WORKING_DIR/lib_storage.py --Action umount --StorageName $k --Role $r > /dev/null 2>&1");
			} elseif ($role === 'browse') {
				touch_browse_umount($constants, $current['mountpoint']);
			} elseif ($role === 'other') {
				shell_exec('sudo umount ' . escapeshellarg($current['mountpoint']) . ' > /dev/null 2>&1');
			}
			usleep(800000);
		}
		$after	= touch_find_drive($drive['path']);
		return(!$after or empty($after['mountpoint']));
	}

	# disk tasks (check, repair, format): one at a time, run as root in the background, output kept for the UI
	function touch_disk_task_files($constants) {
		$dir	= $constants['const_WEB_ROOT_LBB'] . '/tmp';
		return(array($dir . '/touch-disk-task.json', $dir . '/touch-disk-task.log', $dir . '/touch-disk-task.exit'));
	}

	function touch_disk_task_running($constants) {
		[$json, $log, $exit]	= touch_disk_task_files($constants);
		$task	= @json_decode((string)@file_get_contents($json), true);
		return(is_array($task) and !is_file($exit) and is_dir('/proc/' . (int)$task['pid']));
	}

	function touch_disk_task_status($constants) {
		[$json, $log, $exit]	= touch_disk_task_files($constants);
		$task	= @json_decode((string)@file_get_contents($json), true);
		if (!is_array($task)) {
			return(null);
		}
		$lines	= @file($log, FILE_IGNORE_NEW_LINES) ?: array();
		$code	= is_file($exit) ? (int)trim((string)@file_get_contents($exit)) : null;
		return(array(
			'title'		=> $task['title'],
			'device'	=> $task['device'],
			'started'	=> $task['started'],
			'running'	=> $code === null and is_dir('/proc/' . (int)$task['pid']),
			'exit'		=> $code,
			'log'		=> array_slice($lines, -60),
		));
	}

	function touch_disk_task_start($constants, $title, $device, $commands) {
		# $commands: shell commands run one after another; the first is the task, its exit code is the result
		# (the others only show the outcome, e.g. lsblk after formatting)
		[$json, $log, $exit]	= touch_disk_task_files($constants);
		@unlink($exit);
		$script	= '';
		foreach (array_values($commands) as $index => $command) {
			$script	.= 'echo ' . escapeshellarg('$ ' . $command) . '; ' . $command . ($index === 0 ? '; rc=$?; ' : '; ');
		}
		$script	.= 'sync; echo $rc > ' . escapeshellarg($exit);
		$pid	= (int)shell_exec('sudo sh -c ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!');
		@file_put_contents($json, json_encode(array('title' => $title, 'device' => $device, 'pid' => $pid, 'started' => time())));
	}

	function touch_fsck_command($fstype, $repair, $device) {
		$d	= escapeshellarg($device);
		switch ($fstype) {
			case 'exfat':	return($repair ? "fsck.exfat -p $d" : "fsck.exfat -n $d");
			case 'vfat':	return($repair ? "fsck.vfat -a $d" : "fsck.vfat -n $d");
			case 'ext2':
			case 'ext3':
			case 'ext4':	return($repair ? "e2fsck -f -p $d" : "e2fsck -f -n $d");
			case 'ntfs':
			case 'ntfs3':	return($repair ? "ntfsfix $d" : "ntfsfix -n $d");
			case 'hfsplus':	return($repair ? "fsck.hfsplus -p $d" : "fsck.hfsplus -n $d");
		}
		return(null);
	}

	function touch_format_command($fs, $device, $label) {
		# the drive keeps its name (label) where the file system allows it
		$d	= escapeshellarg($device);
		switch ($fs) {
			case 'exfat':	return('mkfs.exfat -L ' . escapeshellarg(substr($label, 0, 15)) . " $d");
			case 'vfat':	return('mkfs.vfat -F 32 -I -n ' . escapeshellarg(strtoupper(substr($label, 0, 11))) . " $d");
			case 'ext4':	return('mkfs.ext4 -F -L ' . escapeshellarg(substr($label, 0, 16)) . " $d");
			case 'ntfs':	return('mkfs.ntfs -f -L ' . escapeshellarg(substr($label, 0, 32)) . " $d");
		}
		return(null);
	}

	# file operations of the Files tab: paths are relative to the root of a mounted drive and must stay inside it
	function touch_file_target($root, $relative) {
		$relative	= trim(str_replace('\\', '/', (string)$relative), '/');
		if ($relative === '' or in_array('..', explode('/', $relative), true)) {
			return(false);
		}
		$full	= realpath($root . '/' . $relative);
		if ($full === false or !str_starts_with($full, $root . '/')) {
			return(false);
		}
		return($full);
	}

	function touch_valid_file_name($name) {
		$name	= trim((string)$name);
		return($name !== '' and $name !== '.' and $name !== '..' and !str_starts_with($name, '.') and strpbrk($name, "/\\\0") === false and strlen($name) <= 255);
	}

	function touch_drive_openable($constants, $drive) {
		# a drive the box has not mounted itself (as backup drive, source or read only):
		# not mounted at all, or mounted by something else (e.g. the desktop's automount under /media/<user>)
		$mountpoint	= (string)$drive['mountpoint'];
		if ($mountpoint === '') {
			return(true);
		}
		foreach (array('const_MOUNTPOINT_USB_TARGET', 'const_MOUNTPOINT_USB_SOURCE', 'const_MOUNTPOINT_NVME_TARGET', 'const_MOUNTPOINT_NVME_SOURCE') as $constant) {
			if ($mountpoint === $constants['const_MEDIA_DIR'] . '/' . $constants[$constant]) {
				return(false);
			}
		}
		return(!str_starts_with($mountpoint, touch_browse_dir($constants) . '/'));
	}

	function touch_media_filter($constants, $files, $type, $query, $sort) {
		# type: photo, raw, video ('' = all); query: part of the path;
		# sort: '' added to the drive (newest first, as listed), new / old: date of the file, name
		$lists	= array(
			'raw'	=> explode(';', $constants['const_FILE_EXTENSIONS_LIST_RAW']),
			'video'	=> array_merge(explode(';', $constants['const_FILE_EXTENSIONS_LIST_VIDEO']), array('mov', 'mts', 'm4v', 'mkv', '3gp')),
		);
		$query	= trim((string)$query);

		if ($type !== '' or $query !== '') {
			$files	= array_values(array_filter($files, function($file) use ($lists, $type, $query) {
				$ext	= strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
				if ($type == 'raw' and !in_array($ext, $lists['raw'], true)) {return(false);}
				if ($type == 'video' and !in_array($ext, $lists['video'], true)) {return(false);}
				if ($type == 'photo' and (in_array($ext, $lists['raw'], true) or in_array($ext, $lists['video'], true))) {return(false);}
				return($query === '' or stripos($file['path'], $query) !== false);
			}));
		}

		if ($sort == 'new' or $sort == 'old') {
			usort($files, function($a, $b) use ($sort) {return($sort == 'new' ? $b['time'] <=> $a['time'] : $a['time'] <=> $b['time']);});
		} elseif ($sort == 'name') {
			usort($files, function($a, $b) {return(strnatcasecmp(basename($a['path']), basename($b['path'])));});
		}

		return($files);
	}

	function touch_is_video($ext, $constants) {
		$videos	= array_merge(explode(';', $constants['const_FILE_EXTENSIONS_LIST_VIDEO']), array('mov', 'mts', 'm4v', 'mkv', '3gp'));
		return(in_array(strtolower($ext), $videos, true));
	}

	function touch_browse_dir($constants) {
		# drives opened in the file browser are mounted read only below this folder, without a backup role
		return($constants['const_MEDIA_DIR'] . '/browse');
	}

	function touch_browse_key($drive) {
		return('browse:' . preg_replace('/[^A-Za-z0-9_-]/', '', $drive['uuid'] ?: basename($drive['path'])));
	}

	function touch_browse_mount($constants, $drive) {
		$mountpoint	= touch_browse_dir($constants) . '/' . substr(touch_browse_key($drive), 7);
		if (!empty($drive['mountpoint']) and touch_drive_openable($constants, $drive)) {
			shell_exec('sudo umount ' . escapeshellarg($drive['mountpoint']) . ' > /dev/null 2>&1');
		}
		# FAT/exFAT/NTFS have no owners: make the files readable for the web server
		$options	= in_array($drive['fstype'], array('vfat', 'exfat', 'ntfs', 'ntfs3'), true) ? 'ro,uid=33,gid=33' : 'ro';
		shell_exec('sudo mkdir -p ' . escapeshellarg($mountpoint) . ' && sudo mount -o ' . $options . ' ' . escapeshellarg($drive['path']) . ' ' . escapeshellarg($mountpoint) . ' > /dev/null 2>&1');
	}

	function touch_browse_umount($constants, $mountpoint) {
		if (str_starts_with($mountpoint, touch_browse_dir($constants) . '/')) {
			shell_exec('sudo umount ' . escapeshellarg($mountpoint) . ' > /dev/null 2>&1; sudo rmdir ' . escapeshellarg($mountpoint) . ' > /dev/null 2>&1');
		}
	}

	function touch_medium_path($constants, $key) {
		# mount path of a mounted medium, false otherwise (cheap: no database access)
		if (str_starts_with($key, 'browse:')) {
			$path	= touch_browse_dir($constants) . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', substr($key, 7));
			return(str_contains((string)@file_get_contents('/proc/mounts'), ' ' . $path . ' ') ? $path : false);
		}
		$mountpoints	= array(
			'target_usb'	=> $constants['const_MOUNTPOINT_USB_TARGET'],
			'source_usb'	=> $constants['const_MOUNTPOINT_USB_SOURCE'],
			'target_nvme'	=> $constants['const_MOUNTPOINT_NVME_TARGET'],
			'source_nvme'	=> $constants['const_MOUNTPOINT_NVME_SOURCE'],
		);
		if ($key == 'internal') {
			return($constants['const_MEDIA_DIR'] . '/' . $constants['const_INTERNAL_BACKUP_DIR']);
		}
		if (!isset($mountpoints[$key])) {
			return(false);
		}
		$path	= $constants['const_MEDIA_DIR'] . '/' . $mountpoints[$key];
		return(str_contains((string)@file_get_contents('/proc/mounts'), ' ' . $path . ' ') ? $path : false);
	}

	function touch_media_files($constants, $key, $path, $refresh = false) {
		# photos and videos on a drive, the ones added to the drive last first, read from the file system (just copied
		# files show at once). 'time': date of the file (kept from the camera by the backup), 'added': when it was written
		# to the drive (birth time; rsync writes every file new), the date of the file where the drive does not know it.
		# Cached briefly per mounted file system: paging through a big drive should not list it again and again.
		$stat	= @stat($path);
		$id		= $stat ? $stat['dev'] . '-' . $stat['ino'] : '';
		$cache	= $constants['const_WEB_ROOT_LBB'] . '/tmp/touch-files-' . preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(':', '-', $key)) . '.json';
		$cached	= $refresh ? null : @json_decode((string)@file_get_contents($cache), true);
		if (is_array($cached) and $cached['id'] === $id and time() - $cached['time'] < 15 and isset($cached['version'])) {
			return($cached['files']);
		}

		$names	= array();
		foreach (touch_media_extensions($constants) as $ext) {
			$names[]	= '-iname ' . escapeshellarg('*.' . $ext);
		}
		$banned	= array();
		foreach (explode(';', $constants['const_VIEW_BANNED_PATHS']) as $pattern) {
			if ($pattern !== '') {
				$banned[]	= '-not -ipath ' . escapeshellarg($pattern);
			}
		}

		$lines	= array();
		# without hidden files and folders (macOS "._name" metadata files, .Spotlight-V100, .Trashes, ...)
		# stat in batches: find can not print the birth time
		exec('find ' . escapeshellarg($path) . ' -type f \\( ' . implode(' -o ', $names) . ' \\) -not -path ' . escapeshellarg('*/tims/*') . ' -not -path ' . escapeshellarg('*/.*') . ' ' . implode(' ', $banned) . " -print0 2>/dev/null | xargs -0 -r stat -c '%Y\t%W\t%n' 2>/dev/null", $lines);

		$prefix	= rtrim($path, '/') . '/';
		$files	= array();
		foreach ($lines as $line) {
			$parts	= explode("\t", $line, 3);
			if (count($parts) == 3 and str_starts_with($parts[2], $prefix)) {
				$time	= (int)$parts[0];
				$born	= (int)$parts[1];
				$files[]	= array('path' => substr($parts[2], strlen($prefix)), 'time' => $time, 'added' => $born > 0 ? $born : $time);
			}
		}
		usort($files, function($a, $b) {return(($b['added'] <=> $a['added']) ?: ($b['time'] <=> $a['time']));});

		@file_put_contents($cache, json_encode(array('id' => $id, 'time' => time(), 'version' => 2, 'files' => $files)));
		return($files);
	}

	function touch_media_item($constants, $medium, $storage_path, $relative, $extra) {
		$file	= $storage_path . '/' . $relative;
		$name	= basename($relative);
		$ext	= strtoupper(pathinfo($name, PATHINFO_EXTENSION));
		$tims	= dirname($file) . '/tims/' . $name . '.JPG';

		$item	= array_merge(array(
			'name'		=> $name,
			'ext'		=> $ext,
			'date'		=> '',
			'rating'	=> 0,
			'video'		=> touch_is_video($ext, $constants),
			'url'		=> lbb_dashboard_url_path($file),
			# thumbnail made during backup, else a preview made on demand
			'thumb'		=> file_exists($tims) ? lbb_dashboard_url_path($tims) : 'touch-api.php?action=thumb&medium=' . rawurlencode($medium) . '&path=' . rawurlencode($relative),
		), $extra);

		if ($item['video']) {
			$item['file']	= lbb_dashboard_url_path($file);
			# GoPro low resolution copy (GH010001.MP4 -> GL010001.LRV), plays where the original codec (H.265) does not
			$preview	= dirname($file) . '/' . substr_replace(pathinfo($file, PATHINFO_FILENAME), 'L', 1, 1) . '.LRV';
			$item['preview']	= file_exists($preview) ? lbb_dashboard_url_path($preview) : null;
		}

		return($item);
	}

	function touch_make_thumb($source, $thumb, $video, $raw) {
		# preview for a file without thumbnail: a video frame, the preview image embedded by the camera,
		# or the scaled picture. Never written to the drive, only into the cache on the system disk.
		$s		= escapeshellarg($source);
		$t		= escapeshellarg($thumb);
		$tmp	= $thumb . '.tmp';
		$magick	= is_executable('/usr/bin/magick') ? '/usr/bin/magick' : '/usr/bin/convert';

		if ($video) {
			# keyframes only: no decoding of every frame up to the second (4K HEVC is slow to decode on a Pi)
			$errors	= array();
			foreach (array('1', '0') as $second) {
				$output	= array();
				exec("timeout 60 ffmpeg -nostdin -loglevel error -skip_frame nokey -ss $second -i $s -an -sn -dn -frames:v 1 -vf scale=480:-2 -y -f image2 $t 2>&1", $output, $rc);
				if (@filesize($thumb) > 0) {
					return(null);
				}
				$errors[]	= $rc == 124 ? 'timeout' : (end($output) ?: "exit $rc");
			}
			return(implode('; ', $errors));
		}

		if (!$raw) {
			exec("timeout 30 $magick -define jpeg:size=960x960 " . escapeshellarg($source . '[0]') . " -auto-orient -thumbnail 480x480 -quality 80 jpg:$t 2>/dev/null");
			if (@filesize($thumb) > 0) {
				return;
			}
		}

		# the embedded preview is stored unrotated: the raw file says how to turn it
		$rotate	= array('3' => 180, '6' => 90, '8' => 270)[trim((string)shell_exec("timeout 20 exiftool -n -s3 -Orientation $s 2>/dev/null"))] ?? 0;
		foreach (array('PreviewImage', 'JpgFromRaw', 'ThumbnailImage') as $tag) {
			exec("timeout 20 exiftool -b -$tag $s > " . escapeshellarg($tmp) . " 2>/dev/null");
			if (@filesize($tmp) > 0) {
				exec("timeout 30 $magick " . escapeshellarg($tmp) . " -rotate $rotate -thumbnail 480x480 -quality 80 jpg:$t 2>/dev/null");
				break;
			}
		}
		@unlink($tmp);
	}

	# ---------- Wi-Fi (NetworkManager) ----------

	function touch_nmcli($args) {
		# nmcli in terse mode: one array of fields per line ("\:" is a colon inside a field)
		$rows	= array();
		foreach (explode("\n", trim((string)shell_exec('sudo -n nmcli -t ' . $args . ' 2>/dev/null'))) as $line) {
			if ($line === '') {
				continue;
			}
			$rows[]	= array_map(function($field) {return(str_replace(array('\\:', '\\\\'), array(':', '\\'), $field));}, preg_split('/(?<!\\\\):/', $line));
		}
		return($rows);
	}

	function touch_wifi_device() {
		# the first Wi-Fi interface: array(device, state, connection) or null
		foreach (touch_nmcli('-f DEVICE,TYPE,STATE,CONNECTION device') as $row) {
			if (count($row) >= 4 and $row[1] === 'wifi') {
				return(array('device' => $row[0], 'state' => $row[2], 'connection' => $row[3] === '--' ? '' : $row[3]));
			}
		}
		return(null);
	}

	function touch_wifi_connections() {
		# saved Wi-Fi connections: name => array(ssid, mode) (mode 'ap': comitup's hotspot)
		$connections	= array();
		foreach (touch_nmcli('-f NAME,TYPE connection show') as $row) {
			if (count($row) >= 2 and $row[1] === '802-11-wireless') {
				$values	= array();
				foreach (touch_nmcli('-f 802-11-wireless.ssid,802-11-wireless.mode connection show id ' . escapeshellarg($row[0])) as $field) {
					$values[$field[0]]	= isset($field[1]) ? $field[1] : '';
				}
				$connections[$row[0]]	= array(
					'ssid'	=> isset($values['802-11-wireless.ssid']) ? $values['802-11-wireless.ssid'] : $row[0],
					'mode'	=> isset($values['802-11-wireless.mode']) ? $values['802-11-wireless.mode'] : '',
				);
			}
		}
		return($connections);
	}

	function touch_wifi_task_file($constants, $ext) {
		return($constants['const_WEB_ROOT_LBB'] . '/tmp/touch-wifi.' . $ext);
	}

	function touch_wifi_task($constants) {
		# the last connect request: array(ssid, running, ok, message) or null
		$ssid	= @file_get_contents(touch_wifi_task_file($constants, 'ssid'));
		if ($ssid === false) {
			return(null);
		}
		$exit	= @file_get_contents(touch_wifi_task_file($constants, 'exit'));
		$log	= trim((string)@file_get_contents(touch_wifi_task_file($constants, 'log')));
		return(array(
			'ssid'		=> $ssid,
			'running'	=> $exit === false,
			'ok'		=> $exit !== false && trim($exit) === '0',
			'message'	=> $log,
		));
	}

	$action	= isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		# simple cross site request protection: a html form can not set custom headers
		if (!isset($_SERVER['HTTP_X_LBB_TOUCH'])) {
			touch_fail('missing header', 403);
		}
	}

	switch ($action) {

		case 'ping':
			# the app's box search: an answer at once, also while a backup keeps the box busy
			touch_respond(array('ok' => true, 'box' => 'backupbox'));

		case 'status':
			$display	= touch_display_lines($constants);
			$running	= touch_backup_running();
			$storages	= touch_storages($constants);
			touch_respond(array(
				'ok'		=> true,
				'running'	=> $running,
				'display'	=> $display,
				'oled'		=> touch_oled($config, $constants),
				'storages'	=> $storages,
				'flow'		=> touch_flow($config, $constants, $storages, $running),
				'power'		=> touch_power($constants),
				'background'	=> touch_background($constants),
				'copying'	=> $running ? touch_copying($constants) : null,
				'temp'		=> touch_cpu_temp(),
				'time'		=> time(),
			));

		case 'copying':
			# polled twice a second while a backup runs: only reads the file list written by the backup
			touch_respond(array('ok' => true, 'copying' => touch_copying($constants), 'running' => touch_backup_running()));

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
			$limit		= isset($_GET['limit']) ? max(1, min(96, (int)$_GET['limit'])) : 12;
			$offset		= isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
			$medium		= isset($_GET['medium']) && $_GET['medium'] !== '' ? (string)$_GET['medium'] : null;
			$refresh	= !empty($_GET['refresh']);

			$media	= lbb_dashboard_media($constants);
			if ($offset == 0 and lbb_dashboard_automount($constants, $media)) {
				$media	= lbb_dashboard_media($constants);
			}

			# mounted drives: default backup target first, then targets, sources, internal storage
			# (internal storage is not shown in the touch UI)
			$order	= array('target_usb', 'target_nvme', 'source_usb', 'source_nvme');
			$available	= array();
			$paths		= array();
			$labels		= array();
			foreach ($order as $media_key) {
				if (isset($media[$media_key]) and $media[$media_key]['mounted']) {
					$paths[$media_key]		= $media[$media_key]['path'];
					$labels[$media_key]		= touch_medium_role_label($media_key);
					$available[$media_key]	= touch_media_files($constants, $media_key, $paths[$media_key], $refresh and ($medium === null or $medium == $media_key));
				}
			}

			# drives opened read only (here or in Files) and drives that can be opened, by their name
			$openable	= array();
			foreach (touch_attached_drives() as $drive) {
				$browse_key	= touch_browse_key($drive);
				$browse_path	= touch_medium_path($constants, $browse_key);
				if ($browse_path !== false) {
					$paths[$browse_key]		= $browse_path;
					$labels[$browse_key]	= $drive['name'];
					$available[$browse_key]	= touch_media_files($constants, $browse_key, $browse_path, $refresh and ($medium === null or $medium == $browse_key));
				} elseif (touch_drive_openable($constants, $drive)) {
					$openable[]	= array('path' => $drive['path'], 'name' => $drive['name'], 'size' => $drive['size']);
				}
			}

			# shown drive: the one asked for, else the first with files, else the first drive
			$key	= ($medium !== null and isset($available[$medium])) ? $medium : null;
			foreach ($available as $media_key => $files) {
				if ($key === null and count($files) > 0) {
					$key	= $media_key;
				}
			}
			$key	= $key === null ? array_key_first($available) : $key;

			# optional filters of the gallery of the web UI: file type, name search, order
			$list	= $key === null ? array() : touch_media_filter($constants, $available[$key], $_GET['type'] ?? '', $_GET['q'] ?? '', $_GET['sort'] ?? '');
			$offset	= min($offset, max(0, count($list) - 1));

			$items	= array();
			foreach (array_slice($list, $offset, $limit) as $file) {
				$items[]	= touch_media_item($constants, $key, $paths[$key], $file['path'], array(
					'date'		=> date('Y-m-d H:i', $file['time']),
					'time'		=> $file['time'],
					'path'		=> $file['path'],
					'size'		=> (int)@filesize($paths[$key] . '/' . $file['path']),
				));
			}

			$tabs	= array();
			foreach ($available as $media_key => $files) {
				$tabs[]	= array('id' => $media_key, 'label' => $labels[$media_key], 'count' => count($files));
			}

			touch_respond(array(
				'ok'				=> true,
				'medium'			=> $key,
				'medium_label'		=> $key === null ? '' : $labels[$key],
				'media'				=> $tabs,
				# plugged-in drives not shown yet: opened read only on request (action open_drive), several at once
				'openable'			=> touch_backup_running() ? array() : $openable,
				'total'				=> count($list),
				'all'				=> $key === null ? 0 : count($available[$key]),
				'offset'			=> $offset,
				'items'				=> $items,
			));

		case 'files':
			# file browser of the touch UI: folders and files of a mounted drive, hidden and technical files left out
			# every attached drive: mounted ones (target, source) can be browsed, the others are mounted on request
			$attached	= touch_attached_drives();
			$drives		= array();
			foreach (array('target_usb', 'target_nvme', 'source_usb', 'source_nvme') as $media_key) {
				$mountpoint	= touch_medium_path($constants, $media_key);
				if ($mountpoint !== false) {
					$name	= '';
					foreach ($attached as $drive) {
						if ($drive['mountpoint'] === $mountpoint) {
							$name	= $drive['name'];
						}
					}
					$drives[]	= array('id' => $media_key, 'label' => touch_medium_role_label($media_key), 'name' => $name, 'mounted' => true);
				}
			}
			foreach ($attached as $drive) {
				# opened in the file browser (read only, no backup role): checked against the mounts themselves
				if (touch_medium_path($constants, touch_browse_key($drive)) !== false) {
					$drives[]	= array('id' => touch_browse_key($drive), 'label' => '', 'name' => $drive['name'], 'size' => $drive['size'], 'mounted' => true);
				} elseif (touch_drive_openable($constants, $drive)) {
					$drives[]	= array('id' => 'device:' . $drive['path'], 'label' => '', 'name' => $drive['name'], 'size' => $drive['size'], 'path' => $drive['path'], 'mounted' => false);
				}
			}

			$mounted	= array_values(array_filter($drives, function($drive) {return($drive['mounted']);}));
			if (!$mounted) {
				touch_respond(array('ok' => true, 'drives' => $drives, 'medium' => null, 'path' => '', 'entries' => array()));
			}

			$key	= isset($_GET['medium']) && in_array($_GET['medium'], array_column($mounted, 'id'), true) ? (string)$_GET['medium'] : $mounted[0]['id'];
			$root	= realpath(touch_medium_path($constants, $key));
			$sub	= trim(isset($_GET['path']) ? (string)$_GET['path'] : '', '/');
			$dir	= realpath($root . '/' . $sub);
			if ($dir === false or !is_dir($dir) or ($dir !== $root and !str_starts_with($dir, $root . '/'))) {
				$dir	= $root;
			}
			$sub	= ltrim(substr($dir, strlen($root)), '/');

			$extensions	= touch_media_extensions($constants);
			$entries	= array();
			foreach (scandir($dir) ?: array() as $name) {
				# hidden (".name", macOS "._name"), thumbnail folders and the database/ID files of the box
				if (str_starts_with($name, '.') or $name === 'tims' or $name === $constants['const_IMAGE_DATABASE_FILENAME'] or str_ends_with($name, '.lbbid')) {
					continue;
				}
				$full		= $dir . '/' . $name;
				$relative	= ($sub === '' ? '' : $sub . '/') . $name;
				if (is_dir($full)) {
					$entries[]	= array('type' => 'dir', 'name' => $name, 'path' => $relative);
					continue;
				}
				$ext	= strtolower(pathinfo($name, PATHINFO_EXTENSION));
				$media	= in_array($ext, $extensions, true);
				$entry	= touch_media_item($constants, $key, $root, $relative, array(
					'type'	=> 'file',
					'path'	=> $relative,
					'size'	=> @filesize($full),
					'date'	=> date('Y-m-d H:i', (int)@filemtime($full)),
					'url'	=> lbb_dashboard_url_path($full),
					'media'	=> $media,
				));
				if (!$media) {
					$entry['thumb']	= null;
				}
				$entries[]	= $entry;
			}

			# folders first, then files, by name
			usort($entries, function($a, $b) {
				return(($a['type'] == 'dir' ? 0 : 1) <=> ($b['type'] == 'dir' ? 0 : 1) ?: strnatcasecmp($a['name'], $b['name']));
			});

			touch_respond(array(
				'ok'		=> true,
				'drives'	=> $drives,
				'medium'	=> $key,
				'path'		=> $sub,
				'entries'	=> array_slice($entries, 0, 3000),
				'truncated'	=> count($entries) > 3000,
			));

		case 'open_drive':
			# file browser: open an attached drive. It is mounted read only without a backup role;
			# only the drive chosen as backup drive on the home screen is mounted as target.
			if (touch_backup_running()) {
				touch_fail(touch_text(L::touch_running));
			}
			$path	= isset($_POST['path']) ? (string)$_POST['path'] : '';
			$drive	= null;
			foreach (touch_attached_drives() as $attached) {
				if ($attached['path'] === $path and touch_drive_openable($constants, $attached)) {
					$drive	= $attached;
				}
			}
			if (!$drive) {
				touch_fail(touch_text(L::touch_mount_failed));
			}
			$chosen	= touch_target_choice($constants);
			if ($chosen and $chosen['id'] === ($drive['uuid'] ?: $drive['path'])) {
				touch_mount_target($WORKING_DIR, $drive);
				$medium	= 'target_' . $drive['kind'];
			} else {
				touch_browse_mount($constants, $drive);
				$medium	= touch_browse_key($drive);
			}
			if (touch_medium_path($constants, $medium) === false) {
				touch_fail(touch_text(L::touch_mount_failed));
			}
			touch_respond(array('ok' => true, 'medium' => $medium));

		case 'thumb':
			$key	= isset($_GET['medium']) ? (string)$_GET['medium'] : '';
			$root	= touch_medium_path($constants, $key);
			$root	= $root === false ? false : realpath($root);
			$file	= $root === false ? false : realpath($root . '/' . (isset($_GET['path']) ? (string)$_GET['path'] : ''));
			if ($file === false or !str_starts_with($file, $root . '/') or !is_file($file)) {
				touch_fail('not found', 404);
			}
			$ext	= strtolower(pathinfo($file, PATHINFO_EXTENSION));
			if (!in_array($ext, touch_media_extensions($constants), true)) {
				touch_fail('not a media file', 404);
			}

			$stat	= stat($file);
			$dir	= $constants['const_WEB_ROOT_LBB'] . '/tmp/touch-thumbs';
			@mkdir($dir, 0755, true);
			$thumb	= $dir . '/' . md5($file . '|' . $stat['size'] . '|' . $stat['mtime'] . '|2') . '.jpg';
			# a preview that could not be made is not tried again for 10 minutes (the photo lists refresh often)
			$failed	= $thumb . '.failed';
			if (!is_file($thumb) and is_file($failed) and time() - filemtime($failed) < 600) {
				touch_fail('no preview', 404);
			}
			if (!is_file($thumb)) {
				# one preview per file at a time: tiles shown twice do not start a second ffmpeg
				$lock	= fopen($thumb . '.lock', 'c');
				if ($lock) {
					flock($lock, LOCK_EX);
				}
				if (!is_file($thumb)) {
					$error	= touch_make_thumb($file, $thumb, touch_is_video($ext, $constants), in_array($ext, explode(';', $constants['const_FILE_EXTENSIONS_LIST_RAW']), true));
					if (!is_file($thumb) or filesize($thumb) == 0) {
						@unlink($thumb);
						@touch($failed);
						# why: tmp/touch-thumbs/errors.log (kept short)
						$errorlog	= $dir . '/errors.log';
						if (@filesize($errorlog) > 100000) {
							@unlink($errorlog);
						}
						@file_put_contents($errorlog, date('Y-m-d H:i:s') . "\t" . $file . "\t" . ($error ?: 'no preview') . "\n", FILE_APPEND);
					}
				}
				if ($lock) {
					flock($lock, LOCK_UN);
					fclose($lock);
					@unlink($thumb . '.lock');
				}
			}
			if (!is_file($thumb) or filesize($thumb) == 0) {
				touch_fail('no preview', 404);
			}

			while (ob_get_level()) {
				ob_end_clean();
			}
			header('Content-Type: image/jpeg');
			header('Cache-Control: max-age=604800');
			header('Content-Length: ' . filesize($thumb));
			readfile($thumb);
			exit();

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
				# Wi-Fi settings: the box's Wi-Fi interface and its connection
				'wifi'		=> touch_wifi_device(),
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

		case 'choose_target':
			# home screen step 1: the drive the user picked becomes the backup target
			if (touch_backup_running()) {
				touch_fail(touch_text(L::touch_running));
			}
			$path	= isset($_POST['path']) ? (string)$_POST['path'] : '';
			$drive	= null;
			foreach (touch_attached_drives() as $attached) {
				if ($attached['path'] === $path) {
					$drive	= $attached;
				}
			}
			if (!$drive) {
				touch_fail(touch_text(L::touch_mount_failed));
			}
			if (!empty($drive['mountpoint'])) {
				# mounted as source or opened in the file browser: becomes the target now
				if (str_starts_with($drive['mountpoint'], touch_browse_dir($constants) . '/')) {
					touch_browse_umount($constants, $drive['mountpoint']);
				} elseif ($drive['mountpoint'] === touch_medium_path($constants, 'source_' . $drive['kind'])) {
					shell_exec("sudo python3 $WORKING_DIR/lib_storage.py --Action umount --StorageName " . escapeshellarg($drive['kind']) . " --Role source > /dev/null 2>&1");
				} else {
					touch_fail(touch_text(L::touch_mount_failed));
				}
			}
			touch_target_choice($constants, array('id' => $drive['uuid'] ?: $drive['path'], 'kind' => $drive['kind'], 'name' => $drive['name']));
			touch_mount_target($WORKING_DIR, $drive);
			touch_respond(array('ok' => true, 'storages' => touch_storages($constants)));

		case 'change_target':
			# forget the backup drive (and unmount it), the home screen asks again
			if (touch_backup_running()) {
				touch_fail(touch_text(L::touch_running));
			}
			touch_target_choice($constants, null);
			foreach (array('usb', 'nvme') as $storage) {
				shell_exec("sudo python3 $WORKING_DIR/lib_storage.py --Action umount --StorageName $storage --Role target > /dev/null 2>&1");
			}
			touch_respond(array('ok' => true, 'storages' => touch_storages($constants)));

		case 'start':
			# home screen step 3: back up the inserted source to the chosen target, with the configured options
			if (touch_backup_running()) {
				touch_fail(touch_text(L::touch_running));
			}
			$storages	= touch_storages($constants);
			$flow		= touch_flow($config, $constants, $storages, false);
			if ($flow['target']['state'] != 'ready') {
				touch_fail(touch_text(L::touch_choose_target));
			}
			if (!$flow['sources']) {
				touch_fail(touch_text(L::touch_insert_source));
			}
			$source	= $flow['sources'][0];
			foreach ($flow['sources'] as $candidate) {
				if (isset($_POST['source']) and $candidate['path'] === $_POST['source']) {
					$source	= $candidate;
				}
			}
			$target_kind	= explode('_', $flow['target']['id'])[0];
			$target_device	= $flow['target']['path'];

			touch_run_backup($WORKING_DIR,
				"--SourceName usb --TargetName " . escapeshellarg($target_kind) .
				" --device-identifier-preset-source " . escapeshellarg($source['path']) .
				" --device-identifier-preset-target " . escapeshellarg($target_device) .
				" --move-files " . touch_bool($config['conf_BACKUP_MOVE_FILES']) .
				" --rename-files " . touch_bool($config['conf_BACKUP_RENAME_FILES']) .
				" --force-sync-database False" .
				" --generate-thumbnails " . touch_bool($config['conf_BACKUP_GENERATE_THUMBNAILS']) .
				" --update-exif " . touch_bool($config['conf_BACKUP_UPDATE_EXIF']) .
				" --checksum " . touch_bool($config['conf_BACKUP_CHECKSUM']) .
				" --power-off " . touch_bool($config['conf_POWER_OFF'])
			);
			touch_respond(array('ok' => true, 'message' => $source['name'] . ' → ' . $flow['target']['name']));

		case 'clearlog':
			# same as "Delete log" of the classic UI
			$logfile	= $constants['const_LOGFILE'];
			@unlink($logfile);
			if (@file_put_contents($logfile, "Little Backup Box\n") === false) {
				shell_exec("echo 'Little Backup Box' | sudo tee " . escapeshellarg($logfile) . " > /dev/null");
			}
			touch_respond(array('ok' => true));

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
			$preset	= '';
			if ($action == 'mount') {
				# lib_storage takes the first partition it finds, often the small EFI partition of a USB SSD:
				# name the data partition (from the attached drives), a source never on the target's disk
				$target_mountpoints	= array($constants['const_MEDIA_DIR'] . '/' . $constants['const_MOUNTPOINT_USB_TARGET'], $constants['const_MEDIA_DIR'] . '/' . $constants['const_MOUNTPOINT_NVME_TARGET']);
				$drives			= touch_attached_drives();
				$target_disks	= array();
				foreach ($drives as $drive) {
					if (in_array($drive['mountpoint'], $target_mountpoints, true)) {
						$target_disks[]	= $drive['disk'];
					}
				}
				$chosen	= touch_target_choice($constants);
				$found	= null;
				foreach ($drives as $drive) {
					$browsed	= !empty($drive['mountpoint']) && str_starts_with($drive['mountpoint'], touch_browse_dir($constants) . '/');
					if ($drive['kind'] !== $Storage or (!empty($drive['mountpoint']) and !$browsed) or ($Role == 'source' and in_array($drive['disk'], $target_disks, true))) {
						continue;
					}
					# a target: the drive chosen on the home screen first
					if ($found === null or ($Role == 'target' and $chosen and $chosen['id'] === ($drive['uuid'] ?: $drive['path']))) {
						$found	= $drive;
					}
				}
				if ($found === null) {
					# nothing to mount: the UI reports it
					touch_respond(array('ok' => true, 'storages' => touch_storages($constants)));
				}
				if (!empty($found['mountpoint'])) {
					# opened in the file browser: give it to the backup role
					touch_browse_umount($constants, $found['mountpoint']);
				}
				$preset	= ' --DeviceIdentifierPresetThis ' . escapeshellarg($found['path']) . ' --WaitForDevice False';
			}
			$command	= "sudo python3 $WORKING_DIR/lib_storage.py --Action $action --StorageName $Storage --Role $Role" . $preset;
			shell_exec("python3 $WORKING_DIR/lib_log.py 'execute' '' " . escapeshellarg($command) . " '1'");
			touch_respond(array('ok' => true, 'storages' => touch_storages($constants)));

		case 'drives':
			# System tab: every attached drive with what the box uses it for, and the running disk task
			$choice	= touch_target_choice($constants);
			$drives	= array();
			foreach (touch_attached_drives() as $drive) {
				$role	= touch_drive_role($constants, $drive);
				$total	= $drive['mountpoint'] ? @disk_total_space($drive['mountpoint']) : false;
				$free	= $drive['mountpoint'] ? @disk_free_space($drive['mountpoint']) : false;
				$drives[]	= array(
					'path'		=> $drive['path'],
					'name'		=> $drive['name'],
					'size'		=> $drive['size'],
					'fstype'	=> $drive['fstype'],
					'kind'		=> $drive['kind'],
					'role'		=> $role,
					'role_label'	=> in_array($role, array('target_usb', 'target_nvme', 'source_usb', 'source_nvme'), true) ? touch_medium_role_label($role) : '',
					'chosen'	=> $choice and $choice['id'] === ($drive['uuid'] ?: $drive['path']),
					'mounted'	=> !empty($drive['mountpoint']),
					'medium'	=> in_array($role, array('target_usb', 'target_nvme', 'source_usb', 'source_nvme'), true) ? $role : ($role === 'browse' ? touch_browse_key($drive) : null),
					'total'		=> $total === false ? null : $total,
					'free'		=> $free === false ? null : $free,
					'check'		=> touch_fsck_command($drive['fstype'], false, $drive['path']) !== null,
				);
			}
			touch_respond(array('ok' => true, 'drives' => $drives, 'task' => touch_disk_task_status($constants), 'running' => touch_backup_running()));

		case 'disk_task':
			touch_respond(array('ok' => true, 'task' => touch_disk_task_status($constants)));

		case 'wifi':
			# Wi-Fi state, saved and (with scan=1 freshly scanned) available networks
			$device	= touch_wifi_device();
			if (!$device) {
				touch_respond(array('ok' => true, 'available' => false));
			}

			$connections	= touch_wifi_connections();
			$active			= $device['connection'];
			$hotspot		= $active !== '' && isset($connections[$active]) && $connections[$active]['mode'] === 'ap';

			$saved	= array();
			foreach ($connections as $name => $connection) {
				if ($connection['mode'] !== 'ap') {
					$saved[]	= array('name' => $name, 'ssid' => $connection['ssid'], 'active' => $name === $active);
				}
			}

			$networks	= array();
			$current	= null;
			if (!$hotspot) {
				# while the box runs its hotspot, the radio can not scan: comitup's page lists the networks then
				$rescan	= !empty($_REQUEST['scan']) ? 'yes' : 'auto';
				$savedSsids	= array_column($saved, 'ssid');
				foreach (touch_nmcli('-f IN-USE,SSID,SIGNAL,SECURITY device wifi list ifname ' . escapeshellarg($device['device']) . ' --rescan ' . $rescan) as $row) {
					if (count($row) < 4 or $row[1] === '') {
						continue;
					}
					$ssid	= $row[1];
					$inUse	= trim($row[0]) === '*';
					if (isset($networks[$ssid]) and $networks[$ssid]['signal'] >= (int)$row[2] and !$inUse) {
						continue;
					}
					$networks[$ssid]	= array(
						'ssid'		=> $ssid,
						'signal'	=> (int)$row[2],
						'security'	=> trim($row[3]) === '--' ? '' : trim($row[3]),
						'in_use'	=> $inUse || (isset($networks[$ssid]) && $networks[$ssid]['in_use']),
						'saved'		=> in_array($ssid, $savedSsids, true),
					);
				}
				$networks	= array_values($networks);
				usort($networks, function($a, $b) {return(($b['in_use'] <=> $a['in_use']) ?: ($b['saved'] <=> $a['saved']) ?: ($b['signal'] <=> $a['signal']));});

				if ($active !== '' and isset($connections[$active])) {
					$current	= array('name' => $active, 'ssid' => $connections[$active]['ssid'], 'signal' => null);
					foreach ($networks as $network) {
						if ($network['in_use']) {
							$current['signal']	= $network['signal'];
						}
					}
				}
			}

			$ip	= '';
			foreach (touch_nmcli('-f IP4.ADDRESS device show ' . escapeshellarg($device['device'])) as $row) {
				if (isset($row[1]) and $ip === '') {
					$ip	= explode('/', $row[1])[0];
				}
			}

			touch_respond(array(
				'ok'			=> true,
				'available'		=> true,
				'device'		=> $device['device'],
				'state'			=> $device['state'],
				'hotspot'		=> $hotspot,
				'hotspot_ssid'	=> $hotspot ? $connections[$active]['ssid'] : '',
				'wifi_setup'	=> trim((string)shell_exec('systemctl is-active comitup-web 2>/dev/null')) === 'active',
				'current'		=> $current,
				'ip'			=> $ip,
				'saved'			=> $saved,
				'networks'		=> $networks,
				'task'			=> touch_wifi_task($constants),
			));

		case 'wifi_connect':
			# connect to a network; runs in the background: the phone may lose the box when it changes networks
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				touch_fail('POST required', 405);
			}
			$ssid		= isset($_POST['ssid']) ? (string)$_POST['ssid'] : '';
			$password	= isset($_POST['password']) ? (string)$_POST['password'] : '';
			$device		= touch_wifi_device();
			if ($ssid === '' or strlen($ssid) > 32 or !$device) {
				touch_fail(touch_text(L::touch_wifi_failed));
			}
			if ($password !== '' and (strlen($password) < 8 or strlen($password) > 63)) {
				touch_fail(touch_text(L::touch_wifi_password_length));
			}
			$task	= touch_wifi_task($constants);
			if ($task and $task['running']) {
				touch_fail(touch_text(L::touch_wifi_busy));
			}

			# a saved network without a new password: start the saved connection
			$savedName	= '';
			foreach (touch_wifi_connections() as $name => $connection) {
				if ($connection['ssid'] === $ssid and $connection['mode'] !== 'ap') {
					$savedName	= $name;
				}
			}

			if ($savedName !== '' and $password === '') {
				$command	= 'nmcli --wait 45 connection up id ' . escapeshellarg($savedName);
			} else {
				# a new or changed password: the profile is made anew (a failed new one is removed again)
				$command	= ($savedName !== '' ? 'nmcli connection delete id ' . escapeshellarg($savedName) . ' >/dev/null 2>&1; ' : '') .
					'nmcli --wait 45 device wifi connect ' . escapeshellarg($ssid) .
					($password !== '' ? ' password ' . escapeshellarg($password) : '') .
					' ifname ' . escapeshellarg($device['device']);
				$command	= '{ ' . $command . '; } || { rc=$?; nmcli connection delete id ' . escapeshellarg($ssid) . ' >/dev/null 2>&1; exit $rc; }';
			}

			$log	= touch_wifi_task_file($constants, 'log');
			$exit	= touch_wifi_task_file($constants, 'exit');
			@unlink($exit);
			@file_put_contents($log, '');
			file_put_contents(touch_wifi_task_file($constants, 'ssid'), $ssid);
			# a moment first: the answer reaches the phone before the box may leave the network
			shell_exec('sudo -n sh -c ' . escapeshellarg('sleep 1; ( ' . $command . ' ) > ' . escapeshellarg($log) . ' 2>&1; echo $? > ' . escapeshellarg($exit) . '; chown www-data ' . escapeshellarg($log) . ' ' . escapeshellarg($exit)) . ' > /dev/null 2>&1 &');

			touch_respond(array('ok' => true, 'ssid' => $ssid));

		case 'wifi_forget':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				touch_fail('POST required', 405);
			}
			$name			= isset($_POST['name']) ? (string)$_POST['name'] : '';
			$connections	= touch_wifi_connections();
			if (!isset($connections[$name]) or $connections[$name]['mode'] === 'ap') {
				touch_fail(touch_text(L::touch_wifi_failed));
			}
			shell_exec('sudo -n nmcli connection delete id ' . escapeshellarg($name) . ' > /dev/null 2>&1');
			touch_respond(array('ok' => true));

		case 'drive_action':
			# one drive: unmount, photo maintenance, check, repair, format
			$drive	= touch_find_drive(isset($_POST['path']) ? (string)$_POST['path'] : '');
			$do		= isset($_POST['do']) ? (string)$_POST['do'] : '';
			if (!$drive) {
				touch_fail(touch_text(L::touch_mount_failed));
			}
			if (touch_backup_running()) {
				touch_fail(touch_text(L::touch_running));
			}
			if (touch_disk_task_running($constants)) {
				touch_fail(touch_text(L::touch_task_busy));
			}

			if ($do === 'unmount') {
				if (!touch_release_drive($constants, $drive)) {
					touch_fail(touch_text(L::touch_umount_failed));
				}
				touch_respond(array('ok' => true, 'message' => touch_text(L::touch_umount_done)));
			}

			$functions	= array(
				# function => rename, sync database, thumbnails, exif
				'thumbnails'	=> array('False', 'False', 'True', 'False', L::main_function_thumbnails),
				'database'		=> array('False', 'True', 'False', 'False', L::main_function_sync),
				'exif'			=> array('False', 'False', 'False', 'True', L::main_function_exif),
				'rename'		=> array('True', 'False', 'False', 'False', L::main_backup_rename_checkbox_label),
			);
			if (isset($functions[$do])) {
				# runs on this drive as backup target; a drive opened read only or by something else is released first
				if (in_array(touch_drive_role($constants, $drive), array('browse', 'other'), true)) {
					touch_release_drive($constants, $drive);
				}
				[$rename, $sync, $thumbnails, $exif, $label]	= $functions[$do];
				touch_run_backup($WORKING_DIR, "--SourceName $do --TargetName " . escapeshellarg($drive['kind']) . " --rename-files $rename --force-sync-database $sync --generate-thumbnails $thumbnails --update-exif $exif --device-identifier-preset-target " . escapeshellarg($drive['path']) . " --power-off False");
				touch_respond(array('ok' => true, 'message' => touch_text($label . ': ' . $drive['name']), 'home' => true));
			}

			if ($do === 'check' or $do === 'repair') {
				$command	= touch_fsck_command($drive['fstype'], $do === 'repair', $drive['path']);
				if ($command === null) {
					touch_fail(touch_text(L::touch_fs_unsupported));
				}
				if (!touch_release_drive($constants, $drive, false)) {
					touch_fail(touch_text(L::touch_umount_failed));
				}
				touch_disk_task_start($constants, touch_text(($do === 'repair' ? L::touch_repair : L::touch_check) . ': ' . $drive['name']), $drive['path'], array($command));
				touch_respond(array('ok' => true, 'task' => touch_disk_task_status($constants)));
			}

			if ($do === 'format') {
				$fs		= isset($_POST['fs']) ? (string)$_POST['fs'] : '';
				$label	= preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($drive['label'] ?? '')) ?: 'LBB';
				$command	= touch_format_command($fs, $drive['path'], $label);
				if ($command === null) {
					touch_fail(touch_text(L::touch_fs_unsupported));
				}
				# the name typed by the user must match: nothing is formatted by a stray tap
				if ((string)($_POST['confirm'] ?? '') !== $drive['name']) {
					touch_fail(touch_text(L::touch_format_confirm_mismatch));
				}
				if (!touch_release_drive($constants, $drive)) {
					touch_fail(touch_text(L::touch_umount_failed));
				}
				touch_disk_task_start($constants, touch_text(L::touch_format . ': ' . $drive['name'] . ' (' . $fs . ')'), $drive['path'], array($command, 'lsblk -f ' . escapeshellarg($drive['path'])));
				touch_respond(array('ok' => true, 'task' => touch_disk_task_status($constants)));
			}

			touch_fail('unknown drive action');

		case 'file_op':
			# Files tab: delete or rename on a mounted drive; drives opened read only are writable for this moment only
			$medium	= isset($_POST['medium']) ? (string)$_POST['medium'] : '';
			$op		= isset($_POST['op']) ? (string)$_POST['op'] : '';
			$root	= touch_medium_path($constants, $medium);
			if ($root === false or $medium === 'internal') {
				touch_fail(touch_text(L::touch_mount_failed));
			}
			$root	= realpath($root);
			if (touch_backup_running()) {
				touch_fail(touch_text(L::touch_running));
			}
			$paths	= isset($_POST['paths']) ? json_decode((string)$_POST['paths'], true) : null;
			if (!is_array($paths) or !$paths or count($paths) > 500) {
				touch_fail('nothing selected');
			}
			$targets	= array();
			foreach ($paths as $relative) {
				$full	= touch_file_target($root, $relative);
				if ($full === false) {
					touch_fail('invalid path');
				}
				$targets[]	= $full;
			}

			$readonly	= str_starts_with($medium, 'browse:');
			if ($readonly) {
				# a device mount, or a bind mount (a drive the system had mounted): both made writable
				shell_exec('sudo mount -o remount,rw ' . escapeshellarg($root) . ' > /dev/null 2>&1; sudo mount -o remount,bind,rw ' . escapeshellarg($root) . ' > /dev/null 2>&1');
			}
			$failed	= 0;
			if ($op === 'delete') {
				foreach ($targets as $full) {
					# with its thumbnail of the box, if there is one
					$tims	= dirname($full) . '/tims/' . basename($full) . '.JPG';
					exec('sudo rm -rf -- ' . escapeshellarg($full) . (is_file($tims) ? ' ' . escapeshellarg($tims) : '') . ' 2>&1', $output, $rc);
					$failed	+= ($rc !== 0 or file_exists($full)) ? 1 : 0;
				}
			} elseif ($op === 'rename' and count($targets) === 1) {
				$name	= isset($_POST['name']) ? trim((string)$_POST['name']) : '';
				if (!touch_valid_file_name($name)) {
					touch_fail(touch_text(L::touch_invalid_name));
				}
				$full	= $targets[0];
				$new	= dirname($full) . '/' . $name;
				if (file_exists($new)) {
					touch_fail(touch_text(L::touch_name_exists));
				}
				exec('sudo mv -n -- ' . escapeshellarg($full) . ' ' . escapeshellarg($new) . ' 2>&1', $output, $rc);
				$tims	= dirname($full) . '/tims/' . basename($full) . '.JPG';
				if ($rc === 0 and is_file($tims)) {
					shell_exec('sudo mv -n -- ' . escapeshellarg($tims) . ' ' . escapeshellarg(dirname($full) . '/tims/' . $name . '.JPG') . ' > /dev/null 2>&1');
				}
				$failed	= ($rc !== 0 or !file_exists($new)) ? 1 : 0;
			} else {
				touch_fail('unknown file operation');
			}
			if ($readonly) {
				# back to read only for this mount point only (never the whole file system behind a bind mount)
				shell_exec('sudo mount -o remount,bind,ro ' . escapeshellarg($root) . ' > /dev/null 2>&1');
			}
			# photo lists read the drive again
			foreach (glob($constants['const_WEB_ROOT_LBB'] . '/tmp/touch-files-*.json') ?: array() as $cache) {
				@unlink($cache);
			}
			if ($failed) {
				touch_fail(touch_text(L::touch_file_op_failed));
			}
			touch_respond(array('ok' => true));

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
