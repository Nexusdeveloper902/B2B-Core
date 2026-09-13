# Pulse — Marca e identidad de producto

> **Lee esto en:** [English](BRAND.md)
>
> Establecido por la pasada de productización (2026-09-12). El producto
> es **Pulse** — en todos los lugares donde una persona, un dispositivo
> o un buscador pueden ver un nombre. Este documento es el contrato de
> identidad: cuáles son los assets de marca, dónde viven y las reglas
> para usarlos.

## 1. Nombre

El nombre del producto es **Pulse** (en EN y en ES — el nombre no se
traduce). Los términos de dominio NO son marca: un *evento de
presencia*, la tabla `presence_events`, `config/presence.php` y el
namespace interno `Presence` del firmware describen el modelo de
eventos y se quedan como están.

- Títulos del navegador: `<página> — Pulse` (plantilla `<title>` del
  armazón).
- `config/app.php` por defecto: `Pulse` (Core) / `Pulse Marketplace`
  (escaparate); `.env.example` coincide.
- Las cuentas demo siguen siendo `@presence.test` — un dominio de
  correo (infraestructura de datos semilla), deliberadamente sin
  renombrar en esta pasada.

## 2. Assets del logo (`public/brand/`)

Todos los assets se derivan de la **suite de marca real**
(`B2B-Logo-Suite/` en el workspace — la P con el anillo dorado y el
eslogan "Tecnología para problemas reales"). No se usa ninguna marca
genérica ni inventada.

| Asset | Origen | Uso |
|---|---|---|
| `mark.png` | Mark.png (crema eliminado a transparente) | marca maestra transparente, tinta + oro |
| `mark-96.png` | ↳ miniatura | barra superior / pie / banda de login (`width="42" height="30"`) |
| `mark-cream-96.png` | ↳ remapeo alfa a crema | la marca sobre superficies oscuras (pie del escaparate) |
| `favicon-16/32/48.png`, `favicon.ico` | marca sobre baldosa `#E8EDDF` | pestañas del navegador |
| `apple-touch-icon.png` (180) | marca sobre baldosa `#E8EDDF` | pantalla de inicio iOS |
| `icon-192/512.png`, `icon-maskable-*.png` | marca sobre baldosa `#E8EDDF` (maskable: contenido en la zona segura del 80 %) | manifiesto PWA |
| `lockup-cream-480.png` | Horizontal.png (recorte ajustado) | contextos que necesitan el logotipo completo |
| `og-image.png` (1200×630) | logotipo sobre crema de marca | vistas previas sociales (`og:image`) |

Reglas:

- Deliberadamente **no existe favicon SVG**: la marca rasterizada es el
  asset auténtico; un vector trazado a mano arriesgaría deformarla.
- El logotipo textual del armazón es el nombre tipografiado en la
  fuente display junto a la marca real — la misma gramática del
  lockup Horizontal de la suite. La vieja ficha de reemplazo
  (`wordmark-tap`) no debe volver a renderizarse; fue eliminada y un
  test fija su ausencia.

## 3. Paleta → tokens semánticos

Los cinco valores establecidos de Pulse anclan `public/css/tokens.css`
(ADR-047). Los nombres de los tokens son el contrato; los valores son
la marca:

| Valor de marca | Rol |
|---|---|
| `#E8EDDF` crema | `--background` / `--surface` — el fondo |
| `#CFDBD5` salvia | `--surface-variant`, el paso de contenedor más fuerte, familia de líneas finas |
| `#242423` tinta | `--primary` (color de acción), `--text`, texto sobre oro |
| `#333533` grafito | `--primary-container`, paso de hover (`--surface-tint`) |
| `#F5CB5C` oro | `--tertiary-fixed` — acento de puntos/eco, **y** `--on-primary`: el lenguaje de ficha oro-sobre-tinta de la marca es la gramática de botones/nav activo |

El rojo de error (familia `#ba1a1a`) sigue independiente del conjunto
de marca para que el estado nunca colapse en decoración. Suelo de
contraste: oro sobre tinta ≈ 9,7:1, tinta sobre crema ≈ 12,6:1 — cada
par fijado pasa WCAG AA.

## 4. Sistema de toasts (la única capa de confirmación)

`public/js/toast.js` + el bloque `.toast-*` en `app.css`. Un sistema,
cuatro tonos:

```js
PulseToast.success('Estudiante creado.');
PulseToast.error('No se pudo guardar.', 'El servidor rechazó la fila.');
PulseToast.warning('Algunas filas no se pudieron importar.');
PulseToast.info('Emparejamiento armado — toca una tarjeta nueva.');
```

- Apilados abajo a la derecha (móvil: franja inferior a ancho
  completo), máximo 4 — el más viejo sale primero.
- Auto-cierre: éxito/info 4 s, aviso 6,5 s, error 8 s; el hover pausa;
  botón de cierre manual; `aria-live` polite (assertive para
  error/aviso); con movimiento reducido no hay animación.
- **División del trabajo**: el toast es la confirmación, el cuadro de
  resultado en línea (`.nl-answer`) es la documentación. Las salidas
  con mucho detalle (errores por fila del CSV, claves API de un solo
  uso) se quedan en el cuadro; el toast lleva la confirmación corta.
  Los eventos en tiempo real ambientales NUNCA generan toasts.
- Cableado hoy: escritorio de estudiantes (crear / curso / importar /
  aprovisionar accesos + cada `catch` de red), escritorio de lectores
  (crear / guardar / rotación de clave + catches), escritorio de
  emparejamiento (armado / emparejado / rechazado / desvinculado +
  catches — los toasts de rechazo se deduplican por UID), panel admin
  (fallo NL, canje), escritorio docente (fallo de red NL).
- Los textos bilingües viven en `lang/*/app.php` bajo las claves
  `toast_*`; las vistas pasan cadenas traducidas vía `Js::from`
  (la regla TASK-014).

## 5. Páginas de error

403/404/419/429/500/503 se renderizan dentro del armazón
(`resources/views/errors/*`), localizadas, cada una con salida. 419 y
429 fueron añadidas en esta pasada — antes caían a la página
desnuda del framework.

## 6. Lo que deliberadamente NO se renombró

- `PresenceEvent`, `presence_events`, `config/presence.php`, el
  namespace `Presence` del firmware — identificadores internos que
  nombran el modelo de eventos (renombrar = agitación funcional sin
  ganancia visible).
- Los correos demo `@presence.test` — infraestructura de datos
  semilla.
- Los registros históricos `.agent/` y los documentos de auditoría
  fechados — la historia append-only nunca se reescribe.
