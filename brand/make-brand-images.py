# Builds the brand images of the box from the Your Wedding Saga logo (white on transparent):
#   scripts/img/brand/logo-*.png, mark-*.png   logo with and without its text, white / black / sage
#   scripts/img/icons/favicon-*.png, favicon.ico   app icons (white wreath on sage #a9af90)
#   scripts/img/boot-mono.png   boot greeting for 128x64 monochrome displays (Waveshare 1.3" OLED)
#   scripts/img/boot-color.png   boot greeting for colour displays
# Usage (from the scripts folder): python3 ../brand/make-brand-images.py ../brand/yws-logo-white.webp .
import sys
from PIL import Image, ImageDraw, ImageFont, ImageOps

src, out = sys.argv[1], sys.argv[2]
SAGE = (0xa9, 0xaf, 0x90)
BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'

logo = Image.open(src).convert('RGBA')
logo = logo.crop(logo.split()[3].getbbox())          # full logo with "Your Wedding Saga"
w, h = logo.size
mark = logo.crop((0, 0, w, 1627 - 21))                # wreath with YWS only (text starts below)
mark = mark.crop(mark.split()[3].getbbox())

def colored(img, rgb):
	alpha = img.split()[3]
	flat = Image.new('RGBA', img.size, rgb + (255,))
	flat.putalpha(alpha)
	return flat

def fit(img, size):
	img = img.copy()
	img.thumbnail(size, Image.LANCZOS)
	return img

# logos for the web pages (white for dark backgrounds, black for light ones)
for name, rgb in (('white', (255, 255, 255)), ('black', (0, 0, 0)), ('sage', SAGE)):
	fit(colored(logo, rgb), (800, 800)).save(f'{out}/img/brand/logo-{name}.png', optimize=True)
	fit(colored(mark, rgb), (512, 512)).save(f'{out}/img/brand/mark-{name}.png', optimize=True)

# app icons: white wreath on sage
def icon(size, pad=0.14):
	canvas = Image.new('RGBA', (size, size), SAGE + (255,))
	m = fit(colored(mark, (255, 255, 255)), (int(size * (1 - 2 * pad)),) * 2)
	canvas.alpha_composite(m, ((size - m.width) // 2, (size - m.height) // 2))
	return canvas

for size in (180, 192, 512):
	icon(size).save(f'{out}/img/icons/favicon-{size}.png', optimize=True)
icon(256, 0.10).save(f'{out}/favicon.ico', sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])

# boot greeting of the displays: logo + BACKUP BOX
def boot(width, height, text_rgb, mono):
	canvas = Image.new('RGB', (width, height), (0, 0, 0))
	m = fit(colored(mark, (255, 255, 255)), (int(height * 0.94),) * 2)
	x = int(height * 0.04)
	y = (height - m.height) // 2
	canvas.paste(m, (x, y), m)
	draw = ImageDraw.Draw(canvas)
	if mono:
		# the thin YWS letters are below one pixel on a 128x64 display: drawn again in bold at their place
		box = (x + m.width * 0.25, y + m.height * 0.28, x + m.width * 0.75, y + m.height * 0.58)
		draw.rectangle(box, fill=(0, 0, 0))
		fsize = int(m.height * 0.24)
		f = ImageFont.truetype(BOLD, fsize)
		while draw.textlength('YWS', font=f) > (box[2] - box[0]) and fsize > 6:
			fsize -= 1
			f = ImageFont.truetype(BOLD, fsize)
		draw.text(((box[0] + box[2]) / 2, (box[1] + box[3]) / 2), 'YWS', font=f, fill=(255, 255, 255), anchor='mm')
	left = x + m.width + int(height * 0.08)
	room = width - left - int(height * 0.04)
	size = int(height * 0.36)
	while size > 6:
		font = ImageFont.truetype(BOLD, size)
		if max(draw.textlength(t, font=font) for t in ('BACKUP', 'BOX')) <= room:
			break
		size -= 1
	line = size * 1.12
	top = (height - 2 * line) / 2
	for i, t in enumerate(('BACKUP', 'BOX')):
		draw.text((left, top + i * line), t, font=font, fill=text_rgb)
	if mono:
		canvas = canvas.convert('L').point(lambda v: 255 if v > 100 else 0).convert('1')
	return canvas

boot(128, 64, (255, 255, 255), True).save(f'{out}/img/boot-mono.png')
boot(640, 320, SAGE, False).save(f'{out}/img/boot-color.png', optimize=True)
print('ok')
