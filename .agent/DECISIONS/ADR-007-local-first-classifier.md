# ADR-007

## Date
2026-09-02

## Context
The project owner requires that the platform be able to **run fully locally at
later stages — including the classification model** (no cloud dependency). At
the same time, no model exists yet today, and the task explicitly forbids
training/integrating a real CV model in this run.

## Decision
Make the local model a first-class driver, not an afterthought:
- `LocalModelClassifier` performs an HTTP call to a configurable local
  inference endpoint (`LOCAL_CLASSIFIER_URL`, default
  `http://127.0.0.1:8501/v1/models/material:predict` — TF-Serving-style path)
  using a stable multipart contract: `image=<file>` →
  `{"material_class": ..., "confidence": ...}`.
- The contract is documented bilingually (docs/LOCAL_MODEL.md / .es.md) and a
  runnable FastAPI reference server ships in `scripts/local-model-server/`
  (hash-based placeholder logic with a marked swap-in point for a real
  ONNX/TFLite model).
- Driver order: `stub` (default today) → `local` (the intended production
  driver) → `gemini` (optional cloud fallback).

## Alternatives Considered
- In-process PHP inference (e.g. a pure-PHP ONNX port) — rejected: no mature
  maintained PHP ONNX runtime; a sidecar service is the standard pattern and
  keeps the Laravel process lean.
- Only a stub with "swap later" documentation — rejected: the owner's
  local-first requirement deserved a concrete, runnable path now.

## Reasoning
HTTP keeps the Laravel app model-agnostic: TensorFlow Serving, TorchServe,
ONNX Runtime serving, or any custom server can stand behind the same URL. The
reference server proves the contract end-to-end today. When a real model is
trained (separate future task), deployment is: run the model server, flip
`RECYCLING_CLASSIFIER_DRIVER=local` — zero application code changes.

## Consequences
- One extra process to run in local-model mode (documented).
- Classifier failures are 503s with zero points touched (retry-safe).
- The e2e script and tests cover the driver-failure path deterministically.

## Status
ACTIVE

## Supersedes
none
