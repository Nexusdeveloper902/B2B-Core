/* ==========================================================================
   Presence Platform — minimal safe Markdown renderer (TASK-027).

   The NL-query answers arrive as MODEL-GENERATED text. They may contain
   light Markdown (the system prompt asks for **bold**, "- " bullets and
   `code` and forbids headings/tables), but they are never trusted HTML.

   Safety model: EVERYTHING is HTML-escaped FIRST, then the tiny subset
   of Markdown is applied on the escaped text — so the renderer can
   never emit an element it did not itself create. No links (no href =
   no javascript: URLs), no images, no headings, no tables, no raw HTML.

   Contract: window.renderMarkdown(text) -> safe HTML string (or the
   escaped text when the input is not a string). No build step, no deps.
   ========================================================================== */
(function () {
    'use strict';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function inline(escaped) {
        var out = escaped;
        // `code` first so bold/italic never swallow backticks.
        out = out.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        // **bold**
        out = out.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        // *italic* (not part of a ** pair)
        out = out.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
        return out;
    }

    function renderMarkdown(text) {
        if (typeof text !== 'string') {
            return escapeHtml(text === null || text === undefined ? '' : text);
        }

        var escaped = escapeHtml(text);
        var lines = escaped.split(/\r?\n/);
        var html = [];
        var listOpen = false;

        function closeList() {
            if (listOpen) {
                html.push('</ul>');
                listOpen = false;
            }
        }

        lines.forEach(function (line) {
            var bullet = line.match(/^\s*(?:[-•*]|\d+[.)])\s+(.*)$/);
            if (bullet) {
                if (!listOpen) {
                    html.push('<ul>');
                    listOpen = true;
                }
                html.push('<li>' + inline(bullet[1]) + '</li>');
                return;
            }

            closeList();
            if (line.trim() === '') {
                return; // blank lines separate paragraphs; nothing emitted
            }
            // Strip stray heading markers instead of rendering them.
            var stripped = line.replace(/^\s{0,3}#{1,6}\s+/, '');
            html.push('<p>' + inline(stripped) + '</p>');
        });

        closeList();
        return html.join('');
    }

    window.renderMarkdown = renderMarkdown;
})();
