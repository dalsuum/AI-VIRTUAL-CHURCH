#!/usr/bin/env python3
"""
Live Sticker engine — SELF-CONTAINED & REMOVABLE.

Pipeline (render): photo -> face-detect square crop -> AI repaint
(OpenRouter / gpt-image-1, img2img) -> background removal (rembg) -> die-cut
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
import unicodedata
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

COUNT = 1                  # stickers per job (1 AI repaint → ~$0.02-0.04/job)
SIZE = 768                 # final square sticker, px
EMOJI_STRIKE = 109         # NotoColorEmoji's single bitmap strike
PAD = 90                   # canvas padding for border + shadow + decorations
BORDER = 16                # white die-cut outline thickness, px
# Reject decompression bombs: a tiny file that declares huge dimensions can
# exhaust RAM when decoded (OpenCV has no built-in guard). 60 MP covers any real
# phone photo while a 60000x60000 bomb (3.6 GP) is refused at the header.
MAX_PIXELS = 60_000_000

# --- OpenRouter image model -------------------------------------------------
# OpenAI's gpt-image-1 preserves facial likeness noticeably better than Gemini
# flash for img2img portraits, so it's the default. Override with STICKER_MODEL
# in workers/.env (e.g. google/gemini-2.5-flash-image) to fall back. Resolved at
# call time so a workers/.env override (loaded later) is honoured.
DEFAULT_MODEL = "openai/gpt-image-1"
OR_URL = "https://openrouter.ai/api/v1/chat/completions"


def sticker_model():
    return os.environ.get("STICKER_MODEL", "").strip() or DEFAULT_MODEL

# Visually DISTINCT art styles, picked at random per render so consecutive
# users get different-looking stickers (not just watercolor variants).
STYLES = [
    "soft watercolor painting with gentle pastel washes",
    "bold cartoon comic style with clean black outlines and flat colours",
    "Japanese anime style with expressive eyes and cel shading",
    "vibrant pop-art style with bright saturated colours and halftone dots",
    "warm oil painting with visible textured brush strokes",
    "cute 3D animated character style, soft studio lighting (Pixar-like)",
    "colour pencil sketch with light hand-drawn shading",
    "flat modern vector illustration, minimal clean shading",
]
PROMPT = (
    "Restyle this photo as a cute die-cut sticker portrait{occasion} in {style}. "
    "CRITICAL: preserve each person's exact facial identity and likeness — keep "
    "the same face shape, eyes, nose, mouth, eyebrows, skin tone, facial hair, "
    "glasses, hairstyle and expression so they remain clearly recognisable as "
    "the same individual. Apply the artistic style ONLY to rendering/texture, "
    "never change facial features, proportions or who the person is. Keep the "
    "same clothing and pose; head and shoulders. Plain solid white background. "
    "No text, no border. Friendly and warm."
)

DECOR = ["❤️", "\U0001f49b", "✨", "⭐", "\U0001f31f", "\U0001fa77"]
# Themed decoration sets keyed by words in the current Special Sunday title.
THEME_DECOR = {
    "father":    ["❤️", "\U0001f44a", "⭐", "✨", "\U0001f3c5"],
    "mother":    ["\U0001f337", "❤️", "\U0001f49b", "✨", "\U0001f338"],
    "christmas": ["\U0001f384", "\U0001f381", "⭐", "❄️", "✨"],
    "easter":    ["\U0001f430", "\U0001f338", "\U0001f95a", "✨", "\U0001f337"],
    "thanksgiv": ["\U0001f342", "\U0001f33d", "⭐", "✨", "❤️"],
    "new year":  ["\U0001f389", "✨", "⭐", "\U0001f38a", "\U0001f31f"],
    "valentine": ["❤️", "\U0001f49d", "\U0001f495", "✨", "\U0001f339"],
}


def decor_for(theme):
    t = (theme or "").lower()
    for key, emojis in THEME_DECOR.items():
        if key in t:
            return emojis
    return DECOR
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
    img = Image.open(path)
    # Check declared dimensions from the header BEFORE decoding pixels, so a
    # decompression bomb is refused without ever allocating its full bitmap.
    w, h = img.size
    if w * h > MAX_PIXELS:
        raise ValueError(f"image too large: {w}x{h}")
    return ImageOps.exif_transpose(img).convert("RGB")


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


def openrouter_repaint(pil_square, style, occasion=""):
    """img2img watercolor repaint via OpenRouter; returns a PIL RGB or None."""
    key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if not key:
        return None
    occ = f" celebrating {occasion}" if occasion else ""
    buf = io.BytesIO()
    pil_square.convert("RGB").save(buf, "JPEG", quality=90)
    b64 = base64.b64encode(buf.getvalue()).decode()
    body = {
        "model": sticker_model(),
        "modalities": ["image", "text"],
        "messages": [{"role": "user", "content": [
            {"type": "text", "text": PROMPT.format(style=style, occasion=occ)},
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


def decorate(canvas, subj_mask, rng, decor=DECOR):
    """Scatter colour-emoji around the subject, avoiding heavy overlap."""
    placed = []
    picks = rng.sample(decor, rng.choice([3, 4, 5]))
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


def graphemes(text):
    """Split into grapheme clusters so we never break a Burmese stack (a base
    letter + its medials/vowel-signs/asat). A cluster extends while the next char
    is a combining mark or a Myanmar dependent sign (U+102B–U+103E)."""
    out = []
    for ch in text:
        dep = unicodedata.combining(ch) or unicodedata.category(ch) in ("Mn", "Mc", "Me") \
            or 0x102B <= ord(ch) <= 0x103E or ch in ("‍", "‌")
        if out and dep:
            out[-1] += ch
        else:
            out.append(ch)
    return out


def wrap_clusters(draw, text, font, max_w, max_lines):
    """Greedy wrap on grapheme boundaries. Returns (lines, ok) where ok is False
    if the text didn't fully fit in max_lines (caller can shrink the font)."""
    lines, cur = [], ""
    for g in graphemes(text):
        trial = cur + g
        if draw.textlength(trial, font=font) <= max_w or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = g
            if len(lines) == max_lines:
                return lines, False
    if cur:
        lines.append(cur)
    return lines, True


_CMAP = {}


def font_supports(fpath):
    """Cached set of code points the font has glyphs for (None if unreadable)."""
    if fpath not in _CMAP:
        try:
            from fontTools.ttLib import TTFont
            _CMAP[fpath] = set(TTFont(fpath).getBestCmap().keys())
        except Exception:
            _CMAP[fpath] = None
    return _CMAP[fpath]


def drop_unsupported(text, fpath):
    """Strip characters the font can't render (e.g. the Myanmar font has no Latin
    glyphs, so a stray '.' would show as a tofu box). Keep spaces + ZW joiners,
    which shaping needs but the cmap may omit."""
    cm = font_supports(fpath)
    if not cm:
        return text
    keep = {0x20, 0x200B, 0x200C, 0x200D}
    return "".join(ch for ch in text if ord(ch) in cm or ord(ch) in keep)


def add_caption(canvas, text, bottom=28, sizes=(46, 40, 34, 28)):
    """Draw a centred, stroked caption whose bottom edge sits `bottom` px from the
    canvas bottom. Wraps on grapheme boundaries and auto-shrinks; never truncates.
    Returns the total height drawn (0 if nothing)."""
    if not text:
        return 0
    d = ImageDraw.Draw(canvas)
    fpath = MYANMAR_FONT if has_myanmar(text) and os.path.exists(MYANMAR_FONT) \
        else (LATIN_FONTS[0] if LATIN_FONTS else None)
    if not fpath:
        return 0

    text = drop_unsupported(text, fpath).strip()
    if not text:
        return 0

    max_w = SIZE - 56
    lines, font, fsize = None, None, sizes[-1]
    for size in sizes:
        f = ImageFont.truetype(fpath, size)
        wrapped, ok = wrap_clusters(d, text, f, max_w, 2)
        if ok:
            lines, font, fsize = wrapped, f, size
            break
    if lines is None:           # extremely long: keep 2 lines at the smallest size
        font = ImageFont.truetype(fpath, sizes[-1])
        lines, _ = wrap_clusters(d, text, font, max_w, 2)

    line_h = fsize + 12
    block_h = line_h * len(lines)
    y = SIZE - bottom - block_h
    for ln in lines:
        w = d.textlength(ln, font=font)
        d.text(((SIZE - w) / 2, y), ln, font=font, fill=(40, 40, 60, 255),
               stroke_width=6, stroke_fill=(255, 255, 255, 235))
        y += line_h
    return block_h


def build_sticker(base_square, style, caption, rng, occasion="", title=""):
    art = openrouter_repaint(base_square, style, occasion)
    if art is None:
        art = base_square          # graceful fallback: cut out the real photo
    cut = cutout(art)
    canvas, mask = die_cut(cut)
    decorate(canvas, mask, rng, decor_for(occasion))
    # Always burn the page title at the very bottom (static). Any visitor-chosen
    # words go just above it, slightly smaller, and only if different.
    h = add_caption(canvas, title, bottom=28)
    words = (caption or "").strip()
    if words and words != (title or "").strip():
        add_caption(canvas, words, bottom=28 + h + 10, sizes=(34, 30, 26, 22))
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


def pick_styles(job_dir, count, rng):
    """Pick `count` distinct styles, never starting with the previous render's
    style. Persists the last index in stickers/.laststyle so back-to-back users
    get different-looking art."""
    base = os.path.dirname(os.path.dirname(job_dir))   # .../stickers
    statef = os.path.join(base, ".laststyle")
    last = -1
    try:
        last = int(open(statef).read().strip())
    except Exception:
        pass

    order = list(range(len(STYLES)))
    rng.shuffle(order)
    # Don't let the first pick equal the previous job's pick.
    if len(order) > 1 and order[0] == last:
        order[0], order[1] = order[1], order[0]

    picks = order[:max(1, count)]
    try:
        open(statef, "w").write(str(picks[-1]))
        os.chmod(statef, 0o664)
    except Exception:
        pass
    return [STYLES[i] for i in picks]


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
    occasion = (inp.get("theme") or "").strip()
    title = (inp.get("title") or "").strip()

    rng = random.Random(int.from_bytes(os.urandom(4), "big"))
    # Rotate through styles without repeating the previous render's pick, so
    # consecutive visitors get visibly different art styles.
    chosen = pick_styles(job_dir, COUNT, rng)
    for i in range(1, COUNT + 1):
        pct = 8 + int(i * (90 / COUNT))
        set_status(job_dir, progress=pct,
                   stage="Painting your sticker" if COUNT == 1 else f"Painting sticker {i}/{COUNT}")
        sticker = build_sticker(base, chosen[i - 1], caption, rng, occasion, title)
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
