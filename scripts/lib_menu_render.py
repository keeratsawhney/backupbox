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

# Draws the screens of the display menu (displaymenu.py) as images:
# lists with icons and a selection bar, confirm dialogs, info pages and short messages.
# Sizes follow the display: 128x64 monochrome OLEDs up to colour LCDs.

import os

from PIL import Image, ImageDraw, ImageFont

# 8x8 icons, '#' = pixel
ICONS	= {
	'backup': [
		'..####..',
		'.#....#.',
		'#......#',
		'#......#',
		'#....#.#',
		'.#...##.',
		'..####..',
		'.....#..',
	],
	'play': [
		'.#......',
		'.##.....',
		'.###....',
		'.####...',
		'.####...',
		'.###....',
		'.##.....',
		'.#......',
	],
	'stop': [
		'........',
		'.######.',
		'.######.',
		'.######.',
		'.######.',
		'.######.',
		'.######.',
		'........',
	],
	'drive': [
		'........',
		'########',
		'#......#',
		'#......#',
		'#.##.#.#',
		'#......#',
		'########',
		'........',
	],
	'card': [
		'.#####..',
		'#.#.#.#.',
		'#.#.#.##',
		'#......#',
		'#......#',
		'#......#',
		'#......#',
		'########',
	],
	'camera': [
		'..###...',
		'########',
		'#......#',
		'#..##..#',
		'#.#..#.#',
		'#..##..#',
		'#......#',
		'########',
	],
	'cloud': [
		'........',
		'...##...',
		'..#..##.',
		'.#.....#',
		'#......#',
		'#......#',
		'.######.',
		'........',
	],
	'status': [
		'......#.',
		'......#.',
		'....#.#.',
		'....#.#.',
		'..#.#.#.',
		'..#.#.#.',
		'#.#.#.#.',
		'#.#.#.#.',
	],
	'wifi': [
		'........',
		'.######.',
		'#......#',
		'..####..',
		'.#....#.',
		'...##...',
		'........',
		'...##...',
	],
	'qr': [
		'###.#.##',
		'#.#..#.#',
		'###.#.##',
		'....#...',
		'#.##.###',
		'.#..#...',
		'##.##.#.',
		'#.#.#.##',
	],
	'power': [
		'...##...',
		'.#.##.#.',
		'#..##..#',
		'#..##..#',
		'#......#',
		'#......#',
		'.#....#.',
		'..####..',
	],
	'reboot': [
		'..###.#.',
		'.#...##.',
		'#...###.',
		'#.......',
		'#......#',
		'#......#',
		'.#....#.',
		'..####..',
	],
	'display': [
		'########',
		'#......#',
		'#......#',
		'#......#',
		'#......#',
		'########',
		'..#..#..',
		'.######.',
	],
	'reset': [
		'........',
		'.#....#.',
		'..#..#..',
		'...##...',
		'...##...',
		'..#..#..',
		'.#....#.',
		'........',
	],
	'vpn': [
		'..####..',
		'.#....#.',
		'.#....#.',
		'########',
		'###..###',
		'###..###',
		'########',
		'........',
	],
	'ip': [
		'..####..',
		'.#.##.#.',
		'#..##..#',
		'########',
		'#..##..#',
		'#..##..#',
		'.#.##.#.',
		'..####..',
	],
	'temp': [
		'...##...',
		'..#..#..',
		'..#..#..',
		'..#..#..',
		'..####..',
		'.######.',
		'.######.',
		'..####..',
	],
	'bolt': [
		'....##..',
		'...##...',
		'..##....',
		'.######.',
		'....##..',
		'...##...',
		'..##....',
		'.#......',
	],
	'check': [
		'........',
		'.......#',
		'......##',
		'#....##.',
		'##..##..',
		'.####...',
		'..##....',
		'........',
	],
	'warn': [
		'...##...',
		'...##...',
		'..#..#..',
		'..#..#..',
		'.#.##.#.',
		'.#....#.',
		'#..##..#',
		'########',
	],
	'right': [
		'........',
		'.#......',
		'..#.....',
		'...#....',
		'..#.....',
		'.#......',
		'........',
		'........',
	],
}

class renderer(object):

	def __init__(self, width, height, mode, color_text, color_high, color_bg, color_alert, font_path):
		self.width		= width
		self.height		= height
		self.mode		= mode
		self.fg			= color_text
		self.high		= color_high
		self.bg			= color_bg
		self.alert		= color_alert

		# sizes from the screen height: 64 px (OLED) .. 128+ px (LCD)
		self.scale		= max(1, int(height / 64))
		size			= 10 if height < 96 else 13 if height < 160 else 16
		bold_path		= font_path.replace('DejaVuSans.ttf', 'DejaVuSans-Bold.ttf')
		self.font		= ImageFont.truetype(font_path, size)
		self.font_small	= ImageFont.truetype(font_path, size - 2)
		self.font_bold	= ImageFont.truetype(bold_path if os.path.isfile(bold_path) else font_path, size - 1)
		self.font_big	= ImageFont.truetype(bold_path if os.path.isfile(bold_path) else font_path, size + 2)

		self.icon_px	= self.scale
		self.head_h		= self.text_h(self.font_bold) + 3
		self.row_h		= max(12, self.text_h(self.font) + 3)
		self.rows		= max(1, (self.height - self.head_h - 1) // self.row_h)

	# ---------- basics ----------

	def text_h(self, font):
		left, top, right, bottom	= font.getbbox('Ag')
		return(bottom - top)

	def text_w(self, text, font):
		left, top, right, bottom	= font.getbbox(text)
		return(right - left)

	def fit(self, text, font, width):
		# shorten with "…" to fit the width
		text	= str(text)
		if self.text_w(text, font) <= width:
			return(text)
		while text and self.text_w(text + '…', font) > width:
			text	= text[:-1]
		return(text.rstrip() + '…')

	def wrap(self, text, font, width, lines):
		# words to lines; "\n" starts a new line
		out		= []
		for paragraph in str(text).split('\n'):
			line	= ''
			for word in paragraph.split():
				test	= (line + ' ' + word).strip()
				if self.text_w(test, font) <= width or not line:
					line	= test
				else:
					out.append(line)
					line	= word
			if line:
				out.append(line)
		if len(out) > lines:
			out	= out[:lines]
			out[-1]	= self.fit(out[-1] + ' …', font, width)
		return([self.fit(line, font, width) for line in out])

	def new(self):
		image	= Image.new(self.mode, (self.width, self.height), self.bg)
		draw	= ImageDraw.Draw(image)
		if self.mode == '1':
			# crisp pixels on monochrome displays
			draw.fontmode	= '1'
		return(image, draw)

	def text(self, draw, xy, text, font, fill):
		left, top, right, bottom	= font.getbbox('Ag')
		draw.text((xy[0], xy[1] - top), text, font=font, fill=fill)

	def icon(self, draw, name, x, y, fill):
		pattern	= ICONS.get(name)
		if not pattern:
			return
		s	= self.icon_px
		for row, line in enumerate(pattern):
			for col, pixel in enumerate(line):
				if pixel == '#':
					draw.rectangle((x + col * s, y + row * s, x + col * s + s - 1, y + row * s + s - 1), fill=fill)

	def header(self, draw, title, right=''):
		w_right	= self.text_w(right, self.font_small) + 4 if right else 0
		self.text(draw, (2, 1), self.fit(title, self.font_bold, self.width - 4 - w_right), self.font_bold, self.high)
		if right:
			self.text(draw, (self.width - w_right + 2, 2), right, self.font_small, self.fg)
		draw.line((0, self.head_h - 1, self.width - 1, self.head_h - 1), fill=self.fg)

	def scrollbar(self, draw, first, visible, total, top):
		if total <= visible:
			return
		track_h	= self.height - top - 1
		thumb_h	= max(4, int(track_h * visible / total))
		thumb_y	= top + int((track_h - thumb_h) * first / max(1, total - visible))
		x		= self.width - 2
		for y in range(top, self.height, 2):
			draw.point((x, y), fill=self.fg)
		draw.rectangle((x - 1, thumb_y, x, thumb_y + thumb_h), fill=self.fg)

	# ---------- screens ----------

	def list(self, title, items, pos, first):
		# items: dicts with label, icon, submenu (bool), dim (bool)
		image, draw	= self.new()
		self.header(draw, title, f'{pos + 1}/{len(items)}' if len(items) > 1 else '')
		top			= self.head_h + 1
		scrolling	= len(items) > self.rows
		right_edge	= self.width - (4 if scrolling else 1)
		icon_w		= 8 * self.icon_px
		for n, item in enumerate(items[first:first + self.rows]):
			index		= first + n
			y			= top + n * self.row_h
			selected	= index == pos
			fg			= self.bg if selected else self.fg
			if selected:
				draw.rounded_rectangle((0, y, right_edge, y + self.row_h - 2), radius=3, fill=self.high)
			icon_y	= y + (self.row_h - 1 - icon_w) // 2
			self.icon(draw, item.get('icon'), 3, icon_y, fg)
			text_x	= 3 + icon_w + 4
			text_w	= right_edge - text_x - (8 if item.get('submenu') else 2)
			text_y	= y + (self.row_h - 1 - self.text_h(self.font)) // 2
			self.text(draw, (text_x, text_y), self.fit(item.get('label', ''), self.font, text_w), self.font, fg)
			if item.get('submenu'):
				self.icon(draw, 'right', right_edge - 7, icon_y, fg)
		self.scrollbar(draw, first, self.rows, len(items), top)
		return(image)

	def confirm(self, title, text, ok_label, cancel_label, choice, danger=False):
		image, draw	= self.new()
		self.header(draw, title)
		btn_h		= self.row_h + 1
		body_top	= self.head_h + 2
		body_bottom	= self.height - btn_h - 2
		lines		= self.wrap(text, self.font, self.width - 6, max(1, (body_bottom - body_top) // (self.text_h(self.font) + 2)))
		y			= body_top + max(0, (body_bottom - body_top - len(lines) * (self.text_h(self.font) + 2)) // 2)
		if danger:
			self.icon(draw, 'warn', self.width - 8 * self.icon_px - 2, 1, self.alert)
		for line in lines:
			self.text(draw, ((self.width - self.text_w(line, self.font)) // 2, y), line, self.font, self.fg)
			y	+= self.text_h(self.font) + 2

		# two buttons: cancel (0), ok (1)
		gap		= 4
		btn_w	= (self.width - 3 * gap) // 2
		btn_y	= self.height - btn_h - 1
		for index, label in enumerate([cancel_label, ok_label]):
			x			= gap + index * (btn_w + gap)
			selected	= index == choice
			if selected:
				draw.rounded_rectangle((x, btn_y, x + btn_w, btn_y + btn_h), radius=4, fill=self.high)
			else:
				draw.rounded_rectangle((x, btn_y, x + btn_w, btn_y + btn_h), radius=4, outline=self.fg)
			label	= self.fit(label, self.font_bold, btn_w - 4)
			self.text(draw, (x + (btn_w - self.text_w(label, self.font_bold)) // 2 + 1, btn_y + (btn_h - self.text_h(self.font_bold)) // 2), label, self.font_bold, self.bg if selected else self.fg)
		return(image)

	def page(self, title, rows, first):
		# rows: dicts with icon, label, value, bar (0..1 or None), alert (bool)
		image, draw	= self.new()
		self.header(draw, title)
		top			= self.head_h + 2
		row_h		= self.row_h + 1
		visible		= max(1, (self.height - top) // row_h)
		scrolling	= len(rows) > visible
		right_edge	= self.width - (5 if scrolling else 2)
		icon_w		= 8 * self.icon_px
		for n, row in enumerate(rows[first:first + visible]):
			y		= top + n * row_h
			fg		= self.alert if row.get('alert') else self.fg
			self.icon(draw, row.get('icon'), 2, y + 1, fg)
			text_x	= 2 + icon_w + 3
			value	= str(row.get('value', ''))
			value_w	= self.text_w(value, self.font_small)
			label_w	= right_edge - text_x - value_w - 4
			self.text(draw, (text_x, y), self.fit(row.get('label', ''), self.font_small, max(10, label_w)), self.font_small, fg)
			self.text(draw, (right_edge - value_w, y), value, self.font_small, self.high if not row.get('alert') else self.alert)
			if row.get('bar') is not None:
				# used space: solid part, the free rest dotted
				bar_y	= y + self.text_h(self.font_small) + 2
				fill_w	= int((right_edge - text_x) * min(1, max(0, row['bar'])))
				for x in range(text_x + fill_w, right_edge + 1, 2):
					draw.point((x, bar_y + 1), fill=self.fg)
				if fill_w > 0:
					draw.rectangle((text_x, bar_y, text_x + fill_w, bar_y + 1), fill=self.high)
		self.scrollbar(draw, first, visible, len(rows), top)
		return(image)

	def page_rows(self):
		return(max(1, (self.height - self.head_h - 2) // (self.row_h + 1)))

	def message(self, icon, text, alert=False):
		image, draw	= self.new()
		fg		= self.alert if alert else self.high
		size	= 8 * self.icon_px * 2
		# icon drawn at double size
		pattern	= ICONS.get(icon, [])
		s		= self.icon_px * 2
		x0		= (self.width - size) // 2
		y0		= 4
		for row, line in enumerate(pattern):
			for col, pixel in enumerate(line):
				if pixel == '#':
					draw.rectangle((x0 + col * s, y0 + row * s, x0 + col * s + s - 1, y0 + row * s + s - 1), fill=fg)
		lines	= self.wrap(text, self.font_bold, self.width - 6, 2)
		y		= y0 + size + 4
		for line in lines:
			self.text(draw, ((self.width - self.text_w(line, self.font_bold)) // 2, y), line, self.font_bold, self.fg)
			y	+= self.text_h(self.font_bold) + 2
		return(image)

	def home(self, title, state, ip, network, online, drive, temp, no_drive='No drive'):
		# the box's home screen (instead of text lines and the status bar):
		#   title bar with the temperature, the state, IP and network state, the backup drive with a fill bar
		# drive: dict name, used (0..1), size (text) or None
		image, draw	= self.new()
		self.header(draw, title, f'{temp:.0f}°C' if temp is not None else '')

		# state, large, with a check mark
		icon_w	= 8 * self.icon_px
		y		= self.head_h + 2
		big_h	= self.text_h(self.font_big)
		self.icon(draw, 'check', 2, y + (big_h - icon_w) // 2, self.high)
		self.text(draw, (2 + icon_w + 4, y), self.fit(state, self.font_big, self.width - icon_w - 8), self.font_big, self.high)
		y	+= big_h + 3

		# network: IP and online / offline
		small_h	= self.text_h(self.font_small)
		self.icon(draw, 'wifi' if online else 'ip', 2, y + (small_h - icon_w) // 2, self.fg)
		network_w	= self.text_w(network, self.font_small) if network else 0
		self.text(draw, (2 + icon_w + 4, y), self.fit(ip or network, self.font_small, self.width - icon_w - network_w - 12), self.font_small, self.fg)
		if network and ip:
			self.text(draw, (self.width - network_w - 2, y), network, self.font_small, self.fg)
		y	+= small_h + 3

		# backup drive: name, fill and size, bar
		if drive:
			self.icon(draw, 'drive', 2, y + (small_h - icon_w) // 2, self.fg)
			value	= f"{100 * drive['used']:.0f}% · {drive['size']}" if drive.get('used') is not None else drive['size']
			value_w	= self.text_w(value, self.font_small)
			self.text(draw, (2 + icon_w + 4, y), self.fit(drive['name'], self.font_small, self.width - icon_w - value_w - 12), self.font_small, self.fg)
			self.text(draw, (self.width - value_w - 2, y), value, self.font_small, self.high)
			if drive.get('used') is None:
				return(image)
			bar_y	= min(self.height - 2, y + small_h + 2)
			x0, x1	= 2 + icon_w + 4, self.width - 3
			fill_w	= int((x1 - x0) * min(1, max(0, drive['used'])))
			for x in range(x0 + fill_w, x1 + 1, 2):
				draw.point((x, bar_y + 1), fill=self.fg)
			if fill_w > 0:
				draw.rectangle((x0, bar_y, x0 + fill_w, bar_y + 1), fill=self.high)
		else:
			self.icon(draw, 'drive', 2, y + (small_h - icon_w) // 2, self.fg)
			self.text(draw, (2 + icon_w + 4, y), no_drive, self.font_small, self.fg)
		return(image)

	def screen(self, title, lines, temp, task=''):
		# any other message (comitup, backup progress, power off ...) under the same title bar as the home screen
		# lines: dicts text, style ('h' message, 'b' earlier lines, 'a' alert, 'hc' highlighted), progress (0..100 or None)
		image, draw	= self.new()
		self.header(draw, title, f'{temp:.0f}°C' if temp is not None else '')

		bottom	= self.height
		if task:
			# what a running task does, at the bottom
			small_h	= self.text_h(self.font_small)
			bottom	= self.height - small_h - 3
			draw.line((0, bottom, self.width - 1, bottom), fill=self.fg)
			self.text(draw, (2, bottom + 2), self.fit(task, self.font_small, self.width - 4), self.font_small, self.fg)

		y	= self.head_h + 1
		for line in lines:
			style	= line.get('style', 'h')
			font	= self.font_small if style == 'b' else self.font
			row_h	= self.text_h(font) + 3
			if y + row_h > bottom + 1:
				break

			if line.get('progress') is not None:
				# progress bar with the percentage
				percent	= line['progress']
				label	= f'{percent:.0f}%' if percent >= 10 else f'{percent:.1f}%'
				label_w	= self.text_w('100%', self.font)
				self.text(draw, (2, y + 1), label, self.font, self.high)
				x0, x1	= label_w + 6, self.width - 3
				draw.rounded_rectangle((x0, y + 2, x1, y + row_h - 3), radius=2, outline=self.fg)
				fill_w	= int((x1 - x0 - 2) * min(100, max(0, percent)) / 100)
				if fill_w > 0:
					draw.rectangle((x0 + 1, y + 3, x0 + 1 + fill_w, y + row_h - 4), fill=self.high)
			elif style == 'a':
				# alert: inverted row
				draw.rounded_rectangle((0, y, self.width - 1, y + row_h - 1), radius=3, fill=self.alert)
				self.text(draw, (3, y + 1), self.fit(line['text'], font, self.width - 6), font, self.bg)
			else:
				fill	= self.high if style in ['h', 'hc'] else self.fg
				self.text(draw, (2, y + 1), self.fit(line['text'], self.font_bold if style == 'hc' else font, self.width - 4), self.font_bold if style == 'hc' else font, fill)
			y	+= row_h
		return(image)
