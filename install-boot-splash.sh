#!/usr/bin/env bash

# Boot screen with the logo, straight into the kiosk (touchscreen with the desktop's kiosk browser):
#  - Plymouth theme with the logo from the first moment of the boot (and no boot messages, cursor or rainbow)
#  - the kiosk session shows the same logo until the browser is up: the desktop of the Pi is never seen
#  - the browser window is dark from the start (no white flash)
# Safe to run again. Without a graphical environment (a box with an OLED) it does nothing.
#
# Test: LBB_ROOT=<folder with etc, boot, home, usr> FORCE=1 ./install-boot-splash.sh

set -euo pipefail

INSTALLER_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT="${LBB_ROOT:-}"
BOOT="${ROOT}/boot/firmware"
KIOSK_USER="lbb-desktop"
COLOR="#12161c"
LOGO_SOURCE="${INSTALLER_DIR}/scripts/img/brand/logo-white.png"
SHARE="${ROOT}/usr/share/backupbox"
THEME="${ROOT}/usr/share/plymouth/themes/backupbox"
AUTOSTART="${ROOT}/home/${KIOSK_USER}/.config/labwc/autostart"
FIREFOX_PREFS="${ROOT}/home/${KIOSK_USER}/.mozilla/firefox-esr/kiosk.default/user.js"

if [[ -z "${FORCE:-}" && ! -f /usr/sbin/lightdm ]]; then
	exit 0
fi
if [[ ! -f "${LOGO_SOURCE}" ]]; then
	echo "Boot screen: ${LOGO_SOURCE} not found."
	exit 1
fi

SUDO=""
[[ -z "${ROOT}" ]] && SUDO="sudo"

echo "Setting up the boot screen ..."

# size of the logo: 55 % of the shorter side of the screen (the DSI panel's mode, else 320 px)
SIZE=320
MODE=$(cat "${ROOT}"/sys/class/drm/card*-DSI-*/modes 2>/dev/null | head -1 || true)
if [[ "${MODE}" =~ ^([0-9]+)x([0-9]+) ]]; then
	W="${BASH_REMATCH[1]}"
	H="${BASH_REMATCH[2]}"
	SIZE=$(( (W < H ? W : H) * 55 / 100 ))
fi
[[ -n "${LOGO_SIZE:-}" ]] && SIZE="${LOGO_SIZE}"

${SUDO} mkdir -p "${SHARE}" "${THEME}"
${SUDO} convert "${LOGO_SOURCE}" -resize "${SIZE}x${SIZE}" "${SHARE}/splash-logo.png"
${SUDO} cp -f "${SHARE}/splash-logo.png" "${THEME}/logo.png"
${SUDO} cp -f "${INSTALLER_DIR}/boot-splash/backupbox.plymouth" "${INSTALLER_DIR}/boot-splash/backupbox.script" "${THEME}/"

# Plymouth: this theme (the initramfs is made again for it)
if [[ -z "${ROOT}" ]]; then
	if ! command -v plymouth-set-default-theme >/dev/null 2>&1; then
		sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q plymouth >/dev/null 2>&1 || true
	fi
	if command -v plymouth-set-default-theme >/dev/null 2>&1; then
		sudo plymouth-set-default-theme -R backupbox || echo "Boot screen: the theme could not be set (the boot shows the standard screen)."
	else
		echo "Boot screen: Plymouth is not installed (no boot logo, the kiosk logo still works)."
	fi
fi

# An earlier version kept the logo on the screen when Plymouth ends (--retain-splash). With the kiosk drawing the same
# logo over it, a frame in another size or orientation showed through as lines and a faded double logo: removed again.
if [[ -z "${ROOT}" && -f /etc/systemd/system/plymouth-quit.service.d/retain-splash.conf ]]; then
	sudo rm -f /etc/systemd/system/plymouth-quit.service.d/retain-splash.conf
	sudo rmdir --ignore-fail-on-non-empty /etc/systemd/system/plymouth-quit.service.d 2>/dev/null || true
	sudo systemctl daemon-reload || true
fi

# kernel command line: splash on, no boot messages, no cursor, no penguin; one line, only what is missing is added
CMDLINE="${BOOT}/cmdline.txt"
if [[ -f "${CMDLINE}" ]]; then
	[[ -e "${CMDLINE}.lbb-backup" ]] || ${SUDO} cp "${CMDLINE}" "${CMDLINE}.lbb-backup"
	for OPTION in quiet splash plymouth.ignore-serial-consoles logo.nologo vt.global_cursor_default=0 loglevel=3; do
		if ! tr ' ' '\n' < "${CMDLINE}" | grep -qxF "${OPTION}"; then
			${SUDO} sed -i "1 s/\$/ ${OPTION}/" "${CMDLINE}"
		fi
	done
fi

# firmware: no rainbow screen
CONFIG="${BOOT}/config.txt"
if [[ -f "${CONFIG}" ]] && ! grep -q "^disable_splash=" "${CONFIG}"; then
	[[ -e "${CONFIG}.lbb-backup" ]] || ${SUDO} cp "${CONFIG}" "${CONFIG}.lbb-backup"
	printf '\n# Backup Box: no rainbow screen at power on\ndisable_splash=1\n' | ${SUDO} tee -a "${CONFIG}" >/dev/null
fi

# the kiosk session: the logo on the same colour until the browser is up (swaybg, before the wait for the server)
if [[ -f "${AUTOSTART}" ]] && ! grep -q "splash-logo.png" "${AUTOSTART}"; then
	[[ -e "${AUTOSTART}.lbb-backup" ]] || ${SUDO} cp "${AUTOSTART}" "${AUTOSTART}.lbb-backup"
	BLOCK=$(mktemp)
	printf '%s\n' '# boot logo until the browser is up (install-boot-splash.sh)' 'if [ -f /usr/share/backupbox/splash-logo.png ]; then' "	swaybg -c '${COLOR}' -i /usr/share/backupbox/splash-logo.png -m center >/dev/null 2>&1 &" 'fi' '' > "${BLOCK}"
	if grep -q "^## wait for server" "${AUTOSTART}"; then
		${SUDO} sed -i "/^## wait for server/e cat ${BLOCK}" "${AUTOSTART}"
		${SUDO} chmod +x "${AUTOSTART}"
	else
		echo "Boot screen: the kiosk start script has an unexpected form (${AUTOSTART}); the boot logo is set, the logo in the kiosk session is not."
	fi
	rm -f "${BLOCK}"
fi

# the browser window: dark from the start
if [[ -f "${FIREFOX_PREFS}" ]] && ! grep -q "browser.display.background_color" "${FIREFOX_PREFS}"; then
	echo "user_pref(\"browser.display.background_color\", \"${COLOR}\");" | ${SUDO} tee -a "${FIREFOX_PREFS}" >/dev/null
fi

echo "Boot screen set up. It shows at the next start."
