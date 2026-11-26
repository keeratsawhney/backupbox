#!/usr/bin/env bash

# The kiosk screen (DSI / HDMI screen with the desktop's kiosk browser) like the app:
#  - text and buttons as big as in the app (the page is scaled to the screen: its shorter side is about 320 px)
#  - no mouse pointer (a transparent pointer theme for the kiosk user)
#  - no red scroll bar: lists scroll by dragging (the touch UI) or by touch
#  - touch events on
# Runs after the kiosk is set up (setup-graphical-environment.sh); safe to run again. Without the graphical
# environment (a box with an OLED) it does nothing.
#
# Test: LBB_ROOT=<folder with home, usr, sys> FORCE=1 ./install-kiosk-tweaks.sh
# Scale by hand: LBB_KIOSK_SCALE=1.5 ./install-kiosk-tweaks.sh

set -euo pipefail

ROOT="${LBB_ROOT:-}"
KIOSK_USER="lbb-desktop"
HOME_DIR="${ROOT}/home/${KIOSK_USER}"
PROFILE="${HOME_DIR}/.mozilla/firefox-esr/kiosk.default"
USER_JS="${PROFILE}/user.js"
USER_CONTENT="${PROFILE}/chrome/userContent.css"
CURSOR_DIR="${ROOT}/usr/share/icons/lbb-blank"

if [[ -z "${FORCE:-}" && ! -f /usr/sbin/lightdm ]]; then
	exit 0
fi

SUDO=""
AS_USER=""
if [[ -z "${ROOT}" ]]; then
	SUDO="sudo"
	AS_USER="sudo -u ${KIOSK_USER}"
fi

echo "Setting up the kiosk screen ..."

# ---------- scale: the shorter side of the screen is about 320 px of the page ----------

screen_mode() {
	# WxH of the first connected DSI screen, else HDMI screen
	local KIND STATUS
	for KIND in DSI HDMI; do
		for STATUS in "${ROOT}"/sys/class/drm/card*-"${KIND}"-*/status; do
			[[ -f "${STATUS}" && "$(cat "${STATUS}")" == "connected" ]] || continue
			head -1 "$(dirname "${STATUS}")/modes" 2>/dev/null && return 0
		done
	done
	return 1
}

SCALE="${LBB_KIOSK_SCALE:-}"
if [[ -z "${SCALE}" ]]; then
	MODE=$(screen_mode || true)
	if [[ "${MODE}" =~ ^([0-9]+)x([0-9]+) ]]; then
		W="${BASH_REMATCH[1]}"
		H="${BASH_REMATCH[2]}"
		SHORT=$(( W < H ? W : H ))
		if (( SHORT > 360 )); then
			PERCENT=$(( (SHORT * 100 / 320 + 2) / 5 * 5 ))
			(( PERCENT > 300 )) && PERCENT=300
			SCALE=$(printf '%d.%02d' $(( PERCENT / 100 )) $(( PERCENT % 100 )))
		fi
	fi
fi

set_pref() {
	local KEY="$1"
	local VALUE="$2"
	if ${AS_USER} test -f "${USER_JS}" && ${AS_USER} grep -qE "user_pref\(\"${KEY}\"," "${USER_JS}"; then
		${AS_USER} sed -i -E "s|user_pref\(\"${KEY}\",.*\);|user_pref(\"${KEY}\", ${VALUE});|g" "${USER_JS}"
	elif ${AS_USER} test -d "${PROFILE}"; then
		echo "user_pref(\"${KEY}\", ${VALUE});" | ${AS_USER} tee -a "${USER_JS}" >/dev/null
	fi
}

# ---------- scrolling by dragging in every page: a small add-on of the kiosk browser ----------
# The touch UI scrolls by dragging by itself (js/lbb-dragscroll.js). The add-on runs the same script in every page and
# frame of the box's own browser, e.g. the full web interface. Installed through the browser's policy file, like the
# keyboard add-on (it must also run in the private window of the kiosk).

ADDON_ID="lbb-touch-scroll@little-backup-box"
ADDON_DIR="${ROOT}/opt/little-backup-box/touch-scroll"
POLICY_FILE="${ROOT}/etc/firefox/policies/policies.json"
DRAG_JS="${ROOT}/var/www/little-backup-box/js/lbb-dragscroll.js"
ADDON_OK=0

build_addon() {
	[[ -f "${DRAG_JS}" ]] || return 1
	if ! command -v zip >/dev/null 2>&1; then
		${SUDO} env DEBIAN_FRONTEND=noninteractive apt-get install -y -q zip >/dev/null 2>&1 || return 1
	fi
	# a version that changes with the script: the browser takes a newer add-on at its next start
	local VERSION="1.0.$(( $(cksum < "${DRAG_JS}" | cut -d' ' -f1) % 100000000 ))"
	${SUDO} mkdir -p "${ADDON_DIR}/extension/content"
	${SUDO} cp -f "${DRAG_JS}" "${ADDON_DIR}/extension/content/lbb-dragscroll.js"
	cat <<MANIFEST | ${SUDO} tee "${ADDON_DIR}/extension/manifest.json" >/dev/null
{
	"manifest_version": 2,
	"name": "LBB Touch Scroll",
	"version": "${VERSION}",
	"description": "Scrolling by dragging for the touchscreen of the Little Backup Box kiosk.",
	"browser_specific_settings": {
		"gecko": {
			"id": "${ADDON_ID}"
		}
	},
	"content_scripts": [
		{
			"matches": ["http://*/*", "https://*/*"],
			"js": ["content/lbb-dragscroll.js"],
			"run_at": "document_end",
			"all_frames": true,
			"match_about_blank": true
		}
	]
}
MANIFEST
	(cd "${ADDON_DIR}/extension" && ${SUDO} zip -qr -FS "../lbb-touch-scroll.xpi" .) || return 1

	# the policy file: this add-on is installed (also in private windows), local add-ons without a signature are allowed
	${SUDO} python3 - "${POLICY_FILE}" "file://${ADDON_DIR}/lbb-touch-scroll.xpi" "${ADDON_ID}" <<'PYEOF' || return 1
import json, os, sys

path, url, addon = sys.argv[1:4]
try:
	with open(path) as f:
		data = json.load(f)
except (OSError, ValueError):
	data = {}
policies = data.setdefault('policies', {})
policies.setdefault('ExtensionSettings', {})[addon] = {
	'installation_mode': 'force_installed',
	'install_url': url,
	'private_browsing': True,
}
policies.setdefault('Preferences', {})['xpinstall.signatures.required'] = {'Value': False, 'Status': 'locked', 'Type': 'boolean'}
os.makedirs(os.path.dirname(path), exist_ok=True)
with open(path, 'w') as f:
	json.dump(data, f, indent=2)
PYEOF
}

if build_addon; then
	ADDON_OK=1
	echo "  scrolling by dragging in every page: add-on installed"
else
	echo "  (the scrolling add-on could not be built: the full web interface can not scroll by dragging)"
fi

if ${AS_USER} test -d "${PROFILE}"; then
	if [[ -n "${SCALE}" ]]; then
		set_pref "layout.css.devPixelsPerPx" "\"${SCALE}\""
		echo "  page scale ${SCALE}"
	else
		echo "  page scale 1 (a small screen, or the screen size is not known)"
	fi
	# touch events for the page: a touchscreen then scrolls and taps natively
	set_pref "dom.w3c_touch_events.enabled" "1"

	# no scroll bars: lists scroll by touch or by dragging (the original file made a very wide red bar to drag).
	# Only when the touch UI that scrolls by dragging is installed: without it the bar is the only way to scroll.
	TOUCH_JS="${ROOT}/var/www/little-backup-box/js/lbb-dragscroll.js"
	if ${AS_USER} test -f "${USER_CONTENT}" && ${AS_USER} grep -q "very wide scrollbar" "${USER_CONTENT}"; then
		if [[ "${ADDON_OK}" == 1 && -f "${TOUCH_JS}" ]]; then
			printf '%s\n' '/* no scroll bars: lists scroll by touch or by dragging */' ':root, * {' '  scrollbar-width: none !important;' '}' | ${AS_USER} tee "${USER_CONTENT}" >/dev/null
		else
			echo "  the red scroll bar stays: the scrolling by dragging is not installed completely yet (run ./install-touch-ui.sh)"
		fi
	fi
else
	echo "  (the browser profile is not there yet: the page scale and the scroll bar are not set)"
fi

# ---------- no mouse pointer: a transparent cursor theme ----------

${SUDO} mkdir -p "${CURSOR_DIR}/cursors"
printf '[Icon Theme]\nName=lbb-blank\nComment=No mouse pointer\n' | ${SUDO} tee "${CURSOR_DIR}/index.theme" >/dev/null

${SUDO} python3 - "${CURSOR_DIR}/cursors" <<'PYEOF'
import os, struct, sys

folder = sys.argv[1]

# Xcursor file with one 1x1 transparent image
size = 24
image = struct.pack('<9I', 36, 0xfffd0002, size, 1, 1, 1, 0, 0, 0) + struct.pack('<I', 0)
data = b'Xcur' + struct.pack('<III', 16, 0x10000, 1) + struct.pack('<III', 0xfffd0002, size, 28) + image

with open(os.path.join(folder, 'left_ptr'), 'wb') as f:
	f.write(data)

names = ['default', 'arrow', 'pointer', 'hand', 'hand1', 'hand2', 'text', 'xterm', 'ibeam', 'watch', 'wait', 'progress',
	'left_ptr_watch', 'crosshair', 'cross', 'move', 'fleur', 'grab', 'grabbing', 'openhand', 'closedhand', 'not-allowed',
	'no-drop', 'col-resize', 'row-resize', 'ew-resize', 'ns-resize', 'nesw-resize', 'nwse-resize', 'all-scroll', 'help',
	'question_arrow', 'zoom-in', 'zoom-out', 'copy', 'alias', 'context-menu', 'vertical-text', 'sb_h_double_arrow',
	'sb_v_double_arrow', 'top_left_arrow', 'center_ptr', 'e-resize', 'n-resize', 's-resize', 'w-resize', 'ne-resize',
	'nw-resize', 'se-resize', 'sw-resize', 'cell', 'dnd-ask', 'dnd-copy', 'dnd-move', 'dnd-none', 'pencil', 'plus']
for name in names:
	link = os.path.join(folder, name)
	if not os.path.lexists(link):
		os.symlink('left_ptr', link)
PYEOF

# the kiosk session (labwc, then the browser) and GTK use it
ENV_FILE="${HOME_DIR}/.config/labwc/environment"
if ${AS_USER} test -d "${HOME_DIR}/.config/labwc"; then
	if ! ${AS_USER} grep -qs "^XCURSOR_THEME=" "${ENV_FILE}"; then
		echo "XCURSOR_THEME=lbb-blank" | ${AS_USER} tee -a "${ENV_FILE}" >/dev/null
	fi
fi
GTK_FILE="${HOME_DIR}/.config/gtk-3.0/settings.ini"
if ${AS_USER} test -d "${HOME_DIR}/.config"; then
	${AS_USER} mkdir -p "${HOME_DIR}/.config/gtk-3.0"
	if ! ${AS_USER} test -f "${GTK_FILE}"; then
		printf '[Settings]\ngtk-cursor-theme-name=lbb-blank\n' | ${AS_USER} tee "${GTK_FILE}" >/dev/null
	elif ! ${AS_USER} grep -q "^gtk-cursor-theme-name=" "${GTK_FILE}"; then
		if ${AS_USER} grep -q "^\[Settings\]" "${GTK_FILE}"; then
			${AS_USER} sed -i '/^\[Settings\]/a gtk-cursor-theme-name=lbb-blank' "${GTK_FILE}"
		else
			printf '\n[Settings]\ngtk-cursor-theme-name=lbb-blank\n' | ${AS_USER} tee -a "${GTK_FILE}" >/dev/null
		fi
	fi
fi

# ---------- no Pi desktop: a plain labwc session ----------
# The session of the Pi desktop starts labwc with -m: the desktop's own start script (wallpaper, panel) runs next to the
# kiosk's. A plain labwc session runs only the kiosk's start script.
SESSION_FILE="${ROOT}/usr/share/wayland-sessions/labwc.desktop"
LIGHTDM_CONF="${ROOT}/etc/lightdm/lightdm.conf"
if [[ -f "${SESSION_FILE}" && -f "${LIGHTDM_CONF}" ]]; then
	if ! grep -q "^autologin-session=labwc$" "${LIGHTDM_CONF}"; then
		[[ -e "${LIGHTDM_CONF}.lbb-backup" ]] || ${SUDO} cp "${LIGHTDM_CONF}" "${LIGHTDM_CONF}.lbb-backup"
		if grep -qE "^#?autologin-session=" "${LIGHTDM_CONF}"; then
			${SUDO} sed -i -E "s/^#?autologin-session=.*/autologin-session=labwc/" "${LIGHTDM_CONF}"
			echo "  kiosk session: plain labwc (no Pi desktop)"
		elif grep -q "^autologin-user=" "${LIGHTDM_CONF}"; then
			${SUDO} sed -i "/^autologin-user=/a autologin-session=labwc" "${LIGHTDM_CONF}"
			echo "  kiosk session: plain labwc (no Pi desktop)"
		else
			echo "  (the automatic login is not set in ${LIGHTDM_CONF}: the session is not changed)"
		fi
	fi
else
	echo "  (no plain labwc session on this system: the Pi desktop stays behind the kiosk)"
fi

echo "Kiosk screen set up. It applies at the next start."
