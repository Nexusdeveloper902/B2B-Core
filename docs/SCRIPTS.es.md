# Referencia de Scripts de Ejecución — Un Comando para Todo

> **Leer en:** [English](SCRIPTS.md)
>
> La suite `./run` reemplaza el arranque manual de ~10 pasos (composer install,
> cp .env, key:generate, touch db, migrate --seed, serve, artisan test, pint,
> e2e.sh, venv+pip+uvicorn) por **un comando bien documentado por operación**.
> El soporte Linux es prioritario, **Arch Linux primero**.

Las decisiones de diseño viven en `.agent/DECISIONS/`:
[ADR-009](../.agent/DECISIONS/ADR-009-run-dispatcher.md) (arquitectura del dispatcher),
[ADR-010](../.agent/DECISIONS/ADR-010-arch-first-with-hermetic-fallback.md) (Arch primero + toolchain hermético),
[ADR-011](../.agent/DECISIONS/ADR-011-idempotent-self-diagnosing-scripts.md) (scripts idempotentes y autodiagnósticos).

## La versión de 60 segundos

```bash
git clone https://github.com/Nexusdeveloper902/B2B-Core.git
cd B2B-Core
./run setup     # dependencias + .env + APP_KEY + base de datos + datos demo (imprime credenciales)
./run serve     # servidor de desarrollo verificado → http://127.0.0.1:8000
```

Eso es todo el inicio rápido. Si algo anda mal en tu máquina, el siguiente
comando siempre se te imprime — y normalmente es `./run doctor`.

## Referencia de comandos

| Comando | Reemplaza | Descripción |
|---|---|---|
| `./run setup` | 5 comandos de arranque | Bootstrap completo e idempotente, imprime credenciales demo |
| `./run serve` | `php artisan serve` + esperanza | Servidor de desarrollo con verificaciones previas |
| `./run test` | `php artisan test --testsuite=…` | Pirámide de pruebas: `unit` · `feature` · `e2e` · `all` |
| `./run e2e` | `bash scripts/e2e.sh` | End-to-end real por HTTP (BD desechable) |
| `./run quality` | `vendor/bin/pint` + revisión manual | Pint + sintaxis bash + shellcheck + paridad de docs |
| `./run doctor` | interpretar mensajes de error | Diagnóstico del entorno + soluciones exactas |
| `./run status` | adivinar | Estado de la app: toolchain, .env, BD, servidores, clasificador |
| `./run reset` | `migrate:fresh --seed` | BD nueva + datos demo (pregunta primero) |
| `./run model` | venv + pip + uvicorn (3 cmds) | Servidor del modelo local: start/stop/status/run |
| `./run toolchain` | instalar PHP a mano | PHP+Composer herméticos estáticos en `.tools/` |
| `./run ci` | leer ci.yml | Todo lo que corre CI, localmente y en orden |

Cada comando acepta `--help` (p. ej. `./run setup --help`) y cada script
también se ejecuta directamente (`bash scripts/setup.sh …`) — así los usa CI.

---

## `setup`

```bash
./run setup                # bootstrap completo (seguro de re-ejecutar)
./run setup --ci           # silencioso: composer install + .env + APP_KEY
./run setup --hermetic     # provisiona el PHP de .tools/ y luego hace setup
./run setup --no-seed      # migra sin datos demo
./run setup --fresh        # borra la BD de desarrollo y reconstruye desde cero
```

Qué hace, en orden (cada paso es un no-op si ya está hecho — se puede re-ejecutar
en cualquier momento, ver ADR-011):

1. Resuelve PHP y Composer (ver [resolución del toolchain](#resolución-del-toolchain)).
2. `composer install` (verifica `vendor/` después).
3. Crea `.env` desde `.env.example` **solo si no existe** — tus ediciones
   nunca se sobrescriben.
4. Genera `APP_KEY` solo si está vacía.
5. Crea `database/database.sqlite` cuando la conexión es sqlite.
6. `php artisan migrate --force` + `php artisan db:seed --force` (se omite con
   `--ci`; el seeder es idempotente con firstOrCreate y **re-imprime todas las
   credenciales demo bilingüe** en cada ejecución).

Códigos de salida: `0` bien · `1` fallo con la solución sugerida impresa.

## `serve`

```bash
./run serve                # 127.0.0.1:8000
./run serve 8080           # puerto personalizado
./run serve --host=0.0.0.0 # todas las interfaces (demo en LAN)
```

Falla **antes** de abrir el puerto (no a mitad de una petición) cuando el setup
está incompleto, y te dice exactamente qué comando de `./run` lo arregla.
Variables: `B2B_SERVE_PORT`, `B2B_SERVE_HOST`.

## `test`

```bash
./run test                 # todas las suites
./run test unit            # servicios, clasificadores, orquestación NL
./run test feature         # integración de API + web
./run test e2e             # jornadas completas bilingües (en proceso)
./run test unit --filter=PointsServiceTest   # se pasa directo a artisan
```

Todo lo que sigue al nombre de la suite se reenvía a `php artisan test`. Las
pruebas con LLM real siguen siendo opcionales (`RUN_LIVE_LLM_TESTS=1` +
`GEMINI_API_KEY`).

## `e2e`

```bash
./run e2e                  # BD desechable en el puerto 8089
./run e2e 9090             # puerto personalizado
```

Arranca `php artisan serve` contra `database/e2e.sqlite` (tu base de datos de
desarrollo **nunca se toca**) y luego ejercita toda la historia de la plataforma
por HTTP plano con 22 verificaciones bilingües: tap → clasificar → otorgo
idempotente → reetiquetar lector → canje → estado bloqueado honesto de NL-query
→ enrutado de paneles. Sale con código distinto de cero si algo falla.

## `quality`

```bash
./run quality
```

La puerta de lint, igual que la etapa de lint de CI: (1) `bash -n` sobre `run`
y todos los scripts, (2) shellcheck si está instalado (CI siempre lo instala),
(3) Laravel Pint, (4) paridad bilingüe de docs — cada comando del dispatcher
debe tener su sección en `docs/SCRIPTS.md` **y** `docs/SCRIPTS.es.md`.

## `doctor`

```bash
./run doctor
```

Revisa SO/distro, bash, curl, git, python3, **cada** candidato de PHP y **cada**
candidato de Composer (con el motivo de fallo de cada uno), y luego el estado
del proyecto (.env, APP_KEY, vendor/, archivo de BD + esquema). Sale `0` solo
cuando todo está en verde, e imprime la remediación exacta de cada problema —
en Arch incluye comandos `pacman` + `php.ini` listos para copiar y pegar (ver
[Arch Linux](#arch-linux-1)).

## `status`

```bash
./run status
```

De un vistazo: distro, PHP/Composer resueltos (y de dónde vienen), estado de
.env y APP_KEY, driver del clasificador, base de datos, salud del servidor de
app (`/up`) y del servidor de modelo. Informativo — siempre sale `0`.

## `reset`

```bash
./run reset                # pide confirmación
./run reset --force        # sin pregunta (scripts/CI)
```

Borra **solo la BD sqlite de desarrollo** y la reconstruye con datos demo
frescos (`migrate:fresh --seed`), reimprimiendo todas las credenciales. La BD
desechable del e2e no se afecta. Se niega a operar si `DB_CONNECTION` no es
sqlite.

## `model`

```bash
./run model start    # venv (una vez) + deps + uvicorn en segundo plano + espera sano
./run model status   # salud + PID + ruta del log
./run model stop     # lo detiene (SIGTERM, luego SIGKILL si hace falta)
./run model run      # modo primer plano (Ctrl+C)
```

Gestiona el sidecar clasificador local (`scripts/local-model-server/`, ver
[docs/LOCAL_MODEL.es.md](LOCAL_MODEL.es.md)). El venv se crea una sola vez y las
dependencias se reinstalan automáticamente solo cuando cambia
`requirements.txt`. Log: `storage/logs/model-server.log`; PID:
`.model-server.pid` (ignorado por git). Variable: `B2B_MODEL_PORT` (por defecto
8501, coincide con `LOCAL_CLASSIFIER_URL`).

## `toolchain`

```bash
./run toolchain           # no-op idempotente si ya está provisionado
./run toolchain --force   # re-descarga
```

Descarga un **CLI de PHP enlazado estáticamente** (todas las extensiones
requeridas compiladas dentro — verificado contra la misma lista de módulos que
revisa `doctor`) más `composer.phar` en `.tools/` (ignorado por git). Sin root,
sin paquetes del sistema, sin editar `php.ini`. Funciona en cualquier Linux
x86_64 — incluyendo una instalación limpia de Arch, contenedores y máquinas
donde simplemente no puedes tocar el PHP del sistema. Fija una versión con
`B2B_STATIC_PHP_VERSION` (por defecto: `8.4.23`).

## `ci`

```bash
./run ci
```

Ejecuta, en el orden de CI: `quality` → `test all` → `e2e`. Falla rápido e
indica qué etapa se rompió. Es el mismo camino de código que el pipeline de
GitHub Actions usa en cada push.

---

## Resolución del toolchain

Cada script resuelve su intérprete con una sola cadena (ADR-010), así que
ningún script llama jamás a un `php` desnudo:

1. **Variable de entorno `B2B_PHP`** (debe ser válida; si no, se ignora con aviso)
2. **`php` en el PATH** — cuando es ≥ 8.3 y tiene todos los módulos requeridos
   (`ctype curl dom fileinfo libxml mbstring openssl pdo_sqlite session
   sqlite3 tokenizer xml xmlwriter zip`)
3. **`.tools/php`** — la construcción estática hermética de `./run toolchain`

Composer sigue la misma cadena (`B2B_COMPOSER` → PATH → `.tools/composer`) y
siempre se **ejecuta a través del PHP resuelto** (`php composer.phar …`), así
que toda la suite funciona en máquinas sin `php` en el PATH.

| Variable | Por defecto | Usada por |
|---|---|---|
| `B2B_PHP` | — | forzar un binario de PHP |
| `B2B_COMPOSER` | — | forzar un phar/binario de Composer |
| `B2B_STATIC_PHP_VERSION` | `8.4.23` | `./run toolchain` |
| `B2B_SERVE_PORT` / `B2B_SERVE_HOST` | `8000` / `127.0.0.1` | `./run serve` |
| `B2B_MODEL_PORT` | `8501` | `./run model` |
| `NO_COLOR` | — | desactivar los colores |

## Arch Linux

Arch es la plataforma prioritaria. `sudo pacman -S php` trae un
`/etc/php/php.ini` con la mayoría de líneas `extension=` **comentadas**, y —
importante — el Arch actual **separa las extensiones SQLite en un paquete
`php-sqlite` distinto** (el paquete principal `php` ya no contiene
`pdo_sqlite.so`/`sqlite3.so`), así que una instalación limpia falla los
requisitos de extensiones de Laravel hasta arreglar ambas cosas.
`./run doctor` lo detecta e imprime la solución exacta:

```bash
sudo pacman -S --needed php php-sqlite composer
# Habilita las extensiones que la plataforma necesita (como imprime ./run doctor):
sudo sed -ri 's/^;(extension=(ctype|curl|dom|fileinfo|libxml|mbstring|openssl|pdo_sqlite|session|sqlite3|tokenizer|xml|xmlwriter|zip))$/extension=\1/' /etc/php/php.ini
./run setup && ./run doctor   # verifica → todo en verde
./run serve
```

El comando `sed` no es folclore: el job de CI `arch-smoke` aplica exactamente
esta remediación en un contenedor real `archlinux:base` en cada push, así que
el consejo está verificado por máquina de forma continua. Si prefieres no tocar
el sistema en absoluto, la ruta hermética funciona en un Arch limpio sin
paquetes:

```bash
./run toolchain && ./run setup   # sin root, sin pacman, sin editar php.ini
```

El servidor del modelo local necesita `python3`, que es parte del sistema base
de Arch (`python -m venv` funciona de fábrica — no hace falta ningún paquete
extra).

## Solución de problemas

| Síntoma | Causa | Solución |
|---|---|---|
| `No usable PHP found …` | sin PHP / PHP viejo / faltan extensiones | lee la pista de tu distro, o `./run toolchain` |
| `vendor/ missing — run ./run setup` | dependencias sin instalar | `./run setup` |
| `APP_KEY: EMPTY` | .env creado pero clave sin generar | `./run setup` (solo rellena vacíos) |
| `database/database.sqlite missing` | no hay archivo de BD | `./run setup` |
| Composer "not usable" en doctor | no hay PHP por el cual correr el phar | arregla primero el PHP (ver arriba) |
| `Model server failed to become healthy` | puerto ocupado / venv roto | `tail storage/logs/model-server.log`, `./run model stop` y luego `start` |
| El servidor corre pero las páginas fallan | estado de setup parcial | `./run doctor` y luego `./run setup` |
| Pint falla en `./run quality` | deriva de estilo | `vendor/bin/pint` (aplica fixes), re-ejecuta |

## Mapa de archivos

```
run                              # el dispatcher (punto único de entrada)
scripts/
├── _lib/common.sh               # librería: cadena de resolución, logging, detección de distro
├── setup.sh · serve.sh · test.sh · e2e.sh · quality.sh
├── doctor.sh · status.sh · reset.sh
├── model-server.sh              # ciclo de vida del clasificador local
├── provision-toolchain.sh       # provisionador hermético de PHP+Composer
├── ci.sh                        # espejo local de CI
└── local-model-server/          # servidor FastAPI de referencia (sin cambios)
```
