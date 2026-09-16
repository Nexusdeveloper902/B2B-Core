# Datos y almacenamiento — Pulse Core

> **Lee esto en:** [English](DATABASE.md)
>
> Establecido por la migración a MariaDB (2026-09-12, ADR-049, que
> sustituye el "SQLite por ahora" de ADR-001). El almacenamiento de
> desarrollo/producción del producto es **MariaDB**; SQLite sigue siendo
> un motor de primera clase para clones frescos, la suite de pruebas
> automatizada y el e2e hermético.

## 1. El contrato de motores

| Motor | Uso | Por qué |
|---|---|---|
| **MariaDB** (10.6+ / 12.x verificado) | la aplicación en marcha (dev/prod), tablas de sesiones y caché, feed en tiempo real | almacenamiento basado en servidor, según se pidió; mismo esquema vía migraciones Eloquent |
| **SQLite** (`:memory:`) | `php artisan test` (la suite de 453 pruebas) | hermética, rápida, sin servidor en CI |
| **SQLite** (archivo) | BD desechable de `./run e2e` | la suite de contratos HTTP real es autocontenida por diseño (ADR-010/011) |

Las migraciones son neutras al motor (sin SQL específico de SQLite en
ningún lado — verificado ejecutando la suite completa en AMBOS motores).
El único ensanchamiento deliberado: `roster_updates.payload` es `json`
(LONGTEXT en MariaDB) — un broadcast de importación masiva nunca debe
chocar con el techo de 64 KB del antiguo TEXT.

## 2. Aprovisionar MariaDB (una sola vez)

Crea la base de datos y el usuario de la aplicación (las credenciales
viven SOLO en `.env`):

```sql
CREATE DATABASE pulse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pulse'@'localhost' IDENTIFIED BY '<contraseña-de-.env>';
CREATE USER 'pulse'@'127.0.0.1' IDENTIFIED BY '<contraseña-de-.env>';
GRANT ALL PRIVILEGES ON pulse.* TO 'pulse'@'localhost';
GRANT ALL PRIVILEGES ON pulse.* TO 'pulse'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Luego en `.env`:

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pulse
DB_USERNAME=pulse
DB_PASSWORD=<la contraseña>
```

`./run setup` verifica la conectividad antes de migrar; `./run status`
reporta el destino activo; `./run doctor` comprueba `pdo_mysql`, la
conexión y la profundidad del esquema; `./run reset` ejecuta el
`migrate:fresh --seed` con guarda en MariaDB igual que en SQLite.

## 3. La instancia de esta estación (servicio systemd de usuario)

La MariaDB del sistema (puerto 3306) es solo para root (autenticación
unix_socket, sin root para el agente). Pulse corre por eso en una
**instancia de usuario** — el mismo binario de servidor, propiedad de
`jperez`, duradera (el lingering de usuario está activado):

- Unidad: `~/.config/systemd/user/pulse-mariadb.service`
  (`systemctl --user enable --now pulse-mariadb`)
- Datadir: `~/.local/share/pulse-mariadb`
- Socket: `$XDG_RUNTIME_DIR/pulse-mariadb.sock` (admin = `jperez` vía
  unix_socket), TCP **127.0.0.1:33060** (solo loopback)
- Bases de datos: `pulse` (app) y `pulse_test` (la suite sobre MariaDB)

`.env` apunta a `127.0.0.1:33060`. Para pasar al servidor del sistema
más tarde: aprovisiona la BD y el usuario allí (requiere root), cambia
`DB_PORT` a 3306, `./run reset --force`.

## 4. CI

`ci.yml` ejecuta **la suite completa contra un contenedor de servicio
MariaDB 12** (job `mariadb`) junto a los jobs sqlite por defecto — la
deriva de esquema y las regresiones específicas del motor rompen el
build. La guarda de deriva (`ScriptSuiteTest`) cubre ahora un contrato
de módulos de dos niveles: `PHP_REQUIRED_MODULES` (núcleo, siempre
requerido) y `PHP_RUNTIME_EXTRA_MODULES` (`pdo_mysql` — requerido en
tiempo de ejecución solo cuando `DB_CONNECTION=mariadb`, nunca parte
del filtrado de intérpretes, así que la ruta del toolchain hermético no
se afecta).

## 5. Zona horaria

Sin cambios por esta migración (ADR-025): la aplicación es de un solo
huso horario en hora de pared (`America/Bogota`); las columnas DATETIME
de MariaDB guardan las mismas marcas locales ingenuas que guardaba
SQLite. Las cadenas ISO `-05:00` de la API vienen de la capa de la
aplicación, no del motor.

## 6. Adiciones de esquema — credenciales HCE (teléfono como credencial)

- **`cards.kind`** (`physical` | `hce`, por defecto `physical`): CÓMO se
  capturó la credencial — un UID MIFARE de la capa RF vs un id de
  credencial HCE de Android a nivel de aplicación del intercambio SELECT
  AID (`F0010203040506`) + CHALLENGE. Solo metadato de
  visualización/auditoría: la búsqueda del tap sigue siendo solo por
  `credential_uid` (el UID RF del teléfono lo aleatoriza Android en cada
  toque y jamás se guarda), las filas existentes quedan como `physical`
  y emparejar usa `physical` cuando el lector omite `credential_kind`.
  Segura y reversible (down borra la columna). El escritorio de
  emparejamiento marca las filas `hce` (“Phone” / “Teléfono”).

## 7. Adiciones de esquema — TASK-037 (programa PAE completo)

- **`students`**: la bandera única `pae_enrolled` se convirtió en dos
  columnas independientes — `pae_breakfast_enrolled` y
  `pae_lunch_enrolled` (ambas booleanas, por defecto falso). Un
  estudiante puede inscribirse solo en desayuno, solo en almuerzo, en
  ambos o en ninguno. La migración copia la bandera antigua en ambas
  (un superconjunto — ningún estudiante inscrito pierde una comida)
  antes de eliminar la columna; una resiembra fresca es igualmente
  válida según la especificación.
- **`events`**: dos columnas que hacen explícita la distinción
  servida-vs-marcada sobre la columna de tipos (ADR-053): `served`
  (booleana, por defecto verdadero — solo las filas servidas cuentan
  para las estadísticas PAE) y `reason` (cadena nulleable — el motivo
  de rechazo estable para filas marcadas: `weekend` / `out_of_window` /
  `window_overlap` / `no_student` / `not_enrolled` / `no_attendance` /
  `duplicate`).
- **`settings`** (tabla nueva, ADR-55): `key` (única) + `value` (json) +
  timestamps — las anulaciones en tiempo de ejecución respaldadas por BD
  que lee `SettingsService` (ventanas de comidas, corte de llegada
  tarde, ventana de emparejamiento, convenciones de cuentas). Las
  lecturas resuelven fila → valor por defecto de config; los valores
  por defecto siguen siendo configurables por entorno
  (`PAE_BREAKFAST_START` etc. en `.env.example`).

## 8. Adiciones de esquema — TASK-045 (colegios / organizaciones)

> ADR-064. Aditivo y reversible; sin reescritura de datos.

**`schools`** — la organización a la que pertenece toda fila con dueño,
y el ancla de la cadena de marca (`docs/BRAND.es.md` §3b).

| Columna | Notas |
|---|---|
| `name` | el nombre propio de la institución, único (p. ej. `IE Concejo de Sabaneta J.M.C.B`) |
| `slug` | identificador estable de máquina, único |
| `brand_key` | con qué perfil de `config/branding.php` se renderiza; `NULL`/desconocido ⇒ Pulse estándar |

**`school_id`** (FK nulable, `nullOnDelete`) se agregó solo a las tablas
RAÍZ con dueño organizacional:

```
users · classes · students · readers · rewards · events
roster_updates · recycling_updates
```

Todo lo demás desciende de alguna de ellas y se acota **a través de su
padre**, así la pertenencia se guarda una sola vez y no puede
contradecirse:

| Tabla hija | Dueño resuelto vía |
|---|---|
| `cards`, `points_ledger`, `reward_redemptions`, `pending_pairings` | `students.school_id` |
| `recycling_deposits` | `events.school_id` |
| `pending_captures` | `readers.school_id` |

`events` es la única desnormalización deliberada: asistencia, PAE y
reciclaje son vistas derivadas que se agregan directamente sobre esa
tabla, así que un join por agregado se pagaría en cada render. Se
estampa desde su **lector** (la identidad propia del dispositivo — nunca
un valor enviado por el cliente), y una prueba fija que todo evento
coincide con el colegio de su lector.

Índices agregados: `events (school_id, type, occurred_at)` y
`students (school_id, class_id)` — las dos lecturas calientes de todo
panel.

### `NULL` es un valor admitido, no una migración a medias

Toda columna es nulable y toda fila preexistente sigue funcionando:

- **Admin + `school_id IS NULL` = el administrador del sistema.** No
  pertenece a ninguna organización y por eso opera sobre todas. Es la
  única capacidad interorganizacional deliberada, otorgada por la propia
  fila de la cuenta — nunca por un parámetro de la petición.
- **Cualquier otro rol con `school_id IS NULL`** queda restringido al
  conjunto sin asignar (`NULL`). Nunca se abre a toda la base de datos.

`./run reset` (DemoSeeder) y `./run reset --pilot` deliberadamente no
siembran ningún colegio — son el fixture de Pulse estándar. `./run
seed-realistic` siembra un colegio (`IE Concejo de Sabaneta J.M.C.B`)
dueño de cada fila que escribe, más exactamente un administrador del
sistema **fuera** de él (`SystemAdminSeeder`, ejecutado aparte por el
script).
