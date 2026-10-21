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
source "${WORKING_DIR}/constants.sh"

ps -ef | grep "${WORKING_DIR}/backup.py" | grep -v grep | awk '{print $2}' | xargs -r kill
ps -ef | grep "${WORKING_DIR}/backup-autorun.py" | grep -v grep | awk '{print $2}' | xargs -r kill
python3 ${WORKING_DIR}/lib_system.py --set_led -LED PWR -LED_TRIGGER none -LED_BRIGHTNESS 1
python3 ${WORKING_DIR}/lib_clean.py --clean display_content tasks
rm -f "${WORKING_DIR}/tmp/backup-files.json" # names of the files being copied (web UI)

# drives opened (read only) in the file browser of the touch UI: free them for the backup
for BrowseMount in "${const_MEDIA_DIR}/browse/"*; do
	[ -d "${BrowseMount}" ] || continue
	umount "${BrowseMount}" 2>/dev/null
	rmdir "${BrowseMount}" 2>/dev/null
done
python3 ${WORKING_DIR}/lib_proftpd.py --Action setDefaultRoot --DefaultRoot "${const_MEDIA_DIR}"

python3 ${WORKING_DIR}/lib_display.py "$(python3 ${WORKING_DIR}/lib_language.py box_backup_break1)" "$(python3 ${WORKING_DIR}/lib_language.py box_backup_break2)"



