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

// Usage:
//$constants and $config must be defined in main script.-->

function display($clear=true, $title='') {
	global $config;
	global $constants;

	$show_display	= ($config['conf_DISP'] == 'display' and $config['conf_DISP_RESOLUTION_X'] > 0 and $config['conf_DISP_RESOLUTION_Y'] > 0);

	if (! $show_display and $title == '') {
		return;
	}
	?>
	<header class="lbb-pagehead">
		<?php if ($title != '') { ?>
			<h1><?php echo $title; ?></h1>
		<?php } ?>
		<?php
	if ($show_display) {
		?>

		<div class="lbb-display">
			<img id="display" src="<?php echo str_replace('/var/www/little-backup-box', '' , $constants['const_DISPLAY_IMAGE_EXPORT_FILE']); ?>" width="<?php echo $constants['const_DISPLAY_SIZE_UI_X']; ?>" height="<?php echo $constants['const_DISPLAY_SIZE_UI_Y']; ?>" alt="">
		</div>
		<?php
	}
	?>
	</header>
	<?php
}
