import unittest
from unittest.mock import Mock, patch

from pydantic import ValidationError

from main import GEMINI_MODEL, FertilizerContext, FertilizerResult, call_gemini_fertilizer


class FertilizerRecommendationTests(unittest.TestCase):
    def test_application_requires_a_category_and_date(self):
        recommendation = FertilizerResult(
            success=True,
            decision="apply",
            fertilizer_category="compost",
            nutrient_stress="not_indicated",
            confidence="low",
            recommended_on="2026-12-01",
            guidance="Follow the product label and avoid applying to waterlogged soil.",
            reason="The saved crop stage and care history support a cautious application.",
        )
        self.assertEqual(recommendation.fertilizer_category, "compost")

        with self.assertRaises(ValidationError):
            FertilizerResult(
                success=True,
                decision="apply",
                fertilizer_category="none",
                nutrient_stress="possible",
                confidence="low",
                guidance="Check the product label.",
                reason="Evidence is limited.",
            )

    def test_non_application_cannot_select_a_fertilizer(self):
        with self.assertRaises(ValidationError):
            FertilizerResult(
                success=True,
                decision="review",
                fertilizer_category="nitrogen_rich",
                nutrient_stress="possible",
                confidence="low",
                guidance="Review soil conditions first.",
                reason="The stored observations are not enough to recommend an application.",
            )

    def test_gemini_receives_stored_plant_context_and_cautious_instructions(self):
        client = Mock()
        context = FertilizerContext(
            plant={"name": "Pechay", "species": "Pechay", "age_weeks": 3},
            scan_history=[{"health_status": "Healthy", "growth_stage": "Vegetative Growth"}],
            watering_history=[{"status": "completed", "scheduled_on": "2026-11-27"}],
            fertilizer_history=[],
            soil={"type": None, "manual_moisture_percent": None},
        )

        with patch("main.get_gemini_client", return_value=client):
            call_gemini_fertilizer(context)

        request = client.interactions.create.call_args.kwargs
        self.assertEqual(request["model"], GEMINI_MODEL)
        prompt = request["input"][0]["text"]
        self.assertIn("Pechay", prompt)
        self.assertIn("Leaf color alone never confirms a nutrient deficiency", prompt)
        self.assertIn("Never invent", prompt)


if __name__ == "__main__":
    unittest.main()
