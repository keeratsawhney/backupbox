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

# Thumbnails (tims/<file>.JPG next to every media file), used by backup.py and background.py

import os
import pathlib
import subprocess
import sys
import threading

import lib_common

class thumbnails(object):

	def __init__(self, setup, log=None):
		self.__setup	= setup
		self.__log		= log

		self.__WORKING_DIR	= os.path.dirname(__file__)

		self.const_FILE_EXTENSIONS_LIST_WEB_IMAGES	= setup.get_val('const_FILE_EXTENSIONS_LIST_WEB_IMAGES')
		self.const_FILE_EXTENSIONS_LIST_HEIC		= setup.get_val('const_FILE_EXTENSIONS_LIST_HEIC')
		self.const_FILE_EXTENSIONS_LIST_RAW			= setup.get_val('const_FILE_EXTENSIONS_LIST_RAW')
		self.const_FILE_EXTENSIONS_LIST_TIF			= setup.get_val('const_FILE_EXTENSIONS_LIST_TIF')
		self.const_FILE_EXTENSIONS_LIST_VIDEO		= setup.get_val('const_FILE_EXTENSIONS_LIST_VIDEO')
		self.const_FILE_EXTENSIONS_LIST_AUDIO		= setup.get_val('const_FILE_EXTENSIONS_LIST_AUDIO')
		self.const_FILE_EXTENSIONS_LIST_TEXT		= setup.get_val('const_FILE_EXTENSIONS_LIST_TEXT')

		if os.path.isfile('/usr/lib/libraw/dcraw_emu'):
			self.__DCRAW_EMU	= '/usr/lib/libraw/dcraw_emu'
		else:
			try:
				self.__DCRAW_EMU	= subprocess.check_output(['/usr/bin/whereis', 'dcraw_emu']).decode().strip().split('\n')[0].split(' ')[1]
			except:
				self.__DCRAW_EMU	= 'dcraw_emu'

		# one exiftool kept running per worker thread: starting exiftool takes longer than reading a file
		self.__exiftool_local	= threading.local()
		self.__exiftool_all		= []
		self.__exiftool_lock	= threading.Lock()

		# one thread per ImageMagick: the workers already use the cores, more threads only cost switching
		# and heat, and a core stays free for the web interface and the app
		os.environ['MAGICK_THREAD_LIMIT']	= '1'

	def workers(self):
		# several files at once (the Pi has 4 cores); fewer with little memory or a weak power supply,
		# full load draws more current and could make the drives disconnect
		try:
			MemTotal	= int(open('/proc/meminfo').readline().split()[1]) * 1024
		except:
			MemTotal	= 0
		if MemTotal and MemTotal < 1024 ** 3:
			return(1)
		try:
			Throttled	= int(subprocess.check_output(['/usr/bin/vcgencmd', 'get_throttled'], stderr=subprocess.DEVNULL).decode().split('=')[1], 16)
		except:
			Throttled	= 0
		# under-voltage now or since boot
		return(2 if Throttled & 0x10001 else min(3, os.cpu_count() or 1))

	def close(self):
		with self.__exiftool_lock:
			for Tool in self.__exiftool_all:
				Tool.close()
			self.__exiftool_all.clear()
		self.__exiftool_local	= threading.local()

	def __exiftool(self):
		Tool	= getattr(self.__exiftool_local, 'tool', None)
		if Tool is None:
			Tool	= lib_common.exiftool()
			self.__exiftool_local.tool	= Tool
			with self.__exiftool_lock:
				self.__exiftool_all.append(Tool)
		return(Tool)

	def __rawPreview(self, RawFile):
		# the full size JPEG every camera embeds in its raw files: far faster than developing the raw data
		for Tag in ('PreviewImage', 'JpgFromRaw'):
			Data	= self.__exiftool().run(['-b', f'-{Tag}', RawFile])
			if len(Data) > 20000 and Data[:2] == b'\xff\xd8':
				return(Data)
		return(None)

	def __rawOrientation(self, RawFile):
		# the embedded preview is stored unrotated: the raw file says how to turn it
		Orientation	= self.__exiftool().run(['-n', '-s3', '-Orientation', RawFile]).decode(errors='replace').strip()
		return({'3': '180', '6': '90', '8': '270'}.get(Orientation, '0'))

	def __run(self, Command, **kwargs):
		try:
			subprocess.run(Command, **kwargs)
		except:
			print(f"Error: {' '.join(Command)}",file=sys.stderr)

	def missing(self, MountPoint, File):
		# no (or an empty) thumbnail yet
		File	= File.strip('/')
		TIMS	= os.path.join(MountPoint, os.path.dirname(File), 'tims', f'{os.path.basename(File)}.JPG')
		return(not os.path.isfile(TIMS) or os.path.getsize(TIMS) == 0)

	def generate(self, MountPoint, SourceFilePathName):
		# thumbnail (tims) of one file below MountPoint; returns files to process as well (converted HEIC images)
		Extra	= []

		#extract Extension from filename
		SourceFilePathName	= SourceFilePathName.strip('/')
		try:
			SourceFilePathNameExt	= SourceFilePathName.rsplit('.',1)[1].lower()
		except:
			SourceFilePathNameExt	= ''

		Source	= os.path.join(MountPoint, SourceFilePathName)

		# generate thumbnails
		TIMS_Dir	= os.path.join(os.path.dirname(SourceFilePathName), 'tims')
		pathlib.Path(MountPoint, TIMS_Dir).mkdir(parents=True, exist_ok=True)

		FileName	= os.path.basename(SourceFilePathName)
		TIMS		= os.path.join(MountPoint, TIMS_Dir, f"{FileName}.JPG")

		# jpeg:size: JPEGs are decoded at a reduced size (1/2, 1/4 or 1/8, at least the thumbnail's size),
		# not with all their megapixels; -auto-orient turns the picture itself, -thumbnail drops the metadata
		if SourceFilePathNameExt in f"{self.const_FILE_EXTENSIONS_LIST_WEB_IMAGES};{self.const_FILE_EXTENSIONS_LIST_TIF}".split(';'):
			# file-types: jpeg, tif image
			self.__run(["/usr/bin/convert", "-define", "jpeg:size=800x800", f"{Source}[0]", "-auto-orient", "-thumbnail", "800x800>", "-quality", "85", TIMS])

		elif SourceFilePathNameExt in self.const_FILE_EXTENSIONS_LIST_HEIC.split(';'):
			# file-type: heic/heif
			# convert heif to jpg
			self.__run(['/usr/bin/heif-convert', Source, f'{Source}.JPG'])

			# transfer exif from heif to jpg
			self.__run(['/usr/bin/exiftool', '-overwrite_original', '-ignoreMinorErrors', '-TagsFromFile', Source, f'{Source}.JPG'])

			# create tims file
			self.__run(["/usr/bin/convert", f"{Source}.JPG", "-auto-orient", "-thumbnail", "800x800>", "-quality", "85", TIMS])

			if self.__setup.get_val('conf_VIEW_CONVERT_HEIC'):
				Extra.append(f"{SourceFilePathName}.JPG")
			else:
				self.__run(["/usr/bin/rm", f"{Source}.JPG"])

		elif SourceFilePathNameExt in self.const_FILE_EXTENSIONS_LIST_RAW.split(';'):
			# file-type: raw-image
			Preview	= self.__rawPreview(Source)
			if Preview:
				# the JPEG preview of the camera, turned like the photo
				self.__run(["/usr/bin/convert", "-define", "jpeg:size=800x800", "jpg:-", "-rotate", self.__rawOrientation(Source), "-thumbnail", "800x800>", "-quality", "85", TIMS], input=Preview)
			else:
				# no preview: develop the raw data (slow)
				SourceCommand	= [self.__DCRAW_EMU, "-w", "-Z", "-", Source]
				FilterCommand	= ["/usr/bin/convert", "-", "-resize", "800", TIMS]
				try:
					lib_common.pipe(SourceCommand,FilterCommand)
				except:
					print(f"Error: {' '.join(SourceCommand) + ' | ' + ' '.join(FilterCommand)}",file=sys.stderr)

		elif SourceFilePathNameExt in self.const_FILE_EXTENSIONS_LIST_VIDEO.split(';'):
			# file-type: video
			# -ss before -i: jumps to the second instead of decoding up to it; scaled by ffmpeg, not saved full size first
			for Second in ("00:00:01", "00:00:00"):
				# second 0 for videos shorter than a second
				self.__run(["/usr/bin/ffmpeg", "-loglevel", "error", "-y", "-skip_frame", "nokey", "-ss", Second, "-i", Source, "-frames:v", "1", "-vf", "scale='min(800,iw)':-2", TIMS], stdin=subprocess.DEVNULL)
				if os.path.isfile(TIMS) and os.path.getsize(TIMS) > 0:
					break

			self.__run(["/usr/bin/composite", "-gravity", "center", f"{self.__WORKING_DIR}/img/play.png", TIMS, TIMS])

		elif SourceFilePathNameExt in self.const_FILE_EXTENSIONS_LIST_AUDIO.split(';'):
			self.__run(["/usr/bin/cp", f"{self.__WORKING_DIR}/img/audio.JPG", TIMS])
			self.__run(["/usr/bin/convert", TIMS, "-gravity", "center", "-pointsize", "50", "-annotate", "0", FileName, TIMS])

		elif SourceFilePathNameExt in self.const_FILE_EXTENSIONS_LIST_TEXT.split(';'):
			self.__run(["/usr/bin/cp", f"{self.__WORKING_DIR}/img/text.JPG", TIMS])
			self.__run(["/usr/bin/convert", TIMS, "-gravity", "center", "-pointsize", "50", "-annotate", "0", FileName, TIMS])

		if not os.path.isfile(TIMS):
			if self.__log:
				self.__log.message(f"ERROR: TIMS of '{Source}' ('{TIMS}') not regular created.")

			self.__run(["/usr/bin/cp", f"{self.__WORKING_DIR}/img/unknown.JPG", TIMS])
			self.__run(["/usr/bin/convert", TIMS, "-gravity", "center", "-pointsize", "50", "-annotate", "0", FileName, TIMS])

		return(Extra)
