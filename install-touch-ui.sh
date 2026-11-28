#!/usr/bin/env bash

# Installs the touchscreen UI (touch.php) from this checkout into an existing
# Little Backup Box installation and restarts the kiosk browser.
#
# Usage (on the box, as the regular user, from the checkout's top directory):
#   ./install-touch-ui.sh
#
# A backup of the web root is written first; the script prints the command to roll back.

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

set -euo pipefail

# the scripts of this fork need Python 3.12 or newer (Raspberry Pi OS Trixie): on Bookworm (Python 3.11) they do not
# even start. Checked first, so that nothing of an older installation is replaced.
if ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 12) else 1)' 2>/dev/null; then
	echo "This version of Backup Box needs Raspberry Pi OS Trixie (Python 3.12 or newer); this system has $(python3 --version 2>&1)."
	echo "Nothing was changed. Install Raspberry Pi OS Trixie (Raspberry Pi Imager, 64-bit) and run install-little-backup-box.sh."
	exit 1
fi

INSTALLER_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
WEB_ROOT="/var/www/little-backup-box"
BACKUP_FILE="/var/www/lbb-webroot-backup-$(date +%Y%m%d-%H%M%S).tar.gz"

if [[ ! -d "${WEB_ROOT}" ]]; then
	echo "Little Backup Box is not installed (${WEB_ROOT} missing). Run install-little-backup-box.sh first."
	exit 1
fi

if [[ ! -f "${INSTALLER_DIR}/scripts/touch.php" ]]; then
	echo "Run this script from the top directory of the checkout."
	exit 1
fi

echo "Backing up ${WEB_ROOT} to ${BACKUP_FILE} ..."
sudo tar -czf "${BACKUP_FILE}" --exclude="./tmp" --exclude="./media" -C "${WEB_ROOT}" .

# same copy step as install-little-backup-box.sh (config.cfg is not part of scripts/ and stays untouched)
echo "Copying web files ..."
sudo cp -Rf "${INSTALLER_DIR}/scripts/"* "${WEB_ROOT}/"
sudo chown -R www-data:www-data "${WEB_ROOT}"

# the installed version (the update button of the touch UI compares it with GitHub)
GIT_VERSION=$(git -C "${INSTALLER_DIR}" rev-parse HEAD 2>/dev/null || true)
if [[ "${GIT_VERSION}" =~ ^[0-9a-f]{40}$ ]]; then
	echo "${GIT_VERSION}" | sudo tee "${WEB_ROOT}/VERSION" >/dev/null
fi

# previews (thumbnails) are no longer made after a backup: the Files tab makes one when a file is opened.
# Switched off once (a box that wants them again switches them on in the settings and keeps them).
THUMBS_MARK="${WEB_ROOT}/data/.thumbnails-off"
if [[ ! -e "${THUMBS_MARK}" && -f "${WEB_ROOT}/config.cfg" ]]; then
	CONFIG_OWNER=$(stat -c '%U:%G' "${WEB_ROOT}/config.cfg")
	sudo sed -i 's/^conf_BACKUP_GENERATE_THUMBNAILS=.*/conf_BACKUP_GENERATE_THUMBNAILS=False/;s/^conf_BACKUP_DEFAULT_GENERATE_THUMBNAILS=.*/conf_BACKUP_DEFAULT_GENERATE_THUMBNAILS=False/' "${WEB_ROOT}/config.cfg"
	sudo chown "${CONFIG_OWNER}" "${WEB_ROOT}/config.cfg"
	sudo mkdir -p "${WEB_ROOT}/data"
	sudo touch "${THUMBS_MARK}"
	sudo chown -R www-data:www-data "${WEB_ROOT}/data"
	echo "Previews after a backup: off (they are made when a file is opened)."
fi

# Tiny File Manager (Files of the classic UI): do not show hidden files
# (macOS "._name" files next to every photo copied from a Mac, .Spotlight-V100, .Trashes, ...)
TFM_CONFIG="/var/www/tinyfilemanager/config.php"
if [[ -f "${TFM_CONFIG}" ]]; then
	sudo sed -i 's/"show_hidden":true/"show_hidden":false/' "${TFM_CONFIG}"
fi

# comitup hotspot: no forced sign-in page, port 80 stays with the Backup Box, Wi-Fi setup at /comitup/
if command -v comitup-cli >/dev/null 2>&1; then
	echo "Setting up the hotspot ..."
	sudo cp -f "${INSTALLER_DIR}/etc/apache2_includes_comitup.conf" /etc/apache2/includes/comitup.conf
	sudo python3 "${WEB_ROOT}/lib_comitup.py" --hotspot_setup
	sudo systemctl reload apache2 || true
fi

# Bluetooth for the Android app: find the box nearby, status, start/stop backup (files stay on Wi-Fi)
echo "Setting up Bluetooth for the app ..."
if ! python3 -c "import dbus, gi" >/dev/null 2>&1; then
	sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q python3-dbus python3-gi >/dev/null 2>&1 || echo "python3-dbus / python3-gi could not be installed (no internet?): Bluetooth for the app stays off"
fi
if command -v bluetoothctl >/dev/null 2>&1 && python3 -c "import dbus, gi" >/dev/null 2>&1; then
	sudo rfkill unblock bluetooth 2>/dev/null || true
	sudo cp -f "${INSTALLER_DIR}/etc/lbb-ble.service" /etc/systemd/system/lbb-ble.service
	sudo systemctl daemon-reload
	sudo systemctl enable --now bluetooth.service >/dev/null 2>&1 || true
	sudo systemctl enable lbb-ble.service >/dev/null 2>&1
	sudo systemctl restart lbb-ble.service
else
	echo "Bluetooth (bluez) not found: Bluetooth for the app stays off"
fi

# Ethernet: Wi-Fi off while a cable is connected; network share tuned for gigabit and a login that works on
# Windows 10/11 (user lbb, password on the System page or the box's password)
echo "Setting up Ethernet and the network share ..."
if [ -d /etc/NetworkManager/dispatcher.d ]; then
	sudo install -m 0755 -o root -g root "${INSTALLER_DIR}/etc/NetworkManager_dispatcher_90-lbb-ethernet" /etc/NetworkManager/dispatcher.d/90-lbb-ethernet
	# a cable already connected
	if nmcli -t -f TYPE,STATE device 2>/dev/null | grep -q '^ethernet:connected'; then
		sudo systemctl stop comitup 2>/dev/null
		sudo nmcli radio wifi off
	fi
fi
# a cable straight into a computer (no router): when no router gives an address within 30 s, the box hands out
# addresses itself (box 10.42.0.1, the computer gets one automatically). With a router the router's profile wins:
# it is tried first, and again whenever the cable is plugged in.
if command -v nmcli >/dev/null 2>&1; then
	ETH=$(nmcli -t -f DEVICE,TYPE device 2>/dev/null | awk -F: '$2=="ethernet"{print $1; exit}')
	if [ -n "${ETH}" ]; then
		dpkg -s dnsmasq-base >/dev/null 2>&1 || sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q dnsmasq-base >/dev/null 2>&1 || true
		ROUTER_PROFILES=$(nmcli -t -f NAME,TYPE connection show 2>/dev/null | awk -F: '$2=="802-3-ethernet" && $1!="lbb-direct"{print $1}')
		if [ -z "${ROUTER_PROFILES}" ]; then
			sudo nmcli connection add type ethernet ifname "${ETH}" con-name lbb-lan ipv4.method auto >/dev/null
			ROUTER_PROFILES="lbb-lan"
		fi
		printf '%s\n' "${ROUTER_PROFILES}" | while IFS= read -r NAME; do
			sudo nmcli connection modify "${NAME}" ipv4.dhcp-timeout 30 connection.autoconnect-priority 10 connection.autoconnect-retries 1 >/dev/null 2>&1 || true
		done
		if ! nmcli -t -f NAME connection show 2>/dev/null | grep -qx lbb-direct; then
			sudo nmcli connection add type ethernet ifname "${ETH}" con-name lbb-direct ipv4.method shared ipv4.addresses 10.42.0.1/24 ipv6.method ignore connection.autoconnect yes connection.autoconnect-priority -10 >/dev/null || true
		fi
	fi
fi
if [ -d /etc/samba ]; then
	sudo cp -f "${INSTALLER_DIR}/etc/samba_smb.conf" /etc/samba/smb.conf
	sudo chmod 0440 /etc/samba/smb.conf
	CONF_PASSWORD=$(sed -n "s/^conf_PASSWORD=['\"]\?\([^'\"]*\)['\"]\?\$/\1/p" "${WEB_ROOT}/config.cfg" 2>/dev/null | base64 --decode 2>/dev/null)
	sudo python3 "${WEB_ROOT}/lib_password.py" "${CONF_PASSWORD}" >/dev/null 2>&1 || true
	sudo systemctl restart smbd 2>/dev/null || true
fi

# fan: full speed from 60 °C (after the next reboot)
bash "${INSTALLER_DIR}/install-fan.sh" || true

# the display runs on until it is stopped: restart it with the new code (the home screen is drawn again at once)
if pgrep -f "${WEB_ROOT}/displa[y].py" >/dev/null; then
	echo "Restarting the display ..."
	sudo pkill -f "${WEB_ROOT}/displa[y].py" || true
	sleep 1
fi
# the home screen again: its message starts the display (lib_cron_ip --display sends nothing while the address is unchanged)
sudo python3 -c "import sys; sys.path.insert(0, '${WEB_ROOT}'); import lib_cron_ip; lib_cron_ip.ip_info().ready_screen()" >/dev/null 2>&1 || true
sleep 2

# boot screen with the logo, straight into the kiosk (only with a graphical environment; safe to run again)
bash "${INSTALLER_DIR}/install-boot-splash.sh" || echo "The boot screen could not be set up (see above)."

# kiosk screen like the app: page scaled to the screen, no pointer, no red scroll bar (only with a graphical environment)
bash "${INSTALLER_DIR}/install-kiosk-tweaks.sh" || echo "The kiosk screen tweaks could not be set up (see above)."

# restart the kiosk browser so it loads the touch UI
if systemctl is-active --quiet lightdm; then
	echo "Restarting the screen session ..."
	sudo systemctl restart lightdm
fi

echo
echo "Done. To roll back:"
echo "  sudo tar -xzf ${BACKUP_FILE} -C ${WEB_ROOT} && sudo chown -R www-data:www-data ${WEB_ROOT}"
echo

if [[ -x "${INSTALLER_DIR}/touch-selftest.sh" ]]; then
	sleep 5
	"${INSTALLER_DIR}/touch-selftest.sh" || true
fi
