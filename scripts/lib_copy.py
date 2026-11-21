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

# Copy folders and files to another folder or drive (Files tab of the touch UI: select, "Copy to ...").
# Copy only: nothing is deleted anywhere, and a file that already exists in the destination with other content
# is never overwritten (its older version goes to _replaced/<date> next to it). Afterwards every file is
# looked up in the destination (lib_verify).
#
# usage (as root): lib_copy.py [--remount <mount point>] [--progress <file>] <destination folder> <source> [<source> ...]
#   --remount: the drive is mounted read only (opened in the Files tab): writable for the copy, read only again after it

import os
import re
import select
import subprocess
import sys
import time

import lib_task
import lib_verify

PROGRESS_SEC	= 2

def copy_one(Source, Destination, Number, Count, Stamp, Progress, Done, Needed):
	# Done / Needed: bytes of the items before this one / of all items, for the progress bar
	# returns the exit code of rsync
	Name	= os.path.basename(Source.rstrip('/'))
	Command	= ['/usr/bin/rsync', '-rt', '--no-owner', '--no-group', '--no-perms', f'--modify-window={lib_verify.MODIFY_WINDOW_SEC}',
		'--backup', f'--backup-dir=_replaced/{Stamp}', '--info=progress2', '--out-format=%n', '--mkpath']
	if os.path.isdir(Source):
		Command	+= [Source.rstrip('/') + '/', os.path.join(Destination, Name) + '/']
	else:
		Command	+= [Source, Destination.rstrip('/') + '/']

	Process		= subprocess.Popen(Command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL)
	Buffer		= b''
	Current		= ''
	ProgressText	= ''
	Shown		= 0
	while True:
		if not select.select([Process.stdout], [], [], 1)[0]:
			if Process.poll() is not None:
				break
		else:
			Chunk	= os.read(Process.stdout.fileno(), 65536)
			if not Chunk:
				break
			Buffer	+= Chunk
			# progress lines end with \r, file names and errors with \n
			Parts	= re.split(rb'[\r\n]', Buffer)
			Buffer	= Parts.pop()
			for Part in Parts:
				Line	= Part.decode(errors='replace').strip()
				if not Line:
					continue
				if re.match(r'^[\d.,]+[KMGT]?\s+\d+%', Line):
					ProgressText	= ' '.join(Line.split()[:3])
					try:
						Copied	= int(re.sub(r'[^0-9]', '', Line.split()[0]))
						Progress.write(100 * (Done + Copied) / max(1, Needed), Name, Number - 1, Count, Done + Copied)
					except (ValueError, IndexError):
						pass
				elif Line.startswith(('rsync:', 'rsync error', 'ERROR', 'WARNING')):
					print(Line, flush=True)
				elif not Line.endswith('/') and not Line.startswith(('sending', 'sent ', 'total size', 'created')):
					Current		= Line

		if time.time() - Shown >= PROGRESS_SEC and (Current or ProgressText):
			Shown	= time.time()
			print(f"[{Number}/{Count}] {Name}  ·  {ProgressText}  ·  {os.path.basename(Current)}", flush=True)

	return(Process.wait())

def verify_one(Source, Destination):
	# (all there, text)
	Name	= os.path.basename(Source.rstrip('/'))
	if os.path.isdir(Source):
		Result	= lib_verify.verify(Source, os.path.join(Destination, Name), Everything=True)
		Missing	= Result['missing_count'] + Result['different_count']
		if Missing:
			Listed	= ', '.join((Result['missing'] + Result['different'])[:8])
			return(False, f"{Name}: {Missing} of {Result['files']} files are NOT in the destination: {Listed}{' ...' if Missing > 8 else ''}")
		return(True, f"{Name}: {Result['ok']} files verified")

	Target	= os.path.join(Destination, Name)
	try:
		Same	= os.path.getsize(Target) == os.path.getsize(Source) and abs(os.path.getmtime(Target) - os.path.getmtime(Source)) <= lib_verify.MODIFY_WINDOW_SEC
	except OSError:
		Same	= False
	return(Same, f"{Name}: verified" if Same else f"{Name}: NOT in the destination")

def main(Arguments):
	Remount, ProgressFile	= None, None
	while Arguments and Arguments[0] in ['--remount', '--progress']:
		if Arguments[0] == '--remount':
			Remount	= Arguments[1]
		else:
			ProgressFile	= Arguments[1]
		Arguments	= Arguments[2:]
	if len(Arguments) < 2:
		print(__doc__.split('# usage')[-1].strip().split('\n')[0], file=sys.stderr)
		return(2)

	Destination	= os.path.realpath(Arguments[0])
	Sources		= [os.path.realpath(Source) for Source in Arguments[1:]]

	if not os.path.isdir(Destination):
		print(f'The destination is not a folder: {Destination}')
		return(2)
	for Source in Sources:
		if not os.path.exists(Source):
			print(f'Not found: {Source}')
			return(2)
		if os.path.isdir(Source) and lib_task.inside(Destination, Source):
			print(f'A folder can not be copied into itself: {os.path.basename(Source)}')
			return(2)

	Needed	= sum(lib_task.tree_size(Source)[1] for Source in Sources)
	Free	= lib_task.space(Destination)[0]
	Progress	= lib_task.progress(ProgressFile, 'copy', Destination)
	Progress.write(0, '', 0, len(Sources), 0, force=True)
	print(f'{len(Sources)} to copy, {lib_task.human(Needed)}; free in the destination: {lib_task.human(Free)}', flush=True)
	if Needed > Free - 64 * 1000 * 1000:
		print(f'NOT ENOUGH SPACE in the destination: {lib_task.human(Needed)} needed, {lib_task.human(Free)} free. Nothing was copied.')
		return(3)

	if Remount:
		for Options in ['remount,rw', 'remount,bind,rw']:
			subprocess.run(['mount', '-o', Options, Remount], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

	Code	= 0
	try:
		Stamp	= time.strftime('%Y-%m-%d_%H-%M-%S')
		Copied	= 0
		for Number, Source in enumerate(Sources, 1):
			Return	= copy_one(Source, Destination, Number, len(Sources), Stamp, Progress, Copied, Needed)
			Copied	+= lib_task.tree_size(Source)[1]
			if Return != 0:
				print(f'rsync ended with code {Return} for {os.path.basename(Source)}: some files could not be copied (see above).', flush=True)

		Progress.write(100, '', len(Sources), len(Sources), Copied, force=True)
		print('Checking the copy ...', flush=True)
		for Source in Sources:
			Complete, Text	= verify_one(Source, Destination)
			print(('OK  ' if Complete else 'FAILED  ') + Text, flush=True)
			if not Complete:
				Code	= 1
	finally:
		subprocess.run(['sync'])
		if Remount:
			# read only again, for this mount point only
			subprocess.run(['mount', '-o', 'remount,bind,ro', Remount], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

	print('Done. Everything is in the destination.' if Code == 0 else 'Some files are NOT in the destination: copy again (it copies what is missing).')
	return(Code)

if __name__ == "__main__":
	sys.exit(main(sys.argv[1:]))
