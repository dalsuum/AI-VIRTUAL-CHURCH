import os
import sys
import unittest

# Add the workers directory to sys.path so imports work correctly
sys.path.insert(0, os.path.abspath(os.path.dirname(__file__)))

import llm_engine


class TestLLMEngine(unittest.TestCase):

    def test_strip_formatting(self):
        """Verify that markdown formatting and stage directions are stripped from generated prose."""
        
        # Test stage directions and cues (with and without emphasis)
        self.assertEqual(llm_engine._strip_formatting("Hello **[Pause 3 seconds]** world"), "Hello  world")
        self.assertEqual(llm_engine._strip_formatting("Welcome ***[Soft instrumental intro]***."), "Welcome .")
        self.assertEqual(llm_engine._strip_formatting("Let us pray. [Silence] Amen."), "Let us pray.  Amen.")

        # Test headings
        self.assertEqual(llm_engine._strip_formatting("### Welcome\nNext line"), "Welcome\nNext line")
        self.assertEqual(llm_engine._strip_formatting("# Title"), "Title")
        
        # Test horizontal rules
        self.assertEqual(llm_engine._strip_formatting("Hello\n---\nWorld"), "Hello\n\nWorld")
        self.assertEqual(llm_engine._strip_formatting("Hello\n***\nWorld"), "Hello\n\nWorld")

        # Test lists
        self.assertEqual(llm_engine._strip_formatting("- Point 1\n* Point 2\n+ Point 3\n1. Point 4"), "Point 1\nPoint 2\nPoint 3\nPoint 4")

        # Test bold and italics
        self.assertEqual(llm_engine._strip_formatting("This is **bold** and *italic* and __underline__"), "This is bold and italic and underline")

        # Test whitespace consolidation (trimming indents, max 1 blank line between paragraphs)
        self.assertEqual(llm_engine._strip_formatting("Line 1\n\n\n\nLine 2"), "Line 1\n\nLine 2")
        self.assertEqual(llm_engine._strip_formatting("  Indented\n   Trailing   "), "Indented\nTrailing")

        # Comprehensive combined test
        raw_text = """
        # **Welcome**
        ***[Soft instrumental intro]***
        
        Hello, **friends**. We are gathered today.
        """
        
        expected_text = "Welcome\n\nHello, friends. We are gathered today."
        
        self.assertEqual(llm_engine._strip_formatting(raw_text), expected_text)

    def test_milestone_four_rtl_language_fallbacks_and_guards(self):
        self.assertIn("Arabic", llm_engine._language_instruction("ar"))
        self.assertIn("Hebrew", llm_engine._language_instruction("he"))

        self.assertIn("سلام", llm_engine._fallback_welcome(None, "peace", "ar"))
        self.assertIn("שלום", llm_engine._fallback_welcome(None, "peace", "he"))

        self.assertTrue(llm_engine._lyrics_match_language(llm_engine._fallback_music_lyrics("peace", "ar"), "ar"))
        self.assertTrue(llm_engine._lyrics_match_language(llm_engine._fallback_music_lyrics("peace", "he"), "he"))
        self.assertFalse(llm_engine._lyrics_match_language("Peace and grace in Christ " * 8, "ar"))
        self.assertFalse(llm_engine._lyrics_match_language("Peace and grace in Christ " * 8, "he"))

if __name__ == "__main__":
    unittest.main()
