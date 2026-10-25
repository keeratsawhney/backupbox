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

# Provides a menu for the display.
# It can be used by hardware-buttons. Please read the Wiki at https://github.com/outdoorbits/little-backup-box/wiki/02a.-Displaymenu.
#
# The menu draws its screens itself (lib_menu_render.py) and hands them to the display process
# (display.py, "screen"): no delay by the message queue, the home screen comes back when it closes.
#
# Buttons (buttons.cfg): up, down, left (back), right (open/select), ok (select), back, home (close the menu).
# The first press opens the menu (or only wakes a display that is off), holding up/down scrolls,
# holding left closes the menu.

import configparser
from gpiozero import Button
import json
import os
import subprocess
import sys
import threading
import time

import lib_cron_ip
import lib_language
import lib_menu_render
import lib_network
import lib_setup
import lib_socialmedia
import lib_storage
import lib_system

# import lib_debug
# xx	= lib_debug.debug()

class MENU_CONTROLLER(object):
	def __init__(self):
		self.proceed	= True

	def terminate(self):
		self.proceed	= False

class menu(object):

	def __init__(self, DISPLAY_LINES, setup, menu_controller, screen=None):

		self.__setup			= setup
		self.__lan				= lib_language.language()
		self.__texts			= {}
		self.screen				= screen
		self.lock				= threading.RLock()

		self.WORKING_DIR = os.path.dirname(__file__)

		self.const_MEDIA_DIR							= self.__setup.get_val('const_MEDIA_DIR')
		self.conf_DISP_FRAME_TIME_IP					= self.__setup.get_val('conf_DISP_FRAME_TIME_IP')
		self.conf_RSYNC_SERVER							= self.__setup.get_val('conf_RSYNC_SERVER')
		self.conf_RSYNC_PORT							= self.__setup.get_val('conf_RSYNC_PORT')
		self.conf_RSYNC_USER							= self.__setup.get_val('conf_RSYNC_USER')
		self.conf_RSYNC_PASSWORD						= self.__setup.get_val('conf_RSYNC_PASSWORD')
		self.conf_RSYNC_SERVER_MODULE					= self.__setup.get_val('conf_RSYNC_SERVER_MODULE')
		self.const_BUTTONS_CONFIG_FILE					= self.__setup.get_val('const_BUTTONS_CONFIG_FILE')
		self.const_BUTTONS_PRIVATE_CONFIG_FILE			= self.__setup.get_val('const_BUTTONS_PRIVATE_CONFIG_FILE')
		self.conf_MENU_BUTTON_COMBINATION				= self.__setup.get_val('conf_MENU_BUTTON_COMBINATION')
		self.conf_MENU_BUTTON_ROTATE					= self.__setup.get_val('conf_MENU_BUTTON_ROTATE')
		self.conf_MENU_BUTTON_BOUNCETIME 				= self.__setup.get_val('conf_MENU_BUTTON_BOUNCETIME')
		self.GPIO_MENU_BUTTON_EDGE_DETECTION_RISING 	= self.__setup.get_val('conf_MENU_BUTTON_EDGE_DETECTION') == 'RISING'
		self.GPIO_MENU_BUTTON_RESISTOR_PULL_UP 			= self.__setup.get_val('conf_MENU_BUTTON_RESISTOR_PULL') == 'UP'
		self.const_MENU_TIMEOUT_SEC						= self.__setup.get_val('const_MENU_TIMEOUT_SEC')

		self.RCLONE_CONFIG_FILE							= f"{self.const_MEDIA_DIR}/{self.__setup.get_val('const_RCLONE_CONFIG_FILE')}"

		self.buttons	= {}

		if self.screen is not None:
			self.render	= lib_menu_render.renderer(
				width		= self.screen.device.width,
				height		= self.screen.device.height,
				mode		= self.screen.device.mode,
				color_text	= self.screen.color_text,
				color_high	= self.screen.color_high,
				color_bg	= self.screen.color_bg,
				color_alert	= self.screen.color_alert,
				font_path	= self.__setup.get_val('const_FONT_PATH'),
			)

		self.kill_backup_process	= [f'{self.WORKING_DIR}/stop_backup.sh']
		self.start_backup_trunk		= ['/usr/bin/python3', f'{self.WORKING_DIR}/backup.py']

		self.MENU_MAIN	= self.build_menu()

		# menu state
		self.active			= False		# menu on screen
		self.stack			= []		# open menus: dicts title, items, pos, first
		self.dialog			= None		# confirm dialog: dict item, choice
		self.page			= None		# info page: dict title, rows (function), first
		self.message_until	= 0			# a short message is shown until then
		self.last_input		= 0
		self.page_drawn		= 0

		self.GPIO_init()

		# internal loop: closes the menu after a while without input, refreshes info pages
		while menu_controller.proceed:
			time.sleep(0.5)
			with self.lock:
				if not self.active:
					continue
				now	= time.time()
				if self.message_until:
					if now >= self.message_until:
						self.close()
				elif now - self.last_input >= self.const_MENU_TIMEOUT_SEC:
					self.close()
				elif self.page and now - self.page_drawn >= 2:
					self.draw()

	# ---------- texts ----------

	def t(self, key):
		# translations, cached (every lookup starts php)
		if key not in self.__texts:
			self.__texts[key]	= self.__lan.l(key)
		return(self.__texts[key])

	def service_name(self, service):
		Type, ServiceName	= lib_storage.extractService(service)
		if Type == 'cloud':
			return(ServiceName)
		if Type == 'social':
			return(self.t(f'box_menu_backup_mode_{ServiceName}'))
		return(self.t(f'box_menu_backup_mode_{Type}'))

	def service_icon(self, service):
		Type, ServiceName	= lib_storage.extractService(service)
		return({
			'anyusb':		'drive',
			'usb':			'drive',
			'nvme':			'drive',
			'internal':		'card',
			'camera':		'camera',
			'cloud':		'cloud',
			'cloud_rsync':	'cloud',
			'ftp':			'cloud',
			'social':		'cloud',
		}.get(Type, 'backup'))

	# ---------- menu structure ----------
	# items: label, icon, and one of
	#   submenu:	list of items (or a function returning it)
	#   command:	list of commands (all but the last are waited for), with confirm text and done message
	#   page:		function returning the rows of an info page
	#   run:		function, called after the menu closed (IP and Wi-Fi QR screens)
	#   visible:	function, the item is shown only if it returns True

	def build_menu(self):
		# local backups
		local_services	= ['anyusb', 'usb', 'internal', 'camera']

		## NVMe available?
		if lib_storage.get_available_partitions(StorageType='nvme'):
			local_services.append('nvme')

		cloudservices	= []

		## rsyncserver
		if not (self.conf_RSYNC_SERVER =='' or self.conf_RSYNC_PORT =='' or self.conf_RSYNC_USER =='' or self.conf_RSYNC_PASSWORD =='' or self.conf_RSYNC_SERVER_MODULE ==''):
			cloudservices.append('cloud_rsync')

		## rclone services
		cfg = configparser.ConfigParser()
		cfg.read(self.RCLONE_CONFIG_FILE)
		for rclone_cloudservice in cfg.sections():
			cloudservices.append(f'cloud:{rclone_cloudservice}')

		## ftp
		cloudservices.append('ftp')

		## social media
		socialservices	= [f'social:{socialservice}' for socialservice in lib_socialmedia.socialmedia().get_social_services_configured()]

		BACKUP_SOURCES_MENU	= []
		for source in (local_services + cloudservices):
			sourceName	= self.service_name(source)

			BACKUP_SOURCE_MENU	= []
			for target in (local_services + cloudservices + socialservices):

				# check for invalid combinations of Source and Target
				if (source == target) and (source != 'usb'):
					continue

				# exclude combinations
				if target in ['anyusb', 'camera', 'ftp']:
					continue
				if target == 'cloud_rsync' and source in ['anyusb', 'camera', 'ftp']:
					continue
				if target.startswith('social:') and (source in ['anyusb', 'camera', 'cloud_rsync', 'ftp'] or source.startswith('cloud:')):
					continue

				targetName	= self.service_name(target)

				BACKUP_SOURCE_MENU.append({
					'label':	f'→ {targetName}',
					'icon':		self.service_icon(target),
					'backup':	['--SourceName', source, '--TargetName', target],
					'title':	self.t('box_menu_start_default'),
					'confirm':	f'{sourceName} → {targetName}',
					'ok':		self.t('box_menu_start'),
					'done':		self.t('box_menu_backup_started'),
				})

			if BACKUP_SOURCE_MENU:
				BACKUP_SOURCES_MENU.append({
					'label':	sourceName,
					'title':	f'{sourceName} →',
					'icon':		self.service_icon(source),
					'submenu':	BACKUP_SOURCE_MENU,
				})


		STOP_BACKUP	= {
			'label':	self.t('box_menu_backup_stop'),
			'icon':		'stop',
			'command':	[self.kill_backup_process],
			'confirm':	self.t('box_menu_confirm_stop'),
			'ok':		self.t('box_menu_stop'),
			'danger':	True,
			'done':		self.t('box_menu_backup_stopped'),
			'visible':	self.backup_running,
		}

		MENU_NETWORK	= [
			{
				'label':	self.t('box_menu_ip'),
				'icon':		'ip',
				'run':		lambda: lib_cron_ip.ip_info().display_ip(FrameTime=self.conf_DISP_FRAME_TIME_IP * 2, force=True),
			},
			{
				'label':	self.t('box_menu_wifi'),
				'icon':		'qr',
				'run':		lambda: lib_cron_ip.ip_info().display_wifi_qr(FrameTime=self.conf_DISP_FRAME_TIME_IP * 3, force=True),
			},
			{
				'label':	self.t('box_menu_comitup_reset'),
				'icon':		'reset',
				'command':	[[f'{self.WORKING_DIR}/comitup-reset.sh']],
				'confirm':	self.t('box_menu_confirm_comitup'),
				'danger':	True,
				'visible':	lambda: os.path.isfile('/usr/sbin/comitup-cli') or os.path.isfile('/usr/bin/comitup-cli'),
			},
			{
				'label':	self.t('box_menu_vpn_stop'),
				'icon':		'vpn',
				'command':	[['/usr/bin/python3', f"{self.WORKING_DIR}/lib_vpn.py", 'stop']],
				'confirm':	self.t('box_menu_confirm_vpn'),
				'visible':	self.vpn_active,
			},
		]

		MENU_POWER	= [
			{
				'label':	self.t('box_menu_display_off'),
				'icon':		'display',
				'run':		self.display_off,
			},
			{
				'label':	self.t('box_menu_power_reboot'),
				'icon':		'reboot',
				'command':	[['/usr/bin/python3', f'{self.WORKING_DIR}/lib_poweroff.py', 'reboot']],
				'confirm':	self.t('box_menu_confirm_reboot'),
				'danger':	True,
			},
			{
				'label':	self.t('box_menu_power_shutdown'),
				'icon':		'power',
				'command':	[['/usr/bin/python3', f'{self.WORKING_DIR}/lib_poweroff.py', 'poweroff']],
				'confirm':	self.t('box_menu_confirm_power_off'),
				'ok':		self.t('box_menu_off'),
				'danger':	True,
			},
		]

		MENU_MAIN	= []

		default	= self.default_backup()
		if default:
			MENU_MAIN.append(default)

		MENU_MAIN	+= [
			STOP_BACKUP,
			{
				'label':	self.t('box_menu_backup_backup'),
				'icon':		'backup',
				'submenu':	BACKUP_SOURCES_MENU,
			},
			{
				'label':	self.t('box_menu_status'),
				'icon':		'status',
				'page':		self.status_rows,
			},
			{
				'label':	self.t('box_menu_network'),
				'icon':		'wifi',
				'submenu':	MENU_NETWORK,
			},
			{
				'label':	self.t('box_menu_power'),
				'icon':		'power',
				'submenu':	MENU_POWER,
			},
		]

		return(MENU_MAIN)

	def label(self, item, key='label'):
		value	= item.get(key, '')
		return(value() if callable(value) else value)

	def is_risky(self, item):
		return(item.get('danger', False))

	def confirm_text(self, item):
		# backups from the box's buttons always copy, never delete from the source: the screen says so
		if 'backup' in item:
			return(f"{item['confirm']}\n{self.t('box_menu_mode_copy')}")
		return(item['confirm'])

	def default_backup(self):
		# the backup set as default (Settings): one item, as when the box starts
		Source	= self.__setup.get_val('conf_BACKUP_DEFAULT_SOURCE')
		Target	= self.__setup.get_val('conf_BACKUP_DEFAULT_TARGET')
		if Source in ['none', ''] or Target in ['none', '']:
			return(None)

		Arguments	= [
			'--SourceName', Source,
			'--TargetName', Target,
			'--rename-files', str(self.__setup.get_val('conf_BACKUP_RENAME_FILES')),
			'--force-sync-database', 'False',
			'--generate-thumbnails', str(self.__setup.get_val('conf_BACKUP_GENERATE_THUMBNAILS')),
			'--update-exif', str(self.__setup.get_val('conf_BACKUP_UPDATE_EXIF')),
			'--power-off', 'False',
		]

		return({
			'label':	self.t('box_menu_start_default'),
			'icon':		'play',
			'backup':	Arguments,
			'confirm':	f'{self.service_name(Source)} → {self.service_name(Target)}',
			'ok':		self.t('box_menu_start'),
			'done':		self.t('box_menu_backup_started'),
			'visible':	lambda: not self.backup_running(),
		})

	# ---------- state ----------

	def backup_running(self):
		return(subprocess.run(['pgrep', '-f', f'{self.WORKING_DIR}/backup.py'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0)

	def vpn_active(self):
		try:
			return(any(Interface.startswith(('tun', 'wg')) for Interface in os.listdir('/sys/class/net')))
		except:
			return(False)

	def status_rows(self):
		rows	= []

		running	= self.backup_running()
		rows.append({'icon': 'backup' if running else 'check', 'label': self.t('box_menu_running') if running else self.t('box_menu_ready'), 'value': ''})

		# drives in use under /media (backup targets and sources, browse mounts)
		drives	= {}
		try:
			Blockdevices	= json.loads(subprocess.check_output(['lsblk', '-J', '-o', 'PATH,LABEL,MODEL,MOUNTPOINT,PKNAME'], stderr=subprocess.DEVNULL).decode()).get('blockdevices', [])
		except:
			Blockdevices	= []

		def walk(devices, model=''):
			for device in devices:
				Model	= device.get('model') or model
				Mount	= device.get('mountpoint') or ''
				if Mount.startswith(self.const_MEDIA_DIR + '/') and Mount not in drives:
					drives[Mount]	= (device.get('label') or (Model or '').strip() or os.path.basename(Mount))
				walk(device.get('children', []), Model)
		walk(Blockdevices)

		for Mount, Name in drives.items():
			try:
				st		= os.statvfs(Mount)
				total	= st.f_blocks * st.f_frsize
				free	= st.f_bavail * st.f_frsize
			except:
				continue
			if total <= 0:
				continue
			rows.append({'icon': 'drive', 'label': Name, 'value': f"{100 * (1 - free / total):.0f}% · {self.human(total)}", 'bar': 1 - free / total})

		if not drives:
			# not mounted: the drive the display found (fill read without mounting or remembered)
			Drive	= getattr(self.screen, 'drive_info', None) if self.screen is not None else None
			if Drive and getattr(self.screen, 'drive_cache', None):
				rows.append({'icon': 'drive', 'label': Drive['name'], 'value': f"{100 * Drive['used']:.0f}% · {Drive['size']}" if Drive.get('used') is not None else Drive['size'], 'bar': Drive.get('used')})
			else:
				rows.append({'icon': 'drive', 'label': self.t('box_menu_no_drive'), 'value': ''})

		IPs	= [IP for IP in lib_network.get_IPs().split('\n') if IP and ':' not in IP]
		rows.append({'icon': 'ip', 'label': 'IP', 'value': IPs[0] if IPs else '–'})

		try:
			with open('/sys/class/thermal/thermal_zone0/temp', 'r') as f:
				Temp	= float(f.read()) / 1000
			rows.append({'icon': 'temp', 'label': self.t('box_menu_temperature'), 'value': f'{Temp:.0f}°C', 'alert': Temp >= 75})
		except:
			pass

		# power supply: vcgencmd get_throttled, bit 0 = under-voltage now, bit 16 = since boot
		try:
			Throttled	= int(subprocess.check_output(['vcgencmd', 'get_throttled'], stderr=subprocess.DEVNULL).decode().strip().split('=')[-1], 16)
			if Throttled & 0x1:
				rows.append({'icon': 'bolt', 'label': self.t('box_menu_power'), 'value': self.t('box_menu_power_low'), 'alert': True})
			elif Throttled & 0x10000:
				rows.append({'icon': 'bolt', 'label': self.t('box_menu_power'), 'value': self.t('box_menu_power_was_low'), 'alert': True})
			else:
				rows.append({'icon': 'bolt', 'label': self.t('box_menu_power'), 'value': self.t('box_menu_power_ok')})
		except:
			pass

		return(rows)

	def human(self, Bytes):
		for Unit in ['B', 'KB', 'MB', 'GB', 'TB']:
			if Bytes < 1000 or Unit == 'TB':
				return(f'{Bytes:.0f} {Unit}' if Bytes >= 10 or Unit == 'B' else f'{Bytes:.1f} {Unit}')
			Bytes	/= 1000

	# ---------- buttons ----------

	def GPIO_init(self):
		if self.conf_MENU_BUTTON_COMBINATION:
			if self.conf_MENU_BUTTON_COMBINATION.isnumeric():
				ButtonsConfigFile		= f"{self.WORKING_DIR}/{self.const_BUTTONS_CONFIG_FILE}"
				ButtonCombinationNumber	= int(self.conf_MENU_BUTTON_COMBINATION)
			elif self.conf_MENU_BUTTON_COMBINATION[0:1] == 'c':
				ButtonsConfigFile		= f"{self.const_MEDIA_DIR}/{self.const_BUTTONS_PRIVATE_CONFIG_FILE}"
				ButtonCombinationNumber	=  int(self.conf_MENU_BUTTON_COMBINATION[1:])
			else:
				return

			if os.path.isfile(ButtonsConfigFile):
				ConfigLines	= []
				with open(ButtonsConfigFile,'r') as f:
					ConfigLines	= f.readlines()

				ConfigLineNumber	= 0
				for ConfigLine in ConfigLines:
					ConfigLine	= ConfigLine.strip()
					if ConfigLine:
						if ConfigLine[0:1] != '#':
							ConfigLineNumber	+= 1
							if ConfigLineNumber	== ButtonCombinationNumber:
								ConfigLine	= ConfigLine.split(':',1)[0]
								ButtonDefs	= ConfigLine.split(',')
								for ButtonDef in ButtonDefs:
									GPIO_PIN, ButtonFunction	= ButtonDef.split('=')
									GPIO_PIN	= int(GPIO_PIN)

									self.GPIO_config_button(GPIO_PIN,ButtonFunction.strip())

	def GPIO_config_button(self,GPIO_PIN,ButtonFunction):
		# rotate buttons
		if self.conf_MENU_BUTTON_ROTATE == 2:
			ButtonFunction	= {'up': 'down', 'down': 'up', 'left': 'right', 'right': 'left'}.get(ButtonFunction, ButtonFunction)

		if ButtonFunction not in ['up', 'down', 'left', 'right', 'ok', 'back', 'home']:
			return()

		# define button (up and down repeat while held)
		try:
			self.buttons[GPIO_PIN]	= Button(pin=GPIO_PIN, pull_up=self.GPIO_MENU_BUTTON_RESISTOR_PULL_UP, bounce_time=self.conf_MENU_BUTTON_BOUNCETIME / 1000, hold_time=0.6, hold_repeat=True)
		except:
			return()

		press	= lambda: self.press(ButtonFunction)

		# add events to buttons
		if self.GPIO_MENU_BUTTON_EDGE_DETECTION_RISING:
			self.buttons[GPIO_PIN].when_pressed		= press
			if ButtonFunction in ['up', 'down']:
				self.buttons[GPIO_PIN].when_held	= press
			elif ButtonFunction in ['left', 'back']:
				# hold left: close the menu from any level (joystick only boxes)
				self.buttons[GPIO_PIN].when_held	= lambda: self.press('home')
		else:
			self.buttons[GPIO_PIN].when_released	= press

	def press(self, key):
		with self.lock:
			self.last_input	= time.time()

			# a display that is off is only woken up
			if self.screen is not None and self.screen.wake():
				return

			if self.message_until:
				self.close()
				return

			if not self.active:
				if key in ['back', 'home']:
					return
				self.open()
				return

			if key == 'home':
				self.close()
			elif self.dialog:
				self.press_dialog(key)
			elif self.page:
				self.press_page(key)
			else:
				self.press_list(key)

	def press_list(self, key):
		level	= self.stack[-1]
		items	= self.visible(level['items'])
		if not items:
			self.back()
			return
		level['pos']	= min(level['pos'], len(items) - 1)

		if key == 'down':
			level['pos']	= (level['pos'] + 1) % len(items)
		elif key == 'up':
			level['pos']	= (level['pos'] - 1) % len(items)
		elif key in ['left', 'back']:
			self.back()
			return
		elif key in ['right', 'ok']:
			self.select(items[level['pos']])
			return
		self.draw()

	def press_dialog(self, key):
		if key in ['up', 'down', 'left', 'right'] and not (key == 'right' and self.dialog['choice'] == 1):
			if key == 'left' and self.dialog['choice'] == 0:
				self.dialog	= None
			else:
				self.dialog['choice']	= 1 - self.dialog['choice']
			self.draw()
		elif key == 'back':
			self.dialog	= None
			self.draw()
		elif key in ['ok', 'right']:
			item		= self.dialog['item']
			confirmed	= self.dialog['choice'] == 1
			self.dialog	= None
			if confirmed:
				self.execute(item)
			else:
				self.draw()

	def press_page(self, key):
		rows	= self.page['cache']
		visible	= self.render.page_rows()
		if key == 'down' and self.page['first'] + visible < len(rows):
			self.page['first']	+= 1
		elif key == 'up' and self.page['first'] > 0:
			self.page['first']	-= 1
		elif key in ['left', 'back', 'ok', 'right']:
			self.page	= None
		self.draw(refresh=False)

	# ---------- actions ----------

	def visible(self, items):
		if callable(items):
			items	= items()
		return([item for item in items if not item.get('visible') or item['visible']()])

	def open(self):
		self.active	= True
		self.stack	= [{'title': 'Backup Box', 'items': self.MENU_MAIN, 'pos': 0, 'first': 0}]
		self.dialog	= None
		self.page	= None
		self.draw()

	def close(self):
		self.active			= False
		self.stack			= []
		self.dialog			= None
		self.page			= None
		self.message_until	= 0
		if self.screen is not None:
			self.screen.menu_close()

	def back(self):
		if len(self.stack) > 1:
			self.stack.pop()
			self.draw()
		else:
			self.close()

	def select(self, item):
		if 'submenu' in item:
			self.stack.append({'title': item.get('title', self.label(item)), 'items': item['submenu'], 'pos': 0, 'first': 0})
			self.draw()
		elif 'page' in item:
			self.page	= {'title': item['label'], 'rows': item['page'], 'first': 0, 'cache': []}
			self.draw()
		elif 'run' in item:
			# screens of their own (IP, Wi-Fi QR): the menu closes first
			self.close()
			threading.Thread(target=item['run'], daemon=True).start()
		elif 'command' in item or 'backup' in item:
			if item.get('confirm'):
				# risky actions start on "cancel"
				self.dialog	= {'item': item, 'choice': 0 if self.is_risky(item) else 1}
				self.draw()
			else:
				self.execute(item)

	def execute(self, item):
		if 'backup' in item:
			# copy only: a backup device on the go never deletes from the source by a button press
			Commands	= [self.kill_backup_process, self.start_backup_trunk + item['backup'] + ['--move-files', 'False']]
		else:
			Commands	= item['command']

		def run(commands):
			# all commands but the last are waited for
			for n, command in enumerate(commands):
				if n < len(commands) - 1:
					subprocess.run(command, shell=False)
				else:
					subprocess.Popen(command, shell=False)

		threading.Thread(target=run, args=(Commands,), daemon=True).start()

		if item.get('done'):
			self.show(self.render.message('check', item['done']))
			self.message_until	= time.time() + 1.5
		else:
			self.close()

	def display_off(self):
		if self.screen is not None:
			self.screen.sleep()

	# ---------- drawing ----------

	def draw(self, refresh=True):
		if self.screen is None or not self.active:
			return

		if self.dialog:
			item	= self.dialog['item']
			image	= self.render.confirm(
				title			= item.get('title', self.label(item)),
				text			= self.confirm_text(item),
				ok_label		= item.get('ok', self.t('box_menu_ok')),
				cancel_label	= self.t('box_menu_cancel'),
				choice			= self.dialog['choice'],
				danger			= self.is_risky(item),
			)
		elif self.page:
			if refresh or not self.page['cache']:
				self.page['cache']	= self.page['rows']()
			self.page['first']	= min(self.page['first'], max(0, len(self.page['cache']) - self.render.page_rows()))
			image	= self.render.page(self.page['title'], self.page['cache'], self.page['first'])
			self.page_drawn	= time.time()
		else:
			level	= self.stack[-1]
			items	= self.visible(level['items'])
			level['pos']	= min(level['pos'], max(0, len(items) - 1))

			# keep the selection in view
			rows	= self.render.rows
			if level['pos'] < level['first']:
				level['first']	= level['pos']
			elif level['pos'] >= level['first'] + rows:
				level['first']	= level['pos'] - rows + 1

			image	= self.render.list(
				level['title'],
				[{'label': self.label(item), 'icon': self.label(item, 'icon'), 'submenu': 'submenu' in item or 'page' in item} for item in items],
				level['pos'],
				level['first'],
			)

		self.show(image)

	def show(self, image):
		if self.screen is not None:
			self.screen.menu_show(image)

if __name__ == "__main__":
	pass
