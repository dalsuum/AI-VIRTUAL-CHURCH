# Avatar base portraits

Drop two front-facing portrait images here for the local avatar engine:

- `female_base.jpg` — female presenter
- `male_base.jpg` — male presenter

Requirements (SadTalker works best with these):
- Clear, front-facing, single face, neutral expression, eyes open.
- Square-ish framing, head + shoulders, ~512px+ on the short side.
- JPEG. Plain background renders most cleanly.

Point the worker at them in `workers/.env`:

```
LOCAL_AVATAR_IMAGE_FEMALE=data/avatars/female_base.jpg
LOCAL_AVATAR_IMAGE_MALE=data/avatars/male_base.jpg
```

These are sent (as the `image` part) to LOCAL_AVATAR_URL alongside the segment's
narration audio; the avatar proxy forwards them to the RunPod SadTalker endpoint.
