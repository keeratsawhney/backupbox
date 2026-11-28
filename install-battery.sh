#!/usr/bin/env bash

# Battery monitor with an INA231 (or INA226 / INA219) current / power module on the I2C bus: installs what it needs and the service that
# reads the module (scripts/lib_battery.py). Safe to run again. Without a module the service only looks now and then.
# I2C is switched on if it was not (a restart is then needed once). Settings: see lib_battery.py (data/battery.json).
#
# Test: LBB_ROOT=<folder> FORCE=1 ./install-battery.sh

set -euo pipefail

INSTALLER_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT="${LBB_ROOT:-}"
SUDO=""
[[ -z "${ROOT}" ]] && SUDO="sudo"

if [[ -z "${FORCE:-}" ]]; then
	# only on a Raspberry Pi with the web files installed
	[[ -d /var/www/little-backup-box ]] || exit 0
	command -v raspi-config >/dev/null 2>&1 || exit 0
	${SUDO} DEBIAN_FRONTEND=noninteractive apt-get install -y -q python3-smbus i2c-tools >/dev/null 2>&1 || echo "  (python3-smbus could not be installed)"
	if ! ls /dev/i2c-1 >/dev/null 2>&1; then
		${SUDO} raspi-config nonint do_i2c 0 || true
		echo "  I2C was switched on: restart the box once so that the battery module is found"
	fi
fi

${SUDO} mkdir -p "${ROOT}/etc/systemd/system"
${SUDO} cp -f "${INSTALLER_DIR}/etc/lbb-battery.service" "${ROOT}/etc/systemd/system/lbb-battery.service"
if [[ -z "${FORCE:-}" ]]; then
	${SUDO} systemctl daemon-reload
	${SUDO} systemctl enable lbb-battery.service >/dev/null 2>&1 || true
	${SUDO} systemctl restart lbb-battery.service || true
fi
echo "Battery monitor (INA231 / INA219) set up. It shows when the module is found on the I2C bus (0x40 .. 0x4F)."
