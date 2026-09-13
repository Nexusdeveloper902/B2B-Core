/*
 * Pulse — global toast feedback (productization pass).
 *
 * One toast system for the whole app: success / error / warning / info,
 * stacking (newest bottom, max 4 — the oldest is removed), auto-dismiss
 * with sane durations, manual dismiss, pause-on-hover, aria-live
 * semantics (polite for success/info, assertive for error/warning) and
 * a reduced-motion guard (no slide/fade animation).
 *
 * Usage:
 *   PulseToast.success('Student created.');
 *   PulseToast.error('Unable to save.', 'The server rejected the row.');
 *   PulseToast.warning('…'); PulseToast.info('…');
 *   PulseToast.show(type, message, description);
 *
 * The container is created lazily on first toast (no DOM cost on pages
 * that never fire one). Bilingual copy is the CALLER's job — views pass
 * already-translated strings (same pattern as the desk scripts).
 */
(function () {
    'use strict';

    var MAX_STACK = 4;
    var DURATION = { success: 4000, info: 4000, warning: 6500, error: 8000 };

    var ICONS = {
        success: 'check_circle',
        error: 'error',
        warning: 'warning',
        info: 'info'
    };

    var container = null;

    function ensureContainer() {
        if (container && document.body.contains(container)) { return container; }
        container = document.createElement('div');
        container.className = 'toast-region';
        container.setAttribute('role', 'region');
        // Localized via the layout's PulseToastLabels map (same channel
        // as the dismiss label) — no hardcoded English in the layer.
        container.setAttribute('aria-label',
            (window.PulseToastLabels && window.PulseToastLabels.region) || 'Notifications');
        document.body.appendChild(container);
        return container;
    }

    function dismiss(toast) {
        if (!toast || toast.dataset.closing === '1') { return; }
        toast.dataset.closing = '1';
        clearTimeout(toast._timer);
        // Reduced motion: no exit animation, just remove.
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            toast.remove();
            return;
        }
        toast.classList.add('is-leaving');
        setTimeout(function () { toast.remove(); }, 200);
    }

    function show(type, message, description) {
        var region = ensureContainer();
        var live = (type === 'error' || type === 'warning') ? 'assertive' : 'polite';

        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', live);

        var icon = document.createElement('span');
        icon.className = 'material-symbols-outlined toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = ICONS[type] || 'info';

        var body = document.createElement('div');
        body.className = 'toast-body';
        var msg = document.createElement('p');
        msg.className = 'toast-message';
        msg.textContent = String(message || '');
        body.appendChild(msg);
        if (description) {
            var desc = document.createElement('p');
            desc.className = 'toast-description';
            desc.textContent = String(description);
            body.appendChild(desc);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-dismiss';
        close.setAttribute('aria-label', (window.PulseToastLabels && window.PulseToastLabels.dismiss) || 'Dismiss');
        var closeIcon = document.createElement('span');
        closeIcon.className = 'material-symbols-outlined';
        closeIcon.setAttribute('aria-hidden', 'true');
        closeIcon.textContent = 'close';
        close.appendChild(closeIcon);
        close.addEventListener('click', function () { dismiss(toast); });

        toast.appendChild(icon);
        toast.appendChild(body);
        toast.appendChild(close);

        // Newest toast goes last (visual stack grows downward); the
        // region caps the stack so a burst of failures never floods
        // the screen.
        region.appendChild(toast);
        while (region.children.length > MAX_STACK) {
            dismiss(region.firstElementChild);
        }

        // Auto-dismiss; hovering (reading) pauses the countdown.
        toast._timer = setTimeout(function () { dismiss(toast); }, DURATION[type] || 4500);
        toast.addEventListener('mouseenter', function () { clearTimeout(toast._timer); });
        toast.addEventListener('mouseleave', function () {
            toast._timer = setTimeout(function () { dismiss(toast); }, 2000);
        });

        return toast;
    }

    window.PulseToast = {
        show: show,
        success: function (m, d) { return show('success', m, d); },
        error: function (m, d) { return show('error', m, d); },
        warning: function (m, d) { return show('warning', m, d); },
        info: function (m, d) { return show('info', m, d); }
    };
})();
