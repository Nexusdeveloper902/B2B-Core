# Plataforma de Presencia — Plataforma Central (Backend + Paneles)

> **Léelo en:** [English](README.md)
>
> La tarjeta es la identidad, la plataforma es la inteligencia. Una tarjeta
> NFC por persona; cada tap se convierte en un evento de presencia
> etiquetado; la asistencia, el PAE (alimentación escolar) y los incentivos
> de reciclaje son **vistas derivadas de ese único flujo de eventos**.

[![CI](https://github.com/Nexusdeveloper902/B2B-Core/actions/workflows/ci.yml/badge.svg)](https://github.com/Nexusdeveloper902/B2B-Core/actions/workflows/ci.yml)

## Qué es esto

Una aplicación Laravel 13 que ingiere **eventos de tap** de lectores NFC
(hoy simulados vía Postman/pruebas — hardware ESP32 real más adelante con
**cero cambios en el backend**), y deriva tres aplicaciones de un único flujo
unificado de eventos:

1. **Registro de asistencia** — la aplicación fundamental
2. **PAE (programa de alimentación escolar)** — desayuno/almuerzo obligatorios
3. **Incentivos de reciclaje** — tap → clasificar material → **ganar**
   puntos → **gastarlos** en recompensas (un bucle real de ganar+gastar con
   paso de verificación, a diferencia de displays de puntos sin mecanismo de
   gasto)

Más dos componentes de IA que cierran huecos reales:

- Un **clasificador de materiales por visión** en la estación de reciclaje,
  detrás de una interfaz intercambiable — se ejecuta como **modelo local**
  por diseño ([docs/LOCAL_MODEL.es.md](docs/LOCAL_MODEL.es.md))
- Una **interfaz de consulta en lenguaje natural** sobre la base de eventos
  usando function-calling de Gemini — el LLM selecciona funciones, el
  backend calcula las respuestas reales

La app es completamente **bilingüe (inglés / español)**: interfaz, mensajes
de API para dispositivos (`Accept-Language`), salida del seeder,
documentación y pruebas.

## Inicio rápido

La suite **`./run`** es lo único que debes recordar — un comando por
operación, completamente documentada en [docs/SCRIPTS.es.md](docs/SCRIPTS.es.md) ·
[docs/SCRIPTS.md](docs/SCRIPTS.md), Linux primero con **soporte para Arch**:

```bash
git clone https://github.com/Nexusdeveloper902/B2B-Core.git
cd B2B-Core
./run setup     # dependencias + .env + APP_KEY + BD + datos demo — imprime credenciales 🖨️
./run serve     # → http://127.0.0.1:8000  (salud: /up)
```

`./run setup` es idempotente y se puede re-ejecutar en cualquier momento;
bajo el capó hace `composer install`, crea `.env` (nunca sobrescribe uno
existente), genera `APP_KEY` y luego `php artisan migrate --seed` — el seeder
imprime todas las credenciales demo bilingüe (EN/ES). ¿Aún no tienes un PHP
utilizable? `./run doctor` imprime la solución exacta para tu distro, o
`./run toolchain` provisiona un PHP+Composer hermético sin paquetes del
sistema.

El seeder imprime los inicios de sesión del panel, el `credential_uid` de
cada tarjeta y la `api_key` Bearer de cada lector — cópialos directamente a
las variables de [docs/postman_collection.json](docs/postman_collection.json)
o a curl.

**Inicios de sesión demo:** `admin@presence.test` /
`teacher@presence.test` — contraseña `password`.

### Configuración opcional (¡nunca commitear claves reales!)

```dotenv
GEMINI_API_KEY=               # habilita consultas NL en vivo (familia flash,
                             # por defecto gemini-3.1-flash-lite)
RECYCLING_CLASSIFIER_DRIVER=stub   # stub | local | gemini
LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
ATTENDANCE_LATE_CUTOFF=08:15  # corte de "tarde" del panel del profesor
```

## Prueba el bucle central en 60 segundos

```bash
READER_KEY=<clave del lector de aula según la salida del seeder>

# 1. Un tap → un evento
curl -X POST http://localhost:8000/api/v1/events/tap \
  -H "Authorization: Bearer $READER_KEY" -H "Accept: application/json" \
  -d '{"credential_uid":"<uid de tarjeta de estudiante>"}'

# 2. Mensajes de dispositivo en español
curl -X POST http://localhost:8000/api/v1/events/tap \
  -H "Authorization: Bearer $READER_KEY" -H "Accept-Language: es" \
  -H "Accept: application/json" -d '{"credential_uid":"NOPE"}'
# → {"status":"error","message":"Tarjeta no reconocida"}
```

Documentación completa de endpoints: [docs/API.es.md](docs/API.es.md) ·
[docs/API.md](docs/API.md) ·
[docs/postman_collection.json](docs/postman_collection.json)

## Paneles

| Ruta | Rol | Muestra |
|---|---|---|
| `/login` | — | Inicio de sesión EN/ES |
| `/teacher` | profesor (o admin) | Asistencia de hoy de la clase: presente / tarde (tras el corte configurable) / ausente |
| `/admin` | admin | Estadísticas de toda la escuela (asistencia, PAE desayuno/almuerzo, reciclaje artículos+puntos), control de modo de lectores, caja de consulta NL, mostrador de canjes, enlaces a vista de padres |
| `/parent/students/{id}` | admin/profesor | Línea de tiempo completa de eventos de un estudiante (sustituto simplificado de vista de padres — un sistema real de autenticación de padres está intencionalmente fuera del alcance) |

Cambio de idioma: `EN·ES` en la barra de navegación (por sesión).

### Sistema de diseño — "The Event Ledger"

Los paneles comparten una sola identidad visual con la tienda del marketplace:
fondo porcelana, reglas finas, datos tabulares en monoespaciada, acentos
verde pino, radios de 2px en controles, fuentes autohospedadas Space Grotesk /
IBM Plex. Los tokens viven en `public/css/tokens.css` (fuente de verdad) y se
replican por valor desde el repositorio del marketplace — ver
`.agent/DECISIONS/ADR-013-design-tokens-value-matched.md` y
`.agent/DECISIONS/ADR-014-shared-layout-components.md`.

## Pruebas

```bash
./run test                     # todo (unit + feature + E2E)
./run test unit                # servicios, clasificadores, orquestación NL
./run test feature             # pruebas de integración API + web
./run test e2e                 # recorridos completos bilingües de la plataforma

./run quality                  # Pint + sintaxis bash + shellcheck + paridad de docs
./run e2e                      # E2E con HTTP REAL: arranca el servidor,
                               # siembra una BD desechable y ejercita todo el flujo
./run ci                       # todo lo que corre CI, localmente y en orden
```

Cada comando acepta `--help`. Las pruebas LLM en vivo se **omiten por
defecto** (amigables con el nivel gratuito); actívalas con
`RUN_LIVE_LLM_TESTS=1` más una `GEMINI_API_KEY` real.

## CI

`.github/workflows/ci.yml` se ejecuta en cada push/PR: **lint** (Pint),
**unit**, **integración** (feature), **E2E**, un trabajo e2e de **HTTP
real**, un **escaneo de secretos** (gitleaks) y un trabajo opcional de
**smoke LLM en vivo** — más tres trabajos que verifican continuamente la
suite `./run` en sí: **scripts-lint** (sintaxis bash + shellcheck sobre todos
los scripts), **arch-smoke** (la suite en un contenedor real
`archlinux:base`) y **hermetic-smoke** (setup completo en un contenedor **sin
ningún PHP**). Los trabajos de CI usan `./run setup --ci` y `./run e2e` en
cada push.

## Arquitectura en un párrafo

`events` es la **columna vertebral de tipos de evento**: un tap = una fila
(`card_id`, `reader_id`, `type`, `occurred_at`). Los números de asistencia,
PAE y reciclaje **siempre se derivan** de esa tabla — nunca se almacenan por
separado — así las tres aplicaciones nunca pueden divergir. Los lectores se
autentican con una clave Bearer estática (la clave ES la identidad del
lector). Los puntos viven en un `points_ledger` de solo-agregación (saldo =
`SUM(delta)`). El clasificador es un contrato (`MaterialClassifier`)
resuelto desde configuración — stub hoy, un servicio local de inferencia en
etapas posteriores, sin tocar un solo controlador. Ver `.agent/ARCHITECTURE/`
para detalles y `.agent/DECISIONS/` para los ADR.

## Estructura del repositorio

```
app/
├── Contracts/MaterialClassifier.php      # el contrato intercambiable del clasificador
├── Enums/                                # EventType, MaterialClass, ...
├── Http/Controllers/Api/V1/              # tap, classify, redeem, mode, nl-query
├── Http/Controllers/Web/                 # login + paneles
├── Models/                               # Eloquent (events = PresenceEvent)
└── Services/                             # Tap, Points, Attendance, Recycling, NlQuery
database/migrations/ + seeders/DemoSeeder.php   # esquema + impresión bilingüe de credenciales
docs/                                     # API, LOCAL_MODEL, SCRIPTS (.md/.es.md), Postman
run + scripts/ + scripts/_lib/common.sh   # la suite ./run (un comando por operación)
scripts/local-model-server/               # sidecar de referencia del clasificador local (FastAPI)
tests/{Unit,Feature,E2E}/                 # la pirámide de pruebas
.agent/                                   # memoria persistente del proyecto (solo-agregación)
```

## Estado

Construido bajo `TASK-002-core-platform-mvp` — ver
`.agent/TASKS/TASK-002-core-platform-mvp.md` para el estado fase por fase y
`.agent/STATE/` para las instantáneas. Las pruebas en vivo del LLM de la
Fase E dependen del entorno (reporta el bloqueo honestamente, nunca
falsees una respuesta).
