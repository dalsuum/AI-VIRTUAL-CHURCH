"""Curated public-domain hymn library (Open Hymnal Project).

This is the single source of truth shared by two callers:

  * seed_hymns.py        — renders each hymn's MIDI to an MP3 and stores it under
                           the `hymns/` key, once per machine (like a DB seed).
  * HymnStrategy.fetch()  — at service time, picks a mood-appropriate hymn and
                           hands back the already-stored MP3. No network, no AI
                           credit: the audio was produced ahead of time.

Every hymn below is firmly in the public domain (text and tune), and its `midi`
filename exists verbatim in Open Hymnal's `OpenHymnal2014.06-midi.zip`. `moods`
are lowercase trigger words matched against the worshipper's mood/prompt; a hymn
tagged "default" is eligible when nothing else matches, so selection never fails.
"""

from __future__ import annotations

import random

# slug, title, author, year, scripture, midi (Open Hymnal filename), moods
HYMNS: list[dict] = [
    {
        "slug": "amazing-grace", "title": "Amazing Grace", "author": "John Newton",
        "year": 1779, "scripture": "Ephesians 2:8", "midi": "Amazing_Grace-New_Britain.mid",
        "moods": {"grateful", "seeking", "hope", "hopeful", "grace", "default"},
    },
    {
        "slug": "it-is-well", "title": "It Is Well With My Soul", "author": "Horatio Spafford",
        "year": 1873, "scripture": "Psalm 46:1", "midi": "It_Is_Well_With_My_Soul-It_Is_Well-Ville_Du_Havre.mid",
        "moods": {"grieving", "anxious", "peace", "comfort", "loss"},
    },
    {
        "slug": "be-still-my-soul", "title": "Be Still, My Soul", "author": "Katharina von Schlegel",
        "year": 1752, "scripture": "Psalm 46:10", "midi": "Be_Still_My_Soul-Finlandia.mid",
        "moods": {"anxious", "grieving", "peace", "comfort", "fear"},
    },
    {
        "slug": "abide-with-me", "title": "Abide With Me", "author": "Henry F. Lyte",
        "year": 1847, "scripture": "Luke 24:29", "midi": "Abide_With_Me-Eventide.mid",
        "moods": {"grieving", "comfort", "loss", "lonely"},
    },
    {
        "slug": "nearer-my-god", "title": "Nearer, My God, to Thee", "author": "Sarah F. Adams",
        "year": 1841, "scripture": "Genesis 28:12", "midi": "Nearer_My_God_To_Thee-Bethany.mid",
        "moods": {"grieving", "seeking", "comfort"},
    },
    {
        "slug": "come-ye-disconsolate", "title": "Come, Ye Disconsolate", "author": "Thomas Moore",
        "year": 1816, "scripture": "Psalm 34:18", "midi": "Come_Ye_Disconsolate-Consolator_Webbe.mid",
        "moods": {"grieving", "anxious", "comfort", "loss"},
    },
    {
        "slug": "what-a-friend", "title": "What a Friend We Have in Jesus", "author": "Joseph M. Scriven",
        "year": 1855, "scripture": "1 Peter 5:7", "midi": "What_A_Friend_We_Have_In_Jesus-untitled.mid",
        "moods": {"anxious", "grieving", "comfort", "prayer", "lonely"},
    },
    {
        "slug": "guide-me", "title": "Guide Me, O Thou Great Jehovah", "author": "William Williams",
        "year": 1745, "scripture": "Exodus 13:21", "midi": "Guide_Me_O_Thou_Great_Jehovah-Cwm_Rhondda.mid",
        "moods": {"anxious", "seeking", "hope", "hopeful", "journey"},
    },
    {
        "slug": "how-firm-a-foundation", "title": "How Firm a Foundation", "author": "John Rippon's Selection",
        "year": 1787, "scripture": "Isaiah 41:10", "midi": "How_Firm_A_Foundation-Foundation-Protection.mid",
        "moods": {"anxious", "hope", "hopeful", "fear", "assurance"},
    },
    {
        "slug": "my-hope-is-built", "title": "My Hope Is Built (The Solid Rock)", "author": "Edward Mote",
        "year": 1834, "scripture": "Matthew 7:24", "midi": "My_Hope_Is_Built-Melita.mid",
        "moods": {"hope", "hopeful", "anxious", "assurance"},
    },
    {
        "slug": "joyful-joyful", "title": "Joyful, Joyful, We Adore Thee", "author": "Henry van Dyke",
        "year": 1907, "scripture": "Psalm 98:4", "midi": "Joyful_Joyful_We_Adore_Thee-Ode_To_Joy.mid",
        "moods": {"joyful", "grateful", "praise", "joy", "happy"},
    },
    {
        "slug": "now-thank-we", "title": "Now Thank We All Our God", "author": "Martin Rinkart",
        "year": 1636, "scripture": "1 Thessalonians 5:18", "midi": "Now_Thank_We_All_Our_God-Nun_Danket.mid",
        "moods": {"grateful", "joyful", "thanks", "thankful", "praise"},
    },
    {
        "slug": "praise-to-the-lord", "title": "Praise to the Lord, the Almighty", "author": "Joachim Neander",
        "year": 1680, "scripture": "Psalm 103:1", "midi": "Praise_To_The_Lord_The_Almighty-Lobe_Den_Herren.mid",
        "moods": {"grateful", "joyful", "praise"},
    },
    {
        "slug": "all-creatures", "title": "All Creatures of Our God and King", "author": "St. Francis of Assisi",
        "year": 1225, "scripture": "Psalm 148", "midi": "All_Creatures_Of_Our_God_And_King-Lasst_Uns_Erfreuen.mid",
        "moods": {"joyful", "grateful", "praise"},
    },
    {
        "slug": "to-god-be-the-glory", "title": "To God Be the Glory", "author": "Fanny J. Crosby",
        "year": 1875, "scripture": "John 3:16", "midi": "To_God_Be_the_Glory-To_God_Be_the_Glory.mid",
        "moods": {"joyful", "grateful", "praise"},
    },
    {
        "slug": "holy-holy-holy", "title": "Holy, Holy, Holy", "author": "Reginald Heber",
        "year": 1826, "scripture": "Revelation 4:8", "midi": "Holy_Holy_Holy-Nicaea.mid",
        "moods": {"praise", "reverence", "default"},
    },
    {
        "slug": "crown-him", "title": "Crown Him with Many Crowns", "author": "Matthew Bridges",
        "year": 1851, "scripture": "Revelation 19:12", "midi": "Crown_Him_With_Many_Crowns-Diademata.mid",
        "moods": {"joyful", "praise"},
    },
    {
        "slug": "be-thou-my-vision", "title": "Be Thou My Vision", "author": "Irish hymn, tr. Mary E. Byrne",
        "year": 1905, "scripture": "Proverbs 3:5", "midi": "Be_Thou_My_Vision-Slane.mid",
        "moods": {"seeking", "hope", "hopeful", "devotion", "default"},
    },
    {
        "slug": "come-thou-fount", "title": "Come, Thou Fount of Every Blessing", "author": "Robert Robinson",
        "year": 1758, "scripture": "1 Samuel 7:12", "midi": "Come_Thou_Fount-Nettleton.mid",
        "moods": {"grateful", "seeking", "default"},
    },
    {
        "slug": "blessed-assurance", "title": "Blessed Assurance", "author": "Fanny J. Crosby",
        "year": 1873, "scripture": "Hebrews 10:22", "midi": "Blessed_Assurance-Blessed_Assurance-Assurance.mid",
        "moods": {"hope", "hopeful", "joyful", "assurance", "grateful"},
    },
    {
        "slug": "rock-of-ages", "title": "Rock of Ages", "author": "Augustus M. Toplady",
        "year": 1763, "scripture": "Psalm 94:22", "midi": "Rock_of_Ages-Toplady.mid",
        "moods": {"seeking", "hope", "refuge", "anxious"},
    },
    {
        "slug": "pass-me-not", "title": "Pass Me Not, O Gentle Savior", "author": "Fanny J. Crosby",
        "year": 1868, "scripture": "Luke 18:38", "midi": "Pass_Me_Not_O_Gentle_Savior-Pass_Me_Not_O_Gentle_Savior.mid",
        "moods": {"seeking", "grieving", "prayer"},
    },
    {
        "slug": "i-need-thee", "title": "I Need Thee Every Hour", "author": "Annie S. Hawks",
        "year": 1872, "scripture": "John 15:5", "midi": "I_Need_Thee_Every_Hour-I_Need_Thee_Every_Hour.mid",
        "moods": {"seeking", "anxious", "prayer", "default"},
    },
]

# Public-domain SUNG recordings from the Internet Archive 78rpm collection. Each was
# published in 1925 or earlier, so the SOUND RECORDING is public domain in the US
# (Music Modernization Act); the hymn text/tune is 19th-century or older, so both
# layers are clear. Sourced and verified (right hymn + a singer present + fetchable
# mp3) by source_recordings.py. Hymns NOT listed here have no pre-1925 vocal take —
# in sung mode the selector simply won't consider them; instrumental mode covers all.
RECORDINGS: dict[str, dict] = {
    "it-is-well": {"performer": "Stanley & Gillette", "year": 1914,
        "url": "https://archive.org/download/78_it-is-well-with-my-soul_stanley-gillette-bliss_gbia3029098b/IT%20IS%20WELL%20WITH%20MY%20SOUL%20-%20STANLEY%20%26%20GILLETTE.mp3"},
    "abide-with-me": {"performer": "Phillip Ritte & James Saker", "year": 1910,
        "url": "https://archive.org/download/78_abide-with-me_messrs-phillip-ritte-and-james-saker-monk_gbia3038628a/ABIDE%20WITH%20ME%20-%20Messrs.%20PHILLIP%20RITTE%20and%20JAMES%20SAKER.mp3"},
    "nearer-my-god": {"performer": "Male Quartette", "year": 1908,
        "url": "https://archive.org/download/78_nearer-my-god-to-thee_mason_gbia0482051a/NEARER%2C%20MY%20GOD%2C%20TO%20THEE%20-%20Mason.mp3"},
    "come-ye-disconsolate": {"performer": "Mabel Garrison", "year": 1920,
        "url": "https://archive.org/download/78_come-ye-disconsolate_mabel-garrison-thomas-moore-samuel-webbe_gbia7019206a/Come%2C%20Ye%20Disconsolate%20-%20Mabel%20Garrison%20-%20Thomas%20Moore.mp3"},
    "what-a-friend": {"performer": "Metropolitan Quartet", "year": 1920,
        "url": "https://archive.org/download/78_what-a-friend-we-have-in-jesus_metropolitan-quartet-charles-g-converse_gbia0078158b/What%20A%20Friend%20We%20Have%20In%20Jesus%20-%20Metropolitan%20Quartet.mp3"},
    "how-firm-a-foundation": {"performer": "Metropolitan Quartet", "year": 1925,
        "url": "https://archive.org/download/edison-80858_01_10629/cusb_ed_80858_01_10629_0b.mp3"},
    "now-thank-we": {"performer": "Church Choir", "year": 1925,
        "url": "https://archive.org/download/78_now-thank-we-all-our-god_church-choir-j-crger_gbia3044177b/Now%20thank%20we%20all%20our%20God%20-%20Church%20Choir%20-%20J.%20CR%C3%9CGER.mp3"},
    "come-thou-fount": {"performer": "Metropolitan Quartet", "year": 1921,
        "url": "https://archive.org/download/78_come-thou-fount-of-every-blessing_john-wyeth-metropolitan-quartet_gbia0083652a/Come%2C%20Thou%20Fount%20Of%20Every%20Blessing%20-%20John%20Wyeth.mp3"},
    "rock-of-ages": {"performer": "Irving Gillette", "year": 1910,
        "url": "https://archive.org/download/78_rock-of-ages_irving-gillette-dr-hastings_gbia3029385a/ROCK%20OF%20AGES%20-%20IRVING%20GILLETTE%20-%20Dr.%20Hastings.mp3"},
    "i-need-thee": {"performer": "Alma Gluck & Louise Homer", "year": 1914,
        "url": "https://archive.org/download/78_i-need-thee-every-hour_alma-gluck-louise-homer-annie-s-hawks-robert-lowry_gbia0016538b/I%20Need%20Thee%20Every%20Hour%20-%20Alma%20Gluck%20-%20Louise%20Homer.mp3"},
}

# Attach each hymn's recording (or None) onto its catalog entry.
for _h in HYMNS:
    _h["recording"] = RECORDINGS.get(_h["slug"])

BY_SLUG: dict[str, dict] = {h["slug"]: h for h in HYMNS}


def select(*, mood: str = "", prompt: str = "", query: str = "", eligible: set[str] | None = None) -> dict | None:
    """Pick a hymn whose mood tags best match the worshipper's input.

    `eligible` (optional) restricts the pool to slugs known to be seeded, so we
    never hand back an MP3 that was never rendered.

    The worshipper's chosen `mood` ("Grateful", "Grieving", ...) is the primary
    signal: we match on it first. The LLM-written `prompt`/`query` are only a
    fallback, NOT mixed in — they're verbose worship prose that mentions the same
    generic words ("hope", "grace", "praise", "comfort") for every service, so
    scoring against them pins one hymn per machine regardless of the chosen mood.
    Ties and no-match both resolve by random choice (varying the hymn across
    repeat services); the no-match pool is the "default"-tagged hymns, so this
    always returns one.
    """
    pool = [h for h in HYMNS if eligible is None or h["slug"] in eligible]
    if not pool:
        return None

    def _best(text: str) -> list[dict]:
        text = text.lower()
        scored = [(sum(1 for tag in h["moods"] if tag in text), h) for h in pool]
        best = max(score for score, _ in scored)
        return [h for score, h in scored if score == best] if best > 0 else []

    matches = (
        _best(mood)                          # the worshipper's chosen feeling wins
        or _best(f"{prompt} {query}")        # then the LLM's prose, only if mood missed
        or [h for h in pool if "default" in h["moods"]]
        or pool
    )

    return random.choice(matches)
