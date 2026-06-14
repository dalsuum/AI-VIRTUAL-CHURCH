"""
Build a JSONL fine-tuning dataset for the Tedim (Zolai) language model.

Sources
-------
  tedim1932.json   -- Lai Siangtho: 66-book Tedim Bible, 30 715 verses
  bsb.json         -- Berean Standard Bible (English), same versification
  hymns_td.json    -- ZBC Tedim hymnbook: 467 hymns with Tedim lyrics
  zolai_vocabulary.json -- 108 curated vocabulary + grammar entries

Output
------
  /opt/ai-church/workers/data/tedim_finetune.jsonl    (training set)
  /opt/ai-church/workers/data/tedim_finetune_val.jsonl (10 % validation split)

Usage
-----
  python3 workers/tools/build_tedim_dataset.py

Each JSONL line is a standard chat-format training example:
  {"messages": [
      {"role": "system",  "content": "..."},
      {"role": "user",    "content": "..."},
      {"role": "assistant","content": "..."}
  ]}
"""

from __future__ import annotations

import json
import random
from pathlib import Path

DATA_DIR   = Path(__file__).parent.parent / "data"
VOCAB_FILE = (
    Path(__file__).parent.parent.parent
    / "frontend" / "src" / "data" / "zolai_vocabulary.json"
)
OUT_TRAIN = DATA_DIR / "tedim_finetune.jsonl"
OUT_VAL   = DATA_DIR / "tedim_finetune_val.jsonl"

SEED = 42
random.seed(SEED)

# ── System prompts (varied across examples for robustness) ────────────────────
_SYSTEMS = [
    (
        "You are a Tedim Chin (Zolai / Zomi pau) language assistant. "
        "Write ONLY in Tedim using standard Zolai Latin orthography. "
        "SOV word order -- verb at sentence end. "
        "Declarative sentences end with 'hi'; prayers and blessings end with 'hen'."
    ),
    (
        "You are an expert in the Tedim Chin (Zolai) language and Christian worship. "
        "All responses must be in Tedim Chin only. "
        "Required vocabulary: Pasian (God), Topa (the Lord), Zeisu Krist (Jesus Christ), "
        "Kha Siangtho (Holy Spirit), thungetna (prayer), zangtal (salvation), lungdamna (grace)."
    ),
    (
        "You are a fluent Zolai (Tedim Chin) translator and writer for AI Virtual Church. "
        "Respond only in the Tedim Chin language. "
        "Grammar: SOV, subject marker 'in', sentence-final 'hi' (declarative) or 'hen' (prayer/wish)."
    ),
]

_BILINGUAL_SYS = (
    "You are a fluent bilingual assistant in Tedim Chin (Zolai) and English. "
    "Translate Tedim text accurately into natural English."
)


def _sys() -> str:
    return random.choice(_SYSTEMS)


def _ex(user: str, assistant: str, system: str | None = None) -> dict:
    return {
        "messages": [
            {"role": "system",    "content": system or _sys()},
            {"role": "user",      "content": user},
            {"role": "assistant", "content": assistant},
        ]
    }


# ── 1. Bible examples ─────────────────────────────────────────────────────────
def _load_json(path: Path) -> dict:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _iter_verses(bible: dict):
    """Yield (book_num_str, book_name, ch_num_str, verse_num_str, text)."""
    for bk_num, bk_data in bible["book"].items():
        bk_name = bk_data["info"]["name"]
        for ch_num, ch_data in bk_data.get("chapter", {}).items():
            for v_num, v_data in ch_data.get("verse", {}).items():
                text = v_data.get("text", "").strip()
                if text:
                    yield bk_num, bk_name, ch_num, v_num, text


def bible_examples(td_bible: dict, en_bible: dict) -> list[dict]:
    examples: list[dict] = []

    # Build English verse lookup: (book_num, ch_num, verse_num) -> text
    en_lookup: dict[tuple, str] = {}
    for bk_num, bk_data in en_bible["book"].items():
        for ch_num, ch_data in bk_data.get("chapter", {}).items():
            for v_num, v_data in ch_data.get("verse", {}).items():
                en_lookup[(bk_num, ch_num, v_num)] = v_data.get("text", "").strip()

    all_verses = list(_iter_verses(td_bible))

    # All NT verses (books 40-66), 25% sample of OT
    nt_verses = [v for v in all_verses if int(v[0]) >= 40]
    ot_verses = [v for v in all_verses if int(v[0]) < 40]
    ot_sample = random.sample(ot_verses, k=int(len(ot_verses) * 0.25))
    selected  = nt_verses + ot_sample

    for bk_num, bk_name, ch_num, v_num, td_text in selected:
        ref     = bk_name + " " + ch_num + ":" + v_num
        en_text = en_lookup.get((bk_num, ch_num, v_num), "")

        # Type A -- reference lookup
        examples.append(_ex(
            user="Write " + ref + " from the Tedim Bible (Lai Siangtho).",
            assistant=td_text,
        ))

        # Type B -- English -> Tedim translation
        if en_text:
            examples.append(_ex(
                user='Translate this Bible verse to Tedim (Zolai): "' + en_text + '"',
                assistant=td_text,
            ))

        # Type C -- Tedim -> English
        if en_text:
            examples.append(_ex(
                user='Translate this Tedim verse to English: "' + td_text + '"',
                assistant=en_text,
                system=_BILINGUAL_SYS,
            ))

        # Type D -- verse completion (first half -> full verse)
        words = td_text.split()
        if len(words) >= 8:
            half = " ".join(words[: len(words) // 2])
            examples.append(_ex(
                user='Complete this Tedim Bible verse: "' + half + ' ..."',
                assistant=td_text,
            ))

    return examples


# ── 2. Hymn examples ──────────────────────────────────────────────────────────
def hymn_examples(hymns_data: dict) -> list[dict]:
    examples: list[dict] = []

    for h in hymns_data.get("hymns", []):
        title_td = h.get("title", "").strip()
        title_en = h.get("title_en", "").strip()
        lyrics   = h.get("lyrics", "").strip()
        moods    = h.get("moods", [])

        if not lyrics:
            continue

        # Type A -- English title -> Tedim lyrics
        if title_en:
            examples.append(_ex(
                user='Write the Tedim hymn "' + title_en + '" (' + title_td + ') in full.',
                assistant=lyrics,
            ))

        # Type B -- Tedim title -> full lyrics
        examples.append(_ex(
            user='Write the full lyrics of the Tedim hymn "' + title_td + '".',
            assistant=lyrics,
        ))

        # Type C -- mood-based request -> lyrics
        for mood in moods:
            if mood == "default":
                continue
            examples.append(_ex(
                user="Write a Tedim (Zolai) worship song for someone feeling " + mood + ".",
                assistant=lyrics,
            ))

        # Type D -- hymn completion (first 5 lines -> full hymn)
        lines = lyrics.splitlines()
        first_section = "\n".join(lines[:5])
        if len(lines) > 5:
            examples.append(_ex(
                user="Complete this Tedim hymn:\n" + first_section + "\n...",
                assistant=lyrics,
            ))

    return examples


# ── 3. Vocabulary examples ────────────────────────────────────────────────────
_GRAMMAR_NOTES: dict[str, str] = {
    "ka": (
        "ka = I / my (1st person singular). "
        "Example: 'Ka lungtang a dah hi.' (My heart is sad.)"
    ),
    "nang": (
        "nang = you / your (2nd person singular). "
        "Example: 'Topa in nang it hi.' (The Lord loves you.)"
    ),
    "amah": (
        "amah = he / she / it (3rd person singular). "
        "Example: 'Amah in pai hi.' (He/she went.)"
    ),
    "eite": (
        "eite = we / our (1st person plural). "
        "Example: 'Eite in Pasian phat hi.' (We praise God.)"
    ),
    "in": (
        "'in' is the subject marker in Tedim. It follows the subject noun. "
        "Example: 'Pasian in na it hi.' -- 'Pasian in' marks God as the subject."
    ),
    "hi": (
        "'hi' is the declarative sentence-final particle. Every statement ends with 'hi'. "
        "Example: 'Topa in om hi.' (The Lord exists / is here.)"
    ),
    "hen": (
        "'hen' is the prayer / blessing sentence-final particle. "
        "It closes wishes, prayers, and benedictions. "
        "Example: 'Kha Siangtho in hong makaih hen.' (May the Holy Spirit guide you.)"
    ),
    "hong": (
        "'hong' means 'to come' toward the speaker (directional verb prefix). "
        "It is placed before the main verb. "
        "Example: 'Zeisu in zangtal hong piak hi.' (Jesus comes and gives salvation.)"
    ),
    "it": (
        "'it' means 'to love'. With subject marker and sentence-final particle: "
        "'Pasian in na it hi.' (God loves you.)"
    ),
    "za": (
        "'za' means 'to hear / listen'. "
        "Example: 'Topa in na thungna hong za hi.' (The Lord hears your prayer.)"
    ),
    "piak": (
        "'piak' means 'to give'. "
        "Example: 'Zeisu in zangtal hong piak hi.' (Jesus gives salvation.)"
    ),
    "makaih": (
        "'makaih' means 'to guide / lead'. "
        "Example: 'Kha Siangtho in hong makaih hen.' (May the Holy Spirit lead you.)"
    ),
    "kem": (
        "'kem' means 'to protect / keep'. "
        "Example: 'Topa in na lungtang hong kem hen.' (May the Lord protect your heart.)"
    ),
    "om": (
        "'om' means 'to be / to exist'. "
        "Example: 'Na kiangah Pasian in om hi.' (God is near you.)"
    ),
}


def vocab_examples(vocab: list[dict]) -> list[dict]:
    examples: list[dict] = []

    for entry in vocab:
        zolai   = entry.get("zolai", "").strip()
        english = entry.get("english", "").strip()
        notes   = entry.get("notes", "").strip()

        if not (zolai and english):
            continue

        note_suffix = " Note: " + notes if notes else ""

        # Type A -- English -> Tedim word
        examples.append(_ex(
            user='How do you say "' + english + '" in Tedim (Zolai)?',
            assistant=(
                'The Tedim word for "' + english + '" is "' + zolai + '".' + note_suffix
            ),
        ))

        # Type B -- Tedim word meaning
        examples.append(_ex(
            user='What does the Tedim (Zolai) word "' + zolai + '" mean?',
            assistant=(
                '"' + zolai + '" means "' + english + '" in Tedim.' + note_suffix
            ),
        ))

        # Type C -- grammar/usage note
        if zolai in _GRAMMAR_NOTES:
            examples.append(_ex(
                user='Explain the Tedim word "' + zolai + '" and how to use it in a sentence.',
                assistant=_GRAMMAR_NOTES[zolai],
            ))

        # Type D -- avoid-confusion note (entries with "NOT X" in notes)
        if "NOT" in notes:
            wrong_word = notes.split("NOT")[1].strip().split("(")[0].strip()
            examples.append(_ex(
                user='Is "' + wrong_word + '" a Tedim word?',
                assistant=(
                    'No. The correct Tedim word is "' + zolai + '" (' + english + '). '
                    + notes
                ),
            ))

    return examples


# ── 4. Synthetic grammar and prayer examples ──────────────────────────────────
_SYNTHETIC: list[tuple[str, str]] = [
    (
        "Write an opening prayer in Tedim for someone who feels sad.",
        (
            "Aw Topa Pasian, tuni in ka lungtang hong khol in na kiangah ka hong pai hi.\n"
            "Ka lungkhamna pen nang kianga hi.\n"
            "Na lungdamna in hong kem in.\n"
            "Hih biakna hun sungah na Thu Siangtho in lam hong lak hen.\n"
            "Zeisu Krist min in ka thungen hi. Amen."
        ),
    ),
    (
        "Write an opening prayer in Tedim for someone who feels grateful.",
        (
            "Aw Topa Pasian, tuni in na kilemna tawh na kiangah ka hong pai hi.\n"
            "Na lungdamna mahmah ka mu hi; na hehpihna in ka lungtang hong dim hi.\n"
            "Hih nopna sungah nang kianga ka thungen hi.\n"
            "Na min in kumdan om hen.\n"
            "Zeisu Krist min in ka thungen hi. Amen."
        ),
    ),
    (
        "Write an opening prayer in Tedim for someone who feels anxious.",
        (
            "Aw Topa Pasian, ka lungtang sungah lauhna leh lungkhamna a om hi.\n"
            "Ahihhang nang kianga ka hong pai hi.\n"
            "Na nopna in ka lungtang hong kem hen.\n"
            "Zeisu Krist itna in ka lungtang hong kem sak hen.\n"
            "Kha Siangtho in lam hong lak hen.\n"
            "Zeisu Krist min in ka thungen hi. Amen."
        ),
    ),
    (
        "Write a closing benediction in Tedim.",
        (
            "Topa Pasian nopna in na lungtang kem hen.\n"
            "Zeisu Krist itna in hong thahat sak hen.\n"
            "Kha Siangtho in na lam hong makaih hen.\n"
            "Amen."
        ),
    ),
    (
        "Write a closing benediction in Tedim for someone who feels hopeful.",
        (
            "Topa Pasian, na lam-etna sungah eite hong makaih hen.\n"
            "Zeisu Krist zangtal in eite hong thahat sak hen.\n"
            "Kha Siangtho in ni simin eite hong makaih zel hen.\n"
            "Amen."
        ),
    ),
    (
        "Write a short sermon in Tedim about God's love.",
        (
            "Tuni in, Pasian in na it mahmah cih thute ngaihsun ding hi.\n"
            "Lai Siangtho sungah, 'Pasian in leitung a it mahmah cih hangin, "
            "a tapa khat bang zaw dawlsak hi' ci in gen hi.\n"
            "Hih itna pen vantung pan hong lak a, khanglui mun tawh khat lo hi.\n"
            "Na lungkhamna om leh, na lauhna om leh, Pasian in na kiang pan gamla om lo hi.\n"
            "Amah in na thungna hong za hi; amah in nang mangngilh lo hi.\n"
            "Zeisu Krist sungah lungdamna leh lam-etna neih thei hi.\n"
            "Topa in nang it hi -- hih thu pen siangtho leh angtang ahi hi."
        ),
    ),
    (
        "Write a short sermon in Tedim about hope.",
        (
            "Lam-etna pen Pasian' piak lungdamna ahi hi.\n"
            "Na lungsim a dim lo leh, na nuntakna a dah mahmah leh, "
            "Topa in lam-etna na kiangah hong piang sak thei hi.\n"
            "Lai Siangtho sungah, 'Ka in nang tungah ka ngenna thu ka theih hi; "
            "gimna ni ding lo in, nopna nei dingin' ci in gen hi.\n"
            "Zeisu Krist sungah lam-etna om hi -- hih thu pen tawntung man hi.\n"
            "Na lungtang a gim leh, Topa kiangah pai un; amah in hong hehpih ding hi."
        ),
    ),
    (
        "Write a welcome greeting in Tedim for someone feeling sad.",
        (
            "Dam takin kong na sang hi.\n"
            "Tuni in na lungtang a dah leh a gim mahmah leh, "
            "Topa Pasian in na kiangah om hi.\n"
            "Hih biakna hun sungah Zeisu Krist itna, "
            "nopna leh lam-etna hong thak sak hen."
        ),
    ),
    (
        "Write a welcome greeting in Tedim for someone feeling joyful.",
        (
            "Dam takin hong na sang hi.\n"
            "Tuni in na lungtang ah nopna a om cih ka mu hi.\n"
            "Hih biakna hun sungah Pasian min phat in, "
            "na lungdamna in hong dim thak sak hen."
        ),
    ),
    (
        "What is SOV word order in Tedim? Give an example.",
        (
            "Tedim uses SOV (Subject-Object-Verb) word order -- the verb always comes "
            "at the END of the sentence.\n\n"
            "Example:\n"
            "  Pasian  in  na  it  hi.\n"
            "  God    [S]  you [O] loves [V].\n\n"
            "More examples:\n"
            "  'Topa in na thungna hong za hi.' -- The Lord hears your prayer.\n"
            "  'Zeisu in zangtal hong piak hi.' -- Jesus gives salvation.\n"
            "  'Kha Siangtho in hong makaih hen.' -- May the Holy Spirit guide you."
        ),
    ),
    (
        "What are the sentence-final particles in Tedim grammar?",
        (
            "Tedim has two main sentence-final particles:\n\n"
            "1. 'hi' -- closes every declarative (statement) sentence.\n"
            "   Example: 'Pasian in na it hi.' (God loves you.)\n\n"
            "2. 'hen' -- closes prayer, blessing, and wish sentences (benedictive).\n"
            "   Example: 'Kha Siangtho in hong makaih hen.' "
            "(May the Holy Spirit guide you.)\n\n"
            "A sentence ending with 'hi' states a fact. "
            "A sentence ending with 'hen' is a prayer or wish."
        ),
    ),
    (
        "Write three Tedim sentences: one statement, one negation, one prayer.",
        (
            "Statement: 'Pasian in na it hi.' (God loves you.)\n"
            "Negation:  'Pasian in nang mangngilh lo hi.' (God does not forget you.)\n"
            "Prayer:    'Topa in na lam hong makaih hen.' (May the Lord guide your path.)"
        ),
    ),
    (
        "How do you express past, present, and future tense in Tedim?",
        (
            "Tedim tense is marked by a suffix on the verb:\n\n"
            "Present / habitual: verb + 'hi'\n"
            "  'Topa in om hi.' -- The Lord is here.\n\n"
            "Past:              verb + 'khin hi'\n"
            "  'Zeisu in hong pai khin hi.' -- Jesus came.\n\n"
            "Future:            verb + 'ding hi'\n"
            "  'Topa in hong pai ding hi.' -- The Lord will come.\n\n"
            "Continuous:        verb + 'laitak hi'\n"
            "  'Ka thungeta laitak hi.' -- I am praying.\n\n"
            "Negation:          verb + 'lo hi'\n"
            "  'Ka om lo hi.' -- I am not here."
        ),
    ),
    (
        "What is the correct Tedim word for 'church', and which words should be avoided?",
        (
            "The correct Tedim word for church is 'biakinn'.\n\n"
            "Do NOT use:\n"
            "  'koici'   -- an older romanization, no longer standard.\n"
            "  'kohhran' -- a Mizo word, not Tedim.\n\n"
            "Example: 'Ka biakinn ah pai hi.' (I went to church.)"
        ),
    ),
    (
        "What is the correct Tedim word for 'prayer', and which word should be avoided?",
        (
            "The correct Tedim word for prayer (noun) is 'thungetna'.\n"
            "The verb 'to pray' is 'thungeta'.\n\n"
            "Do NOT use 'tawngtaina' or 'tawngtai' -- those are Mizo words.\n\n"
            "Example: 'Ka thungetna in Topa kiangah pai hi.' (My prayer went to the Lord.)"
        ),
    ),
]


def synthetic_examples() -> list[dict]:
    return [_ex(user=u, assistant=a) for u, a in _SYNTHETIC]


# ── 5. Grammar guide (Paunam Khenna Leh Kampau Luanzia) ──────────────────────
# Grammar data from the user's "Paunam Khenna Leh Kampau Luanzia" guide.
# This section adds Tedim-specific rules not in the vocabulary file:
#   - Taang/Lia gender pronouns, demonstrative/reflexive/interrogative pronouns
#   - Possessive apostrophe form
#   - Full preposition list with examples
#   - Interjections
_GRAMMAR_GUIDE: list[tuple[str, str]] = [
    (
        "What are the consonants used in Tedim (Zolai) writing?",
        (
            "Tedim (Zolai) uses these consonants: B, C, D, G, H, J, K, L, M, N, P, S, T, V, W, Y, Z, "
            "and digraphs Ng, Kh, Ph, Th. "
            "Note: F, J, Q, R, X, Y are not used in standard Tedim orthography."
        ),
    ),
    (
        "What are the vowels in Tedim?",
        (
            "Tedim has 6 base vowels: a, e, i, o, u, aw. "
            "These combine to form many vowel combinations such as: ah, eh, ih, oh, uh, awh, "
            "ai, ei, oi, ui, ak, ek, ik, ok, ang, eng, ing, ong, ung, and others."
        ),
    ),
    (
        "How many parts of speech does Tedim (Zolai) have?",
        (
            "Tedim grammar recognizes 8 parts of speech (Paunam Khenna Nam Giat):\n"
            "1. Minte (Noun)\n"
            "2. Mintaang (Pronoun)\n"
            "3. Gamtatna / Sepna (Verb)\n"
            "4. Pianzia Kammalte (Adjective)\n"
            "5. Sepzia Kammal (Adverb)\n"
            "6. Telgeh / Telkheh Kammalte (Articles)\n"
            "7. Munlahna (Preposition)\n"
            "8. Thuzopna (Conjunction)\n"
            "There is also Lamdang Sakna (Interjection)."
        ),
    ),
    (
        "What are the pronouns in Tedim?",
        (
            "Tedim personal pronouns (Mimal Mintaang):\n"
            "  Kei / ka = I / my (1st singular)\n"
            "  Nang     = you (2nd singular)\n"
            "  Taang    = he (3rd singular, male)\n"
            "  Lia      = she (3rd singular, female)\n"
            "  Amah     = he / she / it (3rd singular, general)\n"
            "  Eite     = we (1st plural)\n"
            "  Note     = you (2nd plural)\n"
            "  Amau     = they (3rd plural)\n\n"
            "Note: Tedim distinguishes 'Taang' (he) and 'Lia' (she) "
            "unlike many related Chin languages."
        ),
    ),
    (
        "How is possession expressed in Tedim?",
        (
            "In Tedim, possession is expressed using an apostrophe (') after the possessor, "
            "rather than the word 'ii' (of):\n\n"
            "  'Tua pasalno' laibu' = That person's book (not 'Tua pasalno ii laibu')\n"
            "  'Nemno' ui'         = Nemo's dog\n"
            "  'Tua kumpipa' sakol' = That king's horse\n\n"
            "This apostrophe form is the standard Zolai orthography for possession."
        ),
    ),
    (
        "What are prepositions (Munlahna) in Tedim?",
        (
            "Tedim prepositions (Munlahna) indicate location and relationship:\n"
            "  sung  = inside / within\n"
            "  tung  = on top of / above\n"
            "  nuai  = below / under\n"
            "  gei   = beside\n"
            "  mai   = in front of\n\n"
            "Combined forms with vowel/consonant endings use a hyphen (-):\n"
            "  kiangah = near / beside (person)\n"
            "  tungah  = on / upon\n"
            "  nuai-ah = below\n"
            "  sungah  = in / within\n\n"
            "Example: 'Ka biakinn sungah om hi.' (I am inside the church.)"
        ),
    ),
    (
        "What are conjunctions (Thuzopna) in Tedim?",
        (
            "Tedim conjunctions (Thuzopna) connect words, phrases, and clauses:\n"
            "  leh          = and\n"
            "  napi-in      = but / however\n"
            "  zong         = also / too\n"
            "  ahih hangin  = although / even though\n"
            "  inla         = if (conditional)\n"
            "  unla         = if (plural conditional)\n\n"
            "Example: 'Pasian in na it hi leh, nang zong amah it hen.' "
            "(God loves you, and you should also love Him.)"
        ),
    ),
    (
        "What are the types of nouns (Minte) in Tedim?",
        (
            "Tedim nouns (Minte) are classified as:\n"
            "1. Neihkhawm minte (Common Nouns): pano (child), mual (hill), sakol (horse)\n"
            "2. Neihtuam minte (Proper Nouns): Tedim, Zogam, Pum Za Mang "
            "   (always start with a capital letter)\n"
            "3. Lawnmawh minte (Abstract Nouns): cidamna (health), natna (sickness), "
            "   thumanna (peace), hansanna (joy)\n"
            "4. Honlawhna minte (Collective Nouns): mi honkhat (a group of people)"
        ),
    ),
    (
        "What types of verbs (Sepna) are there in Tedim?",
        (
            "Tedim verbs (Gamtatna / Sepna) are classified as:\n"
            "1. A thuak kisam sepna (Transitive Verb): requires a direct object.\n"
            "   Example: 'Bawng in lopa a ne hi.' (The cow eats grass.)\n"
            "2. A thuak kullo sepna (Intransitive Verb): no direct object needed.\n"
            "   Example: 'Thangpu a tai hi.' (Thangpu walked.)\n"
            "3. A cingtaklo / A huh sepna (Incomplete / Linking Verb): tawltuak, hehsuak."
        ),
    ),
    (
        "How are adjectives (Pianzia Kammalte) used in Tedim?",
        (
            "Tedim adjectives (Pianzia Kammalte) describe nouns and come in these types:\n"
            "1. Quality (Phacia lak): hoih (good), dik (right), hau (rich), sang (tall)\n"
            "2. Quantity (Phazah lak): pawlkhat (some), tampi (many), tawmkha (few)\n"
            "3. Number (Amalzah lak): nga (five), sawmnih (twenty)\n"
            "4. Demonstrative (Lahkhiatna lak): hua (that), hih (this)\n"
            "5. Interrogative (Dotna lak): a bangci (what kind), koipen (which)\n"
            "6. Possessive (Neihna lak): ka laibu (my book), na sakol (your horse)\n\n"
            "Example: 'Pasian in hoih mahmah hi.' (God is very good.)"
        ),
    ),
    (
        "How are adverbs (Sepzia Kammal) used in Tedim?",
        (
            "Tedim adverbs (Sepzia Kammal) modify verbs and come in these types:\n"
            "1. Manner (Sepzia lak): dam takin (well/healthily), ngaih takin (carefully)\n"
            "2. Time (Sepzia hun lak): zan (night), baih (early), zingciang (morning)\n"
            "3. Place (Sepzia mun lak): to (here), tung (up there), hihlai (this place)\n"
            "4. Degree (Sepzia tehna lak): mahmah (very), dektak (truly), hiathiat (repeatedly)\n\n"
            "Example: 'Topa in ka thungna dam takin hong za hi.' "
            "(The Lord hears my prayer attentively.)"
        ),
    ),
    (
        "How do you say 'God loves you' in Tedim?",
        "Pasian in na it hi.",
    ),
    (
        "How do you say 'The Lord hears your prayer' in Tedim?",
        "Topa in na thungna hong za hi.",
    ),
    (
        "How do you say 'Jesus gives salvation' in Tedim?",
        "Zeisu in zangtal hong piak hi.",
    ),
    (
        "How do you say 'May the Holy Spirit guide you' in Tedim?",
        "Kha Siangtho in hong makaih hen.",
    ),
    (
        "How do you say 'The Lord is near you' in Tedim?",
        "Na kiangah Topa in om hi.",
    ),
    (
        "How do you say 'God does not forget you' in Tedim?",
        "Pasian in nang mangngilh lo hi.",
    ),
    (
        "How do you say 'I am praying' in Tedim?",
        "Ka thungeta laitak hi.",
    ),
    (
        "How do you say 'My heart is sad' in Tedim?",
        "Ka lungtang a dah hi.",
    ),
    (
        "How do you say 'We praise God' in Tedim?",
        "Eite in Pasian phat hi.",
    ),
    (
        "Translate to Tedim: 'May the Lord protect your heart.'",
        "Topa in na lungtang hong kem hen.",
    ),
    (
        "Translate to Tedim: 'May grace cover you.'",
        "Lungdamna in hong tuam in.",
    ),
    (
        "Translate to Tedim: 'Jesus Christ gives salvation.'",
        "Zeisu Krist in zangtal hong piak hi.",
    ),
    # ── Additional entries from Paunam Khenna Leh Kampau Luanzia ──────────────
    (
        "In Tedim, what words mean 'he' and 'she'? How is this different from other Chin languages?",
        (
            "Tedim distinguishes grammatical gender with two separate pronouns:\n"
            "  Taang = he (3rd person singular, male)\n"
            "  Lia   = she (3rd person singular, female)\n\n"
            "The gender-neutral form 'Amah' covers he/she/it in general reference.\n\n"
            "This is distinctive -- many related Chin languages use only one "
            "form for he/she. Example:\n"
            "  'Taang in pai hi.'   (He went.)\n"
            "  'Lia in hong pai hi.' (She came.)"
        ),
    ),
    (
        "What are the demonstrative pronouns in Tedim?",
        (
            "Tedim demonstrative pronouns (Lahkhiatna Mintaang):\n"
            "  Hih in   = this (singular, near)\n"
            "  Hihte in = these (plural, near)\n"
            "  Hua in   = that (singular, far)\n"
            "  Huate in = those (plural, far)\n\n"
            "Example: 'Hih in ka laibu hi.' (This is my book.)\n"
            "Example: 'Hua in topa biakinn hi.' (That is the Lord's church.)"
        ),
    ),
    (
        "What are the reflexive pronouns in Tedim?",
        (
            "Tedim reflexive pronouns (Tungtukik Mintaang) are formed by adding 'mahmah':\n"
            "  Kei mahmah    = myself\n"
            "  Nang mahmah   = yourself\n"
            "  Amah leh amah = himself / herself\n"
            "  Eimau mahmah  = ourselves\n\n"
            "Example: 'Ka mahmah in bawl hi.' (I did it myself.)"
        ),
    ),
    (
        "What are the interrogative pronouns in Tedim?",
        (
            "Tedim interrogative pronouns (Dotna Mintaang):\n"
            "  Kua  = who\n"
            "  Koi  = where / which\n"
            "  Bang = what\n\n"
            "Examples:\n"
            "  'Kua in hong pai hi?' (Who came?)\n"
            "  'Nang koi pan hong pai hi?' (Where did you come from?)\n"
            "  'Tua pen bang hi?' (What is that?)"
        ),
    ),
    (
        "What are the possessive pronouns in Tedim?",
        (
            "Tedim possessive pronouns (Neihna Mintaang) end with 'a':\n"
            "  Kei a   = mine\n"
            "  Nanga   = yours\n"
            "  Taang'a = his\n"
            "  Lia'a   = hers\n"
            "  Ei a    = ours\n"
            "  Amau a  = theirs\n\n"
            "Example: 'Hih laibu pen kei a hi.' (This book is mine.)"
        ),
    ),
    (
        "How do you write interjections in Tedim?",
        (
            "Tedim interjections (Lamdang Sakna) express emotion or surprise:\n"
            "  Oh!        = Oh!\n"
            "  Hallo!     = Hello!\n"
            "  Ah!        = Ah!\n"
            "  Alas!      = Alas!\n"
            "  Alaihsaih! = expression of dismay / oh no!\n"
            "  Nuvaw!     = expression of wonder / wow!\n"
            "  Pa-ei!     = expression of frustration or surprise\n\n"
            "Example in prayer: 'Aw Topa Pasian!' (Oh Lord God!) -- "
            "'Aw' is the devotional interjection used to address God."
        ),
    ),
    (
        "What are articles in Tedim?",
        (
            "Tedim has two types of articles (Telgeh / Telkheh Kammalte):\n\n"
            "1. Indefinite article: 'khat' (= a / an)\n"
            "   Example: 'Mawtaw car khat' (a car)\n\n"
            "2. Definite article: 'Tua ... pen' or 'Tua' (= the)\n"
            "   Example: 'Tua sakol pen' (the horse)"
        ),
    ),
    (
        "What are abstract nouns (Lawnmawh Minte) in Tedim?",
        (
            "Tedim abstract nouns (Lawnmawh Minte) name intangible concepts:\n"
            "  cidamna  = health\n"
            "  natna    = sickness / suffering\n"
            "  thumanna = peace\n"
            "  hansanna = joy / happiness\n"
            "  itna     = love\n"
            "  lungdamna = grace / blessing\n"
            "  lam-etna = hope\n"
            "  lauhna   = fear\n\n"
            "Example: 'Pasian in thumanna hong piak hi.' (God gives peace.)"
        ),
    ),
    (
        "Translate to Tedim: 'Who came to church today?'",
        "Tuni biakinn ah kua in hong pai hi?",
    ),
    (
        "Translate to Tedim: 'Where are you from?'",
        "Nang koi pan hong pai hi?",
    ),
    (
        "Translate to Tedim: 'That is the Lord's church.'",
        "Hua in Topa' biakinn hi.",
    ),
    (
        "Translate to Tedim: 'He went to church.'",
        "Taang in biakinn ah pai hi.",
    ),
    (
        "Translate to Tedim: 'She is praying.'",
        "Lia in thungeta laitak hi.",
    ),
    (
        "Translate to Tedim: 'This book is mine.'",
        "Hih laibu pen kei a hi.",
    ),
    (
        "Translate to Tedim: 'God gives peace.'",
        "Pasian in thumanna hong piak hi.",
    ),
    (
        "Translate to Tedim: 'May joy fill your heart.'",
        "Hansanna in na lungtang hong dim sak hen.",
    ),
]


def grammar_guide_examples() -> list[dict]:
    return [_ex(user=u, assistant=a) for u, a in _GRAMMAR_GUIDE]


# ── 6. Assemble, shuffle, split ───────────────────────────────────────────────
def main():
    print("Loading data files ...", flush=True)
    td_bible   = _load_json(DATA_DIR / "tedim1932.json")
    en_bible   = _load_json(DATA_DIR / "bsb.json")
    hymns_data = _load_json(DATA_DIR / "hymns_td.json")
    with open(VOCAB_FILE, encoding="utf-8") as f:
        vocab = json.load(f)

    print("Generating Bible examples ...", flush=True)
    bible_ex = bible_examples(td_bible, en_bible)
    print(f"  -> {len(bible_ex):,} Bible examples")

    print("Generating hymn examples ...", flush=True)
    hymn_ex = hymn_examples(hymns_data)
    print(f"  -> {len(hymn_ex):,} hymn examples")

    print("Generating vocabulary examples ...", flush=True)
    vocab_ex = vocab_examples(vocab)
    print(f"  -> {len(vocab_ex):,} vocabulary examples")

    print("Generating synthetic prayer/sermon examples ...", flush=True)
    synth_ex = synthetic_examples()
    print(f"  -> {len(synth_ex):,} synthetic examples")

    print("Generating grammar guide examples ...", flush=True)
    gram_ex = grammar_guide_examples()
    print(f"  -> {len(gram_ex):,} grammar guide examples")

    all_examples = bible_ex + hymn_ex + vocab_ex + synth_ex + gram_ex
    random.shuffle(all_examples)
    print(f"\nTotal examples: {len(all_examples):,}")

    split = int(len(all_examples) * 0.9)
    train = all_examples[:split]
    val   = all_examples[split:]

    print(f"Writing {len(train):,} training examples -> {OUT_TRAIN}")
    with open(OUT_TRAIN, "w", encoding="utf-8") as f:
        for ex in train:
            f.write(json.dumps(ex, ensure_ascii=False) + "\n")

    print(f"Writing {len(val):,} validation examples -> {OUT_VAL}")
    with open(OUT_VAL, "w", encoding="utf-8") as f:
        for ex in val:
            f.write(json.dumps(ex, ensure_ascii=False) + "\n")

    print("\nDone! Sample training examples:")
    for ex in random.sample(all_examples[:100], 3):
        msgs = ex["messages"]
        print(f"\n[system] {msgs[0]['content'][:80]}...")
        print(f"[user]   {msgs[1]['content'][:120]}")
        print(f"[asst]   {msgs[2]['content'][:120]}")


if __name__ == "__main__":
    main()
