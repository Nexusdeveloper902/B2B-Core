# Plataforma de Presencia — Referencia de API (Español)

> También disponible en: [English](API.md) · Colección: [Postman](postman_collection.json)

El backend central expone una API HTTP pequeña, estable y versionada. **Cada
endpoint orientado a hardware es un endpoint HTTP plano JSON/multipart** —
cualquier cosa que pueda hacer un POST HTTP autenticado (Postman, curl, un
script de pruebas, un ESP32 futuro) funciona hoy, y el hardware real más
adelante requiere **cero cambios en el backend**.

URL base (desarrollo local): `http://localhost:8000`

## Modelos de autenticación

| Endpoints | Auth | Notas |
|---|---|---|
| `POST /api/v1/events/tap`, `POST /api/v1/recycling/classify` | `Authorization: Bearer <reader.api_key>` | Del lado del dispositivo. La clave ES la identidad del lector — nunca se confía en un reader ID enviado por el cliente. Las claves las imprime el seeder. |
| `POST /api/v1/admin/readers/{id}/mode`, `POST /api/v1/students/{id}/redeem`, `POST /api/v1/nl-query` | Sesión (usuario del panel) o token de acceso personal | Del lado del panel. Roles admin/teacher aplicados por endpoint. |

**Localización:** los mensajes para dispositivos son bilingües. Envía
`Accept-Language: es` para español (p. ej. `{"message": "Tarjeta no reconocida"}`);
el inglés es el valor por defecto y el respaldo para cualquier otro idioma.

---

## POST /api/v1/events/tap — el bucle central de presencia (Fase B)

Registra un tap de tarjeta. El lector se resuelve por la clave Bearer; el tipo
de evento proviene del `active_event_type` actual del lector.

**Petición** (JSON):

```json
{
  "credential_uid": "M9TN530AIT7N",
  "client_timestamp": "2026-09-02T07:58:00-05:00"
}
```

- `credential_uid` (obligatorio, string) — el UID de la tarjeta.
- `client_timestamp` (opcional, ISO 8601) — relojes de dispositivos futuros;
  ausente → hora del servidor. Un valor malformado degrada con elegancia a la
  hora del servidor (un reloj roto nunca pierde el tap).

**Respuestas**

`200 OK` — retroalimentación para el dispositivo (LED/zumbador/pantalla):

```json
{
  "status": "ok",
  "event_id": 1042,
  "event_type": "CLASS_ATTENDANCE",
  "student_first_name": "Maria",
  "next_step": null
}
```

Para un lector de **reciclaje**, `next_step` es `"awaiting_classification"` y
el `event_id` debe usarse en la llamada posterior de clasificación. **No se
otorgan puntos en el momento del tap.**

`401 Unauthorized` — clave Bearer faltante/inválida:
`{"status":"error","message":"Token de portador (bearer) no válido"}`

`404 Not Found` — tarjeta desconocida (`Tarjeta no reconocida`) o no activa
(`La tarjeta no está activa`).

---

## POST /api/v1/admin/readers/{id}/mode — reetiquetar lector (Fase B)

Reetiqueta un lector físico (p. ej. el lector de aula pasa a ser lector de
almuerzo PAE). **Requiere rol admin** (teacher → 403, invitado → 401). También
se acepta `PUT`.

**Petición**:

```json
{ "active_event_type": "PAE_LUNCH" }
```

Valores válidos: `CLASS_ATTENDANCE`, `PAE_BREAKFAST`, `PAE_LUNCH`,
`RECYCLING_DEPOSIT`, `ENTRY` (cualquier otro → 422).

**Respuesta `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Demo Reader — Classroom/PAE", "type": "classroom", "active_event_type": "PAE_LUNCH" }
}
```

---

## POST /api/v1/recycling/classify — clasificación + ganar puntos (Fase C)

**Auth: clave Bearer del lector de reciclaje que posee el evento del tap.**
La petición es `multipart/form-data`:

| Campo | Tipo | Notas |
|---|---|---|
| `event_id` | int | De la respuesta del tap. Debe pertenecer a este lector y ser un evento `RECYCLING_DEPOSIT`. |
| `image` | archivo | Cualquier imagen sirve para el contrato MVP (la clasificación corre detrás de la interfaz intercambiable `MaterialClassifier`). |

**Respuestas**

`200 OK`:

```json
{
  "status": "ok",
  "already_classified": false,
  "material_class": "plastic",
  "confidence": 0.87,
  "points_awarded": 10,
  "new_balance": 45
}
```

- **Idempotente:** reenviar el mismo `event_id` devuelve `200` con
  `already_classified: true` y los valores originales del depósito — **nunca
  se otorgan puntos dos veces por un tap** (seguro para reintentos).
- Tabla de puntos (config/recycling.php): plástico=10, papel=5, metal=15,
  vidrio=8, otro=0.
- `403` — el evento pertenece a otro lector. `422` — el evento no es de
  reciclaje / validación. `503` — driver clasificador no disponible (p. ej.
  servicio local de inferencia caído; no se otorgó nada, reintenta luego).

---

## POST /api/v1/students/{id}/redeem — gastar puntos (Fase D)

Canje en mostrador. **Requiere rol admin o teacher.**

**Petición**: `{"reward_id": 2}`

**Respuestas**

`200 OK`:

```json
{
  "status": "ok",
  "student_id": 2,
  "reward": { "id": 2, "name": "Raffle entry", "point_cost": 20 },
  "new_balance": 5,
  "ledger_id": 7
}
```

`422` — saldo insuficiente, con el faltante:

```json
{
  "status": "error",
  "message": "Puntos insuficientes: faltan 15",
  "current_balance": 5,
  "reward_cost": 20,
  "shortfall": 15
}
```

El `points_ledger` es de solo-agregación: cada ganancia (+) y gasto (−) queda
registrado; el saldo siempre es `SUM(delta)`, nunca un contador mutable.

---

## POST /api/v1/nl-query — consulta en lenguaje natural (Fase E, solo admin)

**Petición**: `{"question": "¿Cuántos niños llegaron tarde esta semana?"}`

Flujo: la pregunta + un conjunto fijo de esquemas de funciones va al modelo
flash-lite de Gemini (por defecto `gemini-3.1-flash-lite`) → el modelo
**selecciona una función** → el backend ejecuta la
**consulta Eloquent real** → el resultado vuelve al modelo → el modelo redacta
la respuesta final. El LLM nunca calcula ni fabrica cifras.

Funciones disponibles: `get_attendance_count(date, class_id?)`,
`get_pae_count(meal, date)`, `get_recycling_totals(date_from, date_to)`,
`get_student_timeline(student_id)`.

**Respuestas**

`200 OK`:

```json
{
  "status": "ok",
  "answer": "Tres estudiantes asistieron a clase hoy.",
  "functions_called": [{ "name": "get_attendance_count", "args": { "date": "2026-09-02" } }]
}
```

`503` — **bloqueo honesto** — cada clase de rechazo tiene su propio
`blocked_reason` accionable (según el contrato de errores documentado de
Google):

| `blocked_reason` | Significado | Corrección |
|---|---|---|
| `missing_llm_credential` | No hay `GEMINI_API_KEY` en `.env` | Añade la clave y ejecuta `./run llm-check` |
| `llm_invalid_key` | Google rechazó la clave (400 `API_KEY_INVALID` / 401 / 403) | Crea una clave nueva en AI Studio y actualiza `.env` |
| `llm_region_unsupported` | «User location is not supported» — la clave SÍ es válida; Google rechaza la región | Ejecuta `./run llm-check` desde esta máquina; consulta la página de regiones soportadas de Google |
| `llm_model_not_found` | `GEMINI_MODEL` desconocido para esta cuenta/API (404) | Usa el valor por defecto `gemini-3.1-flash-lite` |
| `llm_rate_limited` | Cuota agotada (429) | Reintenta más tarde |
| `llm_unavailable` | Error de transporte/servidor | Reintenta; el detalle está en `storage/logs/laravel.log` |

```json
{
  "status": "blocked",
  "blocked_reason": "missing_llm_credential",
  "message": "La consulta en lenguaje natural no está configurada: falta GEMINI_API_KEY (bloqueada, no fallida)."
}
```

Ejecuta `./run llm-check` en la máquina que hace las llamadas — realiza una
petición directa en vivo con la misma clave + modelo e imprime el veredicto
exacto de Google con orientación bilingüe.

---

## Convenciones de errores

Todos los errores devuelven JSON con `{"status": "error", "message": "..."}`
y un código HTTP preciso (401/403/404/422/503). Los errores de validación
(422) incluyen además el objeto `errors` de Laravel. Los mensajes se localizan
vía `Accept-Language` (en/es).

## Credenciales de demo

Ejecuta `php artisan migrate --seed`. El seeder **imprime** (bilingüe EN/ES):

- Usuarios del panel: `admin@presence.test` / `teacher@presence.test` — contraseña `password`
- Un `credential_uid` por estudiante
- Una clave Bearer `api_key` por lector

Estos valores se reimprimen en cada ejecución del seeder — cópialos
directamente a las variables de la colección de Postman.
