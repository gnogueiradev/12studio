#!/usr/bin/env python3
"""Gera os icones do 12studio a partir de uma unica geometria vetorial.

Escreve tres ficheiros em public/:

    favicon.svg           vetor, solido, com regra de prefers-color-scheme
    favicon.ico           16/32/48 com PNG embutido, sobre transparente
    apple-touch-icon.png  180x180 RGB sem alfa, variante de 12 camadas

A marca tambem vive em resources/js/components/app-logo-icon.tsx, que usa o
recorte justo da mesma geometria. Ao mudar os paths aqui, actualizar tambem esse
componente — nao ha nada que os mantenha em sincronia automaticamente.

Requisitos: Python 3.10+, Pillow, e Edge ou Chrome instalado (usado em headless
para rasterizar o SVG). Definir ICON_BROWSER para apontar para outro binario.

    python tools/build-icons.py

Decisoes de desenho em docs/superpowers/specs/2026-08-11-favicon-marca-design.md.
"""
import os
import struct
import subprocess
import sys
import tempfile
from io import BytesIO
from pathlib import Path

from PIL import Image, ImageChops

ROOT = Path(__file__).resolve().parent.parent
PUBLIC = ROOT / "public"

# --- geometria -------------------------------------------------------------
# viewBox de referencia 0 0 64 64; marca em x 4..60, y 13..51.
TOP, BOT = 13.0, 51.0
HEIGHT = BOT - TOP

D1 = "M4 13 H23 V51 H13.5 V20.5 H4 Z"
D2 = (
    "M29 51 H60 V41.5 H48.5 L57.2 37.4 "
    "A15.5 15.5 0 1 0 29 28.5 "
    "H38.5 "
    "A6 6 0 1 1 49.4 31.9 "
    "L29 41.5 Z"
)

GAP_RATIO = 0.22  # folga entre camadas, em fraccao do passo

# --- cores (espelham os tokens de resources/css/app.css) -------------------
INK = "#332F2B"       # --foreground / --primary
INK_DARK = "#FAF8F5"  # --background do tema escuro
TILE = "#F1ECE5"      # --muted / --secondary

ICO_SIZES = [16, 32, 48]
ATI = 180       # lado do apple-touch-icon
ATI_MARK = 148  # marca dentro do azulejo, com margem para os cantos do iOS
ATI_LAYERS = 12


def layer_rects(n):
    """n bandas com n-1 folgas *entre* elas.

    A primeira comeca em TOP e a ultima acaba em BOT, para que a silhueta
    exterior seja identica a da versao solida. Com passo = HEIGHT/n a ultima
    banda ficaria curta e a marca encolheria ao ganhar camadas.
    """
    gap = (HEIGHT / n) * GAP_RATIO
    band = (HEIGHT - (n - 1) * gap) / n
    return [(TOP + i * (band + gap), band) for i in range(n)]


def svg(n, size, color, cid):
    """SVG da marca. n=0 da a versao solida, n>0 recorta n camadas."""
    defs = clip = ""
    if n:
        rects = "".join(
            f'<rect x="0" y="{y:.2f}" width="64" height="{h:.2f}"/>'
            for y, h in layer_rects(n)
        )
        defs = f'<defs><clipPath id="{cid}">{rects}</clipPath></defs>'
        clip = f' clip-path="url(#{cid})"'
    return (
        f'<svg width="{size}" height="{size}" viewBox="0 0 64 64" '
        f'xmlns="http://www.w3.org/2000/svg">{defs}'
        f'<g fill="{color}"{clip}><path d="{D1}"/><path d="{D2}"/></g></svg>'
    )


def find_browser():
    if env := os.environ.get("ICON_BROWSER"):
        return env
    candidates = [
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        "/usr/bin/google-chrome",
        "/usr/bin/chromium",
    ]
    for c in candidates:
        if Path(c).exists():
            return c
    sys.exit("Nao encontrei Edge nem Chrome. Definir ICON_BROWSER.")


def shoot(browser, body, bg, w, h):
    """Rasteriza uma pagina de w x h e devolve a imagem."""
    html = (
        f'<!doctype html><meta charset="utf-8">'
        f'<style>html,body{{margin:0;padding:0;background:{bg}}}'
        f'div svg{{display:block}}</style>'
        f'<body style="width:{w}px;height:{h}px;position:relative">{body}</body>'
    )
    with tempfile.TemporaryDirectory() as tmp:
        page = Path(tmp) / "page.html"
        page.write_text(html, encoding="utf-8")
        out = Path(tmp) / "shot.png"
        subprocess.run(
            [browser, "--headless=new", "--disable-gpu", "--hide-scrollbars",
             f"--user-data-dir={Path(tmp) / 'profile'}",
             "--force-device-scale-factor=1",
             f"--window-size={w},{h}", f"--screenshot={out}", page.as_uri()],
            check=True, capture_output=True, timeout=120,
        )
        return Image.open(out).convert("RGB").copy()


def build_favicon_svg():
    (PUBLIC / "favicon.svg").write_text(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">\n'
        f"  <title>12studio</title>\n"
        f"  <style>\n"
        f"    path {{ fill: {INK}; }}\n"
        f"    @media (prefers-color-scheme: dark) {{ path {{ fill: {INK_DARK}; }} }}\n"
        f"  </style>\n"
        f'  <path d="{D1}"/>\n'
        f'  <path d="{D2}"/>\n'
        f"</svg>\n",
        encoding="utf-8", newline="\n",
    )
    print("favicon.svg")


def build_favicon_ico(browser):
    """Rasteriza cada tamanho nativamente e escreve um .ico com PNG embutido.

    O alfa sai de duas renderizacoes, sobre branco e sobre preto: como
    C = a*F + (1-a)*B, a diferenca entre as duas da 1-a, logo a = 255 - (Cw - Cb).
    Evita depender de flags de fundo transparente do browser.
    """
    pos, gap = 8, 16
    xs, x = [], pos
    for s in ICO_SIZES:
        xs.append(x)
        x += s + gap
    pw, ph = x + pos, max(ICO_SIZES) + pos * 2

    body = "".join(
        f'<div style="position:absolute;left:{xs[i]}px;top:{pos}px">'
        f'{svg(0, s, "#000000", f"i{i}")}</div>'
        for i, s in enumerate(ICO_SIZES)
    )
    on_white = shoot(browser, body, "#ffffff", pw, ph)
    on_black = shoot(browser, body, "#000000", pw, ph)

    ink = tuple(int(INK[i:i + 2], 16) for i in (1, 3, 5))
    frames, blobs = [], []
    for i, s in enumerate(ICO_SIZES):
        box = (xs[i], pos, xs[i] + s, pos + s)
        diff = ImageChops.difference(on_white.crop(box), on_black.crop(box)).convert("L")
        alpha = ImageChops.invert(diff)
        frame = Image.merge("RGBA", (*[Image.new("L", (s, s), v) for v in ink], alpha))
        frames.append(frame)
        buf = BytesIO()
        frame.save(buf, format="PNG", optimize=True)
        blobs.append(buf.getvalue())

    header = struct.pack("<HHH", 0, 1, len(frames))
    offset = len(header) + 16 * len(frames)
    entries = b""
    for frame, blob in zip(frames, blobs):
        entries += struct.pack("<BBBBHHII", frame.width % 256, frame.height % 256,
                               0, 0, 1, 32, len(blob), offset)
        offset += len(blob)
    (PUBLIC / "favicon.ico").write_bytes(header + entries + b"".join(blobs))
    print("favicon.ico", ICO_SIZES)


def build_apple_touch(browser):
    """Azulejo opaco: o iOS compoe sobre fundo solido e transparencia sai preta."""
    off = (ATI - ATI_MARK) // 2
    body = (f'<div style="position:absolute;left:{off}px;top:{off}px">'
            f'{svg(ATI_LAYERS, ATI_MARK, INK, "ati")}</div>')
    img = shoot(browser, body, TILE, ATI, ATI)
    img.convert("RGB").save(PUBLIC / "apple-touch-icon.png", format="PNG", optimize=True)
    print("apple-touch-icon.png", img.size)


def main():
    browser = find_browser()
    build_favicon_svg()
    build_favicon_ico(browser)
    build_apple_touch(browser)


if __name__ == "__main__":
    main()
