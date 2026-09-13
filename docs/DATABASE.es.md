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
