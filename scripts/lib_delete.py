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

# Delete folders and files from a drive (Files tab of the touch UI), as a task that shows its progress.
# Run as a task, not inside a web request: a folder of tens of thousands of files takes longer than a request may
# (it was cut off half way, and the rest of the folder showed up in the list again).
#
# - the list of previews still to do forgets the deleted files (background.py), and nothing makes a deleted folder again
# - the entries of the deleted files leave the photo database of the drive (the classic gallery)
# - after the deletion every path is looked at again; what is still there is deleted once more and reported
# - the free space before and after shows what was deleted
#
# usage (as root): lib_delete.py --root <mount point of the drive> [--remount] [--progress <file>] [--db <database file name>]
#                                <path> [<path> ...]
#   --remount: the drive is mounted read only (opened in the Files tab): writable for the deletion, read only after it

import os
import sqlite3
import subprocess
import sys
import time

import background
import lib_task

def delete_tree(Top, Errors, Count):
	# Count: dict files, bytes, listener (called after every file)
	if os.path.isfile(Top) or os.path.islink(Top):
		Size	= 0
		try:
			Size	= os.lstat(Top).st_size
			os.unlink(Top)
			Count['files']	+= 1
			Count['bytes']	+= Size
			Count['listener']()
		except FileNotFoundError:
			pass
		except OSError as e:
			Errors.append((Top, e.strerror))
		return()

	for Dir, SubDirs, FileNames in os.walk(Top, topdown=False, followlinks=False):
		for FileName in FileNames:
			Path	= os.path.join(Dir, FileName)
			try:
				Size	= os.lstat(Path).st_size
				os.unlink(Path)
				Count['files']	+= 1
				Count['bytes']	+= Size
				Count['listener']()
			except FileNotFoundError:
				pass
			except OSError as e:
				Errors.append((Path, e.strerror))
		for SubDir in SubDirs:
			Path	= os.path.join(Dir, SubDir)
			try:
				if os.path.islink(Path):
					os.unlink(Path)
				else:
					os.rmdir(Path)
			except FileNotFoundError:
				pass
			except OSError:
				# not empty: the files in it are reported
				pass
	try:
		os.rmdir(Top)
	except FileNotFoundError:
		pass
	except OSError as e:
		Errors.append((Top, e.strerror))

def forget_in_database(Root, Database, Relatives, Files):
	# the entries of what was deleted leave the view database of the drive (paths relative to the drive)
	DatabaseFile	= os.path.join(Root, Database)
	if not Database or not os.path.isfile(DatabaseFile):
		return()
	try:
		Connection	= sqlite3.connect(DatabaseFile)
		for Relative, IsFile in zip(Relatives, Files):
			Folder, Name	= os.path.split(Relative)
			if IsFile:
				Connection.execute('DELETE FROM EXIF_DATA WHERE Directory = ? AND File_Name = ?', (Folder, Name))
			else:
				Escaped	= Relative.replace('\\', '\\\\').replace('%', '\\%').replace('_', '\\_')
				Connection.execute("DELETE FROM EXIF_DATA WHERE Directory = ? OR Directory LIKE ? ESCAPE '\\'", (Relative, Escaped + '/%'))
		Connection.commit()
		Connection.close()
	except sqlite3.Error:
		pass

def main(Arguments):
	Root, Remount, ProgressFile, Database	= None, False, None, 'images.db'
	Paths	= []
	while Arguments:
		Argument	= Arguments.pop(0)
		if Argument == '--root':
			Root	= os.path.realpath(Arguments.pop(0))
		elif Argument == '--remount':
			Remount	= True
		elif Argument == '--progress':
			ProgressFile	= Arguments.pop(0)
		elif Argument == '--db':
			Database	= Arguments.pop(0)
		else:
			Paths.append(Argument)

	if not Root or not Paths:
		print('usage: lib_delete.py --root <mount point> [--remount] [--progress <file>] [--db <name>] <path> [<path> ...]', file=sys.stderr)
		return(2)

	# only below a mount point under /media, never the drive itself
	if not Root.startswith('/media/'):
		print(f'Not a drive of the box: {Root}')
		return(2)
	Targets	= []
	for Path in Paths:
		Real	= os.path.join(os.path.realpath(os.path.dirname(Path)), os.path.basename(Path.rstrip('/')))
		if Real == Root or not Real.startswith(Root.rstrip('/') + '/'):
			print(f'Not deleted (outside the drive): {Path}')
			return(2)
		Targets.append(Real)

	Progress	= lib_task.progress(ProgressFile, 'delete', Root)

	print('Counting ...', flush=True)
	Totals	= [lib_task.tree_size(Target) if os.path.lexists(Target) else (0, 0) for Target in Targets]
	TotalFiles	= sum(Files for Files, Bytes in Totals)
	TotalBytes	= sum(Bytes for Files, Bytes in Totals)
	FreeBefore, Size	= lib_task.space(Root)
	print(f'{len(Targets)} to delete: {TotalFiles} files, {lib_task.human(TotalBytes)}.', flush=True)
	print(f'Free space now: {lib_task.human(FreeBefore)} of {lib_task.human(Size)}', flush=True)
	Progress.write(0, '', 0, TotalFiles, 0, force=True)

	if Remount:
		for Options in ['remount,rw', 'remount,bind,rw']:
			subprocess.run(['mount', '-o', Options, Root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

	Relatives	= [os.path.relpath(Target, Root) for Target in Targets]
	IsFile		= [not os.path.isdir(Target) or os.path.islink(Target) for Target in Targets]
	Errors		= []
	Count		= {'files': 0, 'bytes': 0, 'listener': lambda: None}
	Shown		= [0]
	Current		= ['']

	def report():
		Progress.write(100 * Count['files'] / max(1, TotalFiles), Current[0], Count['files'], TotalFiles, Count['bytes'])
		if time.time() - Shown[0] >= 2:
			Shown[0]	= time.time()
			print(f"{Current[0]}  ·  {Count['files']} of {TotalFiles} files  ·  {lib_task.human(Count['bytes'])} deleted", flush=True)
	Count['listener']	= report

	try:
		# nothing is made for them any more (previews of the files that are still to do)
		background.queue_prune(Root, Relatives)

		for Number, Target in enumerate(Targets, 1):
			Current[0]	= f'[{Number}/{len(Targets)}] {os.path.basename(Target)}'
			Errors	= []
			delete_tree(Target, Errors, Count)
			# the thumbnail of a deleted file
			if IsFile[Number - 1]:
				Thumbnail	= os.path.join(os.path.dirname(Target), 'tims', os.path.basename(Target) + '.JPG')
				try:
					os.unlink(Thumbnail)
				except OSError:
					pass

		# looked at again: a writer that was just busy in the folder (or a slow drive) can leave something behind
		for Round in range(2):
			Left	= [Target for Target in Targets if os.path.lexists(Target)]
			if not Left:
				break
			print(f'Still there: {", ".join(os.path.basename(Target) for Target in Left)}: deleting again ...', flush=True)
			time.sleep(2)
			Errors	= []
			for Target in Left:
				Current[0]	= os.path.basename(Target)
				delete_tree(Target, Errors, Count)

		forget_in_database(Root, Database, [Relative for Relative, Target in zip(Relatives, Targets) if not os.path.lexists(Target)], [File for File, Target in zip(IsFile, Targets) if not os.path.lexists(Target)])
		# the work of the last round for a late writer (previews started before the deletion)
		time.sleep(1)
		Left	= [Target for Target in Targets if os.path.lexists(Target)]
	finally:
		subprocess.run(['sync'])
		if Remount:
			# read only again, for this mount point only
			subprocess.run(['mount', '-o', 'remount,bind,ro', Root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

	for Target in Targets:
		print(('NOT deleted: ' if os.path.lexists(Target) else 'Deleted: ') + os.path.basename(Target))
	FreeAfter, Size	= lib_task.space(Root)
	print(f'Free space: {lib_task.human(FreeBefore)}  ->  {lib_task.human(FreeAfter)}  ({"+" if FreeAfter >= FreeBefore else "-"}{lib_task.human(abs(FreeAfter - FreeBefore))})', flush=True)
	Progress.write(100 if not Left else 100 * Count['files'] / max(1, TotalFiles), '', Count['files'], TotalFiles, Count['bytes'], force=True)

	if Left:
		for Path, Reason in Errors[:10]:
			print(f'  {os.path.relpath(Path, Root)}: {Reason}')
		print('Some of it could NOT be deleted (see above). Is the drive full of errors or read only? Try again.')
		return(1)
	print('Done. Everything is deleted.')
	return(0)

if __name__ == "__main__":
	sys.exit(main(sys.argv[1:]))
