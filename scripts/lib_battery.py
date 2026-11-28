#!/usr/bin/env python3

# Battery monitor with an INA231 (or INA226 / INA230 / INA219) current / power module (I2C).
#
# The module sits in the battery line. It measures the voltage and, over its shunt resistor, the current in both
# directions. From the voltage per cell the charge is estimated (Li-ion curve), the sign of the current says charging or
# discharging, and with the capacity of the pack the time left can be estimated.
#
# INA231 / INA226 / INA230 (TI, 16 bit, up to 28 V, shunt up to +-81.92 mV) and INA219 (12 bit, +-320 mV) are recognised by
# themselves: the first three have an ID register (0xFE = 0x5449).
#
# lbb-battery.service runs "lib_battery.py --daemon": every 2 s it reads the sensor and writes /run/lbb-battery.json,
# which the touch UI (touch-api.php) and the OLED display (display.py) read. No sensor: nothing is written.
#
# Optional settings in data/battery.json (next to the web files); everything has a default:
#   address            I2C address (default: looks at 0x40 .. 0x4F)
#   cells              cells in series, 1 .. 4 (default 0: from the voltage)
#   shunt_ohm          shunt resistor of the module, as printed on it: R100 = 0.1, R010 = 0.01, R002 = 0.002 ohm
#                      (default: 0.01 for an INA231 / INA226, 0.1 for an INA219). It sets the largest current:
#                      81.92 mV / R for an INA231 (8.2 A at 0.01 ohm), 320 mV / R for an INA219
#   capacity_mah       capacity of the pack in mAh (default 0: no time left shown)
#   invert_current     true (default): the battery is on VIN+ of the module, current out of the battery shows as
#                      discharging. false: the battery is wired to VIN-.
#   internal_mohm      internal resistance of the pack, corrects the voltage under load (default 0)
#   shutdown_percent   shut the box down below this charge while discharging (default 0: never)
#
# Test: python3 lib_battery.py --once        (one reading as JSON)

import json
import os
import statistics
import subprocess
import sys
import time

STATE_FILE		= '/run/lbb-battery.json'
SETTINGS_FILE	= os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data', 'battery.json')
ADDRESSES		= list(range(0x40, 0x50))

# open circuit voltage of one Li-ion cell -> charge in percent
CURVE = [
	(3.27, 0), (3.61, 5), (3.69, 10), (3.71, 15), (3.73, 20), (3.75, 25), (3.77, 30), (3.79, 35), (3.80, 40),
	(3.82, 45), (3.84, 50), (3.85, 55), (3.87, 60), (3.91, 65), (3.95, 70), (3.98, 75), (4.02, 80), (4.08, 85),
	(4.11, 90), (4.15, 95), (4.20, 100),
]

DEFAULTS = {
	'address': 0, 'cells': 0, 'shunt_ohm': 0, 'capacity_mah': 0, 'invert_current': True, 'internal_mohm': 0,
	'shutdown_percent': 0,
}


def load_settings(path=SETTINGS_FILE):
	settings = dict(DEFAULTS)
	try:
		with open(path) as f:
			data = json.load(f)
		for key in DEFAULTS:
			if key in data:
				settings[key] = data[key]
	except (OSError, ValueError):
		pass
	return settings


def percent_of_cell(volt):
	if volt <= CURVE[0][0]:
		return 0.0
	if volt >= CURVE[-1][0]:
		return 100.0
	for (v1, p1), (v2, p2) in zip(CURVE, CURVE[1:]):
		if v1 <= volt <= v2:
			return p1 + (p2 - p1) * (volt - v1) / (v2 - v1)
	return 0.0


def guess_cells(volt):
	# the smallest number of cells that explains the voltage (a 3S pack at 12.4 V is not told from a 4S one: set "cells")
	for cells in (1, 2, 3, 4):
		if 3.0 * cells <= volt <= 4.25 * cells:
			return cells
	return 4 if volt > 17 else 1


def estimate(volt, amps, settings):
	# amps: positive = charging. Returns the values shown by the touch UI and the display.
	cells = int(settings.get('cells') or 0) or guess_cells(volt)
	cells = max(1, min(4, cells))
	charging = amps > 0.05
	discharging = amps < -0.05
	# under load the voltage sags (and rises while charging): corrected with the internal resistance, if it is known
	corrected = volt + (-amps) * float(settings.get('internal_mohm') or 0) / 1000.0
	percent = percent_of_cell(corrected / cells)
	minutes = None
	capacity = float(settings.get('capacity_mah') or 0)
	if capacity > 0 and abs(amps) > 0.05:
		if discharging:
			minutes = capacity * percent / 100.0 / (abs(amps) * 1000.0) * 60.0
		elif charging:
			minutes = capacity * (100.0 - percent) / 100.0 / (amps * 1000.0) * 60.0
	return {
		'cells': cells,
		'percent': round(percent),
		'state': 'charging' if charging else ('discharging' if discharging else 'idle'),
		'minutes': None if minutes is None else int(min(minutes, 99 * 60)),
	}


class ina_chip(object):
	# INA219: bus voltage 4 mV per step (bits 15:3), shunt 10 uV per step, shunt up to +-320 mV
	# INA231 / INA226 / INA230: bus voltage 1.25 mV per step, shunt 2.5 uV per step, shunt up to +-81.92 mV
	REG_SHUNT = 0x01
	REG_BUS = 0x02
	REG_MANUFACTURER = 0xFE

	def __init__(self, bus, address, family):
		self.bus = bus
		self.address = address
		self.family = family			# 'ina231' or 'ina219'
		self.name = 'INA231' if family == 'ina231' else 'INA219'
		self.default_shunt = 0.01 if family == 'ina231' else 0.1

	def _word(self, register):
		raw = self.bus.read_word_data(self.address, register)
		return ((raw & 0xFF) << 8) | (raw >> 8)		# the chip sends the high byte first

	def bus_volt(self):
		if self.family == 'ina231':
			return self._word(self.REG_BUS) * 0.00125
		return (self._word(self.REG_BUS) >> 3) * 0.004

	def shunt_volt(self):
		raw = self._word(self.REG_SHUNT)
		if raw & 0x8000:
			raw -= 0x10000
		return raw * (2.5e-6 if self.family == 'ina231' else 10e-6)


def open_bus():
	try:
		import smbus2 as smbus
	except ImportError:
		import smbus
	return smbus.SMBus(1)


def find_sensor(bus, settings):
	addresses = [int(settings['address'])] if settings.get('address') else ADDRESSES
	for address in addresses:
		try:
			# the INA231 family answers the manufacturer ID "TI" (0x5449); the INA219 has no ID register
			family = 'ina231' if ina_chip(bus, address, 'ina231')._word(ina_chip.REG_MANUFACTURER) == 0x5449 else 'ina219'
			chip = ina_chip(bus, address, family)
			volt = chip.bus_volt()
		except OSError:
			continue
		# a plausible battery voltage (other chips answer at some of these addresses too)
		if 2.5 <= volt <= 28:
			return chip
	return None


def read_once(chip, settings):
	volt = chip.bus_volt()
	# at the chip a positive current flows from VIN+ to VIN-: with the battery on VIN+ that is the battery discharging.
	# Here a positive current is the battery charging.
	amps = chip.shunt_volt() / (float(settings['shunt_ohm']) or chip.default_shunt)
	if settings.get('invert_current'):
		amps = -amps
	return volt, amps


def write_state(data, path=STATE_FILE):
	temporary = path + '.tmp'
	with open(temporary, 'w') as f:
		json.dump(data, f)
	os.chmod(temporary, 0o644)
	os.replace(temporary, path)


def daemon():
	chip = None
	bus = None
	volts, amps_list = [], []
	while True:
		settings = load_settings()
		try:
			if chip is None:
				bus = bus or open_bus()
				chip = find_sensor(bus, settings)
				if chip is None:
					# no sensor (or I2C not on yet): look again later, nothing is written
					try:
						os.remove(STATE_FILE)
					except OSError:
						pass
					time.sleep(30)
					continue
			volt, amps = read_once(chip, settings)
			# the median of the last readings: a short peak does not move the charge
			volts = (volts + [volt])[-7:]
			amps_list = (amps_list + [amps])[-3:]
			volt_smooth = statistics.median(volts)
			amps_smooth = sum(amps_list) / len(amps_list)
			data = estimate(volt_smooth, amps_smooth, settings)
			data.update({'time': int(time.time()), 'voltage': round(volt_smooth, 2), 'current': round(amps_smooth, 3), 'power': round(volt_smooth * abs(amps_smooth), 2)})
			write_state(data)

			shutdown = int(settings.get('shutdown_percent') or 0)
			if shutdown and data['state'] == 'discharging' and data['percent'] <= shutdown and len(volts) >= 5:
				print(f"battery at {data['percent']} %: shutting down", flush=True)
				subprocess.run(['systemctl', 'poweroff'], check=False)
				time.sleep(60)
		except OSError as e:
			print(f'I2C: {e}', flush=True)
			chip = None
			time.sleep(5)
			continue
		time.sleep(2)


if __name__ == '__main__':
	if '--daemon' in sys.argv:
		daemon()
	elif '--once' in sys.argv:
		settings = load_settings()
		sensor = find_sensor(open_bus(), settings)
		if sensor is None:
			print('no INA219 found (I2C on? address 0x40 .. 0x45)')
			sys.exit(1)
		volt, amps = read_once(sensor, settings)
		result = estimate(volt, amps, settings)
		result.update({'sensor': sensor.name, 'address': hex(sensor.address), 'voltage': round(volt, 3), 'current': round(amps, 3), 'power': round(volt * abs(amps), 2)})
		print(json.dumps(result, indent=1))
	else:
		print(__doc__)
