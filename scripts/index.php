<?php
	// the box's address (http://IP/, the box's own touchscreen) opens the app view (touch UI);
	// this page stays at /index.php ("Full web interface" in the app, the menu of all classic pages)
	if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/') {
		$Scheme		= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$Host		= $_SERVER['HTTP_HOST'] ?? '';
		$Agent		= $_SERVER['HTTP_USER_AGENT'] ?? '';

		// Android phones (QR code on the box's display, a link, the typed address): the Backup Box app if it is
		// installed, else this browser goes on to the touch UI (Chrome and Samsung Internet know intent: links)
		if (
			preg_match('/^[\w.:\[\]-]+$/', $Host) and
			str_contains($Agent, 'Android') and
			(str_contains($Agent, 'Chrome/') or str_contains($Agent, 'SamsungBrowser/')) and
			!str_contains($Agent, 'BackupBoxApp') and
			!str_contains($Agent, '; wv)')
		) {
			$Box		= preg_replace('/:\d+$/', '', $Host);
			$Fallback	= "$Scheme://$Host/touch.php";
			header('Location: intent://' . $Box . '#Intent;scheme=backupbox;package=com.yws.backupbox;S.browser_fallback_url=' . rawurlencode($Fallback) . ';end');
			exit();
		}

		header('Location: /touch.php');
		exit();
	}
?>
<!doctype html>

<!--
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
#######################################################################
-->

<?php
	$WORKING_DIR=dirname(__FILE__);
	$config = parse_ini_file($WORKING_DIR . "/config.cfg", false);
	$constants = parse_ini_file($WORKING_DIR . "/constants.sh", false);

	$theme = $config["conf_THEME"];
	$background = $config["conf_BACKGROUND_IMAGE"] == ""?"":"background='" . $constants["const_MEDIA_DIR"] . '/' . $constants["const_BACKGROUND_IMAGES_DIR"] . "/" . $config["conf_BACKGROUND_IMAGE"] . "'";

	include("sub-i18n-loader.php");

	if (isset($_POST['form_sent'])) {
		$move_files				= isset($_POST['move_files'])?"True":"False";
		$rename_files			= isset($_POST['rename_files'])?"True":"False";
		$generate_thumbnails	= isset($_POST['generate_thumbnails'])?"True":"False";
		$update_exif			= isset($_POST['update_exif'])?"True":"False";
		$checksum				= isset($_POST['checksum'])?"True":"False";
		$power_off_force		= isset($_POST['power_off'])?"True":"False";

		$preset_source			= isset($_POST['preset_source'])?$_POST['preset_source']:"";
		$preset_target			= isset($_POST['preset_target'])?$_POST['preset_target']:"";
	} else {
		$move_files				= $config['conf_BACKUP_MOVE_FILES']?"True":"False";
		$rename_files			= $config['conf_BACKUP_RENAME_FILES']?"True":"False";
		$generate_thumbnails	= $config['conf_BACKUP_GENERATE_THUMBNAILS']?"True":"False";
		$update_exif			= $config['conf_BACKUP_UPDATE_EXIF']?"True":"False";
		$checksum				= $config['conf_BACKUP_CHECKSUM']?"True":"False";
		$power_off_force		= $config['conf_POWER_OFF']?"True":"False";

		$preset_source			= '';
		$preset_target			= '';
	}

	include("sub-popup.php");

	include("sub-common.php");
	include_once("sub-dashboard.php");
	$NVMe_available	= get_nvme_available(WORKING_DIR: $WORKING_DIR, constants: $constants, ignore_fs: false);
	$CloudServices	= get_cloudservices($constants);

	$LocalAutoServices	= array('anyusb');

	$LocalServices		= array('usb', 'internal');
	if ($NVMe_available) {
		array_push($LocalServices, 'nvme');
	}

	$CameraServices		= array('camera');

	$CloudServices_marked	= array();
	foreach($CloudServices as $CloudService) {
		$CloudServices_marked[]	= 'cloud:' . $CloudService;
	}

	$LocalNetworkServices	= array('ftp');

	// rsync
	$rsync_configurated	= ($config['conf_RSYNC_SERVER']=='' or $config['conf_RSYNC_PORT']=='' or $config['conf_RSYNC_USER']=='' or $config['conf_RSYNC_PASSWORD']=='' or $config['conf_RSYNC_SERVER_MODULE']=='') == false;
	if ($rsync_configurated) {
		$CloudServices_marked	= array_merge(['cloud_rsync'], $CloudServices_marked);
	}

	// social media
	include("sub-socialmedia.php");
	$SocialServices_configured	= get_social_services_configured();
	foreach ($SocialServices_configured as &$SocialService) {
		$SocialService = "social:$SocialService";
	}
	unset($item);

	$SourceServices	= array(
		'anyusb'	=> $LocalAutoServices,
		'usb'		=> $LocalServices,
		'camera'	=> $CameraServices,
		'cloud'		=> array_merge($CloudServices_marked, $LocalNetworkServices)
	);

	$TargetServices			= array(
		'usb'		=> $LocalServices,
		'cloud'		=> $CloudServices_marked,
		'social'	=> $SocialServices_configured
	);
?>

<html lang="<?php echo $config["conf_LANGUAGE"]; ?>" data-theme="<?php echo $theme; ?>">

<head>
	<?php include "sub-standards-header-loader.php"; ?>
	<script type="text/javascript" src="js/logmonitor.js"></script>
	<script type="text/javascript" src="js/display.js"></script>
	<script type="text/javascript" src="js/partition_presets.js"></script>

	<script>
		function HideDisallowedButtons(ActiveSource) {
			let TargetServices = [<?php 	$Separator = '';
											foreach ($TargetServices as $Group) {
												foreach ($Group as $TargetService)
												{
													print($Separator . "'" . $TargetService . "'");
													$Separator = ', ';
												}
											};?>];

			for (i in TargetServices) {
				let TargetService = TargetServices[i];

// 				find disallowed combinations
				if (
					((TargetService === ActiveSource.value) && (TargetService !== 'usb')) ||
					((ActiveSource.value === 'anyusb') && (TargetService === 'cloud_rsync')) ||
					((ActiveSource.value === 'camera') && (TargetService === 'cloud_rsync')) ||
					((ActiveSource.value === 'ftp') && (TargetService === 'cloud_rsync')) ||
					((ActiveSource.value === 'anyusb') && TargetService.startsWith('social')) ||
					((ActiveSource.value === 'camera') && TargetService.startsWith('social')) ||
					(ActiveSource.value.startsWith('cloud') && TargetService.startsWith('social')) ||
					((ActiveSource.value === 'ftp') && TargetService.startsWith('social'))
				) {
					document.getElementById("Target_" + TargetService).disabled = true;
				} else {
					document.getElementById("Target_" + TargetService).disabled = false;
				}
			}
		}
    </script>
</head>

<?php
	$OldSource = isset($_POST['SourceDevice']) ? $_POST['SourceDevice'] : 'anyusb';
?>
<body onload="refreshLogMonitor();refreshDisplay();HideDisallowedButtons(document.getElementById('Source_<?php echo $OldSource; ?>'))" <?php echo $background; ?>>
	<?php include "${WORKING_DIR}/sub-standards-body-loader.php"; ?>
	<?php include "${WORKING_DIR}/sub-menu.php"; ?>
	<?php include "${WORKING_DIR}/sub-display.php"; ?>

	<!-- Suppress form re-submit prompt on refresh -->
	<script>
		if (window.history.replaceState) {
			window.history.replaceState(null, null, window.location.href);
		}
	</script>

	<?php display(false, 'Backup Box'); ?>

	<form class="text-center" style="margin-top: 1em;" method="POST">

		<input type="hidden" name="form_sent" value="1">

		<div class="card lbb-backup-panel" id="backup">
			<div class="row" style="width:100%; float: left; display: block;">
				<div class="column" style="display: block;">
					<?php
						// collapsed to the chosen source; opening it lists all sources
						print("<details class='lbb-picker' id='picker-source'><summary><span class='lbb-picker-title'>" . l::main_source . "</span><span class='lbb-picker-value' id='picker-source-value'>" . l::main_source_select . "</span></summary><div class='lbb-picker-list'>");
						foreach($SourceServices as $ButtonClass => $Group) {
							print("<div class='radio-$ButtonClass'>");
								foreach($Group as $Storage) {
									$LabelNameExplode		= explode(':', $Storage, 2);
									$LabelName				= end($LabelNameExplode);

									if ($LabelName == 'anyusb') {
										$LabelName		= l::box_backup_mode_anyusb;
									}
									elseif ($LabelName == 'usb') {
										$LabelName		= l::box_backup_mode_usb;
									}
									elseif ($LabelName == 'internal') {
										$LabelName		= l::box_backup_mode_internal;
									}
									elseif ($LabelName == 'nvme') {
										$LabelName		= l::box_backup_mode_nvme;
									}
									elseif ($LabelName == 'camera') {
										$LabelName		= l::box_backup_mode_cameras;
									}
									elseif ($LabelName == 'cloud_rsync') {
										$LabelName		= l::box_backup_mode_cloud_rsync;
									}
									elseif ($LabelName == 'ftp') {
										$LabelName		= l::box_backup_mode_ftp;
									}

									print("<input type='radio' name='SourceDevice' value='$Storage' id='Source_$Storage' onchange='HideDisallowedButtons(this)' " . ($Storage == $OldSource ? 'checked' : '') . ">");
									print("<label for='Source_$Storage'>$LabelName</label></br>");
								}
							print("</div>");
						}
						print("</div></details>");
					?>
				</div>
				<div class="column" style="display: block;">
					<?php
						// a target button starts the backup
						print("<details class='lbb-picker' id='picker-target'><summary><span class='lbb-picker-title'>" . l::main_target . "</span><span class='lbb-picker-value'>" . L::touch_start . "</span></summary><div class='lbb-picker-list'>");
						foreach($TargetServices as $ButtonClass => $Group) {
							print("<div class='backup-buttons'>");
							foreach($Group as $Storage) {
								$LabelNameExplode		= explode(':', $Storage, 2);
								$LabelName				= end($LabelNameExplode);

								if ($LabelName == 'usb') {
									$LabelName		= l::tools_mount_usb;
								}
								elseif ($LabelName == 'internal') {
									$LabelName		= l::box_backup_mode_internal;
								}
								elseif ($LabelName == 'nvme') {
									$LabelName		= l::box_backup_mode_nvme;
								}
								elseif ($LabelName == 'cloud_rsync') {
									$LabelName		= l::box_backup_mode_cloud_rsync;
								}
								elseif ($LabelName == 'telegram') {
									$LabelName		= l::box_backup_mode_social_telegram;
									if (!empty($_POST['conf_SOCIAL_TELEGRAM_CHAT_IDENTIFIER']) or $config['conf_SOCIAL_TELEGRAM_CHAT_IDENTIFIER']) {
										$LabelName	= !empty($_POST['conf_SOCIAL_TELEGRAM_CHAT_IDENTIFIER']) ? "$LabelName<br /><small style=\"font-weight: normal;\">${_POST['conf_SOCIAL_TELEGRAM_CHAT_IDENTIFIER']}</small>" : "$LabelName<br /><small style=\"font-weight: normal;\">${config['conf_SOCIAL_TELEGRAM_CHAT_IDENTIFIER']}</small>";
									}
								}
								elseif ($LabelName == 'matrix') {
									$LabelName		= l::box_backup_mode_social_matrix;
									if (!empty($_POST['conf_SOCIAL_MATRIX_ROOM_IDENTIFIER']) or $config['conf_SOCIAL_MATRIX_ROOM_IDENTIFIER']) {
										$LabelName	= !empty($_POST['conf_SOCIAL_MATRIX_ROOM_IDENTIFIER']) ? "$LabelName<br /><small style=\"font-weight: normal;\">${_POST['conf_SOCIAL_MATRIX_ROOM_IDENTIFIER']}</small>" : "$LabelName<br /><small style=\"font-weight: normal;\">${config['conf_SOCIAL_MATRIX_ROOM_IDENTIFIER']}</small>";
									}
								}
								elseif ($LabelName == 'mastodon') {
									$LabelName		= l::box_backup_mode_social_mastodon;
								}
								elseif ($LabelName == 'bluesky') {
									$LabelName		= l::box_backup_mode_social_bluesky;
								}

								print("<button class='$ButtonClass' name='TargetDevice' value='$Storage' id='Target_$Storage'>$LabelName</button></br>");

							}
							print("</div>");
						}
						print("</div></details>");
					?>
				</div>
			</div>

			<?php
				// options of the backup started with a target button (were "Modifications" below)
				print("<details class='lbb-picker lbb-picker-options' id='picker-options'><summary><span class='lbb-picker-title'>" . L::touch_options . "</span><span class='lbb-picker-value'>" . L::main_backup_modifications . "</span></summary><div class='lbb-picker-list'>");
			?>

				<div class='backupsection'>
					<h4><?php echo L::main_backup_general; ?></h4>
					<table style='border: 0;'>
						<tr>
							<td style='padding-right: 10pt;'>
								<input type="checkbox" id="power_off" name="power_off" <?php echo $power_off_force=="True"?"checked":""; ?>>
							</td>
							<td>
								<label for="power_off"><?php echo L::main_backup_power_off_checkbox_label; ?></label>
							</td>
						</tr>
					</table>
				</div>

				<div class='backupsection'>
					<h4><?php echo L::main_backup_primary; ?></h4>
					<table style='border: 0;'>

						<tr>
							<td style='padding-right: 10pt; vertical-align: top;'>
								<input type="checkbox" id="move_files" name="move_files" <?php echo $move_files=="True"?"checked":""; ?>>
							</td>
							<td>
								<label for="move_files"><?php echo L::config_backup_move_files_label; ?></label>
							</td>
						</tr>

						<tr>
							<td style='padding-right: 10pt; vertical-align: top;'>
								<input type="checkbox" id="rename_files" name="rename_files" <?php echo $rename_files=="True"?"checked":""; ?>>
							</td>
							<td>
								<label for="rename_files"><?php echo L::main_backup_rename_checkbox_label; ?> <a title="<?php echo L::config_backup_rename_warning; ?>">&#x1F6C8;</a></label>
							</td>
						</tr>

						<tr>
							<td style='padding-right: 10pt; vertical-align: top;'>
								<input type="checkbox" id="generate_thumbnails" name="generate_thumbnails" <?php echo $generate_thumbnails=="True"?"checked":""; ?>>
							</td>
							<td>
								<label for="generate_thumbnails"><?php echo L::main_backup_generate_thumbnails_checkbox_label; ?></label>
							</td>
						</tr>

						<tr>
							<td style='padding-right: 10pt; vertical-align: top;'>
								<input type="checkbox" id="update_exif" name="update_exif" <?php echo $update_exif=="True"?"checked":""; ?>>
							</td>
							<td>
								<label for="update_exif"><?php echo L::main_backup_update_exif_checkbox_label; ?></label>
							</td>
						</tr>

						<tr>
							<td style='padding-right: 10pt; vertical-align: top;'>
								<input type="checkbox" id="checksum" name="checksum" <?php echo $checksum=="True"?"checked":""; ?>>
							</td>
							<td>
								<label for="checksum"><?php echo L::main_backup_checksum_checkbox_label; ?></label>
							</td>
						</tr>

					</table>

					<?php
						unset($Partitions);
						exec("sudo python3 ${WORKING_DIR}/lib_storage.py --Action get_available_partitions --skipMounted False", $Partitions);
					?>

					<table style='border: 0;'>

						<tr>
							<td>
								<label for="preset_source"><?php echo L::main_backup_preset_source_label; ?>:</label>
							</td>
							<td style='padding-right: 10pt;'>
								<select id="preset_source" name="preset_source" onchange="PresetPartitionChange()">
									<option value=''><?php echo L::main_backup_preset_partition_auto; ?></option>
									<?php
										foreach ($Partitions as $Partition) {
											list($Lum, $DeviceIdentifier)	= array_pad(explode(': ', $Partition, 2), 2, null);
											$Lum	= str_replace('/dev/', '', $Lum);
											echo "<option value='".$DeviceIdentifier."'>".$Lum.($DeviceIdentifier!=''?" (".trim($DeviceIdentifier, '-').")":'')."</option>";
										}
									?>
								</select>
							</td>
						</tr>

						<tr>
							<td>
								<label for="preset_target"><?php echo L::main_backup_preset_target_label; ?>:</label>
							</td>
							<td style='padding-right: 10pt;'>
								<select id="preset_target" name="preset_target" onchange="PresetPartitionChange()">
									<option value=''><?php echo L::main_backup_preset_partition_auto; ?></option>
									<?php
										foreach ($Partitions as $Partition) {
											list($Lum, $DeviceIdentifier)	= array_pad(explode(': ', $Partition, 2), 2, null);
											$Lum	= str_replace('/dev/', '', $Lum);
											echo "<option value='".$DeviceIdentifier."'>".$Lum.($DeviceIdentifier!=''?" (".trim($DeviceIdentifier, '-').")":'')."</option>";
										}
									?>
								</select>
							</td>
						</tr>

					</table>
				</div>

				<div class='backupsection'>
					<h4><?php echo L::main_backup_secondary; ?></h4>
					<table style='border: 0;'>
						<tr>
							<td style='padding-right: 10pt;'>
								<?php get_secondary_backup_selector('BACKUP_MODE_2', $CloudServices, $config, $NVMe_available, true); ?>
							</td>
							<td>
								<label for="preset_target"><?php echo L::main_backup_secondary_label; ?></label>
							</td>
						</tr>
					</table>
				</div>

			<?php if (in_array('social:telegram', $SocialServices_configured)) { ?>
				<details>
					<summary style="letter-spacing: 1px; text-transform: uppercase;"><?php echo L::main_telegram_telegram; ?></summary>

						<div class='backupsection'>
							<h4><?php echo L::config_social_telegram_header; ?></h4>
								<?php include("${WORKING_DIR}/sub-telegram-chat-id.php"); ?>
								<input type="hidden" id="conf_SOCIAL_TELEGRAM_TOKEN" name="conf_SOCIAL_TELEGRAM_TOKEN" value="<?php echo $config['conf_SOCIAL_TELEGRAM_TOKEN']; ?>">
						</div>
				</details>
			<?php }

			if (in_array('social:matrix', $SocialServices_configured)) { ?>
				<details>
					<summary style="letter-spacing: 1px; text-transform: uppercase;"><?php echo L::main_matrix_matrix; ?></summary>

						<div class='backupsection'>
							<h4><?php echo L::config_social_matrix_header; ?></h4>
								<?php include("${WORKING_DIR}/sub-matrix-room-id.php"); ?>
								<input type="hidden" id="conf_SOCIAL_MATRIX_HOMESERVER" name="conf_SOCIAL_MATRIX_HOMESERVER" value="<?php echo $config['conf_SOCIAL_MATRIX_HOMESERVER']; ?>">
								<input type="hidden" id="conf_SOCIAL_MATRIX_TOKEN" name="conf_SOCIAL_MATRIX_TOKEN" value="<?php echo $config['conf_SOCIAL_MATRIX_TOKEN']; ?>">
						</div>
				</details>
			<?php } ?>
			<?php print("</div></details>"); ?>

			<?php
				// stop: only while a backup runs (or this request starts one); kept current by the script below
				$backup_running	= lbb_dashboard_backup_running($constants) || isset($_POST['TargetDevice']) || isset($_POST['backup_function']);
			?>
			<div class='backupsection' id='stop-section'<?php echo $backup_running ? '' : ' hidden'; ?>>
				<button name="stopbackup" class="danger"><?php echo L::main_stopbackup_button; ?></button>
			</div>
		</div>

		<script>
			// source picker: shows the chosen source; choosing one closes it and opens the targets
			(function () {
				const source = document.getElementById('picker-source');
				const target = document.getElementById('picker-target');
				const value = document.getElementById('picker-source-value');
				function show() {
					const checked = source.querySelector('input[type=radio]:checked');
					const label = checked && source.querySelector('label[for="' + checked.id + '"]');
					if (label) {
						value.textContent = label.textContent;
						source.classList.add('chosen');
					}
				}
				// stop button only while a backup runs
				const stop = document.getElementById('stop-section');
				async function checkRunning() {
					try {
						const data = await (await fetch('/touch-api.php?action=copying', { cache: 'no-store' })).json();
						stop.hidden = !data.running;
					} catch (e) { /* keep as it is */ }
				}
				setInterval(() => { if (!document.hidden) checkRunning(); }, 4000);

				source.querySelectorAll('input[type=radio]').forEach((radio) => radio.addEventListener('change', () => {
					show();
					source.open = false;
					target.open = true;
				}));
				show();
			})();
		</script>

		<div class="card">
			<details class="lbb-maintenance">
				<summary><?php echo L::touch_maintenance; ?></summary>

				<?php // thumbnails, database, EXIF and renaming for one drive: choose the drive once, then run a task ?>
				<div class="lbb-maint-drive">
					<span><?php echo L::touch_storage; ?></span>
					<span class="lbb-seg">
						<input type="radio" name="maintenance_drive" value="usb" id="maint_usb" checked><label for="maint_usb"><?php echo L::main_usb_button; ?></label>
						<input type="radio" name="maintenance_drive" value="internal" id="maint_internal"><label for="maint_internal"><?php echo L::main_internal_button; ?></label>
						<?php if ($NVMe_available) { ?><input type="radio" name="maintenance_drive" value="nvme" id="maint_nvme"><label for="maint_nvme"><?php echo L::main_nvme_button; ?></label><?php } ?>
					</span>
				</div>

				<table class="lbb-maint">
					<tr>
						<td><b><?php echo L::main_thumbnails_header; ?></b></td>
						<td><button name="backup_function" value="thumbnails" class="usb"><?php echo L::touch_run; ?></button></td>
					</tr>
					<tr>
						<td><b><?php echo L::main_database_header; ?></b></td>
						<td><button name="backup_function" value="database" class="usb"><?php echo L::touch_run; ?></button></td>
					</tr>
					<tr>
						<td><b><?php echo L::main_exif_header; ?></b></td>
						<td><button name="backup_function" value="exif" class="usb"><?php echo L::touch_run; ?></button></td>
					</tr>
					<tr>
						<td><b><?php echo L::main_backup_rename_checkbox_label; ?></b><br><small><?php echo L::config_backup_rename_warning; ?></small></td>
						<td><button name="backup_function" value="rename" class="usb"><?php echo L::touch_run; ?></button></td>
					</tr>
				</table>
			</details>
		</div>


	</form>

	<?php
		// newest photos and videos of the backup drive and source, like the touch UI shows them
		include "${WORKING_DIR}/sub-gallery.php";
		gallery_section('compact', 12);
	?>

	<?php include "sub-logmonitor.php";
		logmonitor($sourcefile=$constants['const_LOGFILE'], $title=L::log_logmonitor, $allow_logfile_operations=true);
	?>

	<div class="card" style="margin-top: 3em;">
		<details>
			<summary style="letter-spacing: 1px; text-transform: uppercase;"><?php echo L::help; ?></summary>
			<p><?php echo L::main_help_txt; ?></p>
		</details>
	</div>

	<?php include "sub-footer.php"; ?>
	<?php
		shell_exec("mkdir -p tmp");
		shell_exec("sudo chown www-data:www-data ./tmp -R");

		// 	Backup
		if (isset($_POST['TargetDevice'])) {

			$SecBackupArgs	= '';
			if (isset($_POST['BACKUP_MODE_2'])) {
				$BACKUP_MODE_2_array	= explode(' ', $_POST['BACKUP_MODE_2'], 2);
				if (count($BACKUP_MODE_2_array)==2) {
					if (($BACKUP_MODE_2_array[0] !== 'none') and ($BACKUP_MODE_2_array[1] !== 'none')) {
						$SecBackupArgs	= "--SecSourceName " . escapeshellarg($BACKUP_MODE_2_array[0]) . " --SecTargetName " . escapeshellarg($BACKUP_MODE_2_array[1]);
					}
				}
			}

			if ($_POST['SourceDevice'] !== 'usb') {
				$preset_source	= '';
			}

			shell_exec("sudo $WORKING_DIR/stop_backup.sh");
			shell_exec("sudo python3 $WORKING_DIR/backup.py --SourceName " . escapeshellarg($_POST['SourceDevice']) . " --TargetName " . escapeshellarg($_POST['TargetDevice']) . " --move-files '$move_files' --rename-files '$rename_files' --force-sync-database False --generate-thumbnails '$generate_thumbnails' --update-exif '$update_exif' --checksum '$checksum' --device-identifier-preset-source " . escapeshellarg($preset_source) . " --device-identifier-preset-target " . escapeshellarg($preset_target) . " --telegram-chat-id " . escapeshellarg($_POST['conf_SOCIAL_TELEGRAM_CHAT_ID']) . " --matrix-room-id " . escapeshellarg($_POST['conf_SOCIAL_MATRIX_ROOM_ID']) . " --power-off $power_off_force $SecBackupArgs> /dev/null 2>&1 &");
			popup(L::main_backup_backup . " " . $_POST['SourceDevice'] . " " . L::main_backup_to . " " . $_POST['TargetDevice'] . " ". L::main_backup_initiated. ".", $config["conf_POPUP_MESSAGES"]);
		}

		// 	functions
		if (isset($_POST['backup_function'])) {
			$FunctionValues	= explode('_', $_POST['backup_function'], 2);
			$Function	= $FunctionValues[0];
			$Target		= $FunctionValues[1] ?? (in_array($_POST['maintenance_drive'] ?? '', array('usb', 'internal', 'nvme'), true) ? $_POST['maintenance_drive'] : 'usb');

			$sync_database	= 'False';
			if ($Function == 'thumbnails') {
				$generate_thumbnails	= 'True';
				$update_exif			= 'False';

				$ButtonLabel_Function	= L::main_function_thumbnails;
			}
			elseif ($Function == 'database') {
				$sync_database			= 'True';
				$generate_thumbnails	= 'False';
				$update_exif			= 'False';

				$ButtonLabel_Function	= L::main_function_sync;
			}
			elseif ($Function == 'exif') {
				$rename_files			= 'False';
				$generate_thumbnails	= 'False';
				$update_exif			= 'True';

				$ButtonLabel_Function	= L::main_function_exif;
			}
			elseif ($Function == 'rename') {
				$rename_files			= 'True';
				$generate_thumbnails	= 'False';
				$update_exif			= 'False';

				$ButtonLabel_Function	= L::main_function_exif;
			}

			if ($Target == 'internal') {
				$ButtonLabel_Target	= L::main_internal_button;
			}
			elseif ($Target == 'nvme') {
				$ButtonLabel_Target	= L::main_nvme_button;
			}
			else {
				$ButtonLabel_Target	= L::main_usb_button;
			}

			shell_exec("sudo $WORKING_DIR/stop_backup.sh");
			shell_exec("sudo python3 $WORKING_DIR/backup.py --SourceName $Function --TargetName $Target --rename-files $rename_files --force-sync-database $sync_database --generate-thumbnails $generate_thumbnails --update-exif $update_exif --device-identifier-preset-source " . escapeshellarg($preset_source) . " --device-identifier-preset-target " . escapeshellarg($preset_target) . " --power-off $power_off_force> /dev/null 2>&1 &");
			popup($ButtonLabel_Function . " " . L::main_backup_on . " " . $ButtonLabel_Target . ".",$config["conf_POPUP_MESSAGES"]);
		}

		// stop backup
		if (isset($_POST['stopbackup'])) {
			popup(L::main_stopbackup_m,$config["conf_POPUP_MESSAGES"]);
			shell_exec("sudo $WORKING_DIR/stop_backup.sh");
		}

	?>
</body>

</html>
