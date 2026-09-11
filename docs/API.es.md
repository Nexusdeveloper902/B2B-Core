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
| `POST /api/v1/admin/readers/{id}/mode`, `PUT /api/v1/admin/readers/{id}`, `POST /api/v1/admin/students`, `POST /api/v1/admin/students/import`, `POST /api/v1/admin/students/{student}/account`, `POST /api/v1/admin/classes`, `POST /api/v1/admin/students/{id}/arm-pairing`, `GET /api/v1/admin/pairing/status`, `DELETE /api/v1/admin/cards/{id}`, `POST /api/v1/students/{id}/redeem`, `GET /api/v1/admin/captures/{deposit}/image` | Sesión (usuario del panel) o token de acceso personal | Del lado del panel. Rol admin aplicado por endpoint. |
| `POST /api/v1/nl-query` | Sesión (usuario del panel) o token de acceso personal | Del lado del panel. **Admin Y docente** (TASK-027): las preguntas de un docente quedan cercadas en el servidor a sus propias clases (`StudentScope`); los estudiantes siguen en 403. |

**Localización:** los mensajes para dispositivos son bilingües. Envía
`Accept-Language: es` para español (p. ej. `{"message": "Tarjeta no reconocida"}`);
el inglés es el valor por defecto y el respaldo para cualquier otro idioma.

**Zona horaria (TASK-015 / ADR-025):** la plataforma corre en **hora
local de Colombia — `America/Bogota` (COT, UTC−5 fijo, sin horario de
verano)**. Los `occurred_at` son hora local de Bogotá; las cadenas
ISO 8601 que emite la API llevan el desfase explícito `-05:00`. Los
`client_timestamp` pueden usar cualquier desfase ISO 8601 y se respetan
tal cual.

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

`422 Unprocessable Entity` — **compuerta de inscripción PAE** (TASK-027):
una tarjeta válida y activa cuyo estudiante **no está inscrito en el PAE**
tocó un lector en modo `PAE_BREAKFAST`/`PAE_LUNCH`. El consumo NO se
registra (mantiene honesto a `paeCount()`: la asistencia de comidas solo
proviene de toques de estudiantes inscritos) y el intento queda escrito en
`storage/logs/laravel.log` como pista de auditoría del programa:

```json
{"status":"error","reason":"student_not_pae","event_type":"PAE_BREAKFAST","message":"Ana no está inscrita en el programa de alimentación"}
```

**Pares entrada/salida (TASK-027):** un lector en modo `ENTRY` registra
cada toque como evento `ENTRY` — y el mismo lector en modo `EXIT` registra
`EXIT`. Varias filas por estudiante y día son el punto (cada entrada queda
registrada); `AttendanceService::studentSessions()` las empareja al vuelo
para derivar el tiempo en la escuela (una entrada sin salida cuenta como
sesión abierta). Sin cambio de esquema: ambos valores cabalgan la misma
columna `events.type`.

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
`RECYCLING_DEPOSIT`, `ENTRY`, `EXIT` (cualquier otro → 422).

**Respuesta `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Demo Reader — Classroom/PAE", "type": "classroom", "active_event_type": "PAE_LUNCH" }
}
```

¿También renombrar el lector? Usa el endpoint combinado de ajustes de
abajo (`PUT /api/v1/admin/readers/{id}`) — una sola petición actualiza el
nombre Y el modo activo.

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

## POST /api/v1/nl-query — consulta en lenguaje natural (Fase E, admin + docente)

**Petición**: `{"question": "¿Cuántos niños llegaron tarde esta semana?"}`

**Roles (TASK-027):** admin (toda la escuela) Y docente. Las preguntas de
un docente quedan cercadas **en el servidor** a las clases que dicta —
cada ejecución de función aplica el `StudentScope` del autor; una clase o
estudiante fuera del muro responde con un error explícito de alcance,
nunca con datos. Los estudiantes siguen en 403.

Flujo: la pregunta + un conjunto fijo de esquemas de funciones va al modelo
de DeepSeek (por defecto `deepseek-v4-flash`) → el modelo
**selecciona una función** → el backend ejecuta la
**consulta Eloquent real** → el resultado vuelve al modelo → el modelo redacta
la respuesta final. El LLM nunca calcula ni fabrica cifras. Las respuestas
son **concisas por contrato** (máximo tres frases cortas o una lista
compacta) y usan **Markdown ligero** (`**negrita**`, viñetas `- `,
`` `comillas inversas` ``) — los paneles lo renderizan vía
`public/js/markdown.js` (escape primero, nunca HTML crudo).

Funciones disponibles: `get_attendance_count(date, class_id?)`,
`get_pae_count(meal, date, class_id?)`,
`get_recycling_totals(date_from, date_to)` (toda la escuela por diseño —
tablero público de competencia, spec §22),
`get_student_timeline(student_id)`, más la **mitad analítica
(TASK-027)**: `get_absence_count(date, class_id?)`,
`get_absent_students(date, class_id?)`, `get_late_count(date,
class_id?)`, `get_attendance_trend(days)`,
`get_repeatedly_absent_students(days, min_absences, class_id?)`,
`get_student_time_in_school(student_id, days)` y
`find_student(name)` (resuelve un nombre parcial a un `student_id`).

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
DeepSeek):

| `blocked_reason` | Significado | Corrección |
|---|---|---|
| `missing_llm_credential` | No hay `DEEPSEEK_API_KEY` en `.env` | Añade la clave y ejecuta `./run llm-check` |
| `llm_invalid_key` | DeepSeek rechazó la clave (401 Authentication Fails) | Crea una clave nueva en platform.deepseek.com y actualiza `.env` |
| `llm_insufficient_balance` | 402 — la clave SÍ es válida pero el saldo de la cuenta está vacío (pago por uso) | Recarga el saldo en platform.deepseek.com |
| `llm_model_not_found` | `DEEPSEEK_MODEL` desconocido para esta cuenta/API (404 Model Not Exist) | Usa el valor por defecto `deepseek-v4-flash` |
| `llm_rate_limited` | Límite de peticiones alcanzado (429) | Reintenta más tarde |
| `llm_unavailable` | Error de transporte/servidor | Reintenta; el detalle está en `storage/logs/laravel.log` |

```json
{
  "status": "blocked",
  "blocked_reason": "missing_llm_credential",
  "message": "La consulta en lenguaje natural no está configurada: falta DEEPSEEK_API_KEY (bloqueada, no fallida)."
}
```

Ejecuta `./run llm-check` en la máquina que hace las llamadas — realiza una
petición directa en vivo con la misma clave + modelo e imprime el veredicto
exacto de DeepSeek con orientación bilingüe.

---

## PUT /api/v1/admin/readers/{id} — ajustes del lector: nombre + modo (TASK-027, solo admin)

El endpoint que respalda el escritorio de gestión `/admin/readers` (la
página perdida en el rediseño del frontend, ahora restaurada): renombrar
un lector Y cambiar su modo activo en UNA petición. **Requiere rol admin**
(teacher → 403, invitado → 401). El endpoint de solo-modo de arriba queda
intacto — su contrato está fijado por pruebas.

**Petición**:

```json
{ "label": "Aula 12 — Entrada", "active_event_type": "ENTRY" }
```

`label`: obligatorio, 3–255 caracteres. `active_event_type`: obligatorio,
mismos valores válidos que el endpoint de modo.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Aula 12 — Entrada", "type": "classroom", "active_event_type": "ENTRY" }
}
```

`422` — errores de validación (nombre corto, modo desconocido).

---

## POST /api/v1/admin/students — crear un estudiante (TASK-027, solo admin)

El fin de los INSERT escritos a mano: el escritorio `/admin/students`
crea estudiantes por este endpoint. **Requiere rol admin.**

TASK-030-A (ADR-044) — la inscripción crea el acceso: la cuenta 1:1 del
estudiante se aprovisiona en la MISMA transacción (email por convención
`{nombre}@presence.test` + contraseña inicial compartida + rotación
forzada en el primer inicio de sesión). La respuesta lleva las
credenciales EXACTAMENTE UNA VEZ (`account` + `account_notice` — la
regla de mostrar-una-sola-vez de las API keys de lector); los frames de
roster solo llevan el email, nunca la contraseña.

**Petición**:

```json
{ "name": "Nueva Estudiante", "grade": "5°", "class_id": 1, "pae_enrolled": true }
```

**Respuesta `200`**:

```json
{
  "status": "ok",
  "student": { "id": 9, "name": "Nueva Estudiante", "grade": "5°", "class_name": "5° B", "pae_enrolled": true, "account_email": "nueva@presence.test" },
  "account": { "email": "nueva@presence.test", "temporary_password": "password", "must_change_password": true },
  "message": "Estudiante Nueva Estudiante creado.",
  "account_notice": "Acceso listo: nueva@presence.test / contraseña inicial password — debe cambiarse en el primer inicio de sesión"
}
```

`422` — errores de validación, o `{"status":"error","reason":"duplicate"}`
para el mismo nombre en la misma clase (un duplicado nunca es un salto
silencioso; las filas rechazadas no crean ninguna cuenta).

---

## POST /api/v1/admin/students/import — importación masiva de roster CSV (TASK-027, solo admin)

**Requiere rol admin.** Petición multipart: `file` (CSV, máx 2 MB, hasta
500 filas). Fila de encabezado OBLIGATORIA — columnas sin distinción de
mayúsculas, orden libre, columnas extra ignoradas:

```csv
name,grade,class,pae_enrolled
María Pérez,5°,5° B,yes
```

`class` se resuelve por NOMBRE de clase (el flujo humano); `pae_enrolled`
acepta `yes`/`no`/`true`/`false`/`1`/`0`/`si`/`sí`. Las fallas por fila se
reportan por fila (número de fila + mensaje bilingüe) — una fila mala
nunca bloquea a las buenas; los duplicados son errores de fila.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "created": 2,
  "failed": 0,
  "errors": [],
  "students": [{ "id": 9, "name": "María Pérez", "class_name": "5° B", "account_email": "maria@presence.test" }],
  "accounts_created": 2,
  "message": "Importación terminada: 2 creados, 0 fallidos."
}
```

`status` es `ok` (todas las filas), `partial` (algunas sí, algunas no) o
`error` + 422 (nada creado — encabezado malo, sin filas de datos, archivo
ilegible). TASK-030-A: cada fila creada sale con un acceso
(`account_email` por fila, `accounts_created` en total); las filas
fallidas no crean nada. Las colisiones del mismo nombre se desambiguan
(`maria@…`, `maria2@…`).

---

## POST /api/v1/admin/students/{student}/account — crear un acceso puntual (TASK-030-A, solo admin)

**Requiere rol admin.** Acceso en un clic para filas anteriores a
TASK-030 (o cualquier estudiante sin cuenta): idempotente — un
estudiante que ya tiene cuenta la conserva (`already: true`, y la
contraseña temporal NO se reemite). Una cuenta recién creada lleva el
par de mostrar-una-sola-vez.

**Respuesta `200` (nueva)**:

```json
{
  "status": "ok",
  "already": false,
  "account": { "email": "legado@presence.test", "temporary_password": "password", "must_change_password": true },
  "message": "Acceso listo: legado@presence.test / contraseña inicial password — debe cambiarse en el primer inicio de sesión"
}
```

**Respuesta `200` (existente)**: `{"status":"ok","already":true,
"account":{"email":"…"},"message":"Este estudiante ya tiene un acceso
(…)"}`.

El primer inicio de sesión con la contraseña inicial cae en el panel
del estudiante y rebota de inmediato a `GET /password/change`: la
cuenta debe rotar a una contraseña personal (verificación de la actual,
mínimo 8, confirmada) antes de que se abra cualquier otra página. Los
clientes JSON reciben `403` `password_change_required` en vez de la
redirección.

---

## POST /api/v1/admin/classes — crear una clase (TASK-029, solo admin)

El primer paso que faltaba en el flujo de roster: el SELECT de clases
del escritorio `/admin/students` era de solo lectura antes — crear una
clase exigía SQL escrito a mano. **Requiere rol admin.** La asignación
de profesor es OPCIONAL (una clase puede existir antes de elegir a su
profesor titular; solo usuarios con `role: teacher` pueden asignarse).

**Petición**:

```json
{ "name": "6° A", "teacher_user_id": 2 }
```

**Respuesta `200`**:

```json
{
  "status": "ok",
  "class": { "id": 5, "name": "6° A", "teacher_name": "Prof. Elena Ramírez" },
  "message": "Clase 6° A creada."
}
```

`422` — errores de validación, o `{"status":"error","reason":"duplicate"}`
si el nombre ya existe (sin distinguir mayúsculas, igual que la regla de
estudiantes). Cada creación confirmada escribe un marco `class_created`
del canal roster en la misma transacción (ver Marcos en vivo del canal
roster abajo) — el SELECT de clases del escritorio se actualiza en vivo
en cuanto la clase existe.

---

## DELETE /api/v1/admin/cards/{id} — desvincular una tarjeta (TASK-027, solo admin)

La mitad GUI del vacío D1: el roster del escritorio de emparejamiento
lleva un botón **Desvincular** por credencial; este endpoint lo respalda.
**Requiere rol admin.** Las semánticas reflejan el comando masivo
`cards:unpair` (ADR-023) con granularidad de una sola tarjeta — «fresca»
significa que la fila no existe, así que desvincular BORRA la fila de la
tarjeta (nunca pone `student_id` en null — una fila nuleada seguiría
bloqueando el re-emparejamiento). Los eventos de tap se eliminan en
cascada con la tarjeta; las filas de historial `pending_pairings` sobreviven
con su vínculo limpiado (pista de auditoría). Una transacción, resultado
determinista.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "unpaired": { "credential_uid": "M9TN530AIT7N", "student_name": "Maria González", "events_deleted": 3, "history_links_cleared": 1 },
  "message": "Tarjeta desvinculada de Maria González — la credencial vuelve a estar fresca"
}
```

Tras una desvinculación exitosa la MISMA credencial puede volver a
emparejarse de inmediato (el bucle de banco: emparejar → desvincular →
re-emparejar).

---

## GET /api/v1/admin/captures/{deposit}/image — transmitir una captura almacenada (TASK-027, vacío E1, solo admin)

Las imágenes de captura viven en el disco **privado** `local`
(`storage/app/private`) como artefactos de auditoría y pueden contener
estudiantes — nunca van a un disco público. Esta ruta con autenticación
de admin es la única puerta autorizada (la página EcoStation la solicita
mismo-origen con la cookie de sesión; una petición de docente/estudiante
recibe 403 en el muro de roles antes de leer un solo byte del archivo).

**Respuesta `200`** — los bytes de la imagen (transmitida, inline,
`Cache-Control: private, max-age=60`).

`404` — el depósito no tiene imagen almacenada, o el archivo falta en
disco.

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


---

## POST /api/v1/recycling/capture — captura botella-primero (TASK-025)

**Aut: clave Bearer de un lector de reciclaje (la estación de cámara).**
La petición es `multipart/form-data`:

| Campo | Tipo | Notas |
|---|---|---|
| `image` | archivo | La imagen capturada. Se guarda como rastro de auditoría (disco `local`, `recycling-captures/`). |

Spec §3 Caso B: una botella colocada ANTES de cualquier tarjeta. La imagen
queda en estado `awaiting_card` durante `RECYCLING_CAPTURE_TTL` segundos
(por defecto 300). **Aquí NO se llama al clasificador** — la puerta de
costo (spec §4) prohíbe toda llamada al API de visión antes de asociar un
estudiante.

`200 OK`:

```json
{
  "status": "ok",
  "capture_id": 12,
  "state": "awaiting_card",
  "expires_in": 300,
  "next_step": "present_card"
}
```

`422` — el lector no es de reciclaje / validación.

---

## POST /api/v1/recycling/captures/{capture}/associate — la tarjeta resuelve la captura (TASK-025)

**Aut: clave Bearer del MISMO lector de reciclaje que guardó la captura.**
La petición es JSON:

```json
{"credential_uid": "A1B2C3D4E5F6"}
```

Una sola llamada hace toda la resolución: valida la tarjeta, crea el
evento de tap, clasifica la imagen guardada, otorga puntos y marca la
captura `accepted`.

`200 OK`:

```json
{
  "status": "ok",
  "capture_id": 12,
  "capture_state": "accepted",
  "event_id": 88,
  "already_classified": false,
  "material_class": "plastic",
  "confidence": 0.91,
  "is_bottle": true,
  "is_recyclable": true,
  "points_awarded": 10,
  "new_balance": 45
}
```

- Una tarjeta desconocida/inactiva devuelve `404` (mensaje mostrable en el
  dispositivo) y **mantiene la ventana abierta** — toca la tarjeta correcta
  y reintenta.
- `403` — la captura pertenece a otro lector. `404` — no hay captura usable
  (expirada / ya resuelta). `503` — clasificador no disponible (la captura
  sigue resoluble; reintenta).

---

## GET /api/v1/recycling/leaderboard — ranking (TASK-025)

**Aut: sesión o PAT; rol admin, teacher o student.**
Query: `?limit=N` (por defecto 10, máximo 100).

`200 OK`:

```json
{
  "status": "ok",
  "entries": [
    {"rank": 1, "student_id": 3, "student_name": "Carlos Pérez", "class_name": "5° B", "points": 25}
  ],
  "me": {"rank": 2, "points": 10, "student_id": 1, "student_name": "Maria González"}
}
```

`me` aparece solo para cuentas de estudiante (resuelto desde la cuenta,
nunca desde un parámetro). El ranking deriva exclusivamente del libro de
puntos; los empates comparten puesto (ranking de competición: 1, 2, 2, 4).

---

## Marcos en vivo de reciclaje (TASK-025)

El canal WebSocket (`realtime:serve`) ahora emite marcos `recycling` a
toda conexión autenticada (mismo token/handshake que los marcos de tap):

```json
{"type": "recycling", "update": {"id": 7, "type": "points_awarded",
 "payload": {"student_id": 1, "student_name": "Maria González", "points": 10, "new_balance": 45},
 "at": "2026-09-07 10:15:03"}}
```

Tipos de marco: `capture_created`, `validation_started`, `validated`,
`points_awarded`, `reward_redeemed`, `leaderboard_updated`. Las filas se
escriben dentro de la MISMA transacción de BD que el cambio de estado que
describen, así un marco solo refleja estado confirmado. El marco hello
lleva la instantánea reciente bajo `recycling`.

**Alcance por conexión (TASK-027):** el canal de toques ahora está cercado
por conexión, resuelto una sola vez en el handshake (fail closed): los
docentes solo ven toques de estudiantes de SUS clases, las cuentas de
estudiante solo ven SUS propios toques, los admins ven toda la escuela. El
marco hello respeta el mismo alcance, y la página EcoStation consume los
marcos `recycling` en vivo (CustomEvents `realtime:recycling` — filas del
libro, métricas de impacto y el panel de última captura se actualizan sin
recargar).

Escritorio web de autoservicio del estudiante (TASK-025): los estudiantes
inician sesión (misma página de login; cuentas demo impresas por el
seeder, p. ej. `carlos@presence.test` / `password`) y aterrizan en
`/student`, `/student/history`, `/student/rewards` — alcance restringido
del lado del servidor a sus propios datos únicamente.

## Marcos en vivo del canal roster (TASK-029)

Los cambios de roster se difunden en un cuarto canal, `roster` — solo
conexiones admin (los payloads reflejan la exposición de los endpoints
REST admin; la misma disciplina del canal de emparejamiento):

```json
{"type": "roster", "update": {"id": 3, "type": "student_created",
 "payload": {"id": 9, "name": "Nueva Estudiante", "grade": "5°", "class_id": 1, "class_name": "5° B", "pae_enrolled": false},
 "at": "2026-09-09 08:00:00"}}
```

Tipos de marco: `student_created` (una fila), `students_imported`
(`payload.students[]` — un marco por importación), `class_created` y
`reader_updated` (lo escriben AMBOS endpoints de escritura de lectores
— el PUT de ajustes y el POST de solo modo). Las filas van en la misma
transacción del cambio que describen; el hello del admin lleva el
snapshot reciente bajo `roster` para que una página recién conectada se
reconcilie. El escritorio de estudiantes antepone filas en vivo y añade
opciones de clase; el escritorio de lectores y la tabla de lectores del
panel admin repintan los cambios de lector.
