#!/usr/bin/env python3

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

# Starts comitup-web (the page to connect the box to a Wi-Fi) on 127.0.0.1:8099 instead of port 80.
# Port 80 stays with the Backup Box in hotspot mode, so http://10.41.0.1 opens the box and not a page
# that only offers to connect to a network. The Wi-Fi page is reached through Apache at /comitup/.
# Started by the systemd drop-in /etc/systemd/system/comitup-web.service.d/lbb.conf (lib_comitup.py --hotspot_setup).

import glob
import os
import runpy
import sys

import flask

HOST	= '127.0.0.1'
PORT	= 8099

__run	= flask.Flask.run

def run(self, host=None, port=None, *args, **kwargs):
	return(__run(self, HOST, PORT, *args, **kwargs))

flask.Flask.run	= run

# comitup >= 1.40: python package comitup_web
try:
	from comitup_web import comitupweb
except ImportError:
	comitupweb	= None

if comitupweb is not None:
	sys.exit(comitupweb.main())

# older comitup packages: /usr/share/comitup/web/comitupweb.py, run from its folder
for Script in glob.glob('/usr/share/comitup/web/comitupweb.py') + glob.glob('/usr/share/comitup/*/comitupweb.py'):
	Folder	= os.path.dirname(Script)
	os.chdir(Folder)
	sys.path[:0]	= [Folder, os.path.dirname(Folder)]
	runpy.run_path(Script, run_name='__main__')
	sys.exit(0)

print('comitup-web not found', file=sys.stderr)
sys.exit(1)
