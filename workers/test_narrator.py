import os
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, os.path.abspath(os.path.dirname(__file__)))

import narrator


class TestNarratorEdgeVoice(unittest.TestCase):
    def test_milestone_one_languages_use_native_edge_defaults(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(narrator.edge_voice("fr", "female"), "fr-FR-DeniseNeural")
            self.assertEqual(narrator.edge_voice("fr", "male"), "fr-FR-HenriNeural")
            self.assertEqual(narrator.edge_voice("de", "female"), "de-DE-KatjaNeural")
            self.assertEqual(narrator.edge_voice("de", "male"), "de-DE-ConradNeural")
            self.assertEqual(narrator.edge_voice("es", "female"), "es-ES-ElviraNeural")
            self.assertEqual(narrator.edge_voice("es", "male"), "es-ES-AlvaroNeural")

    def test_milestone_two_languages_use_native_edge_defaults(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(narrator.edge_voice("ja", "female"), "ja-JP-NanamiNeural")
            self.assertEqual(narrator.edge_voice("ja", "male"), "ja-JP-KeitaNeural")
            self.assertEqual(narrator.edge_voice("zh-CN", "female"), "zh-CN-XiaoxiaoNeural")
            self.assertEqual(narrator.edge_voice("zh-CN", "male"), "zh-CN-YunxiNeural")
            self.assertEqual(narrator.edge_voice("ko", "female"), "ko-KR-SunHiNeural")
            self.assertEqual(narrator.edge_voice("ko", "male"), "ko-KR-InJoonNeural")

    def test_milestone_three_languages_use_native_edge_defaults(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(narrator.edge_voice("hi", "female"), "hi-IN-SwaraNeural")
            self.assertEqual(narrator.edge_voice("hi", "male"), "hi-IN-MadhurNeural")
            self.assertEqual(narrator.edge_voice("ta", "female"), "ta-IN-PallaviNeural")
            self.assertEqual(narrator.edge_voice("ta", "male"), "ta-IN-ValluvarNeural")
            self.assertEqual(narrator.edge_voice("th", "female"), "th-TH-PremwadeeNeural")
            self.assertEqual(narrator.edge_voice("th", "male"), "th-TH-NiwatNeural")

    def test_language_specific_override_does_not_change_other_languages(self):
        with patch.dict(os.environ, {"EDGE_TTS_VOICE_ES_MALE": "es-ES-TestNeural"}, clear=True):
            self.assertEqual(narrator.edge_voice("es", "male"), "es-ES-TestNeural")
            self.assertEqual(narrator.edge_voice("fr", "male"), "fr-FR-HenriNeural")

    def test_hyphenated_language_override_uses_underscore_env_key(self):
        with patch.dict(os.environ, {"EDGE_TTS_VOICE_ZH_CN_FEMALE": "zh-CN-TestNeural"}, clear=True):
            self.assertEqual(narrator.edge_voice("zh-CN", "female"), "zh-CN-TestNeural")
            self.assertEqual(narrator.edge_voice("ko", "female"), "ko-KR-SunHiNeural")

    def test_milestone_three_language_specific_override_is_scoped(self):
        with patch.dict(os.environ, {"EDGE_TTS_VOICE_HI_MALE": "hi-IN-TestNeural"}, clear=True):
            self.assertEqual(narrator.edge_voice("hi", "male"), "hi-IN-TestNeural")
            self.assertEqual(narrator.edge_voice("ta", "male"), "ta-IN-ValluvarNeural")

    def test_existing_english_and_myanmar_defaults_are_preserved(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(narrator.edge_voice("en", "female"), "en-US-AriaNeural")
            self.assertEqual(narrator.edge_voice("en", "male"), "en-US-GuyNeural")
            self.assertEqual(narrator.edge_voice("my", "female"), "my-MM-NilarNeural")
            self.assertEqual(narrator.edge_voice("my", "male"), "my-MM-ThihaNeural")


if __name__ == "__main__":
    unittest.main()
