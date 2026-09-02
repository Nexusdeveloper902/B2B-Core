"""
Reference local model-inference server for the Presence Platform.

Implements the exact HTTP contract the `local` classifier driver expects
(see docs/LOCAL_MODEL.md):

    POST {url}   multipart/form-data: image=<file>
    → 200 {"material_class": "plastic", "confidence": 0.87}

The classification logic here is a deterministic placeholder (hash-based),
mirroring the app-side stub — swap in your real ONNX / TFLite model where
marked. The contract is what matters: when your real model is deployed
behind this URL, the backend keeps working unchanged.

Servidor local de referencia para inferencia del modelo de clasificación.
Implementa el contrato HTTP exacto que espera el driver `local`
(ver docs/LOCAL_MODEL.es.md).
"""

from __future__ import annotations

import hashlib

from fastapi import FastAPI, File, HTTPException, UploadFile

MATERIALS = ["plastic", "paper", "metal", "glass", "other"]

app = FastAPI(title="Presence Platform — Local Material Classifier (reference)")


@app.post("/v1/models/material:predict")
async def predict(image: UploadFile = File(...)) -> dict:
    """Classify an uploaded image and return (material_class, confidence)."""
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="empty image")

    # ------------------------------------------------------------------
    # PLACEHOLDER CLASSIFICATION LOGIC — replace with real inference here:
    #   e.g. onnxruntime.InferenceSession("material_mobilenet.onnx") ...
    # LÓGICA DE CLASIFICACIÓN DE MARCADOR — sustituye por inferencia real.
    # ------------------------------------------------------------------
    digest = hashlib.sha256(data).hexdigest()
    material = MATERIALS[int(digest[:4], 16) % len(MATERIALS)]
    confidence = 0.55 + (int(digest[4:8], 16) % 45) / 100.0

    return {
        "material_class": material,
        "confidence": round(confidence, 2),
    }


@app.get("/healthz")
async def healthz() -> dict:
    return {"status": "ok"}


# Run with:  uvicorn server:app --port 8501
# Then set in the app's .env:
#   RECYCLING_CLASSIFIER_DRIVER=local
#   LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
