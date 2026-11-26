#!/usr/bin/env bash

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
#######################################################################

WORKING_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# run directly
python3 ${WORKING_DIR}/lib_cron_ip.py --display --mail

# Ethernet cable: Wi-Fi off (also if something switched it on again); no cable: Wi-Fi on (also if the unplug was missed)
if nmcli -t -f TYPE,STATE device 2>/dev/null | grep -q '^ethernet:connected'; then
	# not within 30 minutes of "Turn on Wi-Fi" in the Wi-Fi box of the touch UI
	if [ "$(nmcli radio wifi 2>/dev/null)" = "enabled" ] && [ -z "$(find "${WORKING_DIR}/tmp/wifi-keep-on" -mmin -30 2>/dev/null)" ]; then
		systemctl stop comitup 2>/dev/null
		nmcli radio wifi off
	fi
elif [ "$(nmcli radio wifi 2>/dev/null)" = "disabled" ]; then
	nmcli radio wifi on
	systemctl is-enabled --quiet comitup 2>/dev/null && systemctl start comitup
fi

# the backup drive stays mounted while the box is idle: network share and app show it
curl -s -m 30 -o /dev/null "http://127.0.0.1:8080/touch-api.php?action=automount"

# mail checks only when IP mails are configured (each check starts python)
grep -qi "^conf_MAIL_IP=['\"]\?\(true\|1\)\b" "${WORKING_DIR}/config.cfg" 2>/dev/null || exit 0

# run in seconds 15, 30 and 45
for n in {1..3}; do
	sleep 15
 	python3 ${WORKING_DIR}/lib_cron_ip.py --mail
done
