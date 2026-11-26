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
	if [[ -f "${USER_JS}" ]] && grep -qE "user_pref\(\"${KEY}\"," "${USER_JS}"; then
		${AS_USER} sed -i -E "s|user_pref\(\"${KEY}\",.*\);|user_pref(\"${KEY}\", ${VALUE});|g" "${USER_JS}"
	elif [[ -d "${PROFILE}" ]]; then
		echo "user_pref(\"${KEY}\", ${VALUE});" | ${AS_USER} tee -a "${USER_JS}" >/dev/null
	fi
}

if [[ -d "${PROFILE}" ]]; then
	if [[ -n "${SCALE}" ]]; then
		set_pref "layout.css.devPixelsPerPx" "\"${SCALE}\""
		echo "  page scale ${SCALE}"
	else
		echo "  page scale 1 (a small screen, or the screen size is not known)"
	fi
	# touch events for the page: a touchscreen then scrolls and taps natively
	set_pref "dom.w3c_touch_events.enabled" "1"

	# no scroll bars: lists scroll by touch or by dragging (the original file made a very wide red bar to drag)
	if [[ -f "${USER_CONTENT}" ]] && grep -q "very wide scrollbar" "${USER_CONTENT}"; then
		printf '%s\n' '/* no scroll bars: lists scroll by touch or by dragging */' ':root, * {' '  scrollbar-width: none !important;' '}' | ${AS_USER} tee "${USER_CONTENT}" >/dev/null
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
if [[ -d "${HOME_DIR}/.config/labwc" ]]; then
	if ! grep -qs "^XCURSOR_THEME=" "${ENV_FILE}"; then
		echo "XCURSOR_THEME=lbb-blank" | ${AS_USER} tee -a "${ENV_FILE}" >/dev/null
	fi
fi
GTK_FILE="${HOME_DIR}/.config/gtk-3.0/settings.ini"
if [[ -d "${HOME_DIR}/.config" ]]; then
	${AS_USER} mkdir -p "${HOME_DIR}/.config/gtk-3.0"
	if [[ ! -f "${GTK_FILE}" ]]; then
		printf '[Settings]\ngtk-cursor-theme-name=lbb-blank\n' | ${AS_USER} tee "${GTK_FILE}" >/dev/null
	elif ! grep -q "^gtk-cursor-theme-name=" "${GTK_FILE}"; then
		if grep -q "^\[Settings\]" "${GTK_FILE}"; then
			${AS_USER} sed -i '/^\[Settings\]/a gtk-cursor-theme-name=lbb-blank' "${GTK_FILE}"
		else
			printf '\n[Settings]\ngtk-cursor-theme-name=lbb-blank\n' | ${AS_USER} tee -a "${GTK_FILE}" >/dev/null
		fi
	fi
fi

echo "Kiosk screen set up. It applies at the next start."
