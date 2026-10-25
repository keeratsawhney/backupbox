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

# Thumbnails and the view database for the files of a backup, after the backup has reported done:
# the card can be removed at once, the previews follow at low priority.
#
# The files to do are listed on the drive itself (QUEUE_FILE below its mount point), so a power off only
# interrupts the work: the next backup to this drive does the rest.
# While working, tmp/background/<key>.json tells the web UI and the app how far it is (a pill in the top bar).
#
# usage: background.py <mount point>

from concurrent.futures import ThreadPoolExecutor
import fcntl
import hashlib
import json
import os
import subprocess
import sys
import time

QUEUE_FILE	= '.lbb-pending.txt'
CHUNK		= 24

def queue_add(MountPoint, Files):
	# Files: paths below the mount point
	if not Files:
		return()
	with open(os.path.join(MountPoint, QUEUE_FILE), 'a') as f:
		fcntl.flock(f, fcntl.LOCK_EX)
		f.write(''.join(f"{File.strip('/')}\n" for File in Files if '\n' not in File))
		f.flush()
		os.fsync(f.fileno())

def queue_read(MountPoint):
	try:
		with open(os.path.join(MountPoint, QUEUE_FILE), 'r') as f:
			fcntl.flock(f, fcntl.LOCK_SH)
			return(list(dict.fromkeys(Line.strip('\n') for Line in f if Line.strip())))
	except FileNotFoundError:
		return([])

def queue_remove(MountPoint, Done):
	QueueFile	= os.path.join(MountPoint, QUEUE_FILE)
	try:
		with open(QueueFile, 'r+') as f:
			fcntl.flock(f, fcntl.LOCK_EX)
			Lines	= [Line for Line in f.read().split('\n') if Line and Line not in Done]
			f.seek(0)
			f.truncate()
			if Lines:
				f.write('\n'.join(Lines) + '\n')
			else:
				os.remove(QueueFile)
			f.flush()
	except FileNotFoundError:
		pass

def start(MountPoint):
	# detached: keeps running when backup.py ends
	subprocess.Popen(['/usr/bin/python3', os.path.abspath(__file__), MountPoint], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True, close_fds=True)

def backup_running():
	return(subprocess.run(['pgrep', '-f', f'{os.path.dirname(os.path.abspath(__file__))}/backup.py'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0)

def run(MountPoint):
	import lib_log
	import lib_setup
	import lib_storage
	import lib_thumbnails
	import lib_view

	setup	= lib_setup.setup()

	StatusDir	= os.path.join(setup.get_val('const_WEB_ROOT_LBB'), 'tmp', 'background')
	os.makedirs(StatusDir, exist_ok=True)
	Key			= hashlib.md5(MountPoint.encode()).hexdigest()
	StatusFile	= os.path.join(StatusDir, f'{Key}.json')

	# one job per drive; a running one reads the list again before it ends
	Lock	= open(os.path.join(StatusDir, f'{Key}.lock'), 'w')
	try:
		fcntl.flock(Lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
	except OSError:
		return()

	# behind backups and the web interface; idle cores are still used in full
	os.nice(10)

	def status(Done, Total):
		try:
			with open(f'{StatusFile}.tmp', 'w') as f:
				json.dump({'done': Done, 'total': Total, 'drive': os.path.basename(MountPoint), 'pid': os.getpid(), 'time': int(time.time())}, f)
			os.replace(f'{StatusFile}.tmp', StatusFile)
		except OSError:
			pass

	Thumbnails	= lib_thumbnails.thumbnails(setup)
	db			= lib_view.viewdb(setup, lib_log.log(), MountPoint)
	Known		= set(tuple(Row) for Row in (db.dbSelect("select Directory, File_Name from EXIF_DATA") or []))

	def thumbnail(File):
		try:
			return(Thumbnails.generate(MountPoint, File))
		except Exception as e:
			print(f"Error: thumbnail {File}: {e}", file=sys.stderr)
			return([])

	Done	= 0
	try:
		with ThreadPoolExecutor(max_workers=Thumbnails.workers()) as Pool:
			while True:
				Files	= queue_read(MountPoint)
				if not Files:
					break
				Total	= Done + len(Files)
				status(Done, Total)

				for Index in range(0, len(Files), CHUNK):
					if not os.path.ismount(MountPoint):
						# drive removed: the list stays on the drive for the next backup
						return()

					Chunk	= Files[Index:Index + CHUNK]
					Existing	= [File for File in Chunk if os.path.isfile(os.path.join(MountPoint, File))]

					# thumbnails, several at once
					for Extra in Pool.map(thumbnail, [File for File in Existing if Thumbnails.missing(MountPoint, File)]):
						for File in Extra:
							thumbnail(File)

					# database: one exiftool run for the new files
					New	= [File for File in Existing if (os.path.dirname(File).strip('/'), os.path.basename(File)) not in Known]
					if New:
						db.dbInsertImages(New)
						Known.update((os.path.dirname(File).strip('/'), os.path.basename(File)) for File in New)

					queue_remove(MountPoint, set(Chunk))
					Done	+= len(Chunk)
					status(Done, Total)
	finally:
		Thumbnails.close()
		try:
			os.remove(StatusFile)
		except OSError:
			pass

	# files added by a backup that ended meanwhile (its own job found this one still running)
	Lock.close()
	if queue_read(MountPoint):
		return(run(MountPoint))

	subprocess.run(['sync'])

	# like at the end of a backup: the drive can be removed; not while a backup uses it
	if not backup_running():
		lib_storage.umount(setup, MountPoint)

if __name__ == "__main__":
	if len(sys.argv) != 2 or not os.path.isdir(sys.argv[1]):
		print(f'usage: {sys.argv[0]} <mount point>', file=sys.stderr)
		sys.exit(1)
	run(sys.argv[1].rstrip('/'))
