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
| `POST /api/v1/events/tap`, `POST /api/v1/recycling/classify`, `POST /api/v1/admin/cards/pair` | `Authorization: Bearer <reader.api_key>` | Del lado del dispositivo. La clave ES la identidad del lector — nunca se confía en un reader ID enviado por el cliente. Las claves las imprime el seeder. |
| `POST /api/v1/admin/readers/{id}/mode`, `POST /api/v1/admin/students/{id}/arm-pairing`, `GET /api/v1/admin/pairing/status`, `POST /api/v1/students/{id}/redeem`, `POST /api/v1/nl-query` | Sesión (usuario del panel) o token de acceso personal | Del lado del panel. Roles admin/teacher aplicados por endpoint. |

**Localización:** los mensajes para dispositivos son bilingües. Envía
`Accept-Language: es` para español (p. ej. `{"message": "Tarjeta no reconocida"}`);
el inglés es el valor por defecto y el respaldo para cualquier otro idioma.

**Usar el panel desde otro dispositivo en tu LAN (TASK-012).** Las
páginas del panel y sus fetch a `/api/*` se autentican por sesión cuando
la petición es «stateful» (mismo origen). La lista stateful de Sanctum
por defecto es localhost/127.0.0.1/`APP_URL` **más el host que sirve
cada petición** — así que abrir el panel desde un teléfono en la misma
red (p. ej. `http://192.168.1.6:8000`) funciona sin ajustes: inicia
sesión en el teléfono y el botón «Armar emparejamiento» se autentica con
esa sesión. Sirve en todas las interfaces (`php artisan serve
--host=0.0.0.0` o `./run serve`) para que el teléfono alcance al host.
Antes de TASK-012 todo origen distinto de localhost respondía `401
Unauthenticated` en las rutas API aunque el login web hubiera funcionado.
Para fijar el acceso stateful a una lista explícita de hosts, define
`SANCTUM_STATEFUL_DOMAINS` en `.env` (reemplaza el valor por defecto por
completo — incluye el host del escritorio y el del teléfono) y reinicia
el servidor. Los endpoints de dispositivos no cambian: los lectores
nunca envían Referer/Origin, así que su flujo con clave Bearer sigue
siendo sin estado.

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

## POST /api/v1/admin/students/{id}/arm-pairing — armar un emparejamiento (TASK-010, solo admin)

Primer paso del flujo de emparejamiento en dos pasos (ADR-020): arma un
**emparejamiento pendiente** de corta duración para un estudiante. La
siguiente tarjeta **nueva** que se lea en cualquier lector dentro de la
ventana queda vinculada a ese estudiante (lado del dispositivo, abajo).

La ventana es de **45 segundos** por defecto (`PAIRING_WINDOW_SECONDS`,
ver `config/presence.php`) — suficiente para caminar al lector, lo
suficientemente corta para no dejar sesiones abiertas huérfanas. Si se
arman dos estudiantes a la vez, gana el emparejamiento armado **más
reciente** (el flujo de escritorio es secuencial por naturaleza). Armar de
nuevo simplemente crea un emparejamiento más nuevo.

**Petición**: cuerpo JSON vacío — el estudiante viene en la URL.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "student_id": 3,
  "expires_at": "2026-09-05T14:02:31+00:00"
}
```

`401`/`403` — invitado / no admin (un profesor no puede armar). `404` — estudiante desconocido.

> **Atajo del panel (TASK-011)**: el panel de administración tiene una
> página **Emparejar tarjetas** (`/admin/pairing`, sesión de admin) con
> botones **Armar emparejamiento** de un clic por estudiante — los
> botones llaman a ESTE endpoint con tu sesión iniciada, así que no
> necesitas PAT ni curl. La página consulta `GET
> /api/v1/admin/pairing/status` (abajo) y muestra la cuenta regresiva en
> vivo, el momento exacto en que la tarjeta queda emparejada y el
> historial reciente.

## GET /api/v1/admin/pairing/status — estado del escritorio de emparejamiento (TASK-011, solo admin, solo lectura)

Estado de solo lectura para el escritorio de emparejamiento del panel:
>qué sesión está armada ahora (si hay alguna), el último emparejamiento
completado y los 8 más recientes. La página la consulta cada ~2 s
mientras hay una sesión armada, de modo que el operador ve el vínculo
tarjeta→estudiante en el instante en que el lector consume la sesión —
sin mirar el monitor serial.

**Respuesta `200`** (nada armado, nada emparejado aún):

```json
{
  "status": "ok",
  "pending": null,
  "last_pairing": null,
  "recent_pairings": []
}
```

**Respuesta `200`** (sesión armada; una tarjeta emparejada antes):

```json
{
  "status": "ok",
  "pending": {
    "student_id": 3,
    "student_name": "Maria González",
    "expires_at": "2026-09-05T14:03:41+00:00",
    "seconds_left": 23,
    "last_rejection": {
      "card_uid": "62041607",
      "reason": "already_paired",
      "at": "2026-09-05T14:03:12+00:00"
    }
  },
  "last_pairing": {
    "card_uid": "62041607",
    "student_name": "Carlos Pérez",
    "paired_at": "2026-09-05T13:58:02+00:00",
    "reader_label": "Demo Reader — Classroom/PAE"
  },
  "recent_pairings": [
    {
      "card_uid": "62041607",
      "student_name": "Carlos Pérez",
      "paired_at": "2026-09-05T13:58:02+00:00",
      "reader_label": "Demo Reader — Classroom/PAE"
    }
  ]
}
```

`pending` es `null` cuando no hay nada armado (o la ventana ya caducó).
`pending.last_rejection` (TASK-014) es `null` hasta que un toque sobre
esta ventana sea RECHAZADO — un `422 already_paired` hacia el lector
también sella la sesión armada, de modo que el panel puede MOSTRAR el
UID rechazado, la razón y la remediación (toca una tarjeta distinta o
ejecuta `./run unpair`) en lugar de contar en silencio; la ventana sigue
armada, así que una tarjeta genuinamente fresca aún puede completarla.
Un toque sin sesión armada responde `409` al lector y no sella nada (no
hay ventana que reportar). Las entradas de `recent_pairings` provienen
de emparejamientos completados cuya columna de auditoría
`pending_pairings.card_id` (TASK-011) apunta a la fila exacta de
`cards` — las tarjetas demo sembradas (fabricadas por el seeder, nunca
emparejadas) jamás aparecen aquí. `401`/`403` — invitado / no admin.
Este endpoint nunca escribe: armar sigue siendo un POST y emparejar
sigue siendo del lado del lector.

## POST /api/v1/admin/cards/pair — emparejar una tarjeta leída (TASK-010, lado del dispositivo)

Segundo paso: el lector (cualquier lector — la ruta vive bajo `/admin/` por
descubribilidad, pero la autenticación es la **clave Bearer del lector**,
exactamente como el endpoint de tap) envía el UID de una tarjeta recién
leída. El emparejamiento pendiente más reciente no consumido y no caducado
se consume y la tarjeta queda vinculada a su estudiante.

**Petición** (JSON):

```json
{ "credential_uid": "A1B2C3D4E5" }
```

**Respuesta `200`**:

```json
{
  "status": "ok",
  "paired_student_name": "Maria González",
  "student_id": 3
}
```

`409 Conflict` — no hay sesión de emparejamiento activa (ninguna armada,
caducada o ya consumida):
`{"status":"error","message":"No pairing session active"}`.

`422` — el `credential_uid` ya está vinculado a una fila existente de
`cards` (cualquier estado — una tarjeta de reemplazo es una credencial
NUEVA; jamás se reasignan tarjetas existentes):
`{"status":"error","message":"Card already paired"}`. El emparejamiento
pendiente **sigue armado** para que el operador pueda leer de inmediato
otra tarjeta nueva.

`401` — clave Bearer del lector faltante o inválida. El emparejamiento es
de un solo uso: tras un emparejamiento exitoso, la siguiente lectura
recibe el 409. La tarjeta recién emparejada funciona de inmediato para los
toques en el endpoint de tap.

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
