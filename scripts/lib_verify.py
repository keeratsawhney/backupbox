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

# Proof that a card is on the backup drive: every file of the card is looked up on the backup drive, by name,
# size and date. Independent of rsync, which only reports what it decided to copy.
#
# Used by backup.py after every backup from a card to a local drive (the result is part of the summary on the
# display), and by hand:
#
#   sudo python3 lib_verify.py [<card mount point> <folder of the card on the backup drive>]
#
# Without arguments the card and the backup drive mounted by the box are used (/media/usb_source or nvme_source
# against usb_target or nvme_target), or an attached card that is not mounted is mounted read-only for the check.

import fnmatch
import json
import os
import re
import subprocess
import sys
import time

# not copied on purpose (backup.py): the box's own ID files and database, empty files (left by a connection
# that dropped), and the trash folders of Windows, macOS and Linux
NEVER_COPIED_NAMES		= ['$RECYCLE.BIN', 'System Volume Information', '.Trashes', '.Trash-*']
NEVER_COPIED_FILES		= ['*.id', '*.lbbid']

# FAT keeps the local time, which shifts by one hour when the clocks change: the same photo, one hour apart,
# is the same photo. A new photo that reuses a name differs by far more (days).
MODIFY_WINDOW_SEC		= 3601

MAX_LISTED				= 1000

def never_copied_name(Name):
	return(any(fnmatch.fnmatchcase(Name, Pattern) for Pattern in NEVER_COPIED_NAMES))

def never_copied_file(Name, DatabaseName=''):
	return(any(fnmatch.fnmatchcase(Name, Pattern) for Pattern in NEVER_COPIED_FILES) or (DatabaseName != '' and Name == DatabaseName))

def verify(SourceRoot, TargetRoot, DatabaseName='images.db'):
	# returns a dict: files (looked at), ok, missing, different (lists of relative paths, at most MAX_LISTED),
	# empty (not copied on purpose), unreadable (could not be looked at), counts in <name>_count
	Result	= {
		'time':			int(time.time()),
		'source':		SourceRoot,
		'target':		TargetRoot,
		'files':		0,
		'ok':			0,
		'missing':		[],
		'different':	[],
		'empty':		[],
		'unreadable':	[],
		'missing_count':	0,
		'different_count':	0,
		'empty_count':	0,
		'unreadable_count':	0,
	}

	def note(Kind, Path):
		Result[f'{Kind}_count']	+= 1
		if len(Result[Kind]) < MAX_LISTED:
			Result[Kind].append(Path)

	for Dir, SubDirs, FileNames in os.walk(SourceRoot, onerror=lambda e: note('unreadable', str(e.filename))):
		SubDirs[:]	= sorted(SubDir for SubDir in SubDirs if not never_copied_name(SubDir))
		RelDir		= os.path.relpath(Dir, SourceRoot)
		RelDir		= '' if RelDir == '.' else RelDir

		for FileName in sorted(FileNames):
			if never_copied_file(FileName, DatabaseName) or never_copied_name(FileName):
				continue

			Relative	= os.path.join(RelDir, FileName)
			Result['files']	+= 1

			try:
				SourceStat	= os.stat(os.path.join(Dir, FileName))
			except OSError:
				note('unreadable', Relative)
				continue

			if SourceStat.st_size == 0:
				note('empty', Relative)
				continue

			try:
				TargetStat	= os.stat(os.path.join(TargetRoot, Relative))
			except OSError:
				note('missing', Relative)
				continue

			if TargetStat.st_size != SourceStat.st_size or abs(TargetStat.st_mtime - SourceStat.st_mtime) > MODIFY_WINDOW_SEC:
				note('different', Relative)
			else:
				Result['ok']	+= 1

	return(Result)

def save(Result, Path):
	try:
		os.makedirs(os.path.dirname(Path), exist_ok=True)
		with open(f'{Path}.tmp', 'w') as File:
			json.dump(Result, File)
		os.replace(f'{Path}.tmp', Path)
	except OSError:
		pass

# ---------- by hand ----------

def mounted(Path):
	try:
		with open('/proc/mounts') as File:
			return(f' {Path} ' in File.read())
	except OSError:
		return(False)

def find_card_and_backup(MediaDir='/media'):
	Card		= next((Path for Path in [f'{MediaDir}/usb_source', f'{MediaDir}/nvme_source'] if mounted(Path)), None)
	Backup		= next((Path for Path in [f'{MediaDir}/nvme_target', f'{MediaDir}/usb_target'] if mounted(Path)), None)
	return(Card, Backup)

def mount_card_readonly():
	# an attached card that is not mounted: mounted read-only for the check, where it is easy to find
	try:
		Devices	= json.loads(subprocess.check_output(['lsblk', '-J', '-o', 'PATH,TYPE,TRAN,FSTYPE,MOUNTPOINT,RM'], stderr=subprocess.DEVNULL).decode())['blockdevices']
	except Exception:
		return(None)

	for Disk in Devices:
		if not (Disk.get('tran') == 'usb' or str(Disk.get('path', '')).startswith('/dev/sd')) or Disk.get('type') != 'disk':
			continue
		for Part in Disk.get('children') or [Disk]:
			if Part.get('fstype') in ['exfat', 'vfat', 'ntfs'] and not Part.get('mountpoint'):
				Path	= '/mnt/lbb-verify'
				os.makedirs(Path, exist_ok=True)
				if subprocess.run(['mount', '-o', 'ro', Part['path'], Path], stderr=subprocess.DEVNULL).returncode == 0:
					return(Path)
	return(None)

def card_folders(Card, Backup):
	# the folders the box made for this card: named by the ID files (.lbbid / .id) in the card's top folder
	Folders	= []
	for Name in sorted(os.listdir(Card)):
		Match	= re.match(r'^(.+)\.(lbbid|id)$', Name)
		if Match and os.path.isdir(os.path.join(Backup, Match.group(1))):
			Folders.append(os.path.join(Backup, Match.group(1)))
	return(Folders)

def print_result(Result):
	print(f"\n{Result['source']}  ->  {Result['target']}")
	print(f"  files on the card:       {Result['files']}")
	print(f"  on the backup, complete: {Result['ok']}")
	print(f"  NOT on the backup:       {Result['missing_count']}")
	print(f"  different (size/date):   {Result['different_count']}")
	print(f"  empty, not copied:       {Result['empty_count']}")
	if Result['unreadable_count']:
		print(f"  could not be read:       {Result['unreadable_count']}")
	for Title, Kind in [('NOT on the backup', 'missing'), ('different', 'different'), ('could not be read', 'unreadable'), ('empty (not copied on purpose)', 'empty')]:
		if Result[Kind]:
			print(f"\n  {Title}:")
			for Path in Result[Kind][:40]:
				print(f"    {Path}")
			if Result[f'{Kind}_count'] > 40:
				print(f"    ... and {Result[f'{Kind}_count'] - 40} more")

if __name__ == "__main__":
	Card, Backup	= None, None
	Mounted			= None

	if len(sys.argv) == 3:
		Card, Folders	= sys.argv[1], [sys.argv[2]]
	else:
		Card, Backup	= find_card_and_backup()
		if Card is None:
			Mounted	= mount_card_readonly()
			Card	= Mounted
		if Card is None or Backup is None:
			print('Plug in the card and the backup drive is needed: no card or no backup drive found.\nGive them by hand: lib_verify.py <card> <folder of the card on the backup drive>')
			sys.exit(2)
		Folders	= card_folders(Card, Backup)
		if not Folders:
			print(f'No folder of this card found on {Backup} (the card has no .lbbid file that matches a folder there).')
			print('Name it by hand: lib_verify.py <card> <folder>')
			if Mounted:
				subprocess.run(['umount', Mounted])
			sys.exit(1)

	# a card can have several folders (an old shared one and its own): one complete folder is enough
	CompleteIn	= None
	for Folder in Folders:
		Result	= verify(Card, Folder)
		print_result(Result)
		if CompleteIn is None and Result['missing_count'] == 0 and Result['different_count'] == 0:
			CompleteIn	= Folder

	if Mounted:
		subprocess.run(['umount', Mounted])

	print(f'\nEvery file of the card is on the backup drive, in {CompleteIn}.' if CompleteIn else '\nFiles are MISSING on the backup drive: back up this card again (it copies what is missing).')
	sys.exit(0 if CompleteIn else 1)
