# Ejecutar el Modelo de Clasificación Localmente (Español)

> También disponible en: [English](LOCAL_MODEL.md)

La plataforma está diseñada para **ejecutarse completamente en local** en
etapas posteriores — incluido el clasificador de materiales. El backend de
Laravel nunca llama a un modelo directamente: depende de la interfaz
`App\Contracts\MaterialClassifier`, y la implementación activa se elige con
**una variable de .env**:

```dotenv
# stub   → pseudo-clasificador determinista (por defecto; para dev/CI/demos)
# local  → TU servicio local de inferencia   ← driver de producción previsto
# deepseek → respaldo opcional en la nube (deepseek-flash — DeepSeek-V4.1-Flash,
#          el modelo Flash con visión; requiere DEEPSEEK_API_KEY; ADR-046)
RECYCLING_CLASSIFIER_DRIVER=local

LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
LOCAL_CLASSIFIER_TIMEOUT=10
```

Cambiar de driver requiere **cero cambios de código** — ni controladores, ni
rutas, ni pruebas (ver `.agent/DECISIONS/ADR-003` y `ADR-007`).

## El contrato HTTP del modelo local

El driver `local` habla un contrato deliberadamente mínimo y agnóstico del
framework que cualquier servidor de inferencia puede implementar (TensorFlow
Serving, TorchServe, ONNX Runtime serving, o un sidecar FastAPI de 40 líneas):

```text
POST {LOCAL_CLASSIFIER_URL}
Content-Type: multipart/form-data

image=<archivo binario de imagen>

→ 200 OK  {"material_class": "plastic", "confidence": 0.87}
```

- `material_class` DEBE ser uno de: `plastic`, `paper`, `metal`, `glass`,
  `other` (cualquier otro → el backend devuelve 502/503 y no otorga nada).
- `confidence` DEBERÍA ser un flotante en `[0, 1]`.
- Cualquier no-200 o timeout → el endpoint de clasificación responde
  `503 {"status":"error", ...}` y **no se tocan los puntos** — el dispositivo
  puede reintentar más tarde.

## Servidor de referencia

`scripts/local-model-server/server.py` es una implementación de referencia
FastAPI funcional de este contrato (lógica de marcador basada en hash por
diseño — sustituye tu modelo ONNX/TFLite real donde se indica). Ejecútalo:

```bash
cd scripts/local-model-server
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn server:app --port 8501

# luego, en el .env de la app:
#   RECYCLING_CLASSIFIER_DRIVER=local
#   LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
```

Cuando se entrene un modelo real (p. ej. un MobileNet afinado con fotos de
materiales) y se exporte, despliégalo tras la misma URL y todo el bucle de
ganancia sigue funcionando sin cambios.

## El respaldo `deepseek` en la nube (ADR-046)

`RECYCLING_CLASSIFIER_DRIVER=deepseek` (+ `DEEPSEEK_API_KEY`) envía la
captura a `deepseek-flash` (DeepSeek-V4.1-Flash) vía Chat Completions
compatible con OpenAI — reverificado contra api-docs.deepseek.com el
2026-09-11:

- imagen como data URL base64 en una parte `image_url` de un mensaje
  **user** (imágenes en system/assistant dan 400), `detail: high`
  fijado (precisión primero para la brecha de verificación),
  `thinking.disabled` + temperatura 0 (el thinking viene activado por
  defecto e ignora la temperatura).
- `response_format: json_object` con la palabra "json" + un ejemplo de
  formato en el prompt; el modelo devuelve `{material_class,
  confidence, is_bottle, is_recyclable}` — el backend sigue siendo
  dueño de las reglas de puntos (solo tabla de config).
- pre-vuelo local: JPEG/PNG/GIF/WebP detectados del **contenido** del
  archivo, techo de 32 MiB por imagen — las violaciones responden
  driver-unavailable (503, reintentable), nunca 400s crípticos.
- `DEEPSEEK_VISION_MODEL` sobrescribe el ID del modelo (el retirado
  `deepseek-v4-flash-vision-exp` aún sirve si se fija).

## Por qué existe el stub

Hoy no hay datos de entrenamiento ni modelo, así que el driver `stub` por
defecto devuelve una clase plausible y determinista-por-imagen + confianza.
Esto está sancionado (ADR-003): permite construir y ejercitar ahora cada
función posterior (puntos, libro mayor, paneles, consulta NL, CI), igual que
el Principio de Abstracción de Hardware lo hace para los lectores.
