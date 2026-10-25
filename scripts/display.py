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

# Display messages are read from const_DISPLAY_CONTENT_PATH
#
# format:
# FORMAT-OPTIONS:TEXT
#
# multiple format options can be separated by "," (without spaces)
#
# for standard-format just use ":"
#
## s: style
# s=b: BASIC
# s=h: HIGHLIGHT
# s=a: ALERT
# s=s: statusbar
#
## u: underline
#
## PGBAR
# s=b:PGBAR=20
#
## IMAGE
# IMAGE=PATH
#
#
# To set global parameters you can use a 'set:'-line:
# multiple options can be separated by "," (without spaces)
# set:clear,time=1.5,temp
# clear:	Do not display lines from old page
# time=2:	Display time in seconds (empty for standard config)
# temp:		show previous screen again after this
# hidden:	markertext to write into const_DISPLAY_CONTENT_OLD_FILE
# kill:		terminate display daemon

import json
import os
import RPi.GPIO as GPIO
import pathlib
import re
import shutil
import signal
import subprocess
import sys
import threading
import time

import lib_comitup
import lib_language
import lib_network
import lib_setup
import lib_system

from luma.core.interface.serial import i2c, spi, pcf8574
from luma.core.interface.parallel import bitbang_6800
from luma.core.render import canvas
from luma.oled.device import ssd1306, ssd1309, ssd1322, ssd1331, sh1106
from luma.lcd.device import st7735

from PIL import Image, ImageDraw, ImageFont

import displaymenu
import lib_menu_render
from lib_display import display_content_files

# import lib_debug
# xx	= lib_debug.debug()

WORKING_DIR = os.path.dirname(__file__)

class DISPLAY(object):

	def __init__(self):
		# Register the shutdown/reboot signals
		signal.signal(signal.SIGTERM, self.terminate)  # Signal for shutdown
		signal.signal(signal.SIGINT, self.terminate)   # Interrupt signal

		self.loop_continue	= True

		# cleanup pins
		GPIO.cleanup()

		# objects
		self.__setup							= lib_setup.setup()
		self.__display_content_files			= display_content_files(self.__setup)

		# setup
		self.__conf_DISP_CONNECTION					= self.__setup.get_val('conf_DISP_CONNECTION')
		self.__conf_DISP_DRIVER						= self.__setup.get_val('conf_DISP_DRIVER')
		self.__conf_DISP_I2C_ADDRESS				= self.__setup.get_val('conf_DISP_I2C_ADDRESS')
		self.__conf_DISP_SPI_PORT					= self.__setup.get_val('conf_DISP_SPI_PORT')
		self.__conf_DISP_RESOLUTION_X				= self.__setup.get_val('conf_DISP_RESOLUTION_X')
		self.__conf_DISP_RESOLUTION_Y				= self.__setup.get_val('conf_DISP_RESOLUTION_Y')
		self.__conf_DISP_OFFSET_X					= self.__setup.get_val('conf_DISP_OFFSET_X')
		self.__conf_DISP_OFFSET_Y					= self.__setup.get_val('conf_DISP_OFFSET_Y')
		self.__conf_DISP_ROTATE						= self.__setup.get_val('conf_DISP_ROTATE')
		self.__conf_DISP_CONTRAST					= self.__setup.get_val('conf_DISP_CONTRAST')
		self.__conf_DISP_COLOR_BGR					= self.__setup.get_val('conf_DISP_COLOR_BGR')
		self.__conf_DISP_COLOR_INVERSE				= self.__setup.get_val('conf_DISP_COLOR_INVERSE')
		self.__conf_DISP_COLOR_MODEL				= self.__setup.get_val('conf_DISP_COLOR_MODEL')
		self.__conf_DISP_COLOR_TEXT					= self.__setup.get_val('conf_DISP_COLOR_TEXT')
		self.__conf_DISP_COLOR_HIGH					= self.__setup.get_val('conf_DISP_COLOR_HIGH')
		self.__conf_DISP_COLOR_ALERT				= self.__setup.get_val('conf_DISP_COLOR_ALERT')
		self.__conf_DISP_COLOR_BACKGROUND			= self.__setup.get_val('conf_DISP_COLOR_BACKGROUND')
		self.__conf_DISP_FONT_SIZE					= self.__setup.get_val('conf_DISP_FONT_SIZE')
		self.__conf_DISP_FRAME_TIME					= self.__setup.get_val('conf_DISP_FRAME_TIME')
		self.__conf_DISP_SLEEP_MIN					= self.__setup.get_val('conf_DISP_SLEEP_MIN')
		self.__conf_DISP_SHOW_STATUSBAR				= self.__setup.get_val('conf_DISP_SHOW_STATUSBAR')
		self.__conf_DISP_BACKLIGHT_PIN				= self.__setup.get_val('conf_DISP_BACKLIGHT_PIN')
		self.__conf_DISP_BACKLIGHT_ENABLED			= self.__setup.get_val('conf_DISP_BACKLIGHT_ENABLED')
		self.__conf_MENU_ENABLED					= self.__setup.get_val('conf_MENU_ENABLED')
		self.__conf_DIPLAY_IMAGES_KEEP				= self.__setup.get_val('conf_DIPLAY_IMAGES_KEEP')

		self.__const_DISPLAY_CONTENT_OLD_FILE		= self.__setup.get_val('const_DISPLAY_CONTENT_OLD_FILE')
		self.__const_DISPLAY_LINES_LIMIT			= self.__setup.get_val('const_DISPLAY_LINES_LIMIT')
		self.__const_DISPLAY_STATUSBAR_TOGGLE_SEC	= self.__setup.get_val('const_DISPLAY_STATUSBAR_TOGGLE_SEC')
		self.__const_FONT_PATH						= self.__setup.get_val('const_FONT_PATH')
		self.__const_DISPLAY_CONTENT_PATH			= self.__setup.get_val('const_DISPLAY_CONTENT_PATH')
		self.__const_DISPLAY_IMAGE_EXPORT_FILE		= self.__setup.get_val('const_DISPLAY_IMAGE_EXPORT_FILE')
		self.__const_DISPLAY_IMAGE_KEEP_PATH		= self.__setup.get_val('const_DISPLAY_IMAGE_KEEP_PATH')
		self.__const_TASKS_PATH						= self.__setup.get_val('const_TASKS_PATH')

		#define colors
		color = {}
		color['blue']		= (0,	0,		255)
		color['green']		= (0,	255,	0)
		color['red']		= (255,	0,		0)
		color['yellow']		= (255,	255,	0)
		color['orange']		= (255,	94,		14)
		color['white']		= (255,	255,	255)
		color['black']		= (0,	0,		0)
		color['lightgrey']	= (127,	127,	127)
		color['grey']		= (70,	70,		70)

		if self.__conf_DISP_COLOR_MODEL == '1':
			self.color_text = 255
			self.color_high = 255
			self.color_alert = 255
			self.color_bg = 0
		else:
			self.color_text 	= color[self.__conf_DISP_COLOR_TEXT]
			self.color_high 	= color[self.__conf_DISP_COLOR_HIGH]
			self.color_alert 	= color[self.__conf_DISP_COLOR_ALERT]
			self.color_bg 		= color[self.__conf_DISP_COLOR_BACKGROUND]

			if self.__conf_DISP_COLOR_MODEL == 'RGBA':
				# add alpha-channel
				self.color_text		= (*self.color_text, 255)
				self.color_high		= (*self.color_high, 255)
				self.color_alert	= (*self.color_alert, 255)
				self.color_bg		= (*self.color_bg, 255)

		self.hardware_ready	= True

		serial	= None
		try:
			if self.__conf_DISP_CONNECTION == 'I2C':
				serial = i2c(port=1, address=self.__conf_DISP_I2C_ADDRESS)
			elif self.__conf_DISP_CONNECTION == 'SPI':
				if self.__conf_DISP_DRIVER == 'ST7735':
					serial = spi(port=self.__conf_DISP_SPI_PORT, device=0, bus_speed_hz=40000000)
				elif self.__conf_DISP_DRIVER == 'ST7735 WAVESHARE LCD display HAT':
					serial = spi(port=self.__conf_DISP_SPI_PORT, device=0, bus_speed_hz=40000000, gpio_DC=25, gpio_RST=27)
				else:
					serial = spi(port=self.__conf_DISP_SPI_PORT, device=0)
			else:
				print('Error: No valid connection type for display',file=sys.stderr)
				if self.__conf_DISP_DRIVER != 'none':
					raise Exception('Error: No valid connection type for display')
		except:
			self.hardware_ready	= False
			print(f'Display connection to {self.__conf_DISP_CONNECTION} could not be enabled.', file=sys.stderr)

		try:
			if self.__conf_DISP_DRIVER == 'none' or serial is None:
				self.device	= self.__display_dummy()
				self.hardware_ready	= False
			elif self.__conf_DISP_DRIVER == 'SSD1306':
				self.device	= ssd1306(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y)
			elif self.__conf_DISP_DRIVER == 'SSD1309':
				self.device	= ssd1309(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y)
			elif self.__conf_DISP_DRIVER == 'SSD1322':
				self.device	= ssd1322(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y)
			elif self.__conf_DISP_DRIVER == 'SSD1331':
				self.device	= ssd1331(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y)
			elif self.__conf_DISP_DRIVER == 'SH1106':
				self.device	= sh1106(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y)
			elif self.__conf_DISP_DRIVER == 'ST7735':
				self.device	= st7735(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y, gpio_LIGHT=(self.__conf_DISP_BACKLIGHT_PIN if self.__conf_DISP_BACKLIGHT_PIN > 0 else 18), bgr=self.__conf_DISP_COLOR_BGR, inverse=self.__conf_DISP_COLOR_INVERSE) # pin: GPIO Backlight
				self.device.backlight(self.__conf_DISP_BACKLIGHT_ENABLED)
			elif self.__conf_DISP_DRIVER == 'ST7735 WAVESHARE LCD display HAT':
				self.device	= st7735(serial_interface=serial, h_offset=self.__conf_DISP_OFFSET_X, v_offset=self.__conf_DISP_OFFSET_Y, gpio_LIGHT=(self.__conf_DISP_BACKLIGHT_PIN if self.__conf_DISP_BACKLIGHT_PIN > 0 else 18), bgr=self.__conf_DISP_COLOR_BGR, inverse=self.__conf_DISP_COLOR_INVERSE) # pin: GPIO Backlight
				self.device.backlight(self.__conf_DISP_BACKLIGHT_ENABLED)
			else:
				print('Error: No valid display driver', file=sys.stderr)
		except:
			self.hardware_ready	= False
			print(f'Display driver {self.__conf_DISP_DRIVER} could not be enabled.', file=sys.stderr)

		if self.hardware_ready:
			self.device.capabilities(width=self.__conf_DISP_RESOLUTION_X, height=self.__conf_DISP_RESOLUTION_Y, rotate=self.__conf_DISP_ROTATE, mode=self.__conf_DISP_COLOR_MODEL)

			self.device.contrast(self.__conf_DISP_CONTRAST)

			self.device.persist	= False

		# define font
		self.FONT = ImageFont.truetype(self.__const_FONT_PATH, self.__conf_DISP_FONT_SIZE)

		# calculate line dimensions
		self.calculate_LineSize()

		# prepare statusbar
		if self.__conf_DISP_SHOW_STATUSBAR:
			self.traffic_monitor		= lib_network.traffic_monitor()
			self.statusbar_toggle		= 0
			self.statusbar_toggle_time	= 0
			self.comitup_state			= False
			self.comitup_time			= 0
			self.cpu_times				= None

		# power saving: unchanged frames are not sent again, the panel sleeps when idle
		self.last_frame		= None
		self.sleeping		= False
		self.content_time	= time.time()

		# backup drive (status bar, home screen)
		self.drive_cache	= None
		self.drive_info		= None
		self.drive_time		= 0
		self.drive_unmounted	= {}

		# home screen (content marked hidden=READY): drawn as a picture with the backup drive
		self.home_mode		= False
		self.home_lines		= None		# its lines, to come back to after short messages
		self.home_overlay	= False		# another screen than the home screen: the home screen returns after a while
		self.overlay_task	= False		# ... and a backup (or other task) ran meanwhile: its screens stay while it runs
		try:
			self.home_no_drive	= lib_language.language().l('box_menu_no_drive')
		except:
			self.home_no_drive	= 'No drive'
		self.home_render	= lib_menu_render.renderer(
			width		= self.device.width,
			height		= self.device.height,
			mode		= self.device.mode,
			color_text	= self.color_text,
			color_high	= self.color_high,
			color_bg	= self.color_bg,
			color_alert	= self.color_alert,
			font_path	= self.__const_FONT_PATH,
		)

		# display menu: draws its own screens while open (menu_show, menu_close)
		self.menu_active	= False
		self.menu_closed	= False
		self.__lock			= threading.RLock()

		# create folders
		## ensure const_DISPLAY_CONTENT_PATH exists
		if not os.path.isdir(self.__const_DISPLAY_CONTENT_PATH):
			pathlib.Path(self.__const_DISPLAY_CONTENT_PATH).mkdir(parents=True, exist_ok=True)

		if self.__conf_DIPLAY_IMAGES_KEEP:
			## ensure const_DISPLAY_IMAGE_KEEP_PATH exists
			if not os.path.isdir(self.__const_DISPLAY_IMAGE_KEEP_PATH):
				pathlib.Path(self.__const_DISPLAY_IMAGE_KEEP_PATH).mkdir(parents=True, exist_ok=True)

			## create SessionID by subfolders of const_DISPLAY_IMAGE_KEEP_PATH
			self.SessionID = sum(
				os.path.isdir(os.path.join(self.__const_DISPLAY_IMAGE_KEEP_PATH, Finding))
				for Finding in os.listdir(self.__const_DISPLAY_IMAGE_KEEP_PATH)
			) + 1

			## ensure const_DISPLAY_IMAGE_KEEP_PATH/SessionID exists
			if not os.path.isdir(os.path.join(self.__const_DISPLAY_IMAGE_KEEP_PATH, str(self.SessionID))):
				pathlib.Path(self.__const_DISPLAY_IMAGE_KEEP_PATH, str(self.SessionID)).mkdir(parents=True, exist_ok=True)
		else:
			self.SessionID	= 0

		## start display menu
		self.menu_controller	= displaymenu.MENU_CONTROLLER()

		if self.__conf_MENU_ENABLED:
			# start displaymenu as iternal background process
			try:
				thread	= threading.Thread(target=displaymenu.menu, args=(self.maxLines -1 if self.__conf_DISP_SHOW_STATUSBAR else self.maxLines, self.__setup, self.menu_controller, self))
				thread.start()
			except:
				pass

	def calculate_LineSize(self):
		# calculate size of text

		# create image and draw onject
		image	= Image.new(self.device.mode, (self.device.width, self.device.height), self.color_bg)
		draw	= ImageDraw.Draw(image)

		# write test text "gG"
		(left, top, right, bottom) = draw.textbbox((0,0),"gG",font=self.FONT)

		self.line_height = bottom - top

		self.maxLines = int(self.device.height / self.line_height)

		if self.maxLines > self.__const_DISPLAY_LINES_LIMIT:
			self.maxLines = self.__const_DISPLAY_LINES_LIMIT

	def get_statusbar(self):
		if not self.__conf_DISP_SHOW_STATUSBAR:
			return(None)

		statusbar	= []

		# select item to dispay?
		if time.time() - self.statusbar_toggle_time >= self.__const_DISPLAY_STATUSBAR_TOGGLE_SEC:
			self.statusbar_toggle_time	= time.time()
			self.statusbar_toggle	= self.statusbar_toggle + 1 if self.statusbar_toggle < 2 else 0

		# print active tasks (without comitup information)
		if self.statusbar_toggle == 0:
			# look for task files
			try:
				TaskFilesList	= [TaskFile for TaskFile in os.listdir(self.__const_TASKS_PATH) if TaskFile.endswith('.txt')]
			except:
				TaskFilesList	= []

			Tasks	= []
			if TaskFilesList:
				for TaskFile in TaskFilesList:
					try:
						with open(os.path.join(self.__const_TASKS_PATH, TaskFile)) as f:
							Tasks.append(f.readline().strip())
					except:
						continue

			if Tasks:
				statusbar.append(Tasks[0])
				for Task in Tasks[1:]:
					statusbar[0]	+= f'|{Task}'
				return(statusbar)
			else:
				self.statusbar_toggle	= 1

		#comitup
		# comitup-cli is a python program: asking it every frame cost more CPU than everything else
		if time.time() - self.comitup_time >= 30:
			self.comitup_time	= time.time()
			self.comitup_state	= lib_comitup.comitup().get_status()['state']
		comitup_status	= self.comitup_state

		match comitup_status:
			case 'HOTSPOT':
				statusbar.append('HOT')
			case 'CONNECTING':
				statusbar.append('..?')
			case 'CONNECTED':
				statusbar.append('WiFi')

		if self.statusbar_toggle == 1:
			# backup drive at a glance (how full, how big), else network traffic
			drive	= self.__drive_status()
			if drive:
				return(drive)
			statusbar	+=[self.traffic_monitor.get_traffic()]
			return(statusbar)

		# if sill not retuned: CPU usage since the last look (read directly, no processes started)
		try:
			with open('/proc/stat', 'r') as f:
				cpu_fields	= [int(field) for field in f.readline().split()[1:]]
			cpu_times	= (sum(cpu_fields), cpu_fields[3] + cpu_fields[4])
			if self.cpu_times and cpu_times[0] > self.cpu_times[0]:
				busy	= 1 - (cpu_times[1] - self.cpu_times[1]) / (cpu_times[0] - self.cpu_times[0])
				statusbar.append(f'{100 * busy:.0f}%')
			self.cpu_times	= cpu_times
		except:
			pass

		# temperature
		try:
			with open('/sys/class/thermal/thermal_zone0/temp', 'r') as f:
				temp_c	= float(f.read()) / 1000
			statusbar.append(f'{temp_c:.0f}°C')
		except:
			pass

		return(statusbar)

	def __drive_status(self):
		# the backup drive: NVMe first, then a backup target, then any drive mounted under /media;
		# not mounted (drives are unmounted after a backup): the attached NVMe / USB drive, its fill read without mounting
		# or remembered from when it was mounted
		# ['NVMe 67%', '1.0T'] or None; looked up every 10 s
		if time.time() - self.drive_time < 10:
			return(self.drive_cache)
		self.drive_time		= time.time()
		self.drive_cache	= None

		try:
			Mounts	= []
			with open('/proc/mounts', 'r') as f:
				for Line in f:
					Device, MountPoint	= Line.split()[:2]
					MountPoint	= MountPoint.replace('\\040', ' ')
					if Device.startswith('/dev/') and MountPoint.startswith('/media/'):
						Mounts.append((Device, MountPoint))
		except:
			Mounts	= []

		for Device, MountPoint in sorted(Mounts, key=lambda Mount: (0 if Mount[0].startswith('/dev/nvme') else 1 if 'target' in Mount[1] else 2)):
			try:
				st		= os.statvfs(MountPoint)
				Total	= st.f_blocks * st.f_frsize
				Free	= st.f_bavail * st.f_frsize
			except:
				continue
			if Total <= 0:
				continue

			Used	= 1 - Free / Total
			self.__drive_set(Device, self.__drive_label(Device, ''), Used, Total)
			self.__drive_remember(Device, Used, Total)
			return(self.drive_cache)

		# not mounted
		Drive	= self.__attached_drive()
		if Drive:
			Used, Total	= self.__drive_usage_unmounted(Drive)
			self.__drive_set(Drive['path'], Drive['label'], Used, Total or Drive['size'])

		return(self.drive_cache)

	def __drive_set(self, Device, Label, Used, Total):
		Kind	= 'NVMe' if Device.startswith('/dev/nvme') else 'USB' if Device.startswith('/dev/sd') else 'SD' if Device.startswith('/dev/mmcblk') else 'Disk'

		Size	= Total
		for Unit in ['B', 'K', 'M', 'G', 'T']:
			if Size < 1000 or Unit == 'T':
				break
			Size	/= 1000
		SizeText	= f'{Size:.1f}{Unit}' if Size < 10 else f'{Size:.0f}{Unit}'

		self.drive_cache	= [f'{Kind} {100 * Used:.0f}%' if Used is not None else Kind, SizeText]
		self.drive_info		= {'name': Label or Kind, 'used': Used, 'size': SizeText}

		# for the Bluetooth service (lib_ble.py) and others: the drive as the display shows it
		try:
			Path	= os.path.join(os.path.dirname(self.__const_DISPLAY_CONTENT_OLD_FILE), 'drive-status.json')
			Data	= json.dumps(self.drive_info)
			if Data != getattr(self, 'drive_status_written', ''):
				with open(Path, 'w') as f:
					f.write(Data)
				self.drive_status_written	= Data
		except:
			pass

	def __lsblk(self):
		try:
			return(json.loads(subprocess.check_output(['lsblk', '-J', '-b', '-o', 'PATH,TYPE,TRAN,FSTYPE,LABEL,UUID,SIZE,MOUNTPOINT,MODEL'], stderr=subprocess.DEVNULL, timeout=5).decode()).get('blockdevices', []))
		except:
			return([])

	def __attached_drive(self):
		# the largest partition with a file system on an NVMe or USB disk (not the system disk), NVMe first
		Candidates	= []
		for Disk in self.__lsblk():
			Path	= Disk.get('path') or ''
			if Path.startswith('/dev/mmcblk') or Disk.get('type') != 'disk':
				continue
			Parts		= Disk.get('children') or [Disk]
			if any((Part.get('mountpoint') or '') in ['/', '/boot', '/boot/firmware'] for Part in Parts):
				continue
			Kind	= 0 if Path.startswith('/dev/nvme') else 1 if (Disk.get('tran') == 'usb' or Path.startswith('/dev/sd')) else 2
			for Part in Parts:
				if Part.get('fstype') and Part.get('fstype') not in ['swap', 'linux_raid_member'] and int(Part.get('size') or 0) > 0:
					Candidates.append((Kind, -int(Part['size']), {
						'path':		Part['path'],
						'uuid':		Part.get('uuid') or Part['path'],
						'fstype':	Part.get('fstype'),
						'label':	Part.get('label') or (Disk.get('model') or '').strip(),
						'size':		int(Part['size']),
					}))
		Candidates.sort(key=lambda Candidate: (Candidate[0], Candidate[1]))
		return(Candidates[0][2] if Candidates else None)

	def __sbin(self, Program):
		# display.py starts at boot with a short PATH (no /usr/sbin): tune2fs and dump.exfat live there
		for Folder in ['/usr/sbin', '/sbin', '/usr/bin', '/bin']:
			if os.path.isfile(os.path.join(Folder, Program)):
				return(os.path.join(Folder, Program))
		return(Program)

	def __drive_usage_file(self):
		return(os.path.join(os.path.dirname(self.__const_DISPLAY_CONTENT_OLD_FILE), 'drive-usage.json'))

	def __drive_remember(self, Device, Used, Total):
		# fill of a mounted drive, by UUID: shown while it is not mounted
		try:
			UUID	= subprocess.check_output(['lsblk', '-n', '-d', '-o', 'UUID', Device], stderr=subprocess.DEVNULL, timeout=3).decode().strip()
		except:
			UUID	= ''
		if not UUID:
			return
		Usage	= self.__drive_usage_load()
		if Usage.get(UUID, {}).get('used') is not None and abs(Usage[UUID]['used'] - Used) < 0.001:
			return
		Usage[UUID]	= {'used': Used, 'total': Total, 'time': int(time.time())}
		try:
			with open(self.__drive_usage_file(), 'w') as f:
				json.dump(Usage, f)
		except:
			pass

	def __drive_usage_load(self):
		try:
			with open(self.__drive_usage_file(), 'r') as f:
				return(json.load(f))
		except:
			return({})

	def __drive_usage_unmounted(self, Drive):
		# (used 0..1 or None, total bytes or None): read from the file system without mounting (ext*, exFAT),
		# every 5 minutes at most (the drive is not woken up all the time), else remembered from the last mount
		Key		= Drive['uuid']
		Cached	= self.drive_unmounted.get(Key)
		if Cached and time.time() - Cached[0] < 300:
			return(Cached[1], Cached[2])

		Used, Total	= None, None
		try:
			if Drive['fstype'] in ['ext2', 'ext3', 'ext4']:
				Output	= subprocess.check_output([self.__sbin('tune2fs'), '-l', Drive['path']], stderr=subprocess.DEVNULL, timeout=10).decode()
				Values	= {Key_.strip(): Value.strip() for Key_, Value in (Line.split(':', 1) for Line in Output.splitlines() if ':' in Line)}
				Blocks, FreeBlocks, BlockSize	= int(Values['Block count']), int(Values['Free blocks']), int(Values['Block size'])
				Used, Total	= 1 - FreeBlocks / Blocks, Blocks * BlockSize
			elif Drive['fstype'] == 'exfat':
				Output	= subprocess.check_output([self.__sbin('dump.exfat'), Drive['path']], stderr=subprocess.DEVNULL, timeout=10).decode()
				Count	= re.search(r'Cluster Count:\s*(\d+)', Output)
				Free	= re.search(r'Free Clusters?:\s*(\d+)', Output)
				if Count and Free and int(Count.group(1)) > 0:
					Used	= 1 - int(Free.group(1)) / int(Count.group(1))
		except:
			pass

		if Used is None:
			Remembered	= self.__drive_usage_load().get(Drive['uuid'])
			if Remembered:
				Used, Total	= Remembered.get('used'), Remembered.get('total')

		self.drive_unmounted[Key]	= (time.time(), Used, Total)
		return(Used, Total)

	def __drive_label(self, Device, Fallback):
		# the drive's label (or model) for the home screen
		try:
			Output	= subprocess.check_output(['lsblk', '-n', '-d', '-o', 'LABEL', Device], stderr=subprocess.DEVNULL, timeout=3).decode().strip()
			return(Output or Fallback)
		except:
			return(Fallback)

	def __home_image(self, Lines):
		# Lines of the home screen: BACKUP BOX / state / IP / online state (lib_cron_ip.py)
		Contents	= [Line.split(':', 1)[-1].strip() for Line in Lines if Line.split(':', 1)[-1].strip()]
		Title		= Contents[0] if Contents else 'BACKUP BOX'
		State		= Contents[1] if len(Contents) > 1 else ''
		Rest		= Contents[2:]
		IP			= next((Item for Item in Rest if re.match(r'^\d+\.\d+\.\d+\.\d+$', Item)), '')
		Network		= next((Item for Item in Rest if Item != IP), '')

		self.__drive_status()

		try:
			with open('/sys/class/thermal/thermal_zone0/temp', 'r') as f:
				Temp	= float(f.read()) / 1000
		except:
			Temp	= None

		return(self.home_render.home(
			title	= Title,
			state	= State,
			ip		= IP,
			network	= Network,
			online	= bool(IP),
			drive	= self.drive_info if self.drive_cache else None,
			temp	= Temp,
			no_drive	= self.home_no_drive,
		))

	def __screen_image(self, Lines):
		# a message (comitup, backup progress, power off ...) under the home screen's title bar
		Rows	= []
		for Line in Lines:
			Formatstring, Content	= Line.split(':', 1) if ':' in Line else ('s=h', Line)
			Content	= Content.strip()
			if not Content:
				continue
			Style	= 'h'
			for Format in Formatstring.split(','):
				if Format.startswith('s='):
					Style	= Format[2:] or 'h'
			Row	= {'text': Content, 'style': Style if Style in ['h', 'b', 'a', 'hc'] else 'h', 'progress': None}
			if Content.startswith('PGBAR='):
				try:
					Row['progress']	= float(Content[6:])
				except ValueError:
					Row['progress']	= 0
			Rows.append(Row)

		# what a running task does (backup progress)
		Task	= ''
		try:
			for TaskFile in sorted(os.listdir(self.__const_TASKS_PATH)):
				if TaskFile.endswith('.txt'):
					with open(os.path.join(self.__const_TASKS_PATH, TaskFile)) as f:
						Task	= f.readline().strip()
					break
		except:
			pass

		self.__drive_status()

		return(self.home_render.screen(title='BACKUP BOX', lines=Rows, temp=self.__temperature(), task=Task))

	def __temperature(self):
		try:
			with open('/sys/class/thermal/thermal_zone0/temp', 'r') as f:
				return(float(f.read()) / 1000)
		except:
			return(None)

	def show(self, Lines, statusbar=None, new_content=True):
		Image_	= None
		if self.home_mode:
			# the home screen as a picture (its own drive and temperature line instead of the status bar)
			Image_	= self.__home_image(Lines)
		elif Lines and ':IMAGE=' not in Lines[0]:
			# every other text screen in the same design (QR codes and pictures stay full screen)
			try:
				Image_	= self.__screen_image(Lines)
			except Exception as e:
				print(f'Display: message screen failed, plain text instead: {e}', file=sys.stderr)

		if Image_ is not None:
			image	= Image_
			if self.__unchanged(image, new_content):
				return()
			with self.__lock:
				if self.menu_active:
					self.last_frame	= None
					return()
				self.__display_image(image)
			self.__save_image(image=image, new_content=new_content)
			return()

		# fill line count to const_DISPLAY_LINES_LIMIT
		while len(Lines) < self.__const_DISPLAY_LINES_LIMIT:
			Lines.append("s=b:")

		if ":IMAGE=" in Lines[0]:
			# PRINT IMAGE FROM FILE
			# Only the first line can be interpreted as image. In This case no further text will be printed.
			# FORMAT: "IMAGE=filename"

			Formatstring, Content = Lines[0].split(':',1)

			ImageLine = Content.split("=",1)
			ImageFilename = ImageLine[1]

			try:
				image = Image.open(ImageFilename).convert(self.device.mode).resize((self.device.width, self.device.height))
			except:
				return()

			if self.__unchanged(image, new_content):
				return()

			# display image (not over the menu, it may have opened meanwhile)
			with self.__lock:
				if self.menu_active:
					self.last_frame	= None
					return()
				self.__display_image(image)

			# save image to file
			self.__save_image(image=image, new_content=new_content)

		else:
			# Write lines

			if statusbar is not None:
				Lines[self.maxLines-1] = f's=s:STATUSBAR'

			# create image and draw onject
			image	= Image.new(self.device.mode, (self.device.width, self.device.height), self.color_bg)
			draw	= ImageDraw.Draw(image)

			x = 0
			y_shift = 0

			y_space = 0
			if self.maxLines > 1:
				Spare_Y = self.device.height - self.maxLines * self.line_height
				y_space = int(Spare_Y / (self.maxLines - 1))

			# draw background
			draw.rectangle((0, 0, self.device.width, self.device.height), outline=self.color_bg, fill=self.color_bg)

			for n in range(0, self.maxLines):
				Line = Lines[n]

				# basic color settings
				fg_fill = self.color_text
				bg_fill = self.color_bg

				# basic text decoration settings
				underline = False

				Formatstring, Content = Line.split(':',1)
				Formats = Formatstring.split(',')

				for Format in Formats:
					if '=' in Format:
						FormatType, FormatValue = Format.split('=',1)
					else:
						FormatType	= Format
						FormatValue	= ''

					if FormatType == 's':
						if self.device.mode == '1':
							# monochrome
							if FormatValue == 'h': # highlight
								fg_fill = self.color_bg
								bg_fill = self.color_text
							elif FormatValue == 'a': # alert
								underline = True
							elif FormatValue == 's': # statusbar
								fg_fill = self.color_bg
								bg_fill = self.color_text
						else:
							# RGB(A)
							if FormatValue in ['h', 'hc']: # highlight or highlight color
								fg_fill = self.color_high
							elif FormatValue == 'a': # alert
								if self.color_alert not in [self.color_text, self.color_high]:
									fg_fill = self.color_alert
								elif self.color_alert != self.color_high:
									fg_fill = self.color_alert
									bg_fill = self.color_high
								else:
									underline = True
							elif FormatValue == 's': # statusbar
								fg_fill = self.color_bg
								bg_fill = self.color_text

					if FormatType == 'u':
						underline = True

				y	= (n) * self.line_height + y_shift - 1 + y_space

				# Draw a filled box in case of inverted output
				if bg_fill != self.color_bg:
					draw.rectangle(
						(x, y + 2, self.device.width, min(y + self.line_height + 1, self.device.height)),
						outline=bg_fill, fill=bg_fill)

				if Content.startswith("IMAGE="):
					Content	= ''

				if Content.startswith("PGBAR="):
					try:
						progress	= float(Content[6:])
					except ValueError:
						progress	= 0

					progress	= int(progress * 10 + 0.5) / 10 if progress < 100 else int(progress + 0.5)

					# define text to print
					Content = "{}%".format(str(progress if progress <= 100 else '100+'))

					(left, top, right, bottom) = draw.textbbox((0,0), "100%::", font=self.FONT)
					pgbar_text_length = right - left

					# draw progressbar
					pg_space = 2
					pgbar_x_l = x + pgbar_text_length
					pgbar_x_r = self.device.width - pg_space
					pgbar_y_u = y + pg_space
					pgbar_y_d = y + self.line_height - pg_space

					## draw outer frame
					draw.rectangle((pgbar_x_l, pgbar_y_u, pgbar_x_r, pgbar_y_d), outline=fg_fill, fill=bg_fill)

					## draw inner frame
					pgbar_x_l	+= 1
					pgbar_x_r	-= 1
					pgbar_y_u	+= 1
					pgbar_y_d	-= 1

					pgbar_x_r = pgbar_x_l + (pgbar_x_r - pgbar_x_l) * (100 if progress >= 100 else progress) / 100

					draw.rectangle((pgbar_x_l, pgbar_y_u, pgbar_x_r, pgbar_y_d), outline=bg_fill, fill=fg_fill)

				# Write text
				## status bar
				if FormatType == 's' and FormatValue == 's':

					i	= 0
					for item in statusbar:

						if (i < len(statusbar) - 1) or (i == 0):
							# left bound
							x	= int(i * self.device.width / len(statusbar))
						else:
							# right bound
							(left, top, right, bottom) = draw.textbbox((0,0), item, font=self.FONT)
							pgbar_text_length = right - left
							x	= self.device.width - pgbar_text_length - 1

						draw.text((x + 1, y), item, font=self.FONT, fill=fg_fill)

						i += 1
				else:
					## regular text
					draw.text((x + 1, y), Content, font=self.FONT, fill=fg_fill)

				if underline:
					(left, top, right, bottom) = draw.textbbox((0, 0), Content, font=self.FONT)
					draw.line(
						(x + 1, y + self.line_height + y_space, x + 1 + right - left, y + self.line_height + y_space),
						fill=fg_fill, width=1)

			if self.__unchanged(image, new_content):
				return()

			# display image (not over the menu, it may have opened meanwhile)
			with self.__lock:
				if self.menu_active:
					self.last_frame	= None
					return()
				self.__display_image(image)

			# save image to file
			self.__save_image(image=image, new_content=new_content)

	def __display_image(self, image):
		if self.hardware_ready:
			# the menu draws from its own thread
			with self.__lock:
				self.device.display(image)

	def menu_show(self, image):
		# a menu screen, shown at once (no message queue)
		with self.__lock:
			self.menu_active	= True
			self.content_time	= time.time()
			self.__panel(on=True)
			self.__display_image(image)
			self.last_frame	= None
			self.__save_image(image=image, new_content=False)

	def menu_close(self):
		# the main loop shows the current screen again
		with self.__lock:
			self.menu_active	= False
			self.menu_closed	= True
			self.content_time	= time.time()

	def wake(self):
		# True if the display was off (the button press only wakes it)
		with self.__lock:
			self.content_time	= time.time()
			if not self.sleeping:
				return(False)
			self.__panel(on=True)
			return(True)

	def sleep(self):
		with self.__lock:
			self.menu_active	= False
			self.__panel(on=False)

	def __unchanged(self, image, new_content):
		# a statusbar refresh that draws the same picture again: skip sending and saving it
		frame	= image.tobytes()
		if not new_content and frame == self.last_frame:
			return(True)
		self.last_frame	= frame
		return(False)

	def __panel(self, on):
		# switch the panel (and an LCD backlight) off when idle: less power, heat and OLED burn-in
		if self.sleeping != (not on) and self.hardware_ready:
			try:
				self.device.show() if on else self.device.hide()
				if self.__conf_DISP_DRIVER.startswith('ST7735') and self.__conf_DISP_BACKLIGHT_ENABLED:
					self.device.backlight(on)
			except:
				pass
		self.sleeping	= not on

	def __busy(self):
		# a backup or another task is running
		try:
			if any(TaskFile.endswith('.txt') for TaskFile in os.listdir(self.__const_TASKS_PATH)):
				return(True)
		except:
			pass
		# a backup that is still mounting or preparing (no task file yet)
		return(subprocess.run(['pgrep', '-f', 'little-backup-box/backup.py'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0)

	def __save_image(self, image, new_content=True):

		### <<< KEEP IMAGES FOR DOCUMENTATION
		if self.__conf_DIPLAY_IMAGES_KEEP and new_content:
			if os.path.exists(self.__const_DISPLAY_IMAGE_EXPORT_FILE):
				uptime_seconds	= f'{lib_system.get_uptime_sec():0>12.2f}'

				FileNameTimed	= f'{uptime_seconds}-{os.path.basename(self.__const_DISPLAY_IMAGE_EXPORT_FILE)}'
				try:
					os.rename(self.__const_DISPLAY_IMAGE_EXPORT_FILE, os.path.join(self.__const_DISPLAY_IMAGE_KEEP_PATH, str(self.SessionID), FileNameTimed))
				except:
					pass
		### >>> KEEP IMAGES FOR DOCUMENTATION

		try:
			image.save(self.__const_DISPLAY_IMAGE_EXPORT_FILE)
		except:
			pass


	def terminate(self, signum=None, frame=None):
		# stop menu
		self.menu_controller.terminate()
		time.sleep(1) # wait until menu is stopped

		# exit function
		self.loop_continue	= False

		print('display.py process killed.')

	def main(self):
		display_time	= time.time()
		Lines 			= []

		# start endless loop to display content
		while(self.loop_continue):
			import_old_file 		= True
			temp_screen				= False
			hidden_info				= []

			# re-check for new files earlier than conf_DISP_FRAME_TIME, if no message was found
			FrameTime = self.__conf_DISP_FRAME_TIME / 4

			ContentFile	= self.__display_content_files.get_next_file_name()

			if ContentFile:

				if not os.path.isfile(ContentFile):
					continue

				# file could be in writing process, wait for minimal file age
				if time.time() - os.stat(ContentFile).st_mtime < 0.2:
					time.sleep(0.2)

				Lines = []
				FrameTime = self.__conf_DISP_FRAME_TIME

				# read content file
				try:
					CF	= open(ContentFile, 'r')
				except:
					continue

				for Line in CF:

					Line = Line.strip()

					# skip empty lines
					if not Line:
						continue

					if Line[0:4] == 'set:': # global settings line

						settingStr = Line[4:]
						settings = settingStr.split(',')

						for setting in settings:

							if '=' in setting:
								SettingType, SettingValue = setting.split('=',1)
								SettingValue	= SettingValue.strip()
							else:
								SettingType	= setting
								SettingValue	= ''

							if SettingType == 'kill':
								# remove content file
								os.remove(ContentFile)

								self.terminate()
								return()

							if SettingType == 'clear':
								import_old_file	= False

							if SettingType == 'temp':
								temp_screen		= True

							if SettingType == 'hidden':
								if SettingValue and SettingValue not in hidden_info:
									hidden_info.append(SettingValue)

							if SettingType == 'time' and float(SettingValue) >= 0:
								FrameTime		= float(SettingValue)

					elif len (Lines) < self.__const_DISPLAY_LINES_LIMIT: # content line

						if (Line[0:1] == ':'):
								Line = "s=h{}".format(Line)
						elif ":" not in Line:
							Line = "s=h:{}".format(Line)

						Lines.append(Line)

				CF.close()

				# every screen but the home screen is temporary: the home screen comes back 8 s after the last message
				# (60 s after a backup: its result stays readable), never while a backup or other task runs
				if not temp_screen and 'READY' not in hidden_info and self.home_lines:
					if not self.overlay_task and self.__busy():
						self.overlay_task	= True
					self.home_overlay	= True

				# a message over the home screen (or a short message after it) is shown on its own: the home screen's
				# lines are not merged into it; while a task runs, its messages stack as before
				if (self.home_mode or (self.home_overlay and not self.overlay_task)) and import_old_file:
					import_old_file	= False

				# read old lines
				if import_old_file:
					if len(Lines) < self.__const_DISPLAY_LINES_LIMIT and os.path.isfile(self.__const_DISPLAY_CONTENT_OLD_FILE):
						with open(self.__const_DISPLAY_CONTENT_OLD_FILE, 'r') as oCF:
							for Line in oCF:

								Line = Line.strip()

								# settings of the saved screen (set:hidden=...) are not text
								if Line.startswith('set:'):
									continue

								if len(Lines) < self.__const_DISPLAY_LINES_LIMIT:
									Line = Line.split(':',1)[-1]
									if Line:
										Line = "s=b:{}".format(Line)
										Lines.append(Line)

			else:
				# no new content file:
				# reload last displayed content if Lines is empty
				if not Lines and os.path.isfile(self.__const_DISPLAY_CONTENT_OLD_FILE):

					with open(self.__const_DISPLAY_CONTENT_OLD_FILE, 'r') as oCF:
						for Line in oCF:

							Line = Line.strip()

							if not Line or Line.startswith('set:'):
								continue

							Lines.append(Line)

			# absolute fallback against empty screens
			if not Lines:
				Lines = ["s=b:"]

			if ContentFile:
				# remove content file
				try:
					os.remove(ContentFile)
				except:
					pass

				# display temp only:
				if temp_screen and os.path.isfile(self.__const_DISPLAY_CONTENT_OLD_FILE):
					# the screen before comes back as it was ("clear": without merging the saved screen into itself,
					# which doubled its lines after every QR code and stacked "offline" lines)
					with open(self.__const_DISPLAY_CONTENT_OLD_FILE, 'r') as oCF:
						OldContent	= oCF.read()
					with open(ContentFile, 'w') as newCF:
						newCF.write('set:clear\n' + OldContent)

					if hidden_info:
						with open(ContentFile, 'a') as newCF:
							for hidden in hidden_info:
								newCF.write(f"\nset:hidden={hidden}")

				# move lines to old lines file
				if not temp_screen:
					with open(self.__const_DISPLAY_CONTENT_OLD_FILE, 'w') as oCF:
						oCF.write("\n".join(Lines))

						for hidden in hidden_info:
							oCF.write(f"\nset:hidden={hidden}")

					self.home_mode	= 'READY' in hidden_info
					if self.home_mode:
						self.home_lines		= list(Lines)
						self.home_overlay	= False
						self.overlay_task	= False
				else:
					self.home_mode	= False

				# while the menu is open, news are kept and shown when it closes
				if not self.menu_active:
					self.__panel(on=True)
					self.show(Lines=Lines, statusbar=self.get_statusbar(), new_content=True)
				display_time		= time.time()
				self.content_time	= time.time()
			elif self.menu_closed:
				self.menu_closed	= False
				if not self.menu_active and not self.sleeping:
					self.show(Lines=Lines, statusbar=self.get_statusbar(), new_content=True)
					display_time	= time.time()
			elif (
				self.home_overlay and
				self.home_lines and
				not self.menu_active and
				time.time() - self.content_time >= (60 if self.overlay_task else 8)
				):
				# back to the home screen; not while a task runs (asked only now: pgrep at most every 8 s)
				if self.__busy():
					self.overlay_task	= True
					self.content_time	= time.time()
				else:
					self.home_overlay	= False
					self.overlay_task	= False
					Lines	= list(self.home_lines)
					with open(self.__const_DISPLAY_CONTENT_OLD_FILE, 'w') as oCF:
						oCF.write("\n".join(Lines))
						oCF.write("\nset:hidden=READY")
					self.home_mode	= True
					if not self.sleeping:
						self.show(Lines=Lines, statusbar=self.get_statusbar(), new_content=True)
						display_time	= time.time()
			else:
				# sleep after conf_DISP_SLEEP_MIN minutes without news, never while a task runs
				if (
					not self.sleeping and
					not self.menu_active and
					self.__conf_DISP_SLEEP_MIN > 0 and
					time.time() - self.content_time >= self.__conf_DISP_SLEEP_MIN * 60
					):
					if self.__busy():
						self.content_time	= time.time()
					else:
						with self.__lock:
							self.__panel(on=False)

				# refresh: temperature, drive and task line (all text screens are drawn with them now)
				if (
					not self.sleeping and
					not self.menu_active and
					(self.__conf_DISP_SHOW_STATUSBAR or self.home_mode or self.home_render is not None) and
					time.time() - display_time >= self.__const_DISPLAY_STATUSBAR_TOGGLE_SEC
					):
					self.show(Lines=Lines, statusbar=self.get_statusbar(), new_content=False)
					display_time	= time.time()

			time.sleep(FrameTime)

	class __display_dummy(object):

		def __init__(self):
			self.persist = False
			self.width	= 128
			self.height	= 64
			self.mode	= 'RGB'

		def capabilities(self, *args, **kwargs):
			return()

		def contrast(self, *args, **kwargs):
			return()

		def display(self, *args, **kwargs):
			return()

if __name__ == "__main__":
	display	= DISPLAY()
	display.main()

	sys.exit()

