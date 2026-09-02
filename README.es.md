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

Requisitos: PHP 8.3+ (con `sqlite3`, `pdo_sqlite`, `mbstring`, `curl`,
`zip`), Composer.

```bash
git clone https://github.com/Nexusdeveloper902/B2B-Core.git
cd B2B-Core
composer install
cp .env.example .env          # luego define APP_KEY + claves opcionales
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed    # el seeder IMPRIME todas las credenciales 🖨️
php artisan serve             # → http://localhost:8000
```

El seeder imprime (en inglés Y español): los inicios de sesión del panel, el
`credential_uid` de cada tarjeta y la `api_key` Bearer de cada lector —
cópialos directamente a las variables de
[docs/postman_collection.json](docs/postman_collection.json) o a curl.

**Inicios de sesión demo:** `admin@presence.test` /
`teacher@presence.test` — contraseña `password`.

### Configuración opcional (¡nunca commitear claves reales!)

```dotenv
GEMINI_API_KEY=               # habilita consultas NL en vivo (solo modelos flash)
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

## Pruebas

```bash
php artisan test                    # todo (unit + feature + E2E)
php artisan test --testsuite=Unit   # servicios, clasificadores, orquestación NL
php artisan test --testsuite=Feature# pruebas de integración API + web
php artisan test --testsuite=E2E    # recorridos completos bilingües de la plataforma

vendor/bin/pint                     # estilo de código (Laravel Pint)

bash scripts/e2e.sh                 # E2E con HTTP REAL: arranca el servidor,
                                    # siembra y ejercita todo el flujo con curl
```

Las pruebas LLM en vivo se **omiten por defecto** (amigables con el nivel
gratuito); actívalas con `RUN_LIVE_LLM_TESTS=1` más una `GEMINI_API_KEY`
real.

## CI

`.github/workflows/ci.yml` se ejecuta en cada push/PR: **lint** (Pint),
**unit**, **integración** (feature), **E2E**, un trabajo e2e de **HTTP
real** (`scripts/e2e.sh` contra `php artisan serve`), un **escaneo de
secretos** (gitleaks) y un trabajo opcional de **smoke LLM en vivo** que
solo corre cuando el secreto `GEMINI_API_KEY` está configurado.

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
docs/                                     # API.md/.es.md, LOCAL_MODEL.md/.es.md, Postman
scripts/e2e.sh                            # runner end-to-end con HTTP real
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
