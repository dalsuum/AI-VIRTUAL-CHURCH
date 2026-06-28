"""Tests for the scripture engine (Phase 8) against the real local corpus."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from core import scripture  # noqa: E402


def test_single_verse_resolves():
    vo = scripture.resolve_ref("John 3:16", "kjv")
    assert vo.resolved is True
    assert vo.book_num == 43 and vo.chapter == 3
    assert vo.verse_start == 16 and vo.verse_end == 16
    assert vo.canonical_id == "043003016"
    assert "loved" in vo.text.lower()
    assert vo.translation == "kjv"
    assert vo.translation_fallback is False


def test_canonical_id_format():
    assert scripture.canonical_id(43, 3, 16) == "043003016"
    assert scripture.canonical_id(1, 1, 1) == "001001001"


def test_range_resolves_and_sets_end():
    vo = scripture.resolve_ref("Psalm 23:1-4", "kjv")
    assert vo.resolved is True
    assert vo.verse_start == 1 and vo.verse_end == 4
    assert vo.canonical_end == scripture.canonical_id(vo.book_num, vo.chapter, 4)


def test_whole_chapter_has_null_verse_start():
    vo = scripture.resolve_ref("Romans 8", "kjv")
    assert vo.resolved is True
    assert vo.verse_start is None
    assert vo.canonical_id.endswith("000")


def test_long_range_is_truncated():
    vo = scripture.resolve_ref("Psalm 119", "kjv")  # 176 verses
    assert vo.resolved is True
    assert vo.truncated is True


def test_unparseable_ref_is_unresolved_not_fabricated():
    vo = scripture.resolve_ref("Hesitations 9:99", "kjv")
    assert vo.resolved is False
    assert vo.text == ""
    assert vo.canonical_id == ""


def test_world_language_bibles_resolve():
    # Public-domain / CC world-language Bibles vendored from dalsuum/bible.
    # English references resolve against the canonical book index; native text
    # is returned without fabrication or fallback.
    for lang in ("de", "fr", "ta"):
        vo = scripture.resolve_ref("John 3:16", lang)
        assert vo.resolved is True, lang
        assert vo.translation == lang and vo.translation_fallback is False, lang
        assert vo.book_num == 43 and vo.chapter == 3 and vo.verse_start == 16, lang
        assert vo.text.strip(), lang


def test_list_books_carries_english_aliases_for_non_english():
    # Non-English Bibles expose English name/abbr aliases so a book is findable
    # by its English name as well as its native heading (Bible reader search).
    import bible_api
    ta = bible_api.list_books("ta")
    assert ta[0]["name"] != "Genesis"  # native Tamil heading
    aliases = [a.lower() for a in ta[0]["aliases"]]
    assert "genesis" in aliases and "gen" in aliases


def test_bible_discover_license_triage():
    # The discovery helper's conservative verdict: public-domain by age, free CC
    # grants pass, anything copyrighted or NC/ND is restricted, unclear → review.
    import importlib.util
    spec = importlib.util.spec_from_file_location(
        "bible_discover", os.path.join(os.path.dirname(__file__), "tools", "bible_discover.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    assert mod.verdict(1912, "") == "public-domain"
    assert mod.verdict(2019, "Creative Commons CC BY-SA") == "free-license"
    assert mod.verdict(1611, "CC BY-NC-ND") == "restricted"
    assert mod.verdict(1978, "Copyright © 1978 Biblica") == "restricted"
    assert mod.verdict(2012, "") == "review"  # modern, no metadata → human checks


def test_unknown_translation_falls_back():
    vo = scripture.resolve_ref("John 3:16", "niv")  # not vendored in v1
    assert vo.translation_fallback is True
    assert vo.translation == scripture._FALLBACK_TRANSLATION
    assert vo.resolved is True


def test_immutability():
    vo = scripture.resolve_ref("John 3:16", "kjv")
    try:
        vo.text = "tampered"  # type: ignore[misc]
        assert False, "VerseObject must be frozen"
    except Exception:
        pass


def test_detect_refs_finds_multiple_and_dedups():
    text = "As John 3:16 says, and again in John 3:16, compare Romans 8:28 too."
    refs = scripture.detect_refs(text, "kjv")
    cids = {r.canonical_id for r in refs}
    assert "043003016" in cids
    assert "045008028" in cids
    assert len(refs) == len(cids)  # deduped


def test_detect_refs_prefilter_skips_plain_text():
    assert scripture.detect_refs("Grace and peace to you all.", "kjv") == []


def test_detect_refs_bounded():
    text = " ".join(f"John 3:{n}" for n in range(1, 20))
    refs = scripture.detect_refs(text, "kjv", max_refs=5)
    assert len(refs) <= 5


def test_ordinal_book_detected():
    refs = scripture.detect_refs("See 1 John 4:8 about love.", "kjv")
    assert any(r.book_name and r.chapter == 4 and r.verse_start == 8 for r in refs)


if __name__ == "__main__":
    import traceback

    fns = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    passed = 0
    for fn in fns:
        try:
            fn()
            print(f"PASS {fn.__name__}")
            passed += 1
        except Exception:
            print(f"FAIL {fn.__name__}")
            traceback.print_exc()
    print(f"\n{passed}/{len(fns)} passed")
    sys.exit(0 if passed == len(fns) else 1)
