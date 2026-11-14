#!/usr/bin/env python

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


import datetime
import os
import re
import secrets
import shutil
import subprocess
import sys

import lib_comitup
import lib_language
import lib_log
import lib_setup

SHARE_PASSWORD_FILE	= '/var/lib/little-backup-box/share-password'

def share_password():
	# password of the network share while the box has no password of its own: made once, kept over updates,
	# readable by the web interface (shown on the System page)
	try:
		with open(SHARE_PASSWORD_FILE, 'r') as f:
			Password	= f.read().strip()
		if re.fullmatch(r'backup\d{4}', Password):
			return(Password)
	except OSError:
		pass

	# easy to read from the display and to type: backup and four digits, different on every box
	Password	= f'backup{secrets.randbelow(10000):04d}'
	os.makedirs(os.path.dirname(SHARE_PASSWORD_FILE), exist_ok=True)
	with open(SHARE_PASSWORD_FILE, 'w') as f:
		f.write(Password)
	try:
		shutil.chown(SHARE_PASSWORD_FILE, 'root', 'www-data')
	except (LookupError, OSError):
		pass
	os.chmod(SHARE_PASSWORD_FILE, 0o640)
	return(Password)

class password(object):

	def __init__(self):
		self.__setup	= lib_setup.setup()
		self.__lan		= lib_language.language()
		self.__log		= lib_log.log()

		self.user		= 'lbb'

	def set_password(self,Password=''):
		# prepare config files
		if not Password:
			#Apache
			open('/etc/apache2/includes/password.conf','w').close()

			#Samba: guests may connect, and the user lbb with the share password (Windows 10/11 refuse guest access)
			with open('/etc/samba/login.conf','w') as f:
				f.write('guest ok = yes')
			SharePassword	= share_password()
			subprocess.run(['/usr/bin/smbpasswd', '-a', '-s', self.user], input=f'{SharePassword}\n{SharePassword}\n'.encode(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

			#comitup
			lib_comitup.comitup().config(Password='')

			self.__log.message(self.__lan.l('config_password_removed'))

		else:
			#Apache
			with open('/etc/apache2/includes/password.conf','w') as f:
				f.write('Authtype Basic\n')
				f.write('Authname "Password Required"\n')
				f.write('AuthUserFile /etc/apache2/includes/htpasswd\n')
				f.write('<RequireAny>\n')
				f.write('    Require valid-user\n')
				f.write('    Require local\n')
				f.write('</RequireAny>\n')

			subprocess.run(f'/usr/bin/echo "{Password}" | /usr/bin/htpasswd -ci "/etc/apache2/includes/htpasswd" "{self.user}"',shell=True)

			# Linux
			subprocess.run(f'/usr/bin/echo "pi:{Password}" | /usr/sbin/chpasswd',shell=True)
			subprocess.run(f'/usr/bin/echo "{self.user}:{Password}" | /usr/sbin/chpasswd',shell=True)

			# samba
			subprocess.run(f'(/usr/bin/echo {Password}; /usr/bin/echo {Password}) | /usr/bin/smbpasswd -a -s "{self.user}"',shell=True)

			with open('/etc/samba/login.conf','w') as f:
				f.write(f'valid users = {self.user}\n')
				f.write('guest ok = no\n')

			# the share's login is the box's password now
			try:
				os.remove(SHARE_PASSWORD_FILE)
			except OSError:
				pass

			#comitup
			lib_comitup.comitup().config(Password=Password)

			self.__log.message(self.__lan.l('config_password_changed'))

		# restart samba
		subprocess.run('/usr/sbin/service smbd restart',shell=True)

if __name__ == "__main__":
	Password	= None
	if len(sys.argv) > 1:
		Password	= sys.argv[1]

	password().set_password(Password)
