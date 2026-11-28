#!/usr/bin/env python3

# Fast preview of a photo, the way a file manager does it: no full decode of the picture.
#  - JPEG (and PNG, WebP, TIFF): JPEG draft mode decodes at 1/2, 1/4 or 1/8 size, a 24 MP photo takes a few 10 ms
#  - RAW: the preview the camera embedded in the file (PreviewImage / JpgFromRaw / ThumbnailImage), by exiftool
# Usage: lib_thumb_fast.py <source> <destination.jpg> <size> <quality>
# Exit code 0: destination written. Anything else: the caller falls back to ImageMagick.

import io
import os
import subprocess
import sys

from PIL import Image, ImageOps

RAW_EXTENSIONS = {
	'3fr', 'arq', 'arw', 'crm', 'dcr', 'dng', 'cr2', 'cr3', 'crw', 'erf', 'fff', 'gpr', 'iiq', 'j6i', 'k25', 'kc2', 'kdc',
	'mdc', 'mef', 'mrw', 'nef', 'nrw', 'orf', 'pef', 'raw', 'raf', 'rw2', 'rwl', 'sr2', 'srf', 'srw', 'x3f',
}
WEB_EXTENSIONS = {'jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff'}


def save(image, destination, size, quality):
	image = ImageOps.exif_transpose(image).convert('RGB')
	image.thumbnail((size, size))
	temporary = destination + '.tmp'
	image.save(temporary, 'JPEG', quality=quality)
	os.replace(temporary, destination)


def from_picture(source, destination, size, quality):
	image = Image.open(source)
	if image.format == 'JPEG':
		# decode at a fraction of the size (at least twice the wanted size, so the result stays sharp)
		image.draft('RGB', (size * 2, size * 2))
	save(image, destination, size, quality)


def from_raw(source, destination, size, quality):
	# the picture the camera stored in the raw file; it is not rotated: the raw file says how to turn it
	orientation = subprocess.run(['exiftool', '-n', '-s3', '-Orientation', source], capture_output=True, text=True, timeout=20).stdout.strip()
	degrees = {'3': 180, '6': 270, '8': 90}.get(orientation, 0)
	for tag in ('PreviewImage', 'JpgFromRaw', 'ThumbnailImage'):
		data = subprocess.run(['exiftool', '-b', '-' + tag, source], capture_output=True, timeout=30).stdout
		if not data:
			continue
		image = Image.open(io.BytesIO(data))
		if image.format == 'JPEG':
			image.draft('RGB', (size * 2, size * 2))
		image = image.convert('RGB')
		if degrees:
			image = image.rotate(degrees, expand=True)
		image.thumbnail((size, size))
		temporary = destination + '.tmp'
		image.save(temporary, 'JPEG', quality=quality)
		os.replace(temporary, destination)
		return
	raise ValueError('no embedded preview')


def main():
	source, destination, size, quality = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])
	extension = os.path.splitext(source)[1].lower().lstrip('.')
	if extension in RAW_EXTENSIONS:
		from_raw(source, destination, size, quality)
	elif extension in WEB_EXTENSIONS:
		from_picture(source, destination, size, quality)
	else:
		raise ValueError('not handled here')


if __name__ == '__main__':
	try:
		main()
	except Exception as e:
		print(e, file=sys.stderr)
		sys.exit(1)
