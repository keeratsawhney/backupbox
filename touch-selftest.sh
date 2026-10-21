#!/usr/bin/env bash

# Checks the touchscreen UI on a running box and writes a report.
# Usage (on the box): ./touch-selftest.sh
# The report is printed and saved to ~/touch-selftest.txt - paste it into a bug report.

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

BASE="http://localhost:8080"
REPORT="${HOME}/touch-selftest.txt"
FAILED=0

exec > >(tee "${REPORT}") 2>&1

pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILED=$((FAILED + 1)); }
info() { echo "INFO  $1"; }

echo "Little Backup Box touch UI self test - $(date)"
info "model: $(tr -d '\0' 2>/dev/null < /proc/device-tree/model)"
info "os: $(. /etc/os-release && echo "${PRETTY_NAME}")"
info "php: $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
info "firefox: $(firefox-esr --version 2>/dev/null || echo 'not installed')"
echo

# pages
code=$(curl -s -o /tmp/lbb-touch.html -w '%{http_code}' "${BASE}/touch.php")
if [[ "${code}" == "200" ]] && grep -q "LBB_TOUCH" /tmp/lbb-touch.html && ! grep -qE "Fatal error|Warning:" /tmp/lbb-touch.html; then
	pass "touch.php loads"
else
	fail "touch.php (HTTP ${code}): $(grep -oE '(Fatal error|Warning:).{0,200}' /tmp/lbb-touch.html | head -2)"
fi

redirect=$(curl -s -o /dev/null -w '%{redirect_url}' "${BASE}/")
[[ "${redirect}" == *"/touch.php" ]] && pass "screen start page redirects to touch.php" || fail "start page does not redirect (got '${redirect}')"

code=$(curl -s -o /tmp/lbb-index.html -w '%{http_code}' "${BASE}/index.php")
[[ "${code}" == "200" ]] && ! grep -q "Fatal error" /tmp/lbb-index.html && pass "classic index.php loads" || fail "classic index.php (HTTP ${code})"

# api
for action in status services recent log sysinfo; do
	result=$(curl -s -w '\n%{time_total}' "${BASE}/touch-api.php?action=${action}")
	seconds=$(echo "${result}" | tail -1)
	json=$(echo "${result}" | sed '$d')
	summary=$(echo "${json}" | python3 -c '
import json, sys
d = json.load(sys.stdin)
if not d.get("ok"): print("ERROR " + str(d.get("error"))); sys.exit()
a = sys.argv[1]
if a == "status": print("running=%s progress=%s storages=%s temp=%s" % (d["running"], d["display"]["progress"], [s["id"] for s in d["storages"]], d["temp"]))
elif a == "services": print("sources=%s targets=%s" % ([s["id"] for s in d["sources"]], [s["id"] for s in d["targets"]]))
elif a == "recent": print("medium=%s photos=%s database=%s" % (d["medium"], d["total"], d["has_db"]))
elif a == "log": print("%d lines" % len(d["lines"]))
elif a == "sysinfo": print("ips=%s uptime=%ss" % (d["ips"], d["uptime"]))
' "${action}" 2>&1)
	if [[ "${summary}" == ERROR* || "${summary}" == *Traceback* || "${summary}" == *Error* ]]; then
		fail "api ${action}: ${summary} $(echo "${json}" | head -c 300)"
	else
		pass "api ${action} (${seconds}s): ${summary}"
	fi
done

code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d action=stop "${BASE}/touch-api.php")
[[ "${code}" == "403" ]] && pass "api rejects requests without the touch header" || fail "api accepted a request without header (HTTP ${code})"

# permissions the backend needs
sudo -u www-data sudo -n true 2>/dev/null && pass "www-data may use sudo" || fail "www-data can not use sudo (backups can not start)"
sudo -u www-data test -w /var/www/little-backup-box/tmp && pass "tmp is writable for www-data" || fail "/var/www/little-backup-box/tmp not writable for www-data"

# screen and touch
echo
for status in /sys/class/drm/card*-*/status; do
	[[ -e "${status}" && "$(cat "${status}")" == "connected" ]] || continue
	connector=$(dirname "${status}")
	info "screen $(basename "${connector}"): $(head -1 "${connector}/modes" 2>/dev/null)"
done
touch_devices=$(grep -iE '^N: Name=.*(touch|ads7846|ft5406|goodix)' /proc/bus/input/devices | sed 's/N: Name=//')
[[ -n "${touch_devices}" ]] && pass "touch device: ${touch_devices//$'\n'/, }" || info "no touch input device found"

autostart="/home/lbb-desktop/.config/labwc/autostart"
if sudo test -f "${autostart}"; then
	info "kiosk url: $(sudo grep -oE '^URL=.*|https?://localhost[^ ]*' "${autostart}" | head -1)"
	pgrep -f "firefox-esr.*--kiosk" > /dev/null && pass "kiosk browser is running" || fail "kiosk browser is not running"
else
	info "no kiosk autostart (graphical environment not installed)"
fi

echo
[[ ${FAILED} -eq 0 ]] && echo "ALL CHECKS PASSED" || echo "${FAILED} CHECK(S) FAILED"
echo "Report saved to ${REPORT}"
exit ${FAILED}
