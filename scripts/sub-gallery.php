<?php
/*
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

// Gallery of the web UI: photos and videos of the mounted backup drives and sources,
// read from the drives like the photos page of the touch UI (js/lbb-gallery.js, touch-api.php).
// $mode: 'full' (gallery.php) or 'compact' (newest files on the backup page)

function gallery_section($mode='full', $limit=48) {
	$text	= array();
	foreach (array('title', 'latest', 'open', 'all', 'photos', 'raw', 'videos', 'search', 'sort_new', 'sort_old', 'sort_name', 'per_page', 'files', 'page', 'first', 'last', 'previous', 'next', 'close', 'download', 'size', 'folder', 'date', 'refresh', 'no_drive', 'no_match') as $key) {
		$text[$key]	= constant('L::gallery_' . $key);
	}
	foreach (array('no_photos', 'source', 'mount_failed', 'video_unsupported') as $key) {
		$text[$key]	= constant('L::touch_' . $key);
	}
	?>
	<svg width="0" height="0" style="position:absolute" aria-hidden="true">
		<symbol id="lbbg-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></symbol>
		<symbol id="lbbg-play" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></symbol>
		<symbol id="lbbg-photo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 16l-5-5L5 20"/></symbol>
		<symbol id="lbbg-prev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></symbol>
		<symbol id="lbbg-next" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></symbol>
		<symbol id="lbbg-first" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 18l-6-6 6-6"/><path d="M7 6v12"/></symbol>
		<symbol id="lbbg-last" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18l6-6-6-6"/><path d="M17 6v12"/></symbol>
		<symbol id="lbbg-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></symbol>
		<symbol id="lbbg-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M5 20h14"/></symbol>
	</svg>
	<script>window.LBB_GALLERY_TEXT = <?php echo json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
	<section class="card lbb-gallery" id="gallery" data-mode="<?php echo $mode == 'compact' ? 'compact' : 'full'; ?>" data-limit="<?php echo (int)$limit; ?>"></section>
	<script src="js/lbb-gallery.js"></script>
	<?php
}
?>
