import unittest

from pydantic import ValidationError

from main import PlantAnalysis


class PlantAnalysisSchemaTests(unittest.TestCase):
    def test_requires_unavailable_findings_when_no_plant_is_recognized(self):
        result = PlantAnalysis.model_validate({
            "success": True,
            "plant_name": None,
            "health_status": "Uncertain",
            "plant_health": "Unable to Determine",
            "leaf_condition": "Unable to Determine",
            "growth_stage": "Unable to Determine",
            "disease_risk": "Requires further inspection",
            "discoloration": "Unable to Determine",
            "detected_issue": None,
            "visible_symptoms": [],
            "possible_causes": [],
            "severity": "Unknown",
            "confidence": "Unknown",
            "analysis": "No recognizable plant is visible.",
            "recommendations": [],
            "prevention_tips": [],
            "needs_verification": True,
        })

        self.assertEqual(result.plant_health, "Unable to Determine")

    def test_rejects_unhealthy_claim_without_a_recognized_plant(self):
        with self.assertRaises(ValidationError):
            PlantAnalysis.model_validate({
                "success": True,
                "plant_name": None,
                "health_status": "Uncertain",
                "plant_health": "Unhealthy",
                "leaf_condition": "Unable to Determine",
                "growth_stage": "Unable to Determine",
                "disease_risk": "Requires further inspection",
                "discoloration": "Unable to Determine",
                "detected_issue": None,
                "visible_symptoms": [],
                "possible_causes": [],
                "severity": "Unknown",
                "confidence": "Unknown",
                "analysis": "No recognizable plant is visible.",
                "recommendations": [],
                "prevention_tips": [],
                "needs_verification": True,
            })

    def test_rejects_conflicting_health_categories(self):
        with self.assertRaises(ValidationError):
            PlantAnalysis.model_validate({
                "success": True,
                "plant_name": "Pechay",
                "health_status": "Diseased",
                "plant_health": "Healthy",
                "leaf_condition": "Healthy-looking leaves",
                "growth_stage": "Vegetative Growth",
                "disease_risk": "No obvious visible symptoms",
                "discoloration": "No obvious discoloration",
                "detected_issue": "Possible leaf issue",
                "visible_symptoms": [],
                "possible_causes": [],
                "severity": "Low",
                "confidence": "Medium",
                "analysis": "Leaves appear healthy.",
                "recommendations": [],
                "prevention_tips": [],
                "needs_verification": False,
            })

    def test_rejects_healthy_status_when_the_assessment_reports_stress(self):
        with self.assertRaises(ValidationError):
            PlantAnalysis.model_validate({
                "success": True,
                "plant_name": "Pechay",
                "health_status": "Healthy",
                "plant_health": "Showing Possible Stress",
                "leaf_condition": "Yellowing",
                "growth_stage": "Vegetative Growth",
                "disease_risk": "Requires further inspection",
                "discoloration": "Yellowing",
                "detected_issue": None,
                "visible_symptoms": ["Yellow leaves"],
                "possible_causes": [],
                "severity": "None",
                "confidence": "Medium",
                "analysis": "The leaves show possible stress.",
                "recommendations": [],
                "prevention_tips": [],
                "needs_verification": False,
            })

    def test_rejects_symptoms_when_no_plant_is_recognized(self):
        with self.assertRaises(ValidationError):
            PlantAnalysis.model_validate({
                "success": True,
                "plant_name": None,
                "health_status": "Uncertain",
                "plant_health": "Unable to Determine",
                "leaf_condition": "Unable to Determine",
                "growth_stage": "Unable to Determine",
                "disease_risk": "Requires further inspection",
                "discoloration": "Unable to Determine",
                "detected_issue": None,
                "visible_symptoms": ["Yellow leaves"],
                "possible_causes": [],
                "severity": "Unknown",
                "confidence": "Unknown",
                "analysis": "No recognizable plant is visible.",
                "recommendations": [],
                "prevention_tips": [],
                "needs_verification": True,
            })


if __name__ == "__main__":
    unittest.main()
