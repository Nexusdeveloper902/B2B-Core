# Guía de Frontend y Registro de Brechas de Maquetas (TASK-026)

> **Qué es esto.** El 2026-09-07 el dueño entregó ocho páginas HTML de
> maqueta ("más o menos así") y una regla: **mantener la funcionalidad
> intacta y documentar las partes que necesitan funcionalidad que aún
> no existe.** Todas las vistas Blade, el layout y el sistema CSS se
> reconstruyeron para seguir las maquetas; ni una ruta, contrato ni
> comportamiento del backend cambió (la suite completa lo demuestra).
> Este documento es la referencia de diseño Y el registro honesto de
> brechas (gap ledger): qué se construyó, qué NO se construyó a propósito y qué
> necesitaría cada pieza.

Nota bilingüe: este archivo está en español; `FRONTEND.md` es la versión
en inglés. Las cadenas de UI viven en `lang/{en,es}/app.php`.

---

## 1. El sistema de diseño — "Datum"

| Capa | Decisión |
|---|---|
| Paleta | Set tonal Material-3 sobre base salvia clara, literal de las maquetas: `surface #f6fbed`, contenedores `#ffffff → #dfe4d7`, `primary #0e0f0e` (casi negro), **dorado `tertiary-fixed #ffdf93`** (acentos de puntos/eco), `error #ba1a1a`. Set completo en `public/css/tokens.css`. |
| Tipografía | Epilogue (display/títulos) · Manrope (cuerpo) · Space Grotesk (etiquetas/chips de datos) · IBM Plex Mono (mono — equivalente de valor para el JetBrains Mono de las maquetas). Todo **auto-hospedado** (`public/fonts/`, latin + latin-ext; cero llamadas a Google en runtime). |
| Escala tipográfica | Valores literales de la maqueta: display 56/64 · headline-lg 40/48 · headline-md 28/36 · headline-sm 22/30 · body-lg 18/28 · body-md 15/24 · body-sm 13/20 · label-lg 14/20 · label-md 12/16 · label-sm 10/14. |
| Geometría | Esquinas "arquitectónicas": 2px en chips/botones, 8px en tarjetas, píldora para puntos/avatares. Topbar fija de 64px con blur, shell de 1360px. |
| Iconos | Material Symbols Outlined, auto-hospedado y **subsetting + instanciado de ejes** (3,97 MB → 283 KB) a los 27 iconos usados; por ligaduras (`<span class="material-symbols-outlined">eco</span>`). |
| Movimiento | Sin cambios respecto a "Signal": reveals de `motion.js` con compuerta `.js-motion`, estados en CSS, respeto total de `prefers-reduced-motion`. |

Inventario de componentes (todo en `public/css/app.css`): topbar/nav
píldora, footer + chip de ops, paneles (`.panel`, variante con regla
superior negra), métricas bento (`.metric`, `.metric-hero` negro+dorado),
franja KPI (`.stat-*`), píldoras de filtro + búsqueda (`.filterbar`,
`.pill`, `.searchbox`), tablas libro (`.ledger-table[data-stack]` →
tarjetas etiquetadas bajo 620px), chips de eventos (mapa de tonos en CSS
vía `[data-event-type]`), estampas (presente=dorado / tarde=marrón
oscuro / ausente=error), insignias de puntos, tarjetas de recompensa con
medidores de progreso, podio + filas del tablero, arte de pulso NFC,
barra de cuenta regresiva, tarjeta de login, chips demo, estados vacíos.

## 2. Mapa de páginas (maqueta → ruta)

| Maqueta | Ruta | Vista |
|---|---|---|
| Sign In | `/login` | `auth/login` |
| Parent View — Timeline | `/parent/students/{id}` | `parent/timeline` |
| Teacher Dashboard | `/teacher` (+`/dashboard`) | `teacher/dashboard` |
| Admin Dashboard | `/admin` | `admin/dashboard` |
| Pair Cards — Pairing Desk | `/admin/pairing` | `admin/pairing` |
| EcoStation & Recycling Hub | `/admin/ecostation` **(nueva)** | `admin/ecostation` |
| Rewards & Perks Store | `/student/rewards` | `student/rewards` |
| Leaderboard & Class Standings | `/student/leaderboard` **(nueva)** | `student/leaderboard` |
| Estudiantes — escritorio de inscripción | `/admin/students` **(nueva, TASK-027)** | `admin/students` |
| Lectores — escritorio de gestión | `/admin/readers` **(nueva, TASK-027)** | `admin/readers` |
| Define una nueva contraseña | `/password/change` **(nueva, TASK-030-A)** | `auth/password-change` |

Las dos páginas de TASK-026 y los dos escritorios de TASK-027 son vistas
sobre datos que ya existían más nuevos endpoints admin de escritura
(ver §4). El nav sigue con alcance por rol exactamente igual — los
estudiantes ven el hub estudiantil, el personal ve sus páginas.
TASK-028 cierra un agujero de descubribilidad que el dueño encontró de
frente: los dos escritorios de TASK-027 se lanzaron alcanzables SOLO
tecleando sus URLs — el nav admin superior (escritorio y el menú móvil
sin JS) ahora enlaza ambos, fijado por un test de regresión.

## 3. Qué se añadió deliberadamente (datos reales, sin backend nuevo)

- **Filtros + búsqueda en cliente** (píldoras de eventos del timeline
  de padres, búsqueda de roster del escritorio de emparejamiento,
  búsqueda de ledger del profesor): pura presentación sobre filas
  renderizadas por el servidor — los propios scripts de
  micro-interacción de las maquetas, honestos por construcción.
- **Página de clasificación estudiantil**: datos de
  `LeaderboardService` (la misma fuente que la API y el escritorio) +
  posiciones por curso derivadas agrupando la columna `class_name`.
- **Hub EcoStation**: libro de `recycling_deposits` (joins por la
  espina de eventos), tabla de tarifas desde `config/recycling.php`,
  lectores de reciclaje, totales de impacto histórico.
- **Medidores de progreso en recompensas bloqueadas**: matemática real
  (saldo ÷ costo).


## 3b. TASK-029 — el pase de tiempo real + completación de GUI

El veredicto del dueño tras vivir en la GUI: "todo lo que pueda cambiar
necesita websockets, esto tiene que ser en tiempo real" — más la
creación de clases y el fastidio de teclear el ° del grado. Cada página
que muestra estado mutable ahora arranca el cliente de tiempo real y se
actualiza en vivo:

| Página | Canal(es) en vivo | Qué se mueve sin recargar |
|---|---|---|
| Panel admin | tap + reciclaje | franja KPI (Sets de estudiantes distintos para asistencia/PAE), totales de reciclaje, feed en vivo (la tabla de lectores se mudó al escritorio /admin/readers — TASK-035) |
| Panel del profesor | tap | filas de asistencia, chips de resumen por clase, franja KPI (todo con alcance por rol en el servidor, como siempre) |
| Escritorio de estudiantes | roster (marcos admin + replay del hello) | los estudiantes creados/importados se anteponen de forma idempotente; las clases creadas se unen al select |
| Escritorio de lectores | roster | etiqueta/modo se repintan (nunca pisa el input que estás tecleando) |
| Escritorio de emparejamiento | emparejamiento + tap | ventana armada, estado, historial, celdas de tarjeta del roster (sin cambios desde TASK-020/023/027) |
| EcoStation | reciclaje | ledger, métricas, última captura (TASK-027, sin cambios) |
| Timeline de padres | tap | los eventos del estudiante visto se anteponen; las pastillas/búsqueda siguen siendo dueñas de la visibilidad |
| Panel del estudiante | reciclaje | saldo (bug latente corregido: el listener leía `frame.payload`; el servidor envía `frame.update.payload`) |
| Historial del estudiante | reciclaje | las filas del ledger de puntos se anteponen con el saldo corriente real; el saldo del encabezado sigue |
| Tablero del estudiante | reciclaje | tablero + podio se reordenan (puntos DESC, student_id ASC — la regla del servidor), los rangos se renumeran, mi rango/saldo siguen |

El canal roster (TASK-029) es un cuarto canal WS: una tabla
`roster_updates` de solo añadir, escrita dentro de la misma transacción
del cambio que describe (`student_created`, `students_imported`,
`class_created`, `reader_updated`), sondeada por `realtime:serve`,
entregada solo a conexiones admin (la misma disciplina de exposición
del canal de emparejamiento), con el snapshot reciente viajando en el
hello del admin (replay idempotente).

Completación de GUI: el grado es un SELECT (`1°`–`11°`, sin grado 0 —
se acabó teclear el signo de grado), el seeder trae un par de clases
A/B por grado (`1° A`…`11° B`), el selector de grado filtra el de
clases al A/B de ese grado con el primer match auto-seleccionado
(TASK-034 — tags `data-grade`; los nombres custom muestran todas),
la búsqueda del roster filtra en vivo al teclear (fetch-swap con
debounce de tbody + paginación, misma URL, sin endpoint nuevo; el
form GET queda como fallback sin-JS), la creación de clases vive en el escritorio
de estudiantes (`POST /api/v1/admin/classes`, profesor titular
opcional), el input de archivo del importador CSV recibió la superficie
del sistema de diseño, y el paginador ahora renderiza en Datum (una
sobrescritura vendor de `pagination::tailwind` — el marcado Tailwind de
fábrica nunca casó con este CSS; el escritorio de estudiantes y el
historial quedaban sin estilo). Los estilos inline dispersos de
`style="text-align:right"` se volvieron una sola regla `.ta-right`.

Límites honestos (documentados, no fingidos): el panel de posiciones
por clase del tablero sigue siendo un snapshot (los marcos llevan
puntos por estudiante, no agregados por clase); el estado vacío del
timeline no cultiva una tabla en vivo desde cero (una recarga lo
renderiza); el catálogo de recompensas del estudiante es estático por
naturaleza.

## 3c. TASK-030-A — accesos de estudiantes: la columna de acceso + la página de rotación

La inscripción crea el acceso (ADR-044), y el escritorio lo demuestra:
la tabla de roster creció con una columna de Acceso que renderiza el
email aprovisionado por fila, o un "Crear acceso" en un clic para filas
anteriores a TASK-030 (`POST /api/v1/admin/students/{student}/account`,
idempotente). Las llegadas en vivo (respuesta fetch o frame de roster)
pintan la misma celda con un solo renderer, así que una fila creada en
la pantalla de otro admin igual muestra su acceso aquí. La caja de
resultado de creación lleva las credenciales de mostrar-una-sola-vez
(`account_notice`) — el único lugar donde la contraseña temporal
aparece jamás.

`/password/change` (`auth/password-change`, la misma gramática de
tarjeta-auth que el login) es donde caen las cuentas marcadas: todas
las demás páginas rebotan allí hasta rotar a una contraseña personal
(verificación de la actual, mínimo 8, confirmada). El logout, el cambio
de idioma y el propio formulario siguen alcanzables; los clientes JSON
reciben un 403 bilingüe (`password_change_required`) en vez de la
redirección.

## 3d. TASK-030-B — los lectores nacen aquí (escritorio de aprovisionamiento)

El escritorio de lectores creció con un panel de creación (nombre +
tipo + modo inicial): `POST /api/v1/admin/readers` crea la fila con
una clave generada por el servidor y el escritorio muestra la clave
EXACTAMENTE UNA VEZ en la caja de resultado (la regla de
mostrar-una-sola-vez de los accesos, ADR-045) mientras antepone la fila
editable completa — fetch primero, el replay del frame
`reader_created` es no-op. Cada fila lleva además un botón Rotar clave
tras el modal de confirmación compartido de Datum (TASK-034 —
`x-confirm-modal`: tarjeta de panel, mensaje con gramática de alerta,
Cancelar quieto + Confirmar danger, Esc/fondo cancelan, el foco
vuelve; Desvincular del escritorio de pairing usa el mismo modal, y
ninguna vista trae `window.confirm` — pineado por test). La rotación
deja inservible el lector instalado hasta regrabarlo; la clave fresca
se renderiza una vez en la misma caja. El panel del roster toma split
even de grilla (la tabla de 4 columnas sacaba sus botones de acción
fuera del panel angosto), las celdas de acción son fila flex con gap,
y el input de nombre flexiona con su columna. La tabla siempre renderiza (una
fila vacía, nunca sin tabla) para que las llegadas en vivo antepongan
desde cero, y los clics de guardar/rotar son delegados para que las
filas en vivo se comporten como las del servidor. Ninguna clave vuelve
al HTML jamás: el escritorio queda limpio por test.

Los lectores viven solo en su escritorio (TASK-035 quitó la tabla de
lectores del panel — los cambios de modo se hacen en /admin/readers,
cuyas filas repintan Y anteponen en vivo).

## 3e. TASK-030 (Fix 3) — presencia, unidades y una demo vivida

- **Presencia del proyecto**: el pie del shell compartido enlaza el
  Instagram del proyecto (`@puls.e1681`, pestaña nueva + `noopener`)
  en ambos idiomas. Es el ÚNICO canal de contacto suministrado — no se
  inventan emails, teléfonos ni direcciones en ningún lado (la brecha
  P5 sigue abierta para una superficie real de contacto).
- **Sin unidades fijas**: la unidad de puntos de EcoStation renderiza
  vía `app.points_unit` (filas del servidor, tarjeta de tasas y rutas
  JS en vivo por igual), y el mapa de etiquetas del hub es todo
  `Js::from` (los últimos literales `{{ }}`-en-JS se fueron — las
  comillas de los traductores ya no pueden romper el script). Los
  fallbacks en inglés de `realtime.js` (`just now`, estados del badge)
  se auditaron: solo renderizan en páginas CON lista/badge en vivo, y
  cada uno de esos boots lleva el mapa completo de strings
  localizados — ningún fallback puede aflorar en ES.
- **Dataset piloto**: `PilotSeeder` (solo BDs frescas — se niega ante
  BDs no vacías en vez de duplicar) construye tres cursos, 24
  estudiantes con accesos y tarjetas, cinco lectores y diez días de
  clase deterministas de toques (~1k eventos, ~70 depósitos, 2 canjes).
  `./run reset --pilot` es la demo humana en un comando; el pequeño
  fixture `DemoSeeder` sigue siendo el valor por defecto de las
  pruebas automatizadas.

## 4. Registro de brechas (gap ledger) — necesita funcionalidad que AÚN NO existe

Todo lo de abajo fue **omitido o reemplazado con honestidad** (sin datos
falsos ni botones muertos — el piso de honestidad de TASK-014).
"Necesita" = la superficie mínima de backend antes de que el elemento
de maqueta pueda publicarse con verdad.

### Mobiliario global

| # | Elemento de maqueta | Por qué no está | Necesita |
|---|---|---|---|
| G1 | Identidad del colegio ("Northfield Academy", escudos) | No existe config de identidad ni assets de logo | `config('presence.school_name')` + un logo; el shell mantiene la marca PresencePlatform |
| G2 | Chip "All Systems Operational" del footer | No hay sonda global de salud; afirmarlo sería teatro | Un chequeo agregado (BD + WS + cola) — el footer muestra el chip real de entorno |
| G3 | Franjas de telemetría (NODE · LATENCY · EPOCH) | No existe pipeline de métricas | Un endpoint de telemetría por render |
| G4 | Botones "Force Telemetry Poll" / "Global Thresholds" | No existen tales endpoints | Endpoints admin nuevos (fuera de alcance por regla) |
| G5 | Mapa de geocerca con pines de lectores (LAT/LON) | Los lectores no llevan coordenadas | Columnas `readers.lat/lng` + un proveedor de mapas (offline preferible — la app es LAN-first) |
| G6 | Pies "criptográficamente sellado / hash signature" | No existe cadena de hash sobre eventos | Columna de hash + endpoint de verificación |
| G7 | Campana de notificaciones | No hay sistema de notificaciones | Tabla de notificaciones + tipo de frame WS |

### Timeline de padres

| # | Elemento | Por qué no está | Necesita |
|---|---|---|---|
| P1 | "Live Sync Anchor" con hora viva | Los frames WS son solo de personal (rol resuelto, fail-closed) | Tokens realtime por estudiante — decisión de privacidad; se reemplazó con el sello honesto "registro generado" |
| P2 | Foto del estudiante + insignia VERIFIED flotante | No hay almacenamiento de fotos | Subida de foto + consentimiento; monograma + id real en su lugar |
| P3 | Etiqueta `PROTOCOL #PL-...` | No hay modelo de protocolos | — (se usa el id real) |
| P4 | Progreso "Next Milestone: 500 PTS · 70%" | No hay modelo de niveles/metas | Config de niveles + progreso por ledger (buena feature futura; la tarjeta negra ya muestra el saldo real) |
| P5 | Tarjeta de contacto del asesor + botón "Request Attendance Audit" | No hay datos de contacto del personal ni flujo de auditoría | Campos de perfil + tabla/endpoint de solicitudes |
| P6 | Píldora de filtro "Store & Milestones" | Los canjes son filas de ledger, no eventos de presencia — el filtro quedaría vacío | Fusionar `reward_redemptions` + metas en el feed |
| P7 | Narrativa por fila ("check-in a tiempo", "2 PET verificadas") | No hay texto narrativo por evento | Copys fijos por tipo (posible) o campo de notas |

### Tienda de recompensas

| # | Elemento | Por qué no está | Necesita |
|---|---|---|---|
| R1 | Botones "Redeem Voucher", modal QR/código de barras, códigos de pase, vencimiento 14 días, "Add to Wallet", bóveda de vales | El canje es **deliberadamente solo-en-escritorio** (spec §30: verificado por personal) — un botón de canje estudiantil sería decisión de producto | Endpoint de canje estudiantil + modelo de vales/códigos; la tarjeta muestra stock/asequibilidad reales y la nota honesta "Canjea en el escritorio" |
| R2 | Píldoras de categorías + búsqueda + orden | `rewards.type` es texto libre, no una taxonomía | Un enum de categorías + seeding; el tipo real se muestra como REF |
| R3 | Cobertura de ubicación / horarios / disponibilidad | No hay campos de metadata de recompensas | Columnas (ubicación, horarios) |
| R4 | Horarios de tienda | No hay config | — |

### Clasificación

| # | Elemento | Por qué no está | Necesita |
|---|---|---|---|
| L1 | Tabs de temporadas, "Historic Seasons", héroe del reto de curso | No hay modelo de temporadas/campeones/retos | Tablas de temporadas + campañas; las posiciones por curso SÍ están implementadas |
| L2 | Tabs por grado | El tablero lleva `class_name` pero no grado | Exponer `grade` en LeaderboardService |
| L3 | Etiquetas "Your Section · Current Lead" | El margen de liderazgo no existe como dato | Derivable; se mantuvo simple en v1 |

### Dashboard del profesor

| # | Elemento | Por qué no está | Necesita |
|---|---|---|---|
| T1 | Widget de métricas ambientales | No hay sensores | — |
| T2 | Tarjeta de briefing del turno | No hay modelo de personal/turnos | — |
| T3 | Tabs de cohorte por curso | Todos los cursos renderizan como paneles (con búsqueda) | Una ruta de curso único + tabs; mismos datos |

### Escritorio de emparejamiento

| # | Elemento | Estado |
|---|---|---|
| D1 | Acciones por fila "reassignment / replace card" | **CERRADO (TASK-027)** — cada credencial emparejada del roster es un chip con su propio botón **Desvincular**, respaldado por `DELETE /api/v1/admin/cards/{card}` (semánticas del comando masivo `cards:unpair`; el diálogo de confirmación avisa que el historial de toques se borra) |
| D2 | Matriz de referencia de estados | Los estados reales ya renderizan en vivo | — |
| D3 | Telemetría del terminal + estado de firmware | No hay reporte de firmware | Endpoint de estado de dispositivo + frame WS |
| D4 | Pie cripto del ledger | Ver G6 | — |

### EcoStation

| # | Elemento | Estado |
|---|---|---|
| E1 | Despliegue de imágenes de captura | **CERRADO (TASK-027)** — la imagen REAL se transmite por la ruta admin `GET /api/v1/admin/captures/{deposit}/image` (el disco privado sigue privado); un depósito sin imagen mantiene la nota honesta de almacenamiento privado |
| E2 | Telemetría / snippet de firmware / caja de cumplimiento | No hay reporte de hardware | Ver D3 |
| E3 | Modal de simulación de terminal | Herramienta de demo, no feature | — |
| E4 | "Directivas" de material por lector | Las tarifas son config global hoy (renderizadas con verdad) | Overrides por lector (cambio de schema) |
| E5 | Actualizaciones en vivo (sin recargar) | **CERRADO (TASK-027)** — el hub arranca `[data-realtime]` (badge en vivo + CustomEvents `realtime:recycling`): las filas del libro se anteponen, las métricas de impacto suben, el panel de última captura se refresca |

### Login

| # | Elemento | Por qué no está | Necesita |
|---|---|---|---|
| S1 | Enlace "FORGOT KEY?" | No hay flujo de restablecimiento | Tokens de reset |
| S2 | Banner SSO, insignias TLS/FERPA/ISO, build | Afirmaciones de marketing sin respaldo | Artefactos reales de cumplimiento |
| S3 | Naming "Northfield Unified Portal" | Ver G1 | — |

### Nav del hub estudiantil

| # | Elemento | Por qué no está | Necesita |
|---|---|---|---|
| N1 | Enlace EcoStation para estudiantes | EcoStation es solo-admin (operación de lectores) | Variante estudiantil con alcance (decisión de producto) |
| N2 | Página "Attendance & Pass" | Los datos de asistencia son de personal | Una vista propia (decisión de privacidad) |

## 5. Notas de auto-hospedaje y assets

- Las fuentes se descargaron UNA VEZ de Google Fonts y se
  vendorizaron (latin + latin-ext; los acentos de ES están cubiertos).
  Cero requests externos en runtime — la regla LAN-first se mantiene.
- La fuente Material Symbols tiene subsetting y ejes instanciados a la
  instancia exacta del CSS (`FILL 0, wght 400, GRAD 0, opsz 24`):
  3,97 MB → 283 KB. **Añadir un icono nuevo**: agrega el span de
  ligadura en una vista y re-corre la receta de
  `scripts/subset_icons.py` (pyftsubset con `--text` y
  `--layout-features=*` conserva las ligaduras rlig; luego instancer
  fija los ejes). Si un icono se renderiza como sus letras, la fuente
  necesita re-subsetting.
- El CSS de iconos (`fonts.css`) usa `font-display: block` para que el
  texto de ligadura nunca parpadee como palabras sueltas.

## 6. Contrato de regresión (lo que fijan los tests)

- `DashboardTest`: tokens Datum (hexes de paleta + escala tipográfica),
  base clara / selección dorada / foco negro, mapa de tonos de estampas
  y chips sobre roles Datum, gramática del KPI héroe, spans de la fuente
  de iconos, tablas responsivas data-stack, compuerta de motion,
  affordances de login, bootstrap de realtime en JSON.
- `AdminPairingDeskTest`: gramática de franja full-bleed (re-pinneada a
  tokens Datum), actualizaciones vivas de `data-card-cell`, todos los
  ids de script.
- `MockupPagesTest` (nuevo): guardas de rol + contenido de verdad por
  ledger para las páginas de clasificación y EcoStation.
- E2E (`./run e2e`): 33 chequeos sobre HTTP incluyendo el login con CSRF
  y la historia de reciclaje de TASK-025.
