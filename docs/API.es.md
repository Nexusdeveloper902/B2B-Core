# Pulse — Referencia de API (Español)

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
| `POST /api/v1/events/tap`, `POST /api/v1/recycling/classify`, `POST /api/v1/admin/cards/pair` | `Authorization: Pulse-HMAC <kid>:<nonce>:<sig>` (dispositivos) o `Bearer <reader.api_key>` (banco) | Del lado del dispositivo (TASK-043 / ADR-062). Los lectores FIRMAN cada petición (HMAC-SHA256 sobre método, ruta, nonce y hash del cuerpo, con la clave del lector) — la clave nunca viaja por la red, los nonces son de un solo uso, y el tráfico capturado no puede repetirse ni redirigirse. Para subidas de imagen multipart (classify/capture) el cuerpo firmado es el canónico `event_id + image.sha256`, no los bytes crudos (PHP nunca ve el multipart crudo). El Bearer heredado queda para Postman/curl/scripts e2e y se puede desactivar con `DEVICE_AUTH_ALLOW_LEGACY_BEARER=false`. Las claves las imprime el seeder. |
| `POST /api/v1/admin/readers/{id}/mode`, `PUT /api/v1/admin/readers/{id}`, `POST /api/v1/admin/readers`, `POST /api/v1/admin/readers/{reader}/rotate-key`, `DELETE /api/v1/admin/readers/{reader}`, `POST /api/v1/admin/students`, `POST /api/v1/admin/students/import`, `POST /api/v1/admin/students/{student}/account`, `POST /api/v1/admin/classes`, `POST /api/v1/admin/staff`, `POST /api/v1/admin/students/{id}/arm-pairing`, `GET /api/v1/admin/pairing/status`, `DELETE /api/v1/admin/cards/{id}`, `POST /api/v1/students/{id}/redeem`, `GET /api/v1/admin/captures/{deposit}/image` | Sesión (usuario del panel) o token de acceso personal | Del lado del panel. Rol admin aplicado por endpoint. |
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
nunca envían Referer/Origin, así que su flujo firmado sigue
siendo sin estado.

---

## POST /api/v1/events/tap — el bucle central de presencia (Fase B)

Registra un tap de tarjeta. El lector se resuelve por la firma de la petición
(o la clave Bearer del banco); el tipo de evento proviene del
`active_event_type` actual del lector.

**El primer tap cuenta (TASK-043):** un segundo tap de aula del mismo
estudiante para el mismo tipo el mismo día devuelve el evento ORIGINAL con
`"duplicate": true` en vez de escribir una fila nueva — tarjetas retenidas,
reintentos manuales tras time-outs y peticiones repetidas no pueden inflar la
asistencia. Los taps de reciclaje están exentos (cada tap es un depósito físico).

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
  "duplicate": false,
  "next_step": null
}
```

Para un lector de **reciclaje**, `next_step` es `"awaiting_classification"` y
el `event_id` debe usarse en la llamada posterior de clasificación. **No se
otorgan puntos en el momento del tap.**

`401 Unauthorized` — firma faltante/inválida (o clave Bearer en la ruta del
banco): `{"status":"error","message":"Firma de dispositivo no válida"}`

`404 Not Found` — tarjeta desconocida (`Tarjeta no reconocida`) o no activa
(`La tarjeta no está activa`).

`422 Unprocessable Entity` — **motor de servicio de comidas** (TASK-037):
una tarjeta válida y activa tocó un lector de comidas (`type: "pae"`, o
cualquier lector reetiquetado a modo `PAE_BREAKFAST`/`PAE_LUNCH`) y una de
las reglas de elegibilidad falló. El intento NO se sirve, pero SÍ se
persiste como fila marcada (`served=false` + `reason`) — auditable en los
feeds, el escritorio de cocina, los reportes y
`storage/logs/laravel.log`, y nunca cuenta como comida:

```json
{"status":"error","reason":"not_enrolled","meal":"breakfast","event_id":42,"event_type":"PAE_BREAKFAST","message":"Ana no está inscrito para el Desayuno"}
```

Motivos estables y su significado:

| `reason` | Regla que falló | Mensaje |
|---|---|---|
| `weekend` | Servicio de lunes a viernes (America/Bogota) | Hoy no hay servicio |
| `out_of_window` | Ninguna ventana de servicio activa | Nombra ambas ventanas como pista |
| `window_overlap` | Ambas ventanas activas (desconfiguración) | Revisar el escritorio de configuración |
| `no_student` | Tarjeta sin estudiante vinculado | Tarjeta sin estudiante |
| `not_enrolled` | El estudiante no está inscrito para ESA comida | Nombra la comida |
| `no_attendance` | Sin `CLASS_ATTENDANCE` previa el mismo día | Debe registrar asistencia primero |
| `duplicate` | La comida ya se sirvió hoy | Ya recibió (:meal) |

**Reglas de servicio (TASK-037):** una comida cuenta solo cuando (1) la
fecha es día escolar (lun–vie), (2) exactamente UNA ventana de servicio
está activa (auto-detectada desde las ventanas configurables por el
administrador — la etiqueta de modo del lector NO es la autoridad), (3)
el estudiante está inscrito para esa comida específica
(`pae_breakfast_enrolled` / `pae_lunch_enrolled`, banderas
independientes), (4) existe un evento `CLASS_ATTENDANCE` estrictamente
anterior en el mismo día local escolar, y (5) el estudiante aún no
recibió esa comida hoy. Los toques aceptados devuelven `meal` y un
mensaje localizado:

```json
{"status":"ok","event_id":43,"event_type":"PAE_LUNCH","student_first_name":"Maria","meal":"lunch","message":"Maria recibió Almuerzo","next_step":null}
```

**ENTRY/EXIT fue eliminado** (TASK-037, reemplaza las sesiones de
TASK-027): los lectores de puerta, los eventos `ENTRY`/`EXIT` y las
derivaciones de tiempo en la escuela salieron de la plataforma — el
prerrequisito de asistencia del PAE descansa exclusivamente en
`CLASS_ATTENDANCE`.

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
`RECYCLING_DEPOSIT` (cualquier otro → 422). `PAE_ATTEMPT` NO es un modo
válido: es el tipo que escribe el motor para los intentos fuera de
ventana/fin de semana (`served=false`), nunca una etiqueta asignable.
TASK-037: un lector reetiquetado a un modo PAE enruta sus toques por el
motor de servicio — la comida se auto-detecta por el reloj, no por la
etiqueta.

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

**Aut: firma de petición del lector de reciclaje que posee el evento del tap (banco: clave Bearer). El cuerpo firmado es el canónico multipart `event_id + image.sha256` (ver modelos de autenticación).**
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
descubribilidad, pero la autenticación es la **firma de petición del lector**,
exactamente como el endpoint de tap) envía el UID de una tarjeta recién
leída. El emparejamiento pendiente más reciente no consumido y no caducado
se consume y la tarjeta queda vinculada a su estudiante.

**Petición** (JSON):

```json
{ "credential_uid": "A1B2C3D4E5" }
```

`credential_kind` opcional (`physical` | `hce`, por defecto `physical`):
CÓMO se capturó la credencial — un UID MIFARE físico leído de la capa
RF, o un id de credencial HCE de Android a nivel de aplicación obtenido
mediante el intercambio APDU SELECT AID + CHALLENGE (AID
`F0010203040506`). Se guarda en la fila de `cards` (`cards.kind`) como
metadato de visualización/auditoría; la búsqueda del tap sigue siendo
solo por `credential_uid`, así que los lectores viejos que omiten el
kind emparejan exactamente igual que antes. Ver “Credenciales HCE de
Android” abajo.

```json
{ "credential_uid": "TEST-ANDROID-001", "credential_kind": "hce" }
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

`401` — firma del lector faltante o inválida (banco: clave Bearer). El emparejamiento es
de un solo uso: tras un emparejamiento exitoso, la siguiente lectura
recibe el 409. La tarjeta recién emparejada funciona de inmediato para los
toques en el endpoint de tap.

---

## Credenciales HCE de Android — el teléfono como credencial (integración HCE)

Un teléfono Android con la app HCE de Pulse (`B2B-App/pulse-credential`)
es una credencial Pulse de primera clase: se empareja, toca, revoca y
desvincula exactamente igual que una tarjeta física, por los MISMOS
endpoints de arriba. El lector detecta el teléfono como objetivo ISO-DEP
(bit 6 del SAK), selecciona el AID de Pulse `F0010203040506`, emite un
CHALLENGE aleatorio de 8 bytes y verifica la respuesta
`HMAC-SHA256(HCE_SECRET, credId || nonce)` del teléfono antes de enviar
el id de credencial a nivel de aplicación como `credential_uid` con
`credential_kind: "hce"`.

Reglas que el backend impone:

- **El UID NFC NUNCA es la identidad.** Android aleatoriza el UID RF en
  cada toque; el lector solo registra su longitud y el backend jamás lo
  ve. La identidad es el id de credencial a nivel de aplicación dentro
  del intercambio APDU.
- **El emparejamiento es explícito y autorizado por un humano** — arma
  para el estudiante en el escritorio de emparejamiento y luego toca el
  teléfono dentro de la ventana. Un toque sin ventana armada responde
  `409`; un id ya emparejado responde `422` sin reasignación — idéntico
  a las tarjetas físicas.
- **El resto de Pulse no distingue la diferencia**: los toques resuelven
  `credencial → estudiante → asistencia / PAE / reciclaje` por el
  endpoint de tap sin cambios y la misma espina de eventos, sin importar
  `cards.kind`.
- **La revocación es por estado** (`active` | `lost` | `revoked`): un
  teléfono revocado toca `404` como una tarjeta revocada; desvincular
  borra la fila y el id vuelve a ser emparejable.
- **Alcance de seguridad**: el prototipo usa una sola clave precompartida
  de desarrollo (`HCE_SECRET`) verificada en el lector, que la clave
  firma del lector luego avala ante el backend — la misma confianza que
  un UID físico. Ningún secreto se registra ni se guarda en el servidor.
  Claves por credencial, protección anti-replay y autenticación mutua son
  trabajo futuro registrado en la especificación del firmware
  (`B2B-Firmware/docs/HCE_PROTOCOL.md`, la referencia canónica del
  protocolo a nivel de bytes).

El escritorio de emparejamiento marca las credenciales de teléfono
(“Phone” / “Teléfono”) junto al id, en los chips del roster, el historial
reciente (filas renderizadas y en vivo por WebSocket) y el escritorio de
estudiantes.

---

## POST /api/v1/nl-query — consulta en lenguaje natural (Fase E, admin + docente)

**Petición**: `{"question": "¿Cuántos niños llegaron tarde esta semana?"}`

**Roles (TASK-027):** admin (toda la escuela) Y docente. Las preguntas de
un docente quedan cercadas **en el servidor** a las clases que dicta —
cada ejecución de función aplica el `StudentScope` del autor; una clase o
estudiante fuera del muro responde con un error explícito de alcance,
nunca con datos. Los estudiantes siguen en 403.

Flujo: la pregunta + un conjunto fijo de esquemas de funciones va al modelo
de DeepSeek (por defecto `deepseek-flash` — DeepSeek-V4.1-Flash; ADR-046) → el modelo
**selecciona una función** → el backend ejecuta la
**consulta Eloquent real** → el resultado vuelve al modelo → el modelo redacta
la respuesta final. El LLM nunca calcula ni fabrica cifras. Las respuestas
son **concisas por contrato** (máximo tres frases cortas o una lista
compacta) y usan **Markdown ligero** (`**negrita**`, viñetas `- `,
`` `comillas inversas` ``) — los paneles lo renderizan vía
`public/js/markdown.js` (escape primero, nunca HTML crudo).

Funciones disponibles, por familia — cada una ejecuta una consulta
Eloquent real en el servidor (el modelo solo elige y redacta):
conteos de asistencia `get_attendance_count(date, class_id?)`,
`get_absence_count(date, class_id?)`, `get_enrollment_count(class_id?)`;
listas de asistencia `get_present_students(date, class_id?)`,
`get_absent_students(date, class_id?)`,
`get_late_students(date, class_id?)`; vistas de asistencia
`get_class_status(class_id, date?)`, `get_attendance_by_class(date)`,
`get_attendance_trend(days)`,
`get_repeatedly_absent_students(days, min_absences, class_id?)`
(TASK-027), `get_perfect_attendance(days, class_id?)`,
`get_late_count(date, class_id?)`; PAE
`get_pae_count(meal, date, class_id?)`,
`get_pae_students(meal, date, class_id?)`,
`get_pae_trend(meal, days)`; presencia
(TASK-037, paridad PAE — el mismo PaeReportService de
/admin/reports/pae) `get_missed_meals(meal, date?, class_id?)`,
`get_missed_meal_count(meal, date?, class_id?)`,
`get_missed_meal_trend(meal, days?, class_id?)`,
`get_pae_enrollment(class_id?)`,
`get_student_pae_history(student_id, days?)`,
`get_student_meals_on(student_id, date?)` y
`get_flagged_meal_attempts(date_from?, date_to?)` —
`get_student_timeline(student_id)`; reciclaje/puntos
`get_recycling_totals(date_from, date_to)` y
`get_recycling_leaderboard(limit)` (ambas de toda la escuela por
diseño — tablero público de competencia, spec §22),
`get_student_points(student_id)`; más `find_student(name)` (resuelve
un nombre parcial a un `student_id`).

Contexto de fecha/hora: el backend inyecta la fecha y hora actual
(America/Bogota) en cada petición — "hoy", "ahora mismo" y
"¿quién vino?" se resuelven en el servidor y el modelo nunca pide la
fecha al usuario. "Who came / quién vino" lee la lista PRESENT,
"who was absent / quién faltó" la ABSENT.

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
| `llm_model_not_found` | `DEEPSEEK_MODEL` desconocido para esta cuenta/API (404 Model Not Exist) | Usa el valor por defecto `deepseek-flash` |
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

## GET/PUT /api/v1/admin/settings — configuración en vivo (TASK-037, solo administradores)

El entorno presence/PAE configurable por el administrador (ADR-055): la
lectura devuelve los valores EFECTIVOS (fila en settings → valor por
defecto de config); las escrituras validan y persisten lotes parciales
que aplican en la siguiente petición (el motor de comidas, el corte de
llegada tarde, la ventana de emparejamiento y las convenciones de cuentas
de estudiantes resuelven todos por el mismo servicio). Los secretos
nunca forman parte de esta superficie.

**GET `/api/v1/admin/settings`**:

```json
{
  "status": "ok",
  "settings": {
    "pae.breakfast_start": "06:30", "pae.breakfast_end": "08:30",
    "pae.lunch_start": "11:30", "pae.lunch_end": "13:30",
    "attendance.late_cutoff": "08:15",
    "pairing.window_seconds": 45,
    "accounts.student_email_domain": "presence.test",
    "accounts.student_initial_password": "password"
  },
  "customized": ["pae.breakfast_start"],
  "meal_windows": {
    "breakfast": {"start": "06:30", "end": "08:30"},
    "lunch": {"start": "11:30", "end": "13:30"}
  }
}
```

**PUT `/api/v1/admin/settings`** (también POST) — lotes parciales; se
valida la vista combinada, así que un campo malo nunca corrompe el resto:

```json
{ "settings": { "pae.breakfast_start": "07:00", "pae.breakfast_end": "09:00" } }
```

`200` devuelve la configuración efectiva completa. `422` lleva errores
por campo (formato `HH:MM`, fin estrictamente después del inicio, las
ventanas de desayuno/almuerzo no deben solaparse, ventana de
emparejamiento 10–600 s, claves desconocidas rechazadas).

Superficies web que usan esta API: `/admin/settings` (el escritorio,
bilingüe) y los reportes/exportaciones de solo web que siguen.

---

## Superficies web TASK-037 (autenticación de sesión, sin equivalente API)

- `/kitchen` — el escritorio de cocina (ADR-054): un estado gigante
  de aceptado/rechazado guiado por los toques en tiempo real (verde
  servida / rojo rechazada + motivo), una lista de comidas recientes,
  sin paneles densos. Los usuarios de cocina (`role: kitchen`) se
  autentican por el flujo normal de inicio de sesión y quedan
  restringidos a esta página; los administradores también pueden
  abrirla.
- `/admin/reports/pae` — el escritorio de reportes PAE (ADR-056):
  comidas servidas diarias/mensuales con gráficas SVG de tendencia,
  comidas perdidas (lista + tendencia), intentos excluidos con el
  desglose por motivo, resumen de inscripción por comida e historial
  por estudiante. Exportaciones:
  `/admin/reports/pae/export/pdf?type=daily|monthly|missed|flagged` y
  `/admin/reports/pae/export/csv?type=...` (PDF pulido vía dompdf —
  encabezado de marca, tarjetas de resumen, barras, tablas; el CSV son
  filas crudas estructuradas), más las variantes por estudiante
  `/admin/reports/pae/student/{id}/export/{pdf|csv}`.
- `/admin/reports/attendance` — el escritorio de reportes de
  asistencia: presentes/tarde/ausentes por día con desglose por clase
  y gráficas SVG de tendencia, agregados mensuales, ausentes
  frecuentes, conteo de asistencia perfecta e historial por
  estudiante. Exportaciones:
  `/admin/reports/attendance/export/pdf?type=daily|monthly|absentees`
  y `/admin/reports/attendance/export/csv?type=...`, más las
  variantes por estudiante
  `/admin/reports/attendance/student/{id}/export/{pdf|csv}`.
- `/admin/reports/recycling` — el escritorio de reportes de
  reciclaje: rendimiento diario/mensual con gráficas SVG de
  tendencia, mezcla de materiales, clasificación por puntos e
  historiales por estudiante. Exportaciones:
  `/admin/reports/recycling/export/pdf?type=daily|monthly|leaderboard`
  y `/admin/reports/recycling/export/csv?type=...`, más las
  variantes por estudiante
  `/admin/reports/recycling/student/{id}/export/{pdf|csv}`.

Los tres escritorios se muestran dentro de la interfaz con identidad
escolar, y sus PDFs también llevan la identidad del colegio que los
genera (banda y encabezados de tabla en el color primario de marca,
cresta escolar incrustada en el encabezado, nombre del colegio en el
pie — Pulse estándar en caso contrario).

---

## PUT /api/v1/admin/readers/{id} — ajustes del lector: nombre + modo (TASK-027, solo admin)

El endpoint que respalda el escritorio de gestión `/admin/readers` (la
página perdida en el rediseño del frontend, ahora restaurada): renombrar
un lector Y cambiar su modo activo en UNA petición. **Requiere rol admin**
(teacher → 403, invitado → 401). El endpoint de solo-modo de arriba queda
intacto — su contrato está fijado por pruebas.

**Petición**:

```json
{ "label": "Aula 12 — Entrada", "active_event_type": "PAE_LUNCH" }
```

`label`: obligatorio, 3–255 caracteres. `active_event_type`: obligatorio,
mismos valores válidos que el endpoint de modo.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 1, "label": "Aula 12 — Entrada", "type": "classroom", "active_event_type": "PAE_LUNCH" }
}
```

`422` — errores de validación (nombre corto, modo desconocido).

---

## POST /api/v1/admin/readers — crear un lector (TASK-030-B, solo admin)

Aprovisionamiento de lectores sin SQL: el escritorio `/admin/readers`
crea la fila por este endpoint. **Requiere rol admin.** La API key
NUNCA se acepta del cliente — el servidor genera una clave de 32
caracteres (el estándar del seeder) y la devuelve EXACTAMENTE UNA VEZ
(`api_key` + `api_key_notice`, la regla de mostrar-una-sola-vez de los
accesos de estudiantes). El objeto lector, los frames de roster, los
logs y toda respuesta posterior jamás la llevan. La creación anuncia un
frame `reader_created` (misma transacción).

**Petición**:

```json
{ "label": "Aula 12 — Entrada", "type": "pae", "active_event_type": "PAE_LUNCH" }
```

`label`: obligatorio, 3–255 caracteres. `type`: obligatorio, uno de
`classroom` `pae` `recycling`. `active_event_type`:
obligatorio, cualquier tipo de evento (el mismo conjunto que el
endpoint de ajustes).

**Respuesta `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 7, "label": "Aula 12 — Entrada", "type": "pae", "active_event_type": "PAE_LUNCH" },
  "api_key": "…32 caracteres, aquí y nunca más…",
  "message": "Lector Aula 12 — Entrada creado.",
  "api_key_notice": "API key (cópiala ahora — no se vuelve a mostrar)"
}
```

`422` — errores de validación (nada se crea, ningún frame se escribe).

---

## POST /api/v1/admin/readers/{reader}/rotate-key — rotar una clave (TASK-030-B, solo admin)

**Requiere rol admin.** Recuperación de clave perdida o sospechosa sin
SQL: reemplaza la clave y devuelve la nueva EXACTAMENTE UNA VEZ. La
clave vieja responde 401 desde ese momento (el lector instalado deja de
funcionar hasta que se le grabe la nueva — el escritorio confirma antes
de llamar). La rotación queda en el log (id del lector + id del admin,
nunca la clave); ningún frame de roster se escribe (ningún estado
visible cambia).

**Respuesta `200`**:

```json
{
  "status": "ok",
  "reader": { "id": 7, "label": "Aula 12 — Entrada" },
  "api_key": "…32 caracteres frescos, aquí y nunca más…",
  "message": "API key rotada para Aula 12 — Entrada.",
  "api_key_notice": "API key (cópiala ahora — no se vuelve a mostrar)"
}
```

`404` — lector desconocido.

---

## DELETE /api/v1/admin/readers/{reader} — eliminar un lector (solo admin)

**Requiere rol admin** (docente → 403, invitado → 401). Retira un
lector dado de baja desde el escritorio `/admin/readers` (menú ⋯,
confirmación Datum): la fila del lector se va, y sus eventos de
toque + depósitos de reciclaje + capturas pendientes se van con
ella (borrados explícitos hijo-primero — deterministas donde el
pragma foreign_key de sqlite está apagado). Las filas del historial
de emparejamiento sobreviven con el enlace al lector en null; las
filas del libro de puntos conservan su valor con `event_id` en null;
las imágenes guardadas se borran del disco. Un frame de roster
`reader_deleted` quita la fila del escritorio en vivo; la vieja
clave responde 401 desde ese momento.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "deleted": { "id": 7, "label": "Aula 12 — Entrada", "events_deleted": 3 },
  "message": "Lector Aula 12 — Entrada eliminado."
}
```

`404` — lector desconocido.

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
{ "name": "Nueva Estudiante", "grade": "5°", "class_id": 1, "pae_breakfast_enrolled": true, "pae_lunch_enrolled": true }
```

**Respuesta `200`**:

```json
{
  "status": "ok",
  "student": { "id": 9, "name": "Nueva Estudiante", "grade": "5°", "class_name": "5° B", "pae_breakfast_enrolled": true, "pae_lunch_enrolled": true, "account_email": "nueva@presence.test" },
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
name,grade,class,pae_breakfast,pae_lunch
María Pérez,5°,5° B,yes
```

`class` se resuelve por NOMBRE de clase (el flujo humano); TASK-037 —
`pae_breakfast` / `pae_lunch` (alias `breakfast` / `lunch`) inscriben
para CADA comida de forma independiente; la columna única heredada
`pae` (o `pae_enrolled`) sigue funcionando e inscribe para AMBAS
comidas; `pae_enrolled`
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

## POST /api/v1/admin/staff — crear un acceso de personal (TASK-038, solo admin)

El fin de los accesos de personal por seeder o SQL: el escritorio
`/admin/staff` crea accesos de admin, profesor y cocina con este
endpoint. **Requiere rol admin.** El admin elige la contraseña temporal
(mínimo 8, confirmada); el primer inicio de sesión obliga a rotarla por
una personal. El escritorio sugiere `{nombre}.{rol}@{accounts.student_email_domain}`
(editable — una dirección ocupada es un honesto 422). La respuesta lleva las credenciales EXACTAMENTE UNA VEZ
(`account` + `account_notice` — la regla de un solo vistazo de las API
keys de lector); nada más (logs, marcos, otros endpoints) lleva jamás
la contraseña.

Los profesores pueden tomar clases a cargo en la misma petición
(`class_ids` — el escritorio solo lista clases sin profesor, una clase
un profesor; el endpoint las asigna en la MISMA transacción, reasignar
una clase con profesor por llamada directa a la API sobrescribe;
`class_ids` en cualquier otro rol es `422
{"status":"error","reason":"classes_teacher_only"}`). Los accesos de
estudiantes deliberadamente NO están aquí — son la capa de cuentas 1:1
que crea el escritorio de estudiantes (ver `POST /api/v1/admin/students`).

**Petición**:

```json
{ "name": "Prof. Luis Gómez", "email": "luis.g@presence.test", "role": "teacher", "password": "cambia-ya-01", "password_confirmation": "cambia-ya-01", "class_ids": [3] }
```

**TASK-045** — la cuenta nueva se une automáticamente a la organización
del admin que la crea. `school_id` solo se acepta del administrador del
sistema (un admin sin colegio propio); de un admin de colegio se ignora,
nunca se obedece. Los `class_ids` deben pertenecer a la organización de
quien llama, o la petición es `422`.

**Respuesta `200`**:

```json
{
  "status": "ok",
  "staff": { "id": 12, "name": "Prof. Luis Gómez", "email": "luis.g@presence.test", "role": "teacher", "classes": ["5° A"] },
  "account": { "email": "luis.g@presence.test", "temporary_password": "cambia-ya-01", "must_change_password": true },
  "message": "Acceso de personal Prof. Luis Gómez creado.",
  "account_notice": "Acceso listo: luis.g@presence.test / contraseña temporal cambia-ya-01 — debe cambiarse en el primer inicio de sesión"
}
```

`422` — errores de validación, `{"status":"error","reason":"duplicate"}`
si el correo ya existe (sin distinguir mayúsculas), o
`{"status":"error","reason":"classes_teacher_only"}` por `class_ids`
en un rol no profesor. Deliberadamente solo-creación (el precedente de
creación de clases): editar personal y reasignar clases quedan para otra
tarea.

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

## Alcance por organización (colegio) — TASK-045, ADR-064

Todo recurso con dueño organizacional está amurallado **en el
servidor**, en cada verbo y en cada superficie (índice, detalle, crear,
actualizar, borrar, búsqueda, filtros, operaciones masivas, importación
CSV, frames en vivo, consultas NL). La muralla es un scope global a
nivel de modelo, no una comprobación por controlador, así que un
endpoint nuevo no puede olvidarla.

Dos reglas gobiernan todo el contrato:

1. **La organización se deriva, nunca se envía.** Sale de la cuenta
   autenticada (`users.school_id`) o, en endpoints de dispositivo, del
   lector que la clave API/HMAC demostró (ADR-002/ADR-062). Ninguna
   petición acepta un id de organización como verdad. Un recurso creado
   hereda automáticamente la organización de quien lo crea.
2. **Un id ajeno se rechaza, no se filtra.** El route-model binding
   resuelve a través del scope, así que el id de otra organización
   responde `404` — un rechazo que además no confirma que la fila exista
   en otro lado. Las claves foráneas del cuerpo (`class_id`,
   `reward_id`, `event_id`, `class_ids[]`) se validan a través del
   modelo acotado, así que responden `422` en vez de dejar una fila sin
   padre.

**La única excepción deliberada:** un admin cuyo propio `school_id` es
`NULL` es el *administrador del sistema* y opera sobre todas las
organizaciones. Esa capacidad la otorga la propia fila de la cuenta.
`POST /api/v1/admin/staff` es el único endpoint que acepta `school_id`,
y solo desde esa cuenta — para cualquier otra el campo se ignora (nunca
se obedece) y la cuenta nueva hereda la organización de quien la crea.

Los endpoints de dispositivo heredan la misma muralla: un lector solo
puede registrar un toque, clasificar un evento o resolver una captura
dentro de su propia organización. Una tarjeta de otro colegio responde
el `404 {"reason":"not_found"}` habitual — la misma respuesta que recibe
una tarjeta desconocida.

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

**Aut: firma de petición de un lector de reciclaje (la estación de cámara; banco: clave Bearer). El cuerpo firmado es el canónico multipart `image.sha256` (ver modelos de autenticación).**
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

**Aut: firma de petición del MISMO lector de reciclaje que guardó la captura (banco: clave Bearer).**
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
 "payload": {"id": 9, "name": "Nueva Estudiante", "grade": "5°", "class_id": 1, "class_name": "5° B", "pae_breakfast_enrolled": false, "pae_lunch_enrolled": false},
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
