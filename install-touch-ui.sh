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

# Tiny File Manager (Files of the classic UI): do not show hidden files
# (macOS "._name" files next to every photo copied from a Mac, .Spotlight-V100, .Trashes, ...)
TFM_CONFIG="/var/www/tinyfilemanager/config.php"
if [[ -f "${TFM_CONFIG}" ]]; then
	sudo sed -i 's/"show_hidden":true/"show_hidden":false/' "${TFM_CONFIG}"
fi

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
