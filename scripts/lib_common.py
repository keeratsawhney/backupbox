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

import os
import select
import subprocess
import time

def pipe(SourceCommand, FilterCommand):
	if SourceCommand and FilterCommand:
		SourceProcess	= subprocess.Popen(SourceCommand, stdout=subprocess.PIPE)
		result	= subprocess.check_output(FilterCommand,stdin=SourceProcess.stdout)
		SourceProcess.wait()
		return(result)
	else:
		return()

def join_mail_threads(display, lan, threads, timeout):
	waiting_for_outgoing_mails	= False
	TimeLimit	= time.time() + timeout

	for thread in threads:
		if thread is None:
			continue

		while thread.is_alive() and time.time() <= TimeLimit:

			if not waiting_for_outgoing_mails:
				waiting_for_outgoing_mails	= True
				display.message([
					'set:time=2,temp',
					f":{lan.l('box_poweroff_waiting_outgoing_mails1')}",
					f":{lan.l('box_poweroff_waiting_outgoing_mails2')}"
				])

			thread.join(timeout=TimeLimit-time.time())

	if waiting_for_outgoing_mails:
		display.message([
			'set:time=2,temp',
			f":{lan.l('box_poweroff_finished_outgoing_mails1')}",
			f":{lan.l('box_poweroff_finished_outgoing_mails2')}"
		])

class exiftool(object):
	# exiftool started once and kept running (-stay_open): each run() costs only the reading of the file,
	# not the start of perl and exiftool (most of the time of a single exiftool call on a Pi)

	def __init__(self):
		self.__process	= None

	def __start(self):
		self.__process	= subprocess.Popen(['/usr/bin/exiftool', '-stay_open', 'True', '-@', '-'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)

	def run(self, Args, timeout=60):
		# Args: exiftool arguments, one per list item (file names may contain spaces); returns stdout
		if any('\n' in Arg for Arg in Args):
			return(b'')
		try:
			if self.__process is None or self.__process.poll() is not None:
				self.__start()
			self.__process.stdin.write(('\n'.join(Args) + '\n-execute\n').encode())
			self.__process.stdin.flush()

			Output	= b''
			Fd		= self.__process.stdout.fileno()
			Until	= time.time() + timeout
			while not Output.endswith(b'{ready}\n'):
				if not select.select([Fd], [], [], max(0, Until - time.time()))[0]:
					raise TimeoutError()
				Chunk	= os.read(Fd, 1 << 16)
				if not Chunk:
					raise EOFError()
				Output	+= Chunk
			return(Output[:-len(b'{ready}\n')])
		except Exception:
			self.close()
			return(b'')

	def close(self):
		if self.__process is None:
			return()
		try:
			if self.__process.poll() is None:
				self.__process.stdin.write(b'-stay_open\nFalse\n')
				self.__process.stdin.flush()
				self.__process.wait(timeout=5)
		except Exception:
			self.__process.kill()
		self.__process	= None
