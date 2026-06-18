#!/usr/bin/env python3
"""
Live Sticker engine — SELF-CONTAINED & REMOVABLE.

Two modes:

  detect <photo>            -> prints JSON {"w","h","box":{x,y,w,h}|null}
                               (fast face-detect; suggests a square crop the
                               frontend shows for manual adjustment)

  render <job_dir>          -> reads <job_dir>/input.json + the uploaded photo,
                               crops to a square (auto face box or the user's
                               adjusted box), then composites 5 random PNG
                               stickers (sticker_1..5.png) and updates
                               <job_dir>/status.json.

input.json (render):
  {
    "photo":   "src/<file>",        # relative to job_dir
    "crop":    {"x":,"y":,"w":,"h":} | null,   # square px box in ORIGINAL image
    "text":    "free text or chosen lyric line",
    "source":  "lyrics" | "manual",
    "autocorrect": true
  }

Nothing here touches the worship pipeline. To remove: delete this file plus
StickerController, RenderStickerJob, the /stickers routes and the frontend
LiveSticker.vue. No DB, no shared state.
"""

import json
import os
import random
import re
import sys

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont, ImageOps

# --- fonts ------------------------------------------------------------------
# Bundled with the backend so the host needs no extra system fonts.
BACKEND_FONTS = os.path.join(
    os.path.dirname(__file__), "..", "..", "backend", "resources", "fonts"
)
EMOJI_FONT = os.path.join(BACKEND_FONTS, "NotoColorEmoji.ttf")
MYANMAR_FONT = os.path.join(BACKEND_FONTS, "MyanmarNjaun.ttf")
DEJAVU = "/usr/share/fonts/truetype/dejavu"
LATIN_FONTS = [
    os.path.join(DEJAVU, "DejaVuSans-Bold.ttf"),
    os.path.join(DEJAVU, "DejaVuSerif-Bold.ttf"),
    os.path.join(DEJAVU, "DejaVuSansMono-Bold.ttf"),
]
LATIN_FONTS = [f for f in LATIN_FONTS if os.path.exists(f)]

SIZE = 512                      # final square sticker, px
EMOJI_STRIKE = 109              # NotoColorEmoji has a single bitmap strike

# Fun decorative palettes (banner fill, text colour).
PALETTES = [
    ((233, 30, 99), (255, 255, 255)),    # pink
    ((33, 150, 243), (255, 255, 255)),   # blue
    ((255, 152, 0), (40, 20, 0)),        # orange
    ((76, 175, 80), (255, 255, 255)),    # green
    ((156, 39, 176), (255, 255, 255)),   # purple
    ((255, 235, 59), (40, 30, 0)),       # yellow
    ((0, 0, 0), (255, 255, 255)),        # classic
]
EMOJIS = ["🎉", "❤️", "🙏", "⭐", "🥳", "👑", "🌟", "💛", "🎈", "🔥", "😎", "✨"]
# Generic short phrases used to fill / decorate when the source text is empty
# or very long (sticker text must stay short to read at thumbnail size).
FALLBACK_PHRASES = [
    "Happy Father's Day", "Best Dad", "Love You Dad", "Super Dad",
    "Dad #1", "My Hero", "Thank You Dad", "Forever Grateful",
]

MYANMAR_RE = re.compile(r"[က-႟ꩠ-ꩿ]")


def has_myanmar(text):
    return bool(MYANMAR_RE.search(text))


def autocorrect_en(text):
    """Light, conservative English spell-fix. Never touches non-ascii, all-caps
    (likely names/acronyms), or capitalised words (likely proper nouns)."""
    try:
        from spellchecker import SpellChecker
    except Exception:
        return text
    sp = SpellChecker()
    out = []
    for tok in re.findall(r"\w+|\W+", text):
        if (
            tok.isalpha()
            and tok.islower()
            and len(tok) > 2
            and tok in sp.unknown([tok])
        ):
            out.append(sp.correction(tok) or tok)
        else:
            out.append(tok)
    return "".join(out)


def load_image(path):
    """Load via PIL so EXIF orientation is honoured (phones save rotated)."""
    img = Image.open(path)
    img = ImageOps.exif_transpose(img).convert("RGB")
    return img


def detect_face_box(pil_img):
    """Return a square (x,y,w,h) box in original pixels centred on the largest
    detected face (padded), or a sensible centre-square fallback."""
    rgb = np.array(pil_img)
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    cascade = cv2.CascadeClassifier(
        os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
    )
    faces = cascade.detectMultiScale(gray, 1.1, 5, minSize=(40, 40))
    W, H = pil_img.size
    side = min(W, H)
    if len(faces):
        fx, fy, fw, fh = max(faces, key=lambda f: f[2] * f[3])
        cx, cy = fx + fw / 2, fy + fh / 2
        # Square ~2.4x the face so head + shoulders fit; clamp to image.
        s = int(min(max(fw, fh) * 2.4, side))
        x = int(min(max(cx - s / 2, 0), W - s))
        y = int(min(max(cy - s / 2, 0), H - s))
        return {"x": x, "y": y, "w": s, "h": s}
    return {"x": (W - side) // 2, "y": (H - side) // 2, "w": side, "h": side}


def crop_square(pil_img, box):
    W, H = pil_img.size
    x = max(0, int(box["x"]))
    y = max(0, int(box["y"]))
    s = max(16, int(min(box["w"], box["h"])))
    s = min(s, W - x, H - y)
    crop = pil_img.crop((x, y, x + s, y + s)).resize((SIZE, SIZE), Image.LANCZOS)
    return crop


def emoji_image(ch, target):
    """Render a single colour emoji to an RGBA image of ~target px."""
    try:
        font = ImageFont.truetype(EMOJI_FONT, EMOJI_STRIKE)
        canvas = Image.new("RGBA", (EMOJI_STRIKE + 20, EMOJI_STRIKE + 20), (0, 0, 0, 0))
        d = ImageDraw.Draw(canvas)
        d.text((10, 10), ch, font=font, embedded_color=True)
        bbox = canvas.getbbox()
        if not bbox:
            return None
        glyph = canvas.crop(bbox)
        ratio = target / max(glyph.size)
        return glyph.resize(
            (max(1, int(glyph.width * ratio)), max(1, int(glyph.height * ratio))),
            Image.LANCZOS,
        )
    except Exception:
        return None


def pick_phrase(text, source):
    """Choose a short, readable phrase for the sticker banner."""
    text = (text or "").strip()
    if not text:
        return random.choice(FALLBACK_PHRASES)
    # Split lyrics into individual lines / short clauses and prefer short ones.
    parts = [p.strip() for p in re.split(r"[\r\n]+|[.!?]+", text) if p.strip()]
    parts = [p for p in parts if not re.fullmatch(r"\[.*\]", p)]  # drop [Verse] tags
    short = [p for p in parts if len(p) <= 28] or parts or [text]
    phrase = random.choice(short)
    if len(phrase) > 36:
        phrase = phrase[:33].rstrip() + "…"
    return phrase


def wrap(draw, text, font, max_w):
    words, lines, cur = text.split(), [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if draw.textlength(trial, font=font) <= max_w or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines[:3]


def font_for(text, size):
    path = MYANMAR_FONT if has_myanmar(text) and os.path.exists(MYANMAR_FONT) \
        else random.choice(LATIN_FONTS)
    return ImageFont.truetype(path, size)


def rounded(draw, xy, r, fill):
    draw.rounded_rectangle(xy, radius=r, fill=fill)


def make_sticker(base, phrase, seed):
    """Compose one decorated sticker on top of the square photo `base`."""
    random.seed(seed)
    img = base.copy().convert("RGBA")
    overlay = Image.new("RGBA", img.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(overlay)

    band, txt = random.choice(PALETTES)
    top = random.random() < 0.5
    margin = 28

    # --- text banner ---
    fsize = random.choice([46, 52, 58])
    font = font_for(phrase, fsize)
    lines = wrap(d, phrase, font, SIZE - 2 * margin - 30)
    line_h = fsize + 10
    block_h = line_h * len(lines) + 28
    by0 = margin if top else SIZE - margin - block_h
    rounded(d, (margin, by0, SIZE - margin, by0 + block_h), 26,
            (band[0], band[1], band[2], 220))
    for i, ln in enumerate(lines):
        lw = d.textlength(ln, font=font)
        d.text(((SIZE - lw) / 2, by0 + 14 + i * line_h), ln, font=font,
               fill=(txt[0], txt[1], txt[2], 255),
               stroke_width=2, stroke_fill=(0, 0, 0, 160))

    img = Image.alpha_composite(img, overlay)

    # --- emoji decorations (1-2 corners, opposite the banner) ---
    for ch in random.sample(EMOJIS, random.choice([1, 2])):
        em = emoji_image(ch, random.choice([84, 104]))
        if em is None:
            continue
        ex = random.choice([margin - 6, SIZE - em.width - margin + 6])
        ey = (SIZE - em.height - margin + 6) if top else (margin - 6)
        img.alpha_composite(em, (max(0, ex), max(0, ey)))

    # --- sticker frame (rounded white outline = classic sticker look) ---
    frame = Image.new("RGBA", img.size, (0, 0, 0, 0))
    fd = ImageDraw.Draw(frame)
    bw = random.choice([10, 14, 18])
    col = random.choice([(255, 255, 255, 255), band + (255,)])
    fd.rounded_rectangle((bw // 2, bw // 2, SIZE - bw // 2, SIZE - bw // 2),
                         radius=48, outline=col, width=bw)
    img = Image.alpha_composite(img, frame)

    # Round the corners (transparent outside the rounded square).
    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, SIZE, SIZE), radius=48, fill=255)
    img.putalpha(mask)
    return img


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
    with open(os.path.join(job_dir, "input.json")) as f:
        inp = json.load(f)
    set_status(job_dir, progress=10, stage="Loading photo")

    pil = load_image(os.path.join(job_dir, inp["photo"]))
    box = inp.get("crop") or detect_face_box(pil)
    base = crop_square(pil, box)
    set_status(job_dir, progress=35, stage="Detecting & cropping")

    text = (inp.get("text") or "").strip()
    if inp.get("autocorrect") and inp.get("source") == "manual" and not has_myanmar(text):
        text = autocorrect_en(text)

    rng = random.Random(int.from_bytes(os.urandom(4), "big"))
    for i in range(1, 6):
        phrase = pick_phrase(text, inp.get("source"))
        sticker = make_sticker(base, phrase, rng.randint(0, 2**31))
        sticker.save(os.path.join(job_dir, f"sticker_{i}.png"))
        try:
            os.chmod(os.path.join(job_dir, f"sticker_{i}.png"), 0o644)
        except OSError:
            pass
        set_status(job_dir, progress=35 + i * 12, stage=f"Sticker {i}/5")

    set_status(job_dir, status="done", progress=100, stage="Done")


def main():
    if len(sys.argv) < 3:
        print("usage: sticker_render.py detect <photo> | render <job_dir>",
              file=sys.stderr)
        return 2
    mode, arg = sys.argv[1], sys.argv[2]
    if mode == "detect":
        cmd_detect(arg)
    elif mode == "render":
        cmd_render(arg)
    else:
        print("unknown mode", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
