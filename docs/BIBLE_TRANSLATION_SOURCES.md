# Bible Translation Sources

Machine-readable Bible texts may be bundled only when redistribution rights are
clear. GitHub availability alone is not enough.

| Code | Translation | Local File | Source | License / Redistribution |
| ---- | ----------- | ---------- | ------ | ------------------------ |
| `ja` | Colloquial Japanese (1955) | `workers/data/japanese_colloquial1955.json` | `dalsuum/bible` JSON identify `81`, matching CrossWire `JapKougo` | Public Domain. CrossWire identifies the 1954/1955 Kougo-yaku text as copyright-expired and distributed under Public Domain. |
| `hi` | Hindi Indian Revised Version (2019) | `workers/data/hindi_irv2019.json` | eBible `hin2017` USFM, converted by `workers/tools/build_hindi_irv_bible.py` | Creative Commons Attribution-ShareAlike 4.0. eBible grants share/redistribute permission with attribution and ShareAlike terms. |
| `ar` | Arabic Van Dyck Bible | `workers/data/arabic_vandyke.json` | eBible `arb-vd` USFM, converted by `workers/tools/build_ebible_bible.py` | Public Domain. eBible marks the Arabic Van Dyck text public domain. |
| `zh-CN` | Chinese Union Version (Simplified) | `workers/data/chinese_union_simplified.json` | eBible `cmn-cu89s` USFM, converted by `workers/tools/build_ebible_bible.py` | Public Domain. eBible marks the Chinese Union Version (simplified) text public domain. |
| `es` | Reina Valera 1909 | `workers/data/spanish_rv1909.json` | eBible `spaRV1909` USFM, converted by `workers/tools/build_ebible_bible.py` | Public Domain. eBible marks the Reina Valera 1909 text public domain. |
| `th` | Thai KJV Bible | `workers/data/thai_kjv.json` | eBible `thaKJV` USFM, converted by `workers/tools/build_ebible_bible.py` | Creative Commons Attribution-NonCommercial-NoDerivatives 4.0. eBible grants sharing/redistribution in any format when copyright/source attribution is included; noncommercial and no-derivatives restrictions apply. |
| `ko` | Korean Revised Version | `workers/data/korean_krv.json` | getBible v2 `korean` module, converted by `workers/tools/build_getbible_bible.py`; CrossWire `KorRV` also identifies the Korean Revised Version as Wikisource/Public Domain | Public Domain. getBible lists the Korean module as Public Domain with Wikisource as source; CrossWire lists `KorRV` as Public Domain. |

## Copyrighted Drop-In Slots

These translations are registered as disabled placeholders only. Do not commit
their Bible text to this repository unless the project has explicit redistribution
rights for the full text.

| Code | Translation | Expected Local File / Override | Source Metadata | Bundling Decision |
| ---- | ----------- | ------------------------------ | --------------- | ----------------- |
| `ja-jcb` | リビングバイブル (JCB), Japanese Contemporary Bible, 1978 | `workers/data/japanese_jcb.json` or `BIBLE_DATA_FILE_JA_JCB` | `dalsuum/bible` manifest identify `83`; publisher Biblica, Inc.; copyright states Japanese Contemporary Bible (リビングバイブル) Copyright © 1978, 2011, 2016 by Biblica, Inc. | Not bundled. Biblica marks the text as all rights reserved / used by permission; full-Bible usage requires a license. |
| `zh-CN-ccb` | 当代译本 (CCB), Chinese Contemporary Bible, 1979 | `workers/data/chinese_ccb_simplified.json` or `BIBLE_DATA_FILE_ZH_CN_CCB` | `dalsuum/bible` manifest identify `36`; publisher Biblica, Inc.; Chinese Contemporary Bible 2022 (Simplified). | Not bundled. Biblica marks the text as all rights reserved / used by permission; full-Bible usage requires a license. |

Importer notes:

- Japanese is already in the shared `dalsuum/bible` schema and is copied without
  text transformation.
- Hindi is built from USFM rather than the convenience JSON feed so footnotes and
  cross-reference apparatus can be stripped before reader, search, and AI use.
- Arabic, Chinese Simplified, Spanish, and Thai are built from eBible USFM.
- Korean is built from getBible because eBible's public-domain `kor` USFM bundle
  currently has all 66 book files but is missing 1 Peter chapter 5; getBible's
  public-domain Korean module has the full canonical chapter set.
- Thai is redistributable but more restricted than the other bundled sources:
  preserve attribution and do not use it outside the CC BY-NC-ND 4.0 terms.
- JCB and CCB are hidden by default. A licensed deployment may install same-schema
  full-canon JSON at the expected path or set the environment override, then enable
  the corresponding row in Admin Console -> Bible.
- All files use canonical 1-66 book numbering and the existing
  `book -> chapter -> verse -> {"text": ...}` schema consumed by
  `workers/bible_api.py`.
