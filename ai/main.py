import logging
import os
from io import BytesIO
from threading import Lock

from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, UnidentifiedImageError
from transformers import pipeline

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("tanimtrack-ai")

MODEL_ID = os.getenv("MODEL_ID", "neghani/plantvillage-mobilenetv2")
MIN_CONFIDENCE = float(os.getenv("MODEL_MIN_CONFIDENCE", "0.55"))
MAX_IMAGE_BYTES = 5 * 1024 * 1024
model_lock = Lock()
classifier = None

app = FastAPI(title="TanimTrack Plant Classifier", version="1.0.0")


def get_classifier():
    global classifier
    if classifier is None:
        with model_lock:
            if classifier is None:
                logger.info("Loading image classification model %s", MODEL_ID)
                classifier = pipeline(
                    "image-classification",
                    model=MODEL_ID,
                    device=-1,
                )
                logger.info("Image classification model is ready")
    return classifier


def split_label(label: str):
    if "___" in label:
        crop, condition = label.split("___", 1)
        return crop.replace("_", " "), condition.replace("_", " ")
    return "Unknown crop", label.replace("_", " ")


@app.get("/health")
def health():
    return {"status": "ok", "model_loaded": classifier is not None, "model_id": MODEL_ID}


@app.post("/predict")
async def predict(image: UploadFile = File(...)):
    if image.content_type not in {"image/jpeg", "image/png", "image/webp"}:
        raise HTTPException(status_code=415, detail="Upload a JPG, PNG, or WebP plant image.")

    content = await image.read(MAX_IMAGE_BYTES + 1)
    if not content or len(content) > MAX_IMAGE_BYTES:
        raise HTTPException(status_code=413, detail="The image must be between 1 byte and 5 MB.")

    try:
        with Image.open(BytesIO(content)) as source:
            if source.width * source.height > 20_000_000:
                raise HTTPException(status_code=413, detail="The image dimensions are too large to process.")
            source.verify()
        with Image.open(BytesIO(content)) as source:
            plant_image = source.convert("RGB")
    except HTTPException:
        raise
    except (Image.DecompressionBombError, UnidentifiedImageError, OSError, ValueError) as error:
        raise HTTPException(status_code=415, detail="The uploaded file is not a supported image.") from error

    try:
        predictions = get_classifier()(plant_image, top_k=3)
    except Exception as error:
        logger.exception("Plant classification failed")
        raise HTTPException(status_code=503, detail="The plant classification model is unavailable.") from error

    if not predictions:
        raise HTTPException(status_code=503, detail="The model returned no classification results.")

    best = predictions[0]
    label = str(best["label"])
    confidence = float(best["score"])
    crop, condition = split_label(label)

    return {
        "success": True,
        "model": MODEL_ID,
        "disclaimer": "Experimental PlantVillage classifier. It only recognizes its trained classes and is not a diagnosis.",
        "prediction": {
            "label": label,
            "crop": crop,
            "condition": condition,
            "confidence": confidence,
            "accepted": confidence >= MIN_CONFIDENCE,
        },
        "alternatives": [
            {"label": str(item["label"]), "confidence": float(item["score"])}
            for item in predictions
        ],
    }
