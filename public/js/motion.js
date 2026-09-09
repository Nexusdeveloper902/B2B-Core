/**
 * Presence Platform — Core dashboards
 * Motion layer (TASK-019, 2026-09-06; UI-pass revision 2026-09-09).
 * anime.js v4.5.0, self-hosted ESM — the same vendored file and reveal
 * architecture the marketplace storefront uses (design system "Signal",
 * ADR-028).
 *
 * Architecture (marketplace app.js pattern, trimmed for dashboards):
 *  - The inline head script in the layout adds .js-motion to <html> ONLY
 *    when JS is on AND prefers-reduced-motion is unset; without that flag
 *    this module exits before creating any animation — everything stays
 *    visible (CSS owns the hidden initial states, never the module).
 *  - [data-reveal]       : scroll reveals via IntersectionObserver; the
 *                          class hand-off (.is-revealed) drives the CSS
 *                          transition. (The previous anime.js onScroll
 *                          "85% center" trigger crossed at an element's
 *                          CENTER, so a tall panel stayed invisible while
 *                          its top half was already on screen — on phones
 *                          that read as a blank page.)
 *  - [data-reveal-stagger]: same trigger, children animate in sequence
 *                          (stagger 90 ms, out(3) ease — Signal numbers).
 *  - No loops, no hero demos: a dashboard must be calm; the only pulses
 *    are the CSS live dots, which already respect reduced-motion.
 */
import { animate, stagger } from './vendor/anime.esm.min.js';

const doc = document.documentElement;

if (doc.classList.contains('js-motion')) {
    /* Reveal as soon as any part of the element enters the viewport
       (minus a 10% bottom band so the hand-off still reads as motion).
       Threshold 0 keeps tall panels honest: their top edge reveals. */
    const revealer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) { continue; }
            const el = entry.target;
            el.classList.add('is-revealed');
            if (el.hasAttribute('data-reveal-stagger')) {
                animate(Array.from(el.children), {
                    opacity: [0, 1],
                    translateY: [22, 0],
                    duration: 650,
                    ease: 'out(3)',
                    delay: stagger(90),
                });
            }
            revealer.unobserve(el);
        }
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0 });

    document.querySelectorAll('[data-reveal], [data-reveal-stagger]').forEach((el) => {
        revealer.observe(el);
    });
}
