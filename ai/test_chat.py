import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from pydantic import ValidationError

from main import GEMINI_MODEL, ChatRequest, call_gemini_chat


class ChatRequestTests(unittest.TestCase):
    def test_rejects_blank_and_oversized_questions(self):
        with self.assertRaises(ValidationError):
            ChatRequest(message="   ")

        with self.assertRaises(ValidationError):
            ChatRequest(message="x" * 2001)

    def test_requires_complete_ordered_turns_in_history(self):
        with self.assertRaises(ValidationError):
            ChatRequest(
                message="How do I care for it?",
                history=[{"role": "assistant", "content": "What plant is it?"}],
            )

        request = ChatRequest(
            message="How do I care for it?",
            history=[
                {"role": "user", "content": "How do I grow pechay?"},
                {"role": "assistant", "content": "Keep it evenly moist."},
            ],
        )
        self.assertEqual(len(request.history), 2)

    def test_uses_configured_gemini_model_and_includes_context(self):
        client = Mock()
        client.models.generate_content.return_value = SimpleNamespace(text="Water gently each morning.")
        request = ChatRequest(
            message="How should I care for my pechay?",
            history=[
                {"role": "user", "content": "How do I grow pechay?"},
                {"role": "assistant", "content": "Sow the seeds in moist soil."},
            ],
            plant_context="Plant: Pechay. Latest saved scan: Healthy.",
        )

        with patch("main.get_gemini_client", return_value=client):
            response = call_gemini_chat(request)

        self.assertEqual(response.text, "Water gently each morning.")
        call = client.models.generate_content.call_args.kwargs
        self.assertEqual(call["model"], GEMINI_MODEL)
        self.assertEqual([item.role for item in call["contents"]], ["user", "model", "user"])
        self.assertIn("How do I grow pechay?", call["contents"][0].parts[0].text)
        self.assertIn("Relevant authenticated plant records", call["contents"][2].parts[0].text)
        self.assertIn("Plant: Pechay", call["contents"][2].parts[0].text)
