#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""OGP 1200×630（ライト・中央寄せ・マスコット。数字は焼き込まない）。 /usr/bin/python3 scripts/make_ogp.py"""
import os
from PIL import Image, ImageDraw, ImageFont
W, H = 1200, 630
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "outputs", "khokan.png")
MASCOT = "/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png"
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"; FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
img = Image.new("RGB", (W, H), "#ffffff"); dr = ImageDraw.Draw(img, "RGBA")
dr.ellipse([-180, -240, 480, 380], fill=(230, 244, 242, 255)); dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(240, 246, 246, 255))
f = lambda p, s: ImageFont.truetype(p, s)
def center(y, text, font, fill):
    w = dr.textlength(text, font=font); dr.text(((W - w) / 2, y), text, font=font, fill=fill)
center(150, "Kurage 訪問看護ナビ", f(FB, 72), "#12202f")
center(250, "訪問看護ステーションを名前・住所から探す", f(FR, 38), "#0a9a8f")
center(310, "同じ名前の別の事業所を住所で見分ける／医療保険か介護保険かの引き表", f(FR, 26), "#5b6b70")
m = Image.open(MASCOT).convert("RGBA"); m = m.resize((int(m.width * 220 / m.height), 220))
img.paste(m, ((W - m.width) // 2, H - m.height - 30), m)
os.makedirs(os.path.dirname(OUT), exist_ok=True); img.save(OUT, optimize=True); print(OUT, os.path.getsize(OUT), "bytes")
