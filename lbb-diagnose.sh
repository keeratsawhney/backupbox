#!/usr/bin/env bash

# Collects everything needed to find out why backups or the web UI do not work.
# Usage (on the box):
#   ./lbb-diagnose.sh                 collect information only
#   ./lbb-diagnose.sh --test-backup   also run a short backup "any USB -> internal storage"
#                                     (plug in a USB stick or card with a few photos first)
# The report is printed and saved to ~/lbb-diagnose.txt. Passwords and tokens are removed.

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

WEB_ROOT="/var/www/little-backup-box"
REPORT="${HOME}/lbb-diagnose.txt"

exec > >(tee "${REPORT}") 2>&1

section() { echo; echo "===== $1 ====="; }

echo "Little Backup Box diagnose - $(date)"
echo "model: $(tr -d '\0' 2>/dev/null < /proc/device-tree/model)  os: $(. /etc/os-release && echo "${PRETTY_NAME}")  kernel: $(uname -r)  user: $(whoami)"

section "software"
cd "${WEB_ROOT}" 2>/dev/null && echo "web root files: $(ls | wc -l)" || echo "MISSING ${WEB_ROOT}"
for f in touch.php touch-api.php sub-touch-services.php backup.py lib_display.py lib_storage.py; do
	[[ -f "${WEB_ROOT}/${f}" ]] && echo "${f}: $(stat -c '%y %U' "${WEB_ROOT}/${f}" | cut -c1-19,36-)" || echo "${f}: MISSING"
done
grep -E "^const_SOFTWARE_BRANCH" "${WEB_ROOT}/constants.sh" 2>/dev/null
php -v 2>/dev/null | head -1
python3 -c 'import luma.oled, gpiozero; print("python display libs ok")' 2>&1 | tail -1

section "settings (passwords and tokens removed)"
sudo grep -E "^conf_(BACKUP|DISP|MENU|LANGUAGE|POWER_OFF|LOGLEVEL|THEME)" "${WEB_ROOT}/config.cfg" 2>/dev/null \
	| sed -E "s/^(conf_[A-Z_]*(PASSWORD|TOKEN)[A-Z_]*)=.*/\1=<removed>/"

section "drives"
lsblk -o NAME,SIZE,FSTYPE,LABEL,UUID,MOUNTPOINT,MODEL 2>&1
echo
grep -E " /media/" /proc/mounts || echo "nothing mounted under /media"
echo
grep -E "^dtparam=pciex1|^dtparam=spi|^dtoverlay" /boot/firmware/config.txt 2>/dev/null
sudo dmesg 2>/dev/null | grep -iE "nvme|pcie.*link" | tail -8

section "power (a weak power supply makes USB drives disconnect)"
throttled=$(vcgencmd get_throttled 2>/dev/null | cut -d= -f2)
if [[ -n "${throttled}" ]]; then
	echo "get_throttled: ${throttled}"
	(( throttled & 0x1 )) && echo "WARNING: under-voltage NOW"
	(( throttled & 0x10000 )) && echo "WARNING: under-voltage happened since boot"
	(( throttled & 0x4 )) && echo "WARNING: throttled NOW"
	echo "usb_max_current_enable: $(vcgencmd get_config usb_max_current_enable 2>/dev/null | cut -d= -f2)"
fi
echo "--- USB disconnects / resets / under-voltage in the kernel log"
sudo dmesg 2>/dev/null | grep -iE "under-?voltage|usb .*disconnect|reset (high|super)speed|i/o error|offline device" | tail -15

section "available partitions as Little Backup Box sees them"
sudo python3 "${WEB_ROOT}/lib_storage.py" --Action get_available_partitions --skipMounted False 2>&1 | tail -20

section "processes"
ps -eo pid,user,etimes,args | grep -E "[b]ackup\.py|[b]ackup-autorun|[d]isplay\.py|[d]isplaymenu" || echo "no backup or display process running"
for service in apache2 php8.4-fpm comitup smbd; do
	printf "%-12s %s\n" "${service}" "$(systemctl is-active "${service}" 2>/dev/null)"
done

section "touch UI api"
for action in status services recent; do
	echo "--- ${action}"
	curl -s -m 30 "http://localhost:8080/touch-api.php?action=${action}" | head -c 1500
	echo
done

section "little-backup-box.log (last 80 lines)"
sudo tail -n 80 "${WEB_ROOT}/tmp/little-backup-box.log" 2>&1

section "display messages"
sudo cat "${WEB_ROOT}/tmp/display-content-old.txt" 2>&1 | head -12

section "web server errors (last 40 lines)"
sudo tail -n 40 /var/log/apache2/lbb-error.log 2>&1
sudo journalctl -u "php*-fpm" --since "-2h" --no-pager 2>/dev/null | tail -15

if [[ "$1" == "--test-backup" ]]; then
	section "test backup: any USB -> internal storage (max. 3 minutes)"
	sudo "${WEB_ROOT}/stop_backup.sh" > /dev/null 2>&1
	sudo timeout 180 python3 "${WEB_ROOT}/backup.py" --SourceName anyusb --TargetName internal --generate-thumbnails True --power-off False 2>&1 | tail -60
	echo "exit code: ${PIPESTATUS[0]} (124 = stopped after 3 minutes)"
	echo
	echo "--- log after test"
	sudo tail -n 40 "${WEB_ROOT}/tmp/little-backup-box.log"
fi

echo
echo "Report saved to ${REPORT}"
