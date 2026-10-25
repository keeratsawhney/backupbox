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

# Bluetooth Low Energy service for the Backup Box app (Android):
# the phone finds the box nearby, reads its state and starts or stops a backup without any Wi-Fi.
# Files never go over Bluetooth: for the touch UI, previews and downloads the app reads the box's
# Wi-Fi details here and joins its hotspot (or uses the network the box is on).
#
# Pairing: a 6-digit code is shown on the box's display and typed on the phone ("passkey entry").
# Everything but the advertisement needs that pairing (encrypted, authenticated characteristics).
#
# GATT service 7a6b0001-1b2c-4d3e-8f40-6c6262626f78
#   status   7a6b0002-...  read, notify   {"s": "ready"|"running", "l": [display lines], "d": drive, "ip": [...], "h": hotspot, "t": temp, "v": low voltage, "m": last message}
#   command  7a6b0003-...  write          {"c": "start"|"stop"|"reboot"|"poweroff"}
#   wifi     7a6b0004-...  read           {"ssid": ..., "pw": ..., "ip": "10.41.0.1", "lan": [...]}
#
# Runs as the systemd service lbb-ble (etc/lbb-ble.service), started by install-touch-ui.sh.

import base64
import json
import os
import subprocess
import sys
import time

WORKING_DIR	= os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, WORKING_DIR)

SERVICE_UUID	= '7a6b0001-1b2c-4d3e-8f40-6c6262626f78'
STATUS_UUID		= '7a6b0002-1b2c-4d3e-8f40-6c6262626f78'
COMMAND_UUID	= '7a6b0003-1b2c-4d3e-8f40-6c6262626f78'
WIFI_UUID		= '7a6b0004-1b2c-4d3e-8f40-6c6262626f78'

HOTSPOT_IP		= '10.41.0.1'

# ---------- the box (no Bluetooth here: testable without it) ----------

class box_state(object):
	def __init__(self, setup=None):
		if setup is None:
			import lib_setup
			setup	= lib_setup.setup()
		self.__setup	= setup
		self.tmp_dir	= os.path.dirname(self.__setup.get_val('const_DISPLAY_CONTENT_OLD_FILE'))
		self.message	= ''
		self.__hotspot	= (0, None)

	def backup_running(self):
		return(subprocess.run(['pgrep', '-f', f'{WORKING_DIR}/backup.py'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0)

	def display_lines(self):
		# what the box's display shows (text screens; the home screen's lines)
		Lines	= []
		try:
			with open(self.__setup.get_val('const_DISPLAY_CONTENT_OLD_FILE'), 'r') as f:
				for Line in f:
					Line	= Line.strip()
					if not Line or Line.startswith('set:'):
						continue
					Content	= Line.split(':', 1)[-1].strip() if ':' in Line and Line.split(':', 1)[0].startswith('s=') else Line
					if Content and not Content.startswith('IMAGE='):
						Lines.append(Content)
		except:
			pass
		return(Lines[:6])

	def drive(self):
		# the backup drive as the display shows it (display.py: drive-status.json)
		try:
			with open(os.path.join(self.tmp_dir, 'drive-status.json'), 'r') as f:
				Drive	= json.load(f)
			return({'n': Drive.get('name'), 'u': Drive.get('used'), 'z': Drive.get('size')})
		except:
			return(None)

	def ips(self):
		try:
			Output	= subprocess.check_output(['hostname', '-I'], stderr=subprocess.DEVNULL, timeout=3).decode()
		except:
			return([])
		return([IP for IP in Output.split() if '.' in IP and not IP.startswith('127.')])

	def hotspot(self):
		# SSID of the box's own hotspot (comitup) or None; asked every 15 s at most (nmcli)
		Time, SSID	= self.__hotspot
		if time.time() - Time < 15:
			return(SSID)
		SSID	= None
		if HOTSPOT_IP in self.ips():
			try:
				import lib_comitup
				SSID	= lib_comitup.comitup().get_hotspot_ssid() or None
			except:
				SSID	= None
		self.__hotspot	= (time.time(), SSID)
		return(SSID)

	def temperature(self):
		try:
			with open('/sys/class/thermal/thermal_zone0/temp', 'r') as f:
				return(round(float(f.read()) / 1000))
		except:
			return(None)

	def low_voltage(self):
		try:
			Output	= subprocess.check_output(['vcgencmd', 'get_throttled'], stderr=subprocess.DEVNULL, timeout=3).decode()
			return(bool(int(Output.strip().split('=')[-1], 16) & 0x1))
		except:
			return(False)

	def status(self):
		return({
			's':	'running' if self.backup_running() else 'ready',
			'l':	self.display_lines(),
			'd':	self.drive(),
			'ip':	self.ips(),
			'h':	self.hotspot() is not None,
			't':	self.temperature(),
			'v':	self.low_voltage(),
			'm':	self.message,
		})

	def wifi(self):
		# how the phone reaches the box over Wi-Fi: its hotspot (name, password) and its addresses in other networks
		SSID	= self.hotspot()
		Info	= {'lan': [IP for IP in self.ips() if IP != HOTSPOT_IP]}
		if SSID:
			try:
				Password	= base64.b64decode(self.__setup.get_val('conf_WIFI_PASSWORD')).decode('utf-8')
			except:
				Password	= ''
			Info.update({'ssid': SSID, 'pw': Password, 'ip': HOTSPOT_IP})
		return(Info)

	def default_backup(self):
		# the backup set as default (Settings), copy only: a button press on the phone never deletes on the card
		Source	= self.__setup.get_val('conf_BACKUP_DEFAULT_SOURCE')
		Target	= self.__setup.get_val('conf_BACKUP_DEFAULT_TARGET')
		if Source in ['none', ''] or Target in ['none', '']:
			return(None)
		return(['/usr/bin/python3', f'{WORKING_DIR}/backup.py',
			'--SourceName', Source,
			'--TargetName', Target,
			'--move-files', 'False',
			'--rename-files', str(self.__setup.get_val('conf_BACKUP_RENAME_FILES')),
			'--force-sync-database', 'False',
			'--generate-thumbnails', str(self.__setup.get_val('conf_BACKUP_GENERATE_THUMBNAILS')),
			'--update-exif', str(self.__setup.get_val('conf_BACKUP_UPDATE_EXIF')),
			'--power-off', 'False',
		])

	def command(self, Data, run=None):
		# a command from the phone; returns the message for the phone
		run	= run or (lambda Command, Wait: subprocess.run(Command) if Wait else subprocess.Popen(Command, start_new_session=True))
		try:
			Command	= json.loads(Data).get('c', '')
		except:
			Command	= ''

		if Command == 'start':
			if self.backup_running():
				self.message	= 'running'
			else:
				Backup	= self.default_backup()
				if Backup is None:
					self.message	= 'no_default'
				else:
					run([f'{WORKING_DIR}/stop_backup.sh'], True)
					run(Backup, False)
					self.message	= 'started'
		elif Command == 'stop':
			run([f'{WORKING_DIR}/stop_backup.sh'], False)
			self.message	= 'stopped'
		elif Command in ['reboot', 'poweroff']:
			run(['/usr/bin/python3', f'{WORKING_DIR}/lib_poweroff.py', Command], False)
			self.message	= Command
		else:
			self.message	= 'unknown'
		return(self.message)

# ---------- Bluetooth (BlueZ over D-Bus) ----------

def run_service():
	import dbus
	import dbus.exceptions
	import dbus.mainloop.glib
	import dbus.service
	from gi.repository import GLib

	import lib_display
	import lib_language

	BLUEZ				= 'org.bluez'
	ADAPTER_IFACE		= 'org.bluez.Adapter1'
	GATT_MANAGER_IFACE	= 'org.bluez.GattManager1'
	ADV_MANAGER_IFACE	= 'org.bluez.LEAdvertisingManager1'
	AGENT_MANAGER_IFACE	= 'org.bluez.AgentManager1'
	OM_IFACE			= 'org.freedesktop.DBus.ObjectManager'
	PROP_IFACE			= 'org.freedesktop.DBus.Properties'
	SERVICE_IFACE		= 'org.bluez.GattService1'
	CHRC_IFACE			= 'org.bluez.GattCharacteristic1'
	ADV_IFACE			= 'org.bluez.LEAdvertisement1'
	AGENT_IFACE			= 'org.bluez.Agent1'

	dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
	bus		= dbus.SystemBus()
	loop	= GLib.MainLoop()

	state	= box_state()
	display	= lib_display.display()
	lan		= lib_language.language()

	def text(key, fallback):
		try:
			value	= lan.l(key)
			return(value if value and value != key else fallback)
		except:
			return(fallback)

	def to_bytes(value):
		return(dbus.Array([dbus.Byte(b) for b in json.dumps(value, separators=(',', ':')).encode()], signature='y'))

	class Rejected(dbus.exceptions.DBusException):
		_dbus_error_name	= 'org.bluez.Error.Rejected'

	class Application(dbus.service.Object):
		def __init__(self):
			self.path		= '/org/backupbox'
			self.services	= []
			dbus.service.Object.__init__(self, bus, self.path)

		@dbus.service.method(OM_IFACE, out_signature='a{oa{sa{sv}}}')
		def GetManagedObjects(self):
			Objects	= {}
			for Service in self.services:
				Objects[Service.get_path()]	= Service.get_properties()
				for Characteristic in Service.characteristics:
					Objects[Characteristic.get_path()]	= Characteristic.get_properties()
			return(Objects)

	class Service(dbus.service.Object):
		def __init__(self, app, index):
			self.path				= f'{app.path}/service{index}'
			self.characteristics	= []
			dbus.service.Object.__init__(self, bus, self.path)

		def get_path(self):
			return(dbus.ObjectPath(self.path))

		def get_properties(self):
			return({SERVICE_IFACE: {
				'UUID':				SERVICE_UUID,
				'Primary':			True,
				'Characteristics':	dbus.Array([c.get_path() for c in self.characteristics], signature='o'),
			}})

	class Characteristic(dbus.service.Object):
		def __init__(self, service, index, uuid, flags):
			self.path		= f'{service.path}/char{index}'
			self.service	= service
			self.uuid		= uuid
			self.flags		= flags
			dbus.service.Object.__init__(self, bus, self.path)

		def get_path(self):
			return(dbus.ObjectPath(self.path))

		def get_properties(self):
			return({CHRC_IFACE: {
				'Service':	self.service.get_path(),
				'UUID':		self.uuid,
				'Flags':	self.flags,
			}})

		def value(self):
			return(dbus.Array([], signature='y'))

		@dbus.service.method(CHRC_IFACE, in_signature='a{sv}', out_signature='ay')
		def ReadValue(self, options):
			# long values are read in parts (offset)
			Value	= self.value()
			Offset	= int(options.get('offset', 0))
			return(Value[Offset:])

		@dbus.service.method(CHRC_IFACE, in_signature='aya{sv}')
		def WriteValue(self, value, options):
			raise dbus.exceptions.DBusException('org.bluez.Error.NotSupported')

		@dbus.service.method(CHRC_IFACE)
		def StartNotify(self):
			raise dbus.exceptions.DBusException('org.bluez.Error.NotSupported')

		@dbus.service.method(CHRC_IFACE)
		def StopNotify(self):
			pass

		@dbus.service.signal(PROP_IFACE, signature='sa{sv}as')
		def PropertiesChanged(self, interface, changed, invalidated):
			pass

	class StatusCharacteristic(Characteristic):
		def __init__(self, service):
			Characteristic.__init__(self, service, 0, STATUS_UUID, ['encrypt-authenticated-read', 'notify'])
			self.notifying	= False
			self.last		= None
			GLib.timeout_add_seconds(2, self.poll)

		def value(self):
			return(to_bytes(state.status()))

		def poll(self):
			# changes are sent to the phone (backup progress, drive, network)
			if self.notifying:
				Value	= self.value()
				if Value != self.last:
					self.last	= Value
					self.PropertiesChanged(CHRC_IFACE, {'Value': Value}, [])
			return(True)

		@dbus.service.method(CHRC_IFACE)
		def StartNotify(self):
			self.notifying	= True
			self.last		= None

		@dbus.service.method(CHRC_IFACE)
		def StopNotify(self):
			self.notifying	= False

	class CommandCharacteristic(Characteristic):
		def __init__(self, service, status):
			Characteristic.__init__(self, service, 1, COMMAND_UUID, ['encrypt-authenticated-write'])
			self.status	= status

		@dbus.service.method(CHRC_IFACE, in_signature='aya{sv}')
		def WriteValue(self, value, options):
			Message	= state.command(bytes(value).decode('utf-8', 'replace'))
			print(f'command: {Message}', flush=True)
			self.status.last	= None
			GLib.timeout_add(500, lambda: self.status.poll() and False)

	class WifiCharacteristic(Characteristic):
		def __init__(self, service):
			Characteristic.__init__(self, service, 2, WIFI_UUID, ['encrypt-authenticated-read'])

		def value(self):
			return(to_bytes(state.wifi()))

	class Advertisement(dbus.service.Object):
		def __init__(self, with_name):
			self.path		= '/org/backupbox/advertisement0'
			self.with_name	= with_name
			dbus.service.Object.__init__(self, bus, self.path)

		@dbus.service.method(PROP_IFACE, in_signature='s', out_signature='a{sv}')
		def GetAll(self, interface):
			Properties	= {
				'Type':			'peripheral',
				'ServiceUUIDs':	dbus.Array([SERVICE_UUID], signature='s'),
			}
			if self.with_name:
				Properties['LocalName']	= dbus.String('BackupBox')
			return(Properties)

		@dbus.service.method(ADV_IFACE)
		def Release(self):
			pass

	class Agent(dbus.service.Object):
		# pairing: the code is shown on the box's display, typed on the phone
		def __init__(self):
			self.path	= '/org/backupbox/agent'
			dbus.service.Object.__init__(self, bus, self.path)

		@dbus.service.method(AGENT_IFACE)
		def Release(self):
			pass

		@dbus.service.method(AGENT_IFACE, in_signature='os')
		def AuthorizeService(self, device, uuid):
			return

		@dbus.service.method(AGENT_IFACE, in_signature='ouq')
		def DisplayPasskey(self, device, passkey, entered):
			if entered == 0:
				Code	= f'{int(passkey):06d}'
				print(f'pairing code {Code} for {device}', flush=True)
				display.message([
					'set:clear,temp,time=40',
					f":{text('box_ble_pairing', 'Bluetooth pairing')}",
					f":{text('box_ble_code', 'Code for the phone:')}",
					f's=hc:{Code[:3]} {Code[3:]}',
				], logging=False)

		@dbus.service.method(AGENT_IFACE, in_signature='os')
		def DisplayPinCode(self, device, pincode):
			self.DisplayPasskey(device, int(pincode) if pincode.isdigit() else 0, 0)

		@dbus.service.method(AGENT_IFACE, in_signature='o', out_signature='s')
		def RequestPinCode(self, device):
			raise Rejected('no PIN')

		@dbus.service.method(AGENT_IFACE, in_signature='o', out_signature='u')
		def RequestPasskey(self, device):
			raise Rejected('no passkey input')

		@dbus.service.method(AGENT_IFACE, in_signature='ou')
		def RequestConfirmation(self, device, passkey):
			# numeric comparison: the phone shows the same code, the display shows it too
			self.DisplayPasskey(device, passkey, 0)

		@dbus.service.method(AGENT_IFACE, in_signature='o')
		def RequestAuthorization(self, device):
			# "just works" without a code is not accepted
			raise Rejected('pairing needs the code')

		@dbus.service.method(AGENT_IFACE)
		def Cancel(self):
			pass

	# the first Bluetooth adapter that can do GATT and advertising
	Objects	= dbus.Interface(bus.get_object(BLUEZ, '/'), OM_IFACE).GetManagedObjects()
	AdapterPath	= next((Path for Path, Interfaces in Objects.items() if GATT_MANAGER_IFACE in Interfaces and ADV_MANAGER_IFACE in Interfaces), None)
	if AdapterPath is None:
		print('no Bluetooth LE adapter', file=sys.stderr, flush=True)
		sys.exit(1)

	AdapterObject	= bus.get_object(BLUEZ, AdapterPath)
	AdapterProps	= dbus.Interface(AdapterObject, PROP_IFACE)
	AdapterProps.Set(ADAPTER_IFACE, 'Powered', dbus.Boolean(True))
	AdapterProps.Set(ADAPTER_IFACE, 'Alias', dbus.String('Backup Box'))
	AdapterProps.Set(ADAPTER_IFACE, 'Pairable', dbus.Boolean(True))
	AdapterProps.Set(ADAPTER_IFACE, 'PairableTimeout', dbus.UInt32(0))

	agent	= Agent()
	AgentManager	= dbus.Interface(bus.get_object(BLUEZ, '/org/bluez'), AGENT_MANAGER_IFACE)
	AgentManager.RegisterAgent(agent.path, 'DisplayOnly')
	AgentManager.RequestDefaultAgent(agent.path)

	app		= Application()
	service	= Service(app, 0)
	status	= StatusCharacteristic(service)
	service.characteristics	= [status, CommandCharacteristic(service, status), WifiCharacteristic(service)]
	app.services	= [service]

	def failed(error):
		print(f'Bluetooth: {error}', file=sys.stderr, flush=True)
		loop.quit()

	dbus.Interface(AdapterObject, GATT_MANAGER_IFACE).RegisterApplication(app.path, {},
		reply_handler=lambda: print('GATT service registered', flush=True),
		error_handler=failed)

	AdvManager	= dbus.Interface(AdapterObject, ADV_MANAGER_IFACE)

	def advertise(with_name):
		advertisement	= Advertisement(with_name)
		def registered():
			print(f'advertising{" as BackupBox" if with_name else ""}', flush=True)
		def refused(error):
			# the name does not fit next to the 128-bit service UUID on some controllers: without it
			advertisement.remove_from_connection()
			if with_name:
				advertise(False)
			else:
				failed(error)
		AdvManager.RegisterAdvertisement(advertisement.path, {}, reply_handler=registered, error_handler=refused)

	advertise(True)

	# bluetoothd restarted: everything registered is gone, systemd starts this service again
	def owner_changed(name, old, new):
		if name == BLUEZ and not new:
			print('bluetoothd stopped', file=sys.stderr, flush=True)
			loop.quit()
	bus.add_signal_receiver(owner_changed, 'NameOwnerChanged', 'org.freedesktop.DBus', 'org.freedesktop.DBus', '/org/freedesktop/DBus')

	loop.run()
	sys.exit(1)

if __name__ == '__main__':
	if len(sys.argv) > 1 and sys.argv[1] == '--status':
		# check from the command line
		State	= box_state()
		print(json.dumps(State.status(), indent=1))
		print(json.dumps({k: (v if k != 'pw' else '***') for k, v in State.wifi().items()}, indent=1))
	else:
		run_service()
