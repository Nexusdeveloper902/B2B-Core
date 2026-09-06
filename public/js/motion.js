/**
 * Presence Platform — Core dashboards
 * Motion layer (TASK-019, 2026-09-06). anime.js v4.5.0, self-hosted ESM —
 * the same vendored file and reveal architecture the marketplace
 * storefront uses (design system "Signal", ADR-028).
 *
 * Architecture (marketplace app.js pattern, trimmed for dashboards):
 *  - The inline head script in the layout adds .js-motion to <html> ONLY
 *    when JS is on AND prefers-reduced-motion is unset; without that flag
 *    this module exits before creating any animation — everything stays
 *    visible (CSS owns the hidden initial states, never the module).
 *  - [data-reveal]       : scroll reveals via anime.js onScroll; the class
 *                          hand-off (.is-revealed) drives the CSS transition.
 *  - [data-reveal-stagger]: same trigger, children animate in sequence
 *                          (stagger 90 ms, out(3) ease — Signal numbers).
 *  - No loops, no hero demos: a dashboard must be calm; the only pulses
 *    are the CSS live dots, which already respect reduced-motion.
 */
import { animate, stagger, onScroll } from './vendor/anime.esm.min.js';

const doc = document.documentElement;

if (doc.classList.contains('js-motion')) {
    /* Scroll reveals — onScroll drives the class hand-off to CSS. */
    document.querySelectorAll('[data-reveal]').forEach((el) => {
        onScroll({
            target: el,
            enter: '85% center',
            repeat: false,
            onEnter: () => el.classList.add('is-revealed'),
        });
    });

    /* Staggered groups: stat tiles / chips animate in sequence. */
    document.querySelectorAll('[data-reveal-stagger]').forEach((group) => {
        const children = Array.from(group.children);
        onScroll({
            target: group,
            enter: '85% center',
            repeat: false,
            onEnter: () => {
                group.classList.add('is-revealed');
                animate(children, {
                    opacity: [0, 1],
                    translateY: [22, 0],
                    duration: 650,
                    ease: 'out(3)',
                    delay: stagger(90),
                });
            },
        });
    });
}
