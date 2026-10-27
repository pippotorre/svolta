"""Genera le schermate di avvio per iPhone: icona e nome su fondo blu inchiostro.
Uso: python3 tools/splash.py  (dalla cartella del progetto). Stampa i tag <link> per index.html."""
from PIL import Image, ImageDraw, ImageFont
import os

INK = (19, 41, 75); WHITE = (255, 255, 255); MARK = (255, 228, 92); LINE = (178, 192, 212); FOLD = (205, 215, 229)
OUT = 'www/icons/splash'
FONT = '/usr/share/fonts/opentype/inter/Inter-SemiBold.otf'

# (larghezza CSS, altezza CSS, densità) degli iPhone in verticale
DEVICES = [(440, 956, 3), (430, 932, 3), (428, 926, 3), (414, 896, 3), (414, 896, 2), (402, 874, 3),
           (393, 852, 3), (390, 844, 3), (375, 812, 3), (414, 736, 3), (375, 667, 2)]

def sheet(draw, cx, cy, s):
    w, h = 500 * s, 620 * s
    x0, y0 = cx - w / 2, cy - h / 2; x1, y1 = x0 + w, y0 + h; f = 120 * s
    draw.polygon([(x0, y0), (x1 - f, y0), (x1, y0 + f), (x1, y1), (x0, y1)], fill=WHITE)
    draw.polygon([(x1 - f, y0), (x1 - f, y0 + f), (x1, y0 + f)], fill=FOLD)
    lx0, lx1, lh = x0 + 70 * s, x1 - 70 * s, 34 * s
    ys = [y0 + 150 * s, y0 + 250 * s, y0 + 350 * s, y0 + 450 * s]
    return lx0, lx1, lh, ys

def make(w, h):
    im = Image.new('RGBA', (w, h), INK)
    s = (min(w, h) * 0.30) / 500
    cx, cy = w / 2, h / 2 - 40 * s
    d = ImageDraw.Draw(im)
    lx0, lx1, lh, ys = sheet(d, cx, cy, s)
    hl = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(hl).rounded_rectangle([lx0 - 30 * s, ys[1] - 38 * s, lx1 + 20 * s, ys[1] + lh + 30 * s], radius=26 * s, fill=MARK)
    im.alpha_composite(hl.rotate(4, center=(cx, ys[1]), resample=Image.BICUBIC))
    d = ImageDraw.Draw(im)
    for y, wf in zip(ys, [0.62, 1.0, 0.86, 0.7]):
        d.rounded_rectangle([lx0, y, lx0 + (lx1 - lx0) * wf, y + lh], radius=lh / 2, fill=INK if y == ys[1] else LINE)
    font = ImageFont.truetype(FONT, int(min(w, h) * 0.075))
    text = 'sVolta'
    tw = d.textlength(text, font=font)
    d.text((cx - tw / 2, cy + 310 * s + min(w, h) * 0.06), text, font=font, fill=WHITE)
    return im.convert('RGB')

os.makedirs(OUT, exist_ok=True)
for cw, ch, dpr in DEVICES:
    w, h = cw * dpr, ch * dpr
    name = f'splash-{w}x{h}.png'
    make(w, h).save(os.path.join(OUT, name), optimize=True)
    print(f'  <link rel="apple-touch-startup-image" href="icons/splash/{name}" '
          f'media="(device-width: {cw}px) and (device-height: {ch}px) and (-webkit-device-pixel-ratio: {dpr}) and (orientation: portrait)">')
