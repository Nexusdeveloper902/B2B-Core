{{--
    TASK-034 — shared Datum confirm dialog (replaces window.confirm).

    Usage (once per page):
        <x-confirm-modal id="reader-confirm" />

    Opening (from the page script):
        window.DatumConfirm.open('reader-confirm', {
            title: 'Rotate key',
            message: 'Really rotate …?',
            confirmLabel: 'Rotate key',
            onConfirm: function () { …destructive fetch… },
        });

    Cancel/confirm labels: Cancel rides lang (app.modal_cancel); the
    confirm button takes the page's own action label so the destructive
    verb is always explicit. Focus moves to Cancel on open (the safe
    choice), Esc/backdrop cancel, Tab stays inside, focus returns to
    the trigger. The inline script is idempotent across includes and
    — being page-rendered — never goes stale behind asset caching.
--}}
@props(['id' => 'confirm-modal'])
<div class="modal-backdrop hidden" id="{{ $id }}" data-confirm-modal role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-message">
    <div class="modal-card">
        <h2 class="modal-title" id="{{ $id }}-title" data-modal-title></h2>
        <p class="modal-message nl-answer" id="{{ $id }}-message" data-modal-message></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-quiet" data-modal-cancel>{{ __('app.modal_cancel') }}</button>
            <button type="button" class="btn btn-danger" data-modal-confirm></button>
        </div>
    </div>
</div>
<script>
    (function () {
        if (window.DatumConfirm) { return; }
        var openId = null;
        var trigger = null;
        var onConfirm = null;

        function root() { return openId ? document.getElementById(openId) : null; }

        function close() {
            var r = root();
            if (r) { r.classList.add('hidden'); }
            openId = null;
            onConfirm = null;
            document.removeEventListener('keydown', onKey, true);
            if (trigger && trigger.focus) { trigger.focus(); }
            trigger = null;
        }

        function onKey(e) {
            var r = root();
            if (!r) { return; }
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if (e.key !== 'Tab') { return; }
            var items = r.querySelectorAll('[data-modal-cancel], [data-modal-confirm]');
            if (!items.length) { return; }
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }

        function wire(r) {
            if (r.dataset.wired) { return; }
            r.dataset.wired = '1';
            r.addEventListener('click', function (e) {
                if (e.target === r) { close(); return; }
                if (e.target.closest('[data-modal-cancel]')) { close(); return; }
                if (e.target.closest('[data-modal-confirm]')) {
                    var cb = onConfirm;
                    close();
                    if (cb) { cb(); }
                }
            });
        }

        window.DatumConfirm = {
            open: function (id, opts) {
                var r = document.getElementById(id);
                if (!r) { return; }
                wire(r);
                opts = opts || {};
                var title = r.querySelector('[data-modal-title]');
                var msg = r.querySelector('[data-modal-message]');
                var ok = r.querySelector('[data-modal-confirm]');
                var cancel = r.querySelector('[data-modal-cancel]');
                if (title && opts.title !== undefined) { title.textContent = opts.title; }
                if (msg && opts.message !== undefined) { msg.textContent = opts.message; }
                if (ok && opts.confirmLabel !== undefined) { ok.textContent = opts.confirmLabel; }
                onConfirm = opts.onConfirm || null;
                trigger = document.activeElement;
                r.classList.remove('hidden');
                openId = id;
                document.addEventListener('keydown', onKey, true);
                if (cancel) { cancel.focus(); }
            }
        };
    })();
</script>
