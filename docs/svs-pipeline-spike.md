# Architecture Spike: Singing Voice Synthesis (SVS) Pipeline

> Status: **spike / architecture decision record** — not yet implemented.
> Captured to delineate engineering boundaries from data-collection requirements
> before the context decays. The current `local_ai` music source remains
> MusicGen-backed (instrumental only); see [IntakeForm.vue](../frontend/src/components/IntakeForm.vue).

## Objective

Outline the architectural approach and phased implementation strategy for true
vocal synthesis (Option B), specifically addressing the Grapheme-to-Phoneme
(G2P) and alignment challenges for Burmese and Zolai (Tedim).

## Core Architecture Decision

The SVS inference pipeline will execute **off-box** to isolate heavy VRAM
requirements from the primary CPU / database tier.

- **Infrastructure:** RunPod serverless GPU endpoint.
- **Orchestration:** Asynchronous polling pattern from the worker, strictly
  mirroring the existing [nllb_api.py](../workers/nllb_api.py) translation setup
  (post payload → poll/await callback → download result).
- **Mixing:** Lightweight FFmpeg wrapper on the GPU instance to overlay the
  synthesized vocals onto the base instrumental track.
- **Storage:** Final `.mp3` payloads written back to unified storage
  (`hymns_my/`, `hymns_td/`) via `storage.upload_bytes`, so the existing hymn
  strategies serve them with no further changes.

### Pipeline (inputs → output)

1. **Input:** Lyrics (Burmese / Tedim / English) + base melody (MIDI or MusicGen output).
2. **G2P & alignment:** map syllables → phonemes with precise note durations.
3. **Acoustic modeling:** aligned phonemes + pitch → mel-spectrogram (DiffSinger).
4. **Vocoder:** mel-spectrogram → raw vocal audio (e.g. HiFi-GAN).
5. **Mix:** FFmpeg overlays vocals onto the instrumental; return final `.mp3`.

## Phased Implementation Strategy

### Phase 1 — Burmese SVS Pilot (engineering-gated)

Burmese is the pilot language because of existing syllable-tokenization
foundations already in the repository.

**G2P approach (rule-based):**

- Bypass Montreal Forced Aligner (MFA) for v1 to avoid immediate acoustic-model training.
- Build a syllable tokenizer from the existing `_clean_myanmar` logic in
  [nllb_api.py](../workers/nllb_api.py) and the Myanmar Unicode handling
  (`_is_my_plausible`, U+1000–U+109F) in [llm_engine.py](../workers/llm_engine.py).
- Map Burmese syllables → phonemes with a hand-built lexicon, reusing the worship
  vocabulary already curated in [song_library.py](../workers/song_library.py).

**Tone & pitch management:**

- Burmese tone (register / phonation) is **bypassed at the G2P layer**.
- DiffSinger models pitch directly from the **MIDI F0 contour** — the note grid
  dictates pitch. This reduces the G2P requirement to pure **segmental accuracy**
  (which syllable, which vowel, which coda) and is the key insight that makes the
  pilot tractable without an aligner.

### Phase 2 — Zolai / Tedim (data-gated)

Tedim vocal synthesis is **structurally deferred**. It is blocked by a lack of
fundamental linguistic resources: zero G2P resources, no forced-alignment corpus,
and inconsistent orthographic tone markers.

- **Prerequisite:** a dedicated dataset-collection project *before* any pipeline work.
- **Requirements:** a willing native Zolai vocalist and precisely aligned
  orthographic transcripts to train a custom acoustic model.
- **Communicate clearly:** this phase is *data-gated, not engineering-gated*.

### Phase 3 — Acoustic Modeling Optimization (future)

Only if the Phase 1 rule-based G2P + MIDI-driven timing proves too robotic or
unnatural in production:

- Introduce Montreal Forced Aligner (MFA).
- Integrate `epitran` (`mya` support) or a `phonetisaurus` model trained on the
  Myanmar G2P lexicon.
- Train a custom acoustic model from a labeled corpus for dynamic vocal timing.

## Open Questions / Risks

- **Burmese labeled corpus** is the long pole if Phase 3 is needed — sourcing or
  recording aligned Burmese singing data.
- **Latency budget:** SVS inference + mix must stay within the agent loop's music
  window; the async-poll pattern keeps it off the critical text path, matching how
  `generate_music` is already pre-dispatched.
- **Cost:** serverless GPU per-render cost vs. the existing local-hymn fallback,
  which remains the guaranteed-delivery path regardless of SVS availability.
