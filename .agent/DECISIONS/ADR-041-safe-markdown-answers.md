# ADR-041

## Date
2026-09-08

## Context
NL-query answers are MODEL-GENERATED text (DeepSeek, temperature 0,
but still untrusted output by definition). The chat desk needs to
render them as light Markdown — the old desk dumped raw text with
`**bold**` markers visible. A markdown renderer that accepts raw HTML
(even "sanitized later") would make the LLM a persistent-XSS
injection vector: one poisoned answer reaches every operator who
opens the desk.

## Decision
`public/js/markdown.js` — a ~80-line dependency-free renderer with an
escape-FIRST safety model: EVERY character of the answer is
HTML-escaped before any markdown transform, so the renderer can only
emit elements it creates itself: `p`, `ul`, `li`, `strong`, `em`,
`code`. Supported input: `- `/`• `/`* `/`1. ` bullets (one list
level), `**bold**`, `*italic*`, `` `code` ``. NOT supported, by
omission: links (→ no `javascript:` URLs possible), images, headings
(markers stripped), tables, raw HTML, nested structures. The system
prompt (ADR-consistent) asks for exactly this subset and forbids the
rest. Both dashboards route `#nl-answer` through
`window.renderMarkdown(text)`; errors still render via `textContent`.

## Alternatives Considered
- marked.js / markdown-it via CDN — rejected: full markdown includes
  links/images/html; sanitizing after parsing is a second library and
  a second bug class; CDN violates the self-hosted-asset rule.
- Keep textContent (no markdown) — rejected: owner item 2 explicitly
  asks for rendering; the model's concise answers read badly raw.
- Server-side rendering — rejected: the answer is per-request chat
  output; server parsing adds latency and the same trust problem with
  no browser-side benefit.

## Reasoning
The subset the system prompt requests is exactly the subset the
renderer implements — contract and implementation are one grammar, and
the safety argument is structural (escape happens before any tag can
be created), not behavioral (no sanitizer to bypass). 80 lines beat a
dependency the bench cannot audit offline.

## Consequences
- The renderer is the ONLY path from NL answers to innerHTML; grepping
  the desks for `innerHTML` near `nl-answer` must keep showing only
  this call.
- Extending the grammar (e.g. links) requires revisiting this ADR —
  links reintroduce URL-scheme risk.
- Other model-facing surfaces (future chat UIs) should reuse
  `window.renderMarkdown` rather than grow bespoke renderers.

## Status
ACTIVE
