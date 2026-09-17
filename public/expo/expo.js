/* ==========================================================================
   Pulse — Expo behaviour. No dependencies, no network calls.
   Everything here is deterministic presentation logic: the scroll engine,
   the particle field, the event pipeline, the architecture diagram and
   the scripted demos. Nothing talks to a backend.
   ========================================================================== */
(() => {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
    const vh = () => window.innerHeight;

    // School-local date (the platform runs on America/Bogota, ADR-025).
    const today = (() => {
        try {
            return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(new Date());
        } catch (e) {
            return new Date().toISOString().slice(0, 10);
        }
    })();

    /* ------------------------------------------------------------------
       Scroll engine: sticky "scrolly" chapters expose a step index.
       ------------------------------------------------------------------ */
    const scrollies = $$('.scrolly').map((el) => ({
        el,
        steps: parseInt(getComputedStyle(el).getPropertyValue('--steps'), 10) || 1,
        stepEls: $$('.step', el),
        current: -1,
        handlers: [],
        top: 0,
        height: 0,
    }));
    const byId = (id) => scrollies.find((s) => s.el.id === id);

    function measure() {
        scrollies.forEach((s) => {
            const r = s.el.getBoundingClientRect();
            s.top = r.top + window.scrollY;
            s.height = s.el.offsetHeight;
        });
    }

    function updateScrollies() {
        const y = window.scrollY;
        scrollies.forEach((s) => {
            const span = Math.max(1, s.height - vh());
            const p = clamp((y - s.top) / span, 0, 0.9999);
            const step = Math.floor(p * s.steps);
            s.progress = p;
            if (step !== s.current) {
                s.current = step;
                s.stepEls.forEach((st) => {
                    const n = parseInt(st.dataset.step, 10);
                    st.classList.toggle('is-on', n === step);
                    st.classList.toggle('is-past', n < step);
                    st.setAttribute('aria-hidden', n === step ? 'false' : 'true');
                });
                s.handlers.forEach((h) => h(step));
            }
        });
    }

    /* ------------------------------------------------------------------
       Running head: chapter label + progress bar.
       ------------------------------------------------------------------ */
    const chapters = $$('[data-chapter]');
    const rhChapter = $('.rh__chapter');
    const progress = $('.progress');
    let lastChapter = null;

    function updateHead() {
        const y = window.scrollY + vh() * 0.4;
        let active = chapters[0];
        chapters.forEach((c) => {
            if (c.offsetTop <= y) active = c;
        });
        if (active !== lastChapter && rhChapter) {
            lastChapter = active;
            rhChapter.innerHTML = `<b>${active.dataset.chapter}</b><span>${active.dataset.title}</span>`;
        }
        const max = document.documentElement.scrollHeight - vh();
        progress.style.setProperty('--progress', max > 0 ? (window.scrollY / max).toFixed(4) : 0);
    }

    /* ------------------------------------------------------------------
       Presenter beats: Space / PageDown / → advance, Shift+Space / PageUp / ← go back.
       ------------------------------------------------------------------ */
    function beats() {
        const list = [0];
        scrollies.forEach((s) => {
            for (let k = 0; k < s.steps; k++) list.push(Math.round(s.top + k * vh() + 2));
        });
        $$('.section').forEach((sec) => list.push(Math.round(sec.getBoundingClientRect().top + window.scrollY)));
        const spine = $('#columna');
        if (spine) list.push(Math.round(spine.getBoundingClientRect().top + window.scrollY - 90));
        list.push(document.documentElement.scrollHeight - vh());
        return Array.from(new Set(list)).sort((a, b) => a - b);
    }

    let anim = null;
    function scrollToY(target) {
        target = clamp(target, 0, document.documentElement.scrollHeight - vh());
        if (anim) cancelAnimationFrame(anim.raf);
        if (reduced) {
            window.scrollTo(0, target);
            return;
        }
        const from = window.scrollY;
        const dist = target - from;
        const dur = clamp(Math.abs(dist) * 0.55, 450, 1100);
        const t0 = performance.now();
        const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
        anim = {};
        const tick = (now) => {
            const t = clamp((now - t0) / dur, 0, 1);
            window.scrollTo(0, from + dist * ease(t));
            if (t < 1) anim.raf = requestAnimationFrame(tick);
            else anim = null;
        };
        anim.raf = requestAnimationFrame(tick);
    }
    ['wheel', 'touchstart'].forEach((ev) => window.addEventListener(ev, () => {
        if (anim) { cancelAnimationFrame(anim.raf); anim = null; }
    }, { passive: true }));

    const hint = $('.rh__hint');
    function hideHint() { if (hint) hint.style.opacity = '0'; }

    document.addEventListener('keydown', (e) => {
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        const tag = (e.target.tagName || '').toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag)) return;
        const onControl = tag === 'button' || tag === 'a' || e.target.getAttribute('role') === 'tab';
        const next = e.key === 'PageDown' || e.key === 'ArrowRight' || (e.key === ' ' && !e.shiftKey && !onControl);
        const prev = e.key === 'PageUp' || e.key === 'ArrowLeft' || (e.key === ' ' && e.shiftKey && !onControl);
        if (e.target.getAttribute('role') === 'tab' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) return;
        if (next || prev) {
            e.preventDefault();
            hideHint();
            const y = anim ? anim.target ?? window.scrollY : window.scrollY;
            const list = beats();
            const target = next ? list.find((b) => b > y + 8) : [...list].reverse().find((b) => b < y - 8);
            if (target !== undefined) scrollToY(target);
        } else if (e.key === 'f' || e.key === 'F') {
            toggleFullscreen();
        } else if (e.key === 'Home') {
            e.preventDefault();
            scrollToY(0);
        }
    });

    function toggleFullscreen() {
        const d = document;
        if (!d.fullscreenElement) d.documentElement.requestFullscreen?.().catch(() => {});
        else d.exitFullscreen?.().catch(() => {});
    }
    $('[data-fullscreen]')?.addEventListener('click', toggleFullscreen);
    $('[data-top]')?.addEventListener('click', () => scrollToY(0));

    /* ------------------------------------------------------------------
       Autoplay: hands-free run through every beat, then back to top.
       Any manual navigation (wheel, touch, keys) hands control back.
       ------------------------------------------------------------------ */
    (() => {
        const btn = $('[data-autoplay]');
        if (!btn) return;
        const DWELL = 2600;
        const END_HOLD = 3600;
        let on = false, timer = 0;
        const later = (fn, ms) => {
            clearTimeout(timer);
            timer = setTimeout(() => { if (on) fn(); }, reduced ? Math.min(ms, 500) : ms);
        };
        function stop() {
            on = false;
            clearTimeout(timer);
            btn.classList.remove('is-on');
            btn.setAttribute('aria-pressed', 'false');
        }
        function finish() {
            later(() => {
                scrollToY(0);
                later(stop, 1500);
            }, END_HOLD);
        }
        function play() {
            on = true;
            btn.classList.add('is-on');
            btn.setAttribute('aria-pressed', 'true');
            hideHint();
            scrollToY(0);
            const list = beats();
            let i = 0;
            const leg = () => {
                if (i >= list.length) { finish(); return; }
                scrollToY(list[i++]);
                later(leg, DWELL + 1000);
            };
            later(leg, 1100);
        }
        btn.addEventListener('click', () => (on ? stop() : play()));
        ['wheel', 'touchstart'].forEach((ev) => window.addEventListener(ev, () => { if (on) stop(); }, { passive: true }));
        document.addEventListener('keydown', (e) => {
            if (on && [' ', 'PageDown', 'PageUp', 'ArrowRight', 'ArrowLeft', 'Home', 'Escape'].includes(e.key)) stop();
        });
        if (new URLSearchParams(location.search).get('autoplay') && !reduced) setTimeout(play, 900);
    })();

    /* ------------------------------------------------------------------
       01 · Particle field — events → fragments → one door.
       ------------------------------------------------------------------ */
    const field = (() => {
        const canvas = $('#field');
        if (!canvas) return null;
        const ctx = canvas.getContext('2d');
        const scrolly = byId('problema');
        const frags = $$('.frag');
        const fragWrap = $('.frags');
        let W = 0, H = 0, dpr = 1, N = 0, parts = [], clusters = [], state = 0, visible = true, raf = 0, t = 0;
        const shapes = ['lines', 'grid', 'cols', 'card', 'ring', 'block', 'scatter'];
        const pairs = [[0, 1], [1, 2], [3, 4], [4, 5], [5, 6], [0, 3], [2, 6], [1, 3]];

        function layout() {
            const mobile = W < 760;
            const pos = mobile
                ? [[0.2, 0.6], [0.52, 0.56], [0.83, 0.62], [0.3, 0.75], [0.72, 0.77], [0.16, 0.9], [0.56, 0.91]]
                : [[0.58, 0.22], [0.8, 0.17], [0.9, 0.46], [0.68, 0.5], [0.56, 0.77], [0.76, 0.8], [0.92, 0.76]];
            const per = Math.ceil(N / 7);
            const sp = mobile ? 5 : Math.max(5.5, Math.min(8, W / 260));
            clusters = pos.map(([fx, fy], c) => {
                const cols = mobile ? 9 : 14;
                const rows = Math.ceil(per / cols);
                const shape = shapes[c];
                const w = cols * sp, h = (shape === 'lines' ? rows * 2 : rows) * sp;
                return { cx: fx * W, cy: fy * H, cols, rows, w, h: Math.min(h, (mobile ? 60 : 130)), shape, sp };
            });
            frags.forEach((f, i) => {
                const c = clusters[i];
                f.style.left = `${c.cx}px`;
                f.style.top = `${c.cy + c.h / 2 + 12}px`;
            });
        }

        function slot(c, j, per) {
            const { cols, sp, w, shape } = c;
            let x, y;
            const col = j % cols, row = Math.floor(j / cols);
            const hh = c.h;
            switch (shape) {
                case 'lines': x = col * sp; y = row * sp * 2; break;
                case 'cols': x = col * sp * 1.4 - w * 0.2; y = row * sp * 0.8; break;
                case 'card': {
                    const per4 = j / per, pw = w, ph = hh * 0.62;
                    const d = per4 * 2 * (pw + ph);
                    if (d < pw) { x = d; y = 0; } else if (d < pw + ph) { x = pw; y = d - pw; } else if (d < 2 * pw + ph) { x = pw - (d - pw - ph); y = ph; } else { x = 0; y = ph - (d - 2 * pw - ph); }
                    break;
                }
                case 'ring': {
                    const a = (j / per) * Math.PI * 2, r = Math.min(w, hh) * 0.48 * (0.75 + 0.25 * ((j * 7) % 4) / 3);
                    x = w / 2 + Math.cos(a) * r; y = hh / 2 + Math.sin(a) * r;
                    break;
                }
                case 'scatter': {
                    const r1 = rand(j * 3.1 + 7), r2 = rand(j * 5.7 + 3);
                    x = r1 * w; y = r2 * hh;
                    break;
                }
                default: x = col * sp; y = row * sp * 0.85;
            }
            return [c.cx - w / 2 + x, c.cy - hh / 2 + Math.min(y, hh)];
        }

        function rand(s) {
            const v = Math.sin(s * 12.9898) * 43758.5453;
            return v - Math.floor(v);
        }

        function resize() {
            dpr = Math.min(2, window.devicePixelRatio || 1);
            W = canvas.clientWidth;
            H = canvas.clientHeight;
            canvas.width = Math.round(W * dpr);
            canvas.height = Math.round(H * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            const n = W < 760 ? 520 : W < 1400 ? 1000 : 1400;
            if (n !== N) {
                N = n;
                parts = Array.from({ length: N }, (_, i) => {
                    const lane = rand(i + 1);
                    return {
                        fx: rand(i * 1.7 + 2) * W,
                        base: lane,
                        vx: 0.12 + rand(i * 2.3) * 0.55,
                        ph: rand(i * 4.1) * Math.PI * 2,
                        size: rand(i * 9.9) < 0.12 ? 2.4 : 1.6,
                        c: i % 7,
                        j: Math.floor(i / 7),
                        flash: 0,
                        x: rand(i * 1.7 + 2) * W,
                        y: lane * H,
                    };
                });
            }
            layout();
            if (reduced) draw(true);
        }

        function target(p) {
            if (state <= 0) {
                return [p.fx, p.base * H + Math.sin(t * 0.01 + p.ph) * 6];
            }
            if (state >= 4) {
                const span = W + 200;
                const x = (((p.c * N / 7 + p.j) / N) * span + t * 1.1) % span - 100;
                return [x, H * (W < 760 ? 0.8 : 0.8) + (((p.c + p.j) % 5) - 2) * 1.6];
            }
            return slot(clusters[p.c], p.j, Math.ceil(N / 7));
        }

        function draw(snap) {
            ctx.clearRect(0, 0, W, H);
            t += 1;
            const k = snap ? 1 : state >= 1 && state <= 3 ? 0.07 : 0.05;
            // spawn flashes: events happening
            if (state === 0 && !snap) {
                const spawn = Math.max(1, Math.round(N / 160));
                for (let s = 0; s < spawn; s++) parts[(Math.random() * N) | 0].flash = 1;
            }
            ctx.beginPath();
            const flashing = [];
            const alphaBase = state >= 4 ? 0 : 1;
            for (let i = 0; i < N; i++) {
                const p = parts[i];
                p.fx += p.vx;
                if (p.fx > W + 10) p.fx = -10;
                const [tx, ty] = target(p);
                if (Math.abs(tx - p.x) > W * 0.5) { p.x = tx; } else { p.x += (tx - p.x) * k; }
                p.y += (ty - p.y) * k;
                if (p.flash > 0.02) { flashing.push(p); p.flash *= 0.95; continue; }
                if (alphaBase) ctx.rect(p.x, p.y, p.size, p.size);
            }
            if (alphaBase) {
                ctx.fillStyle = state === 0 ? 'rgba(232,237,223,0.34)' : 'rgba(207,219,213,0.62)';
                ctx.fill();
            }
            if (state >= 4) {
                ctx.beginPath();
                for (let i = 0; i < N; i++) ctx.rect(parts[i].x, parts[i].y, 1.8, 1.8);
                ctx.fillStyle = 'rgba(245,203,92,0.85)';
                ctx.fill();
                const g = ctx.createLinearGradient(0, 0, W, 0);
                g.addColorStop(0, 'rgba(245,203,92,0)');
                g.addColorStop(0.5, 'rgba(245,203,92,0.35)');
                g.addColorStop(1, 'rgba(245,203,92,0)');
                ctx.fillStyle = g;
                ctx.fillRect(0, H * 0.8 - 0.5, W, 1);
            }
            for (const p of flashing) {
                ctx.fillStyle = `rgba(245,203,92,${Math.min(1, p.flash).toFixed(2)})`;
                const s = p.size + 2.2 * p.flash;
                ctx.fillRect(p.x - s / 2, p.y - s / 2, s, s);
            }
            // broken connectors between fragments
            if (state >= 2 && state <= 3) {
                const pulse = state === 3 ? 0.35 + 0.35 * Math.abs(Math.sin(t * 0.05)) : 0.28;
                ctx.save();
                ctx.setLineDash([3, 5]);
                ctx.lineWidth = 1;
                ctx.strokeStyle = state === 3 ? `rgba(239,111,94,${pulse.toFixed(2)})` : `rgba(232,237,223,${pulse})`;
                pairs.forEach(([a, b]) => {
                    const A = clusters[a], B = clusters[b];
                    const mx = (A.cx + B.cx) / 2, my = (A.cy + B.cy) / 2;
                    const gx = (B.cx - A.cx) * 0.09, gy = (B.cy - A.cy) * 0.09;
                    ctx.beginPath();
                    ctx.moveTo(A.cx, A.cy); ctx.lineTo(mx - gx, my - gy);
                    ctx.moveTo(mx + gx, my + gy); ctx.lineTo(B.cx, B.cy);
                    ctx.stroke();
                    ctx.save();
                    ctx.setLineDash([]);
                    ctx.beginPath();
                    ctx.moveTo(mx - 4, my - 4); ctx.lineTo(mx + 4, my + 4);
                    ctx.moveTo(mx + 4, my - 4); ctx.lineTo(mx - 4, my + 4);
                    ctx.stroke();
                    ctx.restore();
                });
                ctx.restore();
            }
        }

        function loop() {
            raf = 0;
            if (!visible || reduced) return;
            draw(false);
            raf = requestAnimationFrame(loop);
        }
        function start() { if (!raf && !reduced) raf = requestAnimationFrame(loop); }

        new IntersectionObserver((entries) => {
            visible = entries[0].isIntersecting;
            if (visible) start();
        }).observe(scrolly.el);

        scrolly.handlers.push((step) => {
            state = step < 1 ? 0 : step;
            fragWrap.classList.toggle('show-frags', step >= 2 && step <= 3);
            $('.scroll-cue').style.opacity = step === 0 ? '1' : '0';
            if (reduced) draw(true);
        });

        window.addEventListener('resize', resize);
        resize();
        start();
        return { resize };
    })();

    /* ------------------------------------------------------------------
       02 · One event — the pipeline.
       ------------------------------------------------------------------ */
    (() => {
        const s = byId('evento');
        if (!s) return;
        const pipe = $('.pipe', s.el);
        const stations = $$('.st', s.el);
        const packet = $('.packet', s.el);
        const fill = $('.pipe__fill', s.el);
        const track = $('.pipe__track', s.el);
        const edges = $$('.pipe__edge', s.el);
        const counter = $('[data-ev-count]', s.el);
        const at = [0, 2, 3, 3, 4, 5, 6, 6];
        let centers = [];
        let running = false;

        function geometry() {
            const pr = pipe.getBoundingClientRect();
            centers = stations.map((st) => {
                const r = $('.st__node', st).getBoundingClientRect();
                return r.left + r.width / 2 - pr.left;
            });
            track.style.left = `${centers[0]}px`;
            track.style.right = `${pr.width - centers[centers.length - 1]}px`;
            edges.forEach((e) => {
                const k = parseInt(e.dataset.edge, 10);
                e.style.left = `${(centers[k] + centers[k + 1]) / 2 - centers[0]}px`;
            });
        }

        function place(pos, here) {
            packet.style.left = `${centers[pos]}px`;
            const span = centers[centers.length - 1] - centers[0];
            fill.style.width = `${centers[pos] - centers[0]}px`;
            fill.style.maxWidth = `${span}px`;
            stations.forEach((st, i) => {
                st.classList.toggle('is-lit', i <= pos);
                st.classList.toggle('is-here', here && i === pos);
            });
            edges.forEach((e) => e.classList.toggle('is-lit', parseInt(e.dataset.edge, 10) < pos));
        }

        function show(step) {
            if (running) return;
            counter.textContent = String(step + 1).padStart(2, '0');
            place(at[step], step < 7);
        }

        function replay() {
            if (running) return;
            running = true;
            packet.style.transition = 'none';
            place(0, true);
            void packet.offsetWidth;
            packet.style.transition = '';
            let i = 0;
            const tick = () => {
                i += 1;
                place(i, true);
                if (i < centers.length - 1) setTimeout(tick, reduced ? 0 : 420);
                else setTimeout(() => { running = false; place(6, false); }, 700);
            };
            setTimeout(tick, 300);
        }

        $('[data-replay]', s.el)?.addEventListener('click', replay);
        s.handlers.push(show);
        window.addEventListener('resize', () => { geometry(); show(Math.max(0, s.current)); });
        geometry();
        show(0);
    })();

    /* ------------------------------------------------------------------
       03 · The spine — three lenses over one table.
       ------------------------------------------------------------------ */
    (() => {
        const body = $('[data-spine-rows]');
        if (!body) return;
        // A representative school day (demo data, consistent with the screenshots).
        const rows = [
            ['06:52', 'CLASS_ATTENDANCE', 'Luciana Ramírez', 'Aula 5° B', 'ok'],
            ['06:58', 'CLASS_ATTENDANCE', 'Ximena Salazar', 'Aula 5° B', 'ok'],
            ['07:05', 'PAE_BREAKFAST', 'Luciana Ramírez', 'Cafetería Norte', 'ok'],
            ['07:08', 'PAE_BREAKFAST', 'Ximena Salazar', 'Cafetería Norte', 'ok'],
            ['07:12', 'PAE_BREAKFAST', 'Natalia López', 'Cafetería Norte', 'flag', 'not_enrolled'],
            ['07:26', 'CLASS_ATTENDANCE', 'Juan Castillo', 'Aula 5° B', 'ok'],
            ['07:35', 'PAE_BREAKFAST', 'Juan Castillo', 'Cafetería Norte', 'ok'],
            ['08:01', 'CLASS_ATTENDANCE', 'Martina Guerrero', 'Aula 5° B', 'ok'],
            ['08:22', 'CLASS_ATTENDANCE', 'Joaquín Rivera', 'Aula 5° B', 'late'],
            ['09:40', 'RECYCLING_DEPOSIT', 'Ximena Salazar', 'Ecoestación Patio', 'pts', 'plástico', 10],
            ['10:15', 'RECYCLING_DEPOSIT', 'Felipe Robles', 'Ecoestación Patio', 'pts', 'metal', 15],
            ['12:04', 'PAE_LUNCH', 'Martina Guerrero', 'Cafetería Sur', 'ok'],
            ['12:09', 'PAE_LUNCH', 'Luciana Ramírez', 'Cafetería Sur', 'ok'],
            ['12:31', 'RECYCLING_DEPOSIT', 'Juan Castillo', 'Ecoestación Biblioteca', 'pts', 'papel', 5],
        ];
        const esc = (v) => String(v).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
        const state = (r) => {
            if (r[4] === 'flag') return `<span class="bad-mark">✕ no inscrita</span>`;
            if (r[4] === 'late') return '<span class="ok-mark">✓</span> tarde';
            if (r[4] === 'pts') return `<span class="ok-mark">✓</span> ${r[5]} +${r[6]}`;
            return '<span class="ok-mark">✓</span>';
        };
        const typename = (v) => ({ CLASS_ATTENDANCE: 'Llegada', PAE_BREAKFAST: 'Desayuno', PAE_LUNCH: 'Almuerzo', RECYCLING_DEPOSIT: 'Reciclaje' }[v] || v);
        body.innerHTML = rows.map((r) => `<tr class="${r[4] === 'flag' ? 'is-flag' : ''}"><td>${r[0]}</td><td class="t-type">${typename(r[1])}</td><td>${esc(r[2])}</td><td>${esc(r[3])}</td><td>${state(r)}</td></tr>`).join('');
        const trs = $$('tr', body);
        const title = $('[data-derive-title]');
        const dl = $('[data-derive-rows]');
        const code = $('[data-derive-code]');

        const att = rows.filter((r) => r[1] === 'CLASS_ATTENDANCE');
        const served = (t) => rows.filter((r) => r[1] === t && r[4] !== 'flag').length;
        const rec = rows.filter((r) => r[1] === 'RECYCLING_DEPOSIT');
        const lenses = {
            att: {
                match: (r) => r[1] === 'CLASS_ATTENDANCE',
                title: 'Asistencia de hoy',
                rows: [['Presentes', att.length, 'gold'], ['A tiempo', att.filter((r) => r[4] !== 'late').length], ['Tarde (después de 08:15)', att.filter((r) => r[4] === 'late').length]],
                code: `<span class="c">-- de aquí sale la asistencia de hoy</span>\ncada estudiante cuenta <span class="k">una vez</span>\nsolo vale el primer toque del día`,
            },
            pae: {
                match: (r) => r[1].startsWith('PAE_'),
                title: 'Comedor escolar (PAE)',
                rows: [['Desayunos servidos', served('PAE_BREAKFAST'), 'gold'], ['Almuerzos servidos', served('PAE_LUNCH'), 'gold'], ['Intentos rechazados', '1 · no cuenta', 'bad']],
                code: `<span class="c">-- el comedor solo cuenta lo servido</span>\nlo rechazado se ve,\npero <span class="k">no suma</span>`,
            },
            rec: {
                match: (r) => r[1] === 'RECYCLING_DEPOSIT',
                title: 'Reciclaje',
                rows: [['Depósitos clasificados', rec.length, 'gold'], ['Puntos otorgados', `+${rec.reduce((a, r) => a + r[6], 0)}`, 'gold'], ['Entradas nuevas en el libro', rec.length]],
                code: `<span class="c">-- el saldo no se guarda: se suma</span>\nsaldo = suma de entradas\nnada se borra, nada se edita`,
            },
            all: {
                match: () => true,
                title: 'Toda la tabla',
                rows: [['Filas de la muestra', rows.length, 'gold'], ['Tipos de evento', new Set(rows.map((r) => r[1])).size], ['Aplicaciones que la leen', 3]],
                code: `<span class="c">-- una fila por toque</span>\nquién · dónde · qué\ncuándo · resultado`,
            },
        };
        function apply(key) {
            const L = lenses[key];
            trs.forEach((tr, i) => {
                const m = L.match(rows[i]);
                tr.classList.toggle('is-dim', !m);
                tr.classList.toggle('is-match', m && key !== 'all' && rows[i][4] !== 'flag');
            });
            title.textContent = L.title;
            dl.innerHTML = L.rows.map(([k, v, cls]) => `<div><dt>${k}</dt><dd class="${cls || ''}">${v}</dd></div>`).join('');
            code.innerHTML = L.code;
            $$('[data-lens]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lens === key)));
        }
        $$('[data-lens]').forEach((b) => b.addEventListener('click', () => apply(b.dataset.lens)));
        apply('att');
    })();

    /* ------------------------------------------------------------------
       04 · Architecture diagram
       ------------------------------------------------------------------ */
    (() => {
        const svg = $('#arch-svg');
        if (!svg) return;
        const NS = 'http://www.w3.org/2000/svg';
        const panel = $('[data-arch-panel]');
        const NW = 190, NH = 56;
        const S = { real: ['status--real', 'Implementado'], key: ['status--key', 'Implementado · requiere clave de API'], ref: ['status--ref', 'Contrato listo · servidor de referencia'], simple: ['status--simple', 'Versión simplificada'] };
        const statusColor = { real: '#7fcf9b', key: '#f5cb5c', ref: '#9fb7d9', simple: '#8a8c88' };

        const cols = [
            { x: 30, w: 230, label: 'Mundo físico' },
            { x: 300, w: 230, label: 'Dispositivos' },
            { x: 580, w: 440, label: '' },
            { x: 1060, w: 230, label: 'Datos · inteligencia' },
            { x: 1330, w: 240, label: 'Personas' },
        ];
        const N = {
            estudiante: [45, 110, 'Estudiante', 'una persona', null, 'Una persona con una credencial activa: tarjeta o teléfono. Además tiene su propia cuenta para ver su historial, sus puntos y canjear recompensas.', 'students · users (role = student)'],
            tarjeta: [45, 240, 'Tarjeta NFC', 'solo un identificador', 'real', 'Solo guarda un identificador. Todo lo demás —de quién es, si está activa— vive en el servidor.', 'cards.kind = physical'],
            telefono: [45, 370, 'Teléfono Android', 'teléfono como tarjeta', 'real', 'La app del teléfono se comporta como una tarjeta y responde con su llave secreta. No guarda ningún secreto compartido.', 'B2B-App/pulse-credential'],
            residuo: [45, 560, 'Material reciclable', 'botella, lata, papel', null, 'Plástico, papel, metal o vidrio. Se fotografía y se clasifica antes de otorgar puntos.', '—'],
            lector: [320, 240, 'Lector ESP32', 'lee sin contacto', 'real', 'Lee la tarjeta, responde con luces y avisa al servidor: cada mensaje viaja firmado.', 'B2B-Firmware · env esp32dev'],
            mdns: [320, 380, 'Red local', 'se encuentra solo', 'real', 'El servidor se anuncia en la red; los lectores lo encuentran sin configurar direcciones.', 'scripts/serve.sh · lib/PulseDiscovery'],
            estacion: [320, 560, 'Estación ESP32-CAM', 'cámara + lector', 'real', 'Cámara y lector en una sola estación. Vale llegar con la botella o con la tarjeta primero.', 'B2B-Firmware · env esp32cam'],
            api: [600, 120, 'API v1', 'mensajes firmados', 'real', 'Recibe los avisos de los lectores y atiende a los paneles. Cada aviso de un lector viaja firmado.', 'routes/api.php · docs/API.es.md'],
            escuela: [600, 230, 'Colegios', 'cada colegio aislado', 'real', 'Cada solicitud actúa para un solo colegio. Nada puede cruzar al vecino.', 'app/Models/Concerns · ADR-064'],
            hce: [600, 340, 'Verificación HCE', 'cada teléfono verificado', 'real', 'Verifica la respuesta del teléfono con su llave. Sin prueba válida, no hay evento.', 'app/Services/Hce/HceCredentialAuth.php'],
            realtime: [600, 600, 'Tiempo real', 'avisos en vivo', 'real', 'Servidor propio que lleva cada fila nueva a los paneles de su colegio, en menos de un segundo.', 'php artisan realtime:serve · app/Services/Realtime/'],
            tap: [810, 120, 'TapService', 'toque → evento', 'real', 'Convierte el toque en un evento con nombre. El primer toque del día es el que cuenta.', 'app/Services/TapService.php'],
            pae: [810, 230, 'Motor PAE', '5 reglas justas', 'real', 'Día de clase, hora de la comida, inscripción, asistencia previa y una por día. Lo rechazado queda con su motivo.', 'app/Services/MealServingService.php'],
            recy: [810, 400, 'Reciclaje', 'foto → material → puntos', 'real', 'Une la foto con la tarjeta y clasifica el material antes de dar puntos.', 'app/Services/Recycling/ · app/Contracts/MaterialClassifier.php'],
            points: [810, 500, 'PointsService', 'ganar · canjear', 'real', 'Cada punto ganado o canjeado es una entrada nueva. Nada se borra ni se edita.', 'app/Services/PointsService.php'],
            nl: [810, 700, 'Lenguaje natural', '27 consultas', 'key', 'El modelo elige cuál de las 27 consultas responde; Pulse calcula. Cada profe solo ve sus cursos.', 'app/Services/NlQuery/'],
            events: [1080, 120, 'Tabla events', 'la columna vertebral', 'real', 'Una fila por toque. Asistencia, PAE y reciclaje se derivan de aquí; ninguna aplicación guarda su propia copia.', 'database/migrations · App\\Models\\PresenceEvent'],
            ledger: [1080, 250, 'points_ledger', 'nunca se borra', 'real', 'El saldo es la suma de las entradas. Nunca se sobrescribe un número.', 'App\\Models\\PointsLedger'],
            db: [1080, 380, 'MariaDB · SQLite', 'guarda todo', 'real', 'Base de datos del colegio para operar; base ligera para las pruebas.', 'docs/DATABASE.es.md · ADR-049'],
            local: [1080, 560, 'Clasificador local', 'se puede cambiar', 'ref', 'Servicio propio que dice qué material ve la foto. Hoy con un modelo provisional.', 'scripts/local-model-server/ · docs/LOCAL_MODEL.es.md'],
            deepseek: [1080, 700, 'DeepSeek', 'servicio externo', 'key', 'Modelo externo para conversar y ver. Sin clave, Pulse lo dice en vez de inventar.', 'NlQuery/DeepSeekClient · Recycling/Drivers/DeepSeekClassifier'],
            docente: [1355, 110, 'Docente', '/teacher', 'real', 'Presentes, tarde y ausentes de su clase, en vivo. Solo ve a sus estudiantes.', 'TeacherDashboardController'],
            admin: [1355, 220, 'Administración', '/admin · reportes', 'real', 'Estadísticas, lectores, estudiantes, personal, emparejamiento, configuración, reportes PDF/CSV y preguntas en lenguaje natural.', 'AdminDashboardController · /admin/reports/*'],
            cocina: [1355, 330, 'Cocina', '/kitchen', 'real', 'Pantalla verde o roja por cada toque del comedor, con el motivo. Sin acceso a otros escritorios.', 'KitchenController'],
            estudiante2: [1355, 440, 'Estudiante', '/student', 'real', 'Su historial, sus puntos, la clasificación de reciclaje y el catálogo de recompensas.', 'StudentDashboardController'],
            altavoz: [1355, 550, 'Teléfono-parlante', 'puente de audio', 'real', 'La app se conecta al canal en vivo y suena en un parlante Bluetooth por cada toque, aceptado o rechazado.', 'B2B-App · ADR-003'],
            linea: [1355, 700, 'Línea de tiempo', '/parent/students/{id}', 'simple', 'El historial completo de un estudiante, abierto por administración o docentes. Un sistema de acceso para acudientes está fuera de alcance, a propósito.', 'ParentViewController'],
        };
        const core = new Set(['api', 'escuela', 'hce', 'realtime', 'tap', 'pae', 'recy', 'points', 'nl']);

        const E = {
            e1: ['estudiante', 'tarjeta'], e2: ['estudiante', 'telefono'], e3: ['tarjeta', 'lector'], e4: ['telefono', 'lector'],
            e5: ['tarjeta', 'estacion'], e6: ['residuo', 'estacion'], e7: ['lector', 'mdns', 'dash'], e8: ['lector', 'api'],
            e9: ['estacion', 'api'], e10: ['api', 'escuela'], e11: ['escuela', 'tap'], e12: ['tap', 'pae'], e13: ['tap', 'hce'],
            e14: ['tap', 'events'], e15: ['pae', 'events'], e16: ['escuela', 'recy'], e17: ['recy', 'local'], e18: ['recy', 'deepseek'],
            e19: ['recy', 'points'], e20: ['points', 'ledger'], e21: ['recy', 'events'], e22: ['events', 'db'], e23: ['ledger', 'db'],
            e24: ['events', 'realtime'], e25: ['realtime', 'docente'], e26: ['realtime', 'admin'], e27: ['realtime', 'cocina'],
            e28: ['realtime', 'altavoz'], e29: ['ledger', 'estudiante2'], e30: ['admin', 'nl'], e31: ['nl', 'deepseek'], e32: ['nl', 'events'],
            e33: ['events', 'linea', 'dash'],
        };

        const el = (name, attrs = {}, parent = svg) => {
            const n = document.createElementNS(NS, name);
            Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v));
            parent.appendChild(n);
            return n;
        };

        // columns
        const gCols = el('g', { class: 'g-col' });
        cols.forEach((c) => {
            if (!c.label) return;
            el('rect', { class: 'colbg', x: c.x, y: 60, width: c.w, height: 790 }, gCols);
            el('text', { class: 'col', x: c.x + 14, y: 86 }, gCols).textContent = c.label;
        });
        el('rect', { class: 'core-box', x: 580, y: 60, width: 440, height: 790 }, gCols);
        el('text', { class: 'core-label', x: 596, y: 86 }, gCols).textContent = 'PULSE CORE · LARAVEL 13';

        const box = (id) => { const n = N[id]; return { x: n[0], y: n[1], cx: n[0] + NW / 2, cy: n[1] + NH / 2 }; };
        function pathFor(a, b) {
            const A = box(a), B = box(b);
            if (B.cx > A.cx + 60) {
                const x1 = A.x + NW, y1 = A.cy, x2 = B.x, y2 = B.cy, m = (x2 - x1) / 2;
                return `M${x1},${y1} C${x1 + m},${y1} ${x2 - m},${y2} ${x2},${y2}`;
            }
            if (B.cx < A.cx - 60) {
                const x1 = A.x, y1 = A.cy, x2 = B.x + NW, y2 = B.cy, m = (x1 - x2) / 2;
                return `M${x1},${y1} C${x1 - m},${y1} ${x2 + m},${y2} ${x2},${y2}`;
            }
            const x = A.x, bulge = 26 + Math.abs(B.cy - A.cy) * 0.12;
            return `M${x},${A.cy} C${x - bulge},${A.cy} ${x - bulge},${B.cy} ${x},${B.cy}`;
        }

        const gEdges = el('g');
        const edgeEls = {};
        Object.entries(E).forEach(([id, [a, b, kind]]) => {
            edgeEls[id] = el('path', { class: `edge${kind === 'dash' ? ' is-dash' : ''}`, d: pathFor(a, b) }, gEdges);
        });

        const gNodes = el('g');
        const nodeEls = {};
        Object.entries(N).forEach(([id, n]) => {
            const g = el('g', { class: `node${core.has(id) ? ' is-core' : ''}`, tabindex: 0, role: 'button', 'aria-label': `${n[2]}: ${n[3]}`, transform: `translate(${n[0]},${n[1]})` }, gNodes);
            el('rect', { width: NW, height: NH }, g);
            el('text', { class: 't', x: 14, y: 24 }, g).textContent = n[2];
            el('text', { class: 's', x: 14, y: 42 }, g).textContent = n[3];
            if (n[4]) el('circle', { class: 'stat', cx: NW - 14, cy: 16, r: 3.5, fill: statusColor[n[4]] }, g);
            g.addEventListener('click', () => inspect(id));
            g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); inspect(id); } });
            nodeEls[id] = g;
        });
        const gPk = el('g');

        function inspect(id) {
            stop();
            const n = N[id];
            Object.values(nodeEls).forEach((g) => g.classList.remove('is-sel'));
            nodeEls[id].classList.add('is-sel');
            const st = n[4] ? `<span class="status ${S[n[4]][0]}"><span class="dot"></span>${S[n[4]][1]}</span>` : '';
            panel.innerHTML = `<p class="label label--gold">Inspector</p><h3>${n[2]}</h3>${st}<p>${n[5]}</p><p class="path">${n[6]}</p>`;
        }

        const SCEN = {
            asistencia: { title: 'Asistencia con carné', hops: [
                [['e1'], ['estudiante', 'tarjeta'], 'Martina acerca su carné.'],
                [['e3'], ['lector'], 'El lector lee la tarjeta sin contacto.'],
                [['e7'], ['mdns'], 'Ya conoce al servidor: lo descubrió en la red local.'],
                [['e8'], ['api'], 'El aviso viaja firmado.'],
                [['e10'], ['escuela'], 'La firma identifica al lector; el colegio sale del lector.'],
                [['e11'], ['tap'], 'Primer toque del día en un aula: asistencia.'],
                [['e14'], ['events'], 'Se escribe una fila en events.'],
                [['e22'], ['db'], 'Queda guardada en MariaDB.'],
                [['e24'], ['realtime'], 'El servidor en vivo la detecta en menos de un segundo.'],
                [['e25', 'e26', 'e28'], ['docente', 'admin', 'altavoz'], 'Llega al docente, a administración y al parlante.'],
            ] },
            telefono: { title: 'Teléfono como credencial', hops: [
                [['e2'], ['estudiante', 'telefono'], 'Martina usa su teléfono Android.'],
                [['e4'], ['lector'], 'El lector desafía al teléfono y este responde con su llave secreta.'],
                [['e8'], ['api'], 'El lector reenvía la respuesta, con su firma.'],
                [['e10'], ['escuela'], 'Lector identificado, colegio resuelto.'],
                [['e11'], ['tap'], 'La credencial es de tipo HCE: hay que verificarla.'],
                [['e13'], ['hce'], 'Pulse verifica la respuesta con la llave de esa credencial; nada se puede repetir.'],
                [['e14'], ['events'], 'Solo si la prueba es válida se escribe el evento.'],
                [['e24'], ['realtime'], 'Sale por el canal en vivo.'],
                [['e25', 'e28'], ['docente', 'altavoz'], 'El docente lo ve; el parlante suena.'],
            ] },
            pae: { title: 'Comedor PAE', hops: [
                [['e1'], ['estudiante', 'tarjeta'], 'Llega al comedor con su carné.'],
                [['e3'], ['lector'], 'Lector de cafetería.'],
                [['e8'], ['api'], 'Toque firmado.'],
                [['e10'], ['escuela'], 'Colegio resuelto por el lector.'],
                [['e11'], ['tap'], 'Es un lector de comedor: decide el motor PAE.'],
                [['e12'], ['pae'], 'Día hábil, franja activa, inscripción, asistencia previa, sin repetir.'],
                [['e15'], ['events'], 'Comida servida, o intento marcado con su motivo.'],
                [['e24'], ['realtime'], 'Sale por el canal en vivo.'],
                [['e27'], ['cocina'], 'La cocina ve verde o rojo, con el motivo.'],
            ] },
            reciclaje: { title: 'Reciclaje con cámara', hops: [
                [['e5', 'e6'], ['tarjeta', 'residuo', 'estacion'], 'Tarjeta y botella llegan a la estación ESP32-CAM.'],
                [['e9'], ['api'], 'Toque firmado y foto del material.'],
                [['e10'], ['escuela'], 'Colegio resuelto por el lector.'],
                [['e16'], ['recy'], 'La captura se asocia a la tarjeta.'],
                [['e17', 'e18'], ['local', 'deepseek'], 'El clasificador dice qué material ve.'],
                [['e21'], ['events'], 'El resultado queda ligado a su evento de depósito.'],
                [['e19'], ['points'], 'Plástico: +10 puntos.'],
                [['e20'], ['ledger'], 'Nueva entrada en el libro de puntos.'],
                [['e29'], ['estudiante2'], 'El estudiante ve su saldo y puede canjear.'],
                [['e24', 'e26'], ['realtime', 'admin'], 'Administración lo ve en vivo.'],
            ] },
            pregunta: { title: 'Pregunta en lenguaje natural', hops: [
                [['e30'], ['admin', 'nl'], 'Administración pregunta: «¿Quién llegó tarde hoy?»'],
                [['e31'], ['deepseek'], 'El modelo recibe la pregunta, la fecha y las 27 funciones; elige get_late_students.'],
                [[['e31', 1]], ['nl'], 'Pulse recibe la llamada elegida.'],
                [['e32'], ['events'], 'AttendanceService calcula sobre events, dentro del colegio.'],
                [['e31'], ['deepseek'], 'Los resultados vuelven al modelo, que solo redacta.'],
                [[['e30', 1]], ['admin'], 'La respuesta llega como texto seguro.'],
            ] },
        };

        let run = 0;
        function stop() {
            run += 1;
            gPk.innerHTML = '';
            $$('[data-scen]').forEach((b) => b.setAttribute('aria-pressed', 'false'));
        }
        function clearLit() {
            Object.values(nodeEls).forEach((g) => g.classList.remove('is-lit', 'is-sel'));
            Object.values(edgeEls).forEach((p) => p.classList.remove('is-lit'));
        }
        const wait = (ms) => new Promise((r) => setTimeout(r, reduced ? 0 : ms));
        function travel(path, reverse, token) {
            return new Promise((resolve) => {
                const len = path.getTotalLength();
                const dot = el('circle', { class: 'pk', r: 5 }, gPk);
                if (reduced) { dot.remove(); resolve(); return; }
                const dur = clamp(len * 1.6, 380, 900);
                const t0 = performance.now();
                const step = (now) => {
                    if (token !== run) { dot.remove(); resolve(); return; }
                    const t = clamp((now - t0) / dur, 0, 1);
                    const e = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
                    const pt = path.getPointAtLength((reverse ? 1 - e : e) * len);
                    dot.setAttribute('cx', pt.x);
                    dot.setAttribute('cy', pt.y);
                    if (t < 1) requestAnimationFrame(step);
                    else { dot.remove(); resolve(); }
                };
                requestAnimationFrame(step);
            });
        }
        async function play(key) {
            stop();
            const token = run;
            clearLit();
            const sc = SCEN[key];
            $(`[data-scen="${key}"]`).setAttribute('aria-pressed', 'true');
            panel.innerHTML = `<p class="label label--gold">Recorrido</p><h3>${sc.title}</h3><ol class="hops">${sc.hops.map((h) => `<li>${h[2]}</li>`).join('')}</ol><button class="btn" type="button" data-replay-scen>Repetir</button>`;
            $('[data-replay-scen]', panel).addEventListener('click', () => play(key));
            const lis = $$('.hops li', panel);
            const first = sc.hops[0][1][0];
            if (!sc.hops[0][0].some((e) => (Array.isArray(e) ? e[0] : e) && E[Array.isArray(e) ? e[0] : e][0] !== first)) nodeEls[first].classList.add('is-lit');
            for (let i = 0; i < sc.hops.length; i++) {
                if (token !== run) return;
                const [edges, nodes] = sc.hops[i];
                lis.forEach((li, k) => li.classList.toggle('is-on', k === i));
                edges.forEach((e) => {
                    const [id, rev] = Array.isArray(e) ? e : [e, 0];
                    const from = E[id][rev ? 1 : 0];
                    nodeEls[from].classList.add('is-lit');
                });
                await Promise.all(edges.map((e) => {
                    const [id, rev] = Array.isArray(e) ? e : [e, 0];
                    edgeEls[id].classList.add('is-lit');
                    return travel(edgeEls[id], !!rev, token);
                }));
                if (token !== run) return;
                nodes.forEach((n) => nodeEls[n].classList.add('is-lit'));
                await wait(520);
            }
            if (token === run) lis.forEach((li) => li.classList.add('is-on'));
        }
        $$('[data-scen]').forEach((b) => b.addEventListener('click', () => play(b.dataset.scen)));

        // Autoplay the attendance route once when the diagram first scrolls into view.
        let auto = false;
        new IntersectionObserver((entries, obs) => {
            if (entries[0].isIntersecting && !auto) {
                auto = true;
                obs.disconnect();
                setTimeout(() => play('asistencia'), 400);
            }
        }, { threshold: 0.45 }).observe(svg);
    })();

    /* ------------------------------------------------------------------
       05 · Organizations: the probe + the branding layer.
       ------------------------------------------------------------------ */
    (() => {
        const orgs = $('[data-orgs]');
        if (!orgs) return;
        const probe = () => {
            orgs.classList.remove('probe-run');
            void orgs.offsetWidth;
            orgs.classList.add('probe-run');
        };
        $('[data-probe]')?.addEventListener('click', probe);
        new IntersectionObserver((entries, obs) => {
            if (entries[0].isIntersecting) { obs.disconnect(); setTimeout(probe, 600); }
        }, { threshold: 0.5 }).observe(orgs);

        const swap = $('[data-brandswap]');
        const logo = $('[data-bs-logo]');
        const name = $('[data-bs-name]');
        const school = { src: logo.getAttribute('src'), name: 'IE Concejo de Sabaneta' };
        const pulse = { src: 'assets/mark-ink.webp', name: 'Pulse estándar' };
        $$('[data-brand]').forEach((b) => b.addEventListener('click', () => {
            const isSchool = b.dataset.brand === 'school';
            swap.classList.toggle('is-school', isSchool);
            const p = isSchool ? school : pulse;
            logo.src = p.src;
            name.textContent = p.name;
            $$('[data-brand]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        }));
        logo.addEventListener('error', () => { logo.src = pulse.src; });
    })();

    /* ------------------------------------------------------------------
       07 · Scripted natural-language console.
       ------------------------------------------------------------------ */
    (() => {
        const root = $('[data-console]');
        if (!root) return;
        const Q = [
            {
                q: '¿Quiénes llegaron tarde hoy?',
                call: `get_late_students(hoy)`,
                run: 'llegadas de hoy · primer toque del día\ndespués de la hora límite (08:15)\n<span class="ok">→ 2 estudiantes</span>',
                a: 'Dos estudiantes llegaron después de la hora límite: Joaquín Rivera (08:22) y Paula Méndez (08:34).',
            },
            {
                q: '¿Cuántos desayunos se sirvieron hoy?',
                call: `get_pae_count(desayunos de hoy)`,
                run: 'desayunos servidos hoy\n<span class="c">los intentos rechazados quedan fuera</span>\n<span class="ok">→ 5 estudiantes</span>',
                a: 'Hoy se sirvieron 5 desayunos. Los intentos rechazados no se cuentan.',
            },
            {
                q: '¿Quién va ganando en reciclaje?',
                call: `get_recycling_leaderboard(top 3)`,
                run: 'libro de puntos → puntos por estudiante\n<span class="ok">→ 3 primeros</span>',
                a: 'Va primera Salomé Méndez (9° A) con 307 puntos, seguida de Simón Jiménez (4° A) con 304 y Nicolás Rivera (7° B) con 300.',
            },
        ];
        const qEl = $('.turn__q', root);
        const turns = { call: $('[data-t="call"]', root), run: $('[data-t="run"]', root), ans: $('[data-t="ans"]', root) };
        const callEl = $('[data-call]', root), runEl = $('[data-run]', root), ansEl = $('[data-ans]', root);
        let token = 0;
        const wait = (ms) => new Promise((r) => setTimeout(r, reduced ? 0 : ms));
        async function type(node, text, speed, tk) {
            if (reduced) { node.textContent = text; return; }
            node.textContent = '';
            const caret = document.createElement('span');
            caret.className = 'caret';
            for (let i = 0; i < text.length; i++) {
                if (tk !== token) return;
                node.textContent = text.slice(0, i + 1);
                node.appendChild(caret);
                await wait(speed);
            }
            caret.remove();
        }
        async function ask(i) {
            token += 1;
            const tk = token;
            const d = Q[i];
            $$('[data-q]', root).forEach((b) => b.setAttribute('aria-pressed', String(Number(b.dataset.q) === i)));
            Object.values(turns).forEach((t) => t.classList.remove('is-on'));
            qEl.classList.remove('muted');
            callEl.textContent = ''; runEl.textContent = ''; ansEl.textContent = '';
            await type(qEl, d.q, 28, tk);
            if (tk !== token) return;
            await wait(350);
            turns.call.classList.add('is-on');
            await type(callEl, d.call, 12, tk);
            if (tk !== token) return;
            await wait(300);
            turns.run.classList.add('is-on');
            runEl.innerHTML = d.run;
            await wait(650);
            if (tk !== token) return;
            turns.ans.classList.add('is-on');
            await type(ansEl, d.a, 16, tk);
        }
        $$('[data-q]', root).forEach((b) => b.addEventListener('click', () => ask(Number(b.dataset.q))));
        new IntersectionObserver((entries, obs) => {
            if (entries[0].isIntersecting) { obs.disconnect(); setTimeout(() => ask(0), 500); }
        }, { threshold: 0.5 }).observe(root);
    })();

    /* ------------------------------------------------------------------
       08 · Depth explorer tabs (WAI-ARIA tabs pattern).
       ------------------------------------------------------------------ */
    (() => {
        const tabs = $$('.depth__tab');
        if (!tabs.length) return;
        function select(tab, focus) {
            tabs.forEach((t) => {
                const on = t === tab;
                t.setAttribute('aria-selected', String(on));
                t.tabIndex = on ? 0 : -1;
                $(`#${t.getAttribute('aria-controls')}`).hidden = !on;
            });
            if (focus) tab.focus();
        }
        tabs.forEach((t, i) => {
            t.addEventListener('click', () => select(t, false));
            t.addEventListener('keydown', (e) => {
                const vertical = window.innerWidth > 760;
                const nextKey = vertical ? 'ArrowDown' : 'ArrowRight';
                const prevKey = vertical ? 'ArrowUp' : 'ArrowLeft';
                let j = null;
                if (e.key === nextKey || e.key === 'ArrowRight') j = (i + 1) % tabs.length;
                if (e.key === prevKey || e.key === 'ArrowLeft') j = (i - 1 + tabs.length) % tabs.length;
                if (e.key === 'Home') j = 0;
                if (e.key === 'End') j = tabs.length - 1;
                if (j !== null) { e.preventDefault(); e.stopPropagation(); select(tabs[j], true); }
            });
        });
    })();

    /* ------------------------------------------------------------------
       Reveals, final chapter, app link.
       ------------------------------------------------------------------ */
    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    $$('.reveal').forEach((r) => io.observe(r));

    // "Entrar al sistema": same origin when Core serves this page (./run serve → /expo/);
    // otherwise the Core default port on this host. ?app=<url> overrides.
    (() => {
        const link = $('[data-app-link]');
        const note = $('[data-app-note]');
        if (!link) return;
        const params = new URLSearchParams(location.search);
        let base = params.get('app');
        if (!base) {
            if (location.protocol === 'file:') base = 'http://127.0.0.1:8000';
            else if (location.port === '8090') base = `${location.protocol}//${location.hostname}:8000`;
            else base = '';
        }
        base = base.replace(/\/+$/, '');
        if (!/^(https?:\/\/[^\s"'<>]+)?$/.test(base)) base = '';
        link.href = `${base}/login`;
        if (!base && note) note.textContent = 'Abre el sistema real que está sirviendo esta página.';
    })();

    /* ------------------------------------------------------------------
       Frame loop for scroll-driven state (cheap: reads only).
       ------------------------------------------------------------------ */
    let ticking = false;
    function onScroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            ticking = false;
            updateScrollies();
            updateHead();
        });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('scroll', () => { if (window.scrollY > vh() * 2.2) hideHint(); }, { passive: true });
    window.addEventListener('resize', () => { measure(); onScroll(); });
    window.addEventListener('load', () => { measure(); onScroll(); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { measure(); onScroll(); });
    measure();
    updateScrollies();
    updateHead();
    if (field) field.resize();
})();
