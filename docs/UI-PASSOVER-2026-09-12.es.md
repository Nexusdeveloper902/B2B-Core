# Repaso de UI — Controles y espaciado (2026-09-12)

> **Qué es esto.** Un repaso de pulido de la UI completa pedido por
> el dueño: espaciado inconsistente en muchos lugares, y cajas de
> texto + selectores desplegables sin el estilo de las alertas y
> ventanas. Las alertas conservan su estilo — los controles se
> trajeron hacia ellas. Solo refinamiento: el sistema de diseño
> Datum (TASK-026/ADR-036), cada layout, cada contrato backend y
> cada hook JS quedan intactos.

Nota bilingüe: este archivo es español; `UI-PASSOVER-2026-09-12.md`
es la versión en inglés.

---

## 1. Los controles ahora comparten la superficie de las alertas (alertas intactas)

Cada caja de texto, desplegable, búsqueda e input de archivo usa el
lenguaje de superficie de `.nl-answer` / `.notice` — relleno de
superficie mínima, borde 1px `--border`, radio 2px, tipografía body —
vía un selector compartido en `public/css/app.css`:

- Los inputs `.field` (login, rotación de contraseña), los controles
  densos `.bare-*` de los escritorios, los inputs `.searchbox`
  (antes con relleno teñido + borde transparente) y el input de
  archivo CSV (antes con su propia copia de la superficie, con un
  `max-width` duplicado) usan las mismas reglas de fondo / borde /
  radio / hover / focus.
- Los tamaños siguen siendo por rol a propósito: controles `.field`
  de 44px vs controles densos de 38px (la misma idea que `.btn` vs
  `.btn-small`). Solo se unificó la superficie, no la escala.
- Los desplegables llevan su propio chevron CSS (`appearance: none`
  + fondo SVG en línea, `padding-right: var(--sp-xl)`): las flechas
  nativas difieren por navegador y eran la mitad visible de la
  queja. Las filas mixtas input/select mantienen gutters alineados.
- Detalles compartidos que los controles nunca tuvieron:
  `::placeholder` en `--text-meta`, atenuado `:disabled`, borde de
  error `[aria-invalid]` también en los escritorios, y `textarea`
  entra en la regla de caja (la regla vieja estilaba su focus pero
  nunca su caja).

Los tonos, paddings y bordes de `.nl-answer` / `.notice` /
`.field-error` son byte-idénticos a antes — el estilo de referencia
no se movió.

## 2. Inconsistencias de espaciado corregidas

| # | Inconsistencia | Corrección |
|---|---|---|
| S1 | 13 atributos `style="…"` de layout repartidos en 7 vistas (overrides de filterbar, alineación de iconos, `min-width: 0`, mayúsculas, insets de table-note, meta de captura) | Una regla cada uno en `app.css` (`.filterbar--flush`, `.auth-band-icon`, `.min-w-0`, `.t-uppercase`, `.table-note--inset`, `.capture-meta`, `.metric-value--inline`); las vistas solo referencian clases. El `width: …%` del medidor de meta queda en línea — ese ancho ES el dato. |
| S2 | El `<hr class="rule">` del escritorio de estudiantes y la etiqueta `.check-line` del PAE NO tenían estilo (regla inset por defecto del navegador, checkbox sin estilo) | Nuevas reglas `.rule` (1px `--border`, ritmo `sp-md`) y `.check-line` (inline-flex, caja 18px `accent-color: primary`). |
| S3 | La nota idle del escritorio de pairing (`<p class="live-empty">`) no matcheaba ningún selector — solo existía `.live-row.live-empty` (la fila del feed), así que caía a márgenes `<p>` por defecto | Regla `.live-empty` standalone con la gramática de texto del feed (mono, meta, centrada, padding `sp-md`). |
| S4 | Las cajas de resultado bajo tablas ledger (`#reader-result`, resultado de modo en admin) tocaban la tabla — las respuestas tras formulario reciben `sp-sm` del propio margen inferior de `.tool-form`, las tras tabla no recibían nada | `.ledger-wrap + .nl-answer { margin-top: var(--sp-sm) }` — mismo gap en ambos casos. La regla pinned `.live-panel .nl-answer` full-bleed sigue ganando dentro de paneles live (posterior en el archivo, intacta). |
| S5 | `.reward-grid` usaba gaps `sp-lg` mientras cada otra grilla de sección (`.bento`, `.grid-2`, `.stack`) usa `sp-md` | Cambio de un token a `sp-md`. |
| S6 | `.notices` referenciaba `var(--sm)` indefinido (caía en silencio al fallback) | Ahora `var(--sp-sm)` directo. |

## 2b. Fix de seguimiento — el repaso era invisible tras la caché del navegador

Reporte del dueño tras la primera entrega: "se ve igual".
Verificado en vivo (estilos computados en un navegador real): el
CSS nuevo estaba correcto en disco — el navegador servía el archivo
VIEJO. Causa raíz: el layout servía `css/app.css` sin string de
versión, así que los refreshes normales nunca revalidan (caché
heurística sin revalidación al navegar por links).

Fix (un archivo, `layouts/app.blade.php`): cada URL de asset del
head lleva `?v=<filemtime>` (`fonts.css`, `tokens.css`, `app.css`,
`motion.js`) — cada archivo invalida caché solo cuando ÉL cambia,
así este y cada futuro repaso de estilos aparecen al primer load.
Pineado en el mismo DashboardTest (`css/app.css?v=\d+` y demás
sobre HTML renderizado).

Prueba renderizada (serve local + navegador, sesión admin): URLs
versionadas servidas (`app.css?v=1789176949`); el select computa a
`appearance: none` + chevron SVG, relleno blanco, 1px `#c6c7c1`,
radio 2px; input / caja de respuesta / búsqueda computan a la
superficie idéntica; el screenshot del escritorio de estudiantes
muestra selects con chevron, búsqueda con borde, checkbox con
estilo y separadores `hr`.

Nota honesta: la página de sign-in es la que menos cambia — sus
controles `.field` ya matcheaban la superficie de alertas, así que
ahí solo se movieron detalles (placeholder/disabled/inválido). El
cambio visible vive en los escritorios (búsqueda teñida→blanca+borde,
chevrons, checkbox, separadores, gap tabla→respuesta).

## 3. Deliberadamente NO cambiado

- **Diálogos nativos `confirm()`** (desvincular tarjeta, rotar llave
  de lector): son chrome del navegador y no pueden vestir Datum;
  reemplazarlos por un modal propio es decisión de producto/a11y
  (trampa de foco, teclado), no un fix de pulido. Queda como
  seguimiento.
- **Visuales de alertas/ventanas**: congelados por pedido — los
  controles se movieron hacia ellos, nunca al revés.
- **Alturas**: 44px vs 38px son escala de tamaños, no mismatch
  (documentado en §1).

## 4. Verificación

| Puerta | Resultado |
|---|---|
| `./run test` (unit + feature) | **443 pasados**, 3 skips preexistentes, 0 fallidos (8 249 aserciones) |
| `./run quality` (Pint + shell + paridad docs) | **pasado** |
| Pin nuevo | `DashboardTest::text_boxes_and_dropdowns_share_the_alert_surface_language` (regex de superficie compartida, chevron, reglas check-line/rule/live-empty/gap tabla, tonos de respuesta intactos, checks de clases en HTML de escritorios) |
| Pins preexistentes | La gramática full-bleed `.live-panel .nl-answer` sigue asertada y pasando (AdminPairingDeskTest) |

## 5. Archivos tocados

- `public/css/app.css` — superficie unificada de controles, chevron
  de selects, placeholder/disabled/inválido, `.check-line`, `.rule`,
  `.live-empty`, utilidades extraídas de vistas, gap tabla→respuesta,
  gap de reward-grid, fix del fallback de notices
- `resources/views/admin/{pairing,ecostation}.blade.php`,
  `teacher/dashboard.blade.php`, `auth/{login,password-change}.blade.php`,
  `parent/timeline.blade.php`, `student/rewards.blade.php` —
  estilos en línea reemplazados por las clases nuevas (13 atributos
  eliminados; solo el ancho data-driven del medidor conserva `style=`)
- `tests/Feature/Web/DashboardTest.php` — un pin de regresión
- `.agent/TASKS/TASK-032-ui-controls-and-spacing-passover.md`,
  `.agent/RUNS/RUN-2026-09-12-core-032.md` — registros del agente
