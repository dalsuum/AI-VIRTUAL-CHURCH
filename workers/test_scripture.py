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


def test_norm_folds_latin_accents_but_preserves_other_scripts():
    import bible_api
    # Latin accents fold + casefold so accented queries match plain ASCII keys.
    assert bible_api._norm("Genèse") == bible_api._norm("genese")
    assert bible_api._norm("CAFÉ") == "cafe"
    # Non-Latin combining marks (Tamil/Arabic) are NOT stripped — they carry
    # meaning and live outside U+0300–U+036F.
    tamil = "ஆதியாகமம்"
    assert bible_api._norm(tamil) == tamil.casefold()
    # Arabic harakat (vowel marks, U+064B–U+0652) survive normalization.
    normalized = bible_api._norm("الْكِتَاب")
    assert any(0x064B <= ord(c) <= 0x0652 for c in normalized)


def test_accented_book_name_resolves_after_normalization():
    import bible_api
    # French/German book names with accents normalize for the reader's book index.
    fr = bible_api.list_books("fr")
    names = {bible_api._norm(b["name"]) for b in fr}
    assert bible_api._norm("Genèse") in names


def test_books_meta_passes_validator():
    # The ontology is a verified artifact: the structural validator must be clean
    # (canon completeness, stable unique ids, testament/category ids, no duplicate
    # or cross-book-ambiguous aliases, referential integrity of related_books).
    import json, os, importlib.util, bible_api
    spec = importlib.util.spec_from_file_location(
        "validate_books_meta",
        os.path.join(os.path.dirname(bible_api.__file__), "tools", "validate_books_meta.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    data = json.load(open(os.path.join(os.path.dirname(bible_api.__file__), "data", "books_meta.json"), encoding="utf-8"))
    assert mod.validate(data) == []


def test_books_meta_file_is_versioned():
    import bible_api, json, os
    raw = json.load(open(os.path.join(os.path.dirname(bible_api.__file__), "data", "books_meta.json"), encoding="utf-8"))
    assert raw["schema_version"] == 1
    assert raw.get("generated_at")  # present so consumers can detect format


def test_books_meta_is_complete_canonical_and_stable():
    import bible_api
    meta = bible_api.books_meta()
    # Full canon, keyed by stable id; numbers 1-66 present exactly once.
    assert len(meta) == 66
    assert {b["number"] for b in meta.values()} == set(range(1, 67))
    assert all(bid == b["id"] for bid, b in meta.items())  # key == id
    # Stable canonical ids (these must never change).
    assert "genesis" in meta and "revelation" in meta
    assert meta["genesis"]["canonical_order"] == 1
    assert meta["revelation"]["number"] == 66


def test_books_meta_testament_and_category_ids():
    import bible_api
    meta = bible_api.books_meta()
    by_num = {b["number"]: b for b in meta.values()}
    # Testament uses canonical ids, derived from book number (1-39 / 40-66).
    assert by_num[1]["testament"] == "old_testament"
    assert by_num[39]["testament"] == "old_testament"
    assert by_num[40]["testament"] == "new_testament"
    # Category uses canonical ids, not display strings.
    assert by_num[1]["category"] == "pentateuch"
    assert by_num[19]["category"] == "wisdom"
    assert by_num[40]["category"] == "gospels"
    assert by_num[66]["category"] == "apocalyptic"
    assert all("_" in c or c.islower() for c in (b["category"] for b in meta.values()))


def test_books_meta_reserved_and_typed_fields():
    import bible_api
    meta = bible_api.books_meta()
    for b in meta.values():
        # Localized display names are NOT stored in the language-neutral ontology
        # (they come from locale resources); these stay null here.
        assert b["localized_name"] is None and b["localized_short_name"] is None
        # keywords/themes are lists (empty until their batch populates them).
        assert isinstance(b["keywords"], list) and isinstance(b["themes"], list)
        # pronunciation reserved: null now, may become a string later.
        assert b["pronunciation"] is None or isinstance(b["pronunciation"], str)
    assert bible_api.book_meta("not-a-book") is None


def test_books_meta_does_not_change_existing_index_or_search():
    import bible_api
    # The ontology is additive: the existing English book index + reference
    # resolution are unchanged whether or not meta is consulted.
    assert bible_api._book_index("en")  # still builds
    vo = scripture.resolve_ref("Genesis 1:1", "en")
    assert vo.resolved and "beginning" in vo.text.lower()


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
