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
				if (!$running and empty($drive['mountpoint']) and time() - (int)@filemtime($stamp) > 20) {
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
		# photos and videos on a drive, newest first, read from the file system (just copied files show at once).
		# Cached briefly per mounted file system: paging through a big drive should not list it again and again.
		$stat	= @stat($path);
		$id		= $stat ? $stat['dev'] . '-' . $stat['ino'] : '';
		$cache	= $constants['const_WEB_ROOT_LBB'] . '/tmp/touch-files-' . preg_replace('/[^a-z_]/', '', $key) . '.json';
		$cached	= $refresh ? null : @json_decode((string)@file_get_contents($cache), true);
		if (is_array($cached) and $cached['id'] === $id and time() - $cached['time'] < 15) {
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
		exec('find ' . escapeshellarg($path) . ' -type f \\( ' . implode(' -o ', $names) . ' \\) -not -path ' . escapeshellarg('*/tims/*') . ' -not -path ' . escapeshellarg('*/.*') . ' ' . implode(' ', $banned) . " -printf '%T@\\t%P\\n' 2>/dev/null", $lines);

		$files	= array();
		foreach ($lines as $line) {
			$parts	= explode("\t", $line, 2);
			if (count($parts) == 2) {
				$files[]	= array('path' => $parts[1], 'time' => (int)$parts[0]);
			}
		}
		usort($files, function($a, $b) {return($b['time'] <=> $a['time']);});

		@file_put_contents($cache, json_encode(array('id' => $id, 'time' => time(), 'files' => $files)));
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
			foreach (array('1', '0') as $second) {
				exec("timeout 30 ffmpeg -nostdin -loglevel error -ss $second -i $s -frames:v 1 -vf scale=480:-2 -y -f image2 $t 2>/dev/null");
				if (@filesize($thumb) > 0) {
					return;
				}
			}
			return;
		}

		if (!$raw) {
			exec("timeout 30 $magick -define jpeg:size=960x960 " . escapeshellarg($source . '[0]') . " -auto-orient -thumbnail 480x480 -quality 80 jpg:$t 2>/dev/null");
			if (@filesize($thumb) > 0) {
				return;
			}
		}

		foreach (array('PreviewImage', 'JpgFromRaw', 'ThumbnailImage') as $tag) {
			exec("timeout 20 exiftool -b -$tag $s > " . escapeshellarg($tmp) . " 2>/dev/null");
			if (@filesize($tmp) > 0) {
				exec("timeout 30 $magick " . escapeshellarg($tmp) . " -thumbnail 480x480 -quality 80 jpg:$t 2>/dev/null");
				break;
			}
		}
		@unlink($tmp);
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
				'copying'	=> $running ? touch_copying($constants) : null,
				'temp'		=> touch_cpu_temp(),
				'time'		=> time(),
			));

		case 'copying':
			# polled twice a second while a backup runs: only reads the file list written by the backup
			touch_respond(array('ok' => true, 'copying' => touch_copying($constants)));

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
			foreach ($order as $media_key) {
				if (isset($media[$media_key]) and $media[$media_key]['mounted']) {
					$available[$media_key]	= touch_media_files($constants, $media_key, $media[$media_key]['path'], $refresh and ($medium === null or $medium == $media_key));
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

			$items	= array();
			foreach (array_slice($key === null ? array() : $available[$key], $offset, $limit) as $file) {
				$items[]	= touch_media_item($constants, $key, $media[$key]['path'], $file['path'], array('date' => date('Y-m-d H:i', $file['time'])));
			}

			$tabs			= array();
			$source_mounted	= false;
			foreach ($available as $media_key => $files) {
				$source_mounted	= $source_mounted || str_starts_with($media_key, 'source_');
				$tabs[]	= array('id' => $media_key, 'label' => touch_text(lbb_dashboard_medium_label($media_key)), 'count' => count($files));
			}

			touch_respond(array(
				'ok'				=> true,
				'medium'			=> $key,
				'medium_label'		=> $key === null ? '' : touch_text(lbb_dashboard_medium_label($key)),
				'media'				=> $tabs,
				'can_mount_source'	=> !$source_mounted && !touch_backup_running(),
				'total'				=> $key === null ? 0 : count($available[$key]),
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
					$drives[]	= array('id' => $media_key, 'label' => touch_text(lbb_dashboard_medium_label($media_key)), 'name' => $name, 'mounted' => true);
				}
			}
			foreach ($attached as $drive) {
				# opened in the file browser (read only, no backup role): checked against the mounts themselves
				if (touch_medium_path($constants, touch_browse_key($drive)) !== false) {
					$drives[]	= array('id' => touch_browse_key($drive), 'label' => '', 'name' => $drive['name'], 'size' => $drive['size'], 'mounted' => true);
				} elseif (empty($drive['mountpoint'])) {
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
				if ($attached['path'] === $path and empty($attached['mountpoint'])) {
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
			$thumb	= $dir . '/' . md5($file . '|' . $stat['size'] . '|' . $stat['mtime']) . '.jpg';
			if (!is_file($thumb)) {
				touch_make_thumb($file, $thumb, touch_is_video($ext, $constants), in_array($ext, explode(';', $constants['const_FILE_EXTENSIONS_LIST_RAW']), true));
			}
			if (!is_file($thumb) or filesize($thumb) == 0) {
				@unlink($thumb);
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
