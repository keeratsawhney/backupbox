#!/usr/bin/env python

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

# Helpers of the tasks started from the Files tab (lib_copy.py, lib_delete.py): sizes, free space and the progress
# file the touch UI shows in its task window (bar, files, free space).

import json
import os
import shutil
import time

def human(Bytes):
	for Unit in ['B', 'KB', 'MB', 'GB']:
		if Bytes < 1000 or Unit == 'GB':
			return(f'{Bytes:.0f} {Unit}' if Unit == 'B' else f'{Bytes:.1f} {Unit}')
		Bytes	/= 1000

def inside(Path, Folder):
	Path, Folder	= os.path.realpath(Path), os.path.realpath(Folder)
	return(Path == Folder or Path.startswith(Folder.rstrip('/') + '/'))

def tree_size(Path):
	# (files, bytes) of a file or a folder with everything in it; links are not followed
	if os.path.isfile(Path) or os.path.islink(Path):
		try:
			return(1, os.lstat(Path).st_size)
		except OSError:
			return(1, 0)
	Files, Bytes	= 0, 0
	for Dir, SubDirs, FileNames in os.walk(Path):
		for FileName in FileNames:
			Files	+= 1
			try:
				Bytes	+= os.lstat(os.path.join(Dir, FileName)).st_size
			except OSError:
				pass
	return(Files, Bytes)

def space(Path):
	# (free, total) bytes of the file system of Path
	try:
		Usage	= shutil.disk_usage(Path)
		return(Usage.free, Usage.total)
	except OSError:
		return(0, 0)

class progress(object):
	# the progress file of the running task (read by touch-api.php: disk_task), written at most once a second
	#   kind: 'delete' | 'copy'; percent 0..100; item: what it works on; done / total: files (delete) or items (copy);
	#   change: bytes freed (delete) or copied (copy); free / size: bytes free and total on the drive

	def __init__(self, Path, Kind, SpacePath):
		self.__Path		= Path
		self.__Kind		= Kind
		self.__SpacePath	= SpacePath
		self.__Time		= 0

	def write(self, Percent, Item='', Done=0, Total=0, Change=0, force=False):
		if not self.__Path or (not force and time.time() - self.__Time < 1):
			return()
		self.__Time	= time.time()
		Free, Size	= space(self.__SpacePath)
		try:
			with open(f'{self.__Path}.tmp', 'w') as File:
				json.dump({'kind': self.__Kind, 'percent': max(0, min(100, round(Percent, 1))), 'item': Item, 'done': Done, 'total': Total, 'change': Change, 'free': Free, 'size': Size, 'time': int(time.time())}, File)
			os.replace(f'{self.__Path}.tmp', self.__Path)
		except OSError:
			pass
