import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';

gsap.registerPlugin(ScrollTrigger);

const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Boot smooth scrolling (Lenis) + scroll-triggered reveals (GSAP).
 * Safe to call once per page load. Skips all motion when the user
 * has requested reduced motion.
 */
export function initMotion() {
  if (prefersReduced) return;

  // --- Smooth scrolling (DESKTOP ONLY) ---------------------------------------
  // On portables Lenis adds nothing (touch smoothing is off) but its rAF loop
  // drains battery and programmatic scrolls can fight pinch-zoom — e.g. users
  // stuck zoomed-in after iOS form auto-zoom. Native scrolling + the CSS
  // scroll-behavior own portables; GSAP reveals work off native scroll.
  if (window.matchMedia('(min-width:1025px)').matches) {
    const lenis = new Lenis({
      duration: 1.1,
      easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
      smoothWheel: true,
    });

    function raf(time) {
      lenis.raf(time);
      requestAnimationFrame(raf);
    }
    requestAnimationFrame(raf);

    // Keep ScrollTrigger in sync with Lenis' virtual scroll position.
    lenis.on('scroll', ScrollTrigger.update);

    // CSS `scroll-behavior:smooth` fights Lenis (two easings on one scroll), so
    // turn it off while Lenis drives, and route same-page anchor clicks through
    // Lenis instead — they stay smooth, from a single source. Cross-page anchors
    // (e.g. /#contact from an inner page) still navigate normally.
    document.documentElement.style.scrollBehavior = 'auto';
    document.querySelectorAll('a[href*="#"]').forEach((a) => {
      a.addEventListener('click', (e) => {
        const url = new URL(a.getAttribute('href'), location.href);
        if (url.pathname !== location.pathname || !url.hash) return;
        const target = document.querySelector(url.hash);
        if (!target) return;
        e.preventDefault();
        history.pushState(null, '', url.hash);
        lenis.scrollTo(target);
      });
    });
  }

  // --- Scroll reveals -------------------------------------------------------
  // Any element with [data-reveal] fades/slides in as it enters the viewport.
  // Add [data-reveal-stagger] on a parent to stagger its direct children.
  // Exclude children of stagger groups — those are handled by the stagger pass below.
  const reveals = gsap.utils.toArray('[data-reveal]').filter(
    (el) => !el.closest('[data-reveal-stagger]')
  );
  reveals.forEach((el) => {
    gsap.to(el, {
      opacity: 1,
      y: 0,
      duration: 0.8,
      ease: 'power2.out',
      scrollTrigger: {
        trigger: el,
        start: 'top 85%',
        toggleActions: 'play none none none',
      },
    });
  });

  // Staggered groups (e.g. card grids).
  gsap.utils.toArray('[data-reveal-stagger]').forEach((group) => {
    const kids = group.querySelectorAll('[data-reveal]');
    gsap.to(kids, {
      opacity: 1,
      y: 0,
      duration: 0.7,
      ease: 'power2.out',
      stagger: 0.12,
      scrollTrigger: { trigger: group, start: 'top 80%' },
    });
  });

  // --- Rates card: column-major fade-up + 3s count-up -----------------------
  // The 7 rate cells fade up staggered (left column 1-4, then right column 5-7);
  // each number counts up from 0 to its value over 3s (fast → slow).
  const rateItems = gsap.utils.toArray('.rates .rate');
  if (rateItems.length) {
    const left = rateItems.filter((_, i) => i % 2 === 0);   // DOM is row-major
    const right = rateItems.filter((_, i) => i % 2 === 1);
    const ordered = [...left, ...right];

    gsap.set(rateItems, { opacity: 0, y: 24 });
    // Start the visible numbers at 0 (markup keeps the real value for no-JS / reduced-motion).
    rateItems.forEach((r) => {
      const n = r.querySelector('.num');
      if (n) n.textContent = '0.00';
    });

    ScrollTrigger.create({
      trigger: '.rates',
      start: 'top 90%',
      once: true,
      onEnter: () => {
        ordered.forEach((rate, i) => {
          const delay = i * 0.12;
          gsap.to(rate, { opacity: 1, y: 0, duration: 0.7, ease: 'power2.out', delay });
          const num = rate.querySelector('.num');
          if (num) {
            const counter = { val: 0 };
            gsap.to(counter, {
              val: parseFloat(num.dataset.count),
              duration: 3,
              ease: 'power2.out',          // fast then slows down
              delay,
              onUpdate: () => { num.textContent = counter.val.toFixed(2); },
            });
          }
        });
      },
    });
  }

  // Recalculate once everything (fonts/images) has settled.
  window.addEventListener('load', () => ScrollTrigger.refresh());
}
