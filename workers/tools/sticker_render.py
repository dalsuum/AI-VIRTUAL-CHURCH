#!/usr/bin/env python3
"""
Live Sticker engine — SELF-CONTAINED & REMOVABLE.

Pipeline (render): photo -> face-detect square crop -> AI watercolor repaint
(OpenRouter / Gemini image, img2img) -> background removal (rembg) -> die-cut
white sticker border + soft shadow -> scattered colour-emoji decorations -> PNG.
Five stickers per job, each with a slightly different painterly style.

Two modes:

  detect <photo>            -> prints JSON {"w","h","box":{x,y,w,h}|null}
                               (fast face-detect; the frontend shows the box for
                               manual adjustment)

  render <job_dir>          -> reads <job_dir>/input.json + the uploaded photo,
                               writes sticker_1..5.png + status.json.

input.json (render):
  {
    "photo":  "src/<file>",                   # relative to job_dir
    "crop":   {"x":,"y":,"w":,"h":} | null,   # square px box in ORIGINAL image
    "text":   "optional caption",
    "source": "lyrics" | "manual",
    "autocorrect": true
  }

The OpenRouter key is read from workers/.env (OPENROUTER_API_KEY); when it is
missing or a call fails we fall back to a non-AI cutout so the tool still works.

Nothing here touches the worship pipeline. See StickerController for removal.
"""

import base64
import io
import json
import os
import random
import re
import sys
import urllib.request

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps

# --- fonts ------------------------------------------------------------------
BACKEND_FONTS = os.path.join(
    os.path.dirname(__file__), "..", "..", "backend", "resources", "fonts"
)
EMOJI_FONT = os.path.join(BACKEND_FONTS, "NotoColorEmoji.ttf")
MYANMAR_FONT = os.path.join(BACKEND_FONTS, "MyanmarNjaun.ttf")
DEJAVU = "/usr/share/fonts/truetype/dejavu"
LATIN_FONTS = [f for f in (
    os.path.join(DEJAVU, "DejaVuSans-Bold.ttf"),
    os.path.join(DEJAVU, "DejaVuSerif-Bold.ttf"),
) if os.path.exists(f)]

SIZE = 768                 # final square sticker, px
EMOJI_STRIKE = 109         # NotoColorEmoji's single bitmap strike
PAD = 90                   # canvas padding for border + shadow + decorations
BORDER = 16                # white die-cut outline thickness, px

# --- OpenRouter image model -------------------------------------------------
OR_MODEL = "google/gemini-2.5-flash-image"
OR_URL = "https://openrouter.ai/api/v1/chat/completions"

# Five painterly variations so the 5 stickers differ in feel.
STYLES = [
    "soft watercolor painting, gentle pastel washes",
    "delicate watercolor illustration, light airy colours",
    "vibrant watercolor portrait, expressive loose brush strokes",
    "dreamy watercolor art, soft edges and warm pastel tones",
    "clean watercolour sketch with subtle colour washes",
]
PROMPT = (
    "Repaint this photo as a cute die-cut sticker portrait in {style}. "
    "Keep the same people, faces, hairstyle, clothing and pose; head and "
    "shoulders. Plain solid white background. No text, no border. "
    "Friendly and warm."
)

DECOR = ["❤️", "\U0001f49b", "✨", "⭐", "\U0001f31f", "\U0001fa77"]
MYANMAR_RE = re.compile(r"[က-႟ꩠ-ꩿ]")


def load_env():
    """Merge workers/.env into os.environ (without overwriting real env vars)."""
    path = os.path.join(os.path.dirname(__file__), "..", ".env")
    if not os.path.exists(path):
        return
    for line in open(path):
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip())


def has_myanmar(t):
    return bool(MYANMAR_RE.search(t))


def autocorrect_en(text):
    try:
        from spellchecker import SpellChecker
    except Exception:
        return text
    sp = SpellChecker()
    out = []
    for tok in re.findall(r"\w+|\W+", text):
        if tok.isalpha() and tok.islower() and len(tok) > 2 and tok in sp.unknown([tok]):
            out.append(sp.correction(tok) or tok)
        else:
            out.append(tok)
    return "".join(out)


def load_image(path):
    return ImageOps.exif_transpose(Image.open(path)).convert("RGB")


def detect_face_box(pil_img):
    rgb = np.array(pil_img)
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    cascade = cv2.CascadeClassifier(
        os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
    )
    faces = cascade.detectMultiScale(gray, 1.1, 5, minSize=(40, 40))
    W, H = pil_img.size
    side = min(W, H)
    if len(faces):
        # Square around ALL detected faces (group photos), padded.
        x1 = min(f[0] for f in faces); y1 = min(f[1] for f in faces)
        x2 = max(f[0] + f[2] for f in faces); y2 = max(f[1] + f[3] for f in faces)
        cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
        s = int(min(max(x2 - x1, y2 - y1) * 1.7, side))
        x = int(min(max(cx - s / 2, 0), W - s))
        y = int(min(max(cy - s / 2, 0), H - s))
        return {"x": x, "y": y, "w": s, "h": s}
    return {"x": (W - side) // 2, "y": (H - side) // 2, "w": side, "h": side}


def crop_square(pil_img, box):
    W, H = pil_img.size
    x = max(0, int(box["x"])); y = max(0, int(box["y"]))
    s = max(16, int(min(box["w"], box["h"])))
    s = min(s, W - x, H - y)
    return pil_img.crop((x, y, x + s, y + s))


def openrouter_repaint(pil_square, style):
    """img2img watercolor repaint via OpenRouter; returns a PIL RGB or None."""
    key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if not key:
        return None
    buf = io.BytesIO()
    pil_square.convert("RGB").save(buf, "JPEG", quality=90)
    b64 = base64.b64encode(buf.getvalue()).decode()
    body = {
        "model": OR_MODEL,
        "modalities": ["image", "text"],
        "messages": [{"role": "user", "content": [
            {"type": "text", "text": PROMPT.format(style=style)},
            {"type": "image_url", "image_url": {"url": f"data:image/jpeg;base64,{b64}"}},
        ]}],
    }
    req = urllib.request.Request(
        OR_URL, data=json.dumps(body).encode(),
        headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
    )
    try:
        d = json.loads(urllib.request.urlopen(req, timeout=90).read())
        imgs = d["choices"][0]["message"].get("images") or []
        if not imgs:
            return None
        raw = base64.b64decode(imgs[0]["image_url"]["url"].split(",", 1)[1])
        return Image.open(io.BytesIO(raw)).convert("RGB")
    except Exception as e:
        sys.stderr.write(f"repaint failed: {e}\n")
        return None


_REMBG = {"session": None}


def cutout(pil_rgb):
    """Remove the background; returns an RGBA trimmed to the subject."""
    from rembg import remove, new_session
    if _REMBG["session"] is None:
        _REMBG["session"] = new_session("u2net")
    out = remove(pil_rgb.convert("RGBA"), session=_REMBG["session"],
                 post_process_mask=True)
    bbox = out.getbbox()
    return out.crop(bbox) if bbox else out


def die_cut(cut_rgba):
    """Place the cutout on a SIZE canvas with a white silhouette border + shadow."""
    # Scale the subject to fit inside the padded area.
    inner = SIZE - 2 * PAD
    ratio = inner / max(cut_rgba.size)
    new = (max(1, int(cut_rgba.width * ratio)), max(1, int(cut_rgba.height * ratio)))
    subj = cut_rgba.resize(new, Image.LANCZOS)
    ox = (SIZE - subj.width) // 2
    oy = (SIZE - subj.height) // 2

    alpha = subj.split()[3]
    a = np.array(alpha)
    mask_full = np.zeros((SIZE, SIZE), np.uint8)
    mask_full[oy:oy + subj.height, ox:ox + subj.width] = a

    # White border = dilated silhouette filled white.
    k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (BORDER * 2 + 1,) * 2)
    border_mask = cv2.dilate(mask_full, k)
    border_mask = cv2.GaussianBlur(border_mask, (0, 0), 1.2)

    canvas = Image.new("RGBA", (SIZE, SIZE), (0, 0, 0, 0))

    # Soft drop shadow under the border, offset down-right.
    shadow = Image.fromarray(border_mask).filter(ImageFilter.GaussianBlur(10))
    shadow_layer = Image.new("RGBA", (SIZE, SIZE), (0, 0, 0, 0))
    sm = np.array(shadow).astype(np.float32) * 0.45
    shadow_rgba = np.zeros((SIZE, SIZE, 4), np.uint8)
    shadow_rgba[..., 3] = sm.astype(np.uint8)
    shadow_layer = Image.fromarray(shadow_rgba)
    canvas.alpha_composite(shadow_layer, (8, 12))

    # White border layer.
    white = np.zeros((SIZE, SIZE, 4), np.uint8)
    white[..., :3] = 255
    white[..., 3] = border_mask
    canvas = Image.alpha_composite(canvas, Image.fromarray(white))

    # Subject on top.
    canvas.alpha_composite(subj, (ox, oy))
    return canvas, mask_full


def emoji_image(ch, target):
    try:
        font = ImageFont.truetype(EMOJI_FONT, EMOJI_STRIKE)
        c = Image.new("RGBA", (EMOJI_STRIKE + 20, EMOJI_STRIKE + 20), (0, 0, 0, 0))
        ImageDraw.Draw(c).text((10, 10), ch, font=font, embedded_color=True)
        bbox = c.getbbox()
        if not bbox:
            return None
        g = c.crop(bbox)
        r = target / max(g.size)
        return g.resize((max(1, int(g.width * r)), max(1, int(g.height * r))), Image.LANCZOS)
    except Exception:
        return None


def decorate(canvas, subj_mask, rng):
    """Scatter colour-emoji around the subject, avoiding heavy overlap."""
    placed = []
    picks = rng.sample(DECOR, rng.choice([3, 4, 5]))
    for ch in picks:
        em = emoji_image(ch, rng.choice([70, 90, 110]))
        if em is None:
            continue
        for _ in range(20):
            x = rng.randint(8, SIZE - em.width - 8)
            y = rng.randint(8, SIZE - em.height - 8)
            cx, cy = x + em.width // 2, y + em.height // 2
            # Prefer spots where the subject isn't (keep faces clear).
            if subj_mask[min(cy, SIZE - 1), min(cx, SIZE - 1)] > 40:
                continue
            if any(abs(cx - px) < 70 and abs(cy - py) < 70 for px, py in placed):
                continue
            canvas.alpha_composite(em, (x, y))
            placed.append((cx, cy))
            break


def add_caption(canvas, text):
    if not text:
        return
    d = ImageDraw.Draw(canvas)
    fpath = MYANMAR_FONT if has_myanmar(text) and os.path.exists(MYANMAR_FONT) \
        else (LATIN_FONTS[0] if LATIN_FONTS else None)
    if not fpath:
        return
    if len(text) > 30:
        text = text[:29].rstrip() + "…"
    font = ImageFont.truetype(fpath, 46)
    w = d.textlength(text, font=font)
    x = (SIZE - w) / 2
    y = SIZE - 92
    # White outline so it reads over the art.
    d.text((x, y), text, font=font, fill=(40, 40, 60, 255),
           stroke_width=6, stroke_fill=(255, 255, 255, 235))


def build_sticker(base_square, style, caption, rng):
    art = openrouter_repaint(base_square, style)
    if art is None:
        art = base_square          # graceful fallback: cut out the real photo
    cut = cutout(art)
    canvas, mask = die_cut(cut)
    decorate(canvas, mask, rng)
    add_caption(canvas, caption)
    return canvas


# --- modes ------------------------------------------------------------------

def cmd_detect(photo):
    pil = load_image(photo)
    box = detect_face_box(pil)
    W, H = pil.size
    print(json.dumps({"w": W, "h": H, "box": box}))


def set_status(job_dir, **kw):
    kw.setdefault("status", "rendering")
    with open(os.path.join(job_dir, "status.json"), "w") as f:
        json.dump(kw, f)
    try:
        os.chmod(os.path.join(job_dir, "status.json"), 0o664)
    except OSError:
        pass


def cmd_render(job_dir):
    load_env()
    with open(os.path.join(job_dir, "input.json")) as f:
        inp = json.load(f)
    set_status(job_dir, progress=8, stage="Loading photo")

    pil = load_image(os.path.join(job_dir, inp["photo"]))
    box = inp.get("crop") or detect_face_box(pil)
    base = crop_square(pil, box)

    caption = (inp.get("text") or "").strip()
    if caption and inp.get("autocorrect") and inp.get("source") == "manual" and not has_myanmar(caption):
        caption = autocorrect_en(caption)

    rng = random.Random(int.from_bytes(os.urandom(4), "big"))
    styles = STYLES[:]
    rng.shuffle(styles)
    for i in range(1, 6):
        set_status(job_dir, progress=8 + i * 17, stage=f"Painting sticker {i}/5")
        sticker = build_sticker(base, styles[(i - 1) % len(styles)], caption, rng)
        sticker.save(os.path.join(job_dir, f"sticker_{i}.png"))
        try:
            os.chmod(os.path.join(job_dir, f"sticker_{i}.png"), 0o644)
        except OSError:
            pass

    set_status(job_dir, status="done", progress=100, stage="Done")


def main():
    if len(sys.argv) < 3:
        print("usage: sticker_render.py detect <photo> | render <job_dir>", file=sys.stderr)
        return 2
    mode, arg = sys.argv[1], sys.argv[2]
    if mode == "detect":
        cmd_detect(arg)
    elif mode == "render":
        cmd_render(arg)
    else:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
