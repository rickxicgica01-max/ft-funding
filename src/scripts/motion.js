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

  // --- Smooth scrolling -----------------------------------------------------
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

  // Recalculate once everything (fonts/images) has settled.
  window.addEventListener('load', () => ScrollTrigger.refresh());
}
