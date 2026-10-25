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

// Helpers for the touchscreen UI (touch.php / touch-api.php).
// Expects from calling script: included language (class L), $config, $constants, $WORKING_DIR

	include_once("sub-common.php");
	include_once("sub-socialmedia.php");

	function touch_text($text) {
		# language strings may contain html entities, the touch UI sets them as plain text
		return(trim(html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
	}

	function touch_service_label($Storage) {
		$LabelNameExplode	= explode(':', $Storage, 2);
		$LabelName			= end($LabelNameExplode);

		$labels	= array(
			'anyusb'		=> L::box_backup_mode_anyusb,
			'usb'			=> L::box_backup_mode_usb,
			'internal'		=> L::box_backup_mode_internal,
			'nvme'			=> L::box_backup_mode_nvme,
			'camera'		=> L::box_backup_mode_cameras,
			'cloud_rsync'	=> L::box_backup_mode_cloud_rsync,
			'ftp'			=> L::box_backup_mode_ftp,
			'telegram'		=> L::box_backup_mode_social_telegram,
			'matrix'		=> L::box_backup_mode_social_matrix,
			'mastodon'		=> L::box_backup_mode_social_mastodon,
			'bluesky'		=> L::box_backup_mode_social_bluesky,
		);

		return(touch_text(isset($labels[$LabelName]) ? $labels[$LabelName] : $LabelName));
	}

	function touch_service_kind($Storage) {
		# used by the UI to pick an icon
		if (str_starts_with($Storage, 'cloud') or $Storage == 'ftp') {return('cloud');}
		if (str_starts_with($Storage, 'social:')) {return('social');}
		if (in_array($Storage, array('camera', 'internal', 'nvme', 'anyusb'))) {return($Storage);}
		return('usb');
	}

	function touch_get_services($config, $constants, $WORKING_DIR) {
		# same service lists as the classic start page (index.php)
		$NVMe_available	= get_nvme_available(WORKING_DIR: $WORKING_DIR, constants: $constants, ignore_fs: false);
		$CloudServices	= get_cloudservices($constants);

		$LocalServices	= array('usb', 'internal');
		if ($NVMe_available) {
			array_push($LocalServices, 'nvme');
		}

		$CloudServices_marked	= array();
		foreach($CloudServices as $CloudService) {
			$CloudServices_marked[]	= 'cloud:' . $CloudService;
		}

		$rsync_configurated	= ($config['conf_RSYNC_SERVER']=='' or $config['conf_RSYNC_PORT']=='' or $config['conf_RSYNC_USER']=='' or $config['conf_RSYNC_PASSWORD']=='' or $config['conf_RSYNC_SERVER_MODULE']=='') == false;
		if ($rsync_configurated) {
			$CloudServices_marked	= array_merge(['cloud_rsync'], $CloudServices_marked);
		}

		$SocialServices_configured	= array();
		foreach (get_social_services_configured() as $SocialService) {
			$SocialServices_configured[]	= "social:$SocialService";
		}

		$Sources	= array_merge(array('anyusb'), $LocalServices, array('camera'), $CloudServices_marked, array('ftp'));
		$Targets	= array_merge($LocalServices, $CloudServices_marked, $SocialServices_configured);

		# internal storage is not offered in the touch UI
		$Sources	= array_values(array_diff($Sources, array('internal')));
		$Targets	= array_values(array_diff($Targets, array('internal')));

		$format	= function($list) {
			$result	= array();
			foreach ($list as $Storage) {
				$result[]	= array(
					'id'	=> $Storage,
					'label'	=> touch_service_label($Storage),
					'kind'	=> touch_service_kind($Storage),
				);
			}
			return($result);
		};

		return(array(
			'sources'	=> $format($Sources),
			'targets'	=> $format($Targets),
			'nvme'		=> $NVMe_available,
		));
	}

	function touch_combination_allowed($Source, $Target) {
		# mirrors HideDisallowedButtons() of index.php
		if (($Target === $Source) and ($Target !== 'usb')) {return(false);}
		if (in_array($Source, array('anyusb', 'camera', 'ftp')) and $Target === 'cloud_rsync') {return(false);}
		if (str_starts_with($Target, 'social') and (in_array($Source, array('anyusb', 'camera', 'ftp')) or str_starts_with($Source, 'cloud'))) {return(false);}
		return(true);
	}
?>
