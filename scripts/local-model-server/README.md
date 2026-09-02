# Local Model Server (reference)

A minimal FastAPI reference implementation of the local classifier contract.

See [docs/LOCAL_MODEL.md](../../docs/LOCAL_MODEL.md) / [docs/LOCAL_MODEL.es.md](../../docs/LOCAL_MODEL.es.md) for the contract and usage.

```bash
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn server:app --port 8501
```

The classification logic is a hash-based placeholder — replace it with your
real ONNX/TFLite model inference where marked in `server.py`.
